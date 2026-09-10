<?php
// Sauvegarde complète : dump SQL de la base + photos d'étiquettes, dans un ZIP.
// Pas de mysqldump sur mutualisé : le dump est généré en PHP via PDO.

function backups_dir(): string
{
    $dir = __DIR__ . '/../backups';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    // Défense en profondeur : jamais servi directement par Apache.
    $ht = $dir . '/.htaccess';
    if (!file_exists($ht)) {
        file_put_contents($ht, "Require all denied\n");
    }
    return $dir;
}

function backup_cron_token(): string
{
    return hash('sha256', APP_SECRET . '|cron-backup');
}

function generate_sql_dump(PDO $db): string
{
    $out = "-- Ma Cave — sauvegarde du " . date('Y-m-d H:i:s') . "\n";
    $out .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";

    $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $create = $db->query("SHOW CREATE TABLE `$table`")->fetch();
        $out .= "DROP TABLE IF EXISTS `$table`;\n" . $create['Create Table'] . ";\n\n";

        $rows = $db->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $cols = array_map(fn($c) => "`$c`", array_keys($row));
            $vals = array_map(fn($v) => $v === null ? 'NULL' : $db->quote((string) $v), array_values($row));
            $out .= "INSERT INTO `$table` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ");\n";
        }
        if ($rows) {
            $out .= "\n";
        }
    }
    $out .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    return $out;
}

/**
 * @return array{ok:bool, file?:string, size?:int, error?:string}
 */
function create_backup_zip(PDO $db): array
{
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'error' => 'Extension ZipArchive indisponible sur ce serveur.'];
    }

    $dir = backups_dir();
    $filename = 'macave_backup_' . date('Ymd_His') . '.zip';
    $path = $dir . '/' . $filename;

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return ['ok' => false, 'error' => 'Impossible de créer l\'archive.'];
    }

    $zip->addFromString('database.sql', generate_sql_dump($db));

    $labelsDir = __DIR__ . '/../uploads/labels';
    if (is_dir($labelsDir)) {
        foreach (scandir($labelsDir) as $f) {
            if ($f[0] === '.') {
                continue;
            }
            $full = $labelsDir . '/' . $f;
            if (is_file($full)) {
                $zip->addFile($full, 'uploads/labels/' . $f);
            }
        }
    }
    $zip->close();

    // Rotation : ne garder que les 5 plus récentes
    $all = list_backups();
    foreach (array_slice($all, 5) as $old) {
        @unlink($dir . '/' . $old['name']);
    }

    return ['ok' => true, 'file' => $filename, 'size' => filesize($path)];
}

/**
 * @return list<array{name:string, size:int, mtime:int}> triés du plus récent au plus ancien
 */
function list_backups(): array
{
    $dir = backups_dir();
    $out = [];
    foreach (glob($dir . '/macave_backup_*.zip') ?: [] as $f) {
        $out[] = ['name' => basename($f), 'size' => filesize($f), 'mtime' => filemtime($f)];
    }
    usort($out, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $out;
}

const BACKUP_EMAIL_MAX_BYTES = 10 * 1024 * 1024;

function send_backup_email(string $zipFilename, string $to): bool
{
    $path = backups_dir() . '/' . $zipFilename;
    if (!is_file($path)) {
        return false;
    }
    $from = 'macave@famille-dumas.fr';
    $subject = 'Ma Cave — sauvegarde du ' . date('d/m/Y H:i');

    if (filesize($path) > BACKUP_EMAIL_MAX_BYTES) {
        // Trop lourd pour un email : on notifie seulement, l'archive reste sur le serveur.
        $body = "La sauvegarde $zipFilename a été créée (" . round(filesize($path) / 1048576, 1) . " Mo), "
            . "trop volumineuse pour être jointe.\nTélécharge-la depuis la page Paramètres de l'application.";
        return mail($to, $subject, $body, "From: $from\r\nContent-Type: text/plain; charset=utf-8");
    }

    $boundary = 'b' . bin2hex(random_bytes(12));
    $headers = "From: $from\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"$boundary\"";
    $body = "--$boundary\r\n"
        . "Content-Type: text/plain; charset=utf-8\r\n\r\n"
        . "Sauvegarde automatique de Ma Cave (base de données + photos d'étiquettes) en pièce jointe.\r\n\r\n"
        . "--$boundary\r\n"
        . "Content-Type: application/zip; name=\"$zipFilename\"\r\n"
        . "Content-Transfer-Encoding: base64\r\n"
        . "Content-Disposition: attachment; filename=\"$zipFilename\"\r\n\r\n"
        . chunk_split(base64_encode(file_get_contents($path)))
        . "--$boundary--";
    return mail($to, $subject, $body, $headers);
}
