<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

// Volgorde van $auth_list bewust "verkeerd": de test-environment staat eerst.
$GLOBALS['auth_list'] = [
    'kvtfat_aad' => ['mode' => 'basic', 'user' => 'fat-user', 'pass' => 'x'],
    'kvtmdlive_aad' => ['mode' => 'ntlm', 'user' => 'live-user', 'pass' => 'y'],
];
$GLOBALS['baseUrl'] = 'https://kvtmd365.kvt.nl:7148/';
unset($GLOBALS['mimirApi']);

$c = sample_config();
$calls = [];
$existing = ['SL12600001', 'SL12600002', 'SL12600004'];
$postResponses = [];
$GLOBALS['thothHttpClient'] = static function (string $method, string $url, array $headers, ?string $body, ?array $auth) use (&$calls, &$existing, &$postResponses): array {
    $calls[] = compact('method', 'url', 'headers', 'body', 'auth');
    if ($method === 'GET') {
        return ['status' => 200, 'body' => json_encode(['value' => array_map(static fn ($n) => ['No' => $n], $existing)])];
    }
    $next = array_shift($postResponses) ?? ['status' => 201, 'body' => null];
    if ($next['body'] === null) {
        $next['body'] = $body; // BC geeft het aangemaakte record terug
    }

    return $next;
};

// 1. Normale insert: eerste vrije = 3
$res = thoth_bc_insert($c, 'kvtmdlive_aad', "Hunter van Twist's", ['Name' => 'Gemaal'], 2026);
check_same('SL12600003', $res['number'], 'eerste vrije nummer');
check_same(1, $res['attempts'], 'één poging');
check_same('GET', $calls[0]['method'], 'eerst bestaande nummers ophalen');
check(str_contains(rawurldecode($calls[0]['url']), "startswith(No,'SL126')"), 'filter op prefix + jaar');
check(str_starts_with($calls[0]['url'], "https://kvtmd365.kvt.nl:7148/kvtmdlive_aad/ODataV4/Company('Hunter%20van%20Twist%27%27s')/Locs"), 'URL met environment, gequote bedrijf en tabel: ' . $calls[0]['url']);
check_same('POST', $calls[1]['method'], 'dan POST');
check_same(['No' => 'SL12600003', 'Name' => 'Gemaal'], json_decode((string) $calls[1]['body'], true), 'body met nummer en velden');
check(in_array('Content-Type: application/json', $calls[1]['headers'], true) && in_array('Accept: application/json', $calls[1]['headers'], true), 'JSON-headers zoals Calculus');
check_same('live-user', $calls[1]['auth']['user'], 'auth op environment-sleutel, niet op volgorde');
check_same('ntlm', $calls[1]['auth']['mode'], 'auth-modus uit $auth_list');

// 2. Conflict -> volgende vrije
$calls = [];
$postResponses = [
    ['status' => 400, 'body' => json_encode(['error' => ['code' => 'Internal_EntityWithSameKeyExists', 'message' => 'The record already exists.']])],
    ['status' => 409, 'body' => 'Conflict'],
];
$res = thoth_bc_insert($c, 'kvtmdlive_aad', 'Koninklijke van Twist', ['Name' => 'X'], 2026);
check_same('SL12600006', $res['number'], 'na twee conflicten 3 en 5 overgeslagen');
check_same(3, $res['attempts'], 'drie pogingen');
$tried = array_map(static fn ($c) => json_decode((string) $c['body'], true)['No'] ?? null, array_values(array_filter($calls, static fn ($c) => $c['method'] === 'POST')));
check_same(['SL12600003', 'SL12600005', 'SL12600006'], $tried, 'opeenvolgende vrije nummers geprobeerd');

// 3. Blijvend conflict -> stopt na max pogingen
$calls = [];
$postResponses = array_fill(0, 10, ['status' => 409, 'body' => '{"error":{"message":"bestaat al"}}']);
check_throws(fn () => thoth_bc_insert($c, 'kvtmdlive_aad', 'K', ['Name' => 'X'], 2026), 'pogingen', 'geeft op na maximum');
check_same(THOTH_INSERT_MAX_ATTEMPTS, count(array_filter($calls, static fn ($c) => $c['method'] === 'POST')), 'precies max pogingen');

