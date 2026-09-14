<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/label_search.php';
require_once __DIR__ . '/../includes/ai_enrichment.php';  // recadrage automatique de l'étiquette

$db = get_db();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $db->prepare('SELECT * FROM wines WHERE id = ?');
$stmt->execute([$id]);
$wine = $stmt->fetch();
if (!$wine) {
    http_response_code(404);
    exit('Vin introuvable.');
}

// Message d'erreur transmis après une redirection (ex : destination pleine)
$error = isset($_GET['err']) ? substr((string) $_GET['err'], 0, 200) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_wine') {
        $db->prepare('DELETE FROM wines WHERE id = ?')->execute([$id]);
        header('Location: /pages/wines_list.php');
        exit;
    }

    if ($action === 'add_stock') {
        $locationId = $_POST['storage_location_id'] !== '' ? (int) $_POST['storage_location_id'] : null;
        $quantity = (int) ($_POST['quantity'] ?? 0);
        // Garde-fou serveur : la liste masque déjà les emplacements pleins, mais
        // une page restée ouverte pourrait viser une case entre-temps remplie.
        $remaining = location_remaining_space($db, $locationId);
        if ($quantity > 0 && $remaining !== null && $quantity > $remaining) {
            $error = $remaining === 0
                ? 'Cet emplacement est plein, choisis-en un autre.'
                : "Cet emplacement ne peut plus accueillir que $remaining bouteille(s).";
        } elseif ($quantity > 0) {
            $stmt = $db->prepare('SELECT id, quantity FROM stock WHERE wine_id = ? AND storage_location_id <=> ?');
            $stmt->execute([$id, $locationId]);
            $existing = $stmt->fetch();
            if ($existing) {
                $db->prepare('UPDATE stock SET quantity = quantity + ? WHERE id = ?')->execute([$quantity, $existing['id']]);
            } else {
                $db->prepare('INSERT INTO stock (wine_id, storage_location_id, quantity) VALUES (?, ?, ?)')->execute([$id, $locationId, $quantity]);
            }
            header('Location: /pages/wine_detail.php?id=' . $id);
            exit;
        }
        if (!$error) {
            header('Location: /pages/wine_detail.php?id=' . $id);
            exit;
        }
    }

    // Envoi manuel d'une étiquette depuis la galerie ou l'ordinateur
    if ($action === 'upload_label') {
        $path = upload_label_photo();
        if (!$path) {
            $error = 'Fichier invalide : choisis une image JPEG, PNG ou WebP de 8 Mo maximum.';
        } else {
            if (!empty($_POST['auto_crop'])) {
                crop_label_to_bottle(__DIR__ . '/../' . $path);
            }
            $db->prepare('UPDATE wines SET label_photo_path = ? WHERE id = ?')->execute([$path, $id]);
            header('Location: /pages/wine_detail.php?id=' . $id . '&labelsaved=1');
            exit;
        }
    }

    // Enregistrement d'une étiquette trouvée en ligne : c'est la seule étape
    // qui écrit en base, et elle n'a lieu qu'après validation explicite.
    if ($action === 'accept_label') {
        $result = download_label_image($_POST['image_url'] ?? '');
        if (!$result['ok']) {
            $error = $result['error'];
        } else {
            $db->prepare('UPDATE wines SET label_photo_path = ? WHERE id = ?')
                ->execute([$result['path'], $id]);
            header('Location: /pages/wine_detail.php?id=' . $id . '&labelsaved=1');
            exit;
        }
    }

    if ($action === 'remove_stock_row') {
        $stockId = (int) $_POST['stock_id'];
        $db->prepare('DELETE FROM stock WHERE id = ? AND wine_id = ?')->execute([$stockId, $id]);
        header('Location: /pages/wine_detail.php?id=' . $id);
        exit;
    }

    if ($action === 'move_stock') {
        $stockId = (int) $_POST['stock_id'];
        $newLocationId = $_POST['new_location_id'] !== '' ? (int) $_POST['new_location_id'] : null;

        $stmt = $db->prepare('SELECT * FROM stock WHERE id = ? AND wine_id = ?');
        $stmt->execute([$stockId, $id]);
        $stockRow = $stmt->fetch();

        if ($stockRow) {
            // Quantité à déplacer : tout par défaut, sinon la part demandée.
            $available = (int) $stockRow['quantity'];
            $moveQty = isset($_POST['move_quantity']) && $_POST['move_quantity'] !== ''
                ? max(1, min($available, (int) $_POST['move_quantity']))
                : $available;

            // Refus si la destination ne peut pas absorber la quantité.
            $remaining = location_remaining_space($db, $newLocationId);
            if ($remaining !== null && $moveQty > $remaining) {
                $error = $remaining === 0
                    ? 'La destination est pleine, choisis un autre emplacement.'
                    : "La destination ne peut plus accueillir que $remaining bouteille(s).";
                header('Location: /pages/wine_detail.php?id=' . $id . '&err=' . urlencode($error));
                exit;
            }

            $stmt = $db->prepare('SELECT id, quantity FROM stock WHERE wine_id = ? AND storage_location_id <=> ? AND id != ?');
            $stmt->execute([$id, $newLocationId, $stockId]);
            $existing = $stmt->fetch();

            $db->beginTransaction();
            if ($existing) {
                // La destination a déjà une ligne pour ce vin : on fusionne.
                $db->prepare('UPDATE stock SET quantity = quantity + ? WHERE id = ?')
                    ->execute([$moveQty, $existing['id']]);
                if ($moveQty >= $available) {
                    $db->prepare('DELETE FROM stock WHERE id = ?')->execute([$stockId]);
                } else {
                    $db->prepare('UPDATE stock SET quantity = quantity - ? WHERE id = ?')->execute([$moveQty, $stockId]);
                }
            } elseif ($moveQty >= $available) {
                $db->prepare('UPDATE stock SET storage_location_id = ? WHERE id = ?')->execute([$newLocationId, $stockId]);
            } else {
                // Déplacement partiel vers un emplacement vide : on scinde la ligne.
                $db->prepare('UPDATE stock SET quantity = quantity - ? WHERE id = ?')->execute([$moveQty, $stockId]);
                $db->prepare('INSERT INTO stock (wine_id, storage_location_id, quantity) VALUES (?, ?, ?)')
                    ->execute([$id, $newLocationId, $moveQty]);
            }
            $db->commit();
        }

        header('Location: /pages/wine_detail.php?id=' . $id);
        exit;
    }

    if ($action === 'consume') {
        $stockId = (int) $_POST['stock_id'];
        $quantity = max(1, (int) ($_POST['quantity'] ?? 1));
        $consumedDate = $_POST['consumed_date'] !== '' ? $_POST['consumed_date'] : date('Y-m-d');
        $rating = $_POST['rating'] !== '' ? (int) $_POST['rating'] : null;
        $tastingNotes = trim($_POST['tasting_notes'] ?? '') ?: null;
        $occasion = trim($_POST['occasion'] ?? '') ?: null;

        $stmt = $db->prepare('SELECT * FROM stock WHERE id = ? AND wine_id = ?');
        $stmt->execute([$stockId, $id]);
        $stockRow = $stmt->fetch();

        if ($stockRow && $stockRow['quantity'] >= $quantity) {
            $db->prepare('UPDATE stock SET quantity = quantity - ? WHERE id = ?')->execute([$quantity, $stockId]);
            $db->prepare(
                'INSERT INTO consumption_history (wine_id, storage_location_id, quantity, consumed_date, rating, tasting_notes, occasion)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$id, $stockRow['storage_location_id'], $quantity, $consumedDate, $rating, $tastingNotes, $occasion]);
        } else {
            $error = 'Quantité insuffisante dans cette case.';
        }
        if (!$error) {
            header('Location: /pages/wine_detail.php?id=' . $id);
            exit;
        }
    }
}

