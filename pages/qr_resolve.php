<?php
// Résolution d'un QR code contenant une URL (étiquette électronique, site producteur) :
// récupère le titre de la page pour en déduire le nom du vin.
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

$url = trim($_GET['url'] ?? '');
if (strlen($url) > 2000 || !preg_match('#^https?://#i', $url)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'URL invalide.']);
    exit;
}

$host = parse_url($url, PHP_URL_HOST);
if (!$host) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'URL invalide.']);
    exit;
}

// Anti-SSRF : on refuse les hôtes qui résolvent vers des IP privées/réservées.
$ips = gethostbynamel($host) ?: [];
if (!$ips) {
    echo json_encode(['ok' => false, 'error' => 'Hôte introuvable.']);
    exit;
}
foreach ($ips as $ip) {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'URL non autorisée.']);
        exit;
    }
}

// Téléchargement plafonné à 400 Ko : il ne nous faut que le <head>.
$body = '';
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_TIMEOUT => 10,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 3,
    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    CURLOPT_USERAGENT => 'MaCave/1.0 (cave a vin personnelle; macave.famille-dumas.fr)',
    CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body) {
        $body .= $chunk;
        return strlen($body) > 400 * 1024 ? 0 : strlen($chunk); // stoppe au-delà de 400 Ko
    },
]);
curl_exec($ch);
$finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
curl_close($ch);

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
