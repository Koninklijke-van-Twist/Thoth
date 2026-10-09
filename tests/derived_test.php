<?php

declare(strict_types=1);

/** Automatische velden (afgeleidVan), maxLengte en afhankelijke suggesties (component-config). */

require __DIR__ . '/_bootstrap.php';

$base = [
    'auto-increment-field' => 'No', 'autoIncrementPrefix' => 'COM10', 'autoIncrementNumberPadding' => 5, 'autoIncrementPrefixYear' => false,
];
$fields = [
    ['name' => 'Servicelocatie', 'invoerType' => 'lookup', 'bc-tabel' => 'Comp', 'bc-kolom' => 'Main_Entity', 'verplicht' => true,
        'optiesBron' => ['bc-tabel' => 'MEs', 'waarde-kolom' => 'No']],
    ['name' => 'Omschrijving', 'invoerType' => 'tekst', 'bc-tabel' => 'Comp', 'bc-kolom' => 'Description', 'verplicht' => true, 'maxLengte' => 10],
    ['name' => 'Producent', 'invoerType' => 'combobox', 'bc-tabel' => 'Comp', 'bc-kolom' => 'Manufacturer_Code', 'opties' => ['CAT', 'MTU']],
    ['name' => 'Model', 'invoerType' => 'combobox', 'bc-tabel' => 'Comp', 'bc-kolom' => 'Manufacturer_Model', 'maxLengte' => 50,
        'optiesBron' => ['bc-tabel' => 'Comp', 'waarde-kolom' => 'Manufacturer_Model', 'label-kolommen' => ['Manufacturer_Code'],
            'afhankelijkVan' => ['veld' => 'Manufacturer_Code', 'kolom' => 'Manufacturer_Code']]],
    ['name' => 'Omschrijving 2', 'invoerType' => 'automatisch', 'bc-tabel' => 'Comp', 'bc-kolom' => 'Description_2', 'maxLengte' => 5,
        'afgeleidVan' => ['veld' => 'Manufacturer_Model']],
    ['name' => 'Breedtegraad', 'invoerType' => 'automatisch', 'bc-tabel' => 'Comp', 'bc-kolom' => 'Lat', 'verplicht' => true,
        'afgeleidVan' => ['veld' => 'Main_Entity', 'bc-tabel' => 'MEs', 'sleutel-kolom' => 'No', 'kolom' => 'Lat']],
];
$c = thoth_validate_config($base + ['formFields' => $fields]);
check_same(10, $c['formFields'][1]['maxLengte'], 'maxLengte genormaliseerd');
check_same(['veld' => 'Manufacturer_Model', 'bc-tabel' => null, 'sleutel-kolom' => null, 'kolom' => null], $c['formFields'][4]['afgeleidVan'], 'kopie uit formulierveld');
check_same('MEs', $c['formFields'][5]['afgeleidVan']['bc-tabel'], 'afgeleid uit BC-tabel');
check_same(['veld' => 'Manufacturer_Code', 'kolom' => 'Manufacturer_Code'], $c['formFields'][3]['optiesBron']['afhankelijkVan'], 'afhankelijkVan');

$bad = static function (array $patchField, int $i, string $needle, string $msg) use ($base, $fields): void {
    $f = $fields;
    $f[$i] = $patchField + $f[$i];
    check_throws(fn () => thoth_validate_config($base + ['formFields' => $f]), $needle, $msg);
};
$bad(['maxLengte' => 0], 1, 'maxLengte', 'maxLengte 0 geweigerd');
$bad(['maxLengte' => '10'], 1, 'maxLengte', 'maxLengte als string geweigerd');
$bad(['afgeleidVan' => ['veld' => 'Main_Entity']], 1, 'alleen bij invoerType automatisch', 'afgeleidVan op tekstveld');
$bad(['afgeleidVan' => null], 5, 'afgeleidVan', 'automatisch zonder afgeleidVan');
$bad(['afgeleidVan' => ['veld' => 'Bestaat_Niet']], 4, 'geen ander veld', 'verwijzing naar onbekend veld');
$bad(['afgeleidVan' => ['veld' => 'Lat']], 4, 'niet automatisch', 'verwijzing naar automatisch veld');
$bad(['afgeleidVan' => ['veld' => 'Main_Entity', 'bc-tabel' => 'MEs']], 5, 'sleutel-kolom', 'onvolledige BC-bron');
$bad(['optiesBron' => ['bc-tabel' => 'Comp', 'waarde-kolom' => 'Manufacturer_Model', 'afhankelijkVan' => ['veld' => 'X']]], 3, 'afhankelijkVan', 'afhankelijkVan zonder kolom');

// Validatie: maxLengte, en automatische velden tellen pas mee bij goedkeuren.
$GLOBALS['thothLookupChecker'] = static fn (array $field, string $value): bool => $value === 'ME12600001';
$ctx = ['company' => 'KVT', 'environment' => 'env', 'moment' => 'indienen'];
$vals = ['Main_Entity' => 'ME12600001', 'Description' => 'Paneel', 'Manufacturer_Model' => '404D-22T'];
check_same([], thoth_validate_values($c, $vals, $ctx), 'indienen zonder automatische velden mag');
$long = thoth_validate_values($c, ['Description' => 'Elf tekens!'] + $vals, $ctx);
check(str_contains($long['Description'] ?? '', 'max 10'), 'te lange omschrijving geweigerd');
check_same(['Description' => 'Omschrijving is te lang (max 10 tekens).'], thoth_validate_values($c, ['Description' => 'éééééééééé!'] + $vals, $ctx), 'maxLengte telt tekens (mb)');
$approveCtx = ['moment' => 'goedkeuren'] + $ctx;
check(isset(thoth_validate_values($c, $vals, $approveCtx)['Lat']), 'bij goedkeuren is verplicht automatisch veld nodig');

