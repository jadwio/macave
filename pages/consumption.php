<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$db = get_db();
$history = $db->query(
    'SELECT ch.*, w.name AS wine_name, w.id AS wine_id, w.label_photo_path,
     sl.name AS location_name, c.name AS cellar_name
     FROM consumption_history ch
     INNER JOIN wines w ON w.id = ch.wine_id
     LEFT JOIN storage_locations sl ON sl.id = ch.storage_location_id
     LEFT JOIN cellars c ON c.id = sl.cellar_id
     ORDER BY ch.consumed_date DESC'
)->fetchAll();

$pageTitle = 'Historique de consommation';
$activeNav = 'consumption';
require __DIR__ . '/../includes/layout_header.php';
?>

<h1>Historique de consommation</h1>

<div class="card">
    <?php if (!$history): ?>
        <div class="empty-state">Aucune bouteille consommée pour l'instant.</div>
    <?php else: ?>
    <table class="responsive-table">
        <thead><tr><th></th><th>Date</th><th>Vin</th><th>Quantité</th><th>Emplacement</th><th>Note</th><th>Occasion</th><th>Commentaire</th></tr></thead>
        <tbody>
        <?php foreach ($history as $h): ?>
            <tr>
                <td data-label=""><?= wine_thumbnail_html($h['label_photo_path'] ?? null) ?></td>
                <td data-label="Date"><?= e($h['consumed_date']) ?></td>
                <td data-label="Vin"><a href="/pages/wine_detail.php?id=<?= (int) $h['wine_id'] ?>"><?= e($h['wine_name']) ?></a></td>
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

<?php require __DIR__ . '/../includes/layout_footer.php'; ?>
