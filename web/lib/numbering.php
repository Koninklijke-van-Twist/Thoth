<?php

declare(strict_types=1);

/**
 * Nummering: prefix + (optioneel) 2-cijferig jaar + volgnummer met padding.
 * Voorbeeld: SL1 + 26 + 00012 = SL12600012.
 */

/** Vaste deel vóór het volgnummer, bv. "SL126". */
function thoth_number_stem(array $config, int $year): string
{
    $stem = (string) $config['autoIncrementPrefix'];
    if ($config['autoIncrementPrefixYear']) {
        $stem .= sprintf('%02d', $year % 100);
    }

    return $stem;
}

function thoth_format_number(array $config, int $year, int $sequence): string
{
    if ($sequence < 1) {
        throw new InvalidArgumentException('Volgnummer moet minimaal 1 zijn.');
    }

    return thoth_number_stem($config, $year) . str_pad((string) $sequence, (int) $config['autoIncrementNumberPadding'], '0', STR_PAD_LEFT);
}

/**
 * Haalt de volgnummers uit bestaande BC-nummers met dezelfde stam.
 * Alleen stam + cijfers (minstens de padding-lengte) telt; afwijkende nummers worden genegeerd.
 *
 * @param list<string> $existing
 * @return array<int, true>
 */
function thoth_used_sequences(array $config, int $year, array $existing): array
{
    $stem = thoth_number_stem($config, $year);
    $padding = (int) $config['autoIncrementNumberPadding'];
    $used = [];
    foreach ($existing as $no) {
        $no = strtoupper(trim((string) $no));
        if (!str_starts_with($no, strtoupper($stem))) {
            continue;
        }
        $rest = substr($no, strlen($stem));
        if ($rest === '' || !ctype_digit($rest) || strlen($rest) < $padding) {
            continue;
        }
        $seq = (int) $rest;
        if ($seq >= 1) {
            $used[$seq] = true;
        }
    }

    return $used;
}

/**
 * Volgende volgnummer volgens de strategie van de config.
 *  - eerste-vrije: laagste ongebruikte getal vanaf 1 (gaten worden opgevuld).
 *  - max+1: hoogste gebruikte + 1.
 *
 * @param array<int, true> $used
 */
function thoth_next_sequence(array $config, array $used): int
{
    $strategy = (string) ($config['autoIncrementStrategie'] ?? THOTH_NUMBER_STRATEGY_DEFAULT);
    if ($strategy === 'max+1') {
        return $used === [] ? 1 : max(array_keys($used)) + 1;
    }
    $seq = 1;
    while (isset($used[$seq])) {
        $seq++;
    }

    return $seq;
}

function thoth_sequence_fits(array $config, int $sequence): bool
{
    return strlen((string) $sequence) <= (int) $config['autoIncrementNumberPadding'];
}
