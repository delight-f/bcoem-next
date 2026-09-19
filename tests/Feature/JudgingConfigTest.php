<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

/**
 * Admin judging config screens (spec §6 P4.1, ticket 01): CRUD round-trips
 * for judging sessions, non-judging sessions (same table,
 * judgingLocType=2), drop-off locations, tables, and judging preferences —
 * with the legacy storage transforms pinned: UTC epochs for dates,
 * blank_to_null on text columns, tableStyles as a CSV of styles.ids
 * (ledger/flight-assignment.md #6), table numbering max+1 (#3), and the
 * judging-preferences write split across `judging_preferences` id=1 and
 * the same `preferences` id=1 rows the public surface reads.
 */
final class JudgingConfigTest extends PublicSurfaceTestCase
{
    private const ADMIN_EMAIL = 'config.admin@brewingcompetitions.com';

    private const ADMIN_ID = 9301;

    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    /** @var list<int> */
    private array $locationIds = [];

    /** @var list<int> */
    private array $dropoffIds = [];

    /** @var list<int> */
    private array $tableIds = [];

    /** @var array<string, mixed> */
    private array $origJudgingPrefs = [];

    /** @var array<string, mixed> */
    private array $origPrefs = [];

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('users')->where('user_name', self::ADMIN_EMAIL)->delete();
        DB::table('users')->insert([
            'id' => self::ADMIN_ID,
            'user_name' => self::ADMIN_EMAIL,
            'password' => self::HASH,
            'userLevel' => '1',
            'userCreated' => '2024-01-01 00:00:01',
            'userAdminObfuscate' => 0,
        ]);

