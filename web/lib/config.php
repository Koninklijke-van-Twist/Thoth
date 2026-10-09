<?php

declare(strict_types=1);

/**
 * Formulierconfig laden en valideren (web/config/<type>-config.json).
 *
 * Tims formaat plus twee optionele uitbreidingen:
 *  - "opties": vaste lijst voor dropdown/combobox (strings of {"waarde","label","aliassen"}).
 *  - "optiesBron": opties of zoekresultaten uit BC via Mímir:
 *      {"bc-tabel", "waarde-kolom", "label-kolom" | "label-kolommen", "zoek-kolommen", "filter"}.
 *  - invoerType "lookup" (of "combobox" met "strikt": true): strikte keuze uit BC,
 *    alleen een bestaande waarde mag worden opgeslagen en ingezonden.
 *  - "restricties": per veld, regels uit het register in validation.php (v2).
 *  - "bcGeblokkeerd": "<melding>" op het hoogste niveau: aanmaken in BC kan (nog) niet,
 *    bv. omdat een veld in BC niet bewerkbaar is. Indienen kan wel; goedkeuren faalt
 *    dan vóór elke BC-call met deze melding en het verzoek blijft Ingediend.
 */

const THOTH_TYPES = [
    'servicelocatie' => ['label' => 'Servicelocatie', 'file' => 'servicelocatie-config.json'],
    'component' => ['label' => 'Component', 'file' => 'component-config.json'],
];

const THOTH_INPUT_TYPES = ['tekst', 'nummer', 'dropdown', 'combobox', 'lookup', 'date', 'time', 'datetime'];

/**
 * Standaard nummerstrategie. Eén regel om te zetten:
 *   'eerste-vrije' = laagste ongebruikte volgnummer vanaf 1 in het jaar (huidige keuze)
 *   'max+1'        = hoogste bestaande volgnummer + 1
 * Per config te overschrijven met "autoIncrementStrategie".
 */
const THOTH_NUMBER_STRATEGY_DEFAULT = 'eerste-vrije';

final class ThothConfigException extends RuntimeException
{
}

function thoth_config_dir(): string
{
    $override = getenv('THOTH_CONFIG_DIR');
    if (is_string($override) && trim($override) !== '') {
        return rtrim(trim($override), '/');
    }

    return dirname(__DIR__) . '/config';
}

function thoth_type_exists(string $type): bool
{
    return isset(THOTH_TYPES[$type]);
}

function thoth_type_label(string $type): string
{
    return THOTH_TYPES[$type]['label'] ?? $type;
}

/**
 * Laadt en valideert de config van een type. Gooit ThothConfigException met alle fouten.
 */
function thoth_load_config(string $type): array
{
    static $memo = [];
    if (!thoth_type_exists($type)) {
        throw new ThothConfigException('Onbekend aanvraagtype: ' . $type);
    }
    $path = thoth_config_dir() . '/' . THOTH_TYPES[$type]['file'];
    $key = $path . '|' . (is_file($path) ? (string) filemtime($path) : '');
    if (isset($memo[$key])) {
        return $memo[$key];
    }
    if (!is_file($path)) {
        throw new ThothConfigException('Config ontbreekt: ' . THOTH_TYPES[$type]['file']);
    }
    $raw = json_decode((string) file_get_contents($path), true);
    if (!is_array($raw)) {
        throw new ThothConfigException(THOTH_TYPES[$type]['file'] . ' is geen geldige JSON: ' . json_last_error_msg());
    }

    return $memo[$key] = thoth_validate_config($raw, THOTH_TYPES[$type]['file']);
}

/**
 * Valideert een config-array en geeft een genormaliseerde versie terug.
 * Velden krijgen een 'key' (de bc-kolom) en een genormaliseerd invoerType.
 */
