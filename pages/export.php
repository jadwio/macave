<?php
require_once __DIR__ . '/../includes/bootstrap.php';

$db = get_db();
$wines = $db->query(
    "SELECT w.*, COALESCE(SUM(s.quantity),0) AS qty,
     (SELECT GROUP_CONCAT(gv.name SEPARATOR ', ') FROM grape_varieties gv INNER JOIN wine_grape_varieties wgv ON wgv.grape_variety_id = gv.id WHERE wgv.wine_id = w.id) AS grapes,
     GROUP_CONCAT(DISTINCT CASE WHEN s.quantity > 0 THEN
        CONCAT(COALESCE(CONCAT(c.name, ' — '), ''), COALESCE(sl.name, 'Non assignée'), ' (', s.quantity, ')')
        END ORDER BY c.sort_order, sl.rack_row, sl.rack_col SEPARATOR ' | ') AS emplacements
     FROM wines w
     LEFT JOIN stock s ON s.wine_id = w.id
     LEFT JOIN storage_locations sl ON sl.id = s.storage_location_id
     LEFT JOIN cellars c ON c.id = sl.cellar_id
     GROUP BY w.id ORDER BY w.name"
)->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="ma-cave-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Nom', 'Producteur', 'Couleur', 'Region', 'Appellation', 'Classification', 'Pays', 'Cepages', 'Millesime', 'Quantite', 'Emplacements', 'Boire de', 'Boire jusqua', 'Prix achat', 'Prix actuel', 'Date achat'], ';');

foreach ($wines as $w) {
    fputcsv($out, [
        $w['name'],
        $w['producer'],
        color_label($w['color']),
        $w['region'],
        $w['appellation'],
        $w['classification'],
        $w['country'],
        $w['grapes'],
        $w['vintage'],
        $w['qty'],
        $w['emplacements'],
        $w['drink_from_year'],
        $w['drink_until_year'],
        $w['purchase_price'],
        $w['current_estimated_price'],
        $w['purchase_date'],
    ], ';');
}
fclose($out);
exit;
