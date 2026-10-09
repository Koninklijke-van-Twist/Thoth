<?php

declare(strict_types=1);

/** Regressietests voor de Bugbot-bevindingen op PR #1–#4. */

require __DIR__ . '/_bootstrap.php';

install_test_configs();
$GLOBALS['allowedUsers'] = ['anna@kvt.nl', 'bob@kvt.nl', 'gert@kvt.nl'];
$GLOBALS['approvers'] = ['gert@kvt.nl', 'bob@kvt.nl'];
$GLOBALS['thothLookupChecker'] = static fn (array $field, string $value): bool => $value === 'SL12600001';
$K = 'Koninklijke van Twist';

// PR #1 (High): afwijzen terwijl goedkeuren (BC-insert) loopt mag niet; het verzoek moet niet Afgewezen
// eindigen met een los BC-record en een 'goedgekeurd'-melding.
$r = thoth_save_draft(null, 'servicelocatie', 'anna@kvt.nl', ['Name' => 'Race'], [], $K, 'kvtmdlive_aad');
thoth_submit($r['id'], 'anna@kvt.nl');
$rejectError = null;
$res = thoth_approve($r['id'], 'gert@kvt.nl', static function () use ($r, &$rejectError): array {
    try {
        thoth_reject($r['id'], 'bob@kvt.nl', 'tegelijk');
    } catch (ThothActionException $e) {
        $rejectError = $e->getMessage();
    }
    return ['number' => 'SL12600099'];
});
$after = thoth_get_request($r['id']);
check($rejectError !== null, 'afwijzen tijdens goedkeuren wordt geweigerd');
check($res['ok'] && $after['status'] === THOTH_STATUS_APPROVED && $after['bc_number'] === 'SL12600099', 'goedkeuren wint: Goedgekeurd met BC-nummer');
check(!in_array(THOTH_STATUS_REJECTED, array_column(thoth_history($r['id']), 'to_status'), true), 'geen Afgewezen in de historie');

// Tijdens de BC-call staat het verzoek 'In behandeling'; bij een fout terug naar Ingediend.
$r2 = thoth_save_draft(null, 'servicelocatie', 'anna@kvt.nl', ['Name' => 'Fout'], [], $K, 'kvtmdlive_aad');
thoth_submit($r2['id'], 'anna@kvt.nl');
$during = null;
$res = thoth_approve($r2['id'], 'gert@kvt.nl', static function () use ($r2, &$during): array {
    $during = thoth_get_request($r2['id'])['status'];
    throw new ThothBcException('BC HTTP 400: kapot');
});
check_same('In behandeling', $during, 'claim: In behandeling tijdens BC-insert');
check(!$res['ok'] && thoth_get_request($r2['id'])['status'] === THOTH_STATUS_SUBMITTED, 'BC-fout: terug naar Ingediend');
check(str_contains((string) thoth_get_request($r2['id'])['bc_error'], 'kapot'), 'BC-fout zichtbaar');
check(thoth_approve($r2['id'], 'gert@kvt.nl', static fn () => ['number' => 'SL12600100'])['ok'], 'daarna alsnog goed te keuren');

// Vastgelopen claim (proces gecrasht): lock is vrij, dan mag opnieuw goedkeuren; afwijzen niet.
$r3 = thoth_save_draft(null, 'servicelocatie', 'anna@kvt.nl', ['Name' => 'Crash'], [], $K, 'kvtmdlive_aad');
thoth_submit($r3['id'], 'anna@kvt.nl');
thoth_db()->prepare('UPDATE requests SET status = ? WHERE id = ?')->execute(['In behandeling', $r3['id']]);
check_throws(fn () => thoth_reject($r3['id'], 'bob@kvt.nl', ''), 'ingediende', 'afwijzen alleen vanaf Ingediend');
check(thoth_approve($r3['id'], 'gert@kvt.nl', static fn () => ['number' => 'SL12600101'])['ok'], 'vastgelopen claim kan worden afgerond');

