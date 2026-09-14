<?php
/**
 * Recherche d'étiquettes sur internet pour un vin sans photo.
 *
 * Sources retenues — toutes deux exposent de vraies photos sous licence
 * réutilisable, ce qui n'est pas le cas des moteurs de recherche d'images
 * (pas d'API gratuite exploitable, et scraper leurs résultats serait fragile
 * autant que contraire à leurs conditions) :
 *   - Open Food Facts : photos de produits, licence CC BY-SA
 *   - Wikimedia Commons : médias libres, licence indiquée par image
 *
 * Rien n'est enregistré ici : la recherche ne fait que proposer des candidats.
 * Le téléchargement effectif passe par download_label_image(), déclenché
 * uniquement après validation explicite.
 */

require_once __DIR__ . '/wine_resolver.php';

/** Hôtes autorisés au téléchargement : seules nos sources connues. */
const LABEL_ALLOWED_HOSTS = ['images.openfoodfacts.org', 'upload.wikimedia.org'];

const LABEL_MAX_BYTES = 6 * 1024 * 1024;

/**
 * Candidats d'étiquette pour un vin.
 * @return list<array{url:string, thumb:string, source:string, credit:string, title:string}>
 */
function search_label_candidates(array $wine): array
{
    $name = trim((string) ($wine['name'] ?? ''));
    if ($name === '') {
        return [];
    }
    $producer = trim((string) ($wine['producer'] ?? ''));
    $query = $producer !== '' && strcasecmp($producer, $name) !== 0 ? "$name $producer" : $name;

    $candidates = array_merge(
        label_candidates_from_off($query),
        label_candidates_from_commons($query)
    );

    // Dédoublonnage par URL
    $seen = [];
    $unique = [];
    foreach ($candidates as $c) {
        if (isset($seen[$c['url']])) {
            continue;
        }
        $seen[$c['url']] = true;
        $unique[] = $c;
    }

    // Les vraies photos de produit d'abord, les photos de domaine ensuite.
    usort($unique, fn($a, $b) => ($a['rank'] ?? 9) <=> ($b['rank'] ?? 9));
    return array_slice($unique, 0, 8);
}

/** Source : photos de produits Open Food Facts. */
function label_candidates_from_off(string $query): array
{
    $url = 'https://world.openfoodfacts.org/cgi/search.pl?' . http_build_query([
        'search_terms' => $query,
        'tagtype_0' => 'categories',
        'tag_contains_0' => 'contains',
        'tag_0' => 'wines',
        'json' => 1,
        'page_size' => 6,
        'fields' => 'code,product_name,product_name_fr,brands,image_front_url,image_front_small_url',
    ]);
    $body = wr_http_get($url);
    // OFF renvoie parfois une page HTML d'indisponibilité
    if ($body === null || $body === '' || $body[0] !== '{') {
        return [];
    }
    $json = json_decode($body, true);

    $out = [];
    foreach ($json['products'] ?? [] as $p) {
        if (empty($p['image_front_url'])) {
            continue;
        }
        $title = trim($p['product_name_fr'] ?? '') ?: trim($p['product_name'] ?? '');
        // Pertinence évaluée sur le NOM du produit seul : inclure la marque
        // ramènerait les autres références du même groupe (« Mederano Tinto »
        // pour une recherche « Freixenet »).
        if ($title === '' || !wr_is_relevant($query, $title)) {
            continue;
        }
        $out[] = [
            'url' => $p['image_front_url'],
            'thumb' => $p['image_front_small_url'] ?? $p['image_front_url'],
            'source' => 'Open Food Facts',
            'credit' => 'Open Food Facts — CC BY-SA',
            'title' => $title,
            'note' => null,
            'rank' => 0, // photo de face du produit : c'est bien l'étiquette
        ];
    }
    return $out;
}

/**
 * Sujets qui ne sont manifestement pas une étiquette : Commons regorge de
 * photos de bâtiments, vignobles et chais qui polluent les résultats.
 */
