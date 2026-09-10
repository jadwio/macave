<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$db = get_db();

$search = trim($_GET['search'] ?? '');
$color = $_GET['color'] ?? '';
$region = $_GET['region'] ?? '';
$locationId = $_GET['location_id'] ?? '';
$grapeId = $_GET['grape_id'] ?? '';

$sql = "SELECT w.*, COALESCE(SUM(s.quantity), 0) AS qty,
        GROUP_CONCAT(DISTINCT CASE WHEN s.quantity > 0 THEN
            CONCAT(COALESCE(CONCAT(c.name, ' — '), ''), COALESCE(sl.name, 'Non assignée'), ' (', s.quantity, ')')
            END SEPARATOR ', ') AS locations_summary
        FROM wines w
        LEFT JOIN stock s ON s.wine_id = w.id
        LEFT JOIN storage_locations sl ON sl.id = s.storage_location_id
        LEFT JOIN cellars c ON c.id = sl.cellar_id";
$joins = [];
$where = [];
$params = [];

if ($grapeId !== '') {
    $joins[] = 'INNER JOIN wine_grape_varieties wgv ON wgv.wine_id = w.id AND wgv.grape_variety_id = :grape_id';
    $params['grape_id'] = $grapeId;
}
if ($locationId !== '') {
    $where[] = 'EXISTS (SELECT 1 FROM stock s2 WHERE s2.wine_id = w.id AND s2.storage_location_id = :location_id AND s2.quantity > 0)';
    $params['location_id'] = $locationId;
}
if ($search !== '') {
    $where[] = '(w.name LIKE :search1 OR w.producer LIKE :search2 OR w.region LIKE :search3
        OR EXISTS (
            SELECT 1 FROM wine_grape_varieties wgv2
            INNER JOIN grape_varieties gv2 ON gv2.id = wgv2.grape_variety_id
            WHERE wgv2.wine_id = w.id AND gv2.name LIKE :search4
        ))';
    $like = '%' . $search . '%';
    $params['search1'] = $like;
    $params['search2'] = $like;
    $params['search3'] = $like;
    $params['search4'] = $like;
}
if ($color !== '') {
    $where[] = 'w.color = :color';
    $params['color'] = $color;
}
if ($region !== '') {
    $where[] = 'w.region = :region';
    $params['region'] = $region;
}

$sql .= ' ' . implode(' ', $joins);
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' GROUP BY w.id ORDER BY w.name';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$wines = $stmt->fetchAll();

$regions = $db->query('SELECT DISTINCT region FROM wines WHERE region IS NOT NULL AND region <> "" ORDER BY region')->fetchAll(PDO::FETCH_COLUMN);
$locations = $db->query(
    'SELECT sl.id, sl.name, c.name AS cellar_name FROM storage_locations sl
     LEFT JOIN cellars c ON c.id = sl.cellar_id ORDER BY c.sort_order, sl.name'
)->fetchAll();
$grapes = $db->query('SELECT id, name FROM grape_varieties ORDER BY name')->fetchAll();

$pageTitle = 'Mes vins';
$activeNav = 'wines';
require __DIR__ . '/../includes/layout_header.php';
?>

<div class="wine-detail-header">
    <h1>Mes vins</h1>
    <a href="/pages/wine_form.php" class="btn btn-accent"><?= icon('plus') ?> Ajouter un vin</a>
</div>

<form method="get" class="filters">
    <input type="text" name="search" placeholder="Rechercher (nom, producteur, région)..." value="<?= e($search) ?>">
    <select name="color">
        <option value="">Toutes les couleurs</option>
        <?php foreach (COLOR_LABELS as $key => $label): ?>
            <option value="<?= e($key) ?>" <?= $color === $key ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="region">
        <option value="">Toutes les régions</option>
        <?php foreach ($regions as $r): ?>
            <option value="<?= e($r) ?>" <?= $region === $r ? 'selected' : '' ?>><?= e($r) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="location_id">
        <option value="">Toutes les cases</option>
        <?php foreach ($locations as $loc): ?>
            <option value="<?= (int) $loc['id'] ?>" <?= (string) $locationId === (string) $loc['id'] ? 'selected' : '' ?>><?= e(location_full_label($loc)) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="grape_id">
        <option value="">Tous les cépages</option>
        <?php foreach ($grapes as $g): ?>
            <option value="<?= (int) $g['id'] ?>" <?= (string) $grapeId === (string) $g['id'] ? 'selected' : '' ?>><?= e($g['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn">Filtrer</button>
    <?php if ($search || $color || $region || $locationId || $grapeId): ?>
        <a href="/pages/wines_list.php" class="btn btn-ghost">Réinitialiser</a>
    <?php endif; ?>
</form>

<div class="card">
    <?php if (!$wines): ?>
        <div class="empty-state">Aucun vin ne correspond à ces critères.</div>
    <?php else: ?>
    <table class="responsive-table sortable-table">
        <thead>
            <tr>
                <th></th>
                <th data-sort-key="name">Vin</th>
                <th data-sort-key="color">Couleur</th>
                <th data-sort-key="vintage">Millésime</th>
                <th data-sort-key="region">Région</th>
                <th data-sort-key="qty">Quantité</th>
                <th>Emplacement</th><th>Fenêtre de dégustation</th><th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($wines as $w):
            $qty = (int) $w['qty'];
            $status = drink_status($w['drink_from_year'] !== null ? (int) $w['drink_from_year'] : null, $w['drink_until_year'] !== null ? (int) $w['drink_until_year'] : null);
        ?>
            <tr>
                <td data-label=""><?= wine_thumbnail_html($w['label_photo_path'] ?? null) ?></td>
                <td data-label="Vin"><strong><?= e($w['name']) ?></strong><?php if ($w['producer']): ?><br><span style="color:var(--text-muted); font-size:0.85rem;"><?= e($w['producer']) ?></span><?php endif; ?></td>
                <td data-label="Couleur"><span class="badge <?= color_badge_class($w['color']) ?>"><?= e(color_label($w['color'])) ?></span></td>
                <td data-label="Millésime" data-sort-value="<?= (int) ($w['vintage'] ?? 0) ?>"><?= e((string) ($w['vintage'] ?? '—')) ?></td>
                <td data-label="Région"><?= e($w['region'] ?? '—') ?></td>
                <td data-label="Quantité" data-qty-for="<?= (int) $w['id'] ?>" data-sort-value="<?= $qty ?>"><?= $qty ?></td>
                <td data-label="Emplacement"><?= e($w['locations_summary'] ?? '—') ?></td>
                <td data-label="Dégustation"><?= e($status['label']) ?></td>
                <td data-label="">
                    <?php if ($qty > 0): ?><button type="button" class="btn btn-sm btn-icon-only btn-quick-consume" data-wine-id="<?= (int) $w['id'] ?>" title="Consommer une bouteille"><?= icon('glass', 15) ?></button><?php endif; ?>
                    <a href="/pages/wine_detail.php?id=<?= (int) $w['id'] ?>"><?= icon('eye', 16) ?> Voir</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
