<?php
// Endpoint de sauvegarde planifiée, appelé par un cron (IONOS ou service externe).
// Pas de session : protégé par un jeton dérivé de APP_SECRET.
// URL : /pages/cron_backup.php?token=XXXX

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/backup.php';

header('Content-Type: text/plain; charset=utf-8');

$token = $_GET['token'] ?? '';
if (!hash_equals(backup_cron_token(), $token)) {
    http_response_code(403);
    exit("Jeton invalide.\n");
}

$db = get_db();
$result = create_backup_zip($db);
if (!$result['ok']) {
    http_response_code(500);
    exit('Erreur: ' . $result['error'] . "\n");
}

echo 'OK: ' . $result['file'] . ' (' . round($result['size'] / 1048576, 2) . " Mo)\n";

$email = get_setting($db, 'backup_email');
if ($email) {
    $sent = send_backup_email($result['file'], $email);
    echo $sent ? "Email envoyé à $email\n" : "Échec de l'envoi de l'email.\n";
}