// Le nom de la cave est indispensable : plusieurs caves ont des emplacements
// homonymes (A5 existe dans La Datcha comme dans Abbeville).
$stockRows = $db->prepare(
    'SELECT s.*, sl.name AS location_name, c.name AS cellar_name
     FROM stock s
     LEFT JOIN storage_locations sl ON sl.id = s.storage_location_id
     LEFT JOIN cellars c ON c.id = sl.cellar_id
     WHERE s.wine_id = ? AND s.quantity > 0
     ORDER BY c.sort_order, sl.rack_row, sl.rack_col, sl.name'
);
$stockRows->execute([$id]);
$stockRows = $stockRows->fetchAll();

$totalQty = array_sum(array_column($stockRows, 'quantity'));

$locations = all_locations_for_select($db);

$grapes = $db->prepare('SELECT gv.name, wgv.percentage FROM grape_varieties gv INNER JOIN wine_grape_varieties wgv ON wgv.grape_variety_id = gv.id WHERE wgv.wine_id = ? ORDER BY gv.name');
$grapes->execute([$id]);
$grapes = $grapes->fetchAll();

$history = $db->prepare(
    'SELECT ch.*, sl.name AS location_name, c.name AS cellar_name
     FROM consumption_history ch
     LEFT JOIN storage_locations sl ON sl.id = ch.storage_location_id
     LEFT JOIN cellars c ON c.id = sl.cellar_id
     WHERE ch.wine_id = ? ORDER BY ch.consumed_date DESC'
);
$history->execute([$id]);
$history = $history->fetchAll();

