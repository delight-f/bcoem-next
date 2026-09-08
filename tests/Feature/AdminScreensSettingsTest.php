<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\TestEmailMail;
use App\Support\Tenant\DateFmt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * P5.4 settings screens: competition_info, all_dates, site_preferences
 * (five tabs), send_test_email — DB-write parity with process_comp_info /
 * process_dates / process_prefs.
 */
final class AdminScreensSettingsTest extends AdminScreensTestCase
{
    /**
     * Entries-tab writes clear every brewStyleAtLimit flag globally; restore them.
     *
     * @var array<int, int|string|null>|null
     */
    private ?array $origAtLimit = null;

    public function test_competition_info_round_trip_stores_epochs_json_and_check_http(): void
    {
        $this->remember('contest_info');

        $response = $this->put('/admin/competition-info', [
            'contestName' => 'P54 Cup',
            'contestHost' => 'P54 Host',
            'contestHostWebsite' => 'example.org',
            'contestHostLocation' => 'Denver',
            'competition_rules' => '<p>Rule one</p>',
            'competition_packing_shipping' => 'Box it up',
            'contestAwardsLocation' => 'Hall A',
            'contestShippingAddress' => '123 Dock Rd',
            'contestShippingName' => 'P54 Receiver',
            'contestClubs' => 'Club A; Club B;',
            'contestWinnerLink' => 'https://winners.example.org',
            'contestEntryOpen' => '2030-06-01 09:00 AM',
            'contestEntryDeadline' => '2030-06-30 05:00 PM',
        ]);
        $response->assertRedirect('/admin/competition-info?msg=2');

        $row = (array) DB::table('contest_info')->where('id', 1)->first();

        self::assertSame('P54 Cup', $row['contestName']);
        // check_http prefixes the scheme; blank_to_null on empties.
        self::assertSame('http://example.org', $row['contestHostWebsite']);
        self::assertSame('https://winners.example.org', $row['contestWinnerLink']);
        self::assertNull($row['contestBottles']);
        // contestRules JSON blob + clubs JSON array transforms.
        self::assertSame(
            ['competition_rules' => '<p>Rule one</p>', 'competition_packing_shipping' => 'Box it up'],
            json_decode((string) $row['contestRules'], true),
        );
        self::assertSame(['Club A', 'Club B'], json_decode((string) $row['contestClubs'], true));
        // Dates stored as UTC epochs parsed in the tenant tz (to_utc_epoch()).
        self::assertSame($this->epoch('2030-06-01 09:00 AM'), (int) $row['contestEntryOpen']);
        self::assertSame($this->epoch('2030-06-30 05:00 PM'), (int) $row['contestEntryDeadline']);
    }

    public function test_competition_info_checkin_password_bcrypts_and_clears(): void
    {
        $this->remember('contest_info');

        $this->put('/admin/competition-info', [
            'contestName' => 'P54 Cup',
            'contestCheckInPassword' => 'secret-pin',
        ]);

        $hash = (string) DB::table('contest_info')->where('id', 1)->value('contestCheckInPassword');
        self::assertTrue(password_verify('secret-pin', $hash));

        // Blank submit clears instead of storing bcrypt('') (documented divergence).
        $this->put('/admin/competition-info', [
            'contestName' => 'P54 Cup',
            'contestCheckInPassword' => '',
        ]);
        self::assertNull(DB::table('contest_info')->where('id', 1)->value('contestCheckInPassword'));
    }

    public function test_all_dates_writes_three_tables_with_session_fallbacks(): void
    {
        $this->remember('contest_info');
        $this->remember('judging_preferences');
        $this->remember('preferences');

        $sessionId = (int) DB::table('judging_locations')->insertGetId([
            'judgingLocName' => 'P54 session',
            'judgingLocType' => 0,
            'judgingDate' => strtotime('2030-07-10 10:00 UTC'),
            'judgingDateEnd' => strtotime('2030-07-11 04:00 UTC'),
            'judgingRounds' => 2,
        ]);
        try {
            $this->put('/admin/dates', [
                'contestEntryOpen' => '2030-06-01 08:00 AM',
                'contestAwardsLocDate' => '2030-08-01 06:00 PM',
                // Judging window left blank → falls back to the sessions' range.
                'jPrefsJudgingOpen' => '',
                'jPrefsJudgingClosed' => '',
                // Winners delay set → display flips to Y.
                'prefsWinnerDelay' => '2030-09-01 12:00 PM',
            ]);

            $contest = (array) DB::table('contest_info')->where('id', 1)->first();
            self::assertSame($this->epoch('2030-06-01 08:00 AM'), (int) $contest['contestEntryOpen']);
            self::assertSame((int) $contest['contestAwardsLocDate'], (int) $contest['contestAwardsLocTime']);

            $judging = (array) DB::table('judging_preferences')->where('id', 1)->first();
            self::assertSame(strtotime('2030-07-10 10:00 UTC'), (int) $judging['jPrefsJudgingOpen']);
            self::assertSame(strtotime('2030-07-11 04:00 UTC'), (int) $judging['jPrefsJudgingClosed']);

            $prefs = $this->prefs();
            self::assertSame($this->epoch('2030-09-01 12:00 PM'), (int) $prefs['prefsWinnerDelay']);
            self::assertSame('Y', $prefs['prefsDisplayWinners']);

            // Inline per-session date editors update judging_locations.
            $tz = $this->tenantTzOffset();
            $this->put('/admin/dates', [
                'jPrefsJudgingOpen' => '',
                'jPrefsJudgingClosed' => '',
                'id' => [$sessionId],
                'judgingDate'.$sessionId => '2030-07-12 09:00 AM',
                'judgingDateEnd'.$sessionId => '',
            ]);
            $session = (array) DB::table('judging_locations')->find($sessionId);
            self::assertSame(
                new \DateTimeImmutable('2030-07-12 09:00 AM', new \DateTimeZone(DateFmt::tz($tz)))->getTimestamp(),
                (int) $session['judgingDate'],
            );
            self::assertNull($session['judgingDateEnd']);
        } finally {
            DB::table('judging_locations')->delete($sessionId);
        }
    }

