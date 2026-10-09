<?php

declare(strict_types=1);

/** Strikte keuzelijsten uit BC (Equipmentsoort, Producent, Model per producent) en Omschrijving 2 uit de modeltabel. */

require __DIR__ . '/_bootstrap.php';

$GLOBALS['mimirApi'] = 'mimir_test';
$tables = [
    'KVT_LVS_Sub_Entity' => [['Code' => 'GENERATOR', 'Description' => 'Generator'], ['Code' => 'PUMP', 'Description' => 'Pomp']],
    'KVT_Producenten' => [['Code' => 'INDUSTRIAL', 'Name' => 'Perkins'], ['Code' => 'CAT', 'Name' => 'Caterpillar']],
    'KVT_LVS_Manufacturer_Model' => [
        ['Manufacturer_Code' => 'INDUSTRIAL', 'Model_Code' => '404D-22T', 'Description' => 'Perkins 404D-22T industriële dieselmotor met een hele lange omschrijving erbij'],
        ['Manufacturer_Code' => 'CAT', 'Model_Code' => '404D-22T', 'Description' => 'Zelfde code, andere producent'],
        ['Manufacturer_Code' => 'CAT', 'Model_Code' => 'C18', 'Description' => ''],
        ['Manufacturer_Code' => '', 'Model_Code' => 'PRO200-4', 'Description' => 'PRO200-4'],
    ],
];
$queries = [];
// Mímir-mock: past eq-filters (and) en 'ne' toe zoals BC dat zou doen.
$GLOBALS['thothHttpClient'] = static function (string $method, string $url, array $headers, ?string $body) use ($tables, &$queries): array {
    $q = json_decode((string) $body, true);
    $queries[] = $q;
    $rows = $tables[$q['table']] ?? [];
    preg_match_all("/(\\w+) (eq|ne) '((?:[^']|'')*)'/", (string) ($q['filter'] ?? ''), $m, PREG_SET_ORDER);
    $rows = array_values(array_filter($rows, static function (array $r) use ($m): bool {
        foreach ($m as [, $col, $op, $v]) {
            $v = str_replace("''", "'", $v);
            if (($op === 'eq') !== ((string) ($r[$col] ?? '') === $v)) {
                return false;
            }
        }
        return true;
    }));
    return ['status' => 200, 'body' => json_encode(['value' => $rows, 'meta' => ['environment' => 'kvtmdlive_aad']])];
};

putenv('THOTH_CONFIG_DIR');
$config = thoth_load_config('component');
$f = thoth_config_fields_by_key($config);
$ctx = ['company' => 'Koninklijke van Twist', 'environment' => 'kvtmdlive_aad', 'moment' => 'opslaan'];

// Labels: code + omschrijving/naam
check_same(['GENERATOR · Generator', 'PUMP · Pomp'], array_column(thoth_search_options($f['Sub_Entity'], '', $ctx), 'label'), 'equipmentsoort: code · omschrijving');
check_same('CAT · Caterpillar', thoth_search_options($f['Manufacturer_Code'], 'caterp', $ctx)[0]['label'], 'producent: code · naam, zoeken op naam');
check(str_contains(json_encode($queries), "Code ne ''"), 'lege producent weggefilterd in de BC-query');

// Model per producent
$models = static fn (string $parent, string $q = '') => array_map(static fn ($r) => $r['waarde'] . '/' . $r['ouder'], thoth_search_options($f['Manufacturer_Model'], $q, $ctx, 20, $parent));
check_same(['404D-22T/CAT', 'C18/CAT'], $models('CAT'), 'alleen modellen van de gekozen producent');
check_same(['404D-22T/INDUSTRIAL'], $models('industrial'), 'producent hoofdletterongevoelig');
check_same(['404D-22T/INDUSTRIAL', '404D-22T/CAT', 'C18/CAT', 'PRO200-4/'], $models(''), 'zonder producent: alles, ook modellen zonder producent');
check_same('404D-22T · Perkins 404D-22T industriële dieselmotor met een hele lange omschrijving erbij · INDUSTRIAL', thoth_search_options($f['Manufacturer_Model'], '404', $ctx, 20, 'INDUSTRIAL')[0]['label'], 'model: code · omschrijving · producent');

