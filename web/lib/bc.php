<?php

declare(strict_types=1);

/**
 * Business Central: lezen via Mímir (met directe OData als terugval) en schrijven
 * direct via OData (POST), naar het patroon van Calculus (BcAutomation::requestJson):
 *   POST {baseUrl}{environment}/ODataV4/Company('{bedrijf}')/{webservice}
 *   Accept/Content-Type: application/json, basic of NTLM uit $auth_list[environment].
 *
 * Het environment komt altijd uit het opgeslagen bedrijf (bedrijvenlijst),
 * nooit uit de volgorde van de sleutels in $auth_list.
 *
 * Alle HTTP loopt via thoth_http(), zodat tests een mock kunnen injecteren
 * met $GLOBALS['thothHttpClient'] = fn(method, url, headers, body, auth) => [status, body].
 */

const THOTH_BC_BASE_URL_DEFAULT = 'https://kvtmd365.kvt.nl:7148/';
const THOTH_MIMIR_BASE_DEFAULT = 'https://sleutels.kvt.nl/mimir/api';
const THOTH_UI_MAX_AGE = 86400;
const THOTH_INSERT_MAX_ATTEMPTS = 4;

final class ThothBcException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, public readonly string $body = '')
    {
        parent::__construct($message);
    }
}

/**
 * @param array<string,string>|null $auth ['mode','user','pass'] of null (Mímir: header-sleutel)
 * @return array{status:int, body:string}
 */
function thoth_http(string $method, string $url, array $headers, ?string $body, ?array $auth): array
{
    $client = $GLOBALS['thothHttpClient'] ?? null;
    if (is_callable($client)) {
        $res = $client($method, $url, $headers, $body, $auth);
        return ['status' => (int) ($res['status'] ?? 0), 'body' => (string) ($res['body'] ?? '')];
    }
    $ch = curl_init($url);
    if ($ch === false) {
        throw new ThothBcException('cURL kon niet starten.');
    }
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'Thoth/1.0',
        CURLOPT_ENCODING => '',
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
    }
    if ($auth !== null) {
        $opts[CURLOPT_HTTPAUTH] = (($auth['mode'] ?? 'basic') === 'ntlm') ? CURLAUTH_NTLM : CURLAUTH_BASIC;
        $opts[CURLOPT_USERPWD] = (string) ($auth['user'] ?? '') . ':' . (string) ($auth['pass'] ?? '');
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        throw new ThothBcException('Verbindingsfout: ' . $err);
    }

    return ['status' => $status, 'body' => (string) $raw];
}

function thoth_bc_base_url(): string
{
    $base = $GLOBALS['baseUrl'] ?? '';
    if (!is_string($base) || trim($base) === '' || stripos($base, 'mimir.invalid') !== false) {
        $base = THOTH_BC_BASE_URL_DEFAULT;
    }

    return rtrim(trim($base), '/') . '/';
}

/** BC-auth voor een environment, op sleutel. Nooit op volgorde. */
function thoth_bc_auth_for_environment(string $environment): array
{
    $list = $GLOBALS['auth_list'] ?? [];
    $entry = is_array($list) ? ($list[$environment] ?? null) : null;
    if (!is_array($entry) || trim((string) ($entry['user'] ?? '')) === '' || !in_array($entry['mode'] ?? '', ['basic', 'ntlm'], true)) {
        throw new ThothBcException('Geen BC-inloggegevens voor environment "' . $environment . '" in $auth_list (auth.php).');
    }

    return $entry;
}

function thoth_odata_quote(string $value): string
{
    return str_replace("'", "''", $value);
}

function thoth_bc_entity_url(string $environment, string $company, string $entity, array $query = []): string
{
    $url = thoth_bc_base_url() . rawurlencode($environment) . '/ODataV4/Company(\''
        . rawurlencode(thoth_odata_quote($company)) . '\')/' . rawurlencode($entity);
    if ($query !== []) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    return $url;
}

/** Korte, leesbare foutmelding uit een BC/OData-foutbody. */
function thoth_bc_error_message(int $status, string $body): string
{
    $decoded = json_decode($body, true);
    $msg = is_array($decoded) ? (string) ($decoded['error']['message'] ?? $decoded['error'] ?? '') : '';
    if ($msg === '') {
        $msg = trim(strip_tags($body));
    }
    if (mb_strlen($msg) > 400) {
        $msg = mb_substr($msg, 0, 400) . '…';
    }

    return 'BC HTTP ' . $status . ($msg !== '' ? ': ' . $msg : '');
}

