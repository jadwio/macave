<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$db = get_db();

$wines = $db->query(
    "SELECT w.*, COALESCE(SUM(s.quantity), 0) AS qty,
     GROUP_CONCAT(DISTINCT CASE WHEN s.quantity > 0 THEN
         CONCAT(COALESCE(CONCAT(c.name, ' — '), ''), COALESCE(sl.name, 'Non assignée'), ' (', s.quantity, ')')
         END SEPARATOR ', ') AS locations_summary
     FROM wines w
     LEFT JOIN stock s ON s.wine_id = w.id
     LEFT JOIN storage_locations sl ON sl.id = s.storage_location_id
     LEFT JOIN cellars c ON c.id = sl.cellar_id
     GROUP BY w.id
     ORDER BY w.name"
)->fetchAll();

$totalBottles = 0;
$totalValuation = 0.0;
$distinctWines = count($wines);

// À son apogée : le vin à boire pour profiter au maximum de sa qualité
// gustative (statut 'ready' seul). À boire maintenant : tout vin déjà dans sa
// fenêtre de dégustation ou l'ayant dépassée — 'ready' + 'drink_soon' + 'past'
// réunis, un vin apogée dépassée restant tout à fait buvable, juste plus en
// attente. 'past' est aussi gardé à part pour le compte "À surveiller".
$readyWines = [];
$soonWines = [];
$pastWines = [];
$nowWines = [];

foreach ($wines as $w) {
    $qty = (int) $w['qty'];
    $totalBottles += $qty;
    $unitPrice = $w['current_estimated_price'] !== null ? (float) $w['current_estimated_price'] : (float) ($w['purchase_price'] ?? 0);
    $totalValuation += $unitPrice * $qty;

    if ($qty > 0) {
        $status = drink_status($w['drink_from_year'] !== null ? (int) $w['drink_from_year'] : null, $w['drink_until_year'] !== null ? (int) $w['drink_until_year'] : null);
        if ($status['status'] === 'ready') {
            $readyWines[] = $w;
            $nowWines[] = $w;
        } elseif ($status['status'] === 'drink_soon') {
            $soonWines[] = $w;
            $nowWines[] = $w;
        } elseif ($status['status'] === 'past') {
            $pastWines[] = $w;
            $nowWines[] = $w;
        }
    }
}

// Emplacements pleins : capacité propre à l'emplacement si définie, sinon celle de la cave
$fullCells = $db->query(
    "SELECT sl.name, c.name AS cellar_name, COALESCE(SUM(s.quantity), 0) AS qty,
            COALESCE(NULLIF(sl.capacity, 0), c.cell_capacity) AS cap
     FROM storage_locations sl
     INNER JOIN cellars c ON c.id = sl.cellar_id
     LEFT JOIN stock s ON s.storage_location_id = sl.id
     WHERE sl.rack_row IS NOT NULL AND sl.rack_col IS NOT NULL
     GROUP BY sl.id
     HAVING qty >= cap"
)->fetchAll();

$recent = $db->query(
    "SELECT w.*,
     GROUP_CONCAT(DISTINCT CASE WHEN s.quantity > 0 THEN
         CONCAT(COALESCE(CONCAT(c.name, ' — '), ''), COALESCE(sl.name, 'Non assignée'), ' (', s.quantity, ')')
         END SEPARATOR ', ') AS locations_summary
     FROM wines w
     LEFT JOIN stock s ON s.wine_id = w.id
     LEFT JOIN storage_locations sl ON sl.id = s.storage_location_id
     LEFT JOIN cellars c ON c.id = sl.cellar_id
     GROUP BY w.id
     ORDER BY w.created_at DESC
     LIMIT 5"
)->fetchAll();