    public function test_site_preferences_default_tab_writes_display_rows(): void
    {
        $this->remember('preferences');

        $this->put('/admin/site-preferences/default', [
            'prefsProEdition' => '1',
            'prefsMHPDisplay' => '1', // forced to 0 by Pro edition (legacy quirk)
            'prefsDisplayWinners' => 'Y',
            'prefsWinnerDelay' => '',
            'prefsWinnerMethod' => '1',
            'prefsTheme' => 'default',
            'prefsSEF' => 'N',
            'prefsUseMods' => '0',
            'prefsCAPTCHA' => '0',
            'prefsGoogleAccount' => 'site|6LeKEY|secret',
            'prefsDropOff' => 'Y',
            'prefsShipping' => 'N',
            'prefsAutoPurge' => '0',
            'prefsLanguage' => 'en-US',
            'prefsLanguageToggle' => 'N',
            'prefsDateFormat' => '1',
            'prefsTimeFormat' => '0',
            'prefsTimeZone' => '-7',
            'prefsSponsors' => 'Y',
            'prefsSponsorLogos' => 'Y',
        ]);

        $p = $this->prefs();
        self::assertSame('1', (string) $p['prefsProEdition']);
        self::assertSame('0', (string) $p['prefsMHPDisplay']); // suppressed by pro edition
        // Empty winner delay stores the legacy far-future sentinel.
        self::assertSame(2145916800, (int) $p['prefsWinnerDelay']);
        // reCAPTCHA account stores the pipe-joined value verbatim.
        self::assertSame('site|6LeKEY|secret', (string) $p['prefsGoogleAccount']);
        self::assertSame(1, (int) $p['prefsDropOff']);
        self::assertSame(0, (int) $p['prefsShipping']);
        self::assertSame(['en-US'], json_decode((string) $p['prefsLanguageOptions'], true));
    }

    public function test_site_preferences_entries_tab_moves_fees_and_keeps_discount_flag(): void
    {
        $this->remember('preferences');
        $this->remember('contest_info');
        $this->rememberStyleLimits();

        $set = (string) DB::table('preferences')->where('id', 1)->value('prefsStyleSet');

        $this->put('/admin/site-preferences/entries', [
            'contestEntryFee' => '8',
            'contestEntryFee2' => '6',
            'contestEntryFeeDiscountNum' => '5',
            'prefsStyleSet' => $set, // unchanged — no selected-styles rebuild
            'prefsEntryForm' => '7',
            'prefsSpecific' => '0',
            'prefsSpecialCharLimit' => '150',
            'choose-style-entry-limits' => '0',
        ]);

        $contest = (array) DB::table('contest_info')->where('id', 1)->first();
        self::assertSame('8.00', (string) $contest['contestEntryFee']);
        self::assertSame('6.00', (string) $contest['contestEntryFee2']);
        // Both discount inputs present ⇒ Y.
        self::assertSame('Y', $contest['contestEntryFeeDiscount']);
        self::assertSame('5', $contest['contestEntryFeeDiscountNum']);
    }

