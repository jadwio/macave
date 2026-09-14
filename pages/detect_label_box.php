<?php
// Suggestion de recadrage pour l'outil interactif : détecte la zone d'étiquette
// sur une photo, sans jamais l'enregistrer nulle part — l'utilisateur ajuste
// ensuite (ou pas) avant de valider, et c'est SON navigateur qui produit le
// fichier final envoyé au vrai endpoint d'upload.
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ai_enrichment.php';

header('Content-Type: application/json');

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
if ($_FILES['photo']['size'] > 8 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Image trop volumineuse (8 Mo max).']);
    exit;
}
$allowed = ['image/jpeg' => true, 'image/png' => true, 'image/webp' => true];
$mime = mime_content_type($_FILES['photo']['tmp_name']);
if (!isset($allowed[$mime])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Format d\'image non supporté.']);
    exit;
}

// $_FILES[...]['tmp_name'] est déjà un fichier temporaire sur disque, géré et
// nettoyé par PHP en fin de requête — inutile de le déplacer nous-mêmes.
echo json_encode(['ok' => true, 'box' => detect_label_box($_FILES['photo']['tmp_name'])]);
