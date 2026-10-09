<?php

declare(strict_types=1);

/**
 * Validatie in drie lagen, zodat Tim later regels kan toevoegen:
 *  1. verplicht: elk verplicht veld moet gevuld zijn (inzendvoorwaarde).
 *  2. invoerType-regels: thoth_input_type_rules()[type] (formaat, keuze uit lijst, lookup bestaat).
 *  3. veldregels (v2): "restricties": [{"regel": "<naam>", ...}] in de config,
 *     uitgevoerd via thoth_field_rule_registry(). In v1 nog leeg.
 *
 * Een regel is fn(mixed $value, array $field, array $context, array $ruleConfig): ?string
 * en geeft null (ok) of een Nederlandse foutmelding terug.
 * $context bevat 'company', 'environment', 'labels', 'moment' (opslaan|indienen|goedkeuren).
 */

/** Hook voor v2: regels per veld. Voeg hier bv. 'maxLengte' => fn(...) toe. */
function thoth_field_rule_registry(): array
{
    $extra = $GLOBALS['thothFieldRules'] ?? [];

    return is_array($extra) ? $extra : [];
}

function thoth_input_type_rules(): array
{
    return [
        'nummer' => [static function ($v): ?string {
            return is_numeric(str_replace(',', '.', (string) $v)) ? null : 'moet een getal zijn.';
        }],
        'date' => [static function ($v): ?string {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $v);
            return $d && $d->format('Y-m-d') === $v ? null : 'moet een geldige datum zijn (jjjj-mm-dd).';
        }],
        'time' => [static function ($v): ?string {
            return preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', (string) $v) ? null : 'moet een geldige tijd zijn (uu:mm).';
        }],
        'datetime' => [static function ($v): ?string {
            return preg_match('/^\d{4}-\d{2}-\d{2}T([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', (string) $v) ? null : 'moet een geldige datum en tijd zijn.';
        }],
        'dropdown' => [static function ($v, array $field, array $ctx): ?string {
            return thoth_option_exists($field, (string) $v, $ctx) ? null : 'is geen geldige keuze.';
        }],
        'lookup' => [static function ($v, array $field, array $ctx): ?string {
            return thoth_lookup_exists($field, (string) $v, $ctx) ? null : 'kies een bestaande waarde uit de lijst.';
        }],
        'tekst' => [static function ($v): ?string {
            return mb_strlen((string) $v) <= 250 ? null : 'is te lang (max 250 tekens).';
        }],
        'combobox' => [static function ($v): ?string {
            return mb_strlen((string) $v) <= 250 ? null : 'is te lang (max 250 tekens).';
        }],
    ];
}

/** Breedte- of lengtegraad in decimale graden met een punt, binnen het bereik. */
function thoth_coordinate_error(string $value, string $role): ?string
{
    $max = $role === 'lat' ? 90 : 180;
    if (!preg_match('/^-?\d{1,3}(\.\d{1,12})?$/', trim($value)) || abs((float) $value) > $max) {
        return 'moet een ' . ($role === 'lat' ? 'breedtegraad' : 'lengtegraad') . ' in decimale graden zijn (bv. '
            . ($role === 'lat' ? '52.283847' : '4.771827') . ', punt als decimaalteken, max ' . $max . ').';
    }

    return null;
}

function thoth_value_is_empty(mixed $value): bool
{
    return $value === null || (is_string($value) && trim($value) === '');
}

/**
 * Valideert alle velden. Geeft [key => melding] terug; leeg = mag inzenden.
 *
 * @param array<string, mixed> $values
 */
