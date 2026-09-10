<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ai_enrichment.php';

$db = get_db();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$wine = null;
$grapeNames = '';
$error = null;

if ($id) {
    $stmt = $db->prepare('SELECT * FROM wines WHERE id = ?');
    $stmt->execute([$id]);
    $wine = $stmt->fetch();
    if (!$wine) {
        http_response_code(404);
        exit('Vin introuvable.');
    }
    $stmt = $db->prepare('SELECT gv.name FROM grape_varieties gv INNER JOIN wine_grape_varieties wgv ON wgv.grape_variety_id = gv.id WHERE wgv.wine_id = ? ORDER BY gv.name');
    $stmt->execute([$id]);
    $grapeNames = implode(', ', $stmt->fetchAll(PDO::FETCH_COLUMN));
} elseif (!empty($_GET['prefill_name'])) {
    // Pré-remplissage depuis le mode magasin (page Scanner)
    $wine = [
        'name' => $_GET['prefill_name'],
        'producer' => $_GET['prefill_producer'] ?? null,
        'vintage' => !empty($_GET['prefill_vintage']) ? (int) $_GET['prefill_vintage'] : null,
    ];
}

$locations = all_locations_for_select($db);

function sync_grape_varieties(PDO $db, int $wineId, string $grapeNamesCsv): void
{
    $db->prepare('DELETE FROM wine_grape_varieties WHERE wine_id = ?')->execute([$wineId]);
    $names = array_filter(array_map('trim', explode(',', $grapeNamesCsv)));
    foreach ($names as $name) {
        $stmt = $db->prepare('SELECT id FROM grape_varieties WHERE name = ?');
        $stmt->execute([$name]);
        $grapeId = $stmt->fetchColumn();
        if (!$grapeId) {
            $db->prepare('INSERT INTO grape_varieties (name) VALUES (?)')->execute([$name]);
            $grapeId = $db->lastInsertId();
        }
        $db->prepare('INSERT IGNORE INTO wine_grape_varieties (wine_id, grape_variety_id) VALUES (?, ?)')->execute([$wineId, $grapeId]);
    }
}

