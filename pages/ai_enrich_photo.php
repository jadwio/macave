<?php
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

if (empty($_FILES['photo']['tmp_name']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Aucune photo reçue.']);
    exit;
}

$allowed = ['image/jpeg' => true, 'image/png' => true, 'image/webp' => true];
$mime = mime_content_type($_FILES['photo']['tmp_name']);
if (!isset($allowed[$mime])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Format d\'image non supporté.']);
    exit;
}

if ($_FILES['photo']['size'] > 8 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Image trop volumineuse (8 Mo max).']);
    exit;
}

$base64 = base64_encode(file_get_contents($_FILES['photo']['tmp_name']));
$result = enrich_wine_from_photo($base64, $mime);
echo json_encode($result);
