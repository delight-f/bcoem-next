<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use BCOEM\Tests\Integration\MySqlTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

/**
 * Judging AJAX endpoints (P4.7): tables_mode, practice_session,
 * custom_style. import_scores is pinned in EvalSubAppTest (P4.6 shipped
 * it as eval.import.run).
 *
 * Envelopes are asserted against the captured legacy shapes
 * (ajax/*.ajax.php json_encode output / echo fragments):
 *  - tables_mode:   {"status","error_count","error_type"} stringified;
 *                   empty body for a non-privileged hit.
 *  - practice_session: raw HTML fragments "<Style> Complete<br>" +
 *                   "<Scoresheet Practice> Table Added<br>"; legacy's
 *                   image redirect for non-admins.
 *  - custom_style:  {"status","message"} incl. the untouched
 *                   "Awaiting input." sentinel for statuses 0/4/9.
 */
final class JudgingAjaxTest extends PublicSurfaceTestCase
{
    private const ADMIN = 'admin.judgingajax@brewingcompetitions.com';

    private const JUDGE = 'judge.judgingajax@brewingcompetitions.com';

    private const ENTRANT = 'entrant.judgingajax@brewingcompetitions.com';

    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    private const LEGACY_REDIRECT = 'https://pbs.twimg.com/media/CGx6dsDVIAAV0am.png';

    /** @var list<int> */
    private array $styleIds = [];

    /** @var list<int> */
    private array $entryIds = [];

    /** @var list<int> */
    private array $tableIds = [];

    /** @var list<int> */
    private array $dummyUserIds = [];

    private string $origPlanningFlag = '';

    protected function setUp(): void
    {
        parent::setUp();

        MySqlTestCase::ensureMigrated();

        foreach ([self::ADMIN, self::JUDGE, self::ENTRANT] as $email) {
            DB::table('users')->where('user_name', $email)->delete();
        }

        DB::table('users')->insert([
            ['user_name' => self::ADMIN, 'password' => self::HASH, 'userLevel' => '0', 'userCreated' => '2024-01-01 00:00:01', 'userAdminObfuscate' => 0],
            ['user_name' => self::JUDGE, 'password' => self::HASH, 'userLevel' => '2', 'userCreated' => '2024-01-01 00:00:01', 'userAdminObfuscate' => 0],
            ['user_name' => self::ENTRANT, 'password' => self::HASH, 'userLevel' => '2', 'userCreated' => '2024-01-01 00:00:01', 'userAdminObfuscate' => 1],
        ]);

        // A judge whose availability updates must be restorable.
        $judgeUid = (int) DB::table('users')->where('user_name', self::JUDGE)->value('id');
        DB::table('brewer')->where('uid', $judgeUid)->delete();
        DB::table('brewer')->insert([
            'uid' => $judgeUid,
            'brewerFirstName' => 'Ajax',
            'brewerLastName' => 'Judge',
            'brewerEmail' => self::JUDGE,
            'brewerJudge' => 'Y',
            'brewerJudgeLocation' => '',
        ]);

        $this->origPlanningFlag = (string) DB::table('judging_preferences')->where('id', 1)->value('jPrefsTablePlanning');
    }

    protected function tearDown(): void
    {
        foreach ($this->tableIds as $id) {
            DB::table('judging_assignments')->where('assignTable', $id)->delete();
            DB::table('judging_flights')->where('flightTable', $id)->delete();
            DB::table('judging_tables')->where('id', $id)->delete();
        }
        DB::table('judging_flights')->whereIn('flightEntryID', $this->entryIds !== [] ? $this->entryIds : [0])->delete();
        $practiceUserIds = DB::table('users')->where('user_name', 'like', '%@practice-user.com')->pluck('id');
        DB::table('brewing')->whereIn('brewBrewerID', $practiceUserIds->isNotEmpty() ? $practiceUserIds : [0])->delete();
        DB::table('brewer')->whereIn('uid', $practiceUserIds->isNotEmpty() ? $practiceUserIds : [0])->delete();
        DB::table('users')->whereIn('id', $this->dummyUserIds !== [] ? $this->dummyUserIds : [0])->delete();
        DB::table('users')->where('user_name', 'like', '%@practice-user.com')->delete();
        DB::table('judging_locations')->where('judgingLocType', 1)->where('judgingLocName', 'Practice Session')->delete();
        DB::table('styles')->whereIn('id', $this->styleIds !== [] ? $this->styleIds : [0])->delete();
        foreach ($this->entryIds as $id) {
            DB::table('brewing')->where('id', $id)->delete();
        }
        DB::table('judging_preferences')->where('id', 1)->update(['jPrefsTablePlanning' => $this->origPlanningFlag]);
        DB::table('brewer')->where('brewerEmail', self::JUDGE)->delete();
        DB::table('users')->whereIn('user_name', [self::ADMIN, self::JUDGE, self::ENTRANT])->delete();
        Session::flush();

        parent::tearDown();
    }

