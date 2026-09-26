# Journal des versions — Ma Cave

Historique des évolutions de l'application de gestion de cave à vin
(macave.famille-dumas.fr). Le numéro de version courant est affiché en bas de
chaque page et dans Paramètres → Journal des versions.

Source unique : `includes/changelog.php`. Toute nouvelle version doit être
ajoutée là **et** reportée ici.

L'historique antérieur au 2026-09-10 a été reconstitué a posteriori à partir
des échanges de développement — les dates sont approximatives mais ordonnées.

## [2.10.19] — 2026-09-26 — IA : vérification à chaque connexion

- Suite à la quarantaine automatique des modèles saturés (v2.10.18), demande
  explicite de vérifier que l'IA est prête dès la connexion plutôt que
  d'attendre un scan raté. Chaque connexion réussie rafraîchit la liste des
  modèles disponibles (reste sur le cache tant qu'il est valide, pas
  d'aller-retour réseau systématique) puis oublie les modèles figés par
  tâche pour repartir sur le plus performant recommandé du moment — les
  modèles en quarantaine restent volontairement protégés, une connexion ne
  doit pas annuler une mise à l'écart encore valide.
- Gouverné par le même réglage que la réinitialisation quotidienne
  (Paramètres → Intelligence artificielle), renommé en conséquence.

## [2.10.18] — 2026-09-26 — IA : quarantaine automatique d'un modèle saturé

- Analyse du journal IA du 25/09 (17h38-19h07) : tous les scans photo ont
  échoué avec la même erreur Gemini « This model is currently experiencing
  high demand » (503, saturation temporaire côté Google) — rien à voir avec
  l'app, mais rien ne permettait non plus de s'en remettre tout seul : le
  scan restait bloqué jusqu'à un clic manuel sur « Réinitialiser maintenant »
  dans Paramètres.
- Corrigé : dès qu'un modèle répond « saturé » ou « quota dépassé », il est
  mis de côté automatiquement pendant quelques minutes — le scan suivant
  (même une tâche différente, même bien plus tard) l'évite de lui-même au
  lieu d'y retomber à chaque tentative. Un modèle « Lite » (moins demandé)
  est aussi désormais systématiquement inclus dans les modèles de secours
  essayés. Visible dans Paramètres → Intelligence artificielle si des
  modèles sont actuellement de côté.
- Corrigé en passant : le journal IA perdait le détail des tentatives (quels
  modèles essayés, dans quel ordre) dès qu'une erreur HTTP directe de Gemini
  était renvoyée — seul le corps brut de la toute dernière tentative restait
  visible, ce qui a compliqué cette analyse.

## [2.10.17] — 2026-09-23 — Scanner magasin : rejouer l'envoi d'une photo sans la reprendre

- En cas d'échec de l'analyse (délai réseau dépassé, IA trop lente) après
  les 3 tentatives automatiques, il fallait reprendre la photo depuis le
  début pour réessayer. La photo déjà prise (et déjà réduite) est
  maintenant conservée : un bouton « Réessayer avec la même photo »
  apparaît sous le message d'erreur et relance l'envoi sans repasser par
  l'appareil photo.

## [2.10.16] — 2026-09-23 — Scanner magasin : une photo d'étiquette trop lourde pouvait faire échouer l'analyse

- Signalé après un scan en magasin resté sans résultat : le journal IA a
  montré une photo d'environ 4,4 Mo envoyée malgré le redimensionnement déjà
  en place — le JPEG généré côté navigateur peut rester volumineux sur une
  étiquette très détaillée (texte fin, reflets de verre), ce qui a fait
  dépasser le délai réseau après une première saturation temporaire du
  service IA.
- Corrigé : la réduction de photo baisse maintenant progressivement la
  qualité jusqu'à repasser sous un poids raisonnable, quel que soit le
  niveau de détail de la photo. Même correctif appliqué à la fiche
  d'ajout/édition d'un vin (v2.10.15), qui réutilise désormais ce même
  réglage renforcé.

## [2.10.15] — 2026-09-23 — Fiche vin : la photo d'étiquette pouvait faire échouer l'analyse IA

- Le bouton « Analyser la photo » (ajout/édition d'un vin) envoyait la photo
  du téléphone telle quelle, sans redimensionnement — une photo trop lourde
  pouvait faire échouer l'analyse. Corrigé : la photo est maintenant réduite
  côté navigateur avant envoi, comme c'était déjà le cas pour le scanner
  magasin.

## [2.10.14] — 2026-09-15 — IA : réinitialisation quotidienne du choix de modèle

- Un modèle de secours retenu après un quota dépassé restait figé
  indéfiniment, même une fois le quota repris à zéro le lendemain — au lieu
  de reprendre le modèle le plus performant disponible.
- Ajout d'une réinitialisation automatique une fois par jour (activée par
  défaut, désactivable dans Paramètres) qui repasse chaque tâche en
  « Automatique », plus un bouton « Réinitialiser maintenant » pour le faire
  à la demande.

## [2.10.13] — 2026-09-14 — Scanner : la photo de l'étiquette manquait aussi à l'ajout

- Suite au correctif de la v2.10.12 : la photo prise/recadrée lors du scan
  n'était toujours pas reprise par « Ajouter à ma cave », il fallait la
  réuploader à la main. Elle est maintenant copiée automatiquement vers la
  fiche du vin ajouté (avec un aperçu et la possibilité de la remplacer
  avant d'enregistrer si besoin).
- Rattrapage ponctuel : le Château Dorléac ajouté juste avant ce correctif
  a reçu sa photo rétroactivement.

## [2.10.12] — 2026-09-14 — Scanner : « Ajouter à ma cave » ne perdait presque toutes les infos

- Depuis la fiche de synthèse du scanner (ou depuis l'historique), cliquer
  sur « Ajouter à ma cave » ne reprenait que le nom, le producteur et le
  millésime — tout le reste (couleur, région, appellation, pays,
  description, accords mets-vin, fenêtre de dégustation, cépages, prix
  indicatif) était perdu et à ressaisir à la main dans le formulaire
  d'ajout.
- Corrigé : le formulaire d'ajout est maintenant pré-rempli avec tout ce
  que la synthèse IA avait déjà trouvé. Vérifié sur un vrai scan (Château
  Dorléac) : les 12 champs (couleur incluse) et les 3 cépages passent bien
  du scanner au formulaire.

## [2.10.11] — 2026-09-14 — Journal des versions : mise à jour éditoriale

- Quelques entrées de ce journal ont été reformulées pour rester générales
  sur les questions de sécurité, sans en changer le sens.

## [2.10.10] — 2026-09-14 — Renforcement de sécurité (suite)

- Renforcement de sécurité complémentaire. Détails non publiés ici par
  précaution.

## [2.10.9] — 2026-09-14 — Maintenance : voir le détail des logs IA et les photos orphelines

- Nouvelle page pages/ai_logs.php (lien « Voir le détail » depuis
  Maintenance) : liste paginée de chaque appel IA avec son prompt et sa
  réponse brute complets (repliables), le type d'appel et le vin lié le cas
  échéant. Jusqu'ici seul un total agrégé était visible.
- Les photos orphelines de la Maintenance s'affichent maintenant en
  vignettes cliquables (agrandissement inclus) plutôt qu'en simple liste de
  noms de fichiers — plus facile de vérifier avant de les supprimer.

## [2.10.8] — 2026-09-14 — Renforcements de sécurité

- Plusieurs renforcements de sécurité applicatifs. Détails non publiés ici
  par précaution.

## [2.10.7] — 2026-09-14 — Outils d'administration : suivi des connexions

- Nouvel outil de suivi des connexions, réservé à l'administrateur.

## [2.10.6] — 2026-09-14 — Lien Vivino sur les fiches du scanner

- Un bouton « Vivino » apparaît maintenant à côté de « Ajouter à ma cave » sur
  la fiche de synthèse du scanner magasin — que ce soit juste après une
  analyse ou en rouvrant une fiche depuis l'historique des scans (« Voir la
  fiche »). Ouvre une recherche Vivino pré-remplie avec le nom (+
  producteur/millésime si connus) dans un nouvel onglet.
- Simple lien de recherche, pas de récupération automatique de note ou de
  prix : le scrapping Vivino reste bloqué côté serveur (WAF anti-bot confirmé
  le 2026-09-10), ce lien ouvre juste Vivino côté navigateur pour une
  consultation manuelle.

## [2.10.5] — 2026-09-14 — « À boire bientôt » n'est plus mélangé à « maintenant »

- Correction de la v2.10.4 : « à boire bientôt » n'a rien à faire dans « à
  boire maintenant » — ce n'est justement pas encore le moment. « À boire
  maintenant » ne regroupe plus que apogée + apogée dépassée.
- « À boire bientôt » reste totalement à part, sans aucun chevauchement
  (vérifié).

## [2.10.4] — 2026-09-14 — « À boire maintenant » redéfini : fenêtre en cours ou dépassée

- Précision apportée sur les définitions : « à son apogée » reste le vin au
  sommet de sa qualité gustative, mais « à boire maintenant » doit couvrir
  tout vin déjà dans sa fenêtre de dégustation OU l'ayant dépassée — pas
  seulement les vins en retard.
- L'onglet « À boire maintenant » regroupe donc désormais à son apogée + à
  boire bientôt + apogée dépassée, avec la colonne « Dégustation » affichée
  pour distinguer chaque cas au sein de cette liste. « À son apogée » et « À
  boire bientôt » restent des onglets à part, plus précis.
- La tuile « À surveiller » du haut de page continue de ne compter que ce qui
  presse vraiment (à boire bientôt + apogée dépassée), sans les vins
  tranquillement à leur apogée.

## [2.10.3] — 2026-09-14 — Tableau de bord : onglet mal étiqueté

- L'onglet « À boire maintenant » ne contenait en réalité que les vins dont
  l'apogée est dépassée, jamais ceux qui y sont actuellement — ceux-là sont
  dans l'onglet « À son apogée », juste à côté. Le libellé induisait en
  erreur : le statut interne de ces vins s'appelle déjà « Apogée dépassée »
  partout ailleurs dans l'application (fiche du vin, colonne « Dégustation »
  de l'onglet Tous les vins), sauf sur cet onglet du tableau de bord.
- Renommé « Apogée dépassée » pour que l'étiquette corresponde enfin à ce
  que l'onglet contient réellement, et aux vins qui y étaient déjà.

## [2.10.2] — 2026-09-14 — Réponses IA plus stables (prix, descriptions, détection de cadre)

- Température des appels IA abaissée : jusqu'ici, même reposer exactement la
  même question au même modèle Gemini pouvait donner un prix sensiblement
  différent (aléa de génération, sans lien avec une vraie incertitude sur le
  vin).
- Testé : la même recherche posée deux fois de suite donne maintenant deux
  fourchettes de prix quasi identiques (7-12€ puis 8-13€, contre des écarts
  bien plus marqués auparavant).
- N'élimine pas les écarts entre modèles différents (chacun garde ses propres
  connaissances) ni l'incertitude réelle sur un vin peu documenté — seul le
  bruit ajouté sans raison à chaque appel est réduit.

## [2.10.1] — 2026-09-14 — Recherche par nom : éviter la dérive vers un vin homonyme

- Diagnostiqué depuis le journal : trois scans du même « Tournepique » en 3
  minutes avaient donné trois profils différents — la photo avait
  correctement lu Cahors (Malbec), mais relancer la recherche sur ce seul
  nom avait fait dériver l'IA vers un « Château Tournepique » bien réel mais
  totalement différent, en Pécharmant.
- Quand une recherche par nom porte sur le même vin qu'une identification
  précédente plus fiable (ex. une photo), l'appellation déjà connue est
  désormais transmise à l'IA comme repère — elle ne part plus d'une feuille
  blanche et reste sur le bon vin. Reproduit et vérifié : sans ce repère
  l'IA dérivait vers Pécharmant, avec elle reste sur Cahors.
- Les écarts de prix et de potentiel de garde d'un appel à l'autre, eux,
  resteront possibles sur un vin peu documenté : ce sont des estimations
  généralistes de l'IA, pas une donnée réelle — c'est justement pour ça que
  la recherche de prix réel (Open Food Facts) existe.

## [2.10.0] — 2026-09-14 — Recadrage d'étiquette : suggestion ajustable ou entièrement manuel

- Le recadrage automatique et silencieux de l'étiquette (qui pouvait mal
  tomber, comme sur le Château d'Avrillé) est remplacé par un outil de
  recadrage interactif : à chaque photo choisie (ajout d'un vin,
  modification, remplacement depuis la fiche), un cadre s'affiche sur
  l'image — pré-positionné par l'IA quand elle trouve l'étiquette — et se
  déplace ou se redimensionne au doigt ou à la souris avant de valider.
- « Utiliser la photo entière » reste possible en un clic, pour ne pas
  recadrer du tout.
- Le recadrage se fait entièrement dans le navigateur : aucune photo n'est
  envoyée au serveur avant validation, seule la suggestion de cadrage l'est
  (sur une version réduite, sans jamais être enregistrée).
- Le recadrage automatique et silencieux est conservé uniquement pour le
  scanner en magasin, où la rapidité prime et la photo n'est qu'un
  aide-mémoire secondaire.

## [2.9.3] — 2026-09-14 — Remplacer la photo d'étiquette d'un vin déjà en cave

- Corrigé : une fois qu'un vin avait une photo d'étiquette (même ratée, par
  exemple un mauvais recadrage automatique), il n'y avait plus aucun moyen de
  la changer — les boutons « Choisir une image » et « Chercher en ligne »
  n'apparaissaient que tant qu'aucune photo n'existait.
- Ces boutons restent désormais disponibles en permanence sur la fiche d'un
  vin, avec le libellé « Remplacer la photo de l'étiquette » quand il y en a
  déjà une.

## [2.9.2] — 2026-09-13 — Fiche du scanner : photo affichée, faux « 0 » corrigé

- Corrigé : un vin scanné sans millésime identifié affichait un « 0 »
  parasite après son nom en rouvrant sa fiche depuis l'historique (ex.
  « Château d'Avrillé Sélection 0 ») — le millésime absent était converti en
  zéro avant d'être inséré dans la page, un chiffre qui ressemble à une
  vraie valeur en JavaScript alors qu'il ne devrait rien afficher du tout.
- La photo de l'étiquette (scan par photo) s'affiche désormais à côté de la
  synthèse de dégustation, sur le tiers droit — au moment du scan comme en
  rouvrant une fiche depuis l'historique.

## [2.9.1] — 2026-09-13 — Recadrage automatique des photos de l'historique de scan

- Corrigé : les photos prises depuis « Scanner un vin en magasin » n'étaient
  jamais recadrées sur l'étiquette (bouteille entière conservée), contrairement
  à l'ajout d'étiquette en cave qui le fait déjà — un oubli lors de la mise en
  place de l'historique de scan, pas un réglage désactivé.
- Les 15 photos déjà présentes dans l'historique ont été recadrées
  rétroactivement, ainsi que les 9 étiquettes déjà enregistrées sur des vins
  de la cave (8 réussies, 1 laissée telle quelle faute de détection
  concluante).
- Nettoyage : suppression d'appels dépréciés depuis PHP 8.0 (curl_close,
  imagedestroy), sans effet mais qui polluaient les journaux de débogage.

