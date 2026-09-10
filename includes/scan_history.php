<?php
// Historique des vins scannés/photographiés en mode magasin, avec
// géolocalisation optionnelle (pour retrouver le magasin plus tard).

const SCAN_HISTORY_UA = 'MaCave/1.0 (cave a vin personnelle; macave.famille-dumas.fr)';

/**
 * Traduit des coordonnées GPS en libellé lisible ("Rue X, Ville") via
 * Nominatim (OpenStreetMap, gratuit, sans clé). Best-effort : une erreur
 * réseau ne doit jamais empêcher l'enregistrement de l'historique.
 */
function reverse_geocode(float $lat, float $lon): ?string
{
    $url = 'https://nominatim.openstreetmap.org/reverse?format=json&zoom=17&addressdetails=1'
        . '&lat=' . urlencode((string) $lat) . '&lon=' . urlencode((string) $lon);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => SCAN_HISTORY_UA,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code >= 400) {
        return null;
    }
    $json = json_decode($body, true);
    $addr = $json['address'] ?? null;
    if (!is_array($addr)) {
        return isset($json['display_name']) ? mb_substr($json['display_name'], 0, 255) : null;
    }
    // Priorité au nom du commerce/lieu s'il est connu de la base, sinon la rue.
    $place = $addr['shop'] ?? $addr['amenity'] ?? $addr['building'] ?? null;
    $street = trim(($addr['road'] ?? '') . ' ' . ($addr['house_number'] ?? ''));
    $city = $addr['city'] ?? $addr['town'] ?? $addr['village'] ?? $addr['municipality'] ?? null;
    $parts = array_filter([$place, $street ?: null, $city]);
    $label = implode(', ', array_unique($parts));
    if ($label === '' && isset($json['display_name'])) {
        $label = $json['display_name'];
    }
    return $label !== '' ? mb_substr($label, 0, 255) : null;
}

/**
 * Enregistre une entrée d'historique de scan.
 * @return array la ligne insérée, prête à être renvoyée en JSON au front
 */
function save_scan_history(PDO $db, array $data): array
{
    $lat = isset($data['latitude']) ? (float) $data['latitude'] : null;
    $lon = isset($data['longitude']) ? (float) $data['longitude'] : null;
    $locationLabel = ($lat !== null && $lon !== null) ? reverse_geocode($lat, $lon) : null;

    $stmt = $db->prepare(
        'INSERT INTO scan_history
         (scan_type, wine_name, producer, vintage, region, color, price_low, price_high, photo_path, latitude, longitude, location_label, details_json)
         VALUES (:scan_type, :wine_name, :producer, :vintage, :region, :color, :price_low, :price_high, :photo_path, :latitude, :longitude, :location_label, :details_json)'
    );
    $stmt->execute([
        'scan_type' => $data['scan_type'],
        'wine_name' => mb_substr($data['wine_name'], 0, 200),
        'producer' => $data['producer'] !== null && $data['producer'] !== '' ? mb_substr($data['producer'], 0, 200) : null,
        'vintage' => $data['vintage'] ?: null,
        'region' => $data['region'] !== null && $data['region'] !== '' ? mb_substr($data['region'], 0, 150) : null,
        'color' => $data['color'] ?: null,
        'price_low' => $data['price_low'] ?: null,
        'price_high' => $data['price_high'] ?: null,
        'photo_path' => $data['photo_path'] ?: null,
        'latitude' => $lat,
        'longitude' => $lon,
        'location_label' => $locationLabel,
        'details_json' => $data['details_json'] ?: null,
    ]);
    $id = (int) $db->lastInsertId();
    $stmt = $db->prepare('SELECT * FROM scan_history WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function get_scan_history(PDO $db, int $limit = 40): array
{
    $stmt = $db->prepare('SELECT * FROM scan_history ORDER BY created_at DESC LIMIT ' . max(1, $limit));
    $stmt->execute();
    return $stmt->fetchAll();
}

function delete_scan_history(PDO $db, int $id): void
{
    $stmt = $db->prepare('SELECT photo_path FROM scan_history WHERE id = ?');
    $stmt->execute([$id]);
    $photo = $stmt->fetchColumn();
    $db->prepare('DELETE FROM scan_history WHERE id = ?')->execute([$id]);
    if ($photo && is_file(__DIR__ . '/../' . $photo)) {
        @unlink(__DIR__ . '/../' . $photo);
    }
}

/**
 * Enregistre la photo d'un scan (même validation que les étiquettes : type
 * réel du fichier, taille, nom aléatoire), dans son propre dossier pour ne
 * pas mélanger avec les étiquettes officielles des fiches vin.
 */
function upload_scan_photo(string $field = 'photo'): ?string
{
    if (empty($_FILES[$field]['name']) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK || $_FILES[$field]['size'] > 8 * 1024 * 1024) {
        return null;
    }
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime = mime_content_type($_FILES[$field]['tmp_name']);
    if (!isset($allowed[$mime])) {
        return null;
    }
    $dir = __DIR__ . '/../uploads/scan_history/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    move_uploaded_file($_FILES[$field]['tmp_name'], $dir . $filename);
    return 'uploads/scan_history/' . $filename;
}

/** Lien Google Maps pointant sur des coordonnées (aucune clé requise). */
function maps_link_url(float $lat, float $lon): string
{
    return 'https://www.google.com/maps?q=' . urlencode($lat . ',' . $lon);
}
