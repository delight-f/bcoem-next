<?php

declare(strict_types=1);

namespace App\Support\Outputs;

/**
 * Text formatting shared by the P5.2c outputs (participant summary,
 * participant entries list, post-judge inventory).
 */
final class OutputFormat
{
    /**
     * readable_judging_number() (common.lib.php:3369): 5-digit numbers split
     * 2-3, 4-digit numbers 1-3, both then zero-padded to six characters;
     * anything else passes through padded unchanged.
     */
    public static function judgingNumber(int|string|null $number): string
    {
        $n = (string) $number;

        return sprintf('%06s', match (strlen($n)) {
            5 => substr($n, 0, 2).'-'.substr($n, 2),
            4 => substr($n, 0, 1).'-'.substr($n, 1),
            default => $n,
        });
    }

    /** addOrdinalNumberSuffix() (common.lib.php:379). */
    public static function ordinal(int|string $num): string
    {
        if (! is_numeric($num)) {
            return (string) $num;
        }

        $num = (int) $num;

        if (! in_array($num % 100, [11, 12, 13], true)) {
            return $num.match ($num % 10) {
                1 => 'st',
                2 => 'nd',
                3 => 'rd',
                default => 'th',
            };
        }

        return $num.'th';
    }
}
