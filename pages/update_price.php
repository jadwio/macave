<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

require_csrf();

$id = (int) ($_POST['id'] ?? 0);
$price = ($_POST['price'] ?? '') !== '' ? (float) $_POST['price'] : null;
$note = trim($_POST['note'] ?? '') ?: null;

// Type de relevé : saisi à la main, ou repris d'Open Food Facts.
$type = match ($_POST['source'] ?? 'observed') {
    'open_prices' => 'open_prices',
    default => 'observed',
};

$db = get_db();
$db->prepare('UPDATE wines SET current_estimated_price = ?, price_updated_at = NOW() WHERE id = ?')->execute([$price, $id]);
if ($price !== null && $price > 0) {
    $db->prepare('INSERT INTO price_history (wine_id, price, price_type, note) VALUES (?, ?, ?, ?)')
       ->execute([$id, $price, $type, $note ? mb_substr($note, 0, 255) : null]);
}

header('Location: /pages/wine_detail.php?id=' . $id);
exit;
