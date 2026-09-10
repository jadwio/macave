<?php
// Wrapper cURL vers l'API Gemini (niveau gratuit) — pas de SDK, pas de Composer.

const WINE_ENRICH_SCHEMA = [
    'type' => 'object',
    'properties' => [
        'producer' => ['type' => 'string'],
        'region' => ['type' => 'string'],
        'appellation' => ['type' => 'string'],
        'classification' => ['type' => 'string'],
        'country' => ['type' => 'string'],
        'color' => ['type' => 'string', 'enum' => ['red', 'white', 'rose', 'sparkling', 'sweet', 'fortified', 'other']],
        'grape_varieties' => ['type' => 'array', 'items' => ['type' => 'string']],
        'alcohol_percent' => ['type' => 'number'],
        'drink_from_year' => ['type' => 'integer'],
        'drink_until_year' => ['type' => 'integer'],
        'description' => ['type' => 'string'],
        'food_pairing' => ['type' => 'string'],
        'estimated_price_eur' => ['type' => 'number'],
    ],
];

const PRICE_ESTIMATE_SCHEMA = [
    'type' => 'object',
    'properties' => [
        'low_estimate' => ['type' => 'number'],
        'high_estimate' => ['type' => 'number'],
        'currency' => ['type' => 'string'],
        'reasoning' => ['type' => 'string'],
    ],
];

const WINE_INFO_SCHEMA = [
    'type' => 'object',
    'properties' => [
        'found' => ['type' => 'boolean'],
        // Renseignés par le modèle quand l'identification part d'une photo
        'name' => ['type' => 'string'],
        'vintage' => ['type' => 'integer'],
        'producer' => ['type' => 'string'],
        'region' => ['type' => 'string'],
        'appellation' => ['type' => 'string'],
        'country' => ['type' => 'string'],
        'color' => ['type' => 'string', 'enum' => ['red', 'white', 'rose', 'sparkling', 'sweet', 'fortified', 'other']],
        'grape_varieties' => ['type' => 'array', 'items' => ['type' => 'string']],
        'description' => ['type' => 'string'],
        'reputation' => ['type' => 'string'],
        'price_low_eur' => ['type' => 'number'],
        'price_high_eur' => ['type' => 'number'],
        'is_vin_de_garde' => ['type' => 'boolean'],
        'garde_advice' => ['type' => 'string'],
        'drink_from_year' => ['type' => 'integer'],
        'drink_until_year' => ['type' => 'integer'],
        'food_pairing' => ['type' => 'string'],
    ],
];

