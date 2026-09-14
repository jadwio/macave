<?php
// Endpoint AJAX du mode magasin : synthèse IA complète d'un vin avant achat.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/ai_enrichment.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Non authentifié.']);
    exit;
}

$sentToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $sentToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token CSRF invalide.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$name = trim($input['name'] ?? '');
if ($name === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Nom du vin manquant.']);
    exit;
}

$producer = trim($input['producer'] ?? '') ?: null;
$vintage = trim((string) ($input['vintage'] ?? '')) ?: null;
$appellation = trim($input['appellation'] ?? '') ?: null;
$region = trim($input['region'] ?? '') ?: null;

echo json_encode(wine_info_summary($name, $producer, $vintage, $appellation, $region));