    private function login(string $email): void
    {
        $this->actingAs(User::query()->where('user_name', $email)->firstOrFail());
    }

    private function logout(): void
    {
        // Full logout: clears the session user without an HTTP round-trip.
        Session::flush();
        $this->app['auth']->forgetGuards();
    }

    private function adminId(): int
    {
        return (int) DB::table('users')->where('user_name', self::ADMIN)->value('id');
    }

    private function judgeUid(): int
    {
        return (int) DB::table('users')->where('user_name', self::JUDGE)->value('id');
    }

    /**
     * @return int styles.id
     */
    private function makeStyle(string $group, string $sub): int
    {
        $id = (int) DB::table('styles')->insertGetId([
            'brewStyle' => 'Ajax Fixture Style',
            'brewStyleGroup' => $group,
            'brewStyleNum' => $sub,
            'brewStyleActive' => 'Y',
            'brewStyleOwn' => 'default',
        ]);
        $this->styleIds[] = $id;

        return $id;
    }

    /**
     * @return int brewing.id
     */
    private function makeEntry(int $brewerUid, int $styleId, string $received): int
    {
        $style = DB::table('styles')->where('id', $styleId)->first(['brewStyleGroup', 'brewStyleNum']);
        self::assertNotNull($style);

        $id = (int) DB::table('brewing')->insertGetId([
            'brewName' => 'JudgingAjax Fixture',
            'brewCategorySort' => $style->brewStyleGroup,
            'brewCategory' => $style->brewStyleGroup,
            'brewSubCategory' => $style->brewStyleNum,
            'brewBrewerID' => $brewerUid,
            'brewConfirmed' => '1',
            'brewPaid' => 0,
            'brewReceived' => $received,
        ]);
        $this->entryIds[] = $id;

        return $id;
    }

    /**
     * @return int judging_tables.id
     */
    private function makeTable(string $styleCsv): int
    {
        $id = (int) DB::table('judging_tables')->insertGetId([
            'tableName' => 'Ajax Fixture Table',
            'tableStyles' => $styleCsv,
            'tableNumber' => 900,
        ]);
        $this->tableIds[] = $id;

        return $id;
    }

    // -----------------------------------------------------------------
    // custom_style
    // -----------------------------------------------------------------

    public function test_custom_style_reports_available_pair(): void
    {
        $this->login(self::ADMIN);

        $this->get('/ajax/custom-style?rid1=70&rid2=Q&rid3=default&rid4=default')
            ->assertOk()
            ->assertExactJson([
                'status' => '3',
                'message' => '<span class="text-success">Style and sub-style combination is available. <i class="fa fa-check-circle"></i></span>',
            ]);
    }

    public function test_custom_style_reports_taken_pair(): void
    {
        $styleId = $this->makeStyle('71', 'Q');
        $style = DB::table('styles')->where('id', $styleId)->first(['brewStyleGroup', 'brewStyleNum']);
        self::assertNotNull($style);
        $this->login(self::ADMIN);

        $this->get('/ajax/custom-style?rid1='.rawurlencode((string) $style->brewStyleGroup).'&rid2='.rawurlencode((string) $style->brewStyleNum).'&rid3=default&rid4=default')
            ->assertOk()
            ->assertExactJson([
                'status' => '2',
                'message' => '<span class="text-danger">Style and sub-style combination already in use. <i class="fa fa-exclamation-triangle"></i></span>',
            ]);
    }