const LABEL_CROP_SCHEMA = [
    'type' => 'object',
    'properties' => [
        'label_found' => ['type' => 'boolean'],
        // [ymin, xmin, ymax, xmax] normalisé de 0 à 1000 (convention Gemini)
        'box_2d' => ['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 4, 'maxItems' => 4],
    ],
];

/**
 * Appelle l'API Gemini generateContent avec sortie JSON structurée.
 * @param array $parts Parties du contenu (texte et/ou image inline)
 * @param array $schema Schéma JSON attendu en réponse
 * @return array{ok:bool, data?:array, error?:string}
 */
function gemini_api_key(): string
{
    $override = get_setting(get_db(), 'gemini_api_key');
    return $override !== null && $override !== '' ? $override : GEMINI_API_KEY;
}

function gemini_model(): string
{
    $override = get_setting(get_db(), 'gemini_model');
    return $override !== null && $override !== '' ? $override : GEMINI_MODEL;
}

/**
 * Les cinq natures d'appel IA de l'application. Chacune peut viser un modèle
 * différent : Google applique le quota gratuit *par modèle*, donc répartir les
 * tâches multiplie d'autant la capacité journalière (5 modèles = 5 × 20 appels).
 *
 * clé de réglage => [libellé, aide]
 */
const GEMINI_TASKS = [
    'text' => ['Enrichissement depuis le nom', 'Recherche des caractéristiques d\'un vin à partir de son nom.'],
    'vision' => ['Analyse de photo d\'étiquette', 'Lecture de l\'étiquette et extraction des informations.'],
    'crop' => ['Recadrage automatique', 'Détection du cadre de l\'étiquette. Tâche mécanique : un modèle « Lite » suffit.'],
    'price' => ['Estimation de prix', 'Estimation de la valeur marchande d\'une bouteille.'],
    'summary' => ['Synthèse magasin', 'Fiche complète avant achat (prix, garde, dégustation). Tâche la plus exigeante.'],
];

/** Clé de réglage portant le modèle d'une tâche. */
function gemini_task_setting_key(string $task): string
{
    return $task === 'text' ? 'gemini_model' : 'gemini_model_' . $task;
}

/**
 * Modèle effectif de chaque tâche : les choix manuels priment, et l'attribution
 * automatique comble les tâches restantes en évitant les modèles déjà retenus
 * manuellement — sans quoi deux tâches partageraient le même quota.
 *
 * @return array<string,string>
 */
function gemini_task_models(): array
{
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }
    $db = get_db();

    $explicit = [];
    foreach (array_keys(GEMINI_TASKS) as $t) {
        $v = get_setting($db, gemini_task_setting_key($t));
        if ($v !== null && $v !== '') {
            $explicit[$t] = $v;
        }
    }

    // Cache uniquement : un enrichissement ne doit jamais attendre l'API « modèles ».
    $models = gemini_list_models(false, true);
    $auto = $models
        ? gemini_recommended_assignment($models, array_values($explicit), array_keys($explicit))
        : [];

    $resolved = [];
    foreach (array_keys(GEMINI_TASKS) as $t) {
        $resolved[$t] = $explicit[$t] ?? ($auto[$t] ?? GEMINI_MODEL);
    }
    return $resolved;
}

function gemini_model_for_task(string $task): string
{
    return gemini_task_models()[$task] ?? GEMINI_MODEL;
}

/**
 * Propose un modèle distinct par tâche pour étaler la consommation.
 * Préférences par tâche (du plus adapté au moins adapté) ; on retient le
 * premier modèle disponible non encore attribué, afin que chaque tâche
 * dispose de son propre quota journalier.
 *
 * @param array<string,string> $available modèles renvoyés par l'API
 * @param list<string> $reserved modèles déjà pris par un choix manuel : on les évite
 *                     pour ne pas partager un même quota entre deux tâches
 * @param list<string> $skipTasks tâches déjà réglées manuellement
 * @return array<string,string> tâche => modèle
 */
function gemini_recommended_assignment(array $available, array $reserved = [], array $skipTasks = []): array
{
    // L'ordre de traitement compte : les tâches les plus exigeantes servent
    // en premier pour ne pas se retrouver avec un modèle léger par défaut.
    $prefs = [
        // Détection d'un cadre sur une photo : tâche mécanique, sans connaissances
        // métier. Un modèle « Lite » répond plus vite et préserve le quota des autres.
        'crop' => ['gemini-flash-lite-latest', 'gemini-3.5-flash-lite', 'gemini-3.1-flash-lite'],
        // Lecture d'étiquettes souvent ornées (dorures, fontes fantaisie) : la
        // qualité de vision prime, d'où un Flash complet plutôt qu'un Lite.
        // L'alias « latest » garantit d'avoir toujours la meilleure vision du moment.
        'vision' => ['gemini-flash-latest', 'gemini-3.6-flash', 'gemini-3.5-flash', 'gemini-3.7-flash'],
        // Prix : donnée la plus volatile, on vise les connaissances les plus récentes.
        'price' => ['gemini-3.7-flash', 'gemini-3.5-flash', 'gemini-3.6-flash', 'gemini-flash-latest'],
        // Synthèse magasin : raisonnement le plus riche → un Flash *complet*
        // (jamais un « Lite »). On évite Pro : son quota gratuit est plus bas et
        // c'est la fonction utilisée en rayon, où une coupure serait pénalisante.
        'summary' => ['gemini-3.5-flash', 'gemini-3.6-flash', 'gemini-3.7-flash', 'gemini-pro-latest'],
        // Connaissances générales sur un vin nommé : modèle éprouvé de l'app.
        'text' => ['gemini-3.6-flash', 'gemini-3.5-flash', 'gemini-flash-latest', 'gemini-3.7-flash'],
    ];

    // Les modèles « preview » peuvent être retirés sans préavis : on ne les
    // propose qu'en dernier recours, jamais comme choix par défaut.
    $available = array_filter(
        $available,
        fn($name) => !str_contains($name, 'preview'),
        ARRAY_FILTER_USE_KEY
    ) ?: $available;

    // La génération 2.5 est encore listée par l'API mais renvoie 404
    // (« no longer available to new users ») : on ne la propose jamais.
    $available = array_filter(
        $available,
        fn($name) => !str_starts_with($name, 'gemini-2.5-'),
        ARRAY_FILTER_USE_KEY
    );

    $assigned = [];
    $used = $reserved; // les modèles choisis manuellement sont considérés comme pris
    foreach ($prefs as $task => $candidates) {
        if (in_array($task, $skipTasks, true)) {
            continue;
        }
        foreach ($candidates as $c) {
            if (isset($available[$c]) && !in_array($c, $used, true)) {
                $assigned[$task] = $c;
                $used[] = $c;
                continue 2;
            }
        }
        // Aucun candidat libre : on prend n'importe quel modèle encore inutilisé.
        foreach (array_keys($available) as $c) {
            if (!in_array($c, $used, true)) {
                $assigned[$task] = $c;
                $used[] = $c;
                break;
            }
        }
    }
    return $assigned;
}

