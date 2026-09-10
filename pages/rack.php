<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$db = get_db();
$cellars = get_cellars($db);

$pageTitle = 'Casier';
$activeNav = 'rack';
require __DIR__ . '/../includes/layout_header.php';
?>

<div class="wine-detail-header">
    <div>
        <h1>Mes caves</h1>
        <?php
        $grandTotal = (int) $db->query(
            'SELECT COALESCE(SUM(s.quantity), 0) FROM stock s
             INNER JOIN storage_locations sl ON sl.id = s.storage_location_id'
        )->fetchColumn();
        ?>
        <p style="color:var(--text-muted); margin-top:-0.6rem;"><?= $grandTotal ?> bouteille(s) rangée(s) sur <?= count($cellars) ?> cave(s)</p>
    </div>
    <div class="header-actions">
        <a href="/pages/rack_config.php" class="btn"><?= icon('settings', 16) ?> Configurer</a>
    </div>
</div>

<?php if (!$cellars): ?>
    <div class="card"><div class="empty-state">Aucune cave configurée. <a href="/pages/rack_config.php">Crée ta première cave</a>.</div></div>
<?php else: ?>

<?php if (count($cellars) > 1): ?>
<div class="tabs">
    <?php foreach ($cellars as $i => $c): ?>
        <button type="button" class="tab-btn <?= $i === 0 ? 'active' : '' ?>" data-tab="cellar-<?= (int) $c['id'] ?>">
            <?= e($c['name']) ?>
        </button>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
<?php foreach ($cellars as $i => $cellar):
    $rows = (int) $cellar['grid_rows'];
    $cols = (int) $cellar['grid_cols'];
    $grid = cellar_grid($db, (int) $cellar['id']);
    $cellarTotal = 0;
    $cellarCapacity = 0;
    $cellCount = 0;
    foreach ($grid as $r) {
        foreach ($r as $cell) {
            $cellarTotal += (int) $cell['bottle_count'];
            $cellarCapacity += effective_cell_capacity($cell, $cellar);
            $cellCount++;
        }
    }
?>
    <div class="tab-panel <?= $i === 0 ? 'active' : '' ?>" id="cellar-<?= (int) $cellar['id'] ?>">
        <div style="display:flex; justify-content:space-between; align-items:baseline; flex-wrap:wrap; gap:0.5rem; margin-bottom:0.8rem;">
            <h2 style="margin-bottom:0;"><?= e($cellar['name']) ?></h2>
            <span style="color:var(--text-muted); font-size:0.9rem;">
                <?php if ($cellar['location']): ?><?= e($cellar['location']) ?> · <?php endif; ?>
                <?= $cellarTotal ?> / <?= $cellarCapacity ?> bouteille(s) · <?= $cellCount ?> emplacement(s)
            </span>
        </div>
        <div class="rack-grid-wrap">
        <table class="rack-grid" style="min-width:<?= 22 + $cols * 100 ?>px">
            <thead>
                <tr>
                    <th></th>
                    <?php for ($c = 1; $c <= $cols; $c++): ?><th><?= e(cellar_column_label($cellar, $c)) ?></th><?php endfor; ?>
                </tr>
            </thead>
            <tbody>
            <?php for ($r = 1; $r <= $rows; $r++): ?>
                <tr>
                    <th><?= e(rack_row_letter($r)) ?></th>
                    <?php for ($c = 1; $c <= $cols; $c++):
                        $cell = $grid[$r][$c] ?? null;
                        $qty = $cell ? (int) $cell['bottle_count'] : 0;
                        $capacity = effective_cell_capacity($cell, $cellar);
                        $fillClass = 'rack-cell-empty';
                        if ($qty > 0 && $capacity > 0 && $qty >= $capacity) {
                            $fillClass = 'rack-cell-full';
                        } elseif ($qty > 0) {
                            $fillClass = 'rack-cell-filled';
                        }
                    ?>
                        <td>
                            <?php if ($cell): ?>
                                <a href="/pages/wines_list.php?location_id=<?= (int) $cell['id'] ?>" class="rack-cell <?= $fillClass ?><?= !empty($cell['hard_access']) ? ' rack-cell-hard' : '' ?>" title="<?= e($cell['name']) ?><?= !empty($cell['hard_access']) ? ' — peu accessible' : '' ?>">
                                    <?php if ($qty > 0): ?><?= wine_thumbnail_html($cell['thumb_photo'] ?: null, 'rack-cell-thumb') ?><?php endif; ?>
                                    <span class="rack-cell-name"><?= e($cell['name']) ?></span>
                                    <span class="rack-cell-count"><?= $qty ?> / <?= $capacity ?></span>
                                </a>
                            <?php else: ?>
                                <span class="rack-cell rack-cell-unassigned">—</span>
                            <?php endif; ?>
                        </td>
                    <?php endfor; ?>
                </tr>
            <?php endfor; ?>
            </tbody>
        </table>
        </div>
    </div>
<?php endforeach; ?>
</div>

<?php endif; ?>

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