$priceHistory = $db->prepare('SELECT * FROM price_history WHERE wine_id = ? ORDER BY recorded_at ASC');
$priceHistory->execute([$id]);
$priceHistory = $priceHistory->fetchAll();
$priceHistoryMax = $priceHistory ? max(array_column($priceHistory, 'price')) : 0;

const PRICE_TYPE_LABELS = [
    'purchase' => 'Achat',
    'observed' => 'Prix relevé',
    'open_prices' => 'Open Food Facts',
    'manual_estimate' => 'Ancienne estimation',
    'ai_estimate' => 'Ancienne estimation IA',
];

$status = drink_status($wine['drink_from_year'] !== null ? (int) $wine['drink_from_year'] : null, $wine['drink_until_year'] !== null ? (int) $wine['drink_until_year'] : null);

$pageTitle = $wine['name'];
$activeNav = 'wines';
require __DIR__ . '/../includes/layout_header.php';
?>

<div class="wine-detail-header">
    <div>
        <h1><?= e($wine['name']) ?></h1>
        <p style="color:var(--text-muted); margin-top:-0.6rem;">
            <?php if ($wine['producer']): ?><?= e($wine['producer']) ?> · <?php endif; ?>
            <span class="badge <?= color_badge_class($wine['color']) ?>"><?= e(color_label($wine['color'])) ?></span>
            <?php if ($wine['vintage']): ?> · <?= e((string) $wine['vintage']) ?><?php endif; ?>
            <?php if ($wine['classification']): ?> · <span class="badge badge-sweet"><?= e($wine['classification']) ?></span><?php endif; ?>
            <?php if ($wine['ai_enriched']): ?> · <span class="badge badge-ai">Enrichi par IA</span><?php endif; ?>
        </p>
    </div>
    <div class="header-actions">
        <a href="<?= e(vivino_search_url($wine['name'], $wine['producer'], $wine['vintage'] !== null ? (int) $wine['vintage'] : null)) ?>" target="_blank" rel="noopener noreferrer" class="btn"><?= icon('external', 16) ?> Vivino</a>
        <a href="<?= e(google_search_url($wine['name'], $wine['producer'], $wine['vintage'] !== null ? (int) $wine['vintage'] : null)) ?>" target="_blank" rel="noopener noreferrer" class="btn"><?= icon('search', 16) ?> Google</a>
        <a href="/pages/wine_form.php?id=<?= $id ?>" class="btn"><?= icon('edit', 16) ?> Modifier</a>
        <form method="post" onsubmit="return confirm('Supprimer définitivement ce vin ?');" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_wine">
            <button type="submit" class="btn btn-danger"><?= icon('trash', 16) ?> Supprimer</button>
        </form>
    </div>
