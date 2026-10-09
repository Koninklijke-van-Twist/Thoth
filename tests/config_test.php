<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$c = sample_config();
check_same('Locs', $c['bc-tabel'], 'hoofdtabel uit de velden');
check_same('lookup', $c['formFields'][3]['invoerType'], 'lookup blijft lookup');
check_same(['No', 'Name', 'City'], $c['formFields'][3]['optiesBron']['zoek-kolommen'], 'zoek-kolommen');
check_same('Open', $c['formFields'][2]['opties'][0]['waarde'], 'opties genormaliseerd');
check_same('Gesloten', $c['formFields'][2]['opties'][1]['label'], 'string-optie krijgt label');

$base = [
    'auto-increment-field' => 'No', 'autoIncrementPrefix' => 'SL1', 'autoIncrementNumberPadding' => 5, 'autoIncrementPrefixYear' => true,
    'formFields' => [['name' => 'A', 'invoerType' => 'tekst', 'bc-tabel' => 'T1', 'bc-kolom' => 'A']],
];
$bad = static function (array $patch, string $needle, string $msg) use ($base): void {
    check_throws(fn () => thoth_validate_config(array_replace($base, $patch)), $needle, $msg);
};

$bad(['formFields' => [['name' => 'A', 'invoerType' => 'tekst', 'bc-tabel' => 'T1', 'bc-kolom' => 'A'], ['name' => 'B', 'invoerType' => 'tekst', 'bc-tabel' => 'T2', 'bc-kolom' => 'B']]], 'Meerdere bc-tabellen', 'meerdere tabellen geweigerd');
$bad(['autoIncrementNumberPadding' => 0], 'autoIncrementNumberPadding', 'padding 0');
$bad(['autoIncrementNumberPadding' => '5'], 'autoIncrementNumberPadding', 'padding als string');
$bad(['autoIncrementPrefixYear' => 'ja'], 'autoIncrementPrefixYear', 'jaar geen bool');
$bad(['auto-increment-field' => ''], 'auto-increment-field', 'geen nummerveld');
$bad(['autoIncrementStrategie' => 'random'], 'autoIncrementStrategie', 'onbekende strategie');
$bad(['formFields' => []], 'formFields', 'geen velden');
$bad(['formFields' => [['name' => 'A', 'invoerType' => 'kleur', 'bc-tabel' => 'T1', 'bc-kolom' => 'A']]], 'invoerType', 'onbekend invoerType');
$bad(['formFields' => [['name' => 'A', 'invoerType' => 'dropdown', 'bc-tabel' => 'T1', 'bc-kolom' => 'A']]], 'opties', 'dropdown zonder opties');
$bad(['formFields' => [['name' => 'A', 'invoerType' => 'lookup', 'bc-tabel' => 'T1', 'bc-kolom' => 'A', 'opties' => ['x']]]], 'optiesBron', 'lookup zonder optiesBron');
$bad(['formFields' => [['name' => 'A', 'invoerType' => 'tekst', 'bc-tabel' => 'T1', 'bc-kolom' => 'A'], ['name' => 'B', 'invoerType' => 'tekst', 'bc-tabel' => 'T1', 'bc-kolom' => 'A']]], 'dubbel', 'dubbele kolom');
$bad(['formFields' => [['name' => 'A', 'invoerType' => 'tekst', 'bc-tabel' => 'T1', 'bc-kolom' => 'No']]], 'auto-increment-field', 'nummerveld op formulier');
$bad(['formFields' => [['name' => 'A', 'invoerType' => 'tekst', 'bc-tabel' => "T1'; drop", 'bc-kolom' => 'A']]], 'bc-tabel', 'ongeldige tabelnaam');
$bad(['formFields' => [['name' => 'A', 'invoerType' => 'tekst', 'bc-tabel' => 'T1', 'bc-kolom' => 'A', 'restricties' => [['regel' => 'maxLengte']]]]], 'onbekende', 'onbekende restrictie');
$bad(['formFields' => [['name' => 'A', 'invoerType' => 'tekst', 'bc-tabel' => 'T1', 'bc-kolom' => 'A', 'verplicht' => 'ja']]], 'verplicht', 'verplicht geen bool');

// combobox + strikt = lookup
$strict = thoth_validate_config(array_replace($base, ['formFields' => [['name' => 'A', 'invoerType' => 'combobox', 'strikt' => true, 'bc-tabel' => 'T1', 'bc-kolom' => 'A',
    'optiesBron' => ['bc-tabel' => 'X', 'waarde-kolom' => 'No', 'label-kolom' => 'Name']]]]));
check_same('lookup', $strict['formFields'][0]['invoerType'], 'combobox strikt wordt lookup');
check_same(['Name'], $strict['formFields'][0]['optiesBron']['label-kolommen'], 'label-kolom als enkelvoud');
check_same(['No', 'Name'], $strict['formFields'][0]['optiesBron']['zoek-kolommen'], 'zoek-kolommen standaard waarde + labels');

// De meegeleverde (echte) configs moeten geldig zijn.
putenv('THOTH_CONFIG_DIR');
foreach (array_keys(THOTH_TYPES) as $type) {
    $cfg = thoth_load_config($type);
    check($cfg['formFields'] !== [], "voorbeeldconfig $type laadt");
}
$component = thoth_config_fields_by_key(thoth_load_config('component'));
check_same('lookup', $component['Main_Entity']['invoerType'] ?? null, 'Component hangt via strikte lookup aan een servicelocatie');
check_same('LVS_MainEntityCard', $component['Main_Entity']['optiesBron']['bc-tabel'] ?? null, 'lookup op de echte servicelocatie-webservice');
$slCfg = thoth_load_config('servicelocatie');
$coCfg = thoth_load_config('component');
check_same(['LVS_MainEntityCard', 'ME12600008', false], [$slCfg['bc-tabel'], thoth_format_number($slCfg, 2026, 8), $slCfg['voorbeeld']], 'servicelocatie: echte webservice, ME1-nummer, geen voorbeeld');
check_same(['AppComponentCard', 'COM1002172'], [$coCfg['bc-tabel'], thoth_format_number($coCfg, 2026, 2172)], 'component: echte webservice, COM10-nummer');
check($coCfg['bcGeblokkeerd'] !== null && str_contains($coCfg['bcGeblokkeerd'], 'Main_Entity'), 'component geblokkeerd tot Main_Entity bewerkbaar is');
check($slCfg['bcGeblokkeerd'] === null, 'servicelocatie niet geblokkeerd');

// Restrictie-hook: een regel uit het register werkt per veld.
$GLOBALS['thothFieldRules'] = ['maxLengte' => static fn ($v, $f, $ctx, $rule) => mb_strlen((string) $v) > (int) $rule['waarde'] ? 'is te lang.' : null];
$withRule = thoth_validate_config(array_replace($base, ['formFields' => [['name' => 'A', 'invoerType' => 'tekst', 'bc-tabel' => 'T1', 'bc-kolom' => 'A', 'restricties' => [['regel' => 'maxLengte', 'waarde' => 3]]]]]));
check_same(['A' => 'A is te lang.'], thoth_validate_values($withRule, ['A' => 'abcd'], []), 'restrictie uit register wordt toegepast');
check_same([], thoth_validate_values($withRule, ['A' => 'abc'], []), 'restrictie ok');
unset($GLOBALS['thothFieldRules']);

finish('config');
