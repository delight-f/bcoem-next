<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

/**
 * Judge/steward assignment screen (spec §6 P4.3): assignment round-trip
 * writing legacy-exact judging_assignments columns, preference surfacing
 * from the brewer-form-2 fields (likes/dislikes CSVs of style ids), and
 * entry-conflict handling like legacy (conflicting assignments deleted on
 * render; conflicted participants never assignable).
 */
final class JudgingAssignTest extends PublicSurfaceTestCase
{
    private const ADMIN_EMAIL = 'assign.admin@brewingcompetitions.com';

    private const ADMIN_ID = 9420;

    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    /** @var list<int> */
    private array $styleIds = [];

    private int $tableId = 0;

    /** @var list<int> */
    private array $judgeUids = [];

    /** @var list<int> */
    private array $entryIds = [];

    /** @var array<string, mixed> */
    private array $origJudgingPrefs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $row = (array) DB::table('judging_preferences')->where('id', 1)->first();
        $this->origJudgingPrefs = collect($row)->except(['id'])->all();
        DB::table('judging_preferences')->where('id', 1)->update([
            'jPrefsTablePlanning' => 0,
        ]);

        DB::table('users')->where('user_name', self::ADMIN_EMAIL)->delete();
        DB::table('users')->insert([
            'id' => self::ADMIN_ID,
            'user_name' => self::ADMIN_EMAIL,
            'password' => self::HASH,
            'userLevel' => '1',
            'userCreated' => '2024-01-01 00:00:01',
            'userAdminObfuscate' => 0,
        ]);
        $this->post('/login', [
            'loginUsername' => self::ADMIN_EMAIL,
            'loginPassword' => 'bcoem',
        ]);
    }

    protected function tearDown(): void
    {
        DB::table('judging_assignments')->where('bid', $this->judgeUids ?: [0])->delete();
        DB::table('brewing')->whereIn('id', $this->entryIds ?: [0])->delete();
        DB::table('brewer')->whereIn('uid', $this->judgeUids ?: [0])->delete();
        if ($this->tableId !== 0) {
            DB::table('judging_flights')->where('flightTable', $this->tableId)->delete();
            DB::table('judging_tables')->where('id', $this->tableId)->delete();
        }
        DB::table('styles')->whereIn('id', $this->styleIds ?: [0])->delete();

        DB::table('judging_preferences')->where('id', 1)->update($this->origJudgingPrefs);

        parent::tearDown();
    }

    private function seedTable(string $likesCsv = '', string $dislikesCsv = ''): void
    {
        $styleId = (int) DB::table('styles')->insertGetId([
            'brewStyle' => 'Assign Fixture Style',
            'brewStyleGroup' => '7',
            'brewStyleNum' => 'C',
            'brewStyleActive' => 'Y',
            'brewStyleOwn' => 'default',
        ]);
        $this->styleIds[] = $styleId;

        $this->tableId = (int) DB::table('judging_tables')->insertGetId([
            'tableName' => 'Amber Hybrid',
            'tableStyles' => (string) $styleId,
            'tableNumber' => 950,
            'tableLocation' => 1,
        ]);

        DB::table('judging_flights')->insert([
            'flightTable' => $this->tableId,
            'flightNumber' => 1,
            'flightEntryID' => null,
            'flightRound' => 1,
        ]);

        foreach ([['Preferred', $likesCsv], ['Plain', ''], ['Disliker', '']] as $i => [$last, $csv]) {
            $uid = 9530 + $i;
            DB::table('brewer')->insert([
                'uid' => $uid,
                'brewerFirstName' => 'Judge',
                'brewerLastName' => $last,
                'brewerEmail' => strtolower($last).'.assign@example.com',
                'brewerJudge' => 'Y',
                'brewerJudgeLikes' => $last === 'Preferred' ? (string) $styleId : ($likesCsv === '' ? null : $likesCsv),
                'brewerJudgeDislikes' => $last === 'Disliker' ? (string) $styleId : ($dislikesCsv === '' ? null : $dislikesCsv),
            ]);
            $this->judgeUids[] = $uid;
        }
    }

    private function seedConflictedEntry(int $uid): int
    {
        $s = (array) DB::table('styles')->where('id', $this->styleIds[0])->first();

        $entryId = (int) DB::table('brewing')->insertGetId([
            'brewName' => 'Conflict Entry',
            'brewStyle' => $s['brewStyle'],
            'brewCategory' => $s['brewStyleGroup'],
            'brewCategorySort' => $s['brewStyleGroup'],
            'brewSubCategory' => $s['brewStyleNum'],
            'brewJudgingNumber' => '777777',
            'brewBrewerID' => $uid,
            'brewPaid' => 0,
            'brewReceived' => '1',
            'brewConfirmed' => '1',
        ]);
        $this->entryIds[] = $entryId;

        return $entryId;
    }

    public function test_assignment_round_trip_writes_exact_row_shape(): void
    {
        $this->seedTable();
        [$preferred, $plain] = $this->judgeUids;

        // Preference surfacing: likes overlap → green, plain → available.
        $response = $this->get('/admin/judging/flights/'.$this->tableId.'/assign/judges');
        $response->assertOk();
        self::assertStringContainsString('Available and Preferred Style(s)', (string) $response->getContent());
        self::assertStringContainsString('Available but Non-Preferred Style(s)', (string) $response->getContent());

        $this->post('/admin/judging/flights/'.$this->tableId.'/assign/judges', [
            'assign' => [
                $preferred => ['1' => '1'],
                $plain => ['1' => '0'],
            ],
        ])->assertRedirect('/admin/judging/flights/'.$this->tableId.'/assign/judges');

        $found = DB::table('judging_assignments')
            ->where('bid', $preferred)
            ->where('assignTable', $this->tableId)
            ->first();
        self::assertNotNull($found);
        $row = (array) $found;

        self::assertSame('J', $row['assignment']);
        self::assertSame(1, (int) $row['assignFlight']);
        self::assertSame(1, (int) $row['assignRound']);
        self::assertSame(1, (int) $row['assignLocation']); // the table's session location
        self::assertSame(0, (int) $row['assignPlanning']); // jPrefsTablePlanning
        self::assertNull($row['assignRoles']);

        // Choosing "Do Not Assign" removes any existing row.
        $this->post('/admin/judging/flights/'.$this->tableId.'/assign/judges', [
            'assign' => [$preferred => ['1' => '0']],
        ])->assertRedirect('/admin/judging/flights/'.$this->tableId.'/assign/judges');

        self::assertTrue(
            DB::table('judging_assignments')->where('bid', $preferred)->doesntExist(),
        );
    }

    public function test_steward_role_is_recorded_as_s(): void
    {
        $this->seedTable();
        DB::table('brewer')->whereIn('uid', $this->judgeUids)->update(['brewerJudge' => 'N', 'brewerSteward' => 'Y']);

        $this->get('/admin/judging/flights/'.$this->tableId.'/assign/stewards')->assertOk();

        $this->post('/admin/judging/flights/'.$this->tableId.'/assign/stewards', [
            'assign' => [$this->judgeUids[0] => ['1' => '1']],
        ])->assertRedirect('/admin/judging/flights/'.$this->tableId.'/assign/stewards');

        self::assertSame(
            'S',
            (string) DB::table('judging_assignments')->where('bid', $this->judgeUids[0])->value('assignment'),
        );
    }

    public function test_entry_conflict_disables_and_cleans_up_existing_assignment(): void
    {
        $this->seedTable();
        $conflicted = $this->judgeUids[0];

        // Pre-existing assignment that must not survive the conflict.
        DB::table('judging_assignments')->insert([
            'bid' => $conflicted,
            'assignment' => 'J',
            'assignTable' => $this->tableId,
            'assignFlight' => 1,
            'assignRound' => 1,
            'assignLocation' => 1,
        ]);
        $this->seedConflictedEntry($conflicted);

        $response = $this->get('/admin/judging/flights/'.$this->tableId.'/assign/judges');
        $response->assertOk();
        self::assertStringContainsString('Has an entry at this table', (string) $response->getContent());

        // Legacy render-time cleanup removed the conflicting assignment.
        self::assertTrue(DB::table('judging_assignments')->where('bid', $conflicted)->doesntExist());

        // And the POST refuses to re-create it.
        $this->post('/admin/judging/flights/'.$this->tableId.'/assign/judges', [
            'assign' => [$conflicted => ['1' => '1']],
        ])->assertRedirect('/admin/judging/flights/'.$this->tableId.'/assign/judges');

        self::assertTrue(DB::table('judging_assignments')->where('bid', $conflicted)->doesntExist());
    }

    public function test_assigned_elsewhere_same_round_is_surfaced_busy(): void
    {
        $this->seedTable();
        $busy = $this->judgeUids[2];

        DB::table('judging_assignments')->insert([
            'bid' => $busy,
            'assignment' => 'J',
            'assignTable' => $this->tableId + 100, // another table, same location/round
            'assignFlight' => 1,
            'assignRound' => 1,
            'assignLocation' => 1,
        ]);

        $response = $this->get('/admin/judging/flights/'.$this->tableId.'/assign/judges');
        self::assertStringContainsString(
            'Assigned to another table in this round',
            (string) $response->getContent(),
        );

        DB::table('judging_assignments')->where('bid', $busy)->delete();
    }

    // -----------------------------------------------------------------
    // Issue #1752 — ineligible-but-assigned visibility and scoped deletes
    // -----------------------------------------------------------------

    public function test_issue_1752_ineligible_but_assigned_is_listed_and_unassignable(): void
    {
        $this->seedTable();
        $uid = $this->judgeUids[0];

        // No longer flagged as a judge, but still holds a row for this table.
        DB::table('brewer')->where('uid', $uid)->update(['brewerJudge' => 'N']);
        DB::table('judging_assignments')->insert([
            'bid' => $uid, 'assignment' => 'J', 'assignTable' => $this->tableId,
            'assignFlight' => 1, 'assignRound' => 1, 'assignLocation' => 1,
        ]);

        $response = $this->get('/admin/judging/flights/'.$this->tableId.'/assign/judges');
        $response->assertOk();
        $body = (string) $response->getContent();
        self::assertStringContainsString('Judge Preferred', $body);
        self::assertStringContainsString('No longer available', $body);

        // The enabled control still unassigns them.
        $this->post('/admin/judging/flights/'.$this->tableId.'/assign/judges', [
            'assign' => [$uid => ['1' => '0']],
        ])->assertRedirect('/admin/judging/flights/'.$this->tableId.'/assign/judges');

        self::assertTrue(DB::table('judging_assignments')->where('bid', $uid)->doesntExist());
    }

    public function test_issue_1752_judges_save_leaves_steward_row_intact(): void
    {
        $this->seedTable();
        $uid = $this->judgeUids[0];

        DB::table('judging_assignments')->insert([
            'bid' => $uid, 'assignment' => 'J', 'assignTable' => $this->tableId,
            'assignFlight' => 1, 'assignRound' => 1, 'assignLocation' => 1,
        ]);
        DB::table('judging_assignments')->insert([
            'bid' => $uid, 'assignment' => 'S', 'assignTable' => $this->tableId,
            'assignFlight' => 1, 'assignRound' => 1, 'assignLocation' => 1,
        ]);

        $this->post('/admin/judging/flights/'.$this->tableId.'/assign/judges', [
            'assign' => [$uid => ['1' => '0']],
        ])->assertRedirect('/admin/judging/flights/'.$this->tableId.'/assign/judges');

        self::assertSame(0, DB::table('judging_assignments')->where('bid', $uid)->where('assignment', 'J')->count());
        self::assertSame(1, DB::table('judging_assignments')->where('bid', $uid)->where('assignment', 'S')->count());
    }

    public function test_issue_1752_conflict_render_delete_is_role_scoped(): void
    {
        $this->seedTable();
        $conflicted = $this->judgeUids[0];

        DB::table('judging_assignments')->insert([
            'bid' => $conflicted, 'assignment' => 'J', 'assignTable' => $this->tableId,
            'assignFlight' => 1, 'assignRound' => 1, 'assignLocation' => 1,
        ]);
        DB::table('judging_assignments')->insert([
            'bid' => $conflicted, 'assignment' => 'S', 'assignTable' => $this->tableId,
            'assignFlight' => 1, 'assignRound' => 1, 'assignLocation' => 1,
        ]);
        $this->seedConflictedEntry($conflicted);

        // The judges screen deletes only the conflicted J row, not the S row.
        $this->get('/admin/judging/flights/'.$this->tableId.'/assign/judges')->assertOk();

        self::assertTrue(DB::table('judging_assignments')->where('bid', $conflicted)->where('assignment', 'J')->doesntExist());
        self::assertTrue(DB::table('judging_assignments')->where('bid', $conflicted)->where('assignment', 'S')->exists());
    }
}
