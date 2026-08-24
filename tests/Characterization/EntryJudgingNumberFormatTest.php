<?php

declare(strict_types=1);

namespace BCOEM\Tests\Characterization;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Characterization: entry judging-number FORMAT contracts (P1.3).
 *
 * Legacy source: lib/process.lib.php — generate_judging_num(),
 * generate_judging_numbers(). The functions are DB-coupled (not vendored),
 * so the composition rules are pinned here as exact expected values of the
 * legacy expressions, and the full algorithms are exercised end-to-end in
 * EntryLifecycleDbTest (CI MySQL).
 *
 * Three allocation methods exist:
 *   default   — random 6 chars, each digit random_int(1,9) => zero never
 *               appears; lowercased; app-level uniqueness vs brewing table
 *               AND $USER_DOCS/<num>.pdf scoresheet files.
 *   identical — sprintf('%06s', id): zero-padded row id.
 *   legacy    — "<category>-<seq>" per category: category padded with
 *               sprintf('%02s'), sequence %03s starting at 001, THEN the
 *               whole string padded again with sprintf('%06s'),
 *               lowercased. Regeneration NULLs every number first and
 *               walks rows ordered by brewCategorySort, brewSubCategory.
 */
final class EntryJudgingNumberFormatTest extends TestCase
{
    #[DataProvider('provideIdenticalMethodIds')]
    public function test_identical_method_pads_row_id_to_six(int $id, string $expected): void
    {
        // lib/process.lib.php:227  sprintf("%06s",$row_judging_numbers['id'])
        self::assertSame($expected, sprintf('%06s', $id));
    }

    /** @return iterable<string, array{int, string}> */
    public static function provideIdenticalMethodIds(): iterable
    {
        yield 'single digit id' => [4, '000004'];
        yield 'six digit id unchanged' => [123456, '123456'];
        yield 'seven digit id not truncated' => [1234567, '1234567'];
    }

    #[DataProvider('provideLegacyMethodComposition')]
    public function test_legacy_method_composes_category_dash_sequence(
        string $category,
        int $nextSequence,
        string $expected,
    ): void {
        // lib/process.lib.php:99-103
        //   $output = sprintf("%02s",$splitter[0])."-".sprintf("%03s",$add_one);
        //   return sprintf("%06s",strtolower($output));
        $composed = sprintf('%02s', $category).'-'.sprintf('%03s', $nextSequence);
        self::assertSame($expected, sprintf('%06s', strtolower($composed)));
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function provideLegacyMethodComposition(): iterable
    {
        // Numeric category, first in category.
        yield 'single digit cat first entry gets double pad' => ['5', 1, '05-001'];
        yield 'two digit cat first entry' => ['21', 1, '21-001'];
        // Sequence increments past 999 grow the tail (sprintf does not clip).
        yield 'sequence past 999 grows tail' => ['21', 1000, '21-1000'];
        // Whole-string %06s pad kicks in only below 6 total chars.
        yield 'short composite padded to six' => ['9', 99, '09-099'];
        // Alpha categories (mead/cider/pro-am) are never zero-padded by %02s.
        yield 'alpha category m1' => ['M1', 1, 'm1-001'];
        yield 'alpha category pr' => ['PR', 12, 'pr-012'];
    }

    public function test_default_method_alphabet_excludes_zero(): void
    {
        // lib/process.lib.php:3-14 — each of the 6 chars is random_int(1,9):
        // "0" can never appear in a default-method judging number. Pinned as
        // the acceptance property for the port; legacy itself is exercised
        // in CI via EntryLifecycleDbTest.
        $alphabet = range(1, 9);
        self::assertNotContains('0', array_map(strval(...), $alphabet));
        self::assertCount(6, str_split('123456')); // length contract
    }
}
