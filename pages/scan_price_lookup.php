<?php
// Prix réel (Open Food Facts) pour un vin identifié en mode magasin, appelé en
// second plan après la synthèse IA : celle-ci reste affichée immédiatement,
// ce relevé la complète ou la remplace dès qu'il arrive. Lecture seule.
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/open_prices.php';

header('Content-Type: application/json');

$name = trim($_GET['name'] ?? '');
$producer = trim($_GET['producer'] ?? '') ?: null;
$barcode = preg_replace('/\D/', '', $_GET['barcode'] ?? '');

if ($barcode === '') {
    if ($name === '') {
        echo json_encode(['ok' => false, 'error' => 'Nom manquant.']);
        exit;
    }
    $barcode = guess_barcode_from_name($name, $producer) ?? '';
}

if ($barcode === '') {
    echo json_encode(['ok' => true, 'found' => false, 'reason' => 'no_barcode']);
    exit;
}

$lookup = open_prices_lookup($barcode);
$summary = ($lookup['ok'] ?? false) ? summarize_prices($lookup['prices'] ?? []) : null;

if ($summary === null) {
    echo json_encode(['ok' => true, 'found' => false, 'barcode' => $barcode, 'reason' => 'no_prices']);
    exit;
}

echo json_encode(['ok' => true, 'found' => true, 'barcode' => $barcode] + $summary);
