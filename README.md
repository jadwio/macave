# Ma Cave

Application personnelle de gestion de cave à vin : catalogue des bouteilles,
rangement multi-caves (casiers, armoire à vin) avec grille configurable,
fenêtre de dégustation, valorisation, historique de consommation,
enrichissement des fiches par IA (Google Gemini) et mode « scanner en
magasin » (code-barres, QR code, photo d'étiquette) avec historique
géolocalisé.

PHP/PDO « à plat » (pas de framework, pas de Composer) + MySQL/MariaDB,
pensé pour un hébergement mutualisé classique. Compte admin unique.

## Installation

1. Importer `sql/schema.sql` dans une base MySQL/MariaDB (utf8mb4).
2. Copier `config.example.php` en `config.php` et renseigner :
   - les identifiants de connexion à la base,
   - `ADMIN_USERNAME` et `ADMIN_PASSWORD_HASH` (voir le commentaire du fichier
     pour générer le hash),
   - `APP_SECRET` (valeur aléatoire propre à l'installation),
   - éventuellement une clé API Gemini (facultatif — sans elle,
     l'enrichissement et l'analyse de photos restent indisponibles ; une clé
     peut aussi être renseignée plus tard dans Paramètres).
3. Déployer sur un hébergement PHP (mod_php ou PHP-FPM). `pages/index.php` est
   la page d'accueil (voir `.htaccess`, `DirectoryIndex` déjà configuré).
4. S'assurer que `uploads/` est accessible en écriture par le serveur web.

## Fonctionnalités

- **Catalogue** : liste filtrable (couleur, région, cépage, cave), fiche
  détaillée, détection de doublons, marquage « cadeau », historique de prix.
- **Caves & casiers** : nombre de caves illimité, grille lignes (A, B, C…) ×
  colonnes (1, 2, 3…), génération automatique des emplacements, capacité par
  cellule ou globale, vue casier visuelle, déplacement de bouteilles entre
  caves avec quantité partielle, blocage des emplacements pleins.
- **Étiquettes** : upload manuel, recadrage automatique par IA, recherche en
  ligne (Open Food Facts + Wikimedia Commons) avec validation manuelle.
- **IA (Gemini)** : auto-remplissage depuis le nom ou une photo, estimation de
  prix, un modèle distinct par tâche pour multiplier le quota gratuit, bascule
  automatique et durable sur un autre modèle en cas de quota épuisé (429).
- **Scanner magasin** : code-barres / QR / photo d'étiquette / nom / domaine,
  synthèse avant achat, historique des scans avec géolocalisation (lieu
  retrouvé via Nominatim), fiche re-consultable, ajout direct à la cave.
- **Consommation & stats** : décrément du stock, notes de dégustation,
  répartition par couleur/région/cépage/millésime, valeur et âge moyens.
- **Paramètres** : 5 thèmes, devise, seuil « à boire bientôt », gestion des
  caves, modèles IA et compteur d'usage, changement de mot de passe.
- **Sauvegardes** : export ZIP (base + photos), téléchargement sécurisé,
  déclenchement par cron (jeton), envoi par email.
- **PWA** : installable sur l'écran d'accueil (Android / iOS).

## Sauvegardes automatiques

`pages/cron_backup.php?token=...` (jeton dérivé de `APP_SECRET`, affiché dans
Paramètres) peut être appelé par une tâche planifiée pour générer une
sauvegarde complète (base + photos d'étiquettes) à intervalle régulier.

## Sécurité

- Compte admin unique : session `HttpOnly`/`Secure`/`SameSite`, verrou
  anti-bruteforce (5 tentatives / 15 min), liste noire IP 24 h si l'identifiant
  tenté n'est pas le bon.
- CSRF sur tous les formulaires (connexion incluse), requêtes préparées
  partout, échappement HTML systématique.
- `config.php`, `/backups/` et les fichiers `.sql`/`.log`/`.md` inaccessibles
  par le navigateur ; `uploads/` interdit à l'exécution de scripts.
- En-têtes de sécurité (X-Frame-Options, X-Content-Type-Options,
  Referrer-Policy), HTTPS forcé.

## Licence

Projet personnel, publié tel quel sans garantie.