    public function test_custom_style_rejects_reserved_categories_under_fifty(): void
    {
        $this->login(self::ADMIN);

        $this->get('/ajax/custom-style?rid1=10&rid2=A&rid3=default&rid4=default')
            ->assertOk()
            ->assertExactJson([
                'status' => '1',
                'message' => '<span class="text-primary">All custom style category numbers must be at least 50 &ndash; 1-49 are reserved for system use. <i class="fa fa-info-circle"></i></span>',
            ]);
    }

    public function test_custom_style_needs_both_fields(): void
    {
        $this->login(self::ADMIN);

        $this->get('/ajax/custom-style')
            ->assertOk()
            ->assertExactJson([
                'status' => '0',
                'message' => '<span class="text-warning">An identifier is needed in both fields. <i class="fa fa-exclamation-triangle"></i></span>',
            ]);
    }

    public function test_custom_style_flags_unchanged_pair_as_status_four_with_sentinel_message(): void
    {
        $this->login(self::ADMIN);

        // rid1==rid3 && rid2==rid4: the organizer isn't changing numbers.
        $this->get('/ajax/custom-style?rid1=72&rid2=Q&rid3=72&rid4=Q')
            ->assertOk()
            ->assertExactJson(['status' => '4', 'message' => 'Awaiting input.']);
    }

    public function test_custom_style_reports_status_nine_without_privileges(): void
    {
        $this->get('/ajax/custom-style?rid1=73&rid2=Q')
            ->assertOk()
            ->assertExactJson(['status' => '9', 'message' => 'Awaiting input.']);

        $this->login(self::ENTRANT);
        $this->get('/ajax/custom-style?rid1=74&rid2=Q')
            ->assertOk()
            ->assertExactJson(['status' => '9', 'message' => 'Awaiting input.']);
    }

    // -----------------------------------------------------------------
    // tables_mode
    // -----------------------------------------------------------------

    public function test_enable_planning_seeds_flight_one_and_sets_the_flag(): void
    {
        $s1 = $this->makeStyle('80', 'A');
        $s2 = $this->makeStyle('81', 'B');
        $e1 = $this->makeEntry($this->adminId(), $s1, '0'); // not received ⇒ planning flight
        $e2 = $this->makeEntry($this->adminId(), $s2, '1'); // already in flights, untouched
        $table = $this->makeTable($s1.','.$s2);
        DB::table('judging_flights')->insert([
            'flightTable' => $table, 'flightNumber' => 1, 'flightEntryID' => $e2, 'flightRound' => 1,
        ]);

        $this->login(self::ADMIN);
        $this->post('/admin/judging/tables-mode', ['section' => 'enable-planning'])
            ->assertOk()
            ->assertExactJson(['status' => '1', 'error_count' => '0', 'error_type' => '0']);

        // Not-received entry pulled into flight 1 of its table.
        $planned = DB::table('judging_flights')->where('flightEntryID', (string) $e1)->first();
        $this->assertNotNull($planned);
        $this->assertSame($table, (int) $planned->flightTable);
        $this->assertSame(1, (int) $planned->flightNumber);

        $this->assertSame(
            1,
            (int) DB::table('judging_flights')->where('flightEntryID', (string) $e2)->value('flightPlanning'),
        );
        $this->assertSame(1, (int) DB::table('judging_preferences')->where('id', 1)->value('jPrefsTablePlanning'));
    }