function thoth_validate_values(array $config, array $values, array $context): array
{
    $errors = [];
    $typeRules = thoth_input_type_rules();
    $registry = thoth_field_rule_registry();
    foreach ($config['formFields'] as $field) {
        $key = $field['key'];
        $value = $values[$key] ?? null;
        if ($field['invoerType'] === 'automatisch' && ($context['moment'] ?? '') !== 'goedkeuren'
            && (!($field['overschrijfbaar'] ?? false) || thoth_value_is_empty($value))) {
            continue; // wordt pas bij goedkeuren bepaald (thoth_resolve_derived)
        }
        if (thoth_value_is_empty($value)) {
            if ($field['verplicht']) {
                $errors[$key] = $field['name'] . ' is verplicht.';
            }
            continue;
        }
        $value = is_string($value) ? trim($value) : $value;
        if (($field['maxLengte'] ?? null) !== null && mb_strlen((string) $value) > $field['maxLengte']) {
            $errors[$key] = $field['name'] . ' is te lang (max ' . $field['maxLengte'] . ' tekens).';
            continue;
        }
        $coordRole = array_search($key, array_intersect_key($config['kaartKiezer'] ?? [], ['lat' => 1, 'lon' => 1]), true);
        if ($coordRole !== false && ($msg = thoth_coordinate_error((string) $value, $coordRole)) !== null) {
            $errors[$key] = $field['name'] . ' ' . $msg;
            continue;
        }
        foreach ($typeRules[$field['invoerType']] ?? [] as $rule) {
            $msg = $rule($value, $field, $context, []);
            if ($msg !== null) {
                $errors[$key] = $field['name'] . ' ' . $msg;
                continue 2;
            }
        }
        foreach ($field['restricties'] as $ruleConfig) {
            $rule = $registry[$ruleConfig['regel'] ?? ''] ?? null;
            if (is_callable($rule)) {
                $msg = $rule($value, $field, $context, $ruleConfig);
                if ($msg !== null) {
                    $errors[$key] = $field['name'] . ' ' . $msg;
                    continue 2;
                }
            }
        }
    }

    return $errors;
}

/** Vergelijkt hoofdletterongevoelig; Mímir geeft NL-captions, directe OData EN. */
function thoth_same_value(string $a, string $b): bool
{
    return mb_strtolower(trim($a)) === mb_strtolower(trim($b));
}

/**
 * Opties voor dropdown/combobox: vaste lijst of uit BC (Mímir, 24 uur cache).
 *
 * @return list<array{waarde:string,label:string,aliassen:list<string>}>
 */
function thoth_field_options(array $field, array $context): array
{
    if (is_array($field['opties'])) {
        return $field['opties'];
    }
    $src = $field['optiesBron'];
    if (!is_array($src) || ($context['company'] ?? '') === '') {
        return [];
    }
    $memoKey = md5(json_encode([$src, $context['company'], $context['environment'] ?? '']));
    if (isset($GLOBALS['thothOptionMemo'][$memoKey])) {
        return $GLOBALS['thothOptionMemo'][$memoKey];
    }
    $depCol = $src['afhankelijkVan']['kolom'] ?? null;
    $select = array_values(array_unique(array_merge([$src['waarde-kolom']], $src['label-kolommen'], $src['zoek-kolommen'], $depCol !== null ? [$depCol] : [])));
    $reader = $GLOBALS['thothOptionReader'] ?? null;
    $rows = is_callable($reader)
        ? $reader($src, $select, $context)
        : thoth_bc_read((string) ($context['environment'] ?? ''), (string) $context['company'], $src['bc-tabel'], $select, $src['filter'], THOTH_UI_MAX_AGE);
    $out = [];
    $seen = [];
    foreach ($rows as $row) {
        $value = trim((string) ($row[$src['waarde-kolom']] ?? ''));
        if ($value === '') {
            continue;
        }
        // Bron kan dezelfde waarde vaak bevatten (bv. modellen uit bestaande componenten): één keer tonen.
        $dedupe = mb_strtolower($value . "\x1f" . ($depCol !== null ? trim((string) ($row[$depCol] ?? '')) : ''));
        if (isset($seen[$dedupe])) {
            continue;
        }
        $seen[$dedupe] = true;
        $out[] = ['waarde' => $value, 'label' => thoth_row_label($row, $src), 'aliassen' => [], 'row' => $row];
    }

    return $GLOBALS['thothOptionMemo'][$memoKey] = $out;
}

