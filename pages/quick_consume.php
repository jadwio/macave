<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée.']);
    exit;
}

$sentToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $sentToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token CSRF invalide.']);
    exit;
}

$db = get_db();
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$wineId = (int) ($input['wine_id'] ?? 0);

$stmt = $db->prepare('SELECT id, storage_location_id, quantity FROM stock WHERE wine_id = ? AND quantity > 0 ORDER BY quantity DESC LIMIT 1');
$stmt->execute([$wineId]);
$stockRow = $stmt->fetch();

if (!$stockRow) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Aucune bouteille disponible pour ce vin.']);
    exit;
}

$db->prepare('UPDATE stock SET quantity = quantity - 1 WHERE id = ?')->execute([$stockRow['id']]);
$db->prepare('INSERT INTO consumption_history (wine_id, storage_location_id, quantity, consumed_date) VALUES (?, ?, 1, ?)')
    ->execute([$wineId, $stockRow['storage_location_id'], date('Y-m-d')]);

echo json_encode(['ok' => true, 'remaining' => total_stock_for_wine($db, $wineId)]);
