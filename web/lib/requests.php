<?php

declare(strict_types=1);

/**
 * Workflow en rechten. Statusovergangen:
 *   (nieuw)     -> Concept      aanvrager, eerste autosave
 *   Concept     -> Ingediend    aanvrager, als alle verplichte velden en restricties kloppen
 *   Afgewezen   -> Ingediend    aanvrager, idem (Afgewezen blijft bewerkbaar)
 *   Ingediend   -> In behandeling -> Goedgekeurd  approver; de claim (conditionele UPDATE) gaat vóór de
 *                                 BC-insert, zodat afwijzen of bewerken intussen niet kan
 *   Ingediend   -> Afgewezen    approver, optionele reden (alleen vanaf Ingediend)
 * Mislukt de BC-insert, dan gaat het terug naar Ingediend met bc_error.
 * Een vastgelopen 'In behandeling' (proces gecrasht) kan opnieuw worden goedgekeurd: de approve-lock
 * is dan vrij en de gereserveerde nummers worden eerst in BC gecontroleerd.
 */

final class ThothActionException extends RuntimeException
{
}

const THOTH_TRANSITIONS = [
    THOTH_STATUS_CONCEPT => [THOTH_STATUS_SUBMITTED],
    THOTH_STATUS_REJECTED => [THOTH_STATUS_SUBMITTED],
    THOTH_STATUS_SUBMITTED => [THOTH_STATUS_PROCESSING, THOTH_STATUS_REJECTED],
    THOTH_STATUS_PROCESSING => [THOTH_STATUS_APPROVED, THOTH_STATUS_SUBMITTED],
    THOTH_STATUS_APPROVED => [],
];

function thoth_transition_allowed(string $from, string $to): bool
{
    return in_array($to, THOTH_TRANSITIONS[$from] ?? [], true);
}

function thoth_email_in(string $email, mixed $list): bool
{
    $email = strtolower(trim($email));
    if ($email === '' || !is_array($list)) {
        return false;
    }
    foreach ($list as $item) {
        if (strtolower(trim((string) $item)) === $email) {
            return true;
        }
    }

    return false;
}

function thoth_is_allowed(string $email): bool
{
    return thoth_email_in($email, $GLOBALS['allowedUsers'] ?? []);
}

/** Fail-closed: zonder $approvers in auth.php keurt niemand goed. */
function thoth_is_approver(string $email): bool
{
    return thoth_is_allowed($email) && thoth_email_in($email, $GLOBALS['approvers'] ?? []);
}

function thoth_is_owner(array $request, string $email): bool
{
    return strtolower((string) $request['owner']) === strtolower(trim($email));
}

/** Een goedkeurder beoordeelt nooit zijn eigen aanvraag (e-mail hoofdletterongevoelig). */
function thoth_can_decide(array $request, string $email): bool
{
    return thoth_is_approver($email) && !thoth_is_owner($request, $email);
}

function thoth_can_edit(array $request, string $email): bool
{
    return thoth_is_owner($request, $email)
        && in_array($request['status'], [THOTH_STATUS_CONCEPT, THOTH_STATUS_REJECTED], true);
}

function thoth_can_view(array $request, string $email): bool
{
    // Goedkeurders zien alles behalve andermans concepten.
    return thoth_is_owner($request, $email)
        || (thoth_is_approver($email) && $request['status'] !== THOTH_STATUS_CONCEPT);
}

function thoth_request_context(array $request, string $moment): array
{
    return ['company' => (string) $request['company'], 'environment' => (string) $request['environment'], 'labels' => $request['labels'] ?? [], 'moment' => $moment];
}

/**
 * Autosave. Maakt een Concept aan als $id null is. Alleen velden uit de config worden bewaard.
 *
 * @param array<string, mixed> $values
 * @param array<string, string> $labels weergavelabels voor lookupvelden
 */