/** Quota journalier du palier gratuit (20 pour gemini-3.6-flash), ajustable. */
function gemini_daily_quota(): int
{
    $q = (int) get_setting(get_db(), 'gemini_daily_quota', '20');
    return $q > 0 ? $q : 20;
}

/**
 * Nombre d'appels IA *réussis* depuis minuit.
 * Les 429 sont journalisés eux aussi mais ne consomment pas de quota :
 * on ne compte donc que les réponses contenant des candidats.
 */
function gemini_requests_today(): int
{
    return array_sum(gemini_requests_today_by_model());
}

/**
 * Détail des appels réussis du jour par modèle. Le quota gratuit étant
 * appliqué par modèle, c'est cette ventilation qui compte réellement.
 * Le modèle est relu dans la réponse de Google (champ modelVersion), ce qui
 * reflète le modèle effectivement servi même derrière un alias « latest ».
 *
 * @return array<string,int>
 */
function gemini_requests_today_by_model(): array
{
    $stmt = get_db()->prepare(
        "SELECT raw_response FROM ai_enrichment_log
         WHERE created_at >= ? AND raw_response LIKE '%\"candidates\"%'"
    );
    $stmt->execute([date('Y-m-d 00:00:00')]);

    $counts = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $raw) {
        $model = preg_match('/"modelVersion"\s*:\s*"([^"]+)"/', (string) $raw, $m) ? $m[1] : 'inconnu';
        $counts[$model] = ($counts[$model] ?? 0) + 1;
    }
    arsort($counts);
    return $counts;
}

/**
 * Modèles Gemini utilisables par l'application, listés dynamiquement depuis
 * l'API (la liste évolue : coder des noms en dur expose à une dépréciation).
 * On écarte les modèles hors sujet ici : synthèse vocale, génération d'images,
 * musique, robotique, etc. — il nous faut du texte + vision avec sortie JSON.
 *
 * Résultat mis en cache 24 h pour ne pas ralentir la page Paramètres.
 *
 * @return array<string,string> nom technique => libellé lisible
 */
