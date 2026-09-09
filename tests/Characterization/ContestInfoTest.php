<?php

declare(strict_types=1);

namespace BCOEM\Tests\Characterization;

use BCOEM\Tests\Integration\MySqlTestCase;
use Illuminate\Support\Facades\DB;

/**
 * Phase-1 behavior capture for contest_info / preferences semantics (P1.1).
 * Two layers are characterized here against the vendored legacy library:
 *
 *  1. PURE date/window/limit functions — always run, no MySQL:
 *     - open_or_closed(): the single primitive behind every window flag
 *       (registration, entry, judge, dropoff, shipping, pay windows are all
 *       derived in includes/constants.inc.php from contest_info date columns
 *       via this function plus getTimeZoneDateTime($_SESSION['prefsTimeZone'], …)).
 *     - judging_winner_display(): prefsWinnerDelay gating.
 *     - open_limit(): capacity caps flipping window flags to closed.
 *
 *  2. The fee model — total_fees()/total_fees_paid() from legacy/common.lib.php.
 *     These hit MySQL through require(CONFIG.'config.php'), so every fee test
 *     gates on the same env check as the integration suite and SKIPS on local
 *     runs without a database (CI runs them against the loaded baseline schema).
 *
 * Fee arguments mirror what callers pass from the session, which common.db.php
 * copies verbatim from the contest_info row: fees/threshold/cap arrive as
 * STRINGS (the columns are varchar), Y/N flags as strings, '' meaning unset.
 */
final class ContestInfoTest extends MySqlTestCase
{
    private const USER_A = 900001;

    private const USER_B = 900002;

    private static bool $dbTestActive = false;

    private static ?string $configBackup = null;

    protected function setUp(): void
    {
        // common.lib.php is definitions-only (no top-level side effects);
        // safe to load once per process for the pure tests below.
        if (! defined('LIB')) {
            define('LIB', dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'legacy'.DIRECTORY_SEPARATOR);
        }
        if (! function_exists('open_or_closed')) {
            require_once LIB.'common.lib.php';
        }
    }

    protected function tearDown(): void
    {
        if (self::$dbTestActive) {
            self::deleteFixtureRows(self::USER_A, self::USER_B);
            self::restoreConfig();
            self::$dbTestActive = false;
        }
    }

    // ------------------------------------------------------------------
    // Window state machine: open_or_closed($now, $open, $close)
    // ------------------------------------------------------------------

    public function test_window_state_is_zero_before_the_open_date(): void
    {
        $now = 1_700_000_000;
        $this->assertSame(0, open_or_closed($now, $now + 100, $now + 200));
    }

    public function test_window_state_is_one_from_open_instant_until_but_excluding_close(): void
    {
        $open = 1_700_000_000;
        $close = 1_700_100_000;
        $this->assertSame(1, open_or_closed($open, $open, $close));
        $this->assertSame(1, open_or_closed($close - 1, $open, $close));
    }

    public function test_window_state_is_two_strictly_after_the_close_date(): void
    {
        $open = 1_700_000_000;
        $close = 1_700_100_000;
        $this->assertSame(2, open_or_closed($close + 1, $open, $close));
    }

    public function test_weirdness_window_at_the_exact_close_instant_reports_not_yet_open(): void
    {
        // Legacy bug preserved deliberately: neither "$now < $date2" nor
        // "$now > $date2" matches when $now == $date2, so the output stays at
        // its initial 0 ("before open") instead of 2 ("closed"). A window is
        // therefore reported as not-yet-open at the very second it closes.
        $open = 1_700_000_000;
        $close = 1_700_100_000;
        $this->assertSame(0, open_or_closed($close, $open, $close));
    }

    public function test_missing_dates_report_the_window_as_closed_before_open(): void
    {
        // isset(null) === false short-circuits the whole check: either date
        // being NULL/unset yields 0, indistinguishable from "not open yet".
        $this->assertSame(0, open_or_closed(1_700_000_000, null, 1_700_100_000));
        $this->assertSame(0, open_or_closed(1_700_000_000, 1_690_000_000, null));
        $this->assertSame(0, open_or_closed(1_700_000_000, null, null));
    }

