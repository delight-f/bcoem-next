<?php

declare(strict_types=1);

namespace App\Support\Results;

/**
 * Place rendering per the scoring ledger (#1): '1'..'4' render as
 * ordinals; '5' renders 'HM' (and 'HM', possible only in the BOS table's
 * varchar column, is equivalent); anything else is N/A.
 */
final class Place
{
    public static function label(int|string|null $place): string
    {
        $p = (string) $place;

        return match ($p) {
            '1' => '1st',
            '2' => '2nd',
            '3' => '3rd',
            '4' => '4th',
            '5', 'HM' => 'HM',
            default => 'N/A',
        };
    }

    /**
     * Ledger #3: BOS eligibility per styleTypeBOSMethod — 1→1st only,
     * 2→top two, 3→top three. Explicit value list, NEVER string >=
     * comparisons (legacy's common.lib.php:2771 worked only because 'HM'
     * sorts above digits by accident).
     *
     * @return list<string>
     */
    public static function bosEligiblePlaces(int $bosMethod): array
    {
        return match ($bosMethod) {
            2 => ['1', '2'],
            3 => ['1', '2', '3'],
            default => ['1'],
        };
    }
}