## [2.9.0] — 2026-09-13 — Le scanner magasin cherche aussi un prix réel

- Le scanner en magasin cherche désormais un prix réellement relevé (Open
  Food Facts) avant de se contenter du prix indicatif de l'IA : celle-ci ne
  sert plus qu'en dernier recours, comme sur la fiche d'un vin.
- Recherche par code-barres quand le scan en fournit un, sinon tentative par
  nom + producteur (moins fiable, mais élargit la couverture).
- En pratique la couverture reste faible sur le vin (base contributive,
  surtout grande distribution) : l'estimation IA reste affichée la plupart
  du temps, mais désormais étiquetée sans ambiguïté « aucun prix réel
  trouvé ».
- La fiche d'un vin sans code-barres enregistré peut maintenant, elle aussi,
  tenter une recherche par nom (auparavant il fallait d'abord saisir le
  code-barres à la main).

## [2.8.1] — 2026-09-13 — Scan magasin : réponses IA incomplètes

- Diagnostiqué depuis le journal : le 12/09 à 16h07, l'analyse de la photo du
  Château d'Escurac a renvoyé un JSON valide mais quasi vide (seuls
  nom/producteur/millésime remplis, sans description ni prix) — le modèle
  s'arrêtait après une phase de réflexion sans vraiment répondre. Il avait
  fallu refaire la recherche par le nom.