    // ------------------------------------------------------------------
    // Winner visibility: judging_winner_display(prefsWinnerDelay)
    // ------------------------------------------------------------------

    public function test_winner_display_requires_now_strictly_after_the_delay_epoch(): void
    {
        // constants.inc.php combines this with prefsDisplayWinners == "Y":
        // show_presentation/scores/scoresheets flip on only when BOTH hold.
        $this->assertTrue(judging_winner_display(time() - 3600));
        $this->assertFalse(judging_winner_display(time() + 3600));
        $this->assertFalse(judging_winner_display(time()));
    }

    // ------------------------------------------------------------------
    // Capacity limit: open_limit(total, limit, windowState)
    // ------------------------------------------------------------------

    public function test_capacity_limit_closes_only_when_limit_reached_while_window_open(): void
    {
        // Third argument is loosely compared to "1": only window state 1
        // (currently open) lets a reached limit register. Callers feed this
        // back as entry_window_open/judge_window_open = 2 (forced closed).
        $this->assertFalse(open_limit(10, '', 1));      // no limit configured
        $this->assertFalse(open_limit(9, '10', 1));     // under the limit
        $this->assertTrue(open_limit(10, '10', 1));     // at the limit
        $this->assertTrue(open_limit(11, '10', 1));     // over the limit
        $this->assertFalse(open_limit(10, '10', 0));    // window not open yet
        $this->assertFalse(open_limit(10, '10', 2));    // window already closed
    }

    // ------------------------------------------------------------------
    // Fee display rounding contract (caller side of total_fees())
    // ------------------------------------------------------------------

    public function test_fees_are_displayed_via_half_up_rounding_to_two_decimals(): void
    {
        // Every caller renders totals through number_format($x, 2); the
        // currency amount itself is never rounded before display.
        $this->assertSame('24.00', number_format(24.0, 2));
        $this->assertSame('0.13', number_format(0.125, 2));
        $this->assertSame('21.67', number_format(21.6666, 2));
    }

    // ------------------------------------------------------------------
    // Fee model: total_fees() / total_fees_paid()
    //
    // Each argument mirrors its contest_info column / session key:
    //   $entry_fee               contestEntryFee
    //   $entry_fee_discount      contestEntryFee2          (volume rate)
    //   $entry_discount          contestEntryFeeDiscount   (Y/N)
    //   $entry_discount_number   contestEntryFeeDiscountNum (threshold)
    //   $cap_no                  contestEntryCap           ('' = none)
    //   $special_discount_number contestEntryFeePasswordNum (member rate)
    // ------------------------------------------------------------------

    public function test_per_brewer_fee_is_confirmed_entries_times_base_fee(): void
    {
        $this->beginFeeTest();
        $this->seedBrewer(self::USER_A, discount: 'N', entries: [['confirmed' => '1'], ['confirmed' => '1'], ['confirmed' => '1']]);

        $fees = total_fees('8', '5', 'N', '2', '', '', (string) self::USER_A, 'default', '1');
        $this->assertSame(24.0, $fees);
    }

    public function test_unconfirmed_entries_are_excluded_from_the_per_brewer_total(): void
    {
        $this->beginFeeTest();
        $this->seedBrewer(self::USER_A, discount: 'N', entries: [['confirmed' => '1'], ['confirmed' => '1'], ['confirmed' => '0']]);

        $fees = total_fees('8', '5', 'N', '2', '', '', (string) self::USER_A, 'default', '1');
        $this->assertSame(16.0, $fees);
    }