const COMMONS_EXCLUDE_WORDS = [
    'exterior', 'interior', 'entrance', 'facade', 'building', 'castle', 'tower',
    'vineyard', 'vignoble', 'vigne', 'grape', 'raisin', 'harvest', 'vendange',
    'cellar', 'chai', 'barrel', 'tonneau', 'cave', 'aerial', 'map', 'carte',
    'plan', 'logo', 'sign', 'panneau', 'portrait', 'statue', 'church', 'village',
    'landscape', 'paysage', 'garden', 'jardin', 'street', 'rue',
];

/** Mots qui, au contraire, signalent une bouteille ou une étiquette. */
const COMMONS_PREFER_WORDS = ['label', 'etiquette', 'bottle', 'bouteille'];

/**
 * Mots neutres tolérés dans un titre : formats, mentions génériques, régions
 * et appellations. Tout autre mot significatif absent de la requête signale
 * un domaine différent (« La Mission Haut-Brion » vs « Haut-Brion »).
 */
const COMMONS_NEUTRAL_WORDS = [
    'wine', 'wines', 'vin', 'vins', 'bottle', 'bouteille', 'label', 'etiquette',
    'jpg', 'jpeg', 'png', 'webp', 'photo', 'image', 'detail', 'closeup',
    'france', 'french', 'francais', 'francaise',
    'bordeaux', 'bourgogne', 'burgundy', 'champagne', 'alsace', 'loire', 'rhone',
    'graves', 'medoc', 'pauillac', 'pessac', 'leognan', 'emilion', 'pomerol',
    'rouge', 'blanc', 'rose', 'red', 'white', 'grand', 'cru', 'classe',
];

/**
 * Le titre décrit-il plausiblement une bouteille de CE vin ?
 * Commons ramène volontiers des domaines voisins (« La Mission Haut-Brion »
 * pour « Haut-Brion ») : on exige donc tous les mots significatifs de la
 * requête, et on écarte les sujets manifestement hors propos.
 */
function commons_title_is_relevant(string $query, string $title): bool
{
    $t = wr_normalize_text($title);
    foreach (COMMONS_EXCLUDE_WORDS as $bad) {
        if (str_contains($t, $bad)) {
            return false;
        }
    }
    $words = array_filter(explode(' ', wr_normalize_text($query)), fn($w) => mb_strlen($w) >= 4);
    // « château » est trop courant pour discriminer quoi que ce soit
    $words = array_filter($words, fn($w) => !in_array($w, ['chateau', 'domaine', 'clos'], true));
    if (!$words) {
        return false;
    }
    foreach ($words as $w) {
        if (!str_contains($t, $w)) {
            return false;
        }
    }

    // Un mot significatif en trop désigne presque toujours un autre domaine :
    // « Château la Mission Haut-Brion » contient bien « haut » et « brion »,
    // mais « mission » en fait un vin différent.
    $ignored = array_merge(COMMONS_NEUTRAL_WORDS, ['chateau', 'domaine', 'clos'], $words);
    foreach (explode(' ', $t) as $titleWord) {
        if (mb_strlen($titleWord) < 4 || ctype_digit($titleWord)) {
            continue; // millésimes et mots courts sans importance
        }
        if (!in_array($titleWord, $ignored, true)) {
            return false;
        }
    }
    return true;
}

