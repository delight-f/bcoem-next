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
 * `$USER_DOCS/<num>.pdf` scoresheet file exists. The port checks the
 * non-public `storage/user_docs` directory when present; tenants without
 * one (the standalone build so far) just get the DB check.
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

            if (! $taken && is_dir(UserDocs::root())) {
                $taken = is_file(UserDocs::path(strtolower($number).'.pdf'));
            }
        } while ($taken);

        return $number;
    }

    /**
     * Legacy "identical" method (generate_judging_numbers $method=
     * "identical"): judging number equals the zero-padded entry id.
     */
    public static function sameAsEntry(int $entryId): string
    {
        return sprintf('%06s', $entryId);
    }

    /**
     * Legacy "legacy" method (generate_judging_num(2, $cat)): per-category
     * sequence CAT-NNN where CAT is the entry's brewCategory and NNN
     * continues from the highest existing NNN in that category, zero-padded
     * to three. Output sprintf("%06s", strtolower(...)) verbatim.
     */
    public static function withStylePrefix(string $category): string
    {
        $highest = DB::table('brewing')
            ->where('brewCategory', $category)
            ->whereNotNull('brewJudgingNumber')
            ->where('brewJudgingNumber', '!=', '')
            ->orderByDesc('brewJudgingNumber')
            ->value('brewJudgingNumber');

        if ($highest === null || $highest === '') {
            return sprintf('%06s', strtolower($category.'-001'));
        }

        $splitter = explode('-', (string) $highest);
        $next = ((int) ($splitter[1] ?? 0)) + 1;

        return sprintf('%06s', strtolower(sprintf('%02s', $splitter[0]).'-'.sprintf('%03s', $next)));
    }
}
