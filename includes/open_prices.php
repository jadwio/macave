<?php
// Prix relevés en magasin via Open Prices (projet Open Food Facts).
// API publique, gratuite, sans clé : https://prices.openfoodfacts.org/api/docs
// Couverture faible sur le vin (données contributives, surtout grande
// distribution) — d'où le repli sur la saisie manuelle.

const OPEN_PRICES_ENDPOINT = 'https://prices.openfoodfacts.org/api/v1/prices';

/**
 * Prix connus pour un code-barres, du plus récent au plus ancien.
 *
 * @return array{ok:bool, prices?:array<int,array>, error?:string}
 */
function open_prices_lookup(string $barcode): array
{
    $barcode = preg_replace('/\D/', '', $barcode);
    if (strlen($barcode) < 8) {
        return ['ok' => false, 'error' => 'Code-barres invalide.'];
    }

    $url = OPEN_PRICES_ENDPOINT . '?' . http_build_query([
        'product_code' => $barcode,
        'order_by' => '-date',
        'size' => 20,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'MaCave/1.0 (cave a vin personnelle; macave.famille-dumas.fr)',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    // curl_close() est un no-op déprécié depuis PHP 8.0 — la ressource se libère seule.

    if ($body === false || $code >= 400) {
        return ['ok' => false, 'error' => 'Open Food Facts injoignable (HTTP ' . $code . ').'];
    }
    $json = json_decode($body, true);
    if (!is_array($json) || !isset($json['items'])) {
        return ['ok' => false, 'error' => 'Réponse Open Food Facts inattendue.'];
    }

    $prices = [];
    foreach ($json['items'] as $it) {
        $loc = $it['location'] ?? null;
        $shop = null;
        if (is_array($loc)) {
            $parts = array_filter([
                $loc['osm_brand'] ?? $loc['osm_name'] ?? null,
                $loc['osm_address_city'] ?? null,
            ]);
            $shop = $parts ? implode(', ', $parts) : null;
        }
        $prices[] = [
            'price' => (float) ($it['price'] ?? 0),
            'currency' => (string) ($it['currency'] ?? 'EUR'),
            'date' => (string) ($it['date'] ?? ''),
            'discounted' => !empty($it['price_is_discounted']),
            'shop' => $shop,
        ];
    }

    return ['ok' => true, 'prices' => $prices];
}

/**
 * Tente de retrouver un code-barres pour un vin identifié par son nom (et
 * éventuellement son producteur), via la recherche Open Food Facts déjà
 * utilisée par le résolveur de vin (barcode_lookup.php / wine_resolve.php).
 * Best effort, très inégal selon le vin (couvre surtout la grande
 * distribution) — retourne null sinon, sans lever d'erreur.
 */
function guess_barcode_from_name(string $name, ?string $producer = null): ?string
{
    require_once __DIR__ . '/wine_resolver.php';
    $query = trim($name . ' ' . ($producer ?? ''));
    if ($query === '') {
        return null;
    }
    $candidate = wr_source_off_search($query);
    return $candidate['ean'] ?? null;
}

/**
 * Synthèse chiffrée d'une liste de relevés (moyenne/mini/maxi), limitée aux
 * prix en euros et aux plus récents : mélanger les devises fausserait la
 * moyenne, et un vieux relevé pèse moins qu'un récent.
 *
 * @param array<int,array> $prices comme renvoyé par open_prices_lookup()
 * @return array{avg:float,low:float,high:float,count:int}|null
 */
function summarize_prices(array $prices, int $limit = 10): ?array
{
    $eur = array_values(array_filter($prices, fn($p) => strtoupper((string) ($p['currency'] ?? '')) === 'EUR'));
    $sample = array_slice($eur, 0, $limit);
    if (!$sample) {
        return null;
    }
    $values = array_column($sample, 'price');
    return [
        'avg' => round(array_sum($values) / count($values), 2),
        'low' => min($values),
        'high' => max($values),
        'count' => count($sample),
    ];
}

/** Libellé court d'un relevé, pour la note d'historique. */
function open_prices_note(array $price): string
{
    $bits = ['Open Food Facts'];
    if (!empty($price['shop'])) {
        $bits[] = $price['shop'];
    }
    if (!empty($price['date'])) {
        $bits[] = $price['date'];
    }
    if (!empty($price['discounted'])) {
        $bits[] = 'promo';
    }
    return mb_substr(implode(' · ', $bits), 0, 255);
}
