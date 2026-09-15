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
    // Sans ceci, un modèle peut renvoyer un JSON valide mais quasi vide (vu sur
    // gemini-3.8-flash le 12/09/2026 : seuls name/producer/vintage remplis,
    // "found" absent, aucune description). Limité à ces deux champs : les
    // autres (prix, garde...) doivent rester réellement absents quand le vin
    // n'est pas identifiable, pas forcés à une valeur inventée.
    'required' => ['found', 'description'],
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
    'summary' => ['Synthèse magasin', 'Fiche complète avant achat (garde, dégustation, prix indicatif). Tâche la plus exigeante.'],
];

/** Clé de réglage portant le modèle d'une tâche. */
function gemini_task_setting_key(string $task): string
{
    return $task === 'text' ? 'gemini_model' : 'gemini_model_' . $task;
}

/** Efface les modèles figés (choix manuel ou repli automatique) : les tâches
 *  redeviennent « Automatique » et reprennent le modèle le plus performant
 *  disponible au prochain appel. */
function gemini_reset_task_models(): void
{
    $db = get_db();
    foreach (array_keys(GEMINI_TASKS) as $t) {
        set_setting($db, gemini_task_setting_key($t), null);
    }
    set_setting($db, 'gemini_last_auto_reset', date('Y-m-d'));
}

/** Case à cocher « Réinitialisation quotidienne » des Paramètres — activée par défaut. */
function gemini_daily_reset_enabled(): bool
{
    return get_setting(get_db(), 'gemini_daily_reset', '1') !== '0';
}

/**
 * Un modèle de secours retenu après une saturation (voir gemini_call) reste
 * sinon figé indéfiniment, même une fois le quota repris à zéro le lendemain.
 * Au premier appel de chaque jour, on oublie ce choix pour redonner sa chance
 * au modèle le plus performant listé par Google — le vrai « automatique ».
 */