function render_wine_table(array $wines, string $emptyMessage, bool $showStatus = false): void
{
    if (!$wines) {
        echo '<div class="empty-state">' . e($emptyMessage) . '</div>';
        return;
    }
    ?>
    <table class="responsive-table sortable-table">
        <thead>
            <tr>
                <th></th>
                <th data-sort-key="name">Vin</th>
                <th data-sort-key="color">Couleur</th>
                <th data-sort-key="vintage">Millésime</th>
                <th data-sort-key="region">Région</th>
                <th data-sort-key="qty">Bouteilles</th>
                <th>Emplacement</th>
                <?php if ($showStatus): ?><th>Dégustation</th><?php endif; ?>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($wines as $w): $qty = (int) $w['qty']; ?>
            <tr>
                <td data-label=""><?= wine_thumbnail_html($w['label_photo_path'] ?? null) ?></td>
                <td data-label="Vin"><strong><?= e($w['name']) ?></strong><?php if ($w['producer']): ?><br><span style="color:var(--text-muted); font-size:0.85rem;"><?= e($w['producer']) ?></span><?php endif; ?></td>
                <td data-label="Couleur"><span class="badge <?= color_badge_class($w['color']) ?>"><?= e(color_label($w['color'])) ?></span></td>
                <td data-label="Millésime" data-sort-value="<?= (int) ($w['vintage'] ?? 0) ?>"><?= e((string) ($w['vintage'] ?? '—')) ?></td>
                <td data-label="Région"><?= e($w['region'] ?? '—') ?></td>
                <td data-label="Bouteilles" data-qty-for="<?= (int) $w['id'] ?>" data-sort-value="<?= $qty ?>"><?= $qty ?></td>
                <td data-label="Emplacement"><?= e($w['locations_summary'] ?? '—') ?></td>
                <?php if ($showStatus):
                    $status = drink_status($w['drink_from_year'] !== null ? (int) $w['drink_from_year'] : null, $w['drink_until_year'] !== null ? (int) $w['drink_until_year'] : null);
                ?>
                    <td data-label="Dégustation"><?= e($status['label']) ?></td>
                <?php endif; ?>
                <td data-label="">
                    <?php if ($qty > 0): ?><button type="button" class="btn btn-sm btn-icon-only btn-quick-consume" data-wine-id="<?= (int) $w['id'] ?>" title="Consommer une bouteille"><?= icon('glass', 15) ?></button><?php endif; ?>
                    <a href="/pages/wine_detail.php?id=<?= (int) $w['id'] ?>"><?= icon('eye', 16) ?> Voir</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

$pageTitle = 'Tableau de bord';
$activeNav = 'dashboard';
require __DIR__ . '/../includes/layout_header.php';
?>

<?php if ($fullCells): ?>
<div class="section">
    <div class="card" style="border-left:4px solid var(--gold, #c9a227); display:flex; align-items:center; gap:0.6rem;">
        <?= icon('rack', 20) ?>
        <div>
            <strong>Case<?= count($fullCells) > 1 ? 's' : '' ?> pleine<?= count($fullCells) > 1 ? 's' : '' ?> :</strong>
            <?= e(implode(', ', array_map(
                fn($c) => $c['cellar_name'] . ' / ' . $c['name'] . ' (' . (int) $c['qty'] . ')',
                $fullCells
            ))) ?>
            — pensez à vérifier le rangement.
            <a href="/pages/rack.php" style="margin-left:0.5rem;">Voir les caves</a>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="section grid grid-4">
    <div class="card stat-tile">
        <div class="value"><?= $totalBottles ?></div>
        <div class="label">Bouteilles en cave</div>
    </div>
    <div class="card stat-tile">
        <div class="value"><?= $distinctWines ?></div>
        <div class="label">Vins différents</div>
    </div>
    <div class="card stat-tile">
        <div class="value"><?= format_price($totalValuation) ?></div>
        <div class="label">Valeur estimée</div>
    </div>
    <div class="card stat-tile">
        <div class="value"><?= count($soonWines) + count($pastWines) ?></div>
        <div class="label">À surveiller</div>
    </div>
</div>

<div class="tabs">
    <button type="button" class="tab-btn active" data-tab="tab-ready">À son apogée <span class="tab-count"><?= count($readyWines) ?></span></button>
    <button type="button" class="tab-btn" data-tab="tab-now">À boire maintenant <span class="tab-count"><?= count($nowWines) ?></span></button>
    <button type="button" class="tab-btn" data-tab="tab-soon">À boire bientôt <span class="tab-count"><?= count($soonWines) ?></span></button>
    <button type="button" class="tab-btn" data-tab="tab-recent">Ajouts récents <span class="tab-count"><?= count($recent) ?></span></button>
    <button type="button" class="tab-btn" data-tab="tab-all">Tous les vins <span class="tab-count"><?= count($wines) ?></span></button>
</div>

<div class="card">
    <div class="tab-panel active" id="tab-ready">
        <?php render_wine_table($readyWines, 'Aucun vin actuellement à son apogée.'); ?>
    </div>
    <div class="tab-panel" id="tab-now">
        <?php render_wine_table($nowWines, 'Aucun vin à boire pour le moment.', true); ?>
    </div>
    <div class="tab-panel" id="tab-soon">
        <?php render_wine_table($soonWines, 'Aucun vin à boire bientôt pour le moment.'); ?>
    </div>
    <div class="tab-panel" id="tab-recent">
        <?php if (!$recent): ?>
            <div class="empty-state">Aucun vin enregistré pour le moment. <a href="/pages/wine_form.php">Ajouter votre premier vin</a>.</div>
        <?php else: ?>
            <?php render_wine_table($recent, ''); ?>
        <?php endif; ?>
    </div>
    <div class="tab-panel" id="tab-all">
        <?php render_wine_table($wines, 'Aucun vin enregistré pour le moment.', true); ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