function thoth_row_label(array $row, array $src): string
{
    $parts = [trim((string) ($row[$src['waarde-kolom']] ?? ''))];
    foreach ($src['label-kolommen'] as $col) {
        $part = trim((string) ($row[$col] ?? ''));
        if ($part !== '' && !in_array($part, $parts, true)) {
            $parts[] = $part;
        }
    }

    return implode(' · ', array_filter($parts, static fn ($p) => $p !== ''));
}

function thoth_option_exists(array $field, string $value, array $context): bool
{
    foreach (thoth_field_options($field, $context) as $opt) {
        if (thoth_same_value($opt['waarde'], $value) || thoth_same_value($opt['label'], $value)) {
            return true;
        }
        foreach ($opt['aliassen'] as $alias) {
            if (thoth_same_value($alias, $value)) {
                return true;
            }
        }
    }

    return false;
}

/** Zet een dropdownwaarde (label/alias/NL-caption) om naar de echte waarde voor BC. */
function thoth_option_canonical(array $field, string $value, array $context): string
{
    foreach (thoth_field_options($field, $context) as $opt) {
        $all = array_merge([$opt['waarde'], $opt['label']], $opt['aliassen']);
        foreach ($all as $candidate) {
            if (thoth_same_value($candidate, $value)) {
                return $opt['waarde'];
            }
        }
    }

    return $value;
}

/**
 * Strikte lookup: bestaat deze waarde in de bron? Gericht filter op de waarde-kolom.
 * Bij opslaan/indienen mag Mímir 10 minuten oud zijn; bij goedkeuren vers (max_age 0).
 */
function thoth_lookup_exists(array $field, string $value, array $context): bool
{
    $src = $field['optiesBron'];
    if (!is_array($src) || ($context['company'] ?? '') === '' || trim($value) === '') {
        return false;
    }
    $checker = $GLOBALS['thothLookupChecker'] ?? null;
    if (is_callable($checker)) {
        return (bool) $checker($field, $value, $context);
    }
    $filter = $src['waarde-kolom'] . " eq '" . thoth_odata_quote(trim($value)) . "'";
    if ($src['filter'] !== '') {
        $filter = '(' . $src['filter'] . ') and ' . $filter;
    }
    $maxAge = ($context['moment'] ?? '') === 'goedkeuren' ? 0 : 600;
    $rows = thoth_bc_read((string) ($context['environment'] ?? ''), (string) $context['company'], $src['bc-tabel'], [$src['waarde-kolom']], $filter, $maxAge);
    foreach ($rows as $row) {
        if (thoth_same_value((string) ($row[$src['waarde-kolom']] ?? ''), $value)) {
            return true;
        }
    }

    return false;
}

/**
 * Zoeken voor lookup/combobox: alle zoektermen moeten in één van de zoek-kolommen voorkomen.
 * De bron komt uit Mímir (24 uur cache); filteren gebeurt hier.
 *
 * @return list<array{waarde:string,label:string}>
 */
function thoth_search_options(array $field, string $query, array $context, int $limit = 20, string $parentValue = ''): array
{
    $depCol = $field['optiesBron']['afhankelijkVan']['kolom'] ?? null;
    $terms = array_values(array_filter(preg_split('/\s+/u', mb_strtolower(trim($query))) ?: [], static fn ($t) => $t !== ''));
    $cols = $field['optiesBron']['zoek-kolommen'] ?? [];
    $out = [];
    foreach (thoth_field_options($field, $context) as $opt) {
        if ($depCol !== null && trim($parentValue) !== '' && !thoth_same_value((string) ($opt['row'][$depCol] ?? ''), $parentValue)) {
            continue;
        }
        $hay = mb_strtolower($opt['waarde'] . ' ' . $opt['label']);
        foreach ($cols as $col) {
            $hay .= ' ' . mb_strtolower((string) ($opt['row'][$col] ?? ''));
        }
        foreach ($terms as $term) {
            if (!str_contains($hay, $term)) {
                continue 2;
            }
        }
        $out[] = ['waarde' => $opt['waarde'], 'label' => $opt['label']];
        if (count($out) >= $limit) {
            break;
        }
    }

    return $out;
}

