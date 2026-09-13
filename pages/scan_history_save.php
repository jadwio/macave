<?php
// Enregistre ou supprime une entrée de l'historique de scan (page Scanner).
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/scan_history.php';
require_once __DIR__ . '/../includes/ai_enrichment.php'; // crop_label_to_bottle()

header('Content-Type: application/json');

$sentToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $sentToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token CSRF invalide.']);
    exit;
}

$db = get_db();

if (($_POST['action'] ?? '') === 'delete') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        delete_scan_history($db, $id);
    }
    echo json_encode(['ok' => true]);
    exit;
}

$allowedTypes = ['photo', 'barcode', 'qr', 'name'];
$scanType = $_POST['scan_type'] ?? '';
$wineName = trim($_POST['wine_name'] ?? '');
if (!in_array($scanType, $allowedTypes, true) || $wineName === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Données de scan invalides.']);
    exit;
}

$lat = null;
$lon = null;
if (isset($_POST['latitude'], $_POST['longitude']) && is_numeric($_POST['latitude']) && is_numeric($_POST['longitude'])) {
    $lat = (float) $_POST['latitude'];
    $lon = (float) $_POST['longitude'];
    if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
        $lat = null;
        $lon = null;
    }
}

// Le détail complet (description, réputation, accords...) est renvoyé tel quel
// par l'IA côté client, encodé en base64 : envoyé en JSON brut (accolades, deux-points),
// ce champ était systématiquement vidé en cours de route par le pare-feu applicatif de
// l'hébergeur. On revalide ici (JSON bien formé, taille plafonnée) avant de le stocker,
// pour pouvoir réafficher la fiche plus tard sans reconsommer de quota IA.
$detailsJson = null;
$rawB64 = $_POST['details_json'] ?? '';
if ($rawB64 !== '' && strlen($rawB64) <= 30000) {
    $rawDetails = base64_decode($rawB64, true);
    if ($rawDetails !== false) {
        $decoded = json_decode($rawDetails, true);
        if (is_array($decoded)) {
            $detailsJson = json_encode($decoded, JSON_UNESCAPED_UNICODE);
        }
    }
}

// Ce recadrage n'était jamais appliqué ici (contrairement à l'ajout d'étiquette
// en cave) : la photo du scanner restait telle quelle, bouteille entière comprise.
// Appelé après l'enregistrement (fichier déjà sur disque), en second plan comme
// le reste de cette sauvegarde : un échec de détection laisse juste la photo
// d'origine, sans jamais faire échouer le scan.
$photoPath = upload_scan_photo('photo');
if ($photoPath) {
    crop_label_to_bottle(__DIR__ . '/../' . $photoPath);
}

$entry = save_scan_history($db, [
    'scan_type' => $scanType,
    'wine_name' => $wineName,
    'producer' => trim($_POST['producer'] ?? ''),
    'vintage' => (int) ($_POST['vintage'] ?? 0),
    'region' => trim($_POST['region'] ?? ''),
    'color' => trim($_POST['color'] ?? ''),
    'price_low' => is_numeric($_POST['price_low'] ?? null) ? (float) $_POST['price_low'] : null,
    'price_high' => is_numeric($_POST['price_high'] ?? null) ? (float) $_POST['price_high'] : null,
    'photo_path' => $photoPath,
    'latitude' => $lat,
    'longitude' => $lon,
    'details_json' => $detailsJson,
]);

echo json_encode(['ok' => true, 'entry' => $entry]);