- Le schéma demandé à l'IA impose désormais que `found` et `description`
  soient présents dans la réponse, ce qui réduit fortement le risque qu'un
  modèle s'arrête en cours de route.
- Filet de sécurité supplémentaire : une réponse qui arrive malgré tout sans
  description est maintenant traitée comme un échec et déclenche
  automatiquement une nouvelle tentative — y compris pour la recherche par
  nom, qui n'avait pas encore ce recours automatique (seule la photo l'avait
  depuis la v2.7.1).

## [2.8.0] — 2026-09-10 — Prix réels : fin de l'estimation IA, Open Food Facts + saisie manuelle

- L'estimation de prix par IA est retirée (fiche et formulaire) : trop
  approximative pour être fiable. L'historique garde les anciennes valeurs,
  étiquetées « ancienne estimation ».
- Nouveau : sur la fiche d'un vin, « Chercher un prix (Open Food Facts) »
  interroge la base contributive Open Prices par code-barres et propose les
  relevés trouvés (prix, magasin, date) à conserver en un clic.
- Nouveau : saisie manuelle d'un prix relevé, avec une note libre (magasin,
  lien Vivino…). L'historique distingue prix d'achat / relevé manuel / Open
  Food Facts.
- Un vin peut désormais enregistrer son code-barres (EAN), rempli
  automatiquement au scan à l'ajout ou saisi à la main.