/** Directe OData-GET met paginering. */
function thoth_bc_get_all(string $environment, string $company, string $entity, array $query): array
{
    $auth = thoth_bc_auth_for_environment($environment);
    $url = thoth_bc_entity_url($environment, $company, $entity, $query);
    $rows = [];
    $guard = 0;
    while ($url !== '' && $guard++ < 200) {
        $res = thoth_http('GET', $url, ['Accept: application/json'], null, $auth);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new ThothBcException(thoth_bc_error_message($res['status'], $res['body']), $res['status'], $res['body']);
        }
        $json = json_decode($res['body'], true);
        if (!is_array($json) || !is_array($json['value'] ?? null)) {
            throw new ThothBcException('BC gaf geen geldige OData-lijst terug.');
        }
        array_push($rows, ...$json['value']);
        $url = (string) ($json['@odata.nextLink'] ?? '');
    }

    return $rows;
}

function thoth_mimir_key(): string
{
    $key = $GLOBALS['mimirApi'] ?? '';

    return is_string($key) ? trim($key) : '';
}

function thoth_mimir_request(string $method, string $path, ?array $body = null): array
{
    $key = thoth_mimir_key();
    if ($key === '') {
        throw new ThothBcException('Mímir niet geconfigureerd ($mimirApi).');
    }
    $base = $GLOBALS['mimirBase'] ?? '';
    $base = is_string($base) && trim($base) !== '' ? rtrim(trim($base), '/') : THOTH_MIMIR_BASE_DEFAULT;
    $headers = ['Accept: application/json', 'Authorization: Bearer ' . $key, 'X-API-Key: ' . $key];
    $payload = null;
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $res = thoth_http($method, $base . '/' . ltrim($path, '/'), $headers, $payload, null);
    $json = json_decode($res['body'], true);
    if ($res['status'] < 200 || $res['status'] >= 300 || !is_array($json) || !empty($json['error'])) {
        $err = is_array($json) && isset($json['error']) ? (is_string($json['error']) ? $json['error'] : json_encode($json['error'])) : 'HTTP ' . $res['status'];
        throw new ThothBcException('Mímir: ' . $err, $res['status'], $res['body']);
    }

    return $json;
}

/**
 * Leest rijen: eerst Mímir, anders (of als Mímir een ander environment voor dit
 * bedrijf kiest, bv. een testbedrijf met dezelfde naam) direct via OData.
 *
 * @param list<string> $select
 */
function thoth_bc_read(string $environment, string $company, string $table, array $select, string $filter, int $maxAge): array
{
    $mimirError = null;
    if (thoth_mimir_key() !== '') {
        try {
            $body = ['company' => $company, 'table' => $table, 'max_age' => max(0, $maxAge), 'top' => 0];
            if ($select !== []) {
                $body['select'] = array_values($select);
            }
            if ($filter !== '') {
                $body['filter'] = $filter;
            }
            $json = thoth_mimir_request('POST', 'query.php', $body);
            $env = (string) ($json['meta']['environment'] ?? '');
            if (is_array($json['value'] ?? null) && ($env === '' || strcasecmp($env, $environment) === 0)) {
                return $json['value'];
            }
        } catch (Throwable $e) {
            $mimirError = $e;
        }
    }
    $query = [];
    if ($select !== []) {
        $query['$select'] = implode(',', $select);
    }
    if ($filter !== '') {
        $query['$filter'] = $filter;
    }
    try {
        return thoth_bc_get_all($environment, $company, $table, $query);
    } catch (ThothBcException $e) {
        if ($mimirError !== null && str_contains($e->getMessage(), 'Geen BC-inloggegevens')) {
            throw new ThothBcException($mimirError->getMessage());
        }
        throw $e;
    }
}

/**
 * Bedrijven over alle environments heen: Mímir companies.php, anders per
 * environment in $auth_list direct {base}{env}/ODataV4/Company.
 *
 * @return list<array{name:string, environment:string}>
 */
function thoth_bc_discover_companies(): array
{
    $out = [];
    if (thoth_mimir_key() !== '') {
        try {
            $json = thoth_mimir_request('GET', 'companies.php');
            foreach ((array) ($json['value'] ?? []) as $item) {
                $name = trim((string) ($item['name'] ?? $item['Name'] ?? ''));
                $env = trim((string) ($item['environment'] ?? ''));
                if ($name !== '' && $env !== '') {
                    $out[] = ['name' => $name, 'environment' => $env];
                }
            }
            if ($out !== []) {
                return $out;
            }
        } catch (Throwable $ignored) {
        }
    }
    foreach (array_keys((array) ($GLOBALS['auth_list'] ?? [])) as $env) {
        try {
            $auth = thoth_bc_auth_for_environment((string) $env);
            $res = thoth_http('GET', thoth_bc_base_url() . rawurlencode((string) $env) . '/ODataV4/Company', ['Accept: application/json'], null, $auth);
            $json = json_decode($res['body'], true);
            foreach ((array) ($json['value'] ?? []) as $item) {
                $name = trim((string) ($item['Name'] ?? ''));
                if ($name !== '') {
                    $out[] = ['name' => $name, 'environment' => (string) $env];
                }
            }
        } catch (Throwable $ignored) {
        }
    }

    return $out;
}

