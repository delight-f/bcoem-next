<?php

declare(strict_types=1);

namespace App\Support\Entries;

use Illuminate\Support\Facades\DB;

/**
 * Judging-number allocation (P3.3a) — port of the default "random" method
 * from lib/process.lib.php generate_judging_num(1, ...) +
 * random_judging_num_generator().
 *
 * Ledger entry-lifecycle #5: six digits, each 1–9 — zero is impossible by
 * construction and load-bearing for handwriting legibility on scoresheets.
 * Uniqueness is app-level only (schema has no unique index, ledger #9):
 * the loop re-rolls while the number is already stored in `brewing` OR a
 * `$USER_DOCS/<num>.pdf` scoresheet file exists. The port checks
 * `public/user_docs` when the directory is present; tenants without one
 * (the standalone build so far) just get the DB check.
 */
final class JudgingNumber
{
    public static function random(): string
    {
        do {
            $number = implode('', array_map(
                static fn (): string => (string) random_int(1, 9),
                range(1, 6),
            ));

            $taken = DB::table('brewing')
                ->where('brewJudgingNumber', $number)
                ->exists();

            if (! $taken && is_dir(public_path('user_docs'))) {
                $taken = is_file(public_path('user_docs/'.strtolower($number).'.pdf'));
            }
        } while ($taken);

        return $number;
    }
}