function find_duplicate_wine(PDO $db, string $name, ?int $vintage): ?array
{
    // Même nom, et millésime compatible : identique, ou l'un des deux non précisé
    // (cas fréquent : le millésime a été oublié à la ressaisie du même vin).
    $stmt = $db->prepare(
        'SELECT * FROM wines
         WHERE LOWER(TRIM(name)) = LOWER(TRIM(:name))
           AND (vintage IS NULL OR :vintage1 IS NULL OR vintage = :vintage2)
         ORDER BY (vintage = :vintage3) DESC, id ASC
         LIMIT 1'
    );
    $stmt->execute(['name' => $name, 'vintage1' => $vintage, 'vintage2' => $vintage, 'vintage3' => $vintage]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function render_duplicate_prompt(PDO $db, array $duplicate, array $postData, ?string $photoPath): void
{
    $totalQty = total_stock_for_wine($db, (int) $duplicate['id']);
    $quantity = max(1, (int) ($postData['quantity'] ?? 1));
    $locationId = $postData['storage_location_id'] ?? '';

    $carryFields = ['name', 'producer', 'region', 'appellation', 'classification', 'country', 'color', 'vintage', 'alcohol_percent',
        'volume_ml', 'barcode', 'description', 'food_pairing', 'drink_from_year', 'drink_until_year', 'purchase_price',
        'purchase_date', 'current_estimated_price', 'notes', 'is_gift', 'gift_note', 'grape_varieties', 'storage_location_id', 'quantity'];

    $pageTitle = 'Vin déjà existant';
    $activeNav = 'wines';
    require __DIR__ . '/../includes/layout_header.php';
    ?>
    <?php $newVintage = $postData['vintage'] ?? ''; ?>
    <h1>Ce vin existe déjà</h1>
    <div class="card section">
        <p>Un vin du même nom est déjà dans ta cave :</p>
        <h2><?= e($duplicate['name']) ?><?= $duplicate['vintage'] ? ' (' . e((string) $duplicate['vintage']) . ')' : '' ?></h2>
        <p style="color:var(--text-muted);">
            <?= $duplicate['producer'] ? e($duplicate['producer']) . ' · ' : '' ?><?= $totalQty ?> bouteille(s) actuellement en cave
        </p>
        <?php if ((string) ($duplicate['vintage'] ?? '') !== (string) $newVintage): ?>
            <p style="color:var(--text-muted); font-size:0.9rem;">
                Millésime existant : <strong><?= $duplicate['vintage'] ? e((string) $duplicate['vintage']) : 'non précisé' ?></strong>
                — millésime que tu viens de saisir : <strong><?= $newVintage !== '' ? e((string) $newVintage) : 'non précisé' ?></strong>.
                Vérifie qu'il s'agit bien de la même bouteille avant de fusionner.
            </p>
        <?php endif; ?>

        <div class="grid grid-2" style="margin-top:1.5rem;">
            <div class="card">
                <h3>Ajouter au stock existant</h3>
                <p style="color:var(--text-muted); font-size:0.9rem;">Ajoute <?= $quantity ?> bouteille(s) au vin déjà enregistré, sans créer de doublon.</p>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="merge_stock">
                    <input type="hidden" name="existing_wine_id" value="<?= (int) $duplicate['id'] ?>">
                    <input type="hidden" name="quantity" value="<?= $quantity ?>">
                    <input type="hidden" name="storage_location_id" value="<?= e((string) $locationId) ?>">
                    <button type="submit" class="btn btn-accent">Ajouter <?= $quantity ?> bouteille(s) au stock existant</button>
                </form>
            </div>
            <div class="card">
                <h3>Créer une fiche séparée</h3>
                <p style="color:var(--text-muted); font-size:0.9rem;">Si c'est en réalité un vin différent (autre format, autre édition...), crée une fiche distincte.</p>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="confirm_duplicate" value="1">
                    <?php if ($photoPath): ?><input type="hidden" name="existing_photo_path" value="<?= e($photoPath) ?>"><?php endif; ?>
                    <?php foreach ($carryFields as $f): ?>
                        <input type="hidden" name="<?= e($f) ?>" value="<?= e((string) ($postData[$f] ?? '')) ?>">
                    <?php endforeach; ?>
                    <button type="submit" class="btn btn-ghost">Créer quand même un vin séparé</button>
                </form>
            </div>
        </div>
    </div>
    <a href="/pages/wine_detail.php?id=<?= (int) $duplicate['id'] ?>" class="btn btn-ghost">Voir la fiche existante</a>
    <?php
    require __DIR__ . '/../includes/layout_footer.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (($_POST['action'] ?? 'save') === 'merge_stock') {
        $existingId = (int) $_POST['existing_wine_id'];
        $mergeLocationId = $_POST['storage_location_id'] !== '' ? (int) $_POST['storage_location_id'] : null;
        $mergeQuantity = max(1, (int) ($_POST['quantity'] ?? 1));

        $stmt = $db->prepare('SELECT id, quantity FROM stock WHERE wine_id = ? AND storage_location_id <=> ?');
        $stmt->execute([$existingId, $mergeLocationId]);
        $existingStock = $stmt->fetch();
        if ($existingStock) {
            $db->prepare('UPDATE stock SET quantity = quantity + ? WHERE id = ?')->execute([$mergeQuantity, $existingStock['id']]);
        } else {
            $db->prepare('INSERT INTO stock (wine_id, storage_location_id, quantity) VALUES (?, ?, ?)')
                ->execute([$existingId, $mergeLocationId, $mergeQuantity]);
        }

        header('Location: /pages/wine_detail.php?id=' . $existingId);
        exit;
    }

    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        $error = 'Le nom du vin est requis.';
    } else {
        $fields = [
            'name' => $name,
            'producer' => trim($_POST['producer'] ?? '') ?: null,
            'region' => trim($_POST['region'] ?? '') ?: null,
            'appellation' => trim($_POST['appellation'] ?? '') ?: null,
            'classification' => trim($_POST['classification'] ?? '') ?: null,
            'country' => trim($_POST['country'] ?? '') ?: null,
            'color' => $_POST['color'] ?? 'other',
            'vintage' => $_POST['vintage'] !== '' ? (int) $_POST['vintage'] : null,
            'alcohol_percent' => $_POST['alcohol_percent'] !== '' ? (float) $_POST['alcohol_percent'] : null,
            'volume_ml' => $_POST['volume_ml'] !== '' ? (int) $_POST['volume_ml'] : 750,
            'barcode' => preg_replace('/\D/', '', $_POST['barcode'] ?? '') ?: null,
            'description' => trim($_POST['description'] ?? '') ?: null,
            'food_pairing' => trim($_POST['food_pairing'] ?? '') ?: null,
            'drink_from_year' => $_POST['drink_from_year'] !== '' ? (int) $_POST['drink_from_year'] : null,
            'drink_until_year' => $_POST['drink_until_year'] !== '' ? (int) $_POST['drink_until_year'] : null,
            'purchase_price' => $_POST['purchase_price'] !== '' ? (float) $_POST['purchase_price'] : null,
            'purchase_date' => $_POST['purchase_date'] !== '' ? $_POST['purchase_date'] : null,
            'current_estimated_price' => $_POST['current_estimated_price'] !== '' ? (float) $_POST['current_estimated_price'] : null,
            'notes' => trim($_POST['notes'] ?? '') ?: null,
            'is_gift' => !empty($_POST['is_gift']) ? 1 : 0,
            'gift_note' => !empty($_POST['is_gift']) ? (trim($_POST['gift_note'] ?? '') ?: null) : null,
        ];

        if (!$id && !empty($_POST['confirm_duplicate']) && !empty($_POST['existing_photo_path'])) {
            // Ré-soumission après confirmation "créer quand même" : la photo a déjà été uploadée/recadrée au premier passage.
            $photoPath = $_POST['existing_photo_path'];
        } else {
            $photoPath = upload_label_photo();
            // À l'ajout, le recadrage suit la case à cocher du panneau IA ; en modification (pas de panneau IA), on recadre par défaut.
            $shouldCrop = $id ? true : !empty($_POST['auto_crop_photo']);
            if ($photoPath && $shouldCrop) {
                crop_label_to_bottle(__DIR__ . '/../' . $photoPath);
            }
        }

        if (!$id && empty($_POST['confirm_duplicate'])) {
            $duplicate = find_duplicate_wine($db, $fields['name'], $fields['vintage']);
            if ($duplicate) {
                render_duplicate_prompt($db, $duplicate, $_POST, $photoPath);
                exit;
            }
        }

        if ($id) {
            $sql = 'UPDATE wines SET name=:name, producer=:producer, region=:region, appellation=:appellation, classification=:classification, country=:country,
                    color=:color, vintage=:vintage, alcohol_percent=:alcohol_percent, volume_ml=:volume_ml, barcode=:barcode, description=:description,
                    food_pairing=:food_pairing, drink_from_year=:drink_from_year, drink_until_year=:drink_until_year,
                    purchase_price=:purchase_price, purchase_date=:purchase_date, current_estimated_price=:current_estimated_price,
                    notes=:notes, is_gift=:is_gift, gift_note=:gift_note' . ($photoPath ? ', label_photo_path=:photo' : '') . ' WHERE id=:id';
            $fields['id'] = $id;
            if ($photoPath) {
                $fields['photo'] = $photoPath;
            }
            $db->prepare($sql)->execute($fields);
            sync_grape_varieties($db, $id, $_POST['grape_varieties'] ?? '');

            if ($fields['current_estimated_price'] !== null) {
                $db->prepare('INSERT INTO price_history (wine_id, price, price_type) VALUES (?, ?, "observed")')
                    ->execute([$id, $fields['current_estimated_price']]);
            }

            header('Location: /pages/wine_detail.php?id=' . $id);
            exit;
        } else {
            $fields['photo'] = $photoPath;
            $sql = 'INSERT INTO wines (name, producer, region, appellation, classification, country, color, vintage, alcohol_percent,
                    volume_ml, barcode, description, food_pairing, drink_from_year, drink_until_year, purchase_price, purchase_date,
                    current_estimated_price, notes, is_gift, gift_note, label_photo_path)
                    VALUES (:name, :producer, :region, :appellation, :classification, :country, :color, :vintage, :alcohol_percent,
                    :volume_ml, :barcode, :description, :food_pairing, :drink_from_year, :drink_until_year, :purchase_price, :purchase_date,
                    :current_estimated_price, :notes, :is_gift, :gift_note, :photo)';
            $db->prepare($sql)->execute($fields);
            $newId = (int) $db->lastInsertId();

            sync_grape_varieties($db, $newId, $_POST['grape_varieties'] ?? '');

            if ($fields['purchase_price'] !== null) {
                $db->prepare('INSERT INTO price_history (wine_id, price, price_type) VALUES (?, ?, "purchase")')
                    ->execute([$newId, $fields['purchase_price']]);
            }

            $locationId = $_POST['storage_location_id'] !== '' ? (int) $_POST['storage_location_id'] : null;
            $quantity = (int) ($_POST['quantity'] ?? 0);
            if ($quantity > 0) {
                $db->prepare('INSERT INTO stock (wine_id, storage_location_id, quantity) VALUES (?, ?, ?)')
                    ->execute([$newId, $locationId, $quantity]);
            }

            header('Location: /pages/wine_detail.php?id=' . $newId);
            exit;
        }
    }
}

