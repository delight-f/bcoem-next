<?php

declare(strict_types=1);

namespace BCOEM\Tests\Unit;

use App\Support\Entries\EntryLimitResult;
use App\Support\Entries\EntryLimits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit pins for the entry limits engine (ticket 11).
 *
 * Table rows mirror ledger/registration-rules.md #1–#10 1:1; the decision
 * table (#8) reproduces provideLimitDecision from
 * tests/Characterization/RegistrationRulesLimitTest.php.
 */
final class EntryLimitsTest extends TestCase
{
    /**
     * Baseline: entrant adding an entry well under every cap.
     *
     * @param  array<string, mixed>  $overrides
     */
    private static function check(array $overrides = []): EntryLimitResult
    {
        return EntryLimits::check(...[
            'action' => 'add',
            'userLevel' => 2,
            'ownsEntry' => true,
            'entryLimitEnabled' => true,
            'paidLimitEnabled' => true,
            'userEntryLimit' => '10',
            'userEntryCount' => 0,
            'style' => '28A',
            'previousStyle' => null,
            'editWindowOpen' => true,
            'subCatLimit' => '',
            'exceptionSubNum' => '',
            'exceptionSubList' => '',
            'styleId' => null,
            'subCategoryCount' => 0,
            ...$overrides,
        ]);
    }

    // ---- #8: the 7-case decision table, exercised through check() ----

    /** @return iterable<string, array{int, int, bool, string}> */
    public static function provideDecisionTable(): iterable
    {
        // [subCategoryCount, subCatLimit, styleExcepted, exceptionSubNum]
        yield 'below limit ok' => [1, 2, false, ''];
        yield 'at limit blocked' => [2, 2, false, ''];
        yield 'over limit blocked' => [5, 2, false, ''];
        yield 'excepted with higher cap still allows below it' => [2, 2, true, '4'];
        yield 'excepted at exception cap blocked' => [4, 2, true, '4'];
        yield 'excepted empty cap is unlimited' => [99, 2, true, ''];
        yield 'not excepted ignores exception cap' => [2, 2, false, '99'];
    }

    #[DataProvider('provideDecisionTable')]
    public function test_subcategory_decision_table(int $count, int $limit, bool $excepted, string $exceptionNum): void
    {
        $result = self::check([
            'subCatLimit' => (string) $limit,
            'exceptionSubNum' => $exceptionNum,
            'exceptionSubList' => $excepted ? '42' : '',
            'styleId' => 42,
            'subCategoryCount' => $count,
        ]);

        $expectedReached = match (true) {
            ! $excepted => $count >= $limit,
            $exceptionNum === '' => false,
            default => $count >= (int) $exceptionNum,
        };

        self::assertSame(! $expectedReached, $result->allowed);
        if ($expectedReached) {
            self::assertSame(EntryLimits::REASON_SUBCATEGORY_CAP, $result->reason);
        }
    }

    // ---- #1/#2 vs #3/#5/#8/#9: both cap flavors ----

    public function test_user_cap_on_add_blocks_with_msg_8(): void
    {
        $result = self::check(['userEntryLimit' => '10', 'userEntryCount' => 10]);

        self::assertFalse($result->allowed);
        self::assertSame(EntryLimits::REASON_USER_CAP, $result->reason);
    }

    public function test_user_cap_counts_all_rows_including_drafts(): void
    {
        // #1: the COUNT(*) itself is DB-side (RegistrationRulesDbTest); here
        // we pin that whatever count arrives IS the cap input — drafts included.
        $result = self::check(['userEntryLimit' => '3', 'userEntryCount' => 2]);

        self::assertTrue($result->allowed);
    }

    public function test_user_cap_not_checked_when_pref_empty(): void
    {
        $result = self::check(['userEntryLimit' => '', 'userEntryCount' => 999]);

        self::assertTrue($result->allowed);
    }

    public function test_user_cap_only_checked_on_add(): void
    {
        $result = self::check([
            'action' => 'edit',
            'userEntryLimit' => '10',
            'userEntryCount' => 10,
            'previousStyle' => '28A',
        ]);

        self::assertTrue($result->allowed);
    }

    public function test_subcategory_cap_blocks_with_msg_9(): void
    {
        $result = self::check(['subCatLimit' => '2', 'subCategoryCount' => 2]);

        self::assertFalse($result->allowed);
        self::assertSame(EntryLimits::REASON_SUBCATEGORY_CAP, $result->reason);
    }

    // ---- #5: edit checks only when window open AND style changed ----

    public function test_edit_with_changed_style_and_open_window_checks_subcat(): void
    {
        $result = self::check([
            'action' => 'edit',
            'previousStyle' => '28B',
            'subCatLimit' => '2',
            'subCategoryCount' => 2,
        ]);

        self::assertFalse($result->allowed);
        self::assertSame(EntryLimits::REASON_SUBCATEGORY_CAP, $result->reason);
    }

    public function test_edit_without_style_change_skips_subcat(): void
    {
        $result = self::check([
            'action' => 'edit',
            'previousStyle' => '28A',
            'subCatLimit' => '2',
            'subCategoryCount' => 99,
        ]);

        self::assertTrue($result->allowed);
    }

    public function test_edit_with_closed_window_skips_subcat(): void
    {
        $result = self::check([
            'action' => 'edit',
            'previousStyle' => '28B',
            'editWindowOpen' => false,
            'subCatLimit' => '2',
            'subCategoryCount' => 99,
        ]);

        self::assertTrue($result->allowed);
    }

    // ---- #6/#7: category normalization + BA count filters ----

    /** @return iterable<string, array{string, string}> */
    public static function provideNormalization(): iterable
    {
        yield 'single digit padded' => ['2', '02'];
        yield 'double digit unchanged' => ['28', '28'];
        yield 'mead M1 kept whole' => ['M1', 'M1'];
        yield 'cider C kept' => ['C', 'C'];
        yield 'provisional P kept' => ['P', 'P'];
        yield 'light L kept' => ['L', 'L'];
    }

    #[DataProvider('provideNormalization')]
    public function test_category_normalization(string $category, string $expected): void
    {
        self::assertSame($expected, EntryLimits::normalizeCategory($category));
    }

    public function test_ba_style_set_drops_category_filter(): void
    {
        self::assertSame(
            ['categorySort' => null, 'subCategory' => '1'],
            EntryLimits::countFilters('X1-1', true),
        );
    }

    public function test_non_ba_count_filters_use_padded_sort_and_exact_sub(): void
    {
        self::assertSame(
            ['categorySort' => '02', 'subCategory' => 'C'],
            EntryLimits::countFilters('2-C', false),
        );
    }

    // ---- #9: comp-limit flags only clear the 403 gate, NOT cap checks ----
    // (legacy: msg=8/msg=9 redirects run before $process_allowed_entries)

    public function test_disabled_entry_limit_flag_does_not_bypass_caps(): void
    {
        $result = self::check([
            'entryLimitEnabled' => false,
            'userEntryLimit' => '10',
            'userEntryCount' => 10,
        ]);

        self::assertFalse($result->allowed);
        self::assertSame(EntryLimits::REASON_USER_CAP, $result->reason);
    }

    public function test_disabled_paid_limit_flag_does_not_bypass_subcat_cap(): void
    {
        $result = self::check([
            'paidLimitEnabled' => false,
            'subCatLimit' => '1',
            'subCategoryCount' => 99,
        ]);

        self::assertFalse($result->allowed);
        self::assertSame(EntryLimits::REASON_SUBCATEGORY_CAP, $result->reason);
    }

    public function test_disabled_flag_allows_non_owner_past_403_gate(): void
    {
        $result = self::check(['ownsEntry' => false, 'entryLimitEnabled' => false]);

        self::assertTrue($result->allowed);
    }

    // ---- #10: admin bypass + non-owner rejection ----

    public function test_admin_bypasses_every_cap(): void
    {
        foreach ([0, 1] as $adminLevel) {
            $result = self::check([
                'userLevel' => $adminLevel,
                'ownsEntry' => false,
                'userEntryCount' => 99,
                'subCatLimit' => '1',
                'subCategoryCount' => 99,
            ]);

            self::assertTrue($result->allowed, "admin level {$adminLevel}");
            self::assertSame('', $result->reason);
        }
    }

    public function test_non_owner_entrant_is_rejected(): void
    {
        $result = self::check(['ownsEntry' => false]);

        self::assertFalse($result->allowed);
        self::assertSame(EntryLimits::REASON_NOT_OWNER, $result->reason);
    }

    public function test_user_cap_redirect_wins_over_non_owner_403(): void
    {
        // Legacy runs the msg=8 redirect before the 403 kill switch, so an
        // over-cap non-owner sees msg=8, not the session-destroying 403.
        $result = self::check([
            'ownsEntry' => false,
            'userEntryLimit' => '10',
            'userEntryCount' => 10,
        ]);

        self::assertFalse($result->allowed);
        self::assertSame(EntryLimits::REASON_USER_CAP, $result->reason);
    }

    // ---- capacity caps: per medal group / per style type / per table ----

    public function test_capacity_caps_block_at_the_limit(): void
    {
        $group = EntryLimits::checkCapacity(2, 2, 2, null, 0, null, 0);
        self::assertFalse($group->allowed);
        self::assertSame(EntryLimits::REASON_STYLE_CAP, $group->reason);

        self::assertFalse(EntryLimits::checkCapacity(2, null, 0, 3, 3, null, 0)->allowed);
        self::assertFalse(EntryLimits::checkCapacity(2, null, 0, null, 0, 1, 1)->allowed);
    }

    public function test_capacity_caps_allow_below_and_when_unset(): void
    {
        self::assertTrue(EntryLimits::checkCapacity(2, 2, 1, 3, 2, 1, 0)->allowed);
        // Null limit = "not configured" and must be skipped, never 0-treated.
        self::assertTrue(EntryLimits::checkCapacity(2, null, 99, null, 99, null, 99)->allowed);
    }

    public function test_capacity_caps_bypass_for_admins(): void
    {
        foreach ([0, 1] as $level) {
            self::assertTrue(EntryLimits::checkCapacity($level, 1, 99, 1, 99, 1, 99)->allowed, "level {$level}");
        }
    }

    // ---- #1-#4 incremental tiers ----

    /** @return iterable<string, array{array<int, array<string, string>>, ?int, int, ?int}> */
    public static function provideIncremental(): iterable
    {
        $tiers = [
            1 => ['limit-number' => '2', 'limit-days' => '10'],
            2 => ['limit-number' => '5', 'limit-days' => '20'],
        ];
        $open = 1000000;

        yield 'inside first window' => [$tiers, $open, $open + (5 * 86400), 2];
        yield 'inside second window' => [$tiers, $open, $open + (15 * 86400), 5];
        yield 'after every window' => [$tiers, $open, $open + (25 * 86400), null];
        yield 'no open epoch' => [$tiers, null, $open, null];
        yield 'no tiers' => [[], $open, $open, null];
    }

    /**
     * @param  array<int, array<string, string>>  $tiers
     */
    #[DataProvider('provideIncremental')]
    public function test_incremental_limit_selects_the_active_tier(array $tiers, ?int $open, int $now, ?int $expected): void
    {
        self::assertSame($expected, EntryLimits::incrementalLimit($tiers, $open, $now));
    }
}
