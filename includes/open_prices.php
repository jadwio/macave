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