- La tâche IA « estimation de prix » disparaît des Paramètres.

## [2.7.1] — 2026-09-10 — Scan photo en magasin : fiabilité

- La photo de l'étiquette est réduite (~1600 px) avant l'envoi : une image de
  téléphone brute faisait souvent dépasser le délai réseau pendant l'analyse,
  obligeant à relancer le scan plusieurs fois.
- Nouvelle tentative automatique (jusqu'à 3) si la réponse de l'IA n'arrive
  pas — plus besoin de refaire la manipulation à la main.
- Le journal des requêtes IA enregistre désormais la taille de l'image, la
  durée totale et le détail des tentatives (modèle, code, temps de réponse),
  pour diagnostiquer ce type de lenteur.

## [2.7.0] — 2026-09-10 — Publication du code, export enrichi et suivi de version

- Code source publié sur GitHub (github.com/jadwio/macave), secrets retirés du
  dépôt : `config.php` n'est plus versionné, `config.example.php` sert de
  modèle.
- Export CSV : nouvelle colonne « Emplacements » (cave + case + quantité pour
  chaque bouteille, y compris quand elle est répartie sur plusieurs caves).
- Mise en place du suivi de version : le numéro est affiché en bas de chaque
  page et l'historique complet dans Paramètres → Journal des versions.

## [2.6.1] — 2026-08-26 — Revue de sécurité

- Jeton CSRF ajouté au formulaire de connexion.
- Fichier `.htaccess` dédié interdisant l'exécution de tout script dans le
  dossier `uploads/`.
- Audit complet (injection SQL, XSS, traversée de chemin, validation des
  uploads, exposition des sauvegardes) — aucun autre défaut trouvé.

## [2.6.0] — 2026-08-26 — IA plus résiliente face aux quotas

- En cas de quota Gemini épuisé (erreur 429) sur le modèle d'une tâche, l'appel
  rejoue automatiquement sur d'autres modèles disponibles — le palier gratuit
  étant compté par modèle, un autre peut encore avoir du crédit.
- Bascule durable : le modèle de secours qui répond devient le nouveau choix
  par défaut de la tâche, pour ne plus retenter un modèle à sec à chaque
  requête.

## [2.5.0] — 2026-08-26 — Fiche de scan re-consultable

- La synthèse IA complète d'un vin scanné (prix, garde, dégustation,
  réputation, accords) est conservée avec son entrée d'historique.
