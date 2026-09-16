<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\TestEmailMail;
use App\Support\Payments\FeeCalculator;
use App\Support\Styles\StyleSets;
use App\Support\Tenant\DateFmt;
use App\Support\Tenant\TenantContext;
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

    /**
     * Issue 20: the competition-info form's subsections render collapsed so
     * the page is not one very tall wall of fields. Issue 40: each section
     * carries a light-blue Bruxellensis accent so adjacent sections are
     * visually distinguishable.
     */
    public function test_competition_info_sections_render_collapsed(): void
    {
        $response = $this->get('/admin/competition-info')->assertOk();

        $response->assertSee('bcoem-comp-info-section', false);
        $response->assertDontSee('<details class="bcoem-comp-info-section" open', false);

        foreach (['General', 'Entry Window', 'Awards Ceremony'] as $title) {
            $response->assertSee('<summary><h3>'.$title.'</h3></summary>', false);
        }

        // The section styling is inline in the Blade (the shared app.css is
        // off-limits) and applies the same light-blue accent values the
        // Bruxellensis palette already uses elsewhere.
        $response->assertSee('.bcoem-comp-info details.bcoem-comp-info-section {', false);
        $response->assertSee('background-color: #eaf3fd;', false);
        $response->assertSee('border-left: 4px solid #1565C0;', false);
    }

    public function test_competition_info_club_search_wires_add_button_state(): void
    {
        // Issue 21: the Add/Clear buttons are disabled until the input has a
        // value, so the input event must be bound to the state refresher.
        // Issue 41: that same input event re-renders the live result list,
        // clicking a suggestion selects it, and Enter adds the typed name.
        $this->get('/admin/competition-info')
            ->assertOk()
            ->assertSee("input.addEventListener('input', refreshMatchState);", false)
            ->assertSee('renderMatches(term);', false)
            ->assertSee("resultsDiv.addEventListener('click'", false)
            ->assertSee("input.addEventListener('keydown'", false)
            ->assertSee('list-group-item-action bcoem-club-option', false);
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

    public function test_all_dates_view_presents_legacy_transliteration(): void
    {
        $response = $this->get('/admin/dates');

        $response->assertOk();

        // Lead + both intro sentences (all_dates.admin.php:152-153 verbatim).
        $response->assertSee('Baseline Data Installation Competition-Related Dates', false);
        $response->assertSee(
            '<p>All competition-related dates for various functions are listed below. Useful when resetting the software for another competition instance after archiving or purging or to adjust any function\'s date/time for the current competition iteration.</p>',
            false,
        );

        // Section headings (legacy: Entry-Related, Account Registration,
        // Judging Sessions, Judging Open and Close [prefsEval=1 baseline],
        // Non-Judging Sessions, Results, Awards Ceremony).
        foreach ([
            '<h3>Entry-Related</h3>',
            '<h3>Account Registration</h3>',
            '<h3>Judging Sessions</h3>',
            '<h3>Judging Open and Close</h3>',
            '<h3>Non-Judging Sessions</h3>',
            '<h3>Results</h3>',
            '<h3>Awards Ceremony</h3>',
        ] as $heading) {
            $response->assertSee($heading, false);
        }

        // Field labels.
        foreach ([
            'Entry Window Open',
            'Entry Window Close',
            'Entry Edit Close Date',
            'Drop-Off Window Open',
            'Drop-Off Window Close',
            'Shipping Window Open',
            'Shipping Window Close',
            'Entrant Open',
            'Entrant Close',
            'Judge/Steward Open',
            'Judge/Steward Close',
            'Display Date and Time',
            'Date and Time',
        ] as $label) {
            $response->assertSee($label);
        }

        // Help blocks (all_dates.admin.php:178,188,239,253,266,279,431,442).
        $response->assertSee(
            'This date is only for restriction of adding <strong>new</strong> entries. Existing entries will be able to be edited beyond this date &ndash; until the drop-off/shipping deadlines &ndash; unless a specific entry editing close date is provided below.',
            false,
        );
        $response->assertSee(
            'If you wish to restrict editing of any exisiting entry\'s information by non-admin participants, provide a close date here. For example, this could allow competition staff to prepare for sorting prior to the entry drop-off/shipment closure dates.',
            false,
        );
        $response->assertSee('The date and time when general entrants are able to create an account.');
        $response->assertSee('The deadline for general entrants to create an account.');
        $response->assertSee('The date and time when judges and stewards are able to create an account and indicate their session preferences.');
        $response->assertSee('The deadline for judges and stewards to create an account and indicate their session preferences.');
        $response->assertSee(
            'Date and time when the system will display winners. If a date and time are specified, winner display will be enabled. If the date and time are removed or blank, winner display will be disabled.',
        );
        $response->assertSee('Provide even if the date of judging is the same.');

        // Legacy control/columns (form-horizontal is BS3 — the BS5 migration
        // (issue 9) dropped it; the form keeps its rows as .row.mb-3).
        $response->assertSee('class="form-control date-time-picker-system"', false);
        $response->assertSee('name="submit" type="submit" class="btn btn-primary" value="Update Competition Dates"', false);
        $response->assertSee('<form data-time-24hr=', false);

        // Baseline has no judging/non-judging sessions → empty-state links.
        $response->assertSee('No judging sessions have been defined.');
        $response->assertSee('Add a judging session');
        $response->assertSee('No non-judging sessions have been defined.');
        $response->assertSee('Add a non-judging session');

        // prefsEval=1 baseline → judging open/close info trigger + modal.
        $response->assertSee('Judging Open/Close Dates and Times Info');
        $response->assertSee('id="judgingWindowModal"', false);
    }

    public function test_all_dates_view_renders_non_judging_session_start_only(): void
    {
        $id = DB::table('judging_locations')->insertGetId([
            'judgingLocName' => 'P54 bottling',
            'judgingLocType' => 2,
            'judgingDate' => strtotime('2030-07-15 10:00 UTC'),
            'judgingDateEnd' => strtotime('2030-07-15 16:00 UTC'),
        ]);
        try {
            $this->get('/admin/dates')
                ->assertSee('P54 bottling - Session Start')
                ->assertSee('Provide a start date and time for the session.')
                ->assertDontSee('P54 bottling - Session End', false);
        } finally {
            DB::table('judging_locations')->delete($id);
        }
    }

    public function test_all_dates_view_renders_distributed_session_end_field(): void
    {
        $id = DB::table('judging_locations')->insertGetId([
            'judgingLocName' => 'P54 remote',
            'judgingLocType' => 1,
            'judgingDate' => strtotime('2030-07-16 09:00 UTC'),
            'judgingDateEnd' => strtotime('2030-07-17 04:00 UTC'),
        ]);
        try {
            $this->get('/admin/dates')
                ->assertSee('P54 remote - Session Start')
                ->assertSee('P54 remote - Session End')
                ->assertSee('For a distributed session, it is required that you provide an end date and time that will serve as a deadline for judges to submit their evaluations.');
        } finally {
            DB::table('judging_locations')->delete($id);
        }
    }

    public function test_all_dates_view_hides_judging_open_close_when_eval_disabled(): void
    {
        DB::table('preferences')->where('id', 1)->update(['prefsEval' => 0]);
        try {
            $this->get('/admin/dates')
                ->assertDontSee('<h3>Judging Open and Close</h3>', false)
                ->assertDontSee('id="judgingWindowModal"', false);
        } finally {
            DB::table('preferences')->where('id', 1)->update(['prefsEval' => 1]);
        }
    }

    public function test_payment_tab_shows_provider_status_summary_and_setup_link(): void
    {
        // Issue #24: the Payment tab is a read-only summary of both providers
        // with one CTA to the setup screen; credentials are edited there.
        $this->remember('preferences');

        $this->get('/admin/site-preferences/payment')
            ->assertOk()
            ->assertSee('Card payments (Stripe):', false)
            ->assertSee('Not connected')
            ->assertSee(htmlspecialchars((string) route('admin.payments.setup')), false)
            ->assertDontSee('Accept PayPal?');

        DB::table('preferences')->where('id', 1)->update([
            'prefsStripe' => json_encode(['account_id' => 'acct_test', 'webhook_secret' => 'whsec_test']),
        ]);

        $this->get('/admin/site-preferences/payment')
            ->assertOk()
            ->assertSee('Connected')
            ->assertDontSee('Accept PayPal?');
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
            'prefsUseMods' => 'N',
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

    /**
     * Issue 23: the General tab stored prefsUseMods as 0/1, but the column is
     * char(1) and both the dashboard and the public mods gate test for 'Y' —
     * so enabling Custom Modules never surfaced the Manage/Add row.
     */
    public function test_general_tab_custom_modules_saves_legacy_yn_and_unlocks_dashboard(): void
    {
        $this->remember('preferences');

        $this->put('/admin/site-preferences/default', $this->defaultTabPayload(['prefsUseMods' => 'Y']))
            ->assertRedirect('/admin/site-preferences/default?msg=2');

        self::assertSame('Y', (string) $this->prefs()['prefsUseMods']);

        $this->get('/admin')->assertOk()->assertSee('Custom Modules');
    }

    /**
     * Issue 17: the Theme picker used to be a dead control (prefsTheme was
     * saved but never consumed). It now selects between the two palettes the
     * port actually ships, and the legacy Bootswatch names are rejected.
     */
    public function test_theme_preference_applies_and_rejects_legacy_names(): void
    {
        $this->remember('preferences');

        DB::table('preferences')->where('id', 1)->update(['prefsTheme' => 'bcoem-brux']);
        $this->get('/')->assertOk()->assertSee('data-bs-theme="bcoem-brux"', false);

        DB::table('preferences')->where('id', 1)->update(['prefsTheme' => 'default']);
        $this->get('/')->assertOk()->assertDontSee('data-bs-theme="bcoem-brux"', false);

        $this->put('/admin/site-preferences/default', $this->defaultTabPayload(['prefsTheme' => 'cerulean']))
            ->assertSessionHasErrors('prefsTheme');
    }

    /**
     * Full default-tab payload (the tab validates ~21 required columns), with
     * the session timeout left blank — i.e. "use the installation default".
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function defaultTabPayload(array $overrides = []): array
    {
        return array_merge([
            'prefsProEdition' => '0',
            'prefsMHPDisplay' => '1',
            'prefsDisplayWinners' => 'Y',
            'prefsWinnerDelay' => '',
            'prefsWinnerMethod' => '0',
            'prefsTheme' => 'default',
            'prefsSEF' => 'N',
            'prefsUseMods' => 'N',
            'prefsCAPTCHA' => '0',
            'prefsGoogleAccount' => '',
            'prefsDropOff' => 'N',
            'prefsShipping' => 'N',
            'prefsAutoPurge' => '0',
            'prefsLanguage' => 'en-US',
            'prefsLanguageToggle' => 'N',
            'prefsDateFormat' => '1',
            'prefsTimeFormat' => '0',
            'prefsTimeZone' => '-7',
            'prefsSponsors' => 'Y',
            'prefsSponsorLogos' => 'Y',
            'prefsRecordPaging' => '150',
            'prefsSessionTimeout' => '',
        ], $overrides);
    }

    /**
     * Upstream 3.1.0 session (auto-logout) timeout: the preference column,
     * both countdown boot sites and the resync heartbeat all agree on it, and
     * a blank submit falls back to the installation default.
     */
    public function test_site_preferences_session_timeout_persists_and_blank_falls_back(): void
    {
        $this->remember('preferences');
        $default = (int) config('session.lifetime', 120);

        DB::table('preferences')->where('id', 1)->update(['prefsSessionTimeout' => 45]);

        // Field prefill comes from the preference.
        $this->get('/admin/site-preferences/default')
            ->assertOk()
            ->assertSee('name="prefsSessionTimeout"', false)
            ->assertSee('value="45"', false);

        // Both boot sites read the preference, not the raw config: the public
        // nav countdown (#session-end, rendered on the public side only)…
        $this->get('/')->assertSee('data-session-end-seconds="2700"', false);

        // …and the admin expiry-modal boot (admin side).
        $this->get('/admin/site-preferences/default')
            ->assertOk()
            ->assertSee('lifetimeMin: 45', false)
            ->assertSee('ajax/heartbeat', false);

        // Resync endpoint hands back the remaining lifetime.
        $before = time();
        $json = $this->get('/ajax/heartbeat')->assertOk()->json();
        self::assertSame('1', (string) $json['status']);
        self::assertSame(45, (int) $json['session_expire_after_minutes']);
        self::assertGreaterThanOrEqual($before + 2700, (int) $json['session_end_seconds']);
        self::assertLessThanOrEqual(time() + 2700, (int) $json['session_end_seconds']);

        // Real endpoint round trip: persist a new value, then blank it.
        $this->put('/admin/site-preferences/default', $this->defaultTabPayload(['prefsSessionTimeout' => '90']))
            ->assertRedirect('/admin/site-preferences/default?msg=2');
        self::assertSame(90, (int) DB::table('preferences')->where('id', 1)->value('prefsSessionTimeout'));
        self::assertSame(90, (int) $this->get('/ajax/heartbeat')->json('session_expire_after_minutes'));

        $this->put('/admin/site-preferences/default', $this->defaultTabPayload(['prefsSessionTimeout' => '']))
            ->assertRedirect('/admin/site-preferences/default?msg=2');
        self::assertNull(DB::table('preferences')->where('id', 1)->value('prefsSessionTimeout'));

        // Blank → installation default in the boot sites and the heartbeat.
        $this->get('/')->assertSee('data-session-end-seconds="'.($default * 60).'"', false);
        $this->get('/admin/site-preferences/default')
            ->assertSee('lifetimeMin: '.$default, false);
        self::assertSame($default, (int) $this->get('/ajax/heartbeat')->json('session_expire_after_minutes'));
    }

    public function test_site_preferences_session_timeout_rejects_invalid_and_clamps_low(): void
    {
        $this->remember('preferences');

        DB::table('preferences')->where('id', 1)->update(['prefsSessionTimeout' => 45]);

        // Non-numeric input is rejected; the stored value is left alone.
        $this->put('/admin/site-preferences/default', $this->defaultTabPayload(['prefsSessionTimeout' => 'soon']))
            ->assertSessionHasErrors('prefsSessionTimeout');
        self::assertSame(45, (int) DB::table('preferences')->where('id', 1)->value('prefsSessionTimeout'));

        // Zero or less stores NULL (blank fallback), like legacy.
        $this->put('/admin/site-preferences/default', $this->defaultTabPayload(['prefsSessionTimeout' => '0']))
            ->assertRedirect('/admin/site-preferences/default?msg=2');
        self::assertNull(DB::table('preferences')->where('id', 1)->value('prefsSessionTimeout'));

        // Below the 3-minute floor clamps up (the 2:00/0:30 warning modals
        // need the room) — process_prefs.inc.php semantics.
        $this->put('/admin/site-preferences/default', $this->defaultTabPayload(['prefsSessionTimeout' => '2']))
            ->assertRedirect('/admin/site-preferences/default?msg=2');
        self::assertSame(3, (int) DB::table('preferences')->where('id', 1)->value('prefsSessionTimeout'));
    }

    /**
     * The General tab's "Records Displayed" (prefsRecordPaging) now actually
     * drives the DataTables page size instead of the old hard-coded 25.
     */
    public function test_records_displayed_pref_drives_table_page_size(): void
    {
        $this->remember('preferences');
        DB::table('preferences')->where('id', 1)->update(['prefsRecordPaging' => '40']);

        $this->get('/backoffice/participants')->assertOk()->assertSee('data-dt-page="40"', false);
    }

    /**
     * Controls that saved a value nothing consumed are gone: the SEF toggle
     * (Laravel always serves clean URLs), the auto-purge switch (replaced by
     * the purge action) and the test-email Yes/No radios (the button does it).
     */
    public function test_dead_controls_are_removed_from_the_forms(): void
    {
        $this->get('/admin/site-preferences/default')->assertOk()
            ->assertDontSee('name="prefsSEF"', false)
            ->assertDontSee('name="prefsAutoPurge"', false)
            ->assertDontSee('Search Engine Friendly URLs')
            ->assertSee('id="purge-stale-form"', false);

        $this->get('/admin/site-preferences/email')->assertOk()
            ->assertDontSee('name="send-test-email"', false)
            // The working test-settings button stays.
            ->assertSee('SMTP Settings Test')
            ->assertSee('Test Current Email Sending Settings');
    }

    /**
     * The General tab's purge action replaces the legacy auto-purge switch
     * (which no longer had a cron path): unconfirmed entries untouched for
     * 24h are removed, fresh ones are kept.
     */
    public function test_general_tab_purge_removes_only_stale_entries(): void
    {
        $this->remember('preferences');

        $stale = (int) DB::table('brewing')->insertGetId([
            'brewName' => 'P54 stale unconfirmed',
            'brewCategorySort' => '10', 'brewCategory' => '10', 'brewSubCategory' => 'A',
            'brewBrewerID' => 9999, 'brewConfirmed' => '0',
            'brewUpdated' => now()->subDays(2)->format('Y-m-d H:i:s'),
        ]);
        $fresh = (int) DB::table('brewing')->insertGetId([
            'brewName' => 'P54 fresh unconfirmed',
            'brewCategorySort' => '10', 'brewCategory' => '10', 'brewSubCategory' => 'A',
            'brewBrewerID' => 9999, 'brewConfirmed' => '0',
            'brewUpdated' => now()->format('Y-m-d H:i:s'),
        ]);

        try {
            $this->post('/admin/site-preferences-purge-stale')
                ->assertRedirectContains('/admin/site-preferences/default?msg=purged');

            self::assertNull(DB::table('brewing')->where('id', $stale)->first());
            self::assertNotNull(DB::table('brewing')->where('id', $fresh)->first());
        } finally {
            DB::table('brewing')->whereIn('id', [$stale, $fresh])->delete();
        }

        // Top-level-admin only: a guest is bounced to login by the route gate.
        $this->post('/logout');
        $this->post('/admin/site-preferences-purge-stale')->assertRedirect('/login');
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

    public function test_email_tab_saves_transport_and_api_key(): void
    {
        $this->remember('preferences');

        $this->put('/admin/site-preferences/email', [
            'prefsEmailSMTP' => '1',
            'prefsContact' => 'N',
            'prefsEmailRegConfirm' => '1',
            'change-email-password-choice' => '0',
            'prefsEmailTransport' => 'resend',
            'prefsEmailApiKey' => 're_saved_key',
            'prefsEmailCC' => '1',
        ]);

        $p = $this->prefs();
        self::assertSame('resend', (string) $p['prefsEmailTransport']);
        self::assertSame('re_saved_key', (string) $p['prefsEmailApiKey']);
    }

    public function test_email_tab_keeps_stored_api_key_when_field_left_blank(): void
    {
        $this->remember('preferences');

        DB::table('preferences')->where('id', 1)->update([
            'prefsEmailApiKey' => 're_existing',
            'prefsEmailTransport' => 'resend',
        ]);

        // The key field is never pre-filled, so an unrelated save posts it blank.
        $this->put('/admin/site-preferences/email', [
            'prefsEmailSMTP' => '1',
            'prefsContact' => 'N',
            'prefsEmailRegConfirm' => '1',
            'change-email-password-choice' => '0',
            'prefsEmailTransport' => 'resend',
            'prefsEmailApiKey' => '',
            'prefsEmailCC' => '0',
        ]);

        self::assertSame('re_existing', (string) $this->prefs()['prefsEmailApiKey']);
    }

    public function test_send_test_email_does_not_claim_success_when_only_logged(): void
    {
        $this->remember('preferences');

        // No transport chosen and the app mailer is not a delivering one, so
        // the mailer accepts the message without any chance of arrival.
        DB::table('preferences')->where('id', 1)->update([
            'prefsEmailSMTP' => '1',
            'prefsEmailTransport' => null,
            'prefsEmailHost' => null,
        ]);

        $this->get('/admin/send-test-email')
            ->assertOk()
            ->assertSee('do not deliver mail')
            ->assertDontSee('Test email sent');
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

    /**
     * The entries-tab picker is driven by StyleSets — all six sets, and only
     * those six (BA/AABC 2019/BJCP2008/BJCP2015 stay out).
     */
    public function test_entries_tab_style_set_picker_lists_exactly_the_six_sets(): void
    {
        $this->get('/admin/site-preferences/entries')
            ->assertOk()
            ->assertSee('name="prefsStyleSet"', false)
            ->assertSee('value="BJCP2025"', false)
            ->assertSee('value="BJCP2021"', false)
            ->assertSee('value="BA2026"', false)
            ->assertSee('value="AABC2022"', false)
            ->assertSee('value="AABC2025"', false)
            ->assertSee('value="NWCiderCup"', false)
            // Option labels are the definitions' short names.
            ->assertSee('BJCP 2021 / 2025')
            ->assertSee('BJCP 2015 / 2021')
            ->assertSee('BA 2026')
            ->assertSee('AABC 2022')
            ->assertSee('AABC 2025')
            ->assertSee('NW Cider Cup')
            // Deliberately excluded values are not offered.
            ->assertDontSee('value="BJCP2008"', false)
            ->assertDontSee('value="BJCP2015"', false)
            ->assertDontSee('value="AABC"', false)
            ->assertDontSee('value="BA"', false);
    }

    /**
     * The reported bug: BJCP2025 must rebuild the dual-version union (~159
     * seeded rows), not the 16 BJCP2025-only rows.
     */
    public function test_style_set_change_to_bjcp2025_rebuilds_the_dual_version_union(): void
    {
        $this->remember('preferences');
        $this->rememberStyleLimits();

        // A different starting set so the rebuild branch actually runs.
        DB::table('preferences')->where('id', 1)->update(['prefsStyleSet' => 'BJCP2021']);

        $this->put('/admin/site-preferences/entries', [
            'prefsStyleSet' => 'BJCP2025',
            'prefsEntryForm' => '7',
            'prefsSpecific' => '0',
            'prefsSpecialCharLimit' => '150',
            'choose-style-entry-limits' => '1',
        ])->assertRedirect('/admin/site-preferences/entries?msg=2');

        $selected = json_decode((string) DB::table('preferences')->where('id', 1)->value('prefsSelectedStyles'), true);
        self::assertIsArray($selected);

        $expected = DB::table('styles')->where(function ($q): void {
            $q->where(function ($qq): void {
                $qq->where('brewStyleVersion', 'BJCP2025')->where('brewStyleType', '2');
            })->orWhere(function ($qq): void {
                $qq->where('brewStyleVersion', 'BJCP2021')->where('brewStyleType', '!=', '2');
            })->orWhere('brewStyleOwn', 'custom');
        })->count();
        $plain = DB::table('styles')->where('brewStyleVersion', 'BJCP2025')->count();

        self::assertGreaterThan(140, $expected, 'seeded dual-version union is ~159 rows');
        self::assertCount($expected, $selected);
        // Plain version equality selects only the 16 BJCP2025 "other" rows.
        self::assertNotSame($plain, count($selected));
    }

    /**
     * BA2026 is offered by the picker but carried no `styles` rows, so
     * selecting it rebuilt an empty accepted-styles list. The seed migration
     * ships the upstream 2026 Brewers Association guidelines (169 rows,
     * groups 01-09 + 11) — the rebuild must pick them up.
     */
    public function test_style_set_change_to_ba2026_rebuilds_the_imported_rows(): void
    {
        $this->remember('preferences');
        $this->rememberStyleLimits();

        $imported = DB::table('styles')->where('brewStyleVersion', 'BA2026')->count();
        self::assertSame(169, $imported, 'seed migration ships the upstream BA 2026 guidelines');

        // A different starting set so the rebuild branch actually runs.
        DB::table('preferences')->where('id', 1)->update(['prefsStyleSet' => 'BJCP2021']);

        $this->put('/admin/site-preferences/entries', [
            'prefsStyleSet' => 'BA2026',
            'prefsEntryForm' => '7',
            'prefsSpecific' => '0',
            'prefsSpecialCharLimit' => '150',
            'choose-style-entry-limits' => '1',
        ])->assertRedirect('/admin/site-preferences/entries?msg=2');

        $selected = json_decode((string) DB::table('preferences')->where('id', 1)->value('prefsSelectedStyles'), true);
        self::assertIsArray($selected);
        self::assertNotEmpty($selected, 'BA2026 rebuild must not be empty');

        // Single-version set: version equality plus customs (StyleSets::activeQuery).
        $expected = DB::table('styles')->where(function ($q): void {
            $q->where('brewStyleVersion', 'BA2026')->orWhere('brewStyleOwn', 'custom');
        })->count();
        self::assertCount($expected, $selected);

        // BA sets carry no group/sub style code in displays
        // (StyleSets::noNumbering — honoured by AwardDeckBuilder, pullsheets,
        // labels and the brew form).
        self::assertTrue(StyleSets::noNumbering('BA2026'));
    }

    /** AABC2022 (single-version) still rebuilds from its own rows (~147). */
    public function test_style_set_change_to_aabc2022_rebuilds_its_own_version_rows(): void
    {
        $this->remember('preferences');
        $this->rememberStyleLimits();

        DB::table('preferences')->where('id', 1)->update(['prefsStyleSet' => 'BJCP2021']);

        $this->put('/admin/site-preferences/entries', [
            'prefsStyleSet' => 'AABC2022',
            'prefsEntryForm' => '7',
            'prefsSpecific' => '0',
            'prefsSpecialCharLimit' => '150',
            'choose-style-entry-limits' => '1',
        ])->assertRedirect('/admin/site-preferences/entries?msg=2');

        $selected = json_decode((string) DB::table('preferences')->where('id', 1)->value('prefsSelectedStyles'), true);
        self::assertIsArray($selected);

        $expected = DB::table('styles')->where(function ($q): void {
            $q->where('brewStyleVersion', 'AABC2022')->orWhere('brewStyleOwn', 'custom');
        })->count();

        self::assertGreaterThan(100, $expected);
        self::assertCount($expected, $selected);
    }

    /** Validation is pinned to StyleSets::names() — legacy values rejected. */
    public function test_entries_tab_rejects_a_set_outside_the_definition(): void
    {
        $this->remember('preferences');
        $this->rememberStyleLimits();

        $before = DB::table('preferences')->where('id', 1)->value('prefsStyleSet');

        $this->put('/admin/site-preferences/entries', [
            'prefsStyleSet' => 'BJCP2008',
            'prefsEntryForm' => '7',
            'prefsSpecific' => '0',
            'prefsSpecialCharLimit' => '150',
            'choose-style-entry-limits' => '1',
        ])->assertSessionHasErrors('prefsStyleSet');

        self::assertSame($before, DB::table('preferences')->where('id', 1)->value('prefsStyleSet'));
    }

    /**
     * Upstream 3.1.0 "Entry fee amounts now support more foreign currency
     * formats without being cut off" (update/run_update.php:4960-4972)
     * widened exactly these three contest_info columns from float(6,2) to
     * DECIMAL(9,2).
     */
    public function test_entry_fee_columns_are_decimal_9_2(): void
    {
        foreach (['contestEntryFee', 'contestEntryFee2', 'contestEntryFeePasswordNum'] as $column) {
            $type = (string) DB::selectOne(
                'SHOW COLUMNS FROM `'.DB::getTablePrefix().'contest_info` WHERE Field = ?',
                [$column],
            )->Type;

            self::assertSame('decimal(9,2)', $type, $column);
        }
    }

    /**
     * Real write path (admin entries tab) + read back: 123456.78 needs six
     * integer digits, so float(6,2) — which held at most 9999.99 — cut it
     * off. DECIMAL(9,2) holds it, and every fee display shows it whole.
     */
    public function test_wide_entry_fee_persists_without_clipping(): void
    {
        $this->remember('preferences');
        $this->remember('contest_info');
        $this->rememberStyleLimits();

        $set = (string) DB::table('preferences')->where('id', 1)->value('prefsStyleSet');

        $this->put('/admin/site-preferences/entries', [
            'contestEntryFee' => '123456.78',
            'contestEntryFee2' => '5000.50',
            'contestEntryFeeDiscountNum' => '5',
            'prefsStyleSet' => $set,
            'prefsEntryForm' => '7',
            'prefsSpecific' => '0',
            'prefsSpecialCharLimit' => '150',
            'choose-style-entry-limits' => '0',
        ])->assertRedirect('/admin/site-preferences/entries?msg=2');

        $contest = (array) DB::table('contest_info')->where('id', 1)->first();
        self::assertSame('123456.78', (string) $contest['contestEntryFee']);
        self::assertSame('5000.50', (string) $contest['contestEntryFee2']);

        // The top of the new range round-trips too (decimal(9,2) ceiling).
        DB::table('contest_info')->where('id', 1)->update(['contestEntryFee' => '9999999.99']);
        self::assertSame('9999999.99', (string) DB::table('contest_info')->where('id', 1)->value('contestEntryFee'));
        DB::table('contest_info')->where('id', 1)->update(['contestEntryFee' => '123456.78']);

        // Admin fee screen renders the stored amount verbatim — no cast,
        // no number_format truncation.
        $this->get('/admin/site-preferences/entries')
            ->assertOk()
            ->assertSee('value="123456.78"', false);

        // Fee math takes the whole amount (BCMath at 2dp, no clipping).
        $params = FeeCalculator::params(TenantContext::load());
        self::assertSame('123456.78', FeeCalculator::total(1, false, $params));
        self::assertSame('246913.56', FeeCalculator::total(2, false, $params));

        // Nothing left over from a clipped write.
        self::assertStringNotContainsString('9999.99', (string) DB::table('contest_info')->where('id', 1)->value('contestEntryFee'));
    }

    /**
     * Upstream 3.1.0 added Korean Won (lib/common.lib.php: switch
     * `case 'krw': '&#8361;^KRW'` = ₩ + code KRW; method-2 dropdown row
     * `krw^&#8361; Won^KRW`). Selectable on the Payment tab, and the symbol
     * the port renders is ₩ (U+20A9).
     */
    public function test_krw_currency_is_selectable_and_maps_to_won(): void
    {
        $this->remember('preferences');

        $this->put('/admin/site-preferences/payment', [
            'prefsCurrency' => 'krw',
            'prefsPayToPrint' => '0',
            'prefsCash' => '1',
            'prefsCheck' => '0',
            'prefsTransFee' => 'N',
        ])->assertRedirect('/admin/site-preferences/payment?msg=2');

        self::assertSame('krw', (string) DB::table('preferences')->where('id', 1)->value('prefsCurrency'));
        self::assertSame('₩', "\u{20A9}");
        self::assertSame('₩', TenantContext::load()->currencySymbol());

        $this->get('/admin/site-preferences/payment')
            ->assertOk()
            ->assertSee('value="krw"', false);
    }

    /** Every pre-3.1.0 currency keeps its legacy symbol (switch side of "^"). */
    public function test_currency_symbol_map_matches_legacy_and_keeps_existing_entries(): void
    {
        $this->remember('preferences');

        $expected = [
            '$' => '$', 'R$' => 'R$', 'pound' => '£', 'czkoruna' => 'Kč', 'euro' => '€',
            'A$' => '$', 'C$' => '$', 'H$' => '$', 'N$' => '$', 'S$' => '$', 'T$' => '$',
            'Ft' => 'Ft', 'shekel' => '₪', 'yen' => '¥',
            'nkr' => 'kr', 'kr' => 'kr', 'skr' => 'kr',
            'RM' => 'RM', 'M$' => '$', 'phpeso' => '₱', 'pol' => 'zł', 'p.' => 'p.',
            'sfranc' => '₣', 'baht' => '฿', 'tlira' => '₺', 'R' => 'R', 'rupee' => '₹',
            'krw' => '₩',
        ];

        foreach ($expected as $pref => $symbol) {
            DB::table('preferences')->where('id', 1)->update(['prefsCurrency' => $pref]);
            self::assertSame($symbol, TenantContext::load()->currencySymbol(), 'prefsCurrency='.$pref);
        }

        // Unknown / blank prefs fall through to the raw value (legacy default).
        DB::table('preferences')->where('id', 1)->update(['prefsCurrency' => 'XYZ']);
        self::assertSame('XYZ', TenantContext::load()->currencySymbol());
    }

    /**
     * Issue 26: contestEntryFeePasswordNum is decimal(9,2) but was validated
     * as an integer, so a decimal member fee was rejected/truncated.
     */
    public function test_member_discount_fee_accepts_decimals(): void
    {
        $this->remember('preferences');
        $this->remember('contest_info');
        $set = (string) DB::table('preferences')->where('id', 1)->value('prefsStyleSet');

        $this->put('/admin/site-preferences/entries', [
            'contestEntryFee' => '10.00',
            'contestEntryFee2' => '10.00',
            'contestEntryFeeDiscountNum' => '5',
            'contestEntryFeePasswordNum' => '12.50',
            'prefsStyleSet' => $set,
            'prefsEntryForm' => '7',
            'prefsSpecific' => '0',
            'prefsSpecialCharLimit' => '150',
            'choose-style-entry-limits' => '0',
        ])->assertRedirect('/admin/site-preferences/entries?msg=2');

        self::assertSame('12.50', (string) DB::table('contest_info')->where('id', 1)->value('contestEntryFeePasswordNum'));
    }

    /**
     * Issue 26: the Payment-tab currency dropdown had lost four options that
     * the symbol map (and legacy) still carry.
     */
    public function test_payment_currency_dropdown_offers_all_legacy_currencies(): void
    {
        $html = (string) $this->get('/admin/site-preferences/payment')->assertOk()->getContent();

        self::assertMatchesRegularExpression('/<option value="R"[\s>]/', $html, 'R (South African Rand) missing');
        foreach (['baht', 'tlira', 'rupee'] as $curr) {
            self::assertStringContainsString('value="'.$curr.'"', $html, $curr.' missing');
        }
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