/** Source : Wikimedia Commons (utile pour les châteaux et domaines connus). */
function label_candidates_from_commons(string $query): array
{
    $url = 'https://commons.wikimedia.org/w/api.php?' . http_build_query([
        'action' => 'query',
        'generator' => 'search',
        'gsrsearch' => $query . ' wine',
        'gsrnamespace' => 6,      // espace « Fichier »
        'gsrlimit' => 6,
        'prop' => 'imageinfo',
        'iiprop' => 'url|extmetadata',
        'iiurlwidth' => 500,
        'format' => 'json',
    ]);
    $body = wr_http_get($url);
    if ($body === null) {
        return [];
    }
    $json = json_decode($body, true);

    $out = [];
    foreach ($json['query']['pages'] ?? [] as $page) {
        $info = $page['imageinfo'][0] ?? null;
        if (!$info || empty($info['url'])) {
            continue;
        }
        // On écarte les formats non photographiques. L'extension est testée sur
        // le chemin seul : Commons ajoute des paramètres de suivi à ses URLs
        // (?utm_source=...), qui masqueraient l'extension.
        $path = parse_url($info['url'], PHP_URL_PATH) ?? '';
        if (!preg_match('/\.(jpe?g|png|webp)$/i', $path)) {
            continue;
        }
        $title = str_replace(['File:', '_'], ['', ' '], $page['title'] ?? '');
        if (!commons_title_is_relevant($query, $title)) {
            continue;
        }
        $license = $info['extmetadata']['LicenseShortName']['value'] ?? 'Licence Wikimedia';
        $t = wr_normalize_text($title);
        $looksLikeLabel = false;
        foreach (COMMONS_PREFER_WORDS as $good) {
            if (str_contains($t, $good)) {
                $looksLikeLabel = true;
                break;
            }
        }
        $out[] = [
            'url' => $info['url'],
            'thumb' => $info['thumburl'] ?? $info['url'],
            'source' => 'Wikimedia Commons',
            'credit' => 'Wikimedia Commons — ' . strip_tags($license),
            'title' => $title,
            // Commons n'est pas une base d'étiquettes : on prévient l'utilisateur
            // quand rien n'indique qu'il s'agit d'une bouteille.
            'note' => $looksLikeLabel ? null : 'Photo liée au domaine — pas forcément l\'étiquette',
            'rank' => $looksLikeLabel ? 1 : 2,
        ];
    }
    return $out;
}

/**
 * Télécharge une étiquette proposée et l'enregistre dans uploads/labels/.
 * Appelé seulement après validation de l'utilisateur.
 *
 * @return array{ok:bool, path?:string, error?:string}
 */
function download_label_image(string $url): array
{
    $host = parse_url($url, PHP_URL_HOST);
    $scheme = parse_url($url, PHP_URL_SCHEME);
    // On n'accepte que nos sources : interdit de faire télécharger n'importe
    // quelle URL au serveur (SSRF).
    if ($scheme !== 'https' || !in_array($host, LABEL_ALLOWED_HOSTS, true)) {
        return ['ok' => false, 'error' => 'Source d\'image non autorisée.'];
    }

    // Suit les redirections nous-mêmes (FOLLOWLOCATION désactivé) pour revalider
    // chaque saut contre LABEL_ALLOWED_HOSTS, plutôt que de laisser curl suivre
    // aveuglément une redirection émise par l'un de ces hôtes.
    $currentUrl = $url;
    $body = '';
    $code = 0;
    for ($hop = 0; $hop <= 3; $hop++) {
        $body = '';
        $ch = curl_init($currentUrl);
        curl_setopt_array($ch, [
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => WR_USER_AGENT,
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body) {
                $body .= $chunk;
                return strlen($body) > LABEL_MAX_BYTES ? 0 : strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: null;
        curl_close($ch);

        if ($code >= 300 && $code < 400 && $redirectUrl) {
            $redirectHost = parse_url($redirectUrl, PHP_URL_HOST);
            $redirectScheme = parse_url($redirectUrl, PHP_URL_SCHEME);
            if ($redirectScheme === 'https' && in_array($redirectHost, LABEL_ALLOWED_HOSTS, true)) {
                $currentUrl = $redirectUrl;
                continue;
            }
            return ['ok' => false, 'error' => 'Source d\'image non autorisée.'];
        }
        break;
    }

    if ($body === '' || $code >= 400) {
        return ['ok' => false, 'error' => 'Téléchargement impossible.'];
    }

    // On vérifie que c'est bien une image, et de quel type
    $info = @getimagesizefromstring($body);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!$info || !isset($allowed[$info['mime']])) {
        return ['ok' => false, 'error' => 'Le fichier téléchargé n\'est pas une image exploitable.'];
    }

    $dir = __DIR__ . '/../uploads/labels/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$info['mime']];
    if (file_put_contents($dir . $filename, $body) === false) {
        return ['ok' => false, 'error' => 'Enregistrement du fichier impossible.'];
    }
    return ['ok' => true, 'path' => 'uploads/labels/' . $filename];
}
