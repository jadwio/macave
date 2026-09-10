<?php
/**
 * WineResolver — point d'entrée unique pour identifier un vin.
 *
 *  WineResolver
 *   │
 *   ├── recherche EAN
 *   │      └── Open Food Facts
 *   │
 *   ├── recherche nom
 *   │      └── sources vin
 *   │
 *   └── recherche domaine/appellation
 *          └── sources vin
 *
 * Les "sources vin" sont les fournisseurs gratuits interrogés en cascade :
 * Open Food Facts (recherche produit), Wikipédia FR (domaines/appellations)
 * et l'IA Gemini (synthèse). Chaque source renvoie une fiche partiellement
 * remplie ; le resolver les fusionne par ordre de fiabilité factuelle.
 */

require_once __DIR__ . '/ai_enrichment.php';

const WR_USER_AGENT = 'MaCave/1.0 (cave a vin personnelle; macave.famille-dumas.fr)';

/** Fiche vide normalisée : toutes les sources produisent cette forme. */
function wr_empty_wine(): array
{
    return [
        'ean' => null,
        'name' => null,
        'producer' => null,
        'region' => null,
        'appellation' => null,
        'country' => null,
        'color' => null,
        'vintage' => null,
        'volume_ml' => null,
        'grape_varieties' => [],
        'description' => null,
        'food_pairing' => null,
        'drink_from_year' => null,
        'drink_until_year' => null,
        'estimated_price_eur' => null,
        'classification' => null,
    ];
}

function wr_http_get(string $url, int $timeout = 12): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => WR_USER_AGENT,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($body === false || $code >= 400) ? null : $body;
}

/**
 * Fusionne des fiches : la première source à renseigner un champ gagne.
 * L'ordre d'appel porte donc la hiérarchie de confiance (données produit
 * réelles > encyclopédie > IA générative).
 */
