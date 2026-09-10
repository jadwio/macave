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

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$id = (int) ($input['id'] ?? 0);

if ($id) {
    $stmt = get_db()->prepare('SELECT * FROM wines WHERE id = ?');
    $stmt->execute([$id]);
    $wine = $stmt->fetch();

    if (!$wine) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Vin introuvable.']);
        exit;
    }
} else {
    // Vin pas encore enregistré (formulaire d'ajout) : on estime à partir des champs saisis.
    $name = trim($input['name'] ?? '');
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Renseigne au moins le nom du vin.']);
        exit;
    }
    $wine = [
        'id' => null,
        'name' => $name,
        'producer' => trim($input['producer'] ?? '') ?: null,
        'region' => trim($input['region'] ?? '') ?: null,
        'vintage' => $input['vintage'] !== '' && isset($input['vintage']) ? (int) $input['vintage'] : null,
    ];
}

$result = estimate_wine_price($wine);
echo json_encode($result);
