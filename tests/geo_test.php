<?php

declare(strict_types=1);

/** Kaartkiezer: Nominatim-proxy (headers, cache, throttle), kaartKiezer-config, coördinaatcontrole en overschrijven. */

require __DIR__ . '/_bootstrap.php';

$calls = [];
$GLOBALS['thothHttpClient'] = static function (string $method, string $url, array $headers) use (&$calls): array {
    $calls[] = ['url' => $url, 'headers' => $headers];
    if (str_contains($url, '/reverse')) {
        return ['status' => 200, 'body' => json_encode(['lat' => '52.28', 'lon' => '4.77', 'display_name' => 'Rijksweg 1, Badhoevedorp',
            'address' => ['road' => 'Rijksweg', 'house_number' => '1', 'postcode' => '1171 AA', 'town' => 'Badhoevedorp', 'country_code' => 'nl']])];
    }
    return ['status' => 200, 'body' => json_encode([
        ['lat' => '52.2838470123', 'lon' => '4.7718271', 'display_name' => 'Kanaaldijk 9, Hengelo', 'address' => ['road' => 'Kanaaldijk', 'house_number' => '9', 'postcode' => '7553 AA', 'city' => 'Hengelo', 'country_code' => 'nl']],
        ['lat' => '50.85', 'lon' => '4.35', 'display_name' => 'Brussel', 'address' => ['village' => 'Brussel', 'country_code' => 'be']],
    ])];
};
$sleeps = [];
$GLOBALS['thothGeoSleep'] = static function (int $us) use (&$sleeps): void { $sleeps[] = $us; };

$r = thoth_geo_search('Kanaaldijk 9 Hengelo');
check_same(1, count($calls), 'één Nominatim-call');
check(str_starts_with($calls[0]['url'], 'https://nominatim.openstreetmap.org/search?'), 'Nominatim search-URL');
check(str_contains($calls[0]['url'], 'q=Kanaaldijk+9+Hengelo') && str_contains($calls[0]['url'], 'format=jsonv2') && str_contains($calls[0]['url'], 'addressdetails=1'), 'query, jsonv2, addressdetails');
check(in_array('User-Agent: ' . THOTH_GEO_USER_AGENT, $calls[0]['headers'], true) && str_contains(THOTH_GEO_USER_AGENT, 'sleutels.kvt.nl'), 'nette User-Agent');
check(in_array('Referer: https://sleutels.kvt.nl/thoth/', $calls[0]['headers'], true), 'Referer meegestuurd');
check_same(['label' => 'Kanaaldijk 9, Hengelo', 'lat' => '52.283847', 'lon' => '4.771827', 'adres' => 'Kanaaldijk 9', 'postcode' => '7553 AA', 'plaats' => 'Hengelo', 'land' => 'NL'], $r[0], 'resultaat genormaliseerd (6 decimalen, land hoofdletters)');
check_same(['', 'Brussel', 'BE'], [$r[1]['adres'], $r[1]['plaats'], $r[1]['land']], 'dorp als plaats, leeg adres');

thoth_geo_search('Kanaaldijk 9 Hengelo');
check_same(1, count($calls), 'tweede keer uit de cache');
check_same([], thoth_geo_search('ab'), 'te korte zoekterm: geen call');
check_same(1, count($calls), 'nog steeds één call');

$a = thoth_geo_reverse(52.2838471, 4.7718272);
check_same(2, count($calls), 'reverse: nieuwe call');
check(str_contains($calls[1]['url'], '/reverse?') && str_contains($calls[1]['url'], 'lat=52.283847'), 'reverse-URL');
check_same(['52.283847', '4.771827', 'Rijksweg 1', '1171 AA', 'Badhoevedorp', 'NL'], [$a['lat'], $a['lon'], $a['adres'], $a['postcode'], $a['plaats'], $a['land']], 'reverse houdt het punt van de gebruiker, adresvelden uit OSM');
check(count($sleeps) === 1 && $sleeps[0] > 0 && $sleeps[0] <= 1000000, 'tweede call binnen 1 s wacht (max 1 req/s)');
check_throws(fn () => thoth_geo_reverse(91.0, 4.0), 'Ongeldige', 'ongeldige breedtegraad');

$GLOBALS['thothHttpClient'] = static fn () => ['status' => 429, 'body' => ''];
check_throws(fn () => thoth_geo_search('iets anders'), 'HTTP 429', 'foutstatus netjes gemeld');
check_same('0', thoth_geo_coord('-0.0000001'), 'geen -0');
check_same('', thoth_geo_coord('abc'), 'ongeldige coördinaat leeg');