// PR #1 (High): een late autosave mag een ingediend verzoek niet overschrijven.
$r4 = thoth_save_draft(null, 'servicelocatie', 'anna@kvt.nl', ['Name' => 'Ingediende naam'], [], $K, 'kvtmdlive_aad');
$GLOBALS['thothBeforeDraftUpdate'] = static function (int $id) use ($r4): void {
    unset($GLOBALS['thothBeforeDraftUpdate']);
    thoth_submit($r4['id'], 'anna@kvt.nl'); // indienen tussen het lezen en schrijven van de autosave
};
$late = null;
try {
    thoth_save_draft($r4['id'], 'servicelocatie', 'anna@kvt.nl', ['Name' => 'Oude autosave'], [], $K, 'kvtmdlive_aad');
} catch (ThothActionException $e) {
    $late = $e->getMessage();
}
unset($GLOBALS['thothBeforeDraftUpdate']);
$r4now = thoth_get_request($r4['id']);
check_same(THOTH_STATUS_SUBMITTED, $r4now['status'], 'race: verzoek is ingediend');
check_same('Ingediende naam', $r4now['data']['Name'] ?? null, 'late autosave overschrijft ingediende data niet');
check($late !== null, 'late autosave krijgt een melding');

// PR #1 (Medium): onbekende bedrijfssleutel maakt bedrijf/environment niet leeg.
$companies = [['key' => 'kvtmdlive_aad|' . $K, 'name' => $K, 'environment' => 'kvtmdlive_aad', 'label' => $K]];
check_same([$K, 'kvtmdlive_aad'], thoth_company_from_key('kvtmdlive_aad|' . $K, $companies), 'bekende sleutel');
check_same([null, null], thoth_company_from_key('kvtfat_aad|Weg', $companies), 'onbekende sleutel: niets wijzigen');
check_same(['', ''], thoth_company_from_key('', $companies), 'expliciet leeg: wissen');
$r5 = thoth_save_draft(null, 'servicelocatie', 'anna@kvt.nl', ['Name' => 'Bedrijf'], [], $K, 'kvtfat_aad');
$r5b = thoth_save_draft($r5['id'], 'servicelocatie', 'anna@kvt.nl', ['Name' => 'Bedrijf 2'], [], null, null);
check_same([$K, 'kvtfat_aad', 'Bedrijf 2'], [$r5b['company'], $r5b['environment'], $r5b['data']['Name']], 'opslaan met onbekende sleutel houdt bedrijf');
$r5c = thoth_save_draft(null, 'servicelocatie', 'anna@kvt.nl', ['Name' => 'Nieuw'], [], null, null);
check_same(['', ''], [$r5c['company'], $r5c['environment']], 'nieuw verzoek met onbekende sleutel: leeg');

// PR #2 (Medium): afgeleide waarden worden na goedkeuren bewaard.
$cfgFile = getenv('THOTH_CONFIG_DIR') . '/component-config.json';
$orig = (string) file_get_contents($cfgFile);
$cfg = json_decode($orig, true);
$cfg['formFields'][] = ['name' => 'Breedtegraad', 'invoerType' => 'automatisch', 'bc-tabel' => 'Locs', 'bc-kolom' => 'Lat', 'verplicht' => true,
    'afgeleidVan' => ['veld' => 'SL_No', 'bc-tabel' => 'ServiceLocs', 'sleutel-kolom' => 'No', 'kolom' => 'Lat']];
file_put_contents($cfgFile, json_encode($cfg));
touch($cfgFile, time() + 20);
clearstatcache();
$GLOBALS['thothDerivedReader'] = static fn (): array => [['No' => 'SL12600001', 'Lat' => '52.25']];
$c = thoth_save_draft(null, 'component', 'anna@kvt.nl', ['Name' => 'Motor', 'SL_No' => 'SL12600001'], [], $K, 'kvtmdlive_aad');
check_same([], thoth_submit($c['id'], 'anna@kvt.nl'), 'component ingediend');
$payload = null;
$res = thoth_approve($c['id'], 'gert@kvt.nl', static function (array $cfg, string $env, string $company, array $p) use (&$payload): array {
    $payload = $p;
    return ['number' => 'CO100001'];
});
check($res['ok'] && ($payload['Lat'] ?? null) === '52.25', 'afgeleide waarde in de BC-payload');
check_same('52.25', thoth_get_request($c['id'])['data']['Lat'] ?? null, 'afgeleide waarde na goedkeuren bewaard in het verzoek');
file_put_contents($cfgFile, $orig);
touch($cfgFile, time() + 30);
clearstatcache();

finish('bugbot');