function gemini_list_models(bool $forceRefresh = false, bool $cacheOnly = false): array
{
    $db = get_db();

    if (!$forceRefresh) {
        $cached = get_setting($db, 'gemini_models_cache');
        $cachedAt = (int) get_setting($db, 'gemini_models_cache_at', '0');
        if ($cached && (time() - $cachedAt) < 86400) {
            $list = json_decode($cached, true);
            if (is_array($list) && $list) {
                return $list;
            }
        }
    }
    if ($cacheOnly) {
        return []; // appelé depuis un chemin critique : pas d'appel réseau
    }

    $url = 'https://generativelanguage.googleapis.com/v1beta/models?pageSize=200&key=' . urlencode(gemini_api_key());
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $code !== 200) {
        return [];
    }
    $json = json_decode($body, true);
    if (empty($json['models'])) {
        return [];
    }

    // Familles inadaptées à l'enrichissement de fiches vin
    $exclude = ['tts', 'image', 'nano-banana', 'lyria', 'robotics', 'computer-use',
                'deep-research', 'embedding', 'antigravity', 'customtools', 'omni'];

    $models = [];
    foreach ($json['models'] as $m) {
        if (!in_array('generateContent', $m['supportedGenerationMethods'] ?? [], true)) {
            continue;
        }
        $name = str_replace('models/', '', $m['name'] ?? '');
        if (!str_starts_with($name, 'gemini-')) {
            continue; // écarte Gemma & co
        }
        // Encore listée par l'API, mais répond 404 « no longer available to new
        // users » : inutile de la proposer, elle ne ferait qu'échouer.
        if (str_starts_with($name, 'gemini-2.5-')) {
            continue;
        }
        foreach ($exclude as $bad) {
            if (str_contains($name, $bad)) {
                continue 2;
            }
        }
        $models[$name] = $m['displayName'] ?? $name;
    }

    // Les alias « -latest » suivent automatiquement la dernière version :
    // on les remonte en tête, ils évitent les ruptures de dépréciation.
    uksort($models, function ($a, $b) {
        $aLatest = str_contains($a, 'latest');
        $bLatest = str_contains($b, 'latest');
        if ($aLatest !== $bLatest) {
            return $aLatest ? -1 : 1;
        }
        return strnatcmp($b, $a); // versions les plus récentes d'abord
    });

    if ($models) {
        set_setting($db, 'gemini_models_cache', json_encode($models));
        set_setting($db, 'gemini_models_cache_at', (string) time());
    }
    return $models;
}

function gemini_call(array $parts, array $schema, string $task = 'text'): array
{
    $apiKey = gemini_api_key();
    if (empty($apiKey) || $apiKey === 'CHANGE_ME') {
        return ['ok' => false, 'error' => 'Clé API Gemini non configurée.'];
    }

    $body = [
        'contents' => [
            ['role' => 'user', 'parts' => $parts],
        ],
        'generationConfig' => [
            'responseMimeType' => 'application/json',
            'responseSchema' => $schema,
        ],
    ];
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE);

    // Repli automatique : un modèle peut être retiré du palier gratuit (404) ou
    // temporairement saturé (503). Plutôt que d'échouer, on rejoue une fois sur
    // le modèle de référence du serveur, puis sur l'alias Flash auto-actualisé.
    $model = gemini_model_for_task($task);
    $fallbacks = array_values(array_unique(array_filter(
        [$model, GEMINI_MODEL, 'gemini-flash-latest'],
        fn($m) => $m !== '' && $m !== null
    )));

    // Si même ces valeurs sûres sont à quota, on pioche dans le reste des
    // modèles connus (jusqu'à 6 de plus) : le palier gratuit est compté par
    // modèle, un autre peut très bien avoir encore du crédit aujourd'hui.
    foreach (array_slice(array_keys(gemini_list_models(false, true)), 0, 6) as $extra) {
        if (!in_array($extra, $fallbacks, true)) {
            $fallbacks[] = $extra;
        }
    }

    // Budget total borné : l'hébergement mutualisé coupe les scripts trop longs,
    // mieux vaut renoncer proprement que de se faire tuer sans réponse.
    $deadline = microtime(true) + 40;

    $response = false;
    $httpCode = 0;
    $curlError = '';
    $usedModel = null;
    foreach ($fallbacks as $i => $candidate) {
        $remaining = (int) floor($deadline - microtime(true));
        if ($i > 0 && $remaining < 8) {
            break; // plus assez de temps pour une tentative utile
        }
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $candidate . ':generateContent?key=' . urlencode($apiKey);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => max(8, min(25, $remaining)),
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // Rejouable : 404 (modèle indisponible pour cette clé), 503 (saturation),
        // 429 (quota épuisé pour CE modèle — le palier gratuit est compté par
        // modèle, un autre peut donc encore avoir du crédit) et échec réseau/délai
        // dépassé. Seul un 200 ou une erreur définitive (400, clé invalide...)
        // arrête la boucle.
        if ($response !== false && !in_array($httpCode, [404, 503, 429], true)) {
            $usedModel = $candidate;
            break;
        }
    }

    if ($response === false) {
        return ['ok' => false, 'error' => 'Erreur réseau: ' . $curlError];
    }
    if ($httpCode !== 200) {
        $detail = $httpCode === 404
            ? ' Ce modèle n\'est pas disponible pour ta clé — change-le dans Paramètres.'
            : ($httpCode === 503 ? ' Modèle temporairement saturé, réessaie dans un instant.'
                : ($httpCode === 429 ? ' Quota gratuit atteint sur tous les modèles essayés — réessaie demain, ou change de modèle dans Paramètres.' : ''));
        return ['ok' => false, 'error' => 'Gemini a renvoyé une erreur (' . $httpCode . ').' . $detail, 'raw' => $response];
    }

    // Le modèle habituel de cette tâche était indisponible (quota/saturation) et
    // un modèle de secours a répondu : on en fait le nouveau choix par défaut de
    // la tâche, pour ne plus re-cogner sur un modèle à sec à chaque requête.
    if ($usedModel !== null && $usedModel !== $model && array_key_exists($task, GEMINI_TASKS)) {
        set_setting(get_db(), gemini_task_setting_key($task), $usedModel);
    }

    $decoded = json_decode($response, true);
    $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if ($text === null) {
        return ['ok' => false, 'error' => 'Réponse Gemini inattendue.', 'raw' => $response];
    }

    $data = json_decode($text, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => 'JSON invalide renvoyé par Gemini.', 'raw' => $response];
    }

    return ['ok' => true, 'data' => ai_sanitize_short_fields($data), 'raw' => $response];
}