$pageTitle = $id ? 'Modifier le vin' : 'Ajouter un vin';
$activeNav = 'wines';
require __DIR__ . '/../includes/layout_header.php';
?>

<h1><?= $id ? 'Modifier le vin' : 'Ajouter un vin' ?></h1>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" id="wine-form">
    <?= csrf_field() ?>

    <?php if (!$id): ?>
    <div class="ai-panel">
        <h3>Remplissage assisté par IA</h3>
        <p style="color:var(--text-muted); font-size:0.88rem; margin-top:-0.4rem;">
            Upload une photo de l'étiquette puis clique sur « Analyser la photo ».
            Ou renseigne le nom du vin ci-dessous et clique sur « Enrichir depuis le nom ». Les champs sont pré-remplis mais restent modifiables.
        </p>
        <div class="field">
            <label>Photo de l'étiquette</label>
            <div class="form-row">
                <button type="button" id="btn-take-photo" class="btn"><?= icon('camera') ?> Prendre une photo</button>
                <button type="button" id="btn-choose-gallery" class="btn"><?= icon('image') ?> Choisir dans la galerie</button>
                <button type="button" id="btn-scan-barcode" class="btn"><?= icon('barcode') ?> Scanner le code-barres</button>
            </div>
            <input type="file" id="label_photo" name="label_photo" accept="image/png,image/jpeg,image/webp" style="display:none;">
            <div id="photo-filename" class="ai-status"></div>
        </div>
        <div class="field" style="display:flex; align-items:center; gap:0.5rem;">
            <input type="checkbox" id="auto_crop_photo" name="auto_crop_photo" value="1" checked style="width:auto;">
            <label for="auto_crop_photo" style="margin:0;">Recadrer automatiquement l'étiquette (IA)</label>
        </div>
        <div class="form-row">
            <button type="button" id="btn-ai-photo" class="btn btn-accent">Analyser la photo</button>
            <button type="button" id="btn-ai-text" class="btn btn-accent">Enrichir depuis le nom</button>
        </div>
        <div id="ai-status" class="ai-status"></div>
    </div>

    <div class="modal-backdrop" id="barcode-modal">
        <div class="modal">
            <h3>Scanner le code-barres ou le QR code</h3>
            <p style="color:var(--text-muted); font-size:0.85rem;">Vise le code EAN ou le QR code de la bouteille avec la caméra, ou saisis le code EAN manuellement. Recherche via Open Food Facts (couvre surtout les vins de grande distribution) ; les QR codes renvoient souvent vers la fiche du producteur, dont le nom du vin est extrait automatiquement.</p>
            <video id="barcode-video" autoplay playsinline muted style="width:100%; max-height:260px; border-radius:8px; background:#000; object-fit:cover;"></video>
            <div id="barcode-status" class="ai-status"></div>
            <div class="form-row" style="margin-top:0.8rem; align-items:flex-end;">
                <div class="field" style="flex:1; margin-bottom:0;">
                    <label for="barcode-manual">Ou saisir le code EAN</label>
                    <input type="text" id="barcode-manual" inputmode="numeric" placeholder="Ex : 3263286343159">
                </div>
                <button type="button" id="barcode-manual-btn" class="btn btn-sm"><?= icon('search', 14) ?> Rechercher</button>
            </div>
            <div class="form-row" style="margin-top:1rem;">
                <button type="button" id="barcode-cancel" class="btn btn-ghost">Fermer</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="form-row">
        <div class="field">
            <label for="name">Nom du vin *</label>
            <input type="text" id="name" name="name" required value="<?= e($wine['name'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="producer">Producteur / Domaine</label>
            <input type="text" id="producer" name="producer" value="<?= e($wine['producer'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="vintage">Millésime</label>
            <input type="number" id="vintage" name="vintage" min="1900" max="2100" value="<?= e((string) ($wine['vintage'] ?? '')) ?>">
        </div>
    </div>

    <div class="form-row">
        <div class="field">
            <label for="color">Couleur</label>
            <select id="color" name="color">
                <?php foreach (COLOR_LABELS as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= ($wine['color'] ?? '') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="region">Région</label>
            <input type="text" id="region" name="region" value="<?= e($wine['region'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="appellation">Appellation</label>
            <input type="text" id="appellation" name="appellation" value="<?= e($wine['appellation'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="classification">Classification</label>
            <input type="text" id="classification" name="classification" value="<?= e($wine['classification'] ?? '') ?>" placeholder="Ex: Crianza, Reserva, Riserva...">
        </div>
        <div class="field">
            <label for="country">Pays</label>
            <input type="text" id="country" name="country" value="<?= e($wine['country'] ?? '') ?>">
        </div>
    </div>

    <div class="form-row">
        <div class="field">
            <label for="grape_varieties">Cépage(s), séparés par des virgules</label>
            <input type="text" id="grape_varieties" name="grape_varieties" value="<?= e($grapeNames) ?>" placeholder="Ex: Cabernet Sauvignon, Merlot">
        </div>
        <div class="field">
            <label for="alcohol_percent">Degré d'alcool (%)</label>
            <input type="number" step="0.1" id="alcohol_percent" name="alcohol_percent" value="<?= e((string) ($wine['alcohol_percent'] ?? '')) ?>">
        </div>
        <div class="field">
            <label for="volume_ml">Volume (ml)</label>
            <input type="number" id="volume_ml" name="volume_ml" value="<?= e((string) ($wine['volume_ml'] ?? 750)) ?>">
        </div>
        <div class="field">
            <label for="barcode">Code-barres (EAN)</label>
            <input type="text" id="barcode" name="barcode" inputmode="numeric" value="<?= e((string) ($wine['barcode'] ?? '')) ?>"
                   placeholder="Rempli au scan — sert à retrouver un prix">
        </div>
    </div>

    <div class="form-row">
        <div class="field">
            <label for="drink_from_year">À boire à partir de</label>
            <input type="number" id="drink_from_year" name="drink_from_year" min="1900" max="2100" value="<?= e((string) ($wine['drink_from_year'] ?? '')) ?>">
        </div>
        <div class="field">
            <label for="drink_until_year">À boire jusqu'à</label>
            <input type="number" id="drink_until_year" name="drink_until_year" min="1900" max="2100" value="<?= e((string) ($wine['drink_until_year'] ?? '')) ?>">
        </div>
    </div>

    <div class="field">
        <label for="description">Description</label>
        <textarea id="description" name="description"><?= e($wine['description'] ?? '') ?></textarea>
    </div>
    <div class="field">
        <label for="food_pairing">Accords mets-vin</label>
        <textarea id="food_pairing" name="food_pairing"><?= e($wine['food_pairing'] ?? '') ?></textarea>
    </div>

    <div class="form-row">
        <div class="field">
            <label for="purchase_price">Prix d'achat (<?= e(currency_symbol()) ?>)</label>
            <input type="number" step="0.01" id="purchase_price" name="purchase_price" value="<?= e((string) ($wine['purchase_price'] ?? '')) ?>">
        </div>
        <div class="field">
            <label for="purchase_date">Date d'achat</label>
            <input type="date" id="purchase_date" name="purchase_date" value="<?= e($wine['purchase_date'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="current_estimated_price">Prix actuel estimé (<?= e(currency_symbol()) ?>)</label>
            <input type="number" step="0.01" id="current_estimated_price" name="current_estimated_price" value="<?= e((string) ($wine['current_estimated_price'] ?? '')) ?>">
        </div>
    </div>

    <div class="field">
        <label style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0;">
            <input type="checkbox" id="is_gift" name="is_gift" value="1" <?= !empty($wine['is_gift']) ? 'checked' : '' ?>>
            Ce vin est un cadeau (prix d'achat inconnu)
        </label>
    </div>
    <div class="field" id="gift_note_field" style="<?= !empty($wine['is_gift']) ? '' : 'display:none;' ?>">
        <label for="gift_note">Occasion / offert par</label>
        <input type="text" id="gift_note" name="gift_note" value="<?= e($wine['gift_note'] ?? '') ?>" placeholder="Ex: anniversaire de Paul, offert par Marie">
    </div>

    <?php if (!$id): ?>
    <div class="form-row">
        <div class="field">
            <label for="storage_location_id">Emplacement de rangement</label>
            <div class="form-row" style="align-items:center; gap:0.5rem;">
                <?php render_cellar_filter($db, 'storage_location_id'); ?>
                <select id="storage_location_id" name="storage_location_id" style="flex:1; min-width:150px;">
                    <option value="">— Non assignée —</option>
                    <?php foreach ($locations as $loc): ?>
                        <option value="<?= (int) $loc['id'] ?>" data-cellar="<?= (int) ($loc['cellar_id'] ?? 0) ?>"><?= e(location_full_label($loc)) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-sm" data-open-modal="rack-picker-modal-new"><?= icon('rack', 15) ?></button>
            </div>
        </div>
        <div class="field">
            <label for="quantity">Nombre de bouteilles</label>
            <input type="number" id="quantity" name="quantity" min="0" value="1">
        </div>
    </div>
    <?php render_rack_picker_modal($db, 'rack-picker-modal-new', 'storage_location_id'); ?>
    <?php endif; ?>

    <?php if ($id): ?>
    <div class="field">
        <label for="label_photo">Photo de l'étiquette</label>
        <input type="file" id="label_photo" name="label_photo" accept="image/png,image/jpeg,image/webp">
        <?php if (!empty($wine['label_photo_path'])): ?>
            <img src="/<?= e($wine['label_photo_path']) ?>" class="label-photo label-photo-sm" style="margin-top:0.5rem;">
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="field">
        <label for="notes">Notes personnelles</label>
        <textarea id="notes" name="notes"><?= e($wine['notes'] ?? '') ?></textarea>
    </div>

    <button type="submit" class="btn btn-accent"><?= $id ? icon('check') . ' Enregistrer les modifications' : icon('plus') . ' Ajouter le vin' ?></button>
    <a href="<?= $id ? '/pages/wine_detail.php?id=' . $id : '/pages/wines_list.php' ?>" class="btn btn-ghost">Annuler</a>
</form>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