/**
 * Vult de automatische velden (invoerType automatisch) vers uit BC, bij goedkeuren.
 * Geeft [waarden, fouten] terug; een verplicht automatisch veld dat leeg blijft is een fout.
 *
 * @param array<string, mixed> $values
 * @return array{0: array<string, mixed>, 1: array<string, string>}
 */
function thoth_resolve_derived(array $config, array $values, array $context): array
{
    $errors = [];
    $byKey = thoth_config_fields_by_key($config);
    $reader = $GLOBALS['thothDerivedReader'] ?? null;
    $memo = [];
    foreach ($config['formFields'] as $field) {
        $src = $field['afgeleidVan'] ?? null;
        if ($field['invoerType'] !== 'automatisch' || !is_array($src)) {
            continue;
        }
        $key = $field['key'];
        $sourceField = $byKey[$src['veld']] ?? null;
        $sourceName = $sourceField['name'] ?? $src['veld'];
        $keyValue = trim((string) ($values[$src['veld']] ?? ''));
        if (($field['overschrijfbaar'] ?? false) && !thoth_value_is_empty($values[$key] ?? null)) {
            $values[$key] = trim((string) $values[$key]); // door de gebruiker gekozen (kaart): niet overschrijven
            continue;
        }
        $values[$key] = '';
        if ($src['bc-tabel'] === null) {
            $values[$key] = ($field['maxLengte'] ?? null) !== null ? mb_substr($keyValue, 0, $field['maxLengte']) : $keyValue;
        } elseif ($keyValue !== '') {
            $memoKey = $src['bc-tabel'] . '|' . $src['sleutel-kolom'] . '|' . $src['kolom'] . '|' . mb_strtolower($keyValue);
            if (!array_key_exists($memoKey, $memo)) {
                $filter = $src['sleutel-kolom'] . " eq '" . thoth_odata_quote($keyValue) . "'";
                $rows = is_callable($reader)
                    ? $reader($src, $filter, $context)
                    : thoth_bc_read((string) ($context['environment'] ?? ''), (string) ($context['company'] ?? ''), $src['bc-tabel'], [$src['sleutel-kolom'], $src['kolom']], $filter, 0);
                $memo[$memoKey] = null;
                foreach ($rows as $row) {
                    if (thoth_same_value((string) ($row[$src['sleutel-kolom']] ?? ''), $keyValue)) {
                        $memo[$memoKey] = $row;
                        break;
                    }
                }
            }
            $row = $memo[$memoKey];
            if ($row === null) {
                $errors[$key] = $field['name'] . ': ' . $sourceName . ' ' . $keyValue . ' niet gevonden in BC (' . $src['bc-tabel'] . ').';
                continue;
            }
            $value = trim((string) ($row[$src['kolom']] ?? ''));
            if (($field['maxLengte'] ?? null) !== null) {
                $value = mb_substr($value, 0, $field['maxLengte']);
            }
            $values[$key] = $value;
        }
        if ($values[$key] === '' && $field['verplicht']) {
            $errors[$key] = $field['name'] . ' kon niet automatisch worden overgenomen: ' . $sourceName
                . ($keyValue !== '' && $src['kolom'] !== null ? ' ' . $keyValue . ' heeft geen ' . $src['kolom'] . ' in BC.' : ' is leeg.');
        }
    }

    return [$values, $errors];
}

/**
 * Zet formulierwaarden om naar de OData-body voor BC.
 *
 * @return array<string, mixed>
 */
function thoth_build_bc_payload(array $config, array $values, array $context): array
{
    $payload = [];
    foreach ($config['formFields'] as $field) {
        $value = $values[$field['key']] ?? null;
        if (thoth_value_is_empty($value)) {
            continue;
        }
        $value = trim((string) $value);
        $payload[$field['bc-kolom']] = match ($field['invoerType']) {
            'nummer' => str_contains($n = str_replace(',', '.', $value), '.') ? (float) $n : (int) $n,
            'time' => strlen($value) === 5 ? $value . ':00' : $value,
            'datetime' => (new DateTimeImmutable($value, new DateTimeZone('Europe/Amsterdam')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'dropdown' => thoth_option_canonical($field, $value, $context),
            default => $value,
        };
    }

    return $payload;
}
