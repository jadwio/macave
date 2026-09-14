<?php
// Résolution d'un QR code contenant une URL (étiquette électronique, site producteur) :
// récupère le titre de la page pour en déduire le nom du vin.
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

// Anti-SSRF : refuse tout hôte qui résolve vers une IP privée/réservée. Appelée
// à CHAQUE saut de redirection (pas seulement l'URL de départ) : sans ça, un
// hôte public tout à fait légitime au premier contrôle peut ensuite rediriger
// (302) vers http://127.0.0.1/... ou http://169.254.169.254/..., et curl suit
// cette redirection sans jamais repasser par ce contrôle.
function qr_validate_public_url(string $url): bool
{
    if (strlen($url) > 2000 || !preg_match('#^https?://#i', $url)) {
        return false;
    }
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) {
        return false;
    }
    $ips = gethostbynamel($host) ?: [];
    if (!$ips) {
        return false;
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
    }
    return true;
}

$url = trim($_GET['url'] ?? '');
if (!qr_validate_public_url($url)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'URL invalide ou non autorisée.']);
    exit;
}

// Suit les redirections nous-mêmes (CURLOPT_FOLLOWLOCATION désactivé) pour
// pouvoir revalider chaque nouvelle destination avant de la contacter.
$currentUrl = $url;
$finalUrl = $url;
$body = '';
$maxHops = 3;

for ($hop = 0; $hop <= $maxHops; $hop++) {
    if (!qr_validate_public_url($currentUrl)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'URL non autorisée.']);
        exit;
    }

    $body = '';
    $ch = curl_init($currentUrl);
    curl_setopt_array($ch, [
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'MaCave/1.0 (cave a vin personnelle; macave.famille-dumas.fr)',
        // Téléchargement plafonné à 400 Ko : il ne nous faut que le <head>.
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body) {
            $body .= $chunk;
            return strlen($body) > 400 * 1024 ? 0 : strlen($chunk);
        },
    ]);
    curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: null;
    curl_close($ch);

    if ($httpCode >= 300 && $httpCode < 400 && $redirectUrl) {
        $currentUrl = $redirectUrl;
        $finalUrl = $redirectUrl;
        continue;
    }
    $finalUrl = $currentUrl;
    break;
}

if ($body === '') {
    echo json_encode(['ok' => false, 'error' => 'Page inaccessible.']);
    exit;
}

$title = null;
if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]*content=["\']([^"\']+)["\']/i', $body, $m)
    || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]*property=["\']og:title["\']/i', $body, $m)
    || preg_match('/<title[^>]*>(.*?)<\/title>/is', $body, $m)) {
    $title = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    // Nettoyage des suffixes de site : "Château X 2019 | Domaine Y" -> "Château X 2019"
    $title = preg_split('/\s*[|·—•]\s*|\s+-\s+/u', $title)[0] ?? $title;
    $title = mb_substr($title, 0, 150);
}

if (!$title) {
    echo json_encode(['ok' => false, 'error' => 'Impossible d\'extraire un nom depuis cette page.', 'url' => $finalUrl]);
    exit;
}

echo json_encode(['ok' => true, 'data' => ['title' => $title, 'url' => $finalUrl]]);
