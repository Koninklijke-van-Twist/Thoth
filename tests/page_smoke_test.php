<?php

declare(strict_types=1);

/**
 * Rooktest van de pagina's via php -S (lokaal = ingelogd als eerste $allowedUsers).
 * Geen echte BC: lege $auth_list, bedrijvenlijst vooraf in de cache gezet.
 */

require __DIR__ . '/_bootstrap.php';

$data = getenv('THOTH_DATA_DIR');
$authFile = $data . '/auth.php';
file_put_contents($authFile, "<?php\n\$allowedUsers = ['anna@kvt.nl'];\n\$approvers = ['anna@kvt.nl'];\n\$auth_list = [];\n");
file_put_contents($data . '/companies.json', json_encode(['fetched_at' => time(), 'companies' => [
    ['name' => 'Koninklijke van Twist', 'environment' => 'kvtmdlive_aad'],
    ['name' => 'Koninklijke van Twist', 'environment' => 'kvtfat_aad'],
]]));

$port = random_int(20000, 40000);
$cmd = sprintf('THOTH_DATA_DIR=%s THOTH_AUTH_FILE=%s exec php -S 127.0.0.1:%d -t %s > /dev/null 2>&1',
    escapeshellarg($data), escapeshellarg($authFile), $port, escapeshellarg(dirname(__DIR__) . '/web'));
$proc = proc_open($cmd, [], $pipes);
register_shutdown_function(static fn () => proc_terminate($proc));
usleep(400000);

$jar = $data . '/cookies.txt';
function http(string $method, string $path, array $post = [], array $headers = []): array
{
    global $port, $jar;
    $ch = curl_init('http://127.0.0.1:' . $port . '/' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_HTTPHEADER => $headers, CURLOPT_HEADER => false]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$status, $body];
}

[$s, $body] = http('GET', 'index.php');
check_same(200, $s, 'overzicht laadt');
check(str_contains($body, 'Mijn verzoeken') && str_contains($body, 'Koninklijke van Twist (test: kvtfat_aad)'), 'bedrijvenlijst met testlabel');
check(str_contains($body, 'Goedkeuren'), 'approver ziet goedkeurmenu');
preg_match('/name="csrf" value="([a-f0-9]+)"/', $body, $m);
$csrf = $m[1] ?? '';
check($csrf !== '', 'CSRF-token in formulier');

[$s, $body] = http('GET', 'verzoek.php?type=servicelocatie');
check_same(200, $s, 'nieuw formulier laadt');
check(!str_contains($body, 'Voorbeeldconfig') && str_contains($body, 'Inzenden') && str_contains($body, 'Servicelocatienaam'), 'echte config zonder voorbeeldwaarschuwing, met inzendknop');
[$s, $body] = http('GET', 'verzoek.php?type=component');
check(str_contains($body, 'data-strikt="1"'), 'component heeft strikte lookup');
check(str_contains($body, 'Aanmaken in BC kan nog niet'), 'component toont blokkade-melding');
check(str_contains($body, 'automatisch overgenomen uit Servicelocatie (LVS_MainEntityCard)'), 'coördinaten tonen automatisch-hint');
check(str_contains($body, 'maxlength="50"'), 'maxlength op tekstvelden');
check(str_contains($body, 'data-ouder="Manufacturer_Code"'), 'model-suggesties afhankelijk van producent');
check(str_contains($body, 'automatisch overgenomen uit Model producent (KVT_LVS_Manufacturer_Model).'), 'omschrijving 2 uit modeltabel');
preg_match_all('/data-strikt="1".*?<input type="text"[^>]*class="combo-input"[^>]*>/s', $body, $strictInputs);
check(count($strictInputs[0]) === 4 && !preg_grep('/maxlength=/', $strictInputs[0]), 'strikte lookup: zoekveld zonder maxlength (label is langer dan de code)');
check(substr_count($body, 'data-strikt="1"') === 4, 'servicelocatie, equipmentsoort, producent en model zijn strikt');
check(str_contains($body, 'name="v[KVT_Latitude_Coordinate__x005B_DD_x005D_]" value="" readonly'), 'overschrijfbare coördinaat: readonly en ingestuurd');
check(!str_contains($body, 'name="v[Description_2]"'), 'niet-overschrijfbaar automatisch veld wordt niet ingestuurd');
check(str_contains($body, 'data-kaart-open') && str_contains($body, 'data-kaart-wis'), 'component: kaartknop en terug naar automatisch');