    public function test_enable_planning_drops_entryless_tables_and_flags_assignments(): void
    {
        $s1 = $this->makeStyle('82', 'A');
        $keptTable = $this->makeTable((string) $s1);
        $droppedTable = $this->makeTable('99999999'); // dangling style id ⇒ no entries
        $this->makeEntry($this->adminId(), $s1, '1');

        // Legacy only runs the per-table pass when any flight exists.
        DB::table('judging_flights')->insert([
            'flightTable' => $keptTable, 'flightNumber' => 1, 'flightEntryID' => '0', 'flightRound' => 1,
        ]);

        DB::table('judging_assignments')->insert([
            'bid' => $this->judgeUid(), 'assignment' => 'J', 'assignTable' => $keptTable, 'assignFlight' => 1, 'assignRound' => 1,
        ]);

        $this->login(self::ADMIN);
        $this->post('/admin/judging/tables-mode', ['section' => 'enable-planning'])
            ->assertOk()
            ->assertExactJson(['status' => '1', 'error_count' => '0', 'error_type' => '0']);

        // Dangling-style table cascades away; kept table pruned to its live style.
        $this->assertNull(DB::table('judging_tables')->where('id', $droppedTable)->first());
        $this->assertSame(
            (string) $s1,
            (string) DB::table('judging_tables')->where('id', $keptTable)->value('tableStyles'),
        );
        $this->assertSame(
            1,
            (int) DB::table('judging_assignments')->where('bid', $this->judgeUid())->where('assignTable', $keptTable)->value('assignPlanning'),
        );
    }

    public function test_enable_competition_prunes_unreceived_flights_and_conflicting_assignments(): void
    {
        $judgeUid = $this->judgeUid();

        $s1 = $this->makeStyle('83', 'A');
        $receivedEntry = $this->makeEntry($this->adminId(), $s1, '1');
        $unreceivedEntry = $this->makeEntry($this->adminId(), $s1, '0');
        $this->makeEntry($judgeUid, $s1, '1'); // judge's own entry at the table ⇒ conflict
        $table = $this->makeTable((string) $s1);
        DB::table('judging_flights')->insert([
            'flightTable' => $table, 'flightNumber' => 1, 'flightEntryID' => $receivedEntry, 'flightRound' => 1,
        ]);
        DB::table('judging_flights')->insert([
            'flightTable' => $table, 'flightNumber' => 1, 'flightEntryID' => $unreceivedEntry, 'flightRound' => 1,
        ]);

        $assignmentId = (int) DB::table('judging_assignments')->insertGetId([
            'bid' => $judgeUid,
            'assignment' => 'J',
            'assignTable' => $table,
            'assignFlight' => 1,
            'assignRound' => 1,
        ]);

        DB::table('judging_preferences')->where('id', 1)->update(['jPrefsTablePlanning' => 1]);

        $this->login(self::ADMIN);
        $this->post('/admin/judging/tables-mode', ['section' => 'enable-competition'])
            ->assertOk()
            ->assertExactJson(['status' => '1', 'error_count' => '0', 'error_type' => '0']);

        // Unreceived entry's flight gone; received entry's flight production-flagged.
        $this->assertNull(DB::table('judging_flights')->where('flightEntryID', (string) $unreceivedEntry)->first());
        $this->assertSame(
            0,
            (int) DB::table('judging_flights')->where('flightEntryID', (string) $receivedEntry)->value('flightPlanning'),
        );

        // Judge held their own received entry at the table's only style ⇒ unassigned.
        $this->assertNull(DB::table('judging_assignments')->where('id', $assignmentId)->first());
        $this->assertSame(1, (int) Session::get('judge_unassign_flag'));

        // Production mode flag cleared.
        $this->assertSame(0, (int) DB::table('judging_preferences')->where('id', 1)->value('jPrefsTablePlanning'));
    }