// 4. Andere fout -> geen retry, duidelijke melding
$calls = [];
$postResponses = [['status' => 400, 'body' => json_encode(['error' => ['message' => "Field 'Kind' has an invalid value"]])]];
check_throws(fn () => thoth_bc_insert($c, 'kvtmdlive_aad', 'K', ['Name' => 'X'], 2026), "Field 'Kind' has an invalid value", 'BC-melding doorgegeven');
check_same(1, count(array_filter($calls, static fn ($c) => $c['method'] === 'POST')), 'geen retry bij andere fout');

// 5. Onbekende environment -> fout, niets verstuurd
$calls = [];
check_throws(fn () => thoth_bc_insert($c, 'kvtgermanylive_aad', 'KVT Germany', ['Name' => 'X'], 2026), 'Geen BC-inloggegevens', 'geen auth voor environment');
check_same([], $calls, 'niets verstuurd zonder auth');

// 6. Lock: lockbestand in datamap, na afloop vrijgegeven (tweede insert direct mogelijk)
$postResponses = [];
$lockFiles = glob(getenv('THOTH_DATA_DIR') . '/bc-insert-*.lock');
check(count($lockFiles) >= 1, 'lockbestand per tabel/bedrijf');
$h = fopen($lockFiles[0], 'c');
check(flock($h, LOCK_EX | LOCK_NB), 'lock is vrijgegeven na insert');
flock($h, LOCK_UN);
fclose($h);

// 7. max+1 in de insert
$existing = ['SL12600001', 'SL12600004'];
$calls = [];
$res = thoth_bc_insert(sample_config(['autoIncrementStrategie' => 'max+1']), 'kvtmdlive_aad', 'K', ['Name' => 'X'], 2026);
check_same('SL12600005', $res['number'], 'max+1 in insert');

// 8. Duplicate-herkenning
check(thoth_bc_is_duplicate_error(400, 'The record in table Service Location already exists. Identification fields: No.=SL12600003'), 'already exists');
check(thoth_bc_is_duplicate_error(400, 'Der Datensatz existiert bereits'), 'Duits');
check(!thoth_bc_is_duplicate_error(400, 'Field must have a value'), 'gewone fout is geen duplicate');

// 9. Payload-opbouw
$payload = thoth_build_bc_payload($c, ['Name' => ' Gemaal ', 'Qty' => '2,5', 'Kind' => 'geopend', 'Loc_No' => ''], ['company' => 'K', 'environment' => 'kvtmdlive_aad']);
check_same(['Name' => 'Gemaal', 'Qty' => 2.5, 'Kind' => 'Open'], $payload, 'trim, getal, NL-alias naar BC-waarde, lege velden weg');
$dt = thoth_validate_config(['auto-increment-field' => 'No', 'autoIncrementPrefix' => 'A', 'autoIncrementNumberPadding' => 3, 'autoIncrementPrefixYear' => false,
    'formFields' => [['name' => 'Moment', 'invoerType' => 'datetime', 'bc-tabel' => 'T', 'bc-kolom' => 'At'], ['name' => 'Tijd', 'invoerType' => 'time', 'bc-tabel' => 'T', 'bc-kolom' => 'Tm']]]);
check_same(['At' => '2026-10-08T13:30:00Z', 'Tm' => '08:15:00'], thoth_build_bc_payload($dt, ['At' => '2026-10-08T15:30', 'Tm' => '08:15'], []), 'datetime naar UTC, tijd met seconden');