- Elle est re-consultable à tout moment sans reconsommer de quota IA, et le vin
  peut être ajouté à la cave directement depuis l'historique.

## [2.4.0] — 2026-08-26 — Historique du scanner, géolocalisé

- Chaque vin scanné ou photographié en mode magasin est conservé dans un
  historique consultable.
- Géolocalisation optionnelle par entrée : le lieu est retrouvé automatiquement
  (Nominatim / OpenStreetMap) pour se souvenir du magasin où le vin a été vu.
- Suppression possible de chaque entrée d'historique.

## [2.3.0] — 2026-08-26 — Scan par photo d'étiquette (mode magasin)

- Nouvelle méthode dans « Scanner un vin en magasin » : photographier
  l'étiquette. Lecture et synthèse complète en un seul appel IA.
- Fonctionne sur n'importe quelle bouteille, y compris sans code-barres
  exploitable — la méthode la plus fiable en rayon.

## [2.2.0] — 2026-08-25 — Modèles IA par tâche et page Paramètres complète

- Un modèle Gemini distinct par nature d'appel (nom, photo, prix, synthèse,
  recadrage) : le quota gratuit étant compté par modèle, la capacité
  journalière est multipliée d'autant.
- Liste des modèles récupérée dynamiquement depuis l'API, repli automatique,
  compteur d'usage journalier par modèle.
