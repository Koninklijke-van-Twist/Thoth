<?php

declare(strict_types=1);

/**
 * Adres zoeken en omgekeerd geocoderen via OpenStreetMap Nominatim, server-side
 * (zodat Thoth zich met een nette User-Agent en Referer identificeert, zie
 * https://operations.osmfoundation.org/policies/nominatim/):
 *  - maximaal 1 request per seconde voor de hele installatie (lock + tijdstempel in de datamap);
 *  - antwoorden 7 dagen gecachet in data/geocache/;
 *  - alleen het minimum terug naar de browser (label, lat/lon, adresvelden).
 * De browser haalt alleen kaarttegels rechtstreeks van tile.openstreetmap.org.
 */

const THOTH_NOMINATIM_BASE = 'https://nominatim.openstreetmap.org';
const THOTH_GEO_USER_AGENT = 'Thoth/1.0 (Koninklijke van Twist; https://sleutels.kvt.nl/thoth/)';
const THOTH_GEO_REFERER = 'https://sleutels.kvt.nl/thoth/';
const THOTH_GEO_CACHE_TTL = 604800;
const THOTH_GEO_MIN_INTERVAL_US = 1000000;

function thoth_geo_cache_dir(): string
{
    $dir = thoth_data_dir() . '/geocache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return $dir;
}

/** Wacht tot de vorige Nominatim-call minstens 1 s geleden is (over alle PHP-processen heen). */
function thoth_geo_throttle(): void
{
    $sleeper = $GLOBALS['thothGeoSleep'] ?? 'usleep';
    $fh = fopen(thoth_data_dir() . '/nominatim.lock', 'c+');
    if ($fh === false) {
        $sleeper(THOTH_GEO_MIN_INTERVAL_US);
        return;
    }
    flock($fh, LOCK_EX);
    $last = (float) stream_get_contents($fh);
    $now = microtime(true);
    $waitUs = (int) round(($last + THOTH_GEO_MIN_INTERVAL_US / 1e6 - $now) * 1e6);
    if ($waitUs > 0) {
        $sleeper(min($waitUs, THOTH_GEO_MIN_INTERVAL_US));
    }
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, sprintf('%.6F', max($now, microtime(true))));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
}

/** @return array<mixed> gedecodeerd Nominatim-antwoord (met cache en throttle) */
function thoth_geo_request(string $path, array $query): array
{
    $query += ['format' => 'jsonv2', 'addressdetails' => 1, 'accept-language' => 'nl'];
    ksort($query);
    $url = THOTH_NOMINATIM_BASE . $path . '?' . http_build_query($query);
    $cacheFile = thoth_geo_cache_dir() . '/' . sha1($url) . '.json';
    if (is_file($cacheFile) && filemtime($cacheFile) > time() - THOTH_GEO_CACHE_TTL) {
        $cached = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }
    thoth_geo_throttle();
    $res = thoth_http('GET', $url, ['Accept: application/json', 'User-Agent: ' . THOTH_GEO_USER_AGENT, 'Referer: ' . THOTH_GEO_REFERER], null, null);
    if ($res['status'] !== 200) {
        throw new ThothActionException('Adreszoeker (OpenStreetMap) gaf HTTP ' . $res['status'] . '. Probeer het zo opnieuw.');
    }
    $json = json_decode($res['body'], true);
    if (!is_array($json)) {
        throw new ThothActionException('Adreszoeker (OpenStreetMap) gaf een onleesbaar antwoord.');
    }
    @file_put_contents($cacheFile, json_encode($json), LOCK_EX);

    return $json;
}

/**
 * Zet een Nominatim-resultaat om naar Thoth-adresvelden.
 *
 * @return array{label:string, lat:string, lon:string, adres:string, postcode:string, plaats:string, land:string}
 */
function thoth_geo_normalize(array $r): array
{
    $a = is_array($r['address'] ?? null) ? $r['address'] : [];
    $street = (string) ($a['road'] ?? $a['pedestrian'] ?? $a['footway'] ?? $a['path'] ?? $a['industrial'] ?? $a['hamlet'] ?? '');
    $house = (string) ($a['house_number'] ?? '');
    $city = (string) ($a['city'] ?? $a['town'] ?? $a['village'] ?? $a['municipality'] ?? $a['suburb'] ?? '');

    return [
        'label' => (string) ($r['display_name'] ?? ''),
        'lat' => thoth_geo_coord($r['lat'] ?? ''),
        'lon' => thoth_geo_coord($r['lon'] ?? ''),
        'adres' => trim($street . ' ' . $house),
        'postcode' => (string) ($a['postcode'] ?? ''),
        'plaats' => $city,
        'land' => strtoupper((string) ($a['country_code'] ?? '')),
    ];
}

/** Coördinaat als tekst met maximaal 6 decimalen (zoals in BC), '' als ongeldig. */
function thoth_geo_coord(mixed $v): string
{
    if (!is_numeric($v)) {
        return '';
    }
    $s = rtrim(rtrim(number_format((float) $v, 6, '.', ''), '0'), '.');

    return $s === '-0' ? '0' : $s;
}

/** @return list<array> */
function thoth_geo_search(string $q): array
{
    $q = trim(mb_substr($q, 0, 200));
    if (mb_strlen($q) < 3) {
        return [];
    }
    $rows = thoth_geo_request('/search', ['q' => $q, 'limit' => 6]);

    return array_values(array_map('thoth_geo_normalize', array_filter($rows, 'is_array')));
}

function thoth_geo_reverse(float $lat, float $lon): ?array
{
    if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
        throw new ThothActionException('Ongeldige coördinaten.');
    }
    $json = thoth_geo_request('/reverse', ['lat' => thoth_geo_coord($lat), 'lon' => thoth_geo_coord($lon), 'zoom' => 18]);
    if (isset($json['error'])) {
        return null;
    }
    $out = thoth_geo_normalize($json);
    // Het punt van de gebruiker blijft leidend, niet het middelpunt van het gevonden object.
    $out['lat'] = thoth_geo_coord($lat);
    $out['lon'] = thoth_geo_coord($lon);

    return $out;
}