// 10. Lezen via Mímir; ander environment voor dit bedrijf -> directe OData
$GLOBALS['mimirApi'] = 'mimir_test';
$calls = [];
$GLOBALS['thothHttpClient'] = static function (string $method, string $url, array $headers, ?string $body, ?array $auth) use (&$calls): array {
    $calls[] = compact('method', 'url', 'body', 'auth');
    if (str_contains($url, 'mimir/api/query.php')) {
        return ['status' => 200, 'body' => json_encode(['value' => [['No' => 'M1', 'Name' => 'Via Mímir', 'City' => 'Hengelo']], 'meta' => ['environment' => 'kvtmdlive_aad']])];
    }
    return ['status' => 200, 'body' => json_encode(['value' => [['No' => 'D1', 'Name' => 'Direct', 'City' => 'Assen']]])];
};
$rows = thoth_bc_read('kvtmdlive_aad', 'K', 'ServiceLocs', ['No'], '', 60);
check_same('M1', $rows[0]['No'], 'Mímir eerst');
check(!isset($calls[0]['auth']), 'Mímir zonder BC-auth');
$rows = thoth_bc_read('kvtfat_aad', 'K', 'ServiceLocs', ['No'], '', 60);
check_same('D1', $rows[0]['No'], 'test-environment: Mímir geeft live, dus direct BC');

// 11. Zoeken voor lookup (meerdere kolommen, alle termen)
$field = $c['formFields'][3];
$GLOBALS['thothHttpClient'] = static fn () => ['status' => 200, 'body' => json_encode(['value' => [
    ['No' => 'SL12600001', 'Name' => 'Gemaal De Hoek', 'City' => 'Hengelo'],
    ['No' => 'SL12600002', 'Name' => 'Schip Twente', 'City' => 'Enschede'],
], 'meta' => ['environment' => 'kvtmdlive_aad']])];
$ctx = ['company' => 'K', 'environment' => 'kvtmdlive_aad'];
check_same(['SL12600001'], array_column(thoth_search_options($field, 'hoek heng', $ctx), 'waarde'), 'zoeken op meerdere kolommen');
check_same(['SL12600002'], array_column(thoth_search_options($field, 'enschede', $ctx), 'waarde'), 'zoeken op plaats');
check_same('SL12600001 · Gemaal De Hoek', thoth_search_options($field, '00001', $ctx)[0]['label'], 'label uit label-kolommen');
check(thoth_lookup_exists($field, 'SL12600002', $ctx + ['moment' => 'goedkeuren']), 'lookup bestaat');

// 12. Bedrijvenlijst: Mímir (alleen live) aangevuld met test-environments uit $auth_list
$GLOBALS['mimirApi'] = 'mimir_test';
$calls = [];
$GLOBALS['thothHttpClient'] = static function (string $method, string $url, array $headers, ?string $body, ?array $auth) use (&$calls): array {
    $calls[] = $url;
    if (str_contains($url, 'companies.php')) {
        return ['status' => 200, 'body' => json_encode(['value' => [['name' => 'Koninklijke van Twist', 'environment' => 'kvtmdlive_aad']]])];
    }
    return ['status' => 200, 'body' => json_encode(['value' => [['Name' => 'Koninklijke van Twist']]])];
};
$found = thoth_bc_discover_companies();
check_same([['name' => 'Koninklijke van Twist', 'environment' => 'kvtmdlive_aad'], ['name' => 'Koninklijke van Twist', 'environment' => 'kvtfat_aad']], $found, 'testbedrijf uit kvtfat_aad erbij');
check(!in_array(true, array_map(static fn ($u) => str_contains($u, '/kvtmdlive_aad/ODataV4/Company'), $calls), true), 'live-environment niet dubbel direct opgevraagd');
unset($GLOBALS['mimirApi']);