    public function test_volume_discount_boundary_first_n_full_then_discounted_rate(): void
    {
        $this->beginFeeTest();

        // At the threshold (2 entries) everything still costs the base fee.
        $this->seedBrewer(self::USER_A, discount: 'N', entries: [['confirmed' => '1'], ['confirmed' => '1']]);
        $at_threshold = total_fees('8', '5', 'Y', '2', '', '', (string) self::USER_A, 'default', '1');
        $this->assertSame(16.0, $at_threshold);
        self::deleteFixtureRows(self::USER_A);

        // One past the threshold: 2 × 8 + 1 × 5.
        $this->seedBrewer(self::USER_A, discount: 'N', entries: [['confirmed' => '1'], ['confirmed' => '1'], ['confirmed' => '1']]);
        $one_past = total_fees('8', '5', 'Y', '2', '', '', (string) self::USER_A, 'default', '1');
        $this->assertSame(21.0, $one_past);
        self::deleteFixtureRows(self::USER_A);

        // Two past: 2 × 8 + 2 × 5.
        $this->seedBrewer(self::USER_A, discount: 'N', entries: [['confirmed' => '1'], ['confirmed' => '1'], ['confirmed' => '1'], ['confirmed' => '1']]);
        $two_past = total_fees('8', '5', 'Y', '2', '', '', (string) self::USER_A, 'default', '1');
        $this->assertSame(26.0, $two_past);
    }

    public function test_cap_clamps_the_per_brewer_total_and_empty_or_zero_means_no_cap(): void
    {
        $this->beginFeeTest();
        $this->seedBrewer(self::USER_A, discount: 'N', entries: [['confirmed' => '1'], ['confirmed' => '1'], ['confirmed' => '1'], ['confirmed' => '1']]);

        // 4 × 8 = 32 clamps to the 20 cap.
        $capped = total_fees('8', '5', 'N', '2', '20', '', (string) self::USER_A, 'default', '1');
        $this->assertSame(20.0, $capped);

        // '' and '0' both bypass the cap (loose > 0 comparison).
        $uncapped_blank = total_fees('8', '5', 'N', '2', '', '', (string) self::USER_A, 'default', '1');
        $this->assertSame(32.0, $uncapped_blank);
        $uncapped_zero = total_fees('8', '5', 'N', '2', '0', '', (string) self::USER_A, 'default', '1');
        $this->assertSame(32.0, $uncapped_zero);

        // A total under the cap passes through unchanged.
        $under = total_fees('8', '5', 'N', '2', '40', '', (string) self::USER_A, 'default', '1');
        $this->assertSame(32.0, $under);
    }

    public function test_member_discount_flat_rate_applies_when_set_and_standard_math_when_blank(): void
    {
        $this->beginFeeTest();

        // Brewer signed up with the member password (brewerDiscount = Y):
        // flat N × contestEntryFeePasswordNum, volume settings ignored.
        $this->seedBrewer(self::USER_A, discount: 'Y', entries: [['confirmed' => '1'], ['confirmed' => '1'], ['confirmed' => '1']]);
        $member = total_fees('8', '5', 'N', '2', '', '6', (string) self::USER_A, 'default', '1');
        $this->assertSame(18.0, $member);
        self::deleteFixtureRows(self::USER_A);

        // Weirdness preserved: brewerDiscount = Y but contestEntryFeePasswordNum
        // empty falls through to the STANDARD branch — full price applies even
        // though the brewer carries the member flag.
        $this->seedBrewer(self::USER_A, discount: 'Y', entries: [['confirmed' => '1'], ['confirmed' => '1'], ['confirmed' => '1']]);
        $flag_without_rate = total_fees('8', '5', 'N', '2', '', '', (string) self::USER_A, 'default', '1');
        $this->assertSame(24.0, $flag_without_rate);
    }

    public function test_brewer_with_no_entries_costs_nothing(): void
    {
        $this->beginFeeTest();
        $this->seedBrewer(self::USER_A, discount: 'N', entries: []);

        $fees = total_fees('8', '5', 'N', '2', '', '', (string) self::USER_A, 'default', '1');
        $this->assertSame(0.0, $fees);
    }

