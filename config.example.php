<?php
// Modèle de configuration — copie ce fichier en config.php et renseigne tes
// propres valeurs. config.php ne doit JAMAIS être commité (voir .gitignore)
// ni accessible publiquement (voir .htaccess, qui bloque déjà son accès direct).

// --- Base de données MySQL / MariaDB ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'nom_de_ta_base');
define('DB_USER', 'utilisateur_mysql');
define('DB_PASS', 'mot_de_passe_mysql');

// --- Authentification (compte admin unique) ---
// Génère le hash depuis une machine avec PHP :
//   php -r "echo password_hash('ton_mot_de_passe', PASSWORD_DEFAULT), PHP_EOL;"
// Le mot de passe reste modifiable ensuite dans Paramètres.
define('ADMIN_USERNAME', 'admin');
define('ADMIN_PASSWORD_HASH', '');

// --- Clé secrète (signe le jeton de sauvegarde planifiée) ---
// Une valeur aléatoire propre à ton installation :
//   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
define('APP_SECRET', 'CHANGE_ME_valeur_aleatoire_unique');

// --- IA (Google Gemini, palier gratuit) ---
// Clé gratuite sur https://aistudio.google.com/apikey — optionnel : sans clé,
// l'enrichissement et l'analyse de photos restent simplement indisponibles
// (renseignable aussi plus tard dans Paramètres).
define('GEMINI_API_KEY', 'CHANGE_ME');
define('GEMINI_MODEL', 'gemini-flash-latest');

// --- Divers ---
define('APP_TIMEZONE', 'Europe/Paris');
date_default_timezone_set(APP_TIMEZONE);
