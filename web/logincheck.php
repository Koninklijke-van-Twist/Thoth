<?php

/**
 * Gedeelde sleutels.kvt.nl-login, zoals Consus/Kothar.
 * Lokaal (php -S, 127.0.0.1) wordt de eerste $allowedUsers-gebruiker ingelogd.
 * Op de server regelt ../login/lib.php de sessie en geeft ../login/403.php een weigering.
 */

function thoth_is_trusted_requester(): bool
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    $server = $_SERVER['SERVER_ADDR'] ?? '';
    if ($remote !== '' && $remote === $server) {
        return true;
    }

    return in_array($remote, ['127.0.0.1', '::1'], true);
}

if (thoth_is_trusted_requester()) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $currentEmail = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
    $defaultAllowedUser = strtolower(trim((string) ($allowedUsers[0] ?? '')));
    if ($currentEmail === '' && $defaultAllowedUser !== '') {
        if (!is_array($_SESSION['user'] ?? null)) {
            $_SESSION['user'] = [];
        }
        $_SESSION['user']['email'] = $defaultAllowedUser;
    }
} else {
    require __DIR__ . '/../login/lib.php';

    if (!array_any((array) ($allowedUsers ?? []), static function ($email) {
        return strtolower((string) $email) === strtolower((string) ($_SESSION['user']['email'] ?? ''));
    })) {
        require __DIR__ . '/../login/403.php';
        die();
    }
}
