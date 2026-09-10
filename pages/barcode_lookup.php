<?php
// Recherche d'un produit par code-barres EAN.
// Délègue à WineResolver (branche « recherche EAN » → Open Food Facts).
// Pas d'enrichissement IA ici : le formulaire enchaîne lui-même l'appel IA,
// et on veut que le retour du scan reste immédiat.
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/wine_resolver.php';

header('Content-Type: application/json');

$result = wine_resolve_by_ean($_GET['ean'] ?? '', false);

if (!$result['ok']) {
    http_response_code(str_contains($result['error'], 'EAN invalide') ? 400 : 200);
    echo json_encode(['ok' => false, 'error' => $result['error']]);
    exit;
}

$w = $result['wine'];
echo json_encode(['ok' => true, 'data' => [
    'ean' => $w['ean'],
    'name' => $w['name'],
    'producer' => $w['producer'],
    'volume_ml' => $w['volume_ml'],
    'color' => $w['color'],
    'country' => $w['country'],
], 'sources' => $result['sources']]);