/**
 * Longueur maximale plausible des champs censés être de courts identifiants
 * (un nom de région ou de producteur ne fait jamais 300 caractères).
 */
const AI_SHORT_FIELD_MAX_LEN = [
    'producer' => 120,
    'region' => 80,
    'appellation' => 80,
    'country' => 60,
    'classification' => 60,
    'currency' => 10,
];

/**
 * Filet de sécurité contre un défaut connu des modèles génératifs : sur une
 * requête ambiguë (nom de vin mal orthographié, producteur peu connu...), le
 * raisonnement interne du modèle peut fuiter dans un champ censé être court
 * ("Bordeaux / Castillon... Wait, let's use exact known data...") au lieu de
 * rester dans son canal de réflexion. Le JSON reste syntaxiquement valide, donc
 * rien d'autre ne l'intercepte. On vide simplement tout champ anormalement long
 * plutôt que d'afficher du texte incohérent — vide vaut mieux que faux.
 */
function ai_sanitize_short_fields(array $data): array
{
    foreach (AI_SHORT_FIELD_MAX_LEN as $key => $maxLen) {
        if (isset($data[$key]) && is_string($data[$key]) && mb_strlen($data[$key]) > $maxLen) {
            $data[$key] = null;
        }
    }
    return $data;
}

function log_ai_enrichment(?int $wineId, string $type, string $prompt, ?string $rawResponse): void
{
    $stmt = get_db()->prepare(
        'INSERT INTO ai_enrichment_log (wine_id, request_type, prompt, raw_response) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$wineId, $type, $prompt, $rawResponse]);
}

function enrich_wine_from_text(string $name, ?string $producer, ?string $vintage): array
{
    $prompt = "Tu es un expert en vin et sommelier. Donne les informations les plus précises possible sur ce vin, "
        . "en te basant sur tes connaissances générales. Si tu n'es pas sûr d'un champ, laisse-le vide plutôt que d'inventer.\n"
        . "Nom du vin: {$name}\n"
        . ($producer ? "Producteur/domaine: {$producer}\n" : '')
        . ($vintage ? "Millésime: {$vintage}\n" : '')
        . "Si ce vin a une mention de classification/vieillissement propre à sa région (par exemple Crianza, Reserva, "
        . "Gran Reserva pour l'Espagne, Riserva pour l'Italie, ou toute autre mention équivalente), indique-la dans "
        . "classification ; sinon laisse ce champ vide.\n"
        . "Donne aussi dans estimated_price_eur une estimation approximative du prix de vente actuel en euros pour une "
        . "bouteille de 75cl (moyenne grossière basée sur tes connaissances générales du marché, pas une donnée temps réel). "
        . "producer, region, appellation, country et classification doivent être de courts identifiants (quelques mots au "
        . "maximum) : jamais de phrase, jamais d'hésitation ni de raisonnement — si le nom est ambigu, choisis silencieusement "
        . "l'interprétation la plus probable, ou laisse le champ vide. Réponds uniquement avec les champs du schéma JSON fourni.";

    $result = gemini_call([['text' => $prompt]], WINE_ENRICH_SCHEMA, 'text');
    log_ai_enrichment(null, 'text', $prompt, $result['raw'] ?? ($result['error'] ?? null));
    return $result;
}

/**
 * Synthèse complète "avant achat" d'un vin (mode magasin) : description, prix
 * indicatif, potentiel de garde, cépages, accords. Rien n'est enregistré en cave.
 */
function wine_info_summary(string $name, ?string $producer, ?string $vintage): array
{
    $currentYear = date('Y');
    $prompt = "Tu es un sommelier expert qui conseille un client dans un magasin de vin, devant la bouteille. "
        . "Donne une synthèse complète et honnête sur ce vin à partir de tes connaissances générales (pas de données temps réel).\n"
        . "Nom du vin: {$name}\n"
        . ($producer ? "Producteur/domaine: {$producer}\n" : '')
        . ($vintage ? "Millésime: {$vintage}\n" : '')
        . "Nous sommes en {$currentYear}.\n"
        . "- found: true si tu connais ce vin ou peux l'évaluer par son appellation/région, false si tu ne peux rien en dire de fiable.\n"
        . "- description: synthèse de dégustation (arômes, bouche, style), 3-4 phrases.\n"
        . "- reputation: ce que vaut ce vin / cette appellation (rapport qualité-prix, notoriété, niveau de gamme), 1-2 phrases.\n"
        . "- price_low_eur / price_high_eur: fourchette de prix boutique en euros pour 75cl (estimation approximative).\n"
        . "- is_vin_de_garde: true si ce vin gagne à vieillir plusieurs années, false s'il faut le boire jeune.\n"
        . "- garde_advice: conseil concret de garde en une phrase (ex: 'À boire dans les 2 ans' ou 'Peut se garder 10-15 ans, apogée vers 2035').\n"
        . "- drink_from_year / drink_until_year: fenêtre de dégustation estimée (années).\n"
        . "- food_pairing: accords mets-vin principaux.\n"
        . "Si tu n'es pas sûr d'un champ, laisse-le vide plutôt que d'inventer.";

    $result = gemini_call([['text' => $prompt]], WINE_INFO_SCHEMA, 'summary');
    log_ai_enrichment(null, 'text', $prompt, $result['raw'] ?? ($result['error'] ?? null));
    return $result;
}

/**
 * Même synthèse « magasin », mais à partir d'une photo d'étiquette.
 * Un seul appel IA fait tout : lecture de l'étiquette *et* analyse. Enchaîner
 * deux appels (identifier puis analyser) doublerait la consommation de quota
 * et perdrait le contexte visuel utile à l'évaluation.
 */
function wine_info_from_photo(string $base64Image, string $mimeType): array
{
    $currentYear = date('Y');
    $prompt = "Tu es un sommelier expert qui conseille un client dans un magasin, devant cette bouteille. "
        . "Lis l'étiquette sur la photo, identifie le vin, puis donne une synthèse complète et honnête "
        . "à partir de tes connaissances générales (pas de données temps réel). Nous sommes en {$currentYear}.\n"
        . "- name: nom du vin tel qu'il figure sur l'étiquette (obligatoire si lisible).\n"
        . "- producer: producteur ou domaine. - vintage: millésime en chiffres si visible.\n"
        . "- found: true si l'étiquette est lisible et le vin identifiable, false sinon.\n"
        . "- description: synthèse de dégustation (arômes, bouche, style), 3-4 phrases.\n"
        . "- reputation: ce que vaut ce vin / cette appellation, 1-2 phrases.\n"
        . "- price_low_eur / price_high_eur: fourchette de prix boutique en euros pour 75cl (estimation approximative).\n"
        . "- is_vin_de_garde: true si ce vin gagne à vieillir, false s'il faut le boire jeune.\n"
        . "- garde_advice: conseil concret de garde en une phrase.\n"
        . "- drink_from_year / drink_until_year: fenêtre de dégustation estimée.\n"
        . "- food_pairing: accords mets-vin principaux.\n"
        . "Si l'étiquette est illisible ou n'est pas un vin, mets found à false. "
        . "Si tu n'es pas sûr d'un champ, laisse-le vide plutôt que d'inventer.";

    $result = gemini_call(
        [['text' => $prompt], ['inline_data' => ['mime_type' => $mimeType, 'data' => $base64Image]]],
        WINE_INFO_SCHEMA,
        'vision'  // entrée image : on vise le modèle choisi pour la lecture d'étiquettes
    );
    log_ai_enrichment(null, 'photo', $prompt, $result['raw'] ?? ($result['error'] ?? null));
    return $result;
}

function enrich_wine_from_photo(string $base64Image, string $mimeType): array
{
    $prompt = 'Tu es un expert en vin et sommelier. Lis attentivement cette photo d\'étiquette de bouteille de vin et '
        . 'identifie le vin. Donne les informations les plus précises possible. Si un champ n\'est pas lisible ou incertain, '
        . 'laisse-le vide plutôt que d\'inventer. Si ce vin a une mention de classification/vieillissement propre à sa '
        . 'région (par exemple Crianza, Reserva, Gran Reserva pour l\'Espagne, Riserva pour l\'Italie, ou toute autre '
        . 'mention équivalente visible sur l\'étiquette), indique-la dans classification ; sinon laisse ce champ vide. '
        . 'Donne aussi dans estimated_price_eur une estimation approximative du '
        . 'prix de vente actuel en euros pour une bouteille de 75cl (moyenne grossière basée sur tes connaissances '
        . 'générales du marché, pas une donnée temps réel). Réponds uniquement avec les champs du schéma JSON fourni.';

    $parts = [
        ['text' => $prompt],
        ['inline_data' => ['mime_type' => $mimeType, 'data' => $base64Image]],
    ];

    $result = gemini_call($parts, WINE_ENRICH_SCHEMA, 'vision');
    log_ai_enrichment(null, 'photo', $prompt, $result['raw'] ?? ($result['error'] ?? null));
    return $result;
}

function estimate_wine_price(array $wine): array
{
    $prompt = "Tu es un expert en vin. Donne une estimation approximative (fourchette basse/haute, en euros) de la valeur "
        . "marchande actuelle de ce vin d'occasion/collection, à partir de tes connaissances générales (pas de données temps réel). "
        . "Précise dans 'reasoning' que c'est une estimation approximative.\n"
        . "Nom: {$wine['name']}\n"
        . "Producteur: " . ($wine['producer'] ?? 'inconnu') . "\n"
        . "Région: " . ($wine['region'] ?? 'inconnue') . "\n"
        . "Millésime: " . ($wine['vintage'] ?? 'inconnu') . "\n";

    $result = gemini_call([['text' => $prompt]], PRICE_ESTIMATE_SCHEMA, 'price');
    log_ai_enrichment($wine['id'] ?? null, 'price_estimate', $prompt, $result['raw'] ?? ($result['error'] ?? null));
    return $result;
}

/**
 * Détecte l'étiquette sur la photo via Gemini et recadre le fichier en place.
 * Best-effort : ne fait rien (retourne false) si la détection ou le recadrage échoue,
 * la photo d'origine reste alors inchangée.
 */
function crop_label_to_bottle(string $absolutePath): bool
{
    $info = @getimagesize($absolutePath);
    if (!$info) {
        return false;
    }
    [$width, $height] = $info;
    $mime = $info['mime'];

    $loaders = [
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png' => 'imagecreatefrompng',
        'image/webp' => 'imagecreatefromwebp',
    ];
    $savers = [
        'image/jpeg' => fn($im, $path) => imagejpeg($im, $path, 88),
        'image/png' => fn($im, $path) => imagepng($im, $path, 6),
        'image/webp' => fn($im, $path) => imagewebp($im, $path, 88),
    ];
    if (!isset($loaders[$mime])) {
        return false;
    }

    $prompt = 'Regarde cette photo de bouteille de vin. Identifie la zone qui encadre l\'étiquette principale (façade), '
        . 'en incluant tout le texte visible (nom, millésime, appellation) sans le couper, et en excluant l\'arrière-plan '
        . 'et le reste de la bouteille (goulot, capsule, base). Un cadrage légèrement généreux est préférable à un '
        . 'cadrage trop serré qui couperait du texte. Réponds avec box_2d au format [ymin, xmin, ymax, xmax], chaque '
        . 'valeur normalisée entre 0 et 1000. Si aucune étiquette n\'est clairement visible, mets label_found à false.';

    $base64 = base64_encode(file_get_contents($absolutePath));
    $result = gemini_call(
        [['text' => $prompt], ['inline_data' => ['mime_type' => $mime, 'data' => $base64]]],
        LABEL_CROP_SCHEMA,
        'crop'
    );
    log_ai_enrichment(null, 'photo', $prompt, $result['raw'] ?? ($result['error'] ?? null));

    if (!$result['ok'] || empty($result['data']['label_found']) || count($result['data']['box_2d'] ?? []) !== 4) {
        return false;
    }

    [$ymin, $xmin, $ymax, $xmax] = $result['data']['box_2d'];
    // Défensif : s'assurer que les bornes sont dans le bon ordre au cas où le modèle les inverse.
    if ($xmin > $xmax) { [$xmin, $xmax] = [$xmax, $xmin]; }
    if ($ymin > $ymax) { [$ymin, $ymax] = [$ymax, $ymin]; }

    $px1 = (int) round($xmin / 1000 * $width);
    $py1 = (int) round($ymin / 1000 * $height);
    $px2 = (int) round($xmax / 1000 * $width);
    $py2 = (int) round($ymax / 1000 * $height);

    // Marge de 6% autour de la zone détectée, pour ne pas couper de texte au bord.
    $marginX = (int) round(($px2 - $px1) * 0.06);
    $marginY = (int) round(($py2 - $py1) * 0.06);
    $px1 = max(0, $px1 - $marginX);
    $py1 = max(0, $py1 - $marginY);
    $px2 = min($width, $px2 + $marginX);
    $py2 = min($height, $py2 + $marginY);

    // Détection visiblement aberrante (zone quasi nulle ou couvrant toute la photo) : on garde la photo d'origine.
    $cropArea = max(0, $px2 - $px1) * max(0, $py2 - $py1);
    $imageArea = $width * $height;
    if ($imageArea <= 0 || $cropArea / $imageArea < 0.08) {
        return false;
    }

    $cropWidth = $px2 - $px1;
    $cropHeight = $py2 - $py1;
    if ($cropWidth < 20 || $cropHeight < 20) {
        return false;
    }

    $source = @$loaders[$mime]($absolutePath);
    if (!$source) {
        return false;
    }

    $cropped = imagecrop($source, ['x' => $px1, 'y' => $py1, 'width' => $cropWidth, 'height' => $cropHeight]);
    if ($cropped === false) {
        imagedestroy($source);
        return false;
    }

    $ok = $savers[$mime]($cropped, $absolutePath);
    imagedestroy($source);
    imagedestroy($cropped);

    return (bool) $ok;
}
