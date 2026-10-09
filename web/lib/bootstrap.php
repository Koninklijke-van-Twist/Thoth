<?php

declare(strict_types=1);

/**
 * Include op topniveau van elke pagina. auth.php zet globale variabelen
 * ($allowedUsers, $approvers, $auth_list, $baseUrl, $mimirApi), die moeten globaal blijven.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/numbering.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/bc.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/requests.php';
require_once __DIR__ . '/geo.php';
require_once __DIR__ . '/layout.php';

$thothAuthFile = getenv('THOTH_AUTH_FILE') ?: __DIR__ . '/../auth.php';
if (!is_file($thothAuthFile)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Thoth is nog niet ingericht: web/auth.php ontbreekt. Kopieer auth.example.php naar auth.php en vul het in.";
    exit;
}
require_once $thothAuthFile;
require_once __DIR__ . '/../logincheck.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$thothUser = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
if (!thoth_is_allowed($thothUser)) {
    http_response_code(403);
    echo 'Geen toegang.';
    exit;
}

// CSP: alles van Thoth zelf; alleen kaarttegels van OpenStreetMap (Nominatim loopt via api.php).
// Referer meesturen naar de tegelserver is vereist volgens de OSM tile policy.
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https://tile.openstreetmap.org; connect-src 'self'; form-action 'self'; frame-ancestors 'self'; base-uri 'self'; object-src 'none'");
header('Referrer-Policy: strict-origin-when-cross-origin');
