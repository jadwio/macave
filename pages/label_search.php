<?php
// Propose des étiquettes trouvées en ligne pour un vin. Ne modifie rien :
// l'enregistrement se fait uniquement après validation, via wine_detail.php.
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/label_search.php';

header('Content-Type: application/json');

$sentToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $sentToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token CSRF invalide.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$wineId = (int) ($input['wine_id'] ?? 0);

$stmt = get_db()->prepare('SELECT id, name, producer FROM wines WHERE id = ?');
$stmt->execute([$wineId]);
$wine = $stmt->fetch();
if (!$wine) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Vin introuvable.']);
    exit;
}

$candidates = search_label_candidates($wine);
echo json_encode([
    'ok' => true,
    'candidates' => $candidates,
    'message' => $candidates ? null : 'Aucune étiquette trouvée pour ce vin sur les sources libres consultées.',
]);
