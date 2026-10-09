<?php

declare(strict_types=1);

/**
 * Gedeelde testopzet: tijdelijke datamap, libs laden zonder login, mini-asserts.
 * Draaien: php tests/<naam>_test.php (of: for f in tests/*_test.php; do php "$f" || exit 1; done)
 */

$tmp = sys_get_temp_dir() . '/thoth-test-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir($tmp, 0777, true);
putenv('THOTH_DATA_DIR=' . $tmp);
register_shutdown_function(static function () use ($tmp): void {
    foreach (glob($tmp . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
    @rmdir($tmp);
});

$web = dirname(__DIR__) . '/web/lib';
require_once $web . '/config.php';
require_once $web . '/numbering.php';
require_once $web . '/store.php';
require_once $web . '/bc.php';
require_once $web . '/validation.php';
require_once $web . '/requests.php';

$GLOBALS['thothTestCount'] = 0;
$GLOBALS['thothTestFailures'] = 0;

function check(bool $cond, string $message): void
{
    $GLOBALS['thothTestCount']++;
    if (!$cond) {
        $GLOBALS['thothTestFailures']++;
        fwrite(STDERR, "FAIL: $message\n");
    }
}

function check_same(mixed $expected, mixed $actual, string $message): void
{
    check($expected === $actual, $message . ' (verwacht ' . var_export($expected, true) . ', kreeg ' . var_export($actual, true) . ')');
}

function check_throws(callable $fn, string $needle, string $message): void
{
    try {
        $fn();
        check(false, $message . ' (geen exception)');
    } catch (Throwable $e) {
        check(str_contains($e->getMessage(), $needle), $message . ' (melding: ' . $e->getMessage() . ')');
    }
}

function finish(string $name): void
{
    $n = $GLOBALS['thothTestCount'];
    $f = $GLOBALS['thothTestFailures'];
    if ($f > 0) {
        fwrite(STDERR, "$name: $f van $n checks mislukt\n");
        exit(1);
    }
    echo "$name: $n checks OK\n";
}

/** Minimale geldige config. */
function sample_config(array $override = []): array
{
    return thoth_validate_config(array_replace([
        'auto-increment-field' => 'No',
        'autoIncrementPrefix' => 'SL1',
        'autoIncrementNumberPadding' => 5,
        'autoIncrementPrefixYear' => true,
        'formFields' => [
            ['name' => 'Naam', 'placeholder' => '', 'invoerType' => 'tekst', 'bc-tabel' => 'Locs', 'bc-kolom' => 'Name', 'verplicht' => true],
            ['name' => 'Aantal', 'placeholder' => '', 'invoerType' => 'nummer', 'bc-tabel' => 'Locs', 'bc-kolom' => 'Qty', 'verplicht' => false],
            ['name' => 'Soort', 'placeholder' => '', 'invoerType' => 'dropdown', 'bc-tabel' => 'Locs', 'bc-kolom' => 'Kind', 'verplicht' => false,
                'opties' => [['waarde' => 'Open', 'label' => 'Open', 'aliassen' => ['Geopend']], 'Gesloten']],
            ['name' => 'Locatie', 'placeholder' => '', 'invoerType' => 'lookup', 'bc-tabel' => 'Locs', 'bc-kolom' => 'Loc_No', 'verplicht' => false,
                'optiesBron' => ['bc-tabel' => 'ServiceLocs', 'waarde-kolom' => 'No', 'label-kolommen' => ['Name'], 'zoek-kolommen' => ['No', 'Name', 'City']]],
        ],
    ], $override));
}

/** Schrijft een config naar een tijdelijke configmap zodat thoth_load_config hem vindt. */
function install_test_configs(): void
{
    $dir = getenv('THOTH_DATA_DIR') . '/config';
    @mkdir($dir);
    putenv('THOTH_CONFIG_DIR=' . $dir);
    $raw = [
        'auto-increment-field' => 'No', 'autoIncrementPrefix' => 'SL1', 'autoIncrementNumberPadding' => 5, 'autoIncrementPrefixYear' => true,
        'formFields' => [
            ['name' => 'Naam', 'invoerType' => 'tekst', 'bc-tabel' => 'Locs', 'bc-kolom' => 'Name', 'verplicht' => true],
            ['name' => 'Aantal', 'invoerType' => 'nummer', 'bc-tabel' => 'Locs', 'bc-kolom' => 'Qty', 'verplicht' => false],
        ],
    ];
    file_put_contents($dir . '/servicelocatie-config.json', json_encode($raw));
    $raw['autoIncrementPrefix'] = 'CO1';
    $raw['formFields'][] = ['name' => 'Servicelocatie', 'invoerType' => 'lookup', 'bc-tabel' => 'Locs', 'bc-kolom' => 'SL_No', 'verplicht' => true,
        'optiesBron' => ['bc-tabel' => 'ServiceLocs', 'waarde-kolom' => 'No', 'label-kolommen' => ['Name']]];
    file_put_contents($dir . '/component-config.json', json_encode($raw));
}
