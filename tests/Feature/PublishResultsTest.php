<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

/**
 * Publish Results (legacy process.inc.php?action=publish, :348-410).
 * Releases winners and force-closes every future deadline.
 */
final class PublishResultsTest extends AdminScreensTestCase
{
    private const FUTURE = 2000000000; // ~2033

    protected function tearDown(): void
    {
        // Restore corpus state so other tests see the original prefs.
        DB::table('preferences')->where('id', 1)->update([
            'prefsDisplayWinners' => 'N',
            'prefsWinnerDelay' => 0,
        ]);
        parent::tearDown();
    }

    public function test_publish_sets_display_winners_and_snaps_future_deadlines(): void
    {
        DB::table('contest_info')->where('id', 1)->update([
            'contestRegistrationDeadline' => self::FUTURE,
            'contestEntryDeadline' => self::FUTURE,
        ]);
        DB::table('judging_preferences')->where('id', 1)->update(['jPrefsJudgingClosed' => self::FUTURE]);
        $locId = DB::table('judging_locations')->insertGetId([
            'judgingLocName' => 'PUB test loc',
            'judgingDate' => self::FUTURE,
            'judgingDateEnd' => 0,
            'judgingLocType' => 1,
        ]);

        $response = $this->post('/admin/results/publish');

        $response->assertRedirect('/admin?msg=36');
        self::assertSame('Y', DB::table('preferences')->where('id', 1)->value('prefsDisplayWinners'));
        self::assertLessThan(
            self::FUTURE,
            (int) DB::table('contest_info')->where('id', 1)->value('contestRegistrationDeadline'),
        );
        self::assertLessThan(
            self::FUTURE,
            (int) DB::table('contest_info')->where('id', 1)->value('contestEntryDeadline'),
        );
        self::assertLessThan(
            self::FUTURE,
            (int) DB::table('judging_preferences')->where('id', 1)->value('jPrefsJudgingClosed'),
        );
        $loc = DB::table('judging_locations')->where('id', $locId)->first();
        self::assertLessThan(self::FUTURE, (int) $loc->judgingDate);
        self::assertGreaterThan(0, (int) $loc->judgingDateEnd);

        DB::table('judging_locations')->where('id', $locId)->delete();
    }

    public function test_past_deadlines_untouched(): void
    {
        $past = 1000000000;
        DB::table('contest_info')->where('id', 1)->update(['contestJudgeDeadline' => $past]);

        $this->post('/admin/results/publish');

        self::assertSame(
            $past,
            (int) DB::table('contest_info')->where('id', 1)->value('contestJudgeDeadline'),
        );
    }
}