// 13. Time-outveilig: na een time-out eerst in BC zoeken, geen tweede POST
$bcRecords = ['SL12600001' => ['No' => 'SL12600001', 'Name' => 'Oud']];
$posts = 0;
$gets = [];
$postMode = 'timeout-created';
$GLOBALS['thothHttpClient'] = static function (string $method, string $url, array $headers, ?string $body, ?array $auth) use (&$bcRecords, &$posts, &$gets, &$postMode): array {
    if ($method === 'GET') {
        $q = rawurldecode($url);
        $gets[] = $q;
        $rows = [];
        foreach ($bcRecords as $no => $row) {
            if (str_contains($q, "startswith(No,'SL126')") || str_contains($q, "No eq '" . $no . "'")) {
                $rows[] = $row;
            }
        }
        return ['status' => 200, 'body' => json_encode(['value' => $rows])];
    }
    $posts++;
    $rec = json_decode((string) $body, true);
    if ($postMode === 'timeout-created') {
        $bcRecords[$rec['No']] = $rec; // BC maakte het wél aan
        throw new ThothBcException('Verbindingsfout: Operation timed out after 120000 milliseconds');
    }
    if ($postMode === 'timeout-not-created') {
        throw new ThothBcException('Verbindingsfout: Operation timed out');
    }
    if ($postMode === '504-created-later') {
        return ['status' => 504, 'body' => 'Gateway Timeout'];
    }
    $bcRecords[$rec['No']] = $rec;
    return ['status' => 201, 'body' => json_encode($rec)];
};
$reservedSeen = [];
$opts = ['on_reserve' => static function (string $n) use (&$reservedSeen) { $reservedSeen[] = $n; }];
$res = thoth_bc_insert($c, 'kvtmdlive_aad', 'K', ['Name' => 'Gemaal Oost'], 2026, $opts);
check_same('SL12600002', $res['number'], 'time-out maar record bestaat: nummer uit BC');
check($res['recovered'], 'recovered gemarkeerd');
check_same(1, $posts, 'geen tweede POST na time-out');
check_same(['SL12600002'], $reservedSeen, 'nummer gereserveerd vóór de POST');
check(str_contains(end($gets), "No eq 'SL12600002'"), 'controle op het gereserveerde nummer');

// Time-out en record bestaat niet: fout, geen nieuwe poging
$posts = 0;
$postMode = 'timeout-not-created';
check_throws(fn () => thoth_bc_insert($c, 'kvtmdlive_aad', 'K', ['Name' => 'Gemaal Zuid'], 2026), 'geen tweede poging', 'time-out zonder record: duidelijke fout');
check_same(1, $posts, 'time-out zonder record: maar één POST');

// 504, record verschijnt later; volgende goedkeuring vindt het via check_first
$posts = 0;
$postMode = '504-created-later';
check_throws(fn () => thoth_bc_insert($c, 'kvtmdlive_aad', 'K', ['Name' => 'Schip West'], 2026), 'SL12600003', '504: melding met gereserveerd nummer');
$bcRecords['SL12600003'] = ['No' => 'SL12600003', 'Name' => 'SCHIP WEST']; // BC verwerkte het toch
$postMode = 'ok';
$posts = 0;
$res = thoth_bc_insert($c, 'kvtmdlive_aad', 'K', ['Name' => 'Schip West'], 2026, ['check_first' => ['SL12600003']]);
check_same(['SL12600003', true, 0], [$res['number'], $res['recovered'], $posts], 'opnieuw goedkeuren: eerst gereserveerd nummer gecontroleerd, geen POST');

// Gereserveerd nummer is intussen door iemand anders gebruikt (andere velden): wel nieuwe POST
$bcRecords['SL12600004'] = ['No' => 'SL12600004', 'Name' => 'Iets anders'];
$res = thoth_bc_insert($c, 'kvtmdlive_aad', 'K', ['Name' => 'Gemaal Noord'], 2026, ['check_first' => ['SL12600004']]);
check(!$res['recovered'] && $res['number'] === 'SL12600005' && $posts === 1, 'vreemd record op gereserveerd nummer telt niet als het onze');

// 400 blijft een gewone fout zonder zoekactie
$GLOBALS['thothHttpClient'] = static function (string $method) use (&$gets): array {
    $gets[] = $method;
    return $method === 'GET' ? ['status' => 200, 'body' => '{"value":[]}'] : ['status' => 400, 'body' => '{"error":{"message":"Veld ongeldig"}}'];
};
$gets = [];
check_throws(fn () => thoth_bc_insert($c, 'kvtmdlive_aad', 'K', ['Name' => 'X'], 2026), 'Veld ongeldig', '400 direct als fout');
check_same(['GET', 'POST'], $gets, '400: geen extra zoekactie');

finish('bc_insert');
