<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$db = get_db();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_cellar') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $error = 'Le nom de la cave est requis.';
        } else {
            $newId = create_cellar($db, $_POST);
            header('Location: /pages/rack_config.php?cellar=' . $newId);
            exit;
        }
    }

    if ($action === 'save_cellar') {
        $cellarId = (int) $_POST['cellar_id'];
        $newRows = max(1, min(26, (int) $_POST['grid_rows']));
        $newCols = max(1, min(99, (int) $_POST['grid_cols']));
        $newCapacity = max(1, (int) $_POST['cell_capacity']);
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $error = 'Le nom de la cave est requis.';
        } else {
            $db->prepare('UPDATE cellars SET name = ?, location = ?, grid_rows = ?, grid_cols = ?, cell_capacity = ?, col_labels = ? WHERE id = ?')
                ->execute([$name, trim($_POST['location'] ?? '') ?: null, $newRows, $newCols, $newCapacity,
                           trim($_POST['col_labels'] ?? '') ?: null, $cellarId]);
            // Désassigne les cases qui sortiraient de la nouvelle grille (sans les supprimer).
            $db->prepare('UPDATE storage_locations SET rack_row = NULL, rack_col = NULL
                          WHERE cellar_id = ? AND (rack_row > ? OR rack_col > ?)')
                ->execute([$cellarId, $newRows, $newCols]);
            header('Location: /pages/rack_config.php?cellar=' . $cellarId);
            exit;
        }
    }

    if ($action === 'delete_cellar') {
        $result = delete_cellar($db, (int) $_POST['cellar_id']);
        if (!$result['ok']) {
            $error = $result['error'];
        } else {
            header('Location: /pages/rack_config.php');
            exit;
        }
    }

    if ($action === 'save_capacities') {
        $cellarId = (int) $_POST['cellar_id'];
        foreach ($_POST['capacity'] ?? [] as $locId => $val) {
            $val = trim((string) $val);
            $newName = trim((string) ($_POST['loc_name'][$locId] ?? ''));
            $db->prepare('UPDATE storage_locations SET '
                . ($newName !== '' ? 'name = ?, ' : '')
                . 'capacity = ?, hard_access = ? WHERE id = ? AND cellar_id = ?')
                ->execute(array_merge(
                    $newName !== '' ? [$newName] : [],
                    [
                        $val === '' ? null : max(1, (int) $val),
                        !empty($_POST['hard_access'][$locId]) ? 1 : 0,
                        (int) $locId,
                        $cellarId,
                    ]
                ));
        }
        header('Location: /pages/rack_config.php?cellar=' . $cellarId);
        exit;
    }

    if ($action === 'generate_locations') {
        $cellarId = (int) $_POST['cellar_id'];
        $created = generate_cellar_locations($db, $cellarId);
        header('Location: /pages/rack_config.php?cellar=' . $cellarId . '&generated=' . $created);
        exit;
    }

    if ($action === 'add_location') {
        $cellarId = (int) $_POST['cellar_id'];
        $name = trim($_POST['loc_new_name'] ?? '');
        if ($name === '') {
            $error = 'Le nom de l\'emplacement est requis.';
        } else {
            $cap = trim($_POST['loc_new_capacity'] ?? '');
            $db->prepare('INSERT INTO storage_locations (cellar_id, name, capacity, hard_access) VALUES (?, ?, ?, ?)')
                ->execute([$cellarId, $name, $cap === '' ? null : max(1, (int) $cap),
                           !empty($_POST['loc_new_hard']) ? 1 : 0]);
            header('Location: /pages/rack_config.php?cellar=' . $cellarId);
            exit;
        }
    }

    if ($action === 'delete_location') {
        $cellarId = (int) $_POST['cellar_id'];
        $locId = (int) $_POST['location_id'];
        $stmt = $db->prepare('SELECT COALESCE(SUM(quantity), 0) FROM stock WHERE storage_location_id = ?');
        $stmt->execute([$locId]);
        $bottles = (int) $stmt->fetchColumn();
        if ($bottles > 0) {
            $error = "Cet emplacement contient encore $bottles bouteille(s).";
        } else {
            $db->prepare('DELETE FROM storage_locations WHERE id = ?')->execute([$locId]);
            header('Location: /pages/rack_config.php?cellar=' . $cellarId);
            exit;
        }
    }

    if ($action === 'save_assignments') {
        $cellarId = (int) $_POST['cellar_id'];
        $cellar = get_cellar($db, $cellarId);
        if ($cellar) {
            $rows = (int) $cellar['grid_rows'];
            $cols = (int) $cellar['grid_cols'];

            $db->beginTransaction();
            // On libère d'abord toute la grille de CETTE cave uniquement.
            $db->prepare('UPDATE storage_locations SET rack_row = NULL, rack_col = NULL WHERE cellar_id = ?')
                ->execute([$cellarId]);
            for ($r = 1; $r <= $rows; $r++) {
                for ($c = 1; $c <= $cols; $c++) {
                    $locId = $_POST["cell_{$r}_{$c}"] ?? '';
                    if ($locId !== '') {
                        // La case rejoint cette cave à la position voulue.
                        $db->prepare('UPDATE storage_locations SET cellar_id = ?, rack_row = ?, rack_col = ? WHERE id = ?')
                            ->execute([$cellarId, $r, $c, (int) $locId]);
                    }
                }
            }
            $db->commit();
            header('Location: /pages/rack_config.php?cellar=' . $cellarId);
            exit;
        }
    }
}