</div>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<?php if (isset($_GET['labelsaved'])): ?><div class="alert alert-success">Étiquette enregistrée.</div><?php endif; ?>

<!-- Validation d'une étiquette proposée : rempli par le JS puis soumis -->
<form method="post" id="accept-label-form" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="accept_label">
    <input type="hidden" name="image_url" id="accept-label-url">
</form>

<div class="grid grid-4 section">
    <div class="card stat-tile">
        <div class="value"><?= $totalQty ?></div>
        <div class="label">Bouteilles en cave</div>
    </div>
    <div class="card stat-tile">
        <div class="value" style="font-size:1.3rem;"><?= e($status['label']) ?></div>
        <div class="label"><?= $wine['drink_from_year'] || $wine['drink_until_year'] ? e(($wine['drink_from_year'] ?? '?') . ' – ' . ($wine['drink_until_year'] ?? '?')) : 'Fenêtre de dégustation' ?></div>
    </div>
    <div class="card stat-tile">
        <?php if (!empty($wine['is_gift'])): ?>
            <div class="value" style="font-size:1.3rem;"><?= icon('gift', 18) ?> Cadeau</div>
        <?php else: ?>
            <div class="value"><?= format_price($wine['purchase_price']) ?></div>
        <?php endif; ?>
        <div class="label">Prix d'achat / bouteille</div>
    </div>
    <div class="card stat-tile">
        <div class="value"><?= format_price($wine['current_estimated_price']) ?></div>
        <div class="label">Dernier prix relevé / bouteille</div>
    </div>
</div>

