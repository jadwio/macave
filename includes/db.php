<?php

// Ne jamais dépendre du réglage par défaut de l'hébergeur : une PDOException
// (contrainte SQL, etc.) affichée brute au visiteur fuiterait des détails
// internes. Toujours désactivé en prod, quoi qu'il arrive côté php.ini.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

function get_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}
