<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if ($q === '' || mb_strlen($q) < 2) {
    echo json_encode(['ok' => true, 'results' => []]);
    exit;
}

$db = get_db();
$stmt = $db->prepare(
    "SELECT w.id, w.name, w.producer, w.vintage, w.color, w.label_photo_path,
     COALESCE(SUM(s.quantity), 0) AS qty,
     GROUP_CONCAT(DISTINCT CASE WHEN s.quantity > 0 THEN sl.name END SEPARATOR ', ') AS locations_summary
     FROM wines w
     LEFT JOIN stock s ON s.wine_id = w.id
     LEFT JOIN storage_locations sl ON sl.id = s.storage_location_id
     WHERE w.name LIKE :q1 OR w.producer LIKE :q2 OR w.region LIKE :q3
        OR EXISTS (
            SELECT 1 FROM wine_grape_varieties wgv
            INNER JOIN grape_varieties gv ON gv.id = wgv.grape_variety_id
            WHERE wgv.wine_id = w.id AND gv.name LIKE :q4
        )
     GROUP BY w.id
     ORDER BY w.name
     LIMIT 8"
);
$like = '%' . $q . '%';
$stmt->execute(['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like]);
$rows = $stmt->fetchAll();

$results = array_map(function ($w) {
    return [
        'id' => (int) $w['id'],
        'name' => $w['name'],
        'producer' => $w['producer'],
        'vintage' => $w['vintage'] !== null ? (int) $w['vintage'] : null,
        'color' => $w['color'],
        'qty' => (int) $w['qty'],
        'locations' => $w['locations_summary'] ?: null,
    ];
}, $rows);

echo json_encode(['ok' => true, 'results' => $results]);