function thoth_validate_config(array $raw, string $source = 'config'): array
{
    $errors = [];
    $field = static fn (string $name) => $raw[$name] ?? null;

    $autoField = $field('auto-increment-field');
    if (!is_string($autoField) || trim($autoField) === '') {
        $errors[] = '"auto-increment-field" ontbreekt of is leeg.';
    }
    $prefix = $field('autoIncrementPrefix');
    if (!is_string($prefix) || !preg_match('/^[A-Za-z0-9_-]{0,20}$/', $prefix)) {
        $errors[] = '"autoIncrementPrefix" moet een tekst zijn (letters, cijfers, - of _, max 20).';
    }
    $padding = $field('autoIncrementNumberPadding');
    if (!is_int($padding) || $padding < 1 || $padding > 12) {
        $errors[] = '"autoIncrementNumberPadding" moet een geheel getal van 1 t/m 12 zijn.';
    }
    $year = $field('autoIncrementPrefixYear');
    if (!is_bool($year)) {
        $errors[] = '"autoIncrementPrefixYear" moet true of false zijn.';
    }
    $strategy = $raw['autoIncrementStrategie'] ?? THOTH_NUMBER_STRATEGY_DEFAULT;
    if (!in_array($strategy, ['eerste-vrije', 'max+1'], true)) {
        $errors[] = '"autoIncrementStrategie" moet "eerste-vrije" of "max+1" zijn.';
    }

    $blocked = $raw['bcGeblokkeerd'] ?? null;
    if ($blocked !== null && (!is_string($blocked) || trim($blocked) === '')) {
        $errors[] = '"bcGeblokkeerd" moet een niet-lege melding zijn (of weglaten).';
        $blocked = null;
    }

    $fields = $field('formFields');
    $normalized = [];
    $tables = [];
    if (!is_array($fields) || $fields === [] || !array_is_list($fields)) {
        $errors[] = '"formFields" moet een niet-lege lijst zijn.';
        $fields = [];
    }
    $seenKeys = [];
    foreach ($fields as $i => $f) {
        $label = 'formFields[' . $i . ']';
        if (!is_array($f)) {
            $errors[] = $label . ' is geen object.';
            continue;
        }
        $name = $f['name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            $errors[] = $label . ': "name" ontbreekt.';
        } else {
            $label .= ' (' . $name . ')';
        }
        $type = $f['invoerType'] ?? null;
        if (!is_string($type) || !in_array($type, THOTH_INPUT_TYPES, true)) {
            $errors[] = $label . ': "invoerType" moet een van ' . implode(', ', THOTH_INPUT_TYPES) . ' zijn.';
            $type = 'tekst';
        }
        $strict = ($f['strikt'] ?? false) === true;
        if ($type === 'combobox' && $strict) {
            $type = 'lookup';
        }
        $table = $f['bc-tabel'] ?? null;
        $column = $f['bc-kolom'] ?? null;
        if (!is_string($table) || !thoth_is_identifier($table)) {
            $errors[] = $label . ': "bc-tabel" ontbreekt of bevat ongeldige tekens.';
        } else {
            $tables[$table] = true;
        }
        if (!is_string($column) || !thoth_is_identifier($column)) {
            $errors[] = $label . ': "bc-kolom" ontbreekt of bevat ongeldige tekens.';
            $column = '__ongeldig_' . $i;
        }
        if (isset($seenKeys[$column])) {
            $errors[] = $label . ': "bc-kolom" ' . $column . ' komt dubbel voor.';
        }
        $seenKeys[$column] = true;
        if (is_string($autoField) && $column === $autoField) {
            $errors[] = $label . ': het auto-increment-field ' . $autoField . ' wordt door Thoth gevuld en hoort niet op het formulier.';
        }
        if (array_key_exists('verplicht', $f) && !is_bool($f['verplicht'])) {
            $errors[] = $label . ': "verplicht" moet true of false zijn.';
        }

        $options = null;
        if (array_key_exists('opties', $f)) {
            $options = thoth_normalize_options($f['opties'], $label, $errors);
        }
        $optSource = null;
        if (array_key_exists('optiesBron', $f)) {
            $optSource = thoth_normalize_option_source($f['optiesBron'], $label, $type, $errors);
        }
        if (in_array($type, ['dropdown', 'combobox'], true) && $options === null && $optSource === null) {
            $errors[] = $label . ': ' . $type . ' heeft "opties" of "optiesBron" nodig.';
        }
        if ($type === 'lookup' && $optSource === null) {
            $errors[] = $label . ': lookup (strikte combobox) heeft "optiesBron" nodig.';
        }
        if ($options !== null && $optSource !== null) {
            $errors[] = $label . ': gebruik "opties" of "optiesBron", niet allebei.';
        }

        $rules = $f['restricties'] ?? [];
        if (!is_array($rules) || !array_is_list($rules)) {
            $errors[] = $label . ': "restricties" moet een lijst zijn.';
            $rules = [];
        }
        foreach ($rules as $j => $rule) {
            $ruleName = is_array($rule) ? ($rule['regel'] ?? null) : null;
            if (!is_string($ruleName) || !array_key_exists($ruleName, thoth_field_rule_registry())) {
                $errors[] = $label . ': restrictie ' . $j . ' heeft een onbekende "regel" (nog geen regels in v1).';
            }
        }

        $normalized[] = [
            'key' => (string) $column,
            'name' => is_string($name) ? trim($name) : '',
            'placeholder' => is_string($f['placeholder'] ?? null) ? $f['placeholder'] : '',
            'invoerType' => $type,
            'bc-tabel' => is_string($table) ? $table : '',
            'bc-kolom' => (string) $column,
            'verplicht' => ($f['verplicht'] ?? false) === true,
            'opties' => $options,
            'optiesBron' => $optSource,
            'restricties' => $rules,
        ];
    }

    if (count($tables) > 1) {
        $errors[] = 'Meerdere bc-tabellen in één formulier (' . implode(', ', array_keys($tables))
            . ') worden in v1 niet ondersteund. Alle velden moeten dezelfde "bc-tabel" hebben.';
    }

    if ($errors !== []) {
        throw new ThothConfigException($source . ' is ongeldig:' . "\n- " . implode("\n- ", $errors));
    }

    return [
        'auto-increment-field' => $autoField,
        'autoIncrementPrefix' => $prefix,
        'autoIncrementNumberPadding' => $padding,
        'autoIncrementPrefixYear' => $year,
        'autoIncrementStrategie' => $strategy,
        'bc-tabel' => (string) array_key_first($tables),
        'voorbeeld' => ($raw['_voorbeeld'] ?? null) !== null,
        'voorbeeldNotitie' => is_string($raw['_voorbeeld'] ?? null) ? $raw['_voorbeeld'] : '',
        'bcGeblokkeerd' => is_string($blocked) ? trim($blocked) : null,
        'formFields' => $normalized,
    ];
}

