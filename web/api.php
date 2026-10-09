<?php

declare(strict_types=1);

/**
 * JSON-endpoints voor het formulier.
 *   POST actie=opslaan   debounced autosave (CSRF verplicht), maakt zo nodig een Concept aan.
 *   GET  actie=zoek      suggesties voor combobox/lookup (type, veld, bedrijf, q, optioneel ouder).
 *   GET  actie=geo-zoek  adres zoeken (q) en actie=geo-adres omgekeerd geocoderen (lat, lon), via Nominatim (lib/geo.php).
 */

require_once __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function thoth_json(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$action = (string) ($_REQUEST['actie'] ?? '');
try {
    if ($action === 'opslaan' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        thoth_require_csrf();
        $type = (string) ($_POST['type'] ?? '');
        $id = ctype_digit((string) ($_POST['id'] ?? '')) ? (int) $_POST['id'] : null;
        [$companyName, $companyEnv] = thoth_company_from_key((string) ($_POST['bedrijf'] ?? ''), thoth_companies());
        $request = thoth_save_draft($id, $type, $thothUser, (array) ($_POST['v'] ?? []), (array) ($_POST['l'] ?? []), $companyName, $companyEnv);
        if ($companyName !== null && $companyName !== '') {
            thoth_set_pref($thothUser, $companyName, (string) $companyEnv);
        }
        thoth_json(['ok' => true, 'id' => $request['id'], 'opgeslagen' => substr((string) $request['updated_at'], 11, 8)]);
    }
    if ($action === 'zoek') {
        $config = thoth_load_config((string) ($_GET['type'] ?? ''));
        $field = thoth_config_fields_by_key($config)[(string) ($_GET['veld'] ?? '')] ?? null;
        if ($field === null || !in_array($field['invoerType'], ['combobox', 'lookup'], true)) {
            thoth_json(['ok' => false, 'fout' => 'Onbekend veld.'], 400);
        }
        $company = thoth_company_by_key((string) ($_GET['bedrijf'] ?? ''), thoth_companies());
        if ($company === null) {
            thoth_json(['ok' => false, 'fout' => 'Kies eerst een bedrijf.'], 400);
        }
        $q = mb_substr((string) ($_GET['q'] ?? ''), 0, 100);
        $context = ['company' => $company['name'], 'environment' => $company['environment'], 'moment' => 'opslaan'];
        $parent = mb_substr((string) ($_GET['ouder'] ?? ''), 0, 250);
        thoth_json(['ok' => true, 'resultaten' => thoth_search_options($field, $q, $context, 20, $parent)]);
    }
    if ($action === 'geo-zoek') {
        thoth_json(['ok' => true, 'resultaten' => thoth_geo_search((string) ($_GET['q'] ?? ''))]);
    }
    if ($action === 'geo-adres') {
        if (!is_numeric($_GET['lat'] ?? null) || !is_numeric($_GET['lon'] ?? null)) {
            thoth_json(['ok' => false, 'fout' => 'Ongeldige coördinaten.'], 400);
        }
        thoth_json(['ok' => true, 'adres' => thoth_geo_reverse((float) $_GET['lat'], (float) $_GET['lon'])]);
    }
    thoth_json(['ok' => false, 'fout' => 'Onbekende actie.'], 400);
} catch (ThothActionException | ThothConfigException $e) {
    thoth_json(['ok' => false, 'fout' => $e->getMessage()], 400);
} catch (Throwable $e) {
    thoth_json(['ok' => false, 'fout' => 'Fout: ' . $e->getMessage()], 500);
}
