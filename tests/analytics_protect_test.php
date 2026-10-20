<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$root = dirname(__DIR__);
$src = $root . '/web/analytics/analytics.php';
$committed = (string) file_get_contents($root . '/web/analytics/.htaccess');

function analytics_run(string $php): string
{
    $proc = proc_open(
        [PHP_BINARY, $php],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    return (string) $out;
}

function analytics_copy(): string
{
    $dir = sys_get_temp_dir() . '/thoth-analytics-' . bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    copy($GLOBALS['src'], $dir . '/analytics.php');

    return $dir;
}

$GLOBALS['src'] = $src;

$dir = analytics_copy();
$out = analytics_run($dir . '/analytics.php');
$created = (string) @file_get_contents($dir . '/.htaccess');
check($created === $committed, 'ontbrekende .htaccess is dezelfde tekst als in git');
check(str_contains($created, '<FilesMatch "\.(sqlite|sqlite3|db)(-wal|-shm|-journal)?$">'), 'FilesMatch dekt sqlite, -wal, -shm en -journal');
check(str_contains($created, 'Require all denied'), 'sqlite wordt geweigerd');
check(!str_contains($created, 'analytics.php'), 'de regel noemt het endpoint niet als te blokkeren bestand');
check(str_contains($out, '"error":"Unauthorized"'), 'zonder key blijft analytics.php een endpoint (401)');
check(!is_file($dir . '/analytics.sqlite'), 'een geweigerde call maakt geen database');

$dir = analytics_copy();
file_put_contents($dir . '/.htaccess', "# bestaand\nOptions +Indexes\n");
analytics_run($dir . '/analytics.php');
$appended = (string) file_get_contents($dir . '/.htaccess');
check(str_starts_with($appended, "# bestaand\nOptions +Indexes\n"), 'bestaande regels blijven staan');
check(str_contains($appended, "# kvt_protect_dirs\n" . $committed), 'sqlite-weigering wordt toegevoegd als die ontbreekt');

$dir = analytics_copy();
$custom = "# laat staan\n<FilesMatch \"\\.sqlite\">\n    Require all denied\n</FilesMatch>\n";
file_put_contents($dir . '/.htaccess', $custom);
analytics_run($dir . '/analytics.php');
check_same($custom, (string) file_get_contents($dir . '/.htaccess'), 'htaccess die sqlite al noemt blijft onaangeroerd');

$workflow = (string) file_get_contents($root . '/.github/workflows/deploy-ftp.yml');
$mirror = <<<'EOF'
          mirror -R --delete \
            --exclude '^\.htaccess$' \
            --exclude-glob .htpasswd \
            --exclude-glob auth.php \
            --exclude-glob cfg.php \
            --exclude-glob data \
            --exclude-glob data/ \
            --exclude-glob data/** \
            --exclude-glob cache \
            --exclude-glob cache/ \
            --exclude-glob cache/** \
            --exclude-glob analytics/*.sqlite \
            --exclude-glob analytics/*.sqlite-* \
            ./web .
EOF;
check(str_contains($workflow, $mirror), 'mirror-commando heeft alleen de twee analytics-excludes extra');
check(substr_count($workflow, '--exclude-glob analytics/*.sqlite \\') === 1, 'sqlite-exclude staat één keer in de workflow');
check(substr_count($workflow, '--exclude-glob analytics/*.sqlite-* \\') === 1, 'sqlite-sidecar-exclude staat één keer in de workflow');

finish('analytics_protect');