    public function test_weirdness_paid_totals_ignore_confirmation_so_paid_can_exceed_due(): void
    {
        $this->beginFeeTest();

        // total_fees() counts only brewConfirmed = 1 rows; total_fees_paid()
        // counts ALL rows for the brewer (no confirmation filter) — so with
        // unconfirmed-but-paid rows the recorded payments exceed the amount
        // due and "balance remaining" goes negative.
        $this->seedBrewer(self::USER_A, discount: 'N', entries: [
            ['confirmed' => '1', 'paid' => '1'],
            ['confirmed' => '1', 'paid' => '1'],
            ['confirmed' => '0', 'paid' => '1'],
        ]);

        $due = total_fees('8', '5', 'N', '2', '', '', (string) self::USER_A, 'default', '1');
        $paid = total_fees_paid('8', '5', 'N', '2', '', '', (string) self::USER_A, 'default', '1');
        $this->assertSame(16.0, $due);
        $this->assertSame(24.0, $paid);
    }

    public function test_member_plus_volume_discount_charges_the_cheaper_rate_on_excess_entries(): void
    {
        $this->beginFeeTest();

        // Beyond-threshold rate = min(contestEntryFee2, contestEntryFeePasswordNum).
        // Here fee2=5 < member=6 → excess entries cost 5: 2 × 6 + 2 × 5 = 22.
        // (The first `threshold` entries ALWAYS bill the member rate.)
        $this->seedBrewer(self::USER_A, discount: 'Y', entries: [['confirmed' => '1'], ['confirmed' => '1'], ['confirmed' => '1'], ['confirmed' => '1']]);
        $cheaper_volume = total_fees('8', '5', 'Y', '2', '', '6', (string) self::USER_A, 'default', '1');
        $this->assertSame(22.0, $cheaper_volume);
        self::deleteFixtureRows(self::USER_A);

        // Member rate pricier than volume rate (7 > 5): excess entries still
        // bill the CHEAPER volume rate 5, while the first threshold entries
        // bill the member rate 7 → 2 × 7 + 2 × 5 = 24.
        $this->seedBrewer(self::USER_A, discount: 'Y', entries: [['confirmed' => '1'], ['confirmed' => '1'], ['confirmed' => '1'], ['confirmed' => '1']]);
        $pricier_member = total_fees('8', '5', 'Y', '2', '', '7', (string) self::USER_A, 'default', '1');
        $this->assertSame(24.0, $pricier_member);
    }

    public function test_paid_fees_tier_through_the_volume_discount_like_the_due_side(): void
    {
        $this->beginFeeTest();
        $this->seedBrewer(self::USER_A, discount: 'N', entries: [
            ['confirmed' => '1', 'paid' => '1'],
            ['confirmed' => '1', 'paid' => '1'],
            ['confirmed' => '1', 'paid' => '1'],
            ['confirmed' => '1', 'paid' => '0'],
        ]);

        // 3 of 4 paid, threshold 2: 2 × 8 + 1 × 5 = 21.
        $partial = total_fees_paid('8', '5', 'Y', '2', '', '', (string) self::USER_A, 'default', '1');
        $this->assertSame(21.0, $partial);
        self::deleteFixtureRows(self::USER_A);

        // Fully paid: remainder tiers off TOTAL entries, not paid count —
        // same figure as the due side: 2 × 8 + 2 × 5 = 26.
        $this->seedBrewer(self::USER_A, discount: 'N', entries: [
            ['confirmed' => '1', 'paid' => '1'],
            ['confirmed' => '1', 'paid' => '1'],
            ['confirmed' => '1', 'paid' => '1'],
            ['confirmed' => '1', 'paid' => '1'],
        ]);
        $all_paid = total_fees_paid('8', '5', 'Y', '2', '', '', (string) self::USER_A, 'default', '1');
        $this->assertSame(26.0, $all_paid);
    }

