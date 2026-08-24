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
}