/** OData-webservice- of kolomnaam: letters, cijfers en _ (BC-namen als Service_Item_No). */
function thoth_is_identifier(string $value): bool
{
    return preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,99}$/', $value) === 1;
}

/** @return list<array{waarde:string,label:string,aliassen:list<string>}>|null */
function thoth_normalize_options(mixed $opties, string $label, array &$errors): ?array
{
    if (!is_array($opties) || $opties === [] || !array_is_list($opties)) {
        $errors[] = $label . ': "opties" moet een niet-lege lijst zijn.';
        return null;
    }
    $out = [];
    foreach ($opties as $k => $opt) {
        if (is_string($opt) || is_int($opt)) {
            $out[] = ['waarde' => (string) $opt, 'label' => (string) $opt, 'aliassen' => []];
            continue;
        }
        if (is_array($opt) && isset($opt['waarde']) && (is_string($opt['waarde']) || is_int($opt['waarde']))) {
            $aliases = array_values(array_filter((array) ($opt['aliassen'] ?? []), 'is_string'));
            $out[] = [
                'waarde' => (string) $opt['waarde'],
                'label' => is_string($opt['label'] ?? null) ? $opt['label'] : (string) $opt['waarde'],
                'aliassen' => $aliases,
            ];
            continue;
        }
        $errors[] = $label . ': optie ' . $k . ' moet een tekst of {"waarde","label"} zijn.';
    }

    return $out;
}

function thoth_normalize_option_source(mixed $src, string $label, string $type, array &$errors): ?array
{
    if (!is_array($src)) {
        $errors[] = $label . ': "optiesBron" moet een object zijn.';
        return null;
    }
    $table = $src['bc-tabel'] ?? null;
    $valueCol = $src['waarde-kolom'] ?? null;
    $ok = true;
    if (!is_string($table) || !thoth_is_identifier($table)) {
        $errors[] = $label . ': optiesBron."bc-tabel" ontbreekt of is ongeldig.';
        $ok = false;
    }
    if (!is_string($valueCol) || !thoth_is_identifier($valueCol)) {
        $errors[] = $label . ': optiesBron."waarde-kolom" ontbreekt of is ongeldig.';
        $ok = false;
    }
    $labelCols = $src['label-kolommen'] ?? (isset($src['label-kolom']) ? [$src['label-kolom']] : []);
    $searchCols = $src['zoek-kolommen'] ?? [];
    foreach (['label-kolommen' => &$labelCols, 'zoek-kolommen' => &$searchCols] as $name => &$cols) {
        if (!is_array($cols) || !array_is_list($cols)) {
            $errors[] = $label . ': optiesBron."' . $name . '" moet een lijst zijn.';
            $cols = [];
            $ok = false;
            continue;
        }
        foreach ($cols as $c) {
            if (!is_string($c) || !thoth_is_identifier($c)) {
                $errors[] = $label . ': optiesBron."' . $name . '" bevat een ongeldige kolom.';
                $ok = false;
            }
        }
    }
    unset($cols);
    $filter = $src['filter'] ?? '';
    if (!is_string($filter)) {
        $errors[] = $label . ': optiesBron."filter" moet een OData-filtertekst zijn.';
        $ok = false;
    }
    if (!$ok) {
        return null;
    }
    if ($searchCols === []) {
        $searchCols = array_values(array_unique(array_merge([$valueCol], $labelCols)));
    }

    return [
        'bc-tabel' => $table,
        'waarde-kolom' => $valueCol,
        'label-kolommen' => array_values($labelCols),
        'zoek-kolommen' => array_values($searchCols),
        'filter' => (string) $filter,
    ];
}

/** @return array<string, array> velden op key */
function thoth_config_fields_by_key(array $config): array
{
    $out = [];
    foreach ($config['formFields'] as $f) {
        $out[$f['key']] = $f;
    }

    return $out;
}
