<?php
// Recherche de prix relevés en magasin (Open Food Facts / Open Prices) pour un
// vin déjà enregistré, à partir de son code-barres. Lecture seule : rien n'est
// écrit ici, l'utilisateur choisit ensuite un prix à conserver via update_price.php.
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/open_prices.php';

header('Content-Type: application/json');

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Vin non précisé.']);
    exit;
}

$stmt = get_db()->prepare('SELECT barcode FROM wines WHERE id = ?');
$stmt->execute([$id]);
$barcode = $stmt->fetchColumn();

if ($barcode === false) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Vin introuvable.']);
    exit;
}
if (!$barcode) {
    echo json_encode(['ok' => false, 'error' => 'Ce vin n\'a pas de code-barres enregistré. Ajoute-le via « Modifier ».']);
    exit;
}

echo json_encode(open_prices_lookup((string) $barcode) + ['barcode' => $barcode]);