    public function test_competition_round_trip_preserves_table_configuration_and_assignments(): void
    {
        $s1 = $this->makeStyle('84', 'A');
        $s2 = $this->makeStyle('85', 'B');
        $e1 = $this->makeEntry($this->adminId(), $s1, '1');
        $this->makeEntry($this->adminId(), $s2, '1');

        $table = $this->makeTable($s1.','.$s2);
        DB::table('judging_flights')->insert([
            'flightTable' => $table, 'flightNumber' => 1, 'flightEntryID' => $e1, 'flightRound' => 1,
        ]);
        $assignmentId = (int) DB::table('judging_assignments')->insertGetId([
            'bid' => $this->judgeUid(),
            'assignment' => 'J',
            'assignTable' => $table,
            'assignFlight' => 1,
            'assignRound' => 1,
        ]);

        DB::table('judging_preferences')->where('id', 1)->update(['jPrefsTablePlanning' => 1]);

        $this->login(self::ADMIN);

        // planning → competition: configuration must survive the switch.
        foreach (['enable-planning', 'enable-competition'] as $section) {
            $this->post('/admin/judging/tables-mode', ['section' => $section])
                ->assertOk()
                ->assertExactJson(['status' => '1', 'error_count' => '0', 'error_type' => '0']);
        }

        $this->assertSame(0, (int) DB::table('judging_preferences')->where('id', 1)->value('jPrefsTablePlanning'));
        $this->assertSame($s1.','.$s2, (string) DB::table('judging_tables')->where('id', $table)->value('tableStyles'));
        $this->assertNotNull(DB::table('judging_assignments')->where('id', $assignmentId)->first());

        // competition → planning: and back again.
        $this->post('/admin/judging/tables-mode', ['section' => 'enable-planning'])
            ->assertOk()
            ->assertExactJson(['status' => '1', 'error_count' => '0', 'error_type' => '0']);

        $this->assertSame(1, (int) DB::table('judging_preferences')->where('id', 1)->value('jPrefsTablePlanning'));
        $this->assertSame($s1.','.$s2, (string) DB::table('judging_tables')->where('id', $table)->value('tableStyles'));
        $this->assertSame(
            1,
            (int) DB::table('judging_assignments')->where('id', $assignmentId)->value('assignPlanning'),
        );
    }

    public function test_enable_competition_without_flights_keeps_tables_and_assignments(): void
    {
        // The previously-destructive case: an organizer who defines tables
        // and assignments but never holds a flight. No usable flightEntryID
        // used to TRUNCATE all three tables; the switch must only flip the
        // preference flag.
        DB::table('judging_flights')->delete();

        $s1 = $this->makeStyle('86', 'A');
        $table = $this->makeTable((string) $s1);
        $assignmentId = (int) DB::table('judging_assignments')->insertGetId([
            'bid' => $this->judgeUid(),
            'assignment' => 'J',
            'assignTable' => $table,
            'assignFlight' => 1,
            'assignRound' => 1,
        ]);

        DB::table('judging_preferences')->where('id', 1)->update(['jPrefsTablePlanning' => 1]);

        $this->login(self::ADMIN);
        $this->post('/admin/judging/tables-mode', ['section' => 'enable-competition'])
            ->assertOk()
            ->assertExactJson(['status' => '1', 'error_count' => '0', 'error_type' => '0']);

        $this->assertSame(0, (int) DB::table('judging_preferences')->where('id', 1)->value('jPrefsTablePlanning'));
        $this->assertNotNull(DB::table('judging_tables')->where('id', $table)->first());
        $this->assertSame((string) $s1, (string) DB::table('judging_tables')->where('id', $table)->value('tableStyles'));
        $this->assertNotNull(DB::table('judging_assignments')->where('id', $assignmentId)->first());
    }

    public function test_tables_mode_rejects_privileged_but_unknown_sections_with_legacy_envelope(): void
    {
        // Logged-in entrant (level 2 passes legacy's <=2 gate): unknown
        // section falls through to the fail envelope.
        $this->login(self::ENTRANT);
        $this->post('/admin/judging/tables-mode', ['section' => 'nonsense'])
            ->assertOk()
            ->assertExactJson(['status' => '0', 'error_count' => '0', 'error_type' => '0']);
    }

    public function test_tables_mode_returns_empty_body_for_anonymous_hits_like_legacy(): void
    {
        $response = $this->post('/admin/judging/tables-mode', ['section' => 'enable-planning']);

        $response->assertOk();
        $this->assertSame('', $response->getContent());
    }

    // -----------------------------------------------------------------
    // practice_session
    // -----------------------------------------------------------------