function wr_merge(array ...$wines): array
{
    $out = wr_empty_wine();
    foreach ($wines as $w) {
        foreach ($out as $k => $v) {
            if (!array_key_exists($k, $w)) {
                continue;
            }
            $isEmpty = $v === null || $v === '' || $v === [];
            $candidate = $w[$k];
            $hasValue = $candidate !== null && $candidate !== '' && $candidate !== [];
            if ($isEmpty && $hasValue) {
                $out[$k] = $candidate;
            }
        }
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Sources
// ---------------------------------------------------------------------------

/** Compare deux libellés sans tenir compte de la casse ni des accents. */
function wr_normalize_text(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['à'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
                    'î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c']);
    return preg_replace('/[^a-z0-9]+/', ' ', $s) ?? $s;
}

/**
 * Garde-fou de pertinence : la recherche plein texte d'Open Food Facts peut
 * renvoyer n'importe quel produit (un fromage pour « Sancerre »). On n'accepte
 * un résultat que s'il partage un mot significatif avec la requête.
 */
function wr_is_relevant(string $query, string $candidate): bool
{
    $qWords = array_filter(explode(' ', wr_normalize_text($query)), fn($w) => mb_strlen($w) >= 4);
    if (!$qWords) {
        return false;
    }
    $cand = wr_normalize_text($candidate);
    foreach ($qWords as $w) {
        if (str_contains($cand, $w)) {
            return true;
        }
    }
    return false;
}

/** Normalise un produit Open Food Facts vers la fiche interne. */
function wr_normalize_off_product(array $p, ?string $ean = null): array
{
    $w = wr_empty_wine();
    $w['ean'] = $ean;
    $w['name'] = trim($p['product_name_fr'] ?? '') ?: (trim($p['product_name'] ?? '') ?: null);

    if (!empty($p['brands'])) {
        $w['producer'] = trim(explode(',', $p['brands'])[0]) ?: null;
    }

    // "75 cl", "750 ml", "0.75 l" -> millilitres
    if (!empty($p['quantity']) && preg_match('/([\d.,]+)\s*(ml|cl|l)\b/i', $p['quantity'], $m)) {
        $val = (float) str_replace(',', '.', $m[1]);
        $w['volume_ml'] = (int) round(match (strtolower($m[2])) {
            'ml' => $val,
            'cl' => $val * 10,
            'l' => $val * 1000,
        });
    }

    $cats = implode(' ', $p['categories_tags'] ?? []);
    if (str_contains($cats, 'sparkling') || str_contains($cats, 'champagne') || str_contains($cats, 'cremant')) {
        $w['color'] = 'sparkling';
    } elseif (str_contains($cats, 'red-wine') || str_contains($cats, 'vins-rouges')) {
        $w['color'] = 'red';
    } elseif (str_contains($cats, 'white-wine') || str_contains($cats, 'vins-blancs')) {
        $w['color'] = 'white';
    } elseif (str_contains($cats, 'rose-wine') || str_contains($cats, 'vins-roses')) {
        $w['color'] = 'rose';
    }

    if (!empty($p['countries'])) {
        $country = trim(explode(',', $p['countries'])[0]);
        if (preg_match('/^[a-z]{2}:(.+)$/', $country, $m)) {
            $country = ucfirst($m[1]);
        }
        $w['country'] = $country ?: null;
    }

    return $w;
}

/** Source : Open Food Facts, fiche produit par code-barres. */
function wr_source_off_product(string $ean): ?array
{
    $url = 'https://world.openfoodfacts.org/api/v2/product/' . $ean . '.json'
        . '?fields=product_name,product_name_fr,brands,quantity,categories_tags,countries';
    $body = wr_http_get($url);
    if ($body === null) {
        return null;
    }
    $json = json_decode($body, true);
    if (!$json || (int) ($json['status'] ?? 0) !== 1 || empty($json['product'])) {
        return null;
    }
    $wine = wr_normalize_off_product($json['product'], $ean);
    return $wine['name'] ? $wine : null;
}

/**
 * Source : Open Food Facts, recherche par nom dans la catégorie vins.
 * Utilise l'endpoint historique cgi/search.pl : c'est le seul qui accepte
 * de façon fiable un filtre de catégorie combiné à des termes de recherche
 * (l'API v2 renvoie une erreur sur cette combinaison).
 */
function wr_source_off_search(string $name): ?array
{
    $url = 'https://world.openfoodfacts.org/cgi/search.pl?' . http_build_query([
        'search_terms' => $name,
        'tagtype_0' => 'categories',
        'tag_contains_0' => 'contains',
        'tag_0' => 'wines',
        'json' => 1,
        'page_size' => 5,
        'fields' => 'code,product_name,product_name_fr,brands,quantity,categories_tags,countries',
    ]);
    $body = wr_http_get($url);
    if ($body === null || $body === '' || $body[0] !== '{') {
        return null; // OFF renvoie parfois une page HTML d'indisponibilité
    }
    $json = json_decode($body, true);
    foreach ($json['products'] ?? [] as $p) {
        $wine = wr_normalize_off_product($p, $p['code'] ?? null);
        if ($wine['name'] && wr_is_relevant($name, $wine['name'] . ' ' . ($wine['producer'] ?? ''))) {
            return $wine;
        }
    }
    return null;
}

/**
 * Source : Wikipédia FR — pertinent surtout pour les domaines, châteaux
 * et appellations, que Open Food Facts ne couvre pas.
 */
function wr_source_wikipedia(string $query): ?array
{
    $searchUrl = 'https://fr.wikipedia.org/w/api.php?' . http_build_query([
        'action' => 'query',
        'list' => 'search',
        'srsearch' => $query . ' vin',
        'srlimit' => 1,
        'format' => 'json',
    ]);
    $body = wr_http_get($searchUrl, 10);
    if ($body === null) {
        return null;
    }
    $json = json_decode($body, true);
    $title = $json['query']['search'][0]['title'] ?? null;
    if (!$title) {
        return null;
    }

    $summaryUrl = 'https://fr.wikipedia.org/api/rest_v1/page/summary/' . rawurlencode($title);
    $body = wr_http_get($summaryUrl, 10);
    if ($body === null) {
        return null;
    }
    $summary = json_decode($body, true);
    $extract = trim($summary['extract'] ?? '');
    if ($extract === '') {
        return null;
    }

    $wine = wr_empty_wine();
    $wine['name'] = $title;
    $wine['description'] = $extract;
    return $wine;
}

/** Dernière erreur remontée par l'IA, pour distinguer « vin inconnu » de « IA indisponible ». */
function wr_last_ai_error(?string $set = null): ?string
{
    static $err = null;
    if (func_num_args() > 0) {
        $err = $set;
    }
    return $err;
}

/** Source : IA Gemini — comble les champs œnologiques que les autres n'ont pas. */
function wr_source_ai(string $name, ?string $producer = null, ?string $vintage = null): ?array
{
    wr_last_ai_error(null);
    $result = enrich_wine_from_text($name, $producer, $vintage);
    if (empty($result['ok']) || empty($result['data'])) {
        wr_last_ai_error($result['error'] ?? 'IA indisponible.');
        return null;
    }
    $d = $result['data'];
    $wine = wr_empty_wine();
    foreach (['producer', 'region', 'appellation', 'country', 'color', 'description',
              'food_pairing', 'drink_from_year', 'drink_until_year', 'classification'] as $k) {
        if (isset($d[$k]) && $d[$k] !== '') {
            $wine[$k] = $d[$k];
        }
    }
    if (!empty($d['grape_varieties']) && is_array($d['grape_varieties'])) {
        $wine['grape_varieties'] = $d['grape_varieties'];
    }
    if (!empty($d['estimated_price_eur'])) {
        $wine['estimated_price_eur'] = (float) $d['estimated_price_eur'];
    }
    return $wine;
}

// ---------------------------------------------------------------------------
// Branches du resolver
// ---------------------------------------------------------------------------

/**
 * Branche « recherche EAN » → Open Food Facts.
 * @return array{ok:bool, wine?:array, sources?:list<string>, error?:string}
 */
function wine_resolve_by_ean(string $ean, bool $enrichWithAi = true): array
{
    $ean = preg_replace('/\D/', '', $ean);
    if (!preg_match('/^(\d{8}|\d{13})$/', $ean)) {
        return ['ok' => false, 'error' => 'Code EAN invalide (8 ou 13 chiffres).'];
    }

    $off = wr_source_off_product($ean);
    if (!$off) {
        return ['ok' => false, 'error' => 'Produit introuvable dans Open Food Facts (fréquent pour les vins de petits domaines).'];
    }

    $sources = ['Open Food Facts'];
    $ai = null;
    if ($enrichWithAi && $off['name']) {
        $ai = wr_source_ai($off['name'], $off['producer']);
        if ($ai) {
            $sources[] = 'IA Gemini';
        }
    }

    // OFF d'abord : ce sont des données produit réelles, l'IA ne fait que compléter.
    return ['ok' => true, 'wine' => wr_merge($off, $ai ?? []), 'sources' => $sources];
}

/**
 * Branche « recherche nom » → sources vin.
 * Open Food Facts (produit commercialisé) puis IA pour l'œnologie.
 */
function wine_resolve_by_name(string $name, ?string $producer = null, ?string $vintage = null): array
{
    $name = trim($name);
    if ($name === '') {
        return ['ok' => false, 'error' => 'Nom du vin manquant.'];
    }

    $sources = [];
    $off = wr_source_off_search($name);
    if ($off) {
        $sources[] = 'Open Food Facts';
    }
    $ai = wr_source_ai($name, $producer ?: ($off['producer'] ?? null), $vintage);
    if ($ai) {
        $sources[] = 'IA Gemini';
    }

    if (!$off && !$ai) {
        $aiErr = wr_last_ai_error();
        return ['ok' => false, 'error' => $aiErr
            ? 'Aucune source disponible pour le moment (' . $aiErr . ').'
            : 'Aucune source n\'a pu identifier ce vin.'];
    }

    $base = wr_empty_wine();
    $base['name'] = $name;
    if ($vintage) {
        $base['vintage'] = (int) $vintage;
    }
    if ($producer) {
        $base['producer'] = $producer;
    }

    return ['ok' => true, 'wine' => wr_merge($base, $off ?? [], $ai ?? []), 'sources' => $sources];
}

/**
 * Branche « recherche domaine/appellation » → sources vin.
 * Wikipédia est ici la source primaire : les domaines et appellations y sont
 * documentés, alors qu'ils n'existent pas comme produit dans Open Food Facts.
 */
function wine_resolve_by_domain(string $domain): array
{
    $domain = trim($domain);
    if ($domain === '') {
        return ['ok' => false, 'error' => 'Domaine ou appellation manquant.'];
    }

    $sources = [];
    $wiki = wr_source_wikipedia($domain);
    if ($wiki) {
        $sources[] = 'Wikipédia';
    }
    $ai = wr_source_ai($domain);
    if ($ai) {
        $sources[] = 'IA Gemini';
    }

    if (!$wiki && !$ai) {
        $aiErr = wr_last_ai_error();
        return ['ok' => false, 'error' => $aiErr
            ? 'Aucune source disponible pour le moment (' . $aiErr . ').'
            : 'Aucune source n\'a pu documenter ce domaine ou cette appellation.'];
    }

    $base = wr_empty_wine();
    $base['name'] = $domain;

    // Wikipédia d'abord : son titre est la forme canonique (« Château Margaux »
    // même si l'utilisateur a tapé « Chateau Margaux »). $base ne sert que de repli.
    return ['ok' => true, 'wine' => wr_merge($wiki ?? [], $ai ?? [], $base), 'sources' => $sources];
}

/**
 * Aiguillage : choisit la branche selon ce qui est fourni.
 * @param array{ean?:string, name?:string, domain?:string, producer?:string, vintage?:string} $query
 */
function wine_resolve(array $query): array
{
    if (!empty($query['ean'])) {
        return wine_resolve_by_ean((string) $query['ean']);
    }
    if (!empty($query['domain'])) {
        return wine_resolve_by_domain((string) $query['domain']);
    }
    if (!empty($query['name'])) {
        return wine_resolve_by_name(
            (string) $query['name'],
            $query['producer'] ?? null,
            isset($query['vintage']) ? (string) $query['vintage'] : null
        );
    }
    return ['ok' => false, 'error' => 'Fournis un code EAN, un nom de vin ou un domaine/appellation.'];
}
