<?php
// Endpoint unifié du WineResolver : expose les trois branches
// (EAN, nom, domaine/appellation) derrière une seule requête.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/wine_resolver.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Non authentifié.']);
    exit;
}

// Les branches nom/domaine déclenchent des appels IA : on protège du CSRF.
$sentToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $sentToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token CSRF invalide.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$result = wine_resolve($input);

if (!$result['ok']) {
    http_response_code(200);
}
echo json_encode($result);