$cellars = get_cellars($db);
$selected = get_cellar($db, isset($_GET['cellar']) ? (int) $_GET['cellar'] : null);

$pageTitle = 'Configuration des caves';
$activeNav = 'rack';
require __DIR__ . '/../includes/layout_header.php';
?>

<h1>Configuration des caves</h1>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<?php if (isset($_GET['generated'])): $n = (int) $_GET['generated']; ?>
    <div class="alert alert-success">
        <?= $n > 0 ? "$n emplacement(s) créé(s) et positionné(s)." : 'La grille était déjà complète, aucun emplacement ajouté.' ?>
    </div>
<?php endif; ?>

<div class="card section">
    <h2>Mes caves</h2>
    <table class="responsive-table">
        <thead><tr><th>Nom</th><th>Lieu</th><th>Grille</th><th>Capacité/case</th><th>Bouteilles</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($cellars as $c):
            $stmt = $db->prepare('SELECT COALESCE(SUM(s.quantity), 0) FROM stock s
                                  INNER JOIN storage_locations sl ON sl.id = s.storage_location_id
                                  WHERE sl.cellar_id = ?');
            $stmt->execute([(int) $c['id']]);
            $bottles = (int) $stmt->fetchColumn();
        ?>
            <tr>
                <td data-label="Nom"><strong><?= e($c['name']) ?></strong></td>
                <td data-label="Lieu"><?= e($c['location'] ?? '—') ?></td>
                <td data-label="Grille"><?= (int) $c['grid_rows'] ?> × <?= (int) $c['grid_cols'] ?></td>
                <td data-label="Capacité/case"><?= (int) $c['cell_capacity'] ?></td>
                <td data-label="Bouteilles"><?= $bottles ?></td>
                <td data-label="">
                    <a href="/pages/rack_config.php?cellar=<?= (int) $c['id'] ?>" class="btn btn-sm"><?= icon('edit', 14) ?> Configurer</a>
                    <?php if (count($cellars) > 1 && $bottles === 0): ?>
                        <form method="post" style="display:inline;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_cellar">
                            <input type="hidden" name="cellar_id" value="<?= (int) $c['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger"><?= icon('trash', 14) ?></button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="card section">
    <h2>Ajouter une cave</h2>
    <p style="color:var(--text-muted); font-size:0.88rem;">Un second casier, une armoire à vin, un rangement dans un autre lieu…</p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_cellar">
        <div class="form-row">
            <div class="field">
                <label for="new_name">Nom</label>
                <input type="text" id="new_name" name="name" required placeholder="Ex : Armoire à vin">
            </div>
            <div class="field">
                <label for="new_location">Lieu (optionnel)</label>
                <input type="text" id="new_location" name="location" placeholder="Ex : Cuisine, maison de campagne">
            </div>
        </div>
        <div class="form-row">
            <div class="field"><label for="new_rows">Lignes</label><input type="number" id="new_rows" name="grid_rows" min="1" max="26" value="3"></div>
            <div class="field"><label for="new_cols">Colonnes</label><input type="number" id="new_cols" name="grid_cols" min="1" max="99" value="6"></div>
            <div class="field"><label for="new_capacity">Capacité par case</label><input type="number" id="new_capacity" name="cell_capacity" min="1" value="12"></div>
        </div>
        <button type="submit" class="btn btn-accent"><?= icon('plus') ?> Créer la cave</button>
    </form>
</div>

<?php if ($selected):
    $cellarId = (int) $selected['id'];
    $rows = (int) $selected['grid_rows'];
    $cols = (int) $selected['grid_cols'];

    // Cases de cette cave + cases non rattachées (assignables ici)
    $stmt = $db->prepare('SELECT id, name, cellar_id FROM storage_locations
                          WHERE cellar_id = ? OR cellar_id IS NULL ORDER BY name');
    $stmt->execute([$cellarId]);
    $allLocations = $stmt->fetchAll();

    $assignedMap = [];
    $stmt = $db->prepare('SELECT id, rack_row, rack_col FROM storage_locations
                          WHERE cellar_id = ? AND rack_row IS NOT NULL AND rack_col IS NOT NULL');
    $stmt->execute([$cellarId]);
    foreach ($stmt->fetchAll() as $a) {
        $assignedMap[(int) $a['rack_row']][(int) $a['rack_col']] = (int) $a['id'];
    }
?>

<div class="card section">
    <h2>Réglages de « <?= e($selected['name']) ?> »</h2>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_cellar">
        <input type="hidden" name="cellar_id" value="<?= $cellarId ?>">
        <div class="form-row">
            <div class="field">
                <label for="name">Nom</label>
                <input type="text" id="name" name="name" required value="<?= e($selected['name']) ?>">
            </div>
            <div class="field">
                <label for="location">Lieu (optionnel)</label>
                <input type="text" id="location" name="location" value="<?= e($selected['location'] ?? '') ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="field"><label for="grid_rows">Lignes</label><input type="number" id="grid_rows" name="grid_rows" min="1" max="26" value="<?= $rows ?>"></div>
            <div class="field"><label for="grid_cols">Colonnes</label><input type="number" id="grid_cols" name="grid_cols" min="1" max="99" value="<?= $cols ?>"></div>
            <div class="field">
                <label for="cell_capacity">Capacité <strong>par emplacement</strong></label>
                <input type="number" id="cell_capacity" name="cell_capacity" min="1" value="<?= (int) $selected['cell_capacity'] ?>">
                <p style="color:var(--text-muted); font-size:0.85rem; margin-top:0.3rem;">
                    Nombre de bouteilles dans <em>une seule</em> case, pas dans toute la cave.
                    Sert de valeur par défaut aux emplacements sans capacité propre.
                    <br><span id="capacity-hint"></span>
                </p>
            </div>
        </div>
        <script>
        // Affiche en direct la capacité totale induite : sans ce repère, la
        // « capacité par emplacement » se confond facilement avec la capacité
        // globale de la cave.
        (function () {
            const r = document.getElementById('grid_rows');
            const c = document.getElementById('grid_cols');
            const cap = document.getElementById('cell_capacity');
            const hint = document.getElementById('capacity-hint');
            function refresh() {
                const total = (parseInt(r.value, 10) || 0) * (parseInt(c.value, 10) || 0) * (parseInt(cap.value, 10) || 0);
                hint.innerHTML = 'Soit <strong>' + total + '</strong> bouteille(s) au total si la grille est complète.';
            }
            [r, c, cap].forEach(function (el) { el.addEventListener('input', refresh); });
            refresh();
        })();
        </script>
        <div class="field">
            <label for="col_labels">Noms des colonnes (optionnel)</label>
            <input type="text" id="col_labels" name="col_labels" value="<?= e($selected['col_labels'] ?? '') ?>" placeholder="Ex : Devant, Derrière">
            <p style="color:var(--text-muted); font-size:0.85rem; margin-top:0.3rem;">
                Séparés par des virgules. Laissé vide, les colonnes s'appellent A, B, C…
                Utile pour une armoire où les colonnes sont des profondeurs plutôt que des positions.
            </p>
        </div>
        <button type="submit" class="btn btn-accent"><?= icon('check') ?> Enregistrer</button>
    </form>
    <p style="color:var(--text-muted); font-size:0.85rem; margin-top:0.75rem; margin-bottom:0;">
        Réduire les dimensions désassigne les cases qui sortiraient de la grille, sans les supprimer ni toucher à leur contenu.
    </p>
</div>

<?php
$stmt = $db->prepare('SELECT sl.*, COALESCE(SUM(s.quantity), 0) AS bottle_count
                      FROM storage_locations sl
                      LEFT JOIN stock s ON s.storage_location_id = sl.id
                      WHERE sl.cellar_id = ?
                      GROUP BY sl.id
                      ORDER BY sl.rack_row IS NULL, sl.rack_row, sl.rack_col, sl.name');
$stmt->execute([$cellarId]);
$cellarCases = $stmt->fetchAll();
$totalCap = 0;
foreach ($cellarCases as $cc) { $totalCap += effective_cell_capacity($cc, $selected); }
?>
<div class="card section">
    <h2>Emplacements de « <?= e($selected['name']) ?> »</h2>
    <?php if ($cellarCases): ?>
    <p style="color:var(--text-muted); font-size:0.88rem;">
        Renomme, ajuste la capacité (vide = valeur par défaut : <?= (int) $selected['cell_capacity'] ?>) et marque les emplacements peu accessibles.
        <br>Capacité totale actuelle : <strong><?= $totalCap ?></strong> bouteille(s).
    </p>
    <form method="post" id="capacities-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_capacities">
        <input type="hidden" name="cellar_id" value="<?= $cellarId ?>">
        <table class="responsive-table">
            <thead><tr><th>Emplacement</th><th>Position</th><th>Capacité</th><th>Peu accessible</th><th>Occupé</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($cellarCases as $cc): ?>
                <tr>
                    <td data-label="Emplacement">
                        <input type="text" name="loc_name[<?= (int) $cc['id'] ?>]" value="<?= e($cc['name']) ?>" required style="min-width:150px;">
                    </td>
                    <td data-label="Position">
                        <?= $cc['rack_row'] !== null
                            ? e(cellar_position_label($selected, (int) $cc['rack_row'], (int) $cc['rack_col']))
                            : '<span style="color:var(--text-muted);">non placé</span>' ?>
                    </td>
                    <td data-label="Capacité">
                        <input type="number" name="capacity[<?= (int) $cc['id'] ?>]" min="1" style="width:110px;"
                               value="<?= $cc['capacity'] !== null ? (int) $cc['capacity'] : '' ?>"
                               placeholder="<?= (int) $selected['cell_capacity'] ?>">
                    </td>
                    <td data-label="Peu accessible">
                        <input type="checkbox" name="hard_access[<?= (int) $cc['id'] ?>]" value="1" <?= !empty($cc['hard_access']) ? 'checked' : '' ?>>
                    </td>
                    <td data-label="Occupé"><?= (int) $cc['bottle_count'] ?> / <?= effective_cell_capacity($cc, $selected) ?></td>
                    <td data-label="">
                        <?php if ((int) $cc['bottle_count'] === 0): ?>
                            <button type="submit" form="delloc-<?= (int) $cc['id'] ?>" class="btn btn-sm btn-danger" title="Supprimer cet emplacement"><?= icon('trash', 14) ?></button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <button type="submit" class="btn btn-accent" style="margin-top:1rem;"><?= icon('check') ?> Enregistrer les emplacements</button>
    </form>
    <?php foreach ($cellarCases as $cc): ?>
        <?php if ((int) $cc['bottle_count'] === 0): ?>
            <form method="post" id="delloc-<?= (int) $cc['id'] ?>" onsubmit="return confirm('Supprimer cet emplacement ?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_location">
                <input type="hidden" name="cellar_id" value="<?= $cellarId ?>">
                <input type="hidden" name="location_id" value="<?= (int) $cc['id'] ?>">
            </form>
        <?php endif; ?>
    <?php endforeach; ?>
    <?php else: ?>
        <div class="empty-state">Aucun emplacement dans cette cave pour l'instant : crée-les ci-dessous.</div>
    <?php endif; ?>

    <?php
    $emptySlots = $rows * $cols - count(array_filter($cellarCases, fn($c) => $c['rack_row'] !== null));
    if ($emptySlots > 0):
    ?>
        <form method="post" style="margin-top:1rem;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="generate_locations">
            <input type="hidden" name="cellar_id" value="<?= $cellarId ?>">
            <button type="submit" class="btn"><?= icon('plus', 15) ?> Générer les <?= $emptySlots ?> emplacement(s) manquant(s)</button>
            <span style="color:var(--text-muted); font-size:0.85rem; margin-left:0.5rem;">
                Crée et positionne automatiquement les cases vides de la grille (A1, A2, B1…).
            </span>
        </form>
    <?php endif; ?>

    <h3 style="margin-top:1.5rem;">Ajouter un emplacement</h3>
    <form method="post" class="form-row" style="align-items:flex-end;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_location">
        <input type="hidden" name="cellar_id" value="<?= $cellarId ?>">
        <div class="field" style="flex:2; margin-bottom:0;">
            <label for="loc_new_name">Nom</label>
            <input type="text" id="loc_new_name" name="loc_new_name" required placeholder="Ex : Niveau 6 — Devant">
        </div>
        <div class="field" style="margin-bottom:0;">
            <label for="loc_new_capacity">Capacité (optionnel)</label>
            <input type="number" id="loc_new_capacity" name="loc_new_capacity" min="1" placeholder="<?= (int) $selected['cell_capacity'] ?>">
        </div>
        <label style="display:flex; align-items:center; gap:0.4rem; margin-bottom:0.6rem;">
            <input type="checkbox" name="loc_new_hard" value="1"> Peu accessible
        </label>
        <button type="submit" class="btn btn-accent"><?= icon('plus') ?> Ajouter</button>
    </form>
    <p style="color:var(--text-muted); font-size:0.85rem; margin-top:0.6rem; margin-bottom:0;">
        Pense ensuite à le positionner dans la grille ci-dessous pour qu'il apparaisse dans le plan de la cave.
    </p>
</div>

<div class="card section">
    <h2>Position des cases dans « <?= e($selected['name']) ?> »</h2>
    <?php if (!$allLocations): ?>
        <div class="empty-state">Aucun emplacement disponible : crée-les dans la section « Emplacements » ci-dessus avant de les positionner ici.</div>
    <?php else: ?>
    <p style="color:var(--text-muted); font-size:0.88rem;">
        Associe une case à chaque position. Les cases proposées sont celles de cette cave et celles qui ne sont rattachées à aucune cave.
    </p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_assignments">
        <input type="hidden" name="cellar_id" value="<?= $cellarId ?>">
        <div class="rack-grid-wrap">
        <table class="rack-grid rack-grid-config" style="min-width:<?= 22 + $cols * 110 ?>px">
            <thead>
                <tr>
                    <th></th>
                    <?php for ($c = 1; $c <= $cols; $c++): ?><th><?= e(cellar_column_label($selected, $c)) ?></th><?php endfor; ?>
                </tr>
            </thead>
            <tbody>
            <?php for ($r = 1; $r <= $rows; $r++): ?>
                <tr>
                    <th><?= e(rack_row_letter($r)) ?></th>
                    <?php for ($c = 1; $c <= $cols; $c++): ?>
                        <td>
                            <select name="cell_<?= $r ?>_<?= $c ?>">
                                <option value="">—</option>
                                <?php foreach ($allLocations as $loc): ?>
                                    <option value="<?= (int) $loc['id'] ?>" <?= (($assignedMap[$r][$c] ?? null) === (int) $loc['id']) ? 'selected' : '' ?>><?= e($loc['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    <?php endfor; ?>
                </tr>
            <?php endfor; ?>
            </tbody>
        </table>
        </div>
        <button type="submit" class="btn btn-accent" style="margin-top:1rem;"><?= icon('check') ?> Enregistrer les emplacements</button>
    </form>
    <?php endif; ?>
</div>

<?php endif; ?>

<a href="/pages/rack.php" class="btn btn-ghost">← Retour aux caves</a>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
