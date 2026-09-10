<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/backup.php';

$file = $_GET['file'] ?? '';
// Nom strictement contrôlé : pas de traversée de chemin possible.
if (!preg_match('/^macave_backup_\d{8}_\d{6}\.zip$/', $file)) {
    http_response_code(400);
    exit('Nom de fichier invalide.');
}

$path = backups_dir() . '/' . $file;
if (!is_file($path)) {
    http_response_code(404);
    exit('Sauvegarde introuvable.');
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
