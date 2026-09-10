<?php
/**
 * Version de l'application + historique des versions (changelog).
 *
 * Source UNIQUE : la page Paramètres (section « Journal des versions ») et le
 * fichier CHANGELOG.md à la racine du projet sont dérivés de ce tableau.
 * À chaque nouvelle version : incrémenter APP_VERSION, ajouter une entrée en
 * tête de CHANGELOG ci-dessous, puis reporter la même entrée en haut de
 * CHANGELOG.md (pas de génération automatique).
 *
 * L'historique antérieur au 2026-09-10 a été reconstitué a posteriori à partir
 * des échanges de développement ; les dates sont approximatives mais ordonnées.
 */

const APP_VERSION = '2.7.1';

const CHANGELOG = [
    [
        'version' => '2.7.1',
        'date' => '2026-09-10',
        'title' => 'Scan photo en magasin : fiabilité',
        'items' => [
            "La photo de l'étiquette est réduite (~1600 px) avant l'envoi : une image de téléphone brute faisait souvent dépasser le délai réseau pendant l'analyse, obligeant à relancer le scan plusieurs fois.",
            "Nouvelle tentative automatique (jusqu'à 3) si la réponse de l'IA n'arrive pas — plus besoin de refaire la manipulation à la main.",
            "Le journal des requêtes IA enregistre désormais la taille de l'image, la durée totale et le détail des tentatives (modèle, code, temps de réponse), pour diagnostiquer ce type de lenteur.",
        ],
    ],
    [
        'version' => '2.7.0',
        'date' => '2026-09-10',
        'title' => 'Publication du code, export enrichi et suivi de version',
        'items' => [
            "Code source publié sur GitHub (github.com/jadwio/macave), secrets retirés du dépôt : config.php n'est plus versionné, config.example.php sert de modèle.",
            "Export CSV : nouvelle colonne « Emplacements » (cave + case + quantité pour chaque bouteille, y compris quand elle est répartie sur plusieurs caves).",
            "Mise en place du suivi de version : le numéro est affiché en bas de chaque page et l'historique complet dans Paramètres → Journal des versions.",
        ],
    ],
    [
        'version' => '2.6.1',
        'date' => '2026-08-26',
        'title' => 'Revue de sécurité',
        'items' => [
            "Jeton CSRF ajouté au formulaire de connexion.",
            "Fichier .htaccess dédié interdisant l'exécution de tout script dans le dossier uploads/.",
            "Audit complet (injection SQL, XSS, traversée de chemin, validation des uploads, exposition des sauvegardes) — aucun autre défaut trouvé.",
        ],
    ],
    [
        'version' => '2.6.0',
        'date' => '2026-08-26',
        'title' => 'IA plus résiliente face aux quotas',
        'items' => [
            "En cas de quota Gemini épuisé (erreur 429) sur le modèle d'une tâche, l'appel rejoue automatiquement sur d'autres modèles disponibles — le palier gratuit étant compté par modèle, un autre peut encore avoir du crédit.",
            "Bascule durable : le modèle de secours qui répond devient le nouveau choix par défaut de la tâche, pour ne plus retenter un modèle à sec à chaque requête.",
        ],
    ],
    [
        'version' => '2.5.0',
        'date' => '2026-08-26',
        'title' => 'Fiche de scan re-consultable',
        'items' => [
            "La synthèse IA complète d'un vin scanné (prix, garde, dégustation, réputation, accords) est conservée avec son entrée d'historique.",
            "Elle est re-consultable à tout moment sans reconsommer de quota IA, et le vin peut être ajouté à la cave directement depuis l'historique.",
        ],
    ],
    [
        'version' => '2.4.0',
        'date' => '2026-08-26',
        'title' => 'Historique du scanner, géolocalisé',
        'items' => [
            "Chaque vin scanné ou photographié en mode magasin est conservé dans un historique consultable.",
            "Géolocalisation optionnelle par entrée : le lieu est retrouvé automatiquement (Nominatim / OpenStreetMap) pour se souvenir du magasin où le vin a été vu.",
            "Suppression possible de chaque entrée d'historique.",
        ],
    ],
    [
        'version' => '2.3.0',
        'date' => '2026-08-26',
        'title' => 'Scan par photo d\'étiquette (mode magasin)',
        'items' => [
            "Nouvelle méthode dans « Scanner un vin en magasin » : photographier l'étiquette. Lecture et synthèse complète en un seul appel IA.",
            "Fonctionne sur n'importe quelle bouteille, y compris sans code-barres exploitable — la méthode la plus fiable en rayon.",
        ],
    ],
    [
        'version' => '2.2.0',
        'date' => '2026-08-25',
        'title' => 'Modèles IA par tâche et page Paramètres complète',
        'items' => [
            "Un modèle Gemini distinct par nature d'appel (nom, photo, prix, synthèse, recadrage) : le quota gratuit étant compté par modèle, la capacité journalière est multipliée d'autant.",
            "Liste des modèles récupérée dynamiquement depuis l'API, repli automatique, compteur d'usage journalier par modèle.",
            "Page Paramètres : thèmes de couleur, devise, seuil « à boire bientôt », gestion des caves, clé API personnalisée, changement de mot de passe, sauvegardes.",
        ],
    ],
    [
        'version' => '2.1.0',
        'date' => '2026-08-25',
        'title' => 'Recherche d\'étiquettes en ligne',
        'items' => [
            "Si l'étiquette d'un vin manque, recherche automatique en ligne (Open Food Facts + Wikimedia Commons) avec filtrage de pertinence.",
            "Aperçu agrandi en pop-up, validation manuelle obligatoire avant enregistrement — rien n'est stocké sans confirmation.",
            "Upload manuel depuis la galerie ou un fichier ; recadrage automatique par IA autour de la bouteille.",
        ],
    ],
    [
        'version' => '2.0.0',
        'date' => '2026-08-25',
        'title' => 'Caves multiples',
        'items' => [
            "Nombre de caves illimité (casiers, armoire à vin…), chacune avec sa propre grille : lignes en lettres (A, B, C…), colonnes en chiffres.",
            "Génération automatique des emplacements sur toute la grille ; capacité configurable par cellule ou globale à la cave.",
            "Vue casier visuelle par cave (onglets), sélecteur d'emplacement au clic, listes déroulantes cave → emplacement en cascade.",
            "Déplacement de bouteilles entre caves et emplacements avec quantité partielle ; les emplacements pleins ne sont plus proposés.",
        ],
    ],
    [
        'version' => '1.4.0',
        'date' => '2026-08-24',
        'title' => 'Sauvegardes',
        'items' => [
            "Export ZIP complet : dump SQL de la base + toutes les photos d'étiquettes.",
            "Téléchargement sécurisé depuis Paramètres ; déclenchement automatique par tâche cron (URL protégée par jeton) ; envoi par email optionnel.",
        ],
    ],
    [
        'version' => '1.3.0',
        'date' => '2026-08-24',
        'title' => 'Casier, thèmes et installation mobile',
        'items' => [
            "Vue casier visuelle avec remplissage codé par couleur.",
            "5 thèmes de couleur (Bordeaux, Bourgogne, Nuit, Ardoise, Champagne), devise d'affichage (EUR, CHF, USD, GBP), seuil « à boire bientôt » réglable.",
            "Application installable sur l'écran d'accueil (PWA : manifeste, service worker, bannière d'installation).",
        ],
    ],
    [
        'version' => '1.2.0',
        'date' => '2026-08-23',
        'title' => 'Scanner en magasin',
        'items' => [
            "Nouvelle page « Scanner un vin en magasin » : synthèse avant achat (prix indicatif, potentiel de garde, style, accords), sans rien ajouter à la cave.",
            "Scan du code-barres / QR code à la caméra ou saisie du code EAN (Open Food Facts), recherche par nom, recherche d'un domaine ou d'une appellation (Wikipédia + IA).",
        ],
    ],
    [
        'version' => '1.1.0',
        'date' => '2026-08-22',
        'title' => 'IA : photo d\'étiquette et estimation de prix',
        'items' => [
            "Enrichissement d'une fiche à partir d'une photo de l'étiquette (lecture automatique du vin).",
            "Bouton « Estimation IA » du prix d'une bouteille, tracé dans un historique de prix.",
            "Détection de doublon à l'ajout d'un vin (fusion : ajout au stock existant plutôt que création d'une seconde fiche).",
        ],
    ],
    [
        'version' => '1.0.0',
        'date' => '2026-08-21',
        'title' => 'Lancement initial',
        'items' => [
            "Catalogue des vins : fiche détaillée (producteur, région, appellation, pays, couleur, cépages, degré, millésime, fenêtre de dégustation, description, accords mets-vin, notes, marquage « cadeau »).",
            "Stock par emplacement, consommation d'une bouteille (note, occasion, commentaire), historique de consommation.",
            "Tableau de bord (nombre de bouteilles, valorisation, vins à leur apogée / à boire bientôt), statistiques (répartition couleur / région / cépage / millésime).",
            "Enrichissement IA (Google Gemini, palier gratuit) d'une fiche à partir du nom du vin.",
            "Recherche filtrée, export CSV.",
            "Authentification par compte admin unique : session sécurisée, verrou anti-bruteforce (5 tentatives / 15 min), liste noire IP 24 h.",
            "Hébergement IONOS mutualisé + Cloudflare, sous-domaine macave.famille-dumas.fr.",
        ],
    ],
];