- Page Paramètres : thèmes de couleur, devise, seuil « à boire bientôt »,
  gestion des caves, clé API personnalisée, changement de mot de passe,
  sauvegardes.

## [2.1.0] — 2026-08-25 — Recherche d'étiquettes en ligne

- Si l'étiquette d'un vin manque, recherche automatique en ligne (Open Food
  Facts + Wikimedia Commons) avec filtrage de pertinence.
- Aperçu agrandi en pop-up, validation manuelle obligatoire avant
  enregistrement — rien n'est stocké sans confirmation.
- Upload manuel depuis la galerie ou un fichier ; recadrage automatique par IA
  autour de la bouteille.

## [2.0.0] — 2026-08-25 — Caves multiples

- Nombre de caves illimité (casiers, armoire à vin…), chacune avec sa propre
  grille : lignes en lettres (A, B, C…), colonnes en chiffres.
- Génération automatique des emplacements sur toute la grille ; capacité
  configurable par cellule ou globale à la cave.
- Vue casier visuelle par cave (onglets), sélecteur d'emplacement au clic,
  listes déroulantes cave → emplacement en cascade.
- Déplacement de bouteilles entre caves et emplacements avec quantité
  partielle ; les emplacements pleins ne sont plus proposés.

## [1.4.0] — 2026-08-24 — Sauvegardes

- Export ZIP complet : dump SQL de la base + toutes les photos d'étiquettes.
- Téléchargement sécurisé depuis Paramètres ; déclenchement automatique par
  tâche cron (URL protégée par jeton) ; envoi par email optionnel.

## [1.3.0] — 2026-08-24 — Casier, thèmes et installation mobile

- Vue casier visuelle avec remplissage codé par couleur.
- 5 thèmes de couleur (Bordeaux, Bourgogne, Nuit, Ardoise, Champagne), devise
  d'affichage (EUR, CHF, USD, GBP), seuil « à boire bientôt » réglable.
- Application installable sur l'écran d'accueil (PWA : manifeste, service
  worker, bannière d'installation).

## [1.2.0] — 2026-08-23 — Scanner en magasin

- Nouvelle page « Scanner un vin en magasin » : synthèse avant achat (prix
  indicatif, potentiel de garde, style, accords), sans rien ajouter à la cave.
- Scan du code-barres / QR code à la caméra ou saisie du code EAN (Open Food
  Facts), recherche par nom, recherche d'un domaine ou d'une appellation
  (Wikipédia + IA).

## [1.1.0] — 2026-08-22 — IA : photo d'étiquette et estimation de prix

- Enrichissement d'une fiche à partir d'une photo de l'étiquette (lecture
  automatique du vin).
- Bouton « Estimation IA » du prix d'une bouteille, tracé dans un historique de
  prix.
- Détection de doublon à l'ajout d'un vin (fusion : ajout au stock existant
  plutôt que création d'une seconde fiche).

## [1.0.0] — 2026-08-21 — Lancement initial

- Catalogue des vins : fiche détaillée (producteur, région, appellation, pays,
  couleur, cépages, degré, millésime, fenêtre de dégustation, description,
  accords mets-vin, notes, marquage « cadeau »).
- Stock par emplacement, consommation d'une bouteille (note, occasion,
  commentaire), historique de consommation.
- Tableau de bord (nombre de bouteilles, valorisation, vins à leur apogée / à
  boire bientôt), statistiques (répartition couleur / région / cépage /
  millésime).
- Enrichissement IA (Google Gemini, palier gratuit) d'une fiche à partir du nom
  du vin.
- Recherche filtrée, export CSV.
- Authentification par compte admin unique : session sécurisée, verrou
  anti-bruteforce (5 tentatives / 15 min), liste noire IP 24 h.
- Hébergement IONOS mutualisé + Cloudflare, sous-domaine
  macave.famille-dumas.fr.