// Server-side: gekozen waarden moeten bestaan (model bij die producent)
$GLOBALS['thothLookupChecker'] = null;
$base = ['Main_Entity' => 'X', 'Sub_Entity' => 'GENERATOR', 'Description' => 'Gen', 'Manufacturer_Code' => 'CAT', 'Manufacturer_Model' => 'C18', 'Serial_No' => '1'];
$check = static function (array $vals) use ($config, $ctx): array {
    $e = thoth_validate_values($config, $vals, ['moment' => 'indienen'] + $ctx);
    unset($e['Main_Entity']); // servicelocatie-mock leeg; niet het onderwerp hier
    return $e;
};
check_same([], $check($base), 'bestaande keuzes geldig');
check(isset($check(['Sub_Entity' => 'BESTAAT NIET'] + $base)['Sub_Entity']), 'onbekende equipmentsoort geweigerd');
check(isset($check(['Manufacturer_Code' => 'ACME'] + $base)['Manufacturer_Code']), 'onbekende producent geweigerd');
check(isset($check(['Manufacturer_Model' => 'C18', 'Manufacturer_Code' => 'INDUSTRIAL'] + $base)['Manufacturer_Model']), 'model van een andere producent geweigerd');
check_same([], $check(['Manufacturer_Model' => '404D-22T', 'Manufacturer_Code' => 'INDUSTRIAL'] + $base), 'dubbele modelcode: juiste producent geldig');
check(isset($check(['Manufacturer_Model' => 'PRO200-4'] + $base)['Manufacturer_Model']), 'model zonder producent niet bij een gekozen producent');
check(str_contains(json_encode(end($queries)), "Manufacturer_Code eq 'CAT'"), 'lookup filtert op gekozen producent');
check(isset($check(['Manufacturer_Code' => ''] + $base)['Manufacturer_Code']), 'producent blijft verplicht');

// Omschrijving 2 = Description van het model (samengestelde sleutel), afgekapt op 50, terugval op Model_Code
$GLOBALS['thothDerivedReader'] = null;
$ap = ['moment' => 'goedkeuren'] + $ctx;
[$out, $errs] = thoth_resolve_derived($config, ['Manufacturer_Code' => 'INDUSTRIAL', 'Manufacturer_Model' => '404D-22T', 'KVT_Latitude_Coordinate__x005B_DD_x005D_' => '1', 'KVT_Longitude_Coordinate__x005B_DD_x005D_' => '2'] + $base, $ap);
check_same(mb_substr('Perkins 404D-22T industriële dieselmotor met een hele lange omschrijving erbij', 0, 50), $out['Description_2'], 'omschrijving van het model van déze producent, max 50');
check_same(50, mb_strlen($out['Description_2']), 'afgekapt op 50 tekens');
check(!isset($errs['Description_2']), 'geen fout');
[$out] = thoth_resolve_derived($config, ['Manufacturer_Code' => 'CAT', 'Manufacturer_Model' => '404D-22T'] + $base, $ap);
check_same('Zelfde code, andere producent', $out['Description_2'], 'dubbele modelcode: omschrijving van de gekozen producent');
[$out] = thoth_resolve_derived($config, ['Manufacturer_Code' => 'CAT', 'Manufacturer_Model' => 'C18'] + $base, $ap);
check_same('C18', $out['Description_2'], 'lege omschrijving: terugval op Model_Code');
check(str_contains(json_encode($queries), "Model_Code eq 'C18' and Manufacturer_Code eq 'CAT'"), 'samengestelde sleutel in het filter');

finish('lookups');