        $this->loginWithEmail(self::ADMIN_EMAIL);
    }

    protected function tearDown(): void
    {
        foreach ($this->locationIds as $id) {
            $this->delete('/admin/judging/locations/'.$id);
        }
        DB::table('judging_locations')->whereIn('id', $this->locationIds ?: [0])->delete();
        DB::table('drop_off')->whereIn('id', $this->dropoffIds ?: [0])->delete();

        // Restore table cascade side effects before removing the rows.
        DB::table('judging_scores')->whereIn('scoreTable', $this->tableIds ?: [0])->delete();
        DB::table('judging_flights')->whereIn('flightTable', $this->tableIds ?: [0])->delete();
        DB::table('judging_tables')->whereIn('id', $this->tableIds ?: [0])->delete();

        if ($this->origJudgingPrefs !== []) {
            DB::table('judging_preferences')->where('id', 1)->update($this->origJudgingPrefs);
        }
        if ($this->origPrefs !== []) {
            DB::table('preferences')->where('id', 1)->update($this->origPrefs);
        }

        DB::table('brewer')->where('uid', self::ADMIN_ID)->delete();
        DB::table('users')->where('id', self::ADMIN_ID)->delete();

        parent::tearDown();
    }

    private function rememberConfigRows(): void
    {
        if ($this->origJudgingPrefs === []) {
            $row = (array) DB::table('judging_preferences')->where('id', 1)->first();
            $this->origJudgingPrefs = collect($row)->except(['id'])->all();
        }
        if ($this->origPrefs === []) {
            $row = (array) DB::table('preferences')->where('id', 1)->first();
            $this->origPrefs = collect($row)->except(['id'])->all();
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lastLocation(): ?array
    {
        $row = DB::table('judging_locations')->orderByDesc('id')->first();

        return $row === null ? null : (array) $row;
    }

    public function test_judging_session_round_trip_stores_epochs_and_blank_to_null(): void
    {
        $response = $this->post('/admin/judging/locations', [
            'judgingLocName' => 'Session 1',
            'judgingLocType' => '0',
            'judgingDate' => '2030-06-15 09:00 AM',
            'judgingDateEnd' => '',
            'judgingLocation' => 'Homebrew Shop',
            'judgingRounds' => '2',
            'judgingLocNotes' => '',
        ]);
        $response->assertRedirect('/admin/judging/locations');

        $row = $this->lastLocation();
        self::assertNotNull($row);
        $this->locationIds[] = (int) $row['id'];

        // 2030-06-15 09:00 AM America/Denver (baseline tz, June = MDT -6)
        // → 15:00 UTC.
        self::assertSame(strtotime('2030-06-15 15:00 UTC'), (int) $row['judgingDate']);
        self::assertNull($row['judgingDateEnd']);
        self::assertNull($row['judgingLocNotes']);
        self::assertSame(2, (int) $row['judgingRounds']);
        self::assertSame(0, (int) $row['judgingLocType']);

        $response = $this->put('/admin/judging/locations/'.((int) $row['id']), [
            'judgingLocName' => 'Session 1 Renamed',
            'judgingLocType' => '1',
            'judgingDate' => '2030-06-16 10:00 AM',
            'judgingDateEnd' => '2030-06-20 05:00 PM',
            'judgingLocation' => 'Distributed',
            'judgingRounds' => '2',
            'judgingLocNotes' => 'ship entries',
        ]);
        $response->assertRedirect('/admin/judging/locations');

        $updated = (array) DB::table('judging_locations')->where('id', $row['id'])->first();
        self::assertSame('Session 1 Renamed', $updated['judgingLocName']);
        self::assertSame(strtotime('2030-06-20 23:00 UTC'), (int) $updated['judgingDateEnd']);
        self::assertSame('ship entries', $updated['judgingLocNotes']);
    }

    public function test_session_rounds_capped_by_preference(): void
    {
        $this->rememberConfigRows();
        DB::table('judging_preferences')->where('id', 1)->update(['jPrefsRounds' => 2]);

        $before = DB::table('judging_locations')->count();
        $payload = [
            'judgingLocName' => 'Capped Session',
            'judgingLocType' => '0',
            'judgingDate' => '2030-06-15 09:00 AM',
            'judgingDateEnd' => '',
            'judgingLocation' => 'Hall',
            'judgingLocNotes' => '',
        ];

        // Above the configured maximum: rejected, nothing written.
        $this->from('/admin/judging/locations/create')
            ->post('/admin/judging/locations', $payload + ['judgingRounds' => '3'])
            ->assertSessionHasErrors('judgingRounds');
        self::assertSame($before, DB::table('judging_locations')->count());

        // At the maximum: stored.
        $this->post('/admin/judging/locations', $payload + ['judgingRounds' => '2'])
            ->assertRedirect('/admin/judging/locations');
        $row = $this->lastLocation();
        self::assertNotNull($row);
        $this->locationIds[] = (int) $row['id'];
        self::assertSame(2, (int) $row['judgingRounds']);

        // A pre-existing over-cap session keeps its rounds when edited…
        DB::table('judging_locations')->where('id', $row['id'])->update(['judgingRounds' => 5]);
        $this->put('/admin/judging/locations/'.((int) $row['id']), $payload + ['judgingRounds' => '5'])
            ->assertRedirect('/admin/judging/locations');
        self::assertSame(5, (int) DB::table('judging_locations')->where('id', $row['id'])->value('judgingRounds'));

        // …but cannot be raised further beyond the cap.
        $this->from('/admin/judging/locations/'.((int) $row['id']).'/edit')
            ->put('/admin/judging/locations/'.((int) $row['id']), $payload + ['judgingRounds' => '6'])
            ->assertSessionHasErrors('judgingRounds');
        self::assertSame(5, (int) DB::table('judging_locations')->where('id', $row['id'])->value('judgingRounds'));

        // An unset (NULL) pref imposes no cap.
        DB::table('judging_preferences')->where('id', 1)->update(['jPrefsRounds' => null]);
        $this->post('/admin/judging/locations', $payload + ['judgingRounds' => '5'])
            ->assertRedirect('/admin/judging/locations');
        $unset = $this->lastLocation();
        self::assertNotNull($unset);
        $this->locationIds[] = (int) $unset['id'];
        self::assertSame(5, (int) $unset['judgingRounds']);
    }

    public function test_distributed_session_requires_end_date(): void
    {
        $before = DB::table('judging_locations')->count();

        $response = $this->from('/admin/judging/locations/create')
            ->post('/admin/judging/locations', [
                'judgingLocName' => 'Distributed Week',
                'judgingLocType' => '1',
                'judgingDate' => '2030-06-15 09:00 AM',
                'judgingDateEnd' => '',
                'judgingLocation' => 'Everywhere',
                'judgingRounds' => '1',
            ]);

        $response->assertSessionHasErrors('judgingDateEnd');
        self::assertSame($before, DB::table('judging_locations')->count());
    }

    public function test_non_judging_session_stored_with_type_2(): void
    {
        $response = $this->post('/admin/judging/non-judging', [
            'judgingLocName' => 'Entry Sorting',
            'judgingDate' => '2030-06-14 06:00 PM',
            'judgingLocation' => 'Warehouse',
            'judgingLocNotes' => '',
        ]);
        $response->assertRedirect('/admin/judging/non-judging');

        $row = $this->lastLocation();
        self::assertNotNull($row);
        $this->locationIds[] = (int) $row['id'];

        self::assertSame(2, (int) $row['judgingLocType']);
        self::assertNull($row['judgingRounds']);
        self::assertNull($row['judgingDateEnd']);
    }

    public function test_location_delete_strips_brewer_availability_marks(): void
    {
        $locId = (int) DB::table('judging_locations')->insertGetId([
            'judgingLocType' => 0,
            'judgingDate' => strtotime('2030-06-15 14:00 UTC'),
            'judgingLocName' => 'Marked Session',
            'judgingLocation' => 'Somewhere',
            'judgingRounds' => 1,
        ]);
        $this->locationIds[] = $locId;

        DB::table('brewer')->insert([
            'uid' => self::ADMIN_ID,
            'brewerFirstName' => 'Config',
            'brewerLastName' => 'Admin',
            'brewerEmail' => self::ADMIN_EMAIL,
            'brewerJudgeLocation' => 'Y-'.$locId.',N-9999',
            'brewerStewardLocation' => 'Y-8888,Y-'.$locId,
        ]);

        $this->delete('/admin/judging/locations/'.$locId)->assertRedirect('/admin/judging/locations');

        self::assertNull(DB::table('judging_locations')->where('id', $locId)->first());
        $brewer = (array) DB::table('brewer')->where('uid', self::ADMIN_ID)->first();
        self::assertSame('N-9999', $brewer['brewerJudgeLocation']);
        self::assertSame('Y-8888', $brewer['brewerStewardLocation']);
    }

    public function test_dropoff_round_trip_applies_capitalize_check_http_and_blank_to_null(): void
    {
        $response = $this->post('/admin/dropoff', [
            'dropLocationName' => 'john Doe-Smith Brewing CO.',
            'dropLocationPhone' => '555-867-5309',
            'dropLocation' => '100 Main St, Anytown',
            'dropLocationWebsite' => 'Example.com/pickup',
            'dropLocationNotes' => '',
        ]);
        $response->assertRedirect('/admin/dropoff');

        $found = DB::table('drop_off')->where('dropLocationName', 'John Doe-Smith Brewing Co.')->first();
        self::assertNotNull($found);
        $row = (array) $found;

        self::assertSame('http://example.com/pickup', $row['dropLocationWebsite']);
        self::assertNull($row['dropLocationNotes']);

        $this->put('/admin/dropoff/'.((int) $row['id']), [
            'dropLocationName' => 'John Doe-Smith Brewing Co.',
            'dropLocationPhone' => '555-867-5309',
            'dropLocation' => '200 Oak Ave, Anytown',
            'dropLocationWebsite' => '',
            'dropLocationNotes' => 'call first',
        ])->assertRedirect('/admin/dropoff');

        $updated = (array) DB::table('drop_off')->where('id', $row['id'])->first();
        self::assertSame('200 Oak Ave, Anytown', $updated['dropLocation']);
        self::assertNull($updated['dropLocationWebsite']);
        self::assertSame('call first', $updated['dropLocationNotes']);

        $this->delete('/admin/dropoff/'.((int) $row['id']))->assertRedirect('/admin/dropoff');
        self::assertNull(DB::table('drop_off')->where('id', $row['id'])->first());
        $this->dropoffIds = array_values(array_diff($this->dropoffIds, [(int) $row['id']]));
    }

    public function test_dropoff_requires_name_phone_address(): void
    {
        $before = DB::table('drop_off')->count();

        $this->post('/admin/dropoff', [
            'dropLocationName' => '',
            'dropLocationPhone' => '',
            'dropLocation' => '',
        ])->assertSessionHasErrors(['dropLocationName', 'dropLocationPhone', 'dropLocation']);

        self::assertSame($before, DB::table('drop_off')->count());
    }

    public function test_table_round_trip_uses_max_plus_one_numbering_and_styles_csv(): void
    {
        $locId = (int) DB::table('judging_locations')->insertGetId([
            'judgingLocType' => 0,
            'judgingDate' => strtotime('2030-06-15 14:00 UTC'),
            'judgingLocName' => 'Table Home',
            'judgingLocation' => 'Hall A',
            'judgingRounds' => 2,
        ]);
        $this->locationIds[] = $locId;

        $styleIds = DB::table('styles')->limit(2)->pluck('id')->all();
        self::assertCount(2, $styleIds);

        $maxBefore = (int) DB::table('judging_tables')->max('tableNumber');

        $response = $this->post('/admin/judging/tables', [
            'tableName' => 'IPA Flight',
            'tableNumber' => (string) ($maxBefore + 1),
            'tableLocation' => (string) $locId,
            'tableEntryLimit' => '48',
            'tableStyles' => $styleIds,
        ]);
        $response->assertRedirect('/admin/judging/tables');

        $found = DB::table('judging_tables')->where('tableName', 'IPA Flight')->first();
        self::assertNotNull($found);
        $row = (array) $found;

        self::assertSame($maxBefore + 1, (int) $row['tableNumber']);
        // Organizer-defined style grouping: CSV of styles.ids (ledger #6).
        self::assertSame(implode(',', $styleIds), $row['tableStyles']);
        self::assertSame(48, (int) $row['tableEntryLimit']);

        // Duplicate number rejected.
        $this->post('/admin/judging/tables', [
            'tableName' => 'Second Table',
            'tableNumber' => $row['tableNumber'],
            'tableLocation' => (string) $locId,
        ])->assertSessionHasErrors('tableNumber');
        self::assertNull(DB::table('judging_tables')->where('tableName', 'Second Table')->first());

        $this->put('/admin/judging/tables/'.((int) $row['id']), [
            'tableName' => 'IPA Flight Renamed',
            'tableNumber' => $row['tableNumber'],
            'tableLocation' => (string) $locId,
            'tableEntryLimit' => '',
            'tableStyles' => [$styleIds[0]],
        ])->assertRedirect('/admin/judging/tables');

        $updated = (array) DB::table('judging_tables')->where('id', $row['id'])->first();
        self::assertSame('IPA Flight Renamed', $updated['tableName']);
        self::assertSame((string) $styleIds[0], $updated['tableStyles']);
        self::assertNull($updated['tableEntryLimit']);
    }

    public function test_table_delete_cascades_scores_flights_and_bos_rows(): void
    {
        $tableId = (int) DB::table('judging_tables')->insertGetId([
            'tableName' => 'Cascade Table',
            'tableStyles' => '1',
            'tableNumber' => ((int) DB::table('judging_tables')->max('tableNumber')) + 1,
            'tableLocation' => 1,
        ]);
        $this->tableIds[] = $tableId;

        $entryId = (int) DB::table('brewing')->insertGetId([
            'brewName' => 'Cascade Entry',
            'brewBrewerID' => self::ADMIN_ID,
            'brewCategorySort' => '1',
            'brewCategory' => '1',
            'brewSubCategory' => 'A',
            'brewStyle' => '1-A',
            'brewConfirmed' => '1',
        ]);

        $scoreId = (int) DB::table('judging_scores')->insertGetId(['eid' => $entryId, 'scoreTable' => $tableId]);
        $flightId = (int) DB::table('judging_flights')->insertGetId([
            'flightTable' => $tableId,
            'flightNumber' => 1,
            'flightEntryID' => $entryId,
        ]);
        $bosId = (int) DB::table('judging_scores_bos')->insertGetId(['eid' => $entryId, 'scorePlace' => 1]);

        $this->delete('/admin/judging/tables/'.$tableId)->assertRedirect('/admin/judging/tables');

        self::assertNull(DB::table('judging_tables')->where('id', $tableId)->first());
        self::assertNull(DB::table('judging_scores')->where('id', $scoreId)->first());
        self::assertNull(DB::table('judging_flights')->where('id', $flightId)->first());
        self::assertNull(DB::table('judging_scores_bos')->where('id', $bosId)->first());

        DB::table('brewing')->where('id', $entryId)->delete();
        $this->tableIds = array_values(array_diff($this->tableIds, [$tableId]));
    }

    public function test_preferences_write_core_rows_without_eval_columns(): void
    {
        $this->rememberConfigRows();

        DB::table('preferences')->where('id', 1)->update(['prefsEval' => 0]);
        DB::table('judging_preferences')->where('id', 1)->update([
            'jPrefsCapJudges' => 5,
            'jPrefsCapStewards' => null,
        ]);

        $this->post('/admin/judging/preferences', [
            'jPrefsQueued' => 'N',
            'jPrefsBottleNum' => '2',
            'jPrefsFlightEntries' => '10',
            'jPrefsMaxBOS' => '3',
            'jPrefsRounds' => '2',
            'jPrefsCapJudges' => '0',
            'jPrefsCapStewards' => '',
            'prefsEval' => '0',
            'prefsDisplaySpecial' => 'E',
        ])->assertRedirect('/admin/judging/preferences');

        $j = (array) DB::table('judging_preferences')->where('id', 1)->first();
        self::assertSame('N', $j['jPrefsQueued']);
        self::assertSame(2, (int) $j['jPrefsBottleNum']);
        self::assertSame(10, (int) $j['jPrefsFlightEntries']);
        self::assertSame(3, (int) $j['jPrefsMaxBOS']);
        self::assertSame(2, (int) $j['jPrefsRounds']);
        // A posted 0 survives (no limit ≠ unset); a blank stays/becomes NULL.
        self::assertSame(0, (int) $j['jPrefsCapJudges']);
        self::assertNull($j['jPrefsCapStewards']);

        $p = (array) DB::table('preferences')->where('id', 1)->first();
        self::assertSame(0, (int) $p['prefsEval']);
        self::assertSame('E', $p['prefsDisplaySpecial']);
    }

    public function test_preferences_eval_enabled_writes_scoresheet_block_and_clamps_dates(): void
    {
        $this->rememberConfigRows();

        $open = strtotime('2030-06-15 15:00 UTC');   // earliest session start
        $close = strtotime('2030-06-20 23:00 UTC');  // latest session end
        $locId = (int) DB::table('judging_locations')->insertGetId([
            'judgingLocType' => 1,
            'judgingDate' => $open,
            'judgingDateEnd' => $close,
            'judgingLocName' => 'Clamp Session',
            'judgingLocation' => 'X',
            'judgingRounds' => 1,
        ]);
        $this->locationIds[] = $locId;

        DB::table('preferences')->where('id', 1)->update(['prefsEval' => 1]);
        DB::table('judging_preferences')->where('id', 1)->update(['jPrefsScoresheet' => 4]);

        // Posted open after the earliest session → clamped DOWN to it;
        // posted close before the latest session end → clamped UP to it.
        $this->post('/admin/judging/preferences', [
            'jPrefsQueued' => 'Y',
            'jPrefsBottleNum' => '2',
            'jPrefsFlightEntries' => '12',
            'jPrefsMaxBOS' => '2',
            'jPrefsRounds' => '1',
            'prefsEval' => '1',
            'prefsDisplaySpecial' => 'J',
            'jPrefsScoresheet' => '3',
            'jPrefsMinWords' => '25',
            'jPrefsScoreDispMax' => '8',
            'jPrefsJudgingOpen' => '2030-07-01 09:00 AM',
            'jPrefsJudgingClosed' => '2030-06-16 09:00 AM',
        ])->assertRedirect('/admin/judging/preferences');

        $j = (array) DB::table('judging_preferences')->where('id', 1)->first();
        self::assertSame(3, (int) $j['jPrefsScoresheet']);
        self::assertSame(25, (int) $j['jPrefsMinWords']);
        self::assertSame(8, (int) $j['jPrefsScoreDispMax']);
        self::assertSame($open, (int) $j['jPrefsJudgingOpen']);
        self::assertSame($close, (int) $j['jPrefsJudgingClosed']);
    }

    public function test_preferences_reject_invalid_queued_flag(): void
    {
        $this->rememberConfigRows();

        $this->post('/admin/judging/preferences', [
            'jPrefsQueued' => 'maybe',
            'jPrefsBottleNum' => '2',
            'jPrefsFlightEntries' => '10',
            'jPrefsMaxBOS' => '3',
            'jPrefsRounds' => '2',
            'prefsEval' => '0',
            'prefsDisplaySpecial' => 'J',
        ])->assertSessionHasErrors('jPrefsQueued');
    }

    public function test_config_screens_require_admin(): void
    {
        Session::flush();
        $this->app['auth']->forgetGuards();

        foreach ([
            '/admin/judging/locations',
            '/admin/judging/non-judging',
            '/admin/dropoff',
            '/admin/judging/tables',
            '/admin/judging/preferences',
        ] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    public function test_config_pages_render_for_admin(): void
    {
        $locId = (int) DB::table('judging_locations')->insertGetId([
            'judgingLocType' => 0,
            'judgingDate' => strtotime('2030-06-15 15:00 UTC'),
            'judgingLocName' => 'Render Session',
            'judgingLocation' => 'Hall',
            'judgingRounds' => 1,
        ]);
        $this->locationIds[] = $locId;

        $tableId = (int) DB::table('judging_tables')->insertGetId([
            'tableName' => 'Render Table',
            'tableNumber' => ((int) DB::table('judging_tables')->max('tableNumber')) + 1,
        ]);
        $this->tableIds[] = $tableId;
        $dropoffId = (int) DB::table('drop_off')->insertGetId(['dropLocationName' => 'Render Spot']);
        $this->dropoffIds[] = $dropoffId;

        $this->get('/admin/judging/locations')
            ->assertOk()
            ->assertSeeText('Render Session');
        $this->get('/admin/judging/locations/create')->assertOk();
        $this->get('/admin/judging/locations/'.$locId.'/edit')->assertOk();

        $this->get('/admin/judging/non-judging')->assertOk()->assertSeeText('Non-Judging Sessions');
        $this->get('/admin/judging/non-judging/create')->assertOk();

        $this->get('/admin/dropoff')->assertOk()->assertSeeText('Render Spot');
        $this->get('/admin/dropoff/create')->assertOk();

        // The mode banner is visible text; the switch-button help lives in
        // the tooltip title attribute, so assert against the raw HTML.
        $tablesHtml = (string) $this->get('/admin/judging/tables')->assertOk()->getContent();
        self::assertStringContainsString('Render Table', $tablesHtml);
        self::assertStringContainsString('Your installation is currently in Tables Competition Mode', $tablesHtml);
        self::assertStringContainsString('When the Tables Competition Mode function is enabled', $tablesHtml);
        // Issue #37: judge assignment control = gavel, steward = clipboard;
        // the old padlock icon is gone.
        self::assertStringNotContainsString('fa-lock', $tablesHtml);
        self::assertMatchesRegularExpression('#/assign/judges[^>]*><span class="fa fa-lg fa-gavel"></span>#', $tablesHtml);
        self::assertMatchesRegularExpression('#/assign/stewards[^>]*><span class="fa fa-lg fa-clipboard"></span>#', $tablesHtml);
        $this->get('/admin/judging/tables/create')->assertOk();
        $this->get('/admin/judging/tables/'.$tableId.'/edit')->assertOk();

        $this->get('/admin/judging/preferences')
            ->assertOk()
            ->assertSeeText('Judging/Competition Organization');
    }

    /**
     * Issue #58: the judging preferences page used a row of blue buttons while
     * every other preferences page used a tab bar. Both now render the same
     * shared partial, so the tabs (and their targets) cannot drift.
     */
    public function test_preference_pages_share_one_tab_bar(): void
    {
        $judging = (string) $this->get('/admin/judging/preferences')->assertOk()->getContent();
        $bar = $this->tabBar($judging);

        // Six tabs, all six destinations present.
        self::assertSame(6, substr_count($bar, 'class="nav-link '));
        foreach (['default', 'entries', 'email', 'payment', 'best'] as $go) {
            self::assertStringContainsString('href="'.url('/admin/site-preferences/'.$go).'"', $bar);
        }
        self::assertStringContainsString('href="'.route('admin.judging.preferences.show').'"', $bar);

        // Judging is the one active tab: exactly one aria-current, on it.
        self::assertStringContainsString(
            '<a class="nav-link active" aria-current="page" href="'.route('admin.judging.preferences.show').'">Judging/Competition Organization</a>',
            $bar,
        );
        self::assertSame(1, substr_count($bar, 'aria-current="page"'));

        // The old blue button row is gone.
        self::assertStringNotContainsString('General Preferences', $judging);

        // The site-preferences pages render the same bar (now with six tabs),
        // with their own tab active.
        $site = $this->tabBar((string) $this->get('/admin/site-preferences')->assertOk()->getContent());
        self::assertSame(6, substr_count($site, 'class="nav-link '));
        self::assertStringContainsString('href="'.route('admin.judging.preferences.show').'"', $site);
        self::assertStringContainsString(
            '<a class="nav-link active" aria-current="page" href="'.url('/admin/site-preferences/default').'">General</a>',
            $site,
        );
    }

    /** The preference tab bar markup, sliced out of a rendered page. */
    private function tabBar(string $html): string
    {
        $start = strpos($html, 'nav nav-tabs mb-4');
        self::assertNotFalse($start, 'preference tab bar not found');
        $end = strpos($html, '</ul>', (int) $start);
        self::assertNotFalse($end, 'preference tab bar not closed');

        return substr($html, (int) $start, (int) $end - (int) $start);
    }

    public function test_tables_empty_state_renders_as_an_alert(): void
    {
        // Issue #37: the bare "No tables have been defined." paragraph is now
        // a proper alert. Rendered directly so the assertion does not depend
        // on whether the shared corpus holds any tables.
        $html = view('judging.config.tables', [
            'ctx' => TenantContext::load(),
            'tables' => collect(),
            'planning' => false,
            'sessionCount' => 1,
            'obfuscate' => false,
            'unassignedJudges' => collect(),
            'unassignedStewards' => collect(),
        ])->render();

        self::assertStringContainsString('alert alert-info', $html);
        self::assertStringContainsString('No tables have been defined.', $html);
    }
}