    public function test_practice_session_builds_sandbox_and_echoes_legacy_fragments(): void
    {
        $judgeUid = $this->judgeUid();

        $response = $this->actingAs(User::query()->findOrFail($this->adminId()))
            ->post('/admin/judging/practice-session', ['selected_style_types' => ['1']]);

        $response->assertOk();
        $this->assertSame(
            'Practice Beer Complete<br>Scoresheet Practice Table Added<br>',
            $response->getContent(),
        );
        $this->assertSame('text/html; charset=UTF-8', $response->headers->get('Content-Type'));

        // Location: practice type, named after the legacy label.
        $location = DB::table('judging_locations')
            ->where('judgingLocType', 1)
            ->where('judgingLocName', 'Practice Session')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($location);
        $this->assertSame('Practice session', (string) $location->judgingLocation);
        $this->assertSame(1, (int) $location->judgingRounds);

        // Dummy obfuscated participant owns the practice entry.
        $dummy = DB::table('users')->where('user_name', 'like', '%@practice-user.com')->orderByDesc('id')->first();
        $this->assertNotNull($dummy);
        $this->dummyUserIds[] = (int) $dummy->id;
        $this->assertSame('2', (string) $dummy->userLevel);
        $this->assertSame(1, (int) $dummy->userAdminObfuscate);
        $this->assertSame(
            'Dummy participant for electronic scoresheet practice.',
            (string) DB::table('brewer')->where('uid', $dummy->id)->value('brewerJudgeNotes'),
        );

        $entry = DB::table('brewing')->where('brewBrewerID', $dummy->id)->first();
        $this->assertNotNull($entry);
        $this->entryIds[] = (int) $entry->id;
        $this->assertSame('Practice Entry 0', (string) $entry->brewName);
        $this->assertSame('1', (string) $entry->brewReceived);
        $this->assertMatchesRegularExpression('/^[1-9]{6}$/', (string) $entry->brewJudgingNumber);

        $style = DB::table('styles')
            ->where('brewStyleGroup', $entry->brewCategorySort)
            ->where('brewStyleNum', $entry->brewSubCategory)
            ->first();
        $this->assertNotNull($style);
        $this->styleIds[] = (int) $style->id;
        $this->assertSame('Practice Beer', (string) $style->brewStyle);
        $this->assertTrue((int) $style->brewStyleGroup >= 50);
        $this->assertSame('A', (string) $style->brewStyleNum);
        $this->assertSame('N', (string) $style->brewStyleActive);
        $this->assertSame('custom', (string) $style->brewStyleOwn);

        // Table 999 holds every practice style in flight 1.
        $table = DB::table('judging_tables')
            ->where('tableName', 'Scoresheet Practice')
            ->where('tableNumber', 999)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($table);
        $this->tableIds[] = (int) $table->id;
        $this->assertSame((string) $style->id, (string) $table->tableStyles);

        $flight = DB::table('judging_flights')
            ->where('flightTable', $table->id)
            ->where('flightEntryID', (string) $entry->id)
            ->first();
        $this->assertNotNull($flight);
        $this->assertSame(1, (int) $flight->flightNumber);
        $this->assertSame(1, (int) $flight->flightRound);

        // Every judge got assigned to the table and marked available ",Y-<loc>".
        $assignment = DB::table('judging_assignments')
            ->where('assignTable', $table->id)
            ->where('bid', $judgeUid)
            ->first();
        $this->assertNotNull($assignment);
        $this->assertSame('J', (string) $assignment->assignment);
        $this->assertSame(1, (int) $assignment->assignRound);
        $availability = (string) DB::table('brewer')->where('uid', $judgeUid)->value('brewerJudgeLocation');
        $this->assertSame(',Y-'.$location->id, substr($availability, -strlen(',Y-'.$location->id)));
    }

    public function test_practice_session_redirects_non_admins_like_legacy(): void
    {
        $this->login(self::ENTRANT);
        $this->post('/admin/judging/practice-session')
            ->assertRedirect(self::LEGACY_REDIRECT);

        $this->logout();
        $this->post('/admin/judging/practice-session')
            ->assertRedirect(self::LEGACY_REDIRECT);
    }
}