function gemini_maybe_daily_reset(): void
{
    if (!gemini_daily_reset_enabled()) {
        return;
    }
    $db = get_db();
    if (get_setting($db, 'gemini_last_auto_reset') === date('Y-m-d')) {
        return;
    }
    gemini_reset_task_models();
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
    gemini_maybe_daily_reset();
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
            // Basse plutôt que la valeur par défaut du modèle (proche de 1) : rien
            // ici ne doit varier "par créativité" (prix, description, détection de
            // cadre...) — seule la vraie incertitude (peu de matière sur un vin
            // méconnu, modèles différents selon la tâche) doit faire bouger la
            // réponse, pas un aléa ajouté sans raison à chaque appel.
            'temperature' => 0.2,
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
    $callStart = microtime(true);
    $deadline = $callStart + 40;

    // Trace des tentatives, reportée dans le journal en cas d'échec : c'est ce
    // qui permet de diagnostiquer après coup un scan qui a demandé plusieurs
    // essais (modèle lent, délai réseau dépassé, quota...).
    $attempts = [];
    $payloadKo = round(strlen($payload) / 1024);

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
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => max(8, min(28, $remaining)),
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $elapsed = round((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME), 1);
        $curlError = curl_error($ch);
        // curl_close() est un no-op déprécié depuis PHP 8.0 — la ressource se libère seule.

        $attempts[] = $candidate . '=' . ($response === false ? 'réseau(' . $elapsed . 's)' : $httpCode . '(' . $elapsed . 's)');

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

    $trace = ' [envoi ' . $payloadKo . ' Ko, ' . round(microtime(true) - $callStart, 1) . 's, tentatives: ' . implode(' → ', $attempts) . ']';

    if ($response === false) {
        return ['ok' => false, 'error' => 'Erreur réseau: ' . $curlError . $trace];
    }
    if ($httpCode !== 200) {
        $detail = $httpCode === 404
            ? ' Ce modèle n\'est pas disponible pour ta clé — change-le dans Paramètres.'
            : ($httpCode === 503 ? ' Modèle temporairement saturé, réessaie dans un instant.'
                : ($httpCode === 429 ? ' Quota gratuit atteint sur tous les modèles essayés — réessaie demain, ou change de modèle dans Paramètres.' : ''));
        return ['ok' => false, 'error' => 'Gemini a renvoyé une erreur (' . $httpCode . ').' . $detail . $trace, 'raw' => $response];
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
 *
 * $appellation/$region : à renseigner quand ils viennent d'une identification
 * plus fiable que le nom seul (ex. lu sur la photo de l'étiquette juste avant).
 * Un nom de vin seul est souvent ambigu — Gemini peut alors dériver vers un
 * tout autre domaine du même nom (vu en pratique : "Tournepique" en Cahors sur
 * la photo, "Château Tournepique" en Pécharmant en recherche par nom seule).
 */
function wine_info_summary(string $name, ?string $producer, ?string $vintage, ?string $appellation = null, ?string $region = null): array
{
    $currentYear = date('Y');
    $prompt = "Tu es un sommelier expert qui conseille un client dans un magasin de vin, devant la bouteille. "
        . "Donne une synthèse complète et honnête sur ce vin à partir de tes connaissances générales (pas de données temps réel).\n"
        . "Nom du vin: {$name}\n"
        . ($producer ? "Producteur/domaine: {$producer}\n" : '')
        . ($vintage ? "Millésime: {$vintage}\n" : '')
        . ($appellation ? "Appellation déjà identifiée avec certitude (lue sur la photo de l'étiquette) : {$appellation}. "
            . "Le nom seul étant ambigu, base-toi impérativement sur cette appellation plutôt que sur un autre vin homonyme.\n" : '')
        . ($region ? "Région déjà identifiée : {$region}\n" : '')
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

    $result = wine_info_guard_incomplete(gemini_call([['text' => $prompt]], WINE_INFO_SCHEMA, 'summary'));
    log_ai_enrichment(null, 'text', $prompt, $result['raw'] ?? ($result['error'] ?? null));
    return $result;
}

/**
 * Filet de sécurité : un modèle peut renvoyer un JSON structurellement valide
 * mais vide de contenu utile — vu sur gemini-3.8-flash le 12/09/2026, "found"
 * absent et seuls name/producer/vintage remplis, alors que le vin était bien
 * identifié. Le "required" du schéma limite déjà le risque, mais on vérifie
 * aussi ici pour couvrir un modèle qui ne l'honorerait pas parfaitement.
 * Traité comme un échec réseau pour déclencher la tentative automatique
 * suivante côté client (voir TRANSIENT_ERROR_RE dans scan_info.php).
 */
function wine_info_guard_incomplete(array $result): array
{
    if (!empty($result['ok'])) {
        $data = $result['data'] ?? [];
        // found === false est un résultat légitime (étiquette illisible, vin
        // inconnu) : pas de description à attendre, ce n'est pas un échec.
        if (($data['found'] ?? null) !== false && empty($data['description'])) {
            return ['ok' => false, 'error' => 'Réponse IA incomplète (champs manquants).', 'raw' => $result['raw'] ?? null];
        }
    }
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

    $result = wine_info_guard_incomplete(gemini_call(
        [['text' => $prompt], ['inline_data' => ['mime_type' => $mimeType, 'data' => $base64Image]]],
        WINE_INFO_SCHEMA,
        'vision'  // entrée image : on vise le modèle choisi pour la lecture d'étiquettes
    ));
    // Sur échec, on garde la taille de l'image dans le journal : une photo non
    // réduite côté client est la cause n°1 d'un délai réseau dépassé.
    $imgKo = round(strlen($base64Image) * 3 / 4 / 1024);
    $logResp = $result['raw'] ?? ($result['error'] ?? null);
    if (empty($result['ok']) && $logResp !== null) {
        $logResp = 'photo ≈ ' . $imgKo . ' Ko | ' . $logResp;
    }
    log_ai_enrichment(null, 'photo', $prompt, $logResp);
    return $result;
}

function enrich_wine_from_photo(string $base64Image, string $mimeType): array
{
    $prompt = 'Tu es un expert en vin et sommelier. Lis attentivement cette photo d\'étiquette de bouteille de vin et '
        . 'identifie le vin. Donne les informations les plus précises possible. Si un champ n\'est pas lisible ou incertain, '
        . 'laisse-le vide plutôt que d\'inventer. Si ce vin a une mention de classification/vieillissement propre à sa '
        . 'région (par exemple Crianza, Reserva, Gran Reserva pour l\'Espagne, Riserva pour l\'Italie, ou toute autre '
        . 'mention équivalente visible sur l\'étiquette), indique-la dans classification ; sinon laisse ce champ vide. '
        . 'Réponds uniquement avec les champs du schéma JSON fourni.';

    $parts = [
        ['text' => $prompt],
        ['inline_data' => ['mime_type' => $mimeType, 'data' => $base64Image]],
    ];

    $result = gemini_call($parts, WINE_ENRICH_SCHEMA, 'vision');
    log_ai_enrichment(null, 'photo', $prompt, $result['raw'] ?? ($result['error'] ?? null));
    return $result;
}

const LABEL_CROP_LOADERS = [
    'image/jpeg' => 'imagecreatefromjpeg',
    'image/png' => 'imagecreatefrompng',
    'image/webp' => 'imagecreatefromwebp',
];
const LABEL_CROP_SAVERS = [
    'image/jpeg' => 88,
    'image/png' => 6,
    'image/webp' => 88,
];

function label_crop_save(string $mime, $image, string $path): bool
{
    return match ($mime) {
        'image/jpeg' => imagejpeg($image, $path, LABEL_CROP_SAVERS[$mime]),
        'image/png' => imagepng($image, $path, LABEL_CROP_SAVERS[$mime]),
        'image/webp' => imagewebp($image, $path, LABEL_CROP_SAVERS[$mime]),
        default => false,
    };
}

/**
 * Détecte la zone d'étiquette sur une photo, sans rien modifier : c'est
 * l'aperçu de recadrage (outil manuel/assisté) qui décide ensuite d'appliquer
 * cette suggestion, une zone ajustée par l'utilisateur, ou rien du tout.
 *
 * @return array{x:float,y:float,w:float,h:float}|null fractions (0..1) de
 *         l'image, ou null si aucune étiquette clairement identifiable.
 */
function detect_label_box(string $absolutePath): ?array
{
    $info = @getimagesize($absolutePath);
    if (!$info || !isset(LABEL_CROP_LOADERS[$info['mime']])) {
        return null;
    }
    $mime = $info['mime'];

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
        return null;
    }

    [$ymin, $xmin, $ymax, $xmax] = $result['data']['box_2d'];
    // Défensif : s'assurer que les bornes sont dans le bon ordre au cas où le modèle les inverse.
    if ($xmin > $xmax) { [$xmin, $xmax] = [$xmax, $xmin]; }
    if ($ymin > $ymax) { [$ymin, $ymax] = [$ymax, $ymin]; }

    // Marge de 6% autour de la zone détectée, pour ne pas couper de texte au bord.
    $marginX = ($xmax - $xmin) * 0.06;
    $marginY = ($ymax - $ymin) * 0.06;
    $xmin = max(0, $xmin - $marginX);
    $ymin = max(0, $ymin - $marginY);
    $xmax = min(1000, $xmax + $marginX);
    $ymax = min(1000, $ymax + $marginY);

    // Détection visiblement aberrante (zone quasi nulle ou couvrant toute la photo) : rien à proposer.
    $area = max(0, $xmax - $xmin) * max(0, $ymax - $ymin);
    if ($area / (1000 * 1000) < 0.08) {
        return null;
    }

    return ['x' => $xmin / 1000, 'y' => $ymin / 1000, 'w' => ($xmax - $xmin) / 1000, 'h' => ($ymax - $ymin) / 1000];
}

/**
 * Recadre un fichier image en place sur une zone donnée (fractions 0..1 de
 * chaque dimension) — utilisé une fois la zone validée (par l'IA acceptée
 * telle quelle, ajustée à la main, ou choisie entièrement à la main).
 */
function crop_image_to_fraction_box(string $absolutePath, float $x, float $y, float $w, float $h): bool
{
    $info = @getimagesize($absolutePath);
    if (!$info || !isset(LABEL_CROP_LOADERS[$info['mime']])) {
        return false;
    }
    [$width, $height] = $info;
    $mime = $info['mime'];

    $px1 = max(0, (int) round($x * $width));
    $py1 = max(0, (int) round($y * $height));
    $cropWidth = min($width - $px1, (int) round($w * $width));
    $cropHeight = min($height - $py1, (int) round($h * $height));
    if ($cropWidth < 20 || $cropHeight < 20) {
        return false;
    }

    $loader = LABEL_CROP_LOADERS[$mime];
    $source = @$loader($absolutePath);
    if (!$source) {
        return false;
    }
    $cropped = imagecrop($source, ['x' => $px1, 'y' => $py1, 'width' => $cropWidth, 'height' => $cropHeight]);
    if ($cropped === false) {
        return false;
    }
    // imagedestroy() est un no-op déprécié depuis PHP 8.0 (les ressources GD
    // sont des objets, libérés par le ramasse-miettes).
    return label_crop_save($mime, $cropped, $absolutePath);
}

/**
 * Recadrage entièrement automatique (détection + application immédiate),
 * pour les cas sans aperçu interactif possible (étiquette trouvée en ligne).
 * Best-effort : ne fait rien si la détection échoue, la photo reste inchangée.
 */
function crop_label_to_bottle(string $absolutePath): bool
{
    $box = detect_label_box($absolutePath);
    return $box !== null && crop_image_to_fraction_box($absolutePath, $box['x'], $box['y'], $box['w'], $box['h']);
}
