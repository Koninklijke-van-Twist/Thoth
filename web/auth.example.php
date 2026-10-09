<?php

/**
 * Kopieer naar web/auth.php op de server (en lokaal). NOOIT committen:
 * web/auth.php en web/cfg.php staan in .gitignore en de deploy slaat auth.php over.
 */

// Wie mag Thoth gebruiken (aanvragen indienen). Lokaal (php -S) wordt de eerste gebruiker ingelogd.
$allowedUsers = [
    'gebruiker@kvt.nl',
];

// Wie mag ingediende verzoeken goedkeuren of afwijzen. Moet ook in $allowedUsers staan.
// Weglaten of [] = niemand keurt goed (fail-closed).
$approvers = [
    // 'goedkeurder@kvt.nl',
];

// --- Mímir (lezen: bedrijvenlijst, opties, lookups) ---
// $mimirApi  = 'mimir_…';
// $mimirBase = 'https://sleutels.kvt.nl/mimir/api'; // optioneel

// --- Business Central OData (schrijven bij goedkeuren, en leesterugval) ---
// Het environment wordt per bedrijf gekozen (bedrijvenlijst), nooit op volgorde van deze sleutels.
$baseUrl = 'https://kvtmd365.kvt.nl:7148/';
$auth_list = [
    'kvtmdlive_aad'      => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD'], // KVT/HVT live
    'kvtgermanylive_aad' => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD'], // KVT Germany
    'kvtfat_aad'         => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD'], // test
    'kvtfat2_aad'        => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD'], // test
];