<div class="grid grid-2 section">
    <div class="card">
        <h2>Fiche détaillée</h2>
        <table>
            <tr><th>Région</th><td><?= e($wine['region'] ?? '—') ?></td></tr>
            <tr><th>Appellation</th><td><?= e($wine['appellation'] ?? '—') ?></td></tr>
            <tr><th>Classification</th><td><?= e($wine['classification'] ?? '—') ?></td></tr>
            <tr><th>Pays</th><td><?= e($wine['country'] ?? '—') ?></td></tr>
            <tr><th>Cépages</th><td><?= $grapes ? e(implode(', ', array_column($grapes, 'name'))) : '—' ?></td></tr>
            <tr><th>Degré</th><td><?= $wine['alcohol_percent'] !== null ? e($wine['alcohol_percent'] . ' %') : '—' ?></td></tr>
            <tr><th>Volume</th><td><?= (int) $wine['volume_ml'] ?> ml</td></tr>
            <?php if (!empty($wine['is_gift'])): ?>
                <tr><th>Prix d'achat</th><td><?= icon('gift', 15) ?> Cadeau<?= $wine['gift_note'] ? ' — ' . e($wine['gift_note']) : '' ?></td></tr>
            <?php else: ?>
                <tr><th>Prix d'achat</th><td><?= format_price($wine['purchase_price']) ?><?= $wine['purchase_date'] ? ' (' . e($wine['purchase_date']) . ')' : '' ?></td></tr>
            <?php endif; ?>
        </table>
        <?php if ($wine['label_photo_path']): ?>
            <img src="/<?= e($wine['label_photo_path']) ?>" class="label-photo" style="margin-top:1rem;">
        <?php endif; ?>
        <div style="margin-top:1rem; padding-top:1rem; border-top:1px solid var(--border);">
            <p style="color:var(--text-muted); font-size:0.9rem;">
                <?= $wine['label_photo_path'] ? 'Remplacer la photo de l\'étiquette :' : 'Aucune photo d\'étiquette pour ce vin.' ?>
            </p>

            <form method="post" enctype="multipart/form-data" id="upload-label-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="upload_label">
                <!-- accept=image/* : sur mobile, propose galerie et appareil photo -->
                <input type="file" name="label_photo" id="label-photo-input"
                       accept="image/png,image/jpeg,image/webp" style="display:none;">
                <div class="form-row">
                    <button type="button" id="btn-pick-label" class="btn btn-accent">
                        <?= icon('image', 15) ?> Choisir une image
                    </button>
                    <button type="button" id="btn-search-label" class="btn" data-wine-id="<?= $id ?>">
                        <?= icon('search', 15) ?> Chercher en ligne
                    </button>
                </div>
                <label style="display:flex; align-items:center; gap:0.5rem; margin-top:0.7rem; font-size:0.9rem;">
                    <input type="checkbox" name="auto_crop" value="1" checked>
                    Recadrer automatiquement sur l'étiquette (IA)
                </label>
                <div id="label-file-name" class="ai-status"></div>
            </form>

            <div id="label-search-status" class="ai-status"></div>
            <div id="label-candidates" class="label-candidates"></div>
        </div>
    </div>

    <div class="card">
        <h2>Description &amp; accords</h2>
        <p><?= nl2br(e($wine['description'] ?? 'Aucune description.')) ?></p>
        <?php if ($wine['food_pairing']): ?>
            <h3 style="margin-top:1rem;">Accords mets-vin</h3>
            <p><?= nl2br(e($wine['food_pairing'])) ?></p>
        <?php endif; ?>
        <?php if ($wine['notes']): ?>
            <h3 style="margin-top:1rem;">Notes personnelles</h3>
            <p><?= nl2br(e($wine['notes'])) ?></p>
        <?php endif; ?>

        <h3 style="margin-top:1.25rem;">Relever un prix</h3>
        <p style="color:var(--text-muted); font-size:0.88rem; margin-top:-0.3rem;">
            Saisis un prix vu en magasin ou sur Vivino, ou cherche un relevé partagé sur Open Food Facts.
        </p>
        <form method="post" action="/pages/update_price.php" style="max-width:520px;">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="source" value="observed">
            <div class="form-row" style="align-items:flex-end;">
                <div class="field" style="flex:0 0 150px;">
                    <label>Prix (<?= e(currency_symbol()) ?>)</label>
                    <input type="number" step="0.01" min="0" name="price" value="<?= e((string) ($wine['current_estimated_price'] ?? '')) ?>">
                </div>
                <div class="field" style="flex:1;">
                    <label>Source (magasin, lien…)</label>
                    <input type="text" name="note" maxlength="255" placeholder="Ex : Nicolas Lyon 6e / vivino.com">
                </div>
                <button type="submit" class="btn btn-sm"><?= icon('check', 15) ?> Enregistrer</button>
            </div>
        </form>

        <button type="button" id="btn-open-prices" class="btn btn-sm" data-wine-id="<?= $id ?>" style="margin-top:0.4rem;">
            <?= icon('search', 15) ?> Chercher un prix (Open Food Facts)
        </button>
        <?php if (empty($wine['barcode'])): ?>
            <span style="color:var(--text-muted); font-size:0.82rem;"> — sans code-barres enregistré, la recherche se fait par nom (moins fiable)</span>
        <?php endif; ?>
        <div id="open-prices-status" class="ai-status"></div>
        <div id="open-prices-results"></div>
        <form method="post" action="/pages/update_price.php" id="accept-open-price-form" style="display:none;">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="source" value="open_prices">
            <input type="hidden" name="price" id="accept-open-price">
            <input type="hidden" name="note" id="accept-open-note">
        </form>
    </div>
</div>

<?php if ($priceHistory): ?>
<div class="card section">
    <h2>Historique des prix</h2>
    <?php foreach ($priceHistory as $p): ?>
        <div style="margin-bottom:0.7rem;">
            <div style="display:flex; justify-content:space-between; font-size:0.9rem; margin-bottom:0.2rem; gap:0.6rem;">
                <span><?= e(date('d/m/Y', strtotime($p['recorded_at']))) ?> — <?= e(PRICE_TYPE_LABELS[$p['price_type']] ?? $p['price_type']) ?><?php if (!empty($p['note'])): ?> <span style="color:var(--text-muted);">· <?= e($p['note']) ?></span><?php endif; ?></span>
                <span style="white-space:nowrap;"><?= format_price((float) $p['price']) ?></span>
            </div>
            <div style="background:var(--bg-elevated); border-radius:4px; height:8px;">
                <div style="background:<?= $p['price_type'] === 'purchase' ? 'var(--gold)' : 'var(--accent)' ?>; border-radius:4px; height:8px; width:<?= $priceHistoryMax > 0 ? (float) $p['price'] / $priceHistoryMax * 100 : 0 ?>%;"></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card section">
    <h2>Emplacement &amp; stock</h2>
    <?php if ($stockRows): ?>
    <table class="responsive-table">
        <thead><tr><th>Emplacement</th><th>Quantité</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($stockRows as $s):
            $locLabel = $s['location_name'] !== null ? location_full_label($s) : 'Non assignée';
        ?>
            <tr>
                <td data-label="Emplacement"><?= e($locLabel) ?></td>
                <td data-label="Quantité"><?= (int) $s['quantity'] ?></td>
                <td data-label="">
                    <button type="button" class="btn btn-sm btn-accent btn-consume" data-stock-id="<?= (int) $s['id'] ?>" data-location="<?= e($locLabel) ?>" data-max="<?= (int) $s['quantity'] ?>"><?= icon('glass', 15) ?> Consommer</button>
                    <button type="button" class="btn btn-sm btn-move-stock" data-stock-id="<?= (int) $s['id'] ?>" data-location-id="<?= e((string) $s['storage_location_id']) ?>" data-max="<?= (int) $s['quantity'] ?>" data-location="<?= e($locLabel) ?>"><?= icon('edit', 15) ?> Déplacer</button>
                    <form method="post" style="display:inline" onsubmit="return confirm('Retirer cette ligne de stock ?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="remove_stock_row">
                        <input type="hidden" name="stock_id" value="<?= (int) $s['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-ghost"><?= icon('trash', 15) ?> Retirer</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
        <div class="empty-state">Aucune bouteille en stock pour ce vin.</div>
    <?php endif; ?>

    <h3 style="margin-top:1.5rem;">Ajouter du stock</h3>
    <form method="post" class="form-row" style="align-items:flex-end;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_stock">
        <div class="field">
            <label>Emplacement</label>
            <div class="form-row" style="align-items:center; gap:0.5rem;">
                <?php render_cellar_filter($db, 'add_stock_location'); ?>
                <select name="storage_location_id" id="add_stock_location" style="flex:1; min-width:150px;">
                    <option value="">— Non assignée —</option>
                    <?php foreach ($locations as $loc): ?>
                        <option value="<?= (int) $loc['id'] ?>" data-cellar="<?= (int) ($loc['cellar_id'] ?? 0) ?>"><?= e(location_full_label($loc)) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-sm" data-open-modal="rack-picker-modal-stock"><?= icon('rack', 15) ?></button>
            </div>
        </div>
        <div class="field" style="flex:0 0 140px;">
            <label>Quantité</label>
            <input type="number" name="quantity" min="1" value="1">
        </div>
        <button type="submit" class="btn"><?= icon('plus', 16) ?> Ajouter</button>
    </form>
    <?php render_rack_picker_modal($db, 'rack-picker-modal-stock', 'add_stock_location'); ?>
</div>

<div class="card section">
    <h2>Historique de consommation</h2>
    <?php if (!$history): ?>
        <div class="empty-state">Aucune bouteille consommée pour l'instant.</div>
    <?php else: ?>
    <table class="responsive-table">
        <thead><tr><th>Date</th><th>Quantité</th><th>Emplacement</th><th>Note</th><th>Occasion</th><th>Commentaire</th></tr></thead>
        <tbody>
        <?php foreach ($history as $h): ?>
            <tr>
                <td data-label="Date"><?= e($h['consumed_date']) ?></td>
                <td data-label="Quantité"><?= (int) $h['quantity'] ?></td>
                <td data-label="Emplacement"><?= $h['location_name'] !== null ? e(location_full_label($h)) : '—' ?></td>
                <td data-label="Note"><?= $h['rating'] !== null ? (int) $h['rating'] . '/10' : '—' ?></td>
                <td data-label="Occasion"><?= e($h['occasion'] ?? '—') ?></td>
                <td data-label="Commentaire"><?= e($h['tasting_notes'] ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<div class="modal-backdrop" id="consume-modal">
    <div class="modal">
        <h3>Consommer une bouteille — <span id="consume-location"></span></h3>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="consume">
            <input type="hidden" name="stock_id" id="consume-stock-id">
            <div class="field">
                <label>Quantité</label>
                <input type="number" name="quantity" id="consume-quantity" min="1" value="1">
            </div>
            <div class="field">
                <label>Date</label>
                <input type="date" name="consumed_date" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="field">
                <label>Note (/10)</label>
                <input type="number" name="rating" min="1" max="10">
            </div>
            <div class="field">
                <label>Occasion</label>
                <input type="text" name="occasion">
            </div>
            <div class="field">
                <label>Commentaire de dégustation</label>
                <textarea name="tasting_notes"></textarea>
            </div>
            <button type="submit" class="btn btn-accent">Confirmer</button>
            <button type="button" class="btn btn-ghost" id="consume-cancel">Annuler</button>
        </form>
    </div>
</div>

<div class="modal-backdrop" id="move-stock-modal">
    <div class="modal">
        <h3>Déplacer des bouteilles</h3>
        <p style="color:var(--text-muted); font-size:0.88rem; margin-top:-0.5rem;">
            Depuis <strong id="move-stock-from">—</strong>. Tu peux n'en déplacer qu'une partie,
            y compris vers une autre cave.
        </p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="move_stock">
            <input type="hidden" name="stock_id" id="move-stock-id">
            <div class="field">
                <label for="move-stock-quantity">Nombre de bouteilles à déplacer</label>
                <input type="number" id="move-stock-quantity" name="move_quantity" min="1" value="1">
                <p style="color:var(--text-muted); font-size:0.85rem; margin-top:0.3rem;">
                    Sur <span id="move-stock-max">—</span> disponible(s) à cet emplacement.
                </p>
            </div>
            <div class="field">
                <label>Nouvel emplacement</label>
                <div class="form-row" style="align-items:center; gap:0.5rem;">
                    <?php render_cellar_filter($db, 'move_stock_location'); ?>
                    <select name="new_location_id" id="move_stock_location" style="flex:1; min-width:150px;">
                        <option value="">— Non assignée —</option>
                        <?php foreach ($locations as $loc): ?>
                            <option value="<?= (int) $loc['id'] ?>" data-cellar="<?= (int) ($loc['cellar_id'] ?? 0) ?>"><?= e(location_full_label($loc)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-sm" data-open-modal="rack-picker-modal-move"><?= icon('rack', 15) ?></button>
                </div>
            </div>
            <button type="submit" class="btn btn-accent"><?= icon('check', 15) ?> Valider</button>
            <button type="button" class="btn btn-ghost" id="move-stock-cancel">Annuler</button>
        </form>
    </div>
</div>
<?php render_rack_picker_modal($db, 'rack-picker-modal-move', 'move_stock_location'); ?>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
