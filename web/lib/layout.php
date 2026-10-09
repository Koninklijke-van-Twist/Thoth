<?php

declare(strict_types=1);

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function thoth_csrf_token(): string
{
    if (empty($_SESSION['thoth_csrf'])) {
        $_SESSION['thoth_csrf'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['thoth_csrf'];
}

function thoth_csrf_valid(?string $token): bool
{
    return is_string($token) && !empty($_SESSION['thoth_csrf']) && hash_equals((string) $_SESSION['thoth_csrf'], $token);
}

/** Stopt elke POST zonder geldig CSRF-token. */
function thoth_require_csrf(): void
{
    $token = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!thoth_csrf_valid(is_string($token) ? $token : null)) {
        http_response_code(400);
        echo 'Ongeldig of verlopen formulier (CSRF). Herlaad de pagina.';
        exit;
    }
}

function thoth_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(thoth_csrf_token()) . '">';
}

function thoth_flash(?string $message = null, string $kind = 'ok'): ?array
{
    if ($message !== null) {
        $_SESSION['thoth_flash'] = ['message' => $message, 'kind' => $kind];
        return null;
    }
    $flash = $_SESSION['thoth_flash'] ?? null;
    unset($_SESSION['thoth_flash']);

    return is_array($flash) ? $flash : null;
}

function thoth_redirect(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

function thoth_status_pill(string $status): string
{
    $class = match ($status) {
        THOTH_STATUS_CONCEPT => 'concept',
        THOTH_STATUS_SUBMITTED => 'ingediend',
        THOTH_STATUS_APPROVED => 'goedgekeurd',
        THOTH_STATUS_REJECTED => 'afgewezen',
        default => '',
    };

    return '<span class="pill pill-' . $class . '">' . h($status) . '</span>';
}

function thoth_header(string $title, string $active = ''): void
{
    global $thothUser;
    $flash = thoth_flash();
    $isApprover = thoth_is_approver((string) $thothUser);
    echo '<!doctype html><html lang="nl"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . h($title) . ' · Thoth</title>'
        . '<link rel="stylesheet" href="assets/app.css?v=1"></head><body>'
        . '<header class="top"><div class="wrap top-inner"><a class="brand" href="index.php">Thoth</a><nav>'
        . '<a href="index.php"' . ($active === 'overzicht' ? ' class="active"' : '') . '>Mijn verzoeken</a>'
        . ($isApprover ? '<a href="goedkeuren.php"' . ($active === 'goedkeuren' ? ' class="active"' : '') . '>Goedkeuren</a>' : '')
        . '</nav><span class="user">' . h($thothUser) . '</span></div></header><main class="wrap">';
    if ($flash !== null) {
        echo '<div class="flash flash-' . h($flash['kind']) . '">' . nl2br(h($flash['message'])) . '</div>';
    }
}

function thoth_footer(): void
{
    echo '</main><script src="assets/app.js?v=1"></script></body></html>';
}