    public function test_style_set_change_rebuilds_selected_styles_for_aabc_dual_version(): void
    {
        $this->remember('preferences');
        $this->rememberStyleLimits();

        $styleIds = [];
        foreach ([
            ['brewStyle' => 'P54 AABC25 other', 'brewStyleVersion' => 'AABC2025', 'brewStyleType' => '2'],
            ['brewStyle' => 'P54 AABC22 core', 'brewStyleVersion' => 'AABC2022', 'brewStyleType' => '1'],
            ['brewStyle' => 'P54 AABC22 excluded', 'brewStyleVersion' => 'AABC2022', 'brewStyleType' => '2'],
            ['brewStyle' => 'P54 custom always', 'brewStyleVersion' => 'BJCP2021', 'brewStyleOwn' => 'custom'],
        ] as $style) {
            $styleIds[] = (int) DB::table('styles')->insertGetId([
                'brewStyleGroup' => '99', 'brewStyleNum' => 'X', ...$style,
            ]);
        }
        try {
            $this->put('/admin/site-preferences/entries', [
                'prefsStyleSet' => 'AABC2025',
                'prefsEntryForm' => '7',
                'prefsSpecific' => '0',
                'prefsSpecialCharLimit' => '150',
                'choose-style-entry-limits' => '0',
            ]);

            $selected = json_decode((string) DB::table('preferences')->where('id', 1)->value('prefsSelectedStyles'), true);

            // Ledger pin #7 truth table through the UI path:
            self::assertArrayHasKey($styleIds[0], $selected); // AABC2025 type=2 kept
            self::assertArrayHasKey($styleIds[1], $selected); // AABC2022 type!=2 kept
            self::assertArrayNotHasKey($styleIds[2], $selected); // AABC2022 type=2 dropped
            self::assertArrayHasKey($styleIds[3], $selected); // custom bypasses filters (#5)
        } finally {
            DB::table('styles')->whereIn('id', $styleIds)->delete();
        }
    }

    public function test_email_tab_smtp_off_copies_stored_transport(): void
    {
        $this->remember('preferences');

        DB::table('preferences')->where('id', 1)->update([
            'prefsEmailFrom' => 'stored@example.org',
            'prefsEmailUsername' => 'stored-user',
            'prefsEmailPassword' => 'stored-pass',
            'prefsEmailHost' => 'smtp.stored.example',
            'prefsEmailPort' => '587',
        ]);

        $this->put('/admin/site-preferences/email', [
            'prefsEmailSMTP' => '0',
            'prefsContact' => 'N',
            'prefsEmailRegConfirm' => '1',
            'change-email-password-choice' => '0',
            'prefsEmailFrom' => 'posted@example.org',
            'prefsEmailUsername' => 'posted-user',
            'prefsEmailHost' => 'smtp.posted.example',
            'prefsEmailEncrypt' => 'tls',
            'prefsEmailPort' => '25',
            'prefsEmailCC' => '1',
        ]);

        $p = $this->prefs();
        // SMTP off: posted transport values are replaced by the stored ones…
        self::assertSame('stored@example.org', (string) $p['prefsEmailFrom']);
        self::assertSame('smtp.stored.example', $p['prefsEmailHost']);
        self::assertSame('stored-pass', (string) $p['prefsEmailPassword']);
        // …and confirmations plus CC are forced off.
        self::assertSame('0', (string) $p['prefsEmailRegConfirm']);
        self::assertSame('0', (string) $p['prefsEmailCC']);
    }

    public function test_send_test_email_mails_the_admin(): void
    {
        Mail::fake();

        $this->get('/admin/send-test-email')
            ->assertOk()
            ->assertSee('Test Email')
            ->assertSee(self::ADMIN_EMAIL);

        Mail::assertSent(TestEmailMail::class, 1);
    }

    public function test_send_test_email_surfaces_transport_errors_inline(): void
    {
        // Failure path: transport throws → inline error message, no exception.
        Mail::shouldReceive('to')->andReturnUsing(function () {
            throw new \RuntimeException('SMTP connect failed');
        });

        $this->withoutExceptionHandling();
        $this->get('/admin/send-test-email')
            ->assertOk()
            ->assertSee('Message could not be sent')
            ->assertSee('SMTP connect failed');
    }

    /** Snapshot every non-null at-limit flag for restore. */
    protected function rememberStyleLimits(): void
    {
        if ($this->origAtLimit !== null) {
            return;
        }
        $this->origAtLimit = DB::table('styles')
            ->whereNotNull('brewStyleAtLimit')
            ->pluck('brewStyleAtLimit', 'id')
            ->all();
    }

    protected function tearDown(): void
    {
        DB::table('judging_locations')->where('judgingLocName', 'like', 'P54%')->delete();

        if ($this->origAtLimit !== null) {
            foreach ($this->origAtLimit as $id => $flag) {
                DB::table('styles')->where('id', $id)->update(['brewStyleAtLimit' => $flag]);
            }
            // Rows cleared during the test but not present in the snapshot:
            DB::table('styles')->whereNotNull('brewStyleAtLimit')->whereNotIn('id', array_keys($this->origAtLimit) ?: [0])->update(['brewStyleAtLimit' => null]);
        }

        parent::tearDown();
    }
}
