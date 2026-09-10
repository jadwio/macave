# Journal des versions — Ma Cave

Historique des évolutions de l'application de gestion de cave à vin
(macave.famille-dumas.fr). Reconstitué à partir des échanges de développement.

## 2026-09-10 — Publication du code et export enrichi

- Publication du code source sur GitHub, secrets retirés : `config.php` n'est
  plus versionné, `config.example.php` sert de modèle.
- Export CSV : ajout de la colonne « Emplacements » (cave + case + quantité,
  par bouteille répartie).

## 2026-08-26 — IA plus résiliente

- En cas de quota Gemini épuisé (429) sur le modèle d'une tâche, l'appel
  rejoue automatiquement sur d'autres modèles (le palier gratuit est compté
  par modèle) au lieu d'échouer immédiatement.
- Bascule durable : le modèle de secours qui répond devient le nouveau choix
  par défaut de la tâche, pour ne plus retenter un modèle à sec à chaque appel.
- Revue de sécurité complète : jeton CSRF ajouté au formulaire de connexion,
  `.htaccess` dédié interdisant l'exécution de scripts dans `uploads/`.

## 2026-08-26 — Historique du scanner magasin

- Chaque vin scanné ou photographié en mode magasin est conservé dans un
  historique, avec géolocalisation optionnelle (lieu retrouvé automatiquement
  via Nominatim) pour se souvenir du magasin.
- La synthèse IA complète est stockée avec l'entrée : la fiche est
  re-consultable à tout moment sans reconsommer de quota, et le vin peut être
  ajouté à la cave directement depuis l'historique.
- Mode magasin : scan par photo de l'étiquette (lecture + synthèse en un seul
  appel IA).

## 2026-08-25 — Recherche d'étiquettes et multi-modèles IA

- Recherche d'étiquette en ligne (Open Food Facts + Wikimedia Commons) avec
  filtrage de pertinence, agrandissement en pop-up, validation manuelle avant
  enregistrement ; upload manuel depuis la galerie ou un fichier.
- Un modèle Gemini distinct par tâche (texte, vision, prix, résumé, recadrage)
  pour multiplier le quota gratuit ; liste des modèles récupérée dynamiquement,
  compteur d'usage journalier par modèle, repli automatique.
- Page Paramètres complète : thèmes, devise, seuil « à boire bientôt »,
  gestion des caves, changement de mot de passe, sauvegardes.

## 2026-08-24 — Multi-caves et rangement

- Nombre de caves illimité ; grille par cave (lignes en lettres, colonnes en
  chiffres) avec génération automatique des emplacements.
- Capacité configurable par cellule ou globale à la cave ; vue casier visuelle
  par cave avec sélecteur d'emplacement.
- Déplacement de bouteilles entre caves/emplacements avec quantité partielle ;
  les emplacements pleins ne sont plus proposés.
- PWA installable (manifest, service worker), 5 thèmes de couleur.
- Sauvegardes ZIP (base + photos), déclenchement par cron, envoi par email.

## 2026-08-21 — Lancement initial

- Catalogue des vins (fiche détaillée, cépages, accords, fenêtre de
  dégustation, prix d'achat et estimé, historique de prix, marquage cadeau).
- Enrichissement IA (Google Gemini) depuis le nom ou une photo d'étiquette ;
  estimation de prix.
- Stock par emplacement, historique de consommation (note, occasion),
  statistiques, export CSV.
- Scanner magasin : code-barres / QR code (Open Food Facts), recherche par nom
  ou par domaine/appellation (Wikipédia + IA).
- Authentification par compte admin unique, verrou anti-bruteforce et liste
  noire IP, hébergement IONOS + Cloudflare.
