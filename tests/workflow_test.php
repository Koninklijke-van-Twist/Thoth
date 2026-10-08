<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

install_test_configs();
$GLOBALS['allowedUsers'] = ['anna@kvt.nl', 'bob@kvt.nl', 'gert@kvt.nl'];
$GLOBALS['approvers'] = ['gert@kvt.nl', 'niet-toegelaten@kvt.nl'];
$GLOBALS['thothLookupChecker'] = static fn (array $field, string $value): bool => $value === 'SL12600001';

// Rechten
check(thoth_is_allowed('Anna@KVT.nl'), 'allowed hoofdletterongevoelig');
check(!thoth_is_allowed('x@kvt.nl'), 'onbekende gebruiker geen toegang');
check(thoth_is_approver('gert@kvt.nl'), 'gert keurt goed');
check(!thoth_is_approver('anna@kvt.nl'), 'anna keurt niet goed');
check(!thoth_is_approver('niet-toegelaten@kvt.nl'), 'approver moet ook allowed zijn');
check_throws(fn () => thoth_save_draft(null, 'servicelocatie', 'x@kvt.nl', [], [], 'KVT', 'kvtmdlive_aad'), 'Geen toegang', 'buitenstaander kan niet opslaan');
$saved = $GLOBALS['approvers'];
unset($GLOBALS['approvers']);
check(!thoth_is_approver('gert@kvt.nl'), 'zonder $approvers keurt niemand goed (fail-closed)');
$GLOBALS['approvers'] = $saved;

// Concept aanmaken (autosave) en bijwerken
$r = thoth_save_draft(null, 'servicelocatie', 'anna@kvt.nl', ['Name' => '', 'Onbekend' => 'x'], [], 'Koninklijke van Twist', 'kvtmdlive_aad');
check_same(THOTH_STATUS_CONCEPT, $r['status'], 'nieuw = Concept');
check(!array_key_exists('Onbekend', $r['data']), 'onbekende velden niet bewaard');
check_same(1, count(thoth_history($r['id'])), 'historie bij aanmaken');
check_throws(fn () => thoth_save_draft($r['id'], 'servicelocatie', 'bob@kvt.nl', ['Name' => 'kaping'], [], 'X', 'kvtmdlive_aad'), 'niet', 'ander mag concept niet bewerken');

// Inzendvoorwaarde: verplichte velden
$errors = thoth_submit($r['id'], 'anna@kvt.nl');
check(isset($errors['Name']), 'verplicht veld leeg -> niet indienen');
check_same(THOTH_STATUS_CONCEPT, thoth_get_request($r['id'])['status'], 'status blijft Concept');
thoth_save_draft($r['id'], 'servicelocatie', 'anna@kvt.nl', ['Name' => 'Gemaal', 'Qty' => 'abc'], [], 'Koninklijke van Twist', 'kvtmdlive_aad');
check(isset(thoth_submit($r['id'], 'anna@kvt.nl')['Qty']), 'nummer-veld moet getal zijn');
thoth_save_draft($r['id'], 'servicelocatie', 'anna@kvt.nl', ['Qty' => '3'], [], 'Koninklijke van Twist', 'kvtmdlive_aad');
check_throws(fn () => thoth_submit($r['id'], 'bob@kvt.nl'), 'niet indienen', 'ander mag niet indienen');
$noCompany = thoth_save_draft(null, 'servicelocatie', 'anna@kvt.nl', ['Name' => 'X'], [], '', '');
check(isset(thoth_submit($noCompany['id'], 'anna@kvt.nl')['_bedrijf']), 'zonder bedrijf niet indienen');
check_same([], thoth_submit($r['id'], 'anna@kvt.nl'), 'alles gevuld -> ingediend');
check_same(THOTH_STATUS_SUBMITTED, thoth_get_request($r['id'])['status'], 'status Ingediend');
check_throws(fn () => thoth_save_draft($r['id'], 'servicelocatie', 'anna@kvt.nl', ['Name' => 'Later'], [], 'K', 'kvtmdlive_aad'), 'niet', 'ingediend is niet meer bewerkbaar');

// Afwijzen
check_throws(fn () => thoth_reject($r['id'], 'anna@kvt.nl', 'zelf'), 'goedkeurders', 'aanvrager kan niet afwijzen');
thoth_reject($r['id'], 'gert@kvt.nl', 'Adres ontbreekt');
$rej = thoth_get_request($r['id']);
check_same(THOTH_STATUS_REJECTED, $rej['status'], 'Afgewezen');
check_same('Adres ontbreekt', $rej['reject_reason'], 'reden bewaard');
$overview = thoth_user_overview('anna@kvt.nl');
check_same([$r['id']], array_column($overview['afgewezen'], 'id'), 'afgewezen apart in overzicht');
check(!in_array($r['id'], array_column($overview['overig'], 'id'), true), 'afgewezen niet dubbel');
check_throws(fn () => thoth_reject($r['id'], 'gert@kvt.nl', ''), 'ingediende', 'afgewezen kan niet nog eens afgewezen');

// Afgewezen blijft bewerkbaar en kan opnieuw ingediend worden
$edited = thoth_save_draft($r['id'], 'servicelocatie', 'anna@kvt.nl', ['Name' => 'Gemaal De Hoek'], [], 'Koninklijke van Twist', 'kvtmdlive_aad');
check_same(THOTH_STATUS_REJECTED, $edited['status'], 'bewerken houdt status Afgewezen');
check_same([], thoth_submit($r['id'], 'anna@kvt.nl'), 'opnieuw indienen');