// thoth_resolve_derived: vers uit BC (lezer is te vervangen), kopie, afkappen, fouten.
$reads = [];
$GLOBALS['thothDerivedReader'] = static function (array $src, string $filter) use (&$reads): array {
    $reads[] = $src['bc-tabel'] . ': ' . $filter;
    return [
        ['No' => 'ME12600001', 'Lat' => ' 52.283847 '],
        ['No' => 'ME12600002', 'Lat' => ' '],
    ];
};
[$out, $errs] = thoth_resolve_derived($c, $vals + ['Lat' => 'gemanipuleerd', 'Description_2' => 'x'], $approveCtx);
check_same([], $errs, 'geen fouten bij gevonden servicelocatie');
check_same('52.283847', $out['Lat'], 'breedtegraad overgenomen en getrimd');
check_same('404D-', $out['Description_2'], 'kopie van model, afgekapt op maxLengte');
check_same(["MEs: No eq 'ME12600001'"], $reads, 'gericht filter op sleutel-kolom');
check_same([], thoth_validate_values($c, $out, $approveCtx), 'na afleiden geldig');
$payload = thoth_build_bc_payload($c, $out, $approveCtx);
check_same('52.283847', $payload['Lat'], 'afgeleide waarde in BC-payload');

[$out, $errs] = thoth_resolve_derived($c, ['Main_Entity' => 'ME12600002'] + $vals, $approveCtx);
check(str_contains($errs['Lat'] ?? '', 'heeft geen Lat'), 'servicelocatie zonder coördinaten: duidelijke fout');
[$out, $errs] = thoth_resolve_derived($c, ['Main_Entity' => "ME'9"] + $vals, $approveCtx);
check(str_contains($errs['Lat'] ?? '', 'niet gevonden'), 'onbekende servicelocatie: fout');
check(str_contains(end($reads), "'ME''9'"), 'OData-quote in filter');
[$out, $errs] = thoth_resolve_derived($c, ['Manufacturer_Model' => ''] + $vals, $approveCtx);
check_same('', $out['Description_2'], 'lege bron geeft lege kopie');
check(!isset($errs['Description_2']), 'optioneel automatisch veld leeg is geen fout');

// Afhankelijke suggesties: dubbele waarden één keer, filter op gekozen producent.
$GLOBALS['thothOptionReader'] = static fn (): array => [
    ['Manufacturer_Model' => '404D-22T', 'Manufacturer_Code' => 'INDUSTRIAL'],
    ['Manufacturer_Model' => '404D-22T', 'Manufacturer_Code' => 'INDUSTRIAL'],
    ['Manufacturer_Model' => '404d-22t', 'Manufacturer_Code' => 'CAT'],
    ['Manufacturer_Model' => 'C18', 'Manufacturer_Code' => 'CAT'],
    ['Manufacturer_Model' => '', 'Manufacturer_Code' => 'CAT'],
];
$model = $c['formFields'][3];
check_same(3, count(thoth_field_options($model, $ctx)), 'ontdubbeld per model+producent, leeg overgeslagen');
check_same(['C18'], array_column(thoth_search_options($model, 'c18', $ctx, 20, 'cat'), 'waarde'), 'zoeken binnen producent');
check_same(['404d-22t', 'C18'], array_column(thoth_search_options($model, '', $ctx, 20, 'CAT'), 'waarde'), 'filter op producent (hoofdletterongevoelig)');
check_same(2, count(thoth_search_options($model, '404', $ctx, 20, '')), 'zonder producent: alle modellen');
check_same('404D-22T · INDUSTRIAL', thoth_search_options($model, '404', $ctx, 20, 'INDUSTRIAL')[0]['label'], 'label toont producent');

// De echte component-config.
putenv('THOTH_CONFIG_DIR');
$real = thoth_load_config('component');
$byKey = thoth_config_fields_by_key($real);
check($real['bcGeblokkeerd'] !== null, 'component blijft geblokkeerd');
check_same(['Main_Entity', 'Sub_Entity', 'Description', 'Manufacturer_Code', 'Manufacturer_Model', 'Description_2', 'Serial_No',
    'KVT_Latitude_Coordinate__x005B_DD_x005D_', 'KVT_Longitude_Coordinate__x005B_DD_x005D_', 'KVT_Place_On_Location'], array_keys($byKey), 'velden volgens Tims specificatie');
check($byKey['Main_Entity']['verplicht'] && $byKey['Main_Entity']['invoerType'] === 'lookup', 'Servicelocatie verplicht en strikt');
foreach (['Description' => 100, 'Description_2' => 50, 'Serial_No' => 50, 'KVT_Place_On_Location' => 50, 'Manufacturer_Code' => 10, 'Manufacturer_Model' => 50] as $k => $len) {
    check_same($len, $byKey[$k]['maxLengte'], "maxLengte $k = BC-lengte");
}
check_same('LVS_MainEntityCard', $byKey['KVT_Latitude_Coordinate__x005B_DD_x005D_']['afgeleidVan']['bc-tabel'], 'breedtegraad uit servicelocatie');
check_same('KVT_Longitude_Coordinate__x005B_DD_x005D_', $byKey['KVT_Longitude_Coordinate__x005B_DD_x005D_']['afgeleidVan']['kolom'], 'lengtegraad uit servicelocatie');
check_same('Manufacturer_Code', $byKey['Manufacturer_Model']['optiesBron']['afhankelijkVan']['veld'], 'model gefilterd op producent');
check(!$byKey['KVT_Place_On_Location']['verplicht'] && !$byKey['Description_2']['verplicht'], 'optionele velden');

finish('derived');