    public function test_weirdness_aggregate_default_view_sums_all_users_and_counts_unconfirmed_entries(): void
    {
        $this->beginFeeTest();

        // The "default"/"default" branch aggregates EVERY user, so the shared
        // fixture tables must be emptied to keep the expected sum exact.
        // Safe: the Characterization suite runs last in the PHPUnit run and
        // every fee test seeds its own rows anyway.
        self::truncate('brewing');
        self::truncate('brewer');
        self::truncate('users');

        $this->seedBrewer(self::USER_A, discount: 'N', entries: [
            ['confirmed' => '1'],
            ['confirmed' => '1'],
            ['confirmed' => '1'],
        ]);
        $this->seedBrewer(self::USER_B, discount: 'N', entries: [
            ['confirmed' => '0'],
        ]);

        $aggregate = total_fees('8', '5', 'N', '2', '', '', 'default', 'default', '1');
        // Weirdness preserved: the aggregate counts the UNCONFIRMED entry
        // (24 + 8 = 32) although the per-brewer branch excludes it.
        $this->assertSame(32.0, $aggregate);

        // Contrast: the same unconfirmed entry is invisible per-brewer.
        $per_brewer = total_fees('8', '5', 'N', '2', '', '', (string) self::USER_B, 'default', '1');
        $this->assertSame(0.0, $per_brewer);
    }

    // ------------------------------------------------------------------
    // Fixtures & MySQL-gated plumbing
    // ------------------------------------------------------------------

    /**
     * @param  list<array{confirmed: string, paid?: string}>  $entries
     */
    private function seedBrewer(int $uid, string $discount, array $entries): void
    {
        DB::table('users')->insert(['id' => $uid, 'user_name' => 'fee-fixture-'.$uid]);
        DB::table('brewer')->insert(['uid' => $uid, 'brewerDiscount' => $discount]);
        foreach ($entries as $entry) {
            DB::table('brewing')->insert([
                'brewBrewerID' => (string) $uid,
                'brewConfirmed' => $entry['confirmed'],
                'brewPaid' => $entry['paid'] ?? '0',
            ]);
        }
    }

    private static function deleteFixtureRows(int ...$uids): void
    {
        $in = implode(',', $uids);
        DB::statement('DELETE FROM brewing WHERE brewBrewerID IN ('.$in.')');
        DB::statement('DELETE FROM brewer WHERE uid IN ('.$in.')');
        DB::statement('DELETE FROM users WHERE id IN ('.$in.')');
    }

    private function beginFeeTest(): void
    {
        if (! self::databaseAvailable()) {
            self::markTestSkipped('MySQL not available');
        }
        self::$dbTestActive = true;
        $this->writeTestConfig();
        if (! defined('LIB')) {
            define('LIB', dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'legacy'.DIRECTORY_SEPARATOR);
        }
        if (! function_exists('total_fees')) {
            require_once LIB.'common.lib.php';
        }
    }

    private function writeTestConfig(): void
    {
        // Same pattern as BestBrewerPointsTest: total_fees() re-requires
        // site-level config.php on every call. Only proceed when there is no
        // real config.php to protect (CI checkout), backing up otherwise.
        $config = CONFIG.'config.php';
        if (file_exists($config)) {
            if (getenv('CI') === false) {
                self::markTestSkipped('legacy/config.php exists; refusing to overwrite a real install config');
            }
            self::$configBackup = (string) file_get_contents($config);
        }
        $host = getenv('BCOEM_TEST_DB_HOST') ?: '127.0.0.1';
        $user = getenv('BCOEM_TEST_DB_USER') ?: 'root';
        $pass = getenv('BCOEM_TEST_DB_PASS') ?: 'root';
        $name = getenv('BCOEM_TEST_DB_NAME') ?: 'bcoem_test';
        file_put_contents($config, <<<PHP
<?php
\$hostname = '{$host}';
\$username = '{$user}';
\$password = '{$pass}';
\$database = '{$name}';
\$database_port = 3306;
\$connection = new mysqli(\$hostname, \$username, \$password, \$database, \$database_port);
mysqli_set_charset(\$connection, 'utf8mb4');
\$prefix = '';
\$installation_id = 'test';
\$session_expire_after = 30;
\$setup_free_access = FALSE;
\$sub_directory = '';
\$base_url = 'http://localhost/';
\$server_root = dirname(__DIR__);
PHP);
    }

    private static function restoreConfig(): void
    {
        $config = CONFIG.'config.php';
        if (self::$configBackup !== null) {
            file_put_contents($config, self::$configBackup);
            self::$configBackup = null;
        } else {
            @unlink($config);
        }
    }
}
