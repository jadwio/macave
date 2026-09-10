<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$db = get_db();

$byColor = $db->query(
    "SELECT w.color, COALESCE(SUM(s.quantity),0) AS qty, COALESCE(SUM(s.quantity * COALESCE(w.current_estimated_price, w.purchase_price, 0)),0) AS value
     FROM wines w LEFT JOIN stock s ON s.wine_id = w.id
     GROUP BY w.color HAVING qty > 0 ORDER BY qty DESC"
)->fetchAll();

$byRegion = $db->query(
    "SELECT COALESCE(NULLIF(w.region,''), 'Non renseignée') AS region, COALESCE(SUM(s.quantity),0) AS qty
     FROM wines w LEFT JOIN stock s ON s.wine_id = w.id
     GROUP BY region HAVING qty > 0 ORDER BY qty DESC LIMIT 10"
)->fetchAll();

$byVintage = $db->query(
    "SELECT COALESCE(w.vintage, 0) AS vintage, COALESCE(SUM(s.quantity),0) AS qty
     FROM wines w LEFT JOIN stock s ON s.wine_id = w.id
     GROUP BY w.vintage HAVING qty > 0 ORDER BY vintage DESC"
)->fetchAll();

$byGrape = $db->query(
    "SELECT gv.name, COUNT(DISTINCT wgv.wine_id) AS wine_count
     FROM grape_varieties gv INNER JOIN wine_grape_varieties wgv ON wgv.grape_variety_id = gv.id
     GROUP BY gv.id ORDER BY wine_count DESC LIMIT 10"
)->fetchAll();

$totalBottles = array_sum(array_column($byColor, 'qty'));
$totalValue = array_sum(array_column($byColor, 'value'));
$maxColorQty = $byColor ? max(array_column($byColor, 'qty')) : 1;
$maxRegionQty = $byRegion ? max(array_column($byRegion, 'qty')) : 1;
$maxVintageQty = $byVintage ? max(array_column($byVintage, 'qty')) : 1;

$ageRow = $db->query(
    "SELECT COALESCE(SUM((YEAR(CURDATE()) - w.vintage) * s.quantity), 0) AS weighted_sum,
            COALESCE(SUM(CASE WHEN w.vintage IS NOT NULL THEN s.quantity ELSE 0 END), 0) AS vintage_qty
     FROM wines w LEFT JOIN stock s ON s.wine_id = w.id
     WHERE w.vintage IS NOT NULL"
)->fetch();
$avgAge = $ageRow['vintage_qty'] > 0 ? round($ageRow['weighted_sum'] / $ageRow['vintage_qty'], 1) : null;

$totalConsumed = (int) $db->query('SELECT COALESCE(SUM(quantity), 0) FROM consumption_history')->fetchColumn();
$everAcquired = $totalConsumed + $totalBottles;
$consumedRatio = $everAcquired > 0 ? round($totalConsumed / $everAcquired * 100) : 0;

$pageTitle = 'Statistiques';
$activeNav = 'stats';
require __DIR__ . '/../includes/layout_header.php';
?>

<h1>Statistiques de la cave</h1>

<div class="grid grid-3 section">
    <div class="card stat-tile"><div class="value"><?= $totalBottles ?></div><div class="label">Bouteilles</div></div>
    <div class="card stat-tile"><div class="value"><?= format_price($totalValue) ?></div><div class="label">Valeur totale</div></div>
    <div class="card stat-tile"><div class="value"><?= $totalBottles > 0 ? format_price($totalValue / $totalBottles) : '—' ?></div><div class="label">Valeur moyenne / bouteille</div></div>
</div>

<div class="grid grid-3 section">
    <div class="card stat-tile"><div class="value"><?= $avgAge !== null ? $avgAge . ' an' . ($avgAge >= 2 ? 's' : '') : '—' ?></div><div class="label">Âge moyen de la cave</div></div>
    <div class="card stat-tile"><div class="value"><?= $totalConsumed ?></div><div class="label">Bouteilles bues au total</div></div>
    <div class="card stat-tile"><div class="value"><?= $consumedRatio ?> %</div><div class="label">Ratio bu / acquis</div></div>
</div>

<div class="grid grid-2 section">
    <div class="card">
        <h2>Par couleur</h2>
        <?php foreach ($byColor as $row): ?>
            <div style="margin-bottom:0.7rem;">
                <div style="display:flex; justify-content:space-between; font-size:0.9rem; margin-bottom:0.2rem;">
                    <span><span class="badge <?= color_badge_class($row['color']) ?>"><?= e(color_label($row['color'])) ?></span></span>
                    <span><?= (int) $row['qty'] ?> bout. · <?= format_price($row['value']) ?></span>
                </div>
                <div style="background:var(--bg-elevated); border-radius:4px; height:8px;">
                    <div style="background:var(--accent); border-radius:4px; height:8px; width:<?= (int) $row['qty'] / $maxColorQty * 100 ?>%;"></div>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (!$byColor): ?><div class="empty-state">Pas encore de données.</div><?php endif; ?>
    </div>

    <div class="card">
        <h2>Par région</h2>
        <?php foreach ($byRegion as $row): ?>
            <div style="margin-bottom:0.7rem;">
                <div style="display:flex; justify-content:space-between; font-size:0.9rem; margin-bottom:0.2rem;">
                    <span><?= e($row['region']) ?></span>
                    <span><?= (int) $row['qty'] ?> bout.</span>
                </div>
                <div style="background:var(--bg-elevated); border-radius:4px; height:8px;">
                    <div style="background:var(--gold); border-radius:4px; height:8px; width:<?= (int) $row['qty'] / $maxRegionQty * 100 ?>%;"></div>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (!$byRegion): ?><div class="empty-state">Pas encore de données.</div><?php endif; ?>
    </div>
</div>

<div class="grid grid-2 section">
    <div class="card">
        <h2>Par millésime</h2>
        <?php foreach ($byVintage as $row): ?>
            <div style="margin-bottom:0.7rem;">
                <div style="display:flex; justify-content:space-between; font-size:0.9rem; margin-bottom:0.2rem;">
                    <span><?= $row['vintage'] ? (int) $row['vintage'] : 'Non renseigné' ?></span>
                    <span><?= (int) $row['qty'] ?> bout.</span>
                </div>
                <div style="background:var(--bg-elevated); border-radius:4px; height:8px;">
                    <div style="background:var(--accent-light); border-radius:4px; height:8px; width:<?= (int) $row['qty'] / $maxVintageQty * 100 ?>%;"></div>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (!$byVintage): ?><div class="empty-state">Pas encore de données.</div><?php endif; ?>
    </div>

    <div class="card">
        <h2>Cépages les plus présents</h2>
        <?php if (!$byGrape): ?>
            <div class="empty-state">Pas encore de données.</div>
        <?php else: ?>
        <table class="responsive-table">
            <thead><tr><th>Cépage</th><th>Nombre de vins</th></tr></thead>
            <tbody>
            <?php foreach ($byGrape as $row): ?>
                <tr><td data-label="Cépage"><?= e($row['name']) ?></td><td data-label="Nombre de vins"><?= (int) $row['wine_count'] ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<div class="section">
    <a href="/pages/export.php" class="btn"><?= icon('download') ?> Exporter en CSV</a>
</div>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