// Goedkeuren: BC mislukt -> blijft Ingediend met fout
check_throws(fn () => thoth_approve($r['id'], 'anna@kvt.nl'), 'goedkeurders', 'aanvrager kan niet goedkeuren');
$fail = thoth_approve($r['id'], 'gert@kvt.nl', static function () { throw new ThothBcException('BC HTTP 400: Field X ongeldig'); });
check(!$fail['ok'] && str_contains($fail['error'], 'Field X ongeldig'), 'duidelijke foutmelding');
$after = thoth_get_request($r['id']);
check_same(THOTH_STATUS_SUBMITTED, $after['status'], 'na BC-fout blijft Ingediend');
check(str_contains((string) $after['bc_error'], 'Field X'), 'bc_error bewaard');
check_same(null, $after['bc_number'], 'geen nummer na fout');

// Goedkeuren: BC lukt
$payloadSeen = null;
$ok = thoth_approve($r['id'], 'gert@kvt.nl', static function (array $cfg, string $env, string $company, array $payload) use (&$payloadSeen) {
    $payloadSeen = [$env, $company, $payload];
    return ['number' => 'SL12600001'];
});
check($ok['ok'] && $ok['number'] === 'SL12600001', 'goedgekeurd');
check_same(['kvtmdlive_aad', 'Koninklijke van Twist', ['Name' => 'Gemaal De Hoek', 'Qty' => 3]], $payloadSeen, 'payload, environment en bedrijf van het verzoek');
$done = thoth_get_request($r['id']);
check_same(THOTH_STATUS_APPROVED, $done['status'], 'status Goedgekeurd');
check_same('SL12600001', $done['bc_number'], 'BC-nummer bewaard');
check_same(null, $done['bc_error'], 'fout gewist');
check_throws(fn () => thoth_approve($r['id'], 'gert@kvt.nl', static fn () => ['number' => 'X']), 'ingediende', 'niet twee keer goedkeuren');
check_throws(fn () => thoth_save_draft($r['id'], 'servicelocatie', 'anna@kvt.nl', ['Name' => 'x'], [], 'K', 'e'), 'niet', 'goedgekeurd niet bewerkbaar');

// Historie
$hist = array_map(static fn ($h) => [$h['from_status'], $h['to_status']], thoth_history($r['id']));
check_same([[null, 'Concept'], ['Concept', 'Ingediend'], ['Ingediend', 'Afgewezen'], ['Afgewezen', 'Ingediend'], ['Ingediend', 'Goedgekeurd']], $hist, 'volledige statushistorie');
check_same('gert@kvt.nl', thoth_history($r['id'])[2]['actor'], 'wie staat in historie');
check_same('Adres ontbreekt', thoth_history($r['id'])[2]['reason'], 'reden in historie');

// Statusmachine
check(thoth_transition_allowed('Concept', 'Ingediend') && !thoth_transition_allowed('Concept', 'Goedgekeurd'), 'Concept kan niet direct naar Goedgekeurd');
check(!thoth_transition_allowed('Goedgekeurd', 'Afgewezen'), 'Goedgekeurd is eindstatus');

// Strikte lookup: niet-bestaande waarde blokkeert indienen en goedkeuren
$comp = thoth_save_draft(null, 'component', 'bob@kvt.nl', ['Name' => 'Motor', 'SL_No' => 'BESTAAT-NIET'], ['SL_No' => 'nep'], 'Koninklijke van Twist', 'kvtmdlive_aad');
check(isset(thoth_submit($comp['id'], 'bob@kvt.nl')['SL_No']), 'lookup met onbekende waarde kan niet worden ingediend');
thoth_save_draft($comp['id'], 'component', 'bob@kvt.nl', ['SL_No' => 'SL12600001'], ['SL_No' => 'SL12600001 · Gemaal'], 'Koninklijke van Twist', 'kvtmdlive_aad');
check_same([], thoth_submit($comp['id'], 'bob@kvt.nl'), 'bestaande lookup-waarde mag');
check_same('SL12600001 · Gemaal', thoth_get_request($comp['id'])['labels']['SL_No'], 'label voor weergave bewaard');
$GLOBALS['thothLookupChecker'] = static fn (): bool => false; // intussen verwijderd in BC
$called = false;
$res = thoth_approve($comp['id'], 'gert@kvt.nl', static function () use (&$called) { $called = true; return ['number' => 'X']; });
check(!$res['ok'] && !$called, 'goedkeuren valideert de lookup opnieuw en schrijft dan niets naar BC');
check_same(THOTH_STATUS_SUBMITTED, thoth_get_request($comp['id'])['status'], 'blijft Ingediend');

// Inzage
$concept = thoth_save_draft(null, 'servicelocatie', 'anna@kvt.nl', [], [], 'K', 'kvtmdlive_aad');
check(thoth_can_view($concept, 'anna@kvt.nl'), 'eigenaar ziet concept');
check(!thoth_can_view($concept, 'gert@kvt.nl'), 'goedkeurder ziet andermans concept niet');
check(!thoth_can_view($concept, 'bob@kvt.nl'), 'collega ziet andermans concept niet');
check(thoth_can_view(thoth_get_request($comp['id']), 'gert@kvt.nl'), 'goedkeurder ziet ingediend verzoek');

finish('workflow');
