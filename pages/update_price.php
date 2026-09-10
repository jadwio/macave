<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

require_csrf();

$id = (int) ($_POST['id'] ?? 0);
$price = $_POST['price'] !== '' ? (float) $_POST['price'] : null;
$source = ($_POST['source'] ?? 'manual') === 'ai' ? 'ai_estimate' : 'manual_estimate';

$db = get_db();
$db->prepare('UPDATE wines SET current_estimated_price = ?, price_updated_at = NOW() WHERE id = ?')->execute([$price, $id]);
if ($price !== null) {
    $db->prepare('INSERT INTO price_history (wine_id, price, price_type) VALUES (?, ?, ?)')->execute([$id, $price, $source]);
}

header('Location: /pages/wine_detail.php?id=' . $id);
exit;
