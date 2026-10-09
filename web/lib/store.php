<?php

declare(strict_types=1);

/**
 * SQLite-opslag in web/data/thoth.sqlite (gitignored, via .htaccess afgeschermd).
 *
 * requests        één verzoek per rij; velden als JSON (data_json, labels_json).
 * status_history  wie, wanneer, van welke naar welke status, reden.
 * user_prefs      gekozen bedrijf per gebruiker.
 */

const THOTH_STATUS_CONCEPT = 'Concept';
const THOTH_STATUS_SUBMITTED = 'Ingediend';
const THOTH_STATUS_APPROVED = 'Goedgekeurd';
const THOTH_STATUS_REJECTED = 'Afgewezen';
/** Tijdelijk tijdens goedkeuren (BC-insert loopt); afwijzen of bewerken kan dan niet. */
const THOTH_STATUS_PROCESSING = 'In behandeling';

function thoth_data_dir(): string
{
    $override = getenv('THOTH_DATA_DIR');
    $dir = is_string($override) && trim($override) !== '' ? rtrim(trim($override), '/') : dirname(__DIR__) . '/data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    // De deploy slaat data/ over; zorg zelf dat de map nooit uitgeleverd wordt.
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
    }

    return $dir;
}

function thoth_db(): PDO
{
    static $pdo = null;
    static $path = null;
    $wanted = thoth_data_dir() . '/thoth.sqlite';
    if ($pdo instanceof PDO && $path === $wanted) {
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . $wanted, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $path = $wanted;
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('CREATE TABLE IF NOT EXISTS requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        type TEXT NOT NULL,
        owner TEXT NOT NULL,
        company TEXT NOT NULL DEFAULT \'\',
        environment TEXT NOT NULL DEFAULT \'\',
        status TEXT NOT NULL,
        data_json TEXT NOT NULL DEFAULT \'{}\',
        labels_json TEXT NOT NULL DEFAULT \'{}\',
        bc_number TEXT,
        bc_error TEXT,
        reject_reason TEXT,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        submitted_at TEXT,
        decided_by TEXT,
        decided_at TEXT
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_requests_owner ON requests(owner, status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_requests_status ON requests(status)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS status_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        request_id INTEGER NOT NULL REFERENCES requests(id) ON DELETE CASCADE,
        actor TEXT NOT NULL,
        from_status TEXT,
        to_status TEXT NOT NULL,
        reason TEXT,
        at TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS user_prefs (
        email TEXT PRIMARY KEY,
        company TEXT NOT NULL DEFAULT \'\',
        environment TEXT NOT NULL DEFAULT \'\'
    )');
    thoth_migrate($pdo);

    return $pdo;
}

/** Schemaversie in PRAGMA user_version; verhogen bij elke schemawijziging. */
const THOTH_SCHEMA_VERSION = 1;

/**
 * Migraties in één BEGIN IMMEDIATE-transactie (les van Mímir): gelijktijdige
 * requests na een deploy wachten op elkaar in plaats van allebei ALTER TABLE
 * te doen ("duplicate column name"). Op de huidige versie draait er geen DDL.
 */
function thoth_migrate(PDO $pdo): void
{
    if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() >= THOTH_SCHEMA_VERSION) {
        return;
    }
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
        if ($version < 1) {
            $cols = array_column($pdo->query('PRAGMA table_info(requests)')->fetchAll(), 'name');
            if (!in_array('bc_reserved_json', $cols, true)) {
                // Nummers die Thoth bij een onduidelijke BC-fout al naar BC stuurde.
                $pdo->exec("ALTER TABLE requests ADD COLUMN bc_reserved_json TEXT NOT NULL DEFAULT '[]'");
            }
        }
        $pdo->exec('PRAGMA user_version = ' . THOTH_SCHEMA_VERSION);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ROLLBACK');
        } catch (Throwable) {
        }
        throw $e;
    }
}

function thoth_now(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('Europe/Amsterdam')))->format('Y-m-d H:i:s');
}

function thoth_decode_request(?array $row): ?array
{
    if ($row === null) {
        return null;
    }
    $row['id'] = (int) $row['id'];
    $row['data'] = json_decode((string) $row['data_json'], true) ?: [];
    $row['labels'] = json_decode((string) $row['labels_json'], true) ?: [];
    $row['bc_reserved'] = array_values(array_filter((array) (json_decode((string) ($row['bc_reserved_json'] ?? '[]'), true) ?: []), 'is_string'));

    return $row;
}

function thoth_get_request(int $id): ?array
{
    $st = thoth_db()->prepare('SELECT * FROM requests WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();

    return thoth_decode_request($row === false ? null : $row);
}

/** @return list<array> */
function thoth_list_requests(string $where = '1=1', array $params = [], string $order = 'updated_at DESC'): array
{
    $st = thoth_db()->prepare('SELECT * FROM requests WHERE ' . $where . ' ORDER BY ' . $order);
    $st->execute($params);

    return array_map('thoth_decode_request', $st->fetchAll());
}

function thoth_add_history(int $requestId, string $actor, ?string $from, string $to, ?string $reason = null): void
{
    thoth_db()->prepare('INSERT INTO status_history (request_id, actor, from_status, to_status, reason, at) VALUES (?,?,?,?,?,?)')
        ->execute([$requestId, $actor, $from, $to, $reason, thoth_now()]);
}

/** @return list<array> */
function thoth_history(int $requestId): array
{
    $st = thoth_db()->prepare('SELECT * FROM status_history WHERE request_id = ? ORDER BY id ASC');
    $st->execute([$requestId]);

    return $st->fetchAll();
}

function thoth_get_pref(string $email): array
{
    $st = thoth_db()->prepare('SELECT company, environment FROM user_prefs WHERE email = ?');
    $st->execute([strtolower($email)]);
    $row = $st->fetch();

    return is_array($row) ? $row : ['company' => '', 'environment' => ''];
}

function thoth_set_pref(string $email, string $company, string $environment): void
{
    thoth_db()->prepare('INSERT INTO user_prefs (email, company, environment) VALUES (?,?,?)
        ON CONFLICT(email) DO UPDATE SET company = excluded.company, environment = excluded.environment')
        ->execute([strtolower($email), $company, $environment]);
}