// kaartKiezer-config
$LAT = 'Lat';
$base = ['auto-increment-field' => 'No', 'autoIncrementPrefix' => 'X', 'autoIncrementNumberPadding' => 5, 'autoIncrementPrefixYear' => false];
$fields = [
    ['name' => 'Servicelocatie', 'invoerType' => 'lookup', 'bc-tabel' => 'T', 'bc-kolom' => 'Main_Entity', 'verplicht' => true, 'optiesBron' => ['bc-tabel' => 'MEs', 'waarde-kolom' => 'No']],
    ['name' => 'Adres', 'invoerType' => 'tekst', 'bc-tabel' => 'T', 'bc-kolom' => 'Addr'],
    ['name' => 'Land', 'invoerType' => 'dropdown', 'bc-tabel' => 'T', 'bc-kolom' => 'Country', 'opties' => ['NL', 'BE']],
    ['name' => 'Breedtegraad', 'invoerType' => 'automatisch', 'bc-tabel' => 'T', 'bc-kolom' => 'Lat', 'verplicht' => true, 'overschrijfbaar' => true,
        'afgeleidVan' => ['veld' => 'Main_Entity', 'bc-tabel' => 'MEs', 'sleutel-kolom' => 'No', 'kolom' => 'Lat']],
    ['name' => 'Lengtegraad', 'invoerType' => 'tekst', 'bc-tabel' => 'T', 'bc-kolom' => 'Lon'],
];
$c = thoth_validate_config($base + ['kaartKiezer' => ['lat' => 'Lat', 'lon' => 'Lon', 'adres' => 'Addr', 'land' => 'Country'], 'formFields' => $fields]);
check_same(['lat' => 'Lat', 'lon' => 'Lon', 'adres' => 'Addr', 'land' => 'Country'], $c['kaartKiezer'], 'kaartKiezer genormaliseerd');
check($c['formFields'][3]['overschrijfbaar'], 'overschrijfbaar automatisch veld');
check_same(null, thoth_validate_config($base + ['formFields' => $fields])['kaartKiezer'], 'zonder kaartKiezer: null');
check_throws(fn () => thoth_validate_config($base + ['kaartKiezer' => ['lat' => 'Lat'], 'formFields' => $fields]), 'minimaal', 'lon ontbreekt');
check_throws(fn () => thoth_validate_config($base + ['kaartKiezer' => ['lat' => 'Lat', 'lon' => 'Nope'], 'formFields' => $fields]), 'verwijst niet', 'onbekend veld');
check_throws(fn () => thoth_validate_config($base + ['kaartKiezer' => ['lat' => 'Lat', 'lon' => 'Lon', 'straat' => 'Addr'], 'formFields' => $fields]), 'onbekende sleutel', 'onbekende rol');
$f2 = $fields;
unset($f2[3]['overschrijfbaar']);
check_throws(fn () => thoth_validate_config($base + ['kaartKiezer' => ['lat' => 'Lat', 'lon' => 'Lon'], 'formFields' => $f2]), 'niet "overschrijfbaar"', 'automatisch maar niet overschrijfbaar');

// Coördinaatcontrole en overschrijven
$GLOBALS['thothLookupChecker'] = static fn (array $field, string $value): bool => $value === 'ME1';
$ctx = ['company' => 'KVT', 'environment' => 'env', 'moment' => 'indienen'];
check_same([], thoth_validate_values($c, ['Main_Entity' => 'ME1', 'Lon' => '4.771827'], $ctx), 'lege overschrijfbare coördinaat mag bij indienen');
check(isset(thoth_validate_values($c, ['Main_Entity' => 'ME1', 'Lon' => '4,77'], $ctx)['Lon']), 'komma geweigerd');
check(isset(thoth_validate_values($c, ['Main_Entity' => 'ME1', 'Lon' => '181'], $ctx)['Lon']), 'lengtegraad buiten bereik');
check(isset(thoth_validate_values($c, ['Main_Entity' => 'ME1', 'Lat' => '95.1'], $ctx)['Lat']), 'breedtegraad buiten bereik, ook als overschreven automatisch veld');
check_same([], thoth_validate_values($c, ['Main_Entity' => 'ME1', 'Lat' => '-33.9', 'Lon' => '151.2'], $ctx), 'negatieve coördinaten ok');

$reads = 0;
$GLOBALS['thothDerivedReader'] = static function () use (&$reads): array { $reads++; return [['No' => 'ME1', 'Lat' => '52.1']]; };
[$out, $errs] = thoth_resolve_derived($c, ['Main_Entity' => 'ME1', 'Lat' => ' 51.5 '], ['moment' => 'goedkeuren'] + $ctx);
check_same(['51.5', 0, []], [$out['Lat'], $reads, $errs], 'gekozen coördinaat blijft, geen BC-lookup');
[$out] = thoth_resolve_derived($c, ['Main_Entity' => 'ME1', 'Lat' => ''], ['moment' => 'goedkeuren'] + $ctx);
check_same(['52.1', 1], [$out['Lat'], $reads], 'leeg: default uit de servicelocatie');

// Echte configs
putenv('THOTH_CONFIG_DIR');
$sl = thoth_load_config('servicelocatie');
check_same(['KVT_Address', 'KVT_Post_Code', 'KVT_City', 'KVT_Country_Region_Code'], [$sl['kaartKiezer']['adres'], $sl['kaartKiezer']['postcode'], $sl['kaartKiezer']['plaats'], $sl['kaartKiezer']['land']], 'servicelocatie: kaart vult adresvelden');
$co = thoth_load_config('component');
check_same(['lat' => 'KVT_Latitude_Coordinate__x005B_DD_x005D_', 'lon' => 'KVT_Longitude_Coordinate__x005B_DD_x005D_'], $co['kaartKiezer'], 'component: kaart alleen voor coördinaten');
check(thoth_config_fields_by_key($co)['KVT_Latitude_Coordinate__x005B_DD_x005D_']['overschrijfbaar'], 'component-coördinaten overschrijfbaar');

finish('geo');