/** Herkent een BC-fout die op een bestaand nummer (duplicate key) wijst. */
function thoth_bc_is_duplicate_error(int $status, string $body): bool
{
    if ($status === 409 || $status === 412) {
        return true;
    }
    $text = mb_strtolower($body);
    foreach (['already exists', 'bestaat al', 'existiert bereits', 'duplicate', 'entityalreadyexists'] as $needle) {
        if (str_contains($text, $needle)) {
            return true;
        }
    }

    return false;
}

/**
 * Bestaande nummers met de juiste stam (prefix + jaar), vers uit BC (geen cache):
 * het is het moment van schrijven, dus direct via OData met dezelfde inloggegevens.
 *
 * @return list<string>
 */
function thoth_bc_existing_numbers(array $config, string $environment, string $company, int $year): array
{
    $field = (string) $config['auto-increment-field'];
    $stem = thoth_number_stem($config, $year);
    $rows = thoth_bc_get_all($environment, $company, (string) $config['bc-tabel'], [
        '$select' => $field,
        '$filter' => 'startswith(' . $field . ",'" . thoth_odata_quote($stem) . "')",
    ]);

    return array_values(array_map(static fn ($r) => (string) ($r[$field] ?? ''), $rows));
}

/**
 * Maakt het record in BC aan. Eén POST met alle velden op één tabel, dus geen
 * half record. Bij een duplicate wordt het volgende vrije nummer geprobeerd,
 * maximaal THOTH_INSERT_MAX_ATTEMPTS keer. Een bestandslock per tabel/bedrijf
 * voorkomt dat twee goedkeuringen tegelijk hetzelfde nummer pakken.
 *
 * @param array<string, mixed> $payload BC-kolom => waarde (zonder nummer)
 * @return array{number:string, attempts:int, response:array}
 */
function thoth_bc_insert(array $config, string $environment, string $company, array $payload, ?int $year = null): array
{
    $year ??= (int) date('Y');
    $auth = thoth_bc_auth_for_environment($environment);
    $lockPath = thoth_data_dir() . '/bc-insert-' . md5($environment . '|' . $company . '|' . $config['bc-tabel']) . '.lock';
    $lock = fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new ThothBcException('Kon de nummerlock niet krijgen.');
    }
    try {
        $used = thoth_used_sequences($config, $year, thoth_bc_existing_numbers($config, $environment, $company, $year));
        $url = thoth_bc_entity_url($environment, $company, (string) $config['bc-tabel']);
        $lastError = '';
        for ($attempt = 1; $attempt <= THOTH_INSERT_MAX_ATTEMPTS; $attempt++) {
            $seq = thoth_next_sequence($config, $used);
            if (!thoth_sequence_fits($config, $seq)) {
                throw new ThothBcException('Nummerreeks ' . thoth_number_stem($config, $year) . ' is vol (padding ' . $config['autoIncrementNumberPadding'] . ').');
            }
            $number = thoth_format_number($config, $year, $seq);
            $body = [$config['auto-increment-field'] => $number] + $payload;
            $res = thoth_http('POST', $url, ['Accept: application/json', 'Content-Type: application/json'], json_encode($body, JSON_UNESCAPED_UNICODE), $auth);
            if ($res['status'] >= 200 && $res['status'] < 300) {
                $json = json_decode($res['body'], true);
                $returned = is_array($json) ? (string) ($json[$config['auto-increment-field']] ?? '') : '';
                return ['number' => $returned !== '' ? $returned : $number, 'attempts' => $attempt, 'response' => is_array($json) ? $json : []];
            }
            $lastError = thoth_bc_error_message($res['status'], $res['body']);
            if (!thoth_bc_is_duplicate_error($res['status'], $res['body'])) {
                throw new ThothBcException($lastError, $res['status'], $res['body']);
            }
            $used[$seq] = true;
        }
        throw new ThothBcException('Na ' . THOTH_INSERT_MAX_ATTEMPTS . ' pogingen nog steeds een dubbel nummer. Laatste fout: ' . $lastError);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