[$s, $body] = http('GET', 'verzoek.php?type=servicelocatie');
check(str_contains($body, 'Kies op kaart') && str_contains($body, 'id="kaart-dialog"'), 'servicelocatie: kaartknop en modal');
check(str_contains($body, 'assets/vendor/leaflet/leaflet.js') && str_contains($body, 'assets/vendor/leaflet/leaflet.css') && !str_contains($body, 'unpkg'), 'Leaflet lokaal, geen CDN');
check(str_contains($body, 'data-kaart-landen="NL,BE,DE,IT,FI,PL"'), 'toegestane landen naar de kaart');
check(str_contains($body, '&quot;adres&quot;:&quot;f_KVT_Address&quot;'), 'kaart vult KVT_Address');
$ch = curl_init('http://127.0.0.1:' . $port . '/verzoek.php?type=servicelocatie');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_NOBODY => true, CURLOPT_COOKIEFILE => $jar]);
$hdr = (string) curl_exec($ch);
curl_close($ch);
check(preg_match("/Content-Security-Policy: [^\r\n]*img-src 'self' data: https:\/\/tile\.openstreetmap\.org/i", $hdr) === 1, 'CSP staat OSM-tegels toe');
check(preg_match("/Content-Security-Policy: [^\r\n]*connect-src 'self'/i", $hdr) === 1 && stripos($hdr, 'Referrer-Policy: strict-origin-when-cross-origin') !== false, 'CSP connect-src self (Nominatim via api.php) en Referrer-Policy');
foreach (['assets/kaart.js', 'assets/vendor/leaflet/leaflet.js', 'assets/vendor/leaflet/images/marker-icon.png'] as $asset) {
    check_same(200, http('GET', $asset)[0], "$asset bereikbaar");
}

[$s, $body] = http('POST', 'api.php', ['actie' => 'opslaan', 'type' => 'servicelocatie', 'v' => ['Name' => 'Test']]);
check_same(400, $s, 'autosave zonder CSRF geweigerd');
[$s, $body] = http('POST', 'api.php', ['actie' => 'opslaan', 'csrf' => $csrf, 'type' => 'servicelocatie', 'bedrijf' => 'kvtmdlive_aad|Koninklijke van Twist', 'v' => ['Description' => '<script>x</script>']]);
$json = json_decode($body, true);
check_same(200, $s, 'autosave ok');
check(($json['ok'] ?? false) && preg_match('/^\d{2}:\d{2}:\d{2}$/', (string) ($json['opgeslagen'] ?? '')) === 1, 'Opgeslagen hh:mm:ss');
$id = (int) ($json['id'] ?? 0);
[$s, $body] = http('GET', 'verzoek.php?id=' . $id);
check(str_contains($body, '&lt;script&gt;x&lt;/script&gt;') && !str_contains($body, '<script>x</script>'), 'output ge-escaped');
check(str_contains($body, 'Historie'), 'historie zichtbaar');

[$s, $body] = http('POST', 'verzoek.php?id=' . $id, ['actie' => 'indienen', 'csrf' => $csrf, 'id' => $id, 'bedrijf' => 'kvtmdlive_aad|Koninklijke van Twist', 'v' => ['Description' => 'Test']]);
check(str_contains($body, 'Nog niet ingediend'), 'indienen geweigerd zolang verplichte velden leeg zijn');

[$s, $body] = http('GET', 'api.php?actie=zoek&type=component&veld=Main_Entity&bedrijf=' . rawurlencode('kvtmdlive_aad|Koninklijke van Twist') . '&q=x');
$json = json_decode($body, true);
check(isset($json['ok']), 'zoek-endpoint geeft JSON (' . $s . ')');

finish('page_smoke');
