<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$c = sample_config();
check_same('SL12600012', thoth_format_number($c, 2026, 12), 'voorbeeld van Tim: nummer 12 in 2026');
check_same('SL12600001', thoth_format_number($c, 2026, 1), 'padding 5');
check_same('SL12712345', thoth_format_number($c, 2027, 12345), 'jaar 2027');
check_same('SL1126', thoth_format_number(sample_config(['autoIncrementPrefixYear' => false, 'autoIncrementNumberPadding' => 3]), 2026, 126), 'zonder jaar, padding 3');
check_same('SL100007', thoth_format_number(sample_config(['autoIncrementPrefixYear' => false]), 2026, 7), 'zonder jaar');
check_same('SL1260000', substr(thoth_format_number($c, 2026, 1), 0, 9), 'jaar 2-cijferig');

// Eerste vrije.
$used = thoth_used_sequences($c, 2026, ['SL12600001', 'SL12600002', 'SL12600004', 'SL12500003', 'SL1260000X', 'XX12600003', 'sl12600005', 'SL1260003']);
check_same([1, 2, 4, 5], array_keys($used), 'alleen geldige nummers van het juiste jaar tellen (vorig jaar, letters, te kort niet)');
check_same(3, thoth_next_sequence($c, $used), 'eerste vrije vult het gat');
check_same(1, thoth_next_sequence($c, []), 'leeg jaar begint bij 1');
check_same(1, thoth_next_sequence($c, thoth_used_sequences($c, 2027, ['SL12600001'])), 'nieuw jaar begint bij 1');

// max+1 met één regel om te zetten.
$m = sample_config(['autoIncrementStrategie' => 'max+1']);
check_same(6, thoth_next_sequence($m, $used), 'max+1 negeert gaten');
check_same('eerste-vrije', THOTH_NUMBER_STRATEGY_DEFAULT, 'standaard is eerste vrije');
check(thoth_sequence_fits($c, 99999) && !thoth_sequence_fits($c, 100000), 'reeks vol bij padding-overloop');
check_throws(fn () => thoth_format_number($c, 2026, 0), 'minimaal 1', 'volgnummer 0 geweigerd');

finish('numbering');