function thoth_save_draft(?int $id, string $type, string $email, array $values, array $labels, ?string $company, ?string $environment): array
{
    if (!thoth_is_allowed($email)) {
        throw new ThothActionException('Geen toegang.');
    }
    $config = thoth_load_config($type);
    $clean = [];
    $cleanLabels = [];
    foreach ($config['formFields'] as $f) {
        if (array_key_exists($f['key'], $values)) {
            $v = $values[$f['key']];
            $clean[$f['key']] = is_scalar($v) ? mb_substr(trim((string) $v), 0, 1000) : '';
            if (isset($labels[$f['key']]) && is_scalar($labels[$f['key']])) {
                $cleanLabels[$f['key']] = mb_substr((string) $labels[$f['key']], 0, 500);
            }
        }
    }
    $now = thoth_now();
    $db = thoth_db();
    if ($id === null) {
        $company ??= '';
        $environment ??= '';
        $db->prepare('INSERT INTO requests (type, owner, company, environment, status, data_json, labels_json, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$type, strtolower($email), $company, $environment, THOTH_STATUS_CONCEPT, json_encode($clean, JSON_UNESCAPED_UNICODE), json_encode($cleanLabels, JSON_UNESCAPED_UNICODE), $now, $now]);
        $newId = (int) $db->lastInsertId();
        thoth_add_history($newId, strtolower($email), null, THOTH_STATUS_CONCEPT);

        return thoth_get_request($newId);
    }
    $request = thoth_get_request($id);
    if ($request === null || !thoth_can_edit($request, $email)) {
        throw new ThothActionException('Dit verzoek kun je niet (meer) bewerken.');
    }
    if ($request['type'] !== $type) {
        throw new ThothActionException('Verkeerd aanvraagtype.');
    }
    $hook = $GLOBALS['thothBeforeDraftUpdate'] ?? null; // test-hook: race tussen lezen en schrijven
    if (is_callable($hook)) {
        $hook($id);
    }
    // Onbekend bedrijf (null) = laten staan. Alleen schrijven zolang het nog bewerkbaar is
    // (status in de WHERE): een late autosave na indienen overschrijft niets.
    $st = $db->prepare('UPDATE requests SET company = ?, environment = ?, data_json = ?, labels_json = ?, updated_at = ?
        WHERE id = ? AND owner = ? AND status IN (?, ?)');
    $st->execute([$company ?? $request['company'], $environment ?? $request['environment'],
        json_encode($clean + $request['data'], JSON_UNESCAPED_UNICODE), json_encode($cleanLabels + $request['labels'], JSON_UNESCAPED_UNICODE), $now,
        $id, strtolower($email), THOTH_STATUS_CONCEPT, THOTH_STATUS_REJECTED]);
    if ($st->rowCount() !== 1) {
        $fresh = thoth_get_request($id);
        if ($fresh === null || !thoth_can_edit($fresh, $email)) {
            throw new ThothActionException('Dit verzoek kun je niet (meer) bewerken; het is intussen ingediend of beoordeeld.');
        }
        throw new ThothActionException('Opslaan mislukt; herlaad de pagina.');
    }

    return thoth_get_request($id);
}

/**
 * Inzenden. Geeft veldfouten terug ([] = ingediend).
 *
 * @return array<string, string>
 */
function thoth_submit(int $id, string $email): array
{
    $request = thoth_get_request($id);
    if ($request === null || !thoth_can_edit($request, $email)) {
        throw new ThothActionException('Dit verzoek kun je niet indienen.');
    }
    if (trim((string) $request['company']) === '' || trim((string) $request['environment']) === '') {
        return ['_bedrijf' => 'Kies eerst een bedrijf.'];
    }
    $config = thoth_load_config($request['type']);
    $errors = thoth_validate_values($config, $request['data'], thoth_request_context($request, 'indienen'));
    if ($errors !== []) {
        return $errors;
    }
    $from = $request['status'];
    if (!thoth_transition_allowed($from, THOTH_STATUS_SUBMITTED)) {
        throw new ThothActionException('Statusovergang niet toegestaan.');
    }
    $db = thoth_db();
    $st = $db->prepare('UPDATE requests SET status = ?, submitted_at = ?, updated_at = ?, bc_error = NULL WHERE id = ? AND status = ?');
    $st->execute([THOTH_STATUS_SUBMITTED, thoth_now(), thoth_now(), $id, $from]);
    if ($st->rowCount() !== 1) {
        throw new ThothActionException('Het verzoek is intussen gewijzigd. Herlaad de pagina.');
    }
    thoth_add_history($id, strtolower($email), $from, THOTH_STATUS_SUBMITTED);

    return [];
}

function thoth_reject(int $id, string $approver, string $reason): void
{
    if (!thoth_is_approver($approver)) {
        throw new ThothActionException('Alleen goedkeurders mogen afwijzen.');
    }
    $own = thoth_get_request($id);
    if ($own !== null && thoth_is_owner($own, $approver)) {
        throw new ThothActionException('Je kunt je eigen aanvraag niet afwijzen; een andere goedkeurder moet hem beoordelen.');
    }
    $reason = mb_substr(trim($reason), 0, 2000);
    $st = thoth_db()->prepare('UPDATE requests SET status = ?, reject_reason = ?, decided_by = ?, decided_at = ?, updated_at = ? WHERE id = ? AND status = ?');
    $st->execute([THOTH_STATUS_REJECTED, $reason !== '' ? $reason : null, strtolower($approver), thoth_now(), thoth_now(), $id, THOTH_STATUS_SUBMITTED]);
    if ($st->rowCount() !== 1) {
        throw new ThothActionException('Alleen ingediende verzoeken kunnen worden afgewezen.');
    }
    thoth_add_history($id, strtolower($approver), THOTH_STATUS_SUBMITTED, THOTH_STATUS_REJECTED, $reason !== '' ? $reason : null);
}

/**
 * Goedkeuren = direct aanmaken in BC. Lukt dat niet, dan blijft het Ingediend met bc_error.
 *
 * @param callable|null $inserter fn(array $config, string $env, string $company, array $payload): array{number:string}
 * @return array{ok:bool, number?:string, error?:string, fields?:array}
 */
function thoth_approve(int $id, string $approver, ?callable $inserter = null): array
{
    if (!thoth_is_approver($approver)) {
        throw new ThothActionException('Alleen goedkeurders mogen goedkeuren.');
    }
    $inserter ??= static fn (array $c, string $env, string $company, array $payload, array $options): array
        => thoth_bc_insert($c, $env, $company, $payload, null, $options);
    $lock = fopen(thoth_data_dir() . '/approve-' . $id . '.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new ThothActionException('Dit verzoek wordt op dit moment al goedgekeurd.');
    }
    try {
        $request = thoth_get_request($id);
        // 'In behandeling' met vrije lock = vastgelopen eerdere poging: mag worden afgerond.
        if ($request === null || !in_array($request['status'], [THOTH_STATUS_SUBMITTED, THOTH_STATUS_PROCESSING], true)) {
            throw new ThothActionException('Alleen ingediende verzoeken kunnen worden goedgekeurd.');
        }
        if (thoth_is_owner($request, $approver)) {
            throw new ThothActionException('Je kunt je eigen aanvraag niet goedkeuren; een andere goedkeurder moet hem beoordelen.');
        }
        $config = thoth_load_config($request['type']);
        if ($config['bcGeblokkeerd'] !== null) {
            $msg = 'Aanmaken in BC geblokkeerd: ' . $config['bcGeblokkeerd'];
            thoth_record_bc_error($id, $msg);
            return ['ok' => false, 'error' => $msg];
        }
        // Atomaire claim vóór de BC-call: lukt alleen als niemand intussen heeft afgewezen.
        $claim = thoth_db()->prepare('UPDATE requests SET status = ?, updated_at = ? WHERE id = ? AND status IN (?, ?)');
        $claim->execute([THOTH_STATUS_PROCESSING, thoth_now(), $id, THOTH_STATUS_SUBMITTED, THOTH_STATUS_PROCESSING]);
        if ($claim->rowCount() !== 1) {
            throw new ThothActionException('Dit verzoek is intussen beoordeeld door een andere goedkeurder.');
        }
        $release = static function (string $msg) use ($id): void {
            thoth_db()->prepare('UPDATE requests SET status = ?, bc_error = ?, updated_at = ? WHERE id = ? AND status = ?')
                ->execute([THOTH_STATUS_SUBMITTED, $msg, thoth_now(), $id, THOTH_STATUS_PROCESSING]);
        };
        $context = thoth_request_context($request, 'goedkeuren');
        try {
            [$data, $derivedErrors] = thoth_resolve_derived($config, $request['data'], $context);
            $fieldErrors = $derivedErrors + thoth_validate_values($config, $data, $context);
            if ($fieldErrors !== []) {
                $msg = 'Validatie mislukt: ' . implode(' ', $fieldErrors);
                $release($msg);
                return ['ok' => false, 'error' => $msg, 'fields' => $fieldErrors];
            }
            $payload = thoth_build_bc_payload($config, $data, $context);
            $reserved = $request['bc_reserved'];
            $options = [
                'check_first' => $reserved,
                'on_reserve' => static function (string $number) use ($id, &$reserved): void {
                    if (!in_array($number, $reserved, true)) {
                        $reserved[] = $number;
                        thoth_db()->prepare('UPDATE requests SET bc_reserved_json = ? WHERE id = ?')
                            ->execute([json_encode(array_slice($reserved, -20)), $id]);
                    }
                },
            ];
            $result = $inserter($config, (string) $request['environment'], (string) $request['company'], $payload, $options);
        } catch (Throwable $e) {
            $msg = 'Aanmaken in BC mislukt: ' . $e->getMessage();
            $release($msg);
            return ['ok' => false, 'error' => $msg];
        }
        $number = (string) $result['number'];
        // Afgeleide waarden (coördinaten, Omschrijving 2) bewaren zoals ze naar BC zijn gestuurd.
        $done = thoth_db()->prepare('UPDATE requests SET status = ?, bc_number = ?, bc_error = NULL, data_json = ?, decided_by = ?, decided_at = ?, updated_at = ? WHERE id = ? AND status = ?');
        $done->execute([THOTH_STATUS_APPROVED, $number, json_encode($data, JSON_UNESCAPED_UNICODE), strtolower($approver), thoth_now(), thoth_now(), $id, THOTH_STATUS_PROCESSING]);
        if ($done->rowCount() !== 1) {
            // Kan alleen bij handmatig ingrijpen in de database; BC-record bestaat wel.
            thoth_record_bc_error($id, 'Aangemaakt in BC als ' . $number . ', maar de status kon niet worden bijgewerkt.');
            return ['ok' => false, 'error' => 'Aangemaakt in BC als ' . $number . ', maar het verzoek was intussen gewijzigd. Controleer het verzoek.'];
        }
        thoth_add_history($id, strtolower($approver), THOTH_STATUS_SUBMITTED, THOTH_STATUS_APPROVED, 'BC-nummer ' . $number
            . (($result['recovered'] ?? false) ? ' (na time-out teruggevonden in BC, geen tweede POST)' : ''));

        return ['ok' => true, 'number' => $number];
    } finally {
        // Lockbestand niet verwijderen: unlink na unlock laat een tweede proces een nieuw bestand locken.
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function thoth_record_bc_error(int $id, string $message): void
{
    thoth_db()->prepare('UPDATE requests SET bc_error = ?, updated_at = ? WHERE id = ?')->execute([$message, thoth_now(), $id]);
}

/** Overzicht van de aanvrager: afgewezen apart bovenaan. */
function thoth_user_overview(string $email): array
{
    $all = thoth_list_requests('owner = ?', [strtolower($email)]);
    $rejected = array_values(array_filter($all, static fn ($r) => $r['status'] === THOTH_STATUS_REJECTED));
    $rest = array_values(array_filter($all, static fn ($r) => $r['status'] !== THOTH_STATUS_REJECTED));

    return ['afgewezen' => $rejected, 'overig' => $rest];
}

/** Korte titel van een verzoek: eerste ingevulde veld. */
function thoth_request_title(array $request): string
{
    try {
        $config = thoth_load_config($request['type']);
        foreach ($config['formFields'] as $f) {
            $v = trim((string) ($request['labels'][$f['key']] ?? $request['data'][$f['key']] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }
    } catch (Throwable $ignored) {
    }

    return '(nog leeg)';
}

/* ---------- Bedrijven ---------- */

const THOTH_COMPANY_TTL = 86400;
const THOTH_LIVE_ENVIRONMENTS = ['kvtmdlive_aad', 'kvtgermanylive_aad'];

function thoth_company_key(string $environment, string $name): string
{
    return $environment . '|' . $name;
}

/**
 * Bedrijvenlijst over alle environments (Mímir, 24 uur cache in data/companies.json).
 * Testomgevingen (kvtfat_aad, kvtfat2_aad) staan erin met "(test: …)" in het label.
 *
 * @return list<array{key:string,name:string,environment:string,label:string}>
 */
function thoth_companies(bool $refresh = false, ?callable $discover = null): array
{
    $path = thoth_data_dir() . '/companies.json';
    $cached = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    $rows = is_array($cached['companies'] ?? null) ? $cached['companies'] : [];
    $fresh = $rows !== [] && (time() - (int) ($cached['fetched_at'] ?? 0)) < THOTH_COMPANY_TTL;
    if (!$fresh || $refresh) {
        try {
            $found = ($discover ?? 'thoth_bc_discover_companies')();
            if ($found !== []) {
                $rows = $found;
                file_put_contents($path, json_encode(['fetched_at' => time(), 'companies' => $rows], JSON_UNESCAPED_UNICODE), LOCK_EX);
            }
        } catch (Throwable $ignored) {
        }
    }
    $out = [];
    foreach ($rows as $row) {
        $name = trim((string) ($row['name'] ?? ''));
        $env = trim((string) ($row['environment'] ?? ''));
        if ($name === '' || $env === '') {
            continue;
        }
        $isLive = in_array(strtolower($env), THOTH_LIVE_ENVIRONMENTS, true);
        $out[thoth_company_key($env, $name)] = [
            'key' => thoth_company_key($env, $name),
            'name' => $name,
            'environment' => $env,
            'label' => $isLive ? $name : $name . ' (test: ' . $env . ')',
            'live' => $isLive,
        ];
    }
    $out = array_values($out);
    usort($out, static fn ($a, $b) => [$b['live'], $a['label']] <=> [$a['live'], $b['label']]);

    return $out;
}

/**
 * Bedrijf uit de formulierwaarde voor opslaan: [naam, environment].
 * Leeg = wissen (['','']); onbekende sleutel (bv. bedrijf niet meer in de lijst) = niet wijzigen ([null, null]).
 *
 * @return array{0: ?string, 1: ?string}
 */
function thoth_company_from_key(string $key, array $companies): array
{
    if (trim($key) === '') {
        return ['', ''];
    }
    $company = thoth_company_by_key($key, $companies);

    return $company === null ? [null, null] : [$company['name'], $company['environment']];
}

function thoth_company_by_key(string $key, array $companies): ?array
{
    foreach ($companies as $c) {
        if ($c['key'] === $key) {
            return $c;
        }
    }

    return null;
}
