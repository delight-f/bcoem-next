<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Pool-assignment screen (spec §6 P4.3 sibling): roster rendering per
 * filter (judges|stewards|staff|bos) and the staff-flag toggle endpoint
 * (legacy ajax/save.ajax.php action=judging_staff). Mirrors the JudgingAssignTest
 * / AjaxEndpointsTest conventions: admin seeded directly into users, brewers
 * seeded into brewer, cleanup in tearDown confined to the fixture uid range.
 */
final class PoolAssignTest extends PublicSurfaceTestCase
{
    private const ADMIN_EMAIL = 'pool.admin@brewingcompetitions.com';

    private const ADMIN_ID = 9430;

    private const PARTICIPANT_EMAIL = 'pool.participant@brewingcompetitions.com';

    private const PARTICIPANT_ID = 9431;

    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    private const UID_MIN = 9530;

    private const UID_MAX = 9549;

    /** @var list<int> */
    private array $tableIds = [];

    private int $tableId = 0;

    /** @var list<int> */
    private array $styleIds = [];

    /** @var list<int> */
    private array $entryIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('users')->whereIn('id', [self::ADMIN_ID, self::PARTICIPANT_ID])->delete();
        DB::table('users')->insert([
            'id' => self::ADMIN_ID,
            'user_name' => self::ADMIN_EMAIL,
            'password' => self::HASH,
            'userLevel' => '1',
            'userCreated' => '2024-01-01 00:00:01',
            'userAdminObfuscate' => 0,
        ]);
        DB::table('users')->insert([
            'id' => self::PARTICIPANT_ID,
            'user_name' => self::PARTICIPANT_EMAIL,
            'password' => self::HASH,
            'userLevel' => '2',
            'userCreated' => '2024-01-01 00:00:01',
            'userAdminObfuscate' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        DB::table('judging_assignments')->whereBetween('bid', [self::UID_MIN, self::UID_MAX])->delete();
        DB::table('brewing')->whereIn('id', $this->entryIds ?: [0])->delete();
        DB::table('staff')->whereBetween('uid', [self::UID_MIN, self::UID_MAX])->delete();
        DB::table('brewer')->whereBetween('uid', [self::UID_MIN, self::UID_MAX])->delete();
        if ($this->tableId !== 0) {
            DB::table('judging_flights')->where('flightTable', $this->tableId)->delete();
            DB::table('judging_tables')->where('id', $this->tableId)->delete();
        }
        DB::table('users')->whereIn('id', [self::ADMIN_ID, self::PARTICIPANT_ID])->delete();

        parent::tearDown();
    }

    private function admin(): User
    {
        return User::query()->findOrFail(self::ADMIN_ID);
    }

    private function participant(): User
    {
        return User::query()->findOrFail(self::PARTICIPANT_ID);
    }

    private function seedBrewer(int $uid, string $last, string $first, string $email, string $judge = 'N', string $steward = 'N', string $staff = 'N'): void
    {
        DB::table('brewer')->insert([
            'uid' => $uid,
            'brewerFirstName' => $first,
            'brewerLastName' => $last,
            'brewerEmail' => $email,
            'brewerJudge' => $judge,
            'brewerSteward' => $steward,
            'brewerStaff' => $staff,
        ]);
    }

    // -----------------------------------------------------------------
    // Roster rendering per filter
    // -----------------------------------------------------------------

    public function test_judges_filter_renders_only_judge_pool_rows(): void
    {
        $this->seedBrewer(9530, 'Alpha', 'Amy', 'alpha.pool@example.com', 'Y');
        $this->seedBrewer(9531, 'Bravo', 'Bob', 'bravo.pool@example.com', 'Y');
        $this->seedBrewer(9532, 'Charlie', 'C.', 'charlie.pool@example.com', 'N');

        $response = $this->actingAs($this->admin())
            ->get('/admin/judging/pool-assign?filter=judges');

        $response->assertOk();
        $body = (string) $response->getContent();
        self::assertStringContainsString('Alpha, Amy', $body);
        self::assertStringContainsString('Bravo, Bob', $body);
        self::assertStringNotContainsString('Charlie, C.', $body);
        self::assertStringContainsString('are assigned to the judge pool automatically upon registration', $body);
    }

    public function test_staff_filter_renders_all_brewers(): void
    {
        $this->seedBrewer(9530, 'Alpha', 'Amy', 'alpha.pool@example.com', 'N', 'N', 'N');
        $this->seedBrewer(9531, 'Bravo', 'Bob', 'bravo.pool@example.com', 'N', 'N', 'Y');

        $response = $this->actingAs($this->admin())
            ->get('/admin/judging/pool-assign?filter=staff');

        $response->assertOk();
        $body = (string) $response->getContent();
        self::assertStringContainsString('Alpha, Amy', $body);
        self::assertStringContainsString('Bravo, Bob', $body);
    }

    // -----------------------------------------------------------------
    // Page purpose + judge→table assignment entry point
    // -----------------------------------------------------------------

    public function test_page_states_its_purpose_and_the_checkbox_handler_uses_the_real_spans(): void
    {
        $this->seedBrewer(9530, 'Alpha', 'Amy', 'alpha.pool@example.com', 'Y');

        $response = $this->actingAs($this->admin())
            ->get('/admin/judging/pool-assign?filter=judges');

        $response->assertOk();
        $body = (string) $response->getContent();
        self::assertStringContainsString('This page assigns judges, stewards, BOS judges, and staff to a pool of available', $body);

        // The row renders ...-status / ...-status-msg. The handler used to look
        // up the non-existent ...-ok / ...-err, which threw on success before
        // the box was re-enabled and froze the checkbox.
        self::assertStringContainsString('`${col}-status`', $body);
        self::assertStringNotContainsString('`${col}-ok`', $body);
        self::assertStringNotContainsString('`${col}-err`', $body);
    }

    public function test_pool_page_offers_per_table_assignment_when_tables_exist(): void
    {
        $this->seedBrewer(9530, 'Alpha', 'Amy', 'alpha.pool@example.com', 'Y');
        $this->tableIds[] = (int) DB::table('judging_tables')->insertGetId([
            'tableName' => 'Pool Fixture Table',
            'tableNumber' => 977,
            'tableStyles' => '',
        ]);

        $response = $this->actingAs($this->admin())
            ->get('/admin/judging/pool-assign?filter=judges');

        $response->assertOk()
            ->assertSee('Assign Judges to a Table')
            ->assertSee('/admin/judging/flights/'.$this->tableIds[0].'/assign/judges', false)
            ->assertDontSee('No tables have been created');
    }

    public function test_pool_page_blocks_table_assignment_with_an_alert_when_no_tables_exist(): void
    {
        $this->seedBrewer(9530, 'Alpha', 'Amy', 'alpha.pool@example.com', 'Y');

        // Snapshot + restore so the shared fixture tables survive the test.
        $saved = DB::table('judging_tables')->get()->map(fn ($r): array => (array) $r)->all();
        DB::table('judging_tables')->delete();

        try {
            $response = $this->actingAs($this->admin())
                ->get('/admin/judging/pool-assign?filter=judges');

            $response->assertOk()
                ->assertSee('No tables have been created. Create a table and define its flights before assigning')
                ->assertDontSee('Assign Judges to a Table');
        } finally {
            foreach ($saved as $row) {
                DB::table('judging_tables')->insert($row);
            }
        }
    }

    // -----------------------------------------------------------------
    // staff_judge toggle
    // -----------------------------------------------------------------

    public function test_staff_judge_toggle_inserts_staff_row_when_missing(): void
    {
        $this->seedBrewer(9530, 'Alpha', 'Amy', 'alpha.pool@example.com', 'Y');

        $response = $this->actingAs($this->admin())
            ->post('/admin/judging/pool-assign/staff', [
                'action' => 'judging_staff',
                'go' => 'staff_judge',
                'id' => 9530,
                'staff_judge' => '1',
            ]);

        $response->assertOk();
        $response->assertJsonPath('status', '1');

        $row = (array) DB::table('staff')->where('uid', 9530)->first();
        self::assertNotEmpty($row);
        self::assertSame(1, (int) $row['staff_judge']);
        self::assertSame(0, (int) $row['staff_steward']);
        self::assertSame(0, (int) $row['staff_staff']);
    }

    public function test_staff_judge_toggle_off_deletes_judge_assignments(): void
    {
        $this->seedBrewer(9530, 'Alpha', 'Amy', 'alpha.pool@example.com', 'Y');
        DB::table('staff')->insert([
            'uid' => 9530,
            'staff_judge' => 1,
            'staff_judge_bos' => 0,
            'staff_steward' => 0,
            'staff_organizer' => 0,
            'staff_staff' => 0,
        ]);
        DB::table('judging_assignments')->insert([
            'bid' => 9530,
            'assignment' => 'J',
            'assignTable' => 50,
            'assignFlight' => 1,
            'assignRound' => 1,
            'assignLocation' => 1,
        ]);
        DB::table('judging_assignments')->insert([
            'bid' => 9530,
            'assignment' => 'S',
            'assignTable' => 50,
            'assignFlight' => 1,
            'assignRound' => 1,
            'assignLocation' => 1,
        ]);

        $response = $this->actingAs($this->admin())
            ->post('/admin/judging/pool-assign/staff', [
                'action' => 'judging_staff',
                'go' => 'staff_judge',
                'id' => 9530,
                'staff_judge' => '0',
            ]);

        $response->assertOk();
        $response->assertJsonPath('status', '1');

        self::assertSame(0, (int) DB::table('staff')->where('uid', 9530)->value('staff_judge'));
        self::assertTrue(
            DB::table('judging_assignments')->where('bid', 9530)->where('assignment', 'J')->doesntExist(),
        );
        self::assertTrue(
            DB::table('judging_assignments')->where('bid', 9530)->where('assignment', 'S')->exists(),
        );
    }

    // -----------------------------------------------------------------
    // staff_steward toggle
    // -----------------------------------------------------------------

    public function test_staff_steward_toggle_off_deletes_steward_assignments(): void
    {
        $this->seedBrewer(9530, 'Alpha', 'Amy', 'alpha.pool@example.com', 'N', 'Y');
        DB::table('staff')->insert([
            'uid' => 9530,
            'staff_judge' => 0,
            'staff_judge_bos' => 0,
            'staff_steward' => 1,
            'staff_organizer' => 0,
            'staff_staff' => 0,
        ]);
        DB::table('judging_assignments')->insert([
            'bid' => 9530,
            'assignment' => 'J',
            'assignTable' => 50,
            'assignFlight' => 1,
            'assignRound' => 1,
            'assignLocation' => 1,
        ]);
        DB::table('judging_assignments')->insert([
            'bid' => 9530,
            'assignment' => 'S',
            'assignTable' => 50,
            'assignFlight' => 1,
            'assignRound' => 1,
            'assignLocation' => 1,
        ]);

        $response = $this->actingAs($this->admin())
            ->post('/admin/judging/pool-assign/staff', [
                'action' => 'judging_staff',
                'go' => 'staff_steward',
                'id' => 9530,
                'staff_steward' => '0',
            ]);

        $response->assertOk();
        $response->assertJsonPath('status', '1');

        self::assertSame(0, (int) DB::table('staff')->where('uid', 9530)->value('staff_steward'));
        self::assertTrue(
            DB::table('judging_assignments')->where('bid', 9530)->where('assignment', 'S')->doesntExist(),
        );
        self::assertTrue(
            DB::table('judging_assignments')->where('bid', 9530)->where('assignment', 'J')->exists(),
        );
    }

    public function test_staff_steward_toggle_turns_on_independently(): void
    {
        $this->seedBrewer(9530, 'Alpha', 'Amy', 'alpha.pool@example.com', 'N', 'Y');
        DB::table('staff')->insert([
            'uid' => 9530,
            'staff_judge' => 0,
            'staff_judge_bos' => 0,
            'staff_steward' => 0,
            'staff_organizer' => 0,
            'staff_staff' => 0,
        ]);

        $response = $this->actingAs($this->admin())
            ->post('/admin/judging/pool-assign/staff', [
                'action' => 'judging_staff',
                'go' => 'staff_steward',
                'id' => 9530,
                'staff_steward' => '1',
            ]);

        $response->assertOk();
        $response->assertJsonPath('status', '1');

        $row = (array) DB::table('staff')->where('uid', 9530)->first();
        self::assertSame(1, (int) $row['staff_steward']);
        self::assertSame(0, (int) $row['staff_judge']);
    }

    // -----------------------------------------------------------------
    // organizer toggle
    // -----------------------------------------------------------------

    public function test_organizer_moves_between_brewers(): void
    {
        $this->seedBrewer(9530, 'Alpha', 'Amy', 'alpha.pool@example.com');
        $this->seedBrewer(9531, 'Bravo', 'Bob', 'bravo.pool@example.com');
        DB::table('staff')->insert([
            'uid' => 9530,
            'staff_judge' => 0,
            'staff_judge_bos' => 0,
            'staff_steward' => 0,
            'staff_organizer' => 1,
            'staff_staff' => 1,
        ]);
        DB::table('staff')->insert([
            'uid' => 9531,
            'staff_judge' => 0,
            'staff_judge_bos' => 0,
            'staff_steward' => 0,
            'staff_organizer' => 0,
            'staff_staff' => 1,
        ]);

        $response = $this->actingAs($this->admin())
            ->post('/admin/judging/pool-assign/staff', [
                'action' => 'judging_staff',
                'go' => 'staff_organizer',
                'staff_organizer' => 9531,
            ]);

        $response->assertOk();
        $response->assertJsonPath('status', '1');

        self::assertSame(0, (int) DB::table('staff')->where('uid', 9530)->value('staff_organizer'));
        $bravo = (array) DB::table('staff')->where('uid', 9531)->first();
        self::assertSame(1, (int) $bravo['staff_organizer']);
        self::assertSame(0, (int) $bravo['staff_staff']);
        self::assertSame(0, (int) $bravo['staff_judge']);
        self::assertSame(0, (int) $bravo['staff_judge_bos']);
        self::assertSame(0, (int) $bravo['staff_steward']);
    }

    // -----------------------------------------------------------------
    // Auth / input gates
    // -----------------------------------------------------------------

    public function test_toggle_requires_admin(): void
    {
        $this->seedBrewer(9530, 'Alpha', 'Amy', 'alpha.pool@example.com', 'Y');

        // Non-admin (level 2) → status '0', no staff row created.
        $response = $this->actingAs($this->participant())
            ->post('/admin/judging/pool-assign/staff', [
                'action' => 'judging_staff',
                'go' => 'staff_judge',
                'id' => 9530,
                'staff_judge' => '1',
            ]);

        $response->assertOk();
        $response->assertJsonPath('status', '0');
        self::assertTrue(DB::table('staff')->where('uid', 9530)->doesntExist());
    }

    public function test_toggle_anonymous_reports_status_9(): void
    {
        $this->seedBrewer(9530, 'Alpha', 'Amy', 'alpha.pool@example.com', 'Y');

        // Anonymous → status '9' (legacy session-expired envelope).
        $response = $this->post('/admin/judging/pool-assign/staff', [
            'action' => 'judging_staff',
            'go' => 'staff_judge',
            'id' => 9530,
            'staff_judge' => '1',
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', '9');
        self::assertTrue(DB::table('staff')->where('uid', 9530)->doesntExist());
    }

    public function test_unknown_go_is_rejected(): void
    {
        $this->seedBrewer(9530, 'Alpha', 'Amy', 'alpha.pool@example.com', 'Y');

        $response = $this->actingAs($this->admin())
            ->post('/admin/judging/pool-assign/staff', [
                'action' => 'judging_staff',
                'go' => 'bogus',
                'id' => 9530,
            ]);

        $response->assertOk();
        $response->assertJsonPath('status', '0');
        $response->assertJsonPath('error_type', '3');
        self::assertTrue(DB::table('staff')->where('uid', 9530)->doesntExist());
    }

    public function test_issue_1752_absent_flag_column_is_not_a_state_change(): void
    {
        $this->seedBrewer(9530, 'Alpha', 'Amy', 'alpha.pool@example.com', 'Y');
        DB::table('staff')->insert([
            'uid' => 9530,
            'staff_judge' => 1,
            'staff_judge_bos' => 0,
            'staff_steward' => 0,
            'staff_organizer' => 0,
            'staff_staff' => 0,
        ]);
        DB::table('judging_assignments')->insert([
            'bid' => 9530,
            'assignment' => 'J',
            'assignTable' => $this->tableId,
            'assignFlight' => 1,
            'assignRound' => 1,
            'assignLocation' => 1,
        ]);

        // The staff_judge column is omitted entirely from the POST.
        $response = $this->actingAs($this->admin())
            ->post('/admin/judging/pool-assign/staff', [
                'action' => 'judging_staff',
                'go' => 'staff_judge',
                'id' => 9530,
            ]);

        $response->assertOk();
        $response->assertJsonPath('error_type', '3');
        self::assertSame(1, (int) DB::table('staff')->where('uid', 9530)->value('staff_judge'));
        self::assertSame(1, DB::table('judging_assignments')->where('bid', 9530)->where('assignment', 'J')->count());
    }

    // -----------------------------------------------------------------
    // Allocation split + inline table assignment (issue #56)
    // -----------------------------------------------------------------

    private function seedTable(): void
    {
        $styleId = (int) DB::table('styles')->insertGetId([
            'brewStyle' => 'Pool Fixture Style',
            'brewStyleGroup' => '8',
            'brewStyleNum' => 'B',
            'brewStyleActive' => 'Y',
            'brewStyleOwn' => 'default',
        ]);
        $this->styleIds[] = $styleId;

        $this->tableId = (int) DB::table('judging_tables')->insertGetId([
            'tableName' => 'Pool Table',
            'tableStyles' => (string) $styleId,
            'tableNumber' => 960,
            'tableLocation' => 1,
        ]);

        DB::table('judging_flights')->insert([
            'flightTable' => $this->tableId,
            'flightNumber' => 1,
            'flightEntryID' => null,
            'flightRound' => 1,
        ]);
    }

    private function seedPoolAssignment(int $uid, string $role = 'J'): void
    {
        DB::table('judging_assignments')->insert([
            'bid' => $uid,
            'assignment' => $role,
            'assignTable' => $this->tableId,
            'assignFlight' => 1,
            'assignRound' => 1,
            'assignLocation' => 1,
        ]);
    }

    private function seedConflictedEntry(int $uid): void
    {
        $s = (array) DB::table('styles')->where('id', $this->styleIds[0])->first();

        $entryId = (int) DB::table('brewing')->insertGetId([
            'brewName' => 'Pool Conflict Entry',
            'brewStyle' => $s['brewStyle'],
            'brewCategory' => $s['brewStyleGroup'],
            'brewCategorySort' => $s['brewStyleGroup'],
            'brewSubCategory' => $s['brewStyleNum'],
            'brewJudgingNumber' => '888888',
            'brewBrewerID' => $uid,
            'brewPaid' => 0,
            'brewReceived' => '1',
            'brewConfirmed' => '1',
        ]);
        $this->entryIds[] = $entryId;
    }

    public function test_judges_filter_splits_allocated_and_unallocated(): void
    {
        $this->seedBrewer(9530, 'Alpha', 'Amy', 'alpha.pool@example.com', 'Y');
        $this->seedBrewer(9531, 'Bravo', 'Bob', 'bravo.pool@example.com', 'Y');
        $this->seedTable();
        $this->seedPoolAssignment(9531);

        $response = $this->actingAs($this->admin())
            ->get('/admin/judging/pool-assign?filter=judges');

        $response->assertOk();
        $body = (string) $response->getContent();

        self::assertStringContainsString('Unallocated to a Table (', $body);
        self::assertStringContainsString('Allocated to a Table (', $body);
        self::assertStringContainsString('Assigned To', $body);

        // The seeded assignment must land in the allocated section, and the
        // unallocated judge in the section with the inline picker. The shared
        // fixture may carry other judges, so assert per-section, not counts.
        $splitAt = strpos($body, 'Allocated to a Table');
        $unallocated = substr($body, strpos($body, 'Unallocated to a Table'), $splitAt - strpos($body, 'Unallocated to a Table'));
        $allocated = substr($body, $splitAt);

        self::assertStringContainsString('Alpha, Amy', $unallocated);
        self::assertStringContainsString('pool-assign-select', $unallocated);
        self::assertStringNotContainsString('Bravo, Bob', $unallocated);

        self::assertStringContainsString('Bravo, Bob', $allocated);
        self::assertStringContainsString('Table 960', $allocated);
    }

    public function test_inline_assign_writes_exact_row_shape(): void
    {
        $this->seedBrewer(9530, 'Alpha', 'Amy', 'alpha.pool@example.com', 'Y');
        $this->seedTable();

        $response = $this->actingAs($this->admin())
            ->post('/admin/judging/pool-assign/table', [
                'action' => 'assign',
                'id' => 9530,
                'role' => 'judges',
                'table' => $this->tableId,
                'flight' => 1,
            ]);

        $response->assertOk();
        $response->assertJsonPath('status', '1');

        $row = (array) DB::table('judging_assignments')->where('bid', 9530)->first();
        self::assertSame('J', $row['assignment']);
        self::assertSame($this->tableId, (int) $row['assignTable']);
        self::assertSame(1, (int) $row['assignFlight']);
        self::assertSame(1, (int) $row['assignRound']);
        self::assertSame(1, (int) $row['assignLocation']);
        self::assertSame(0, (int) $row['assignPlanning']);
        self::assertNull($row['assignRoles']);
    }

    public function test_inline_assign_rejects_conflicted_participant(): void
    {
        $this->seedBrewer(9530, 'Alpha', 'Amy', 'alpha.pool@example.com', 'Y');
        $this->seedTable();
        $this->seedConflictedEntry(9530);

        $response = $this->actingAs($this->admin())
            ->post('/admin/judging/pool-assign/table', [
                'action' => 'assign',
                'id' => 9530,
                'role' => 'judges',
                'table' => $this->tableId,
                'flight' => 1,
            ]);

        $response->assertOk();
        $response->assertJsonPath('status', '0');
        $response->assertJsonPath('error_type', '4');
        self::assertTrue(DB::table('judging_assignments')->where('bid', 9530)->doesntExist());
    }

    public function test_inline_remove_clears_the_role_rows(): void
    {
        $this->seedBrewer(9530, 'Alpha', 'Amy', 'alpha.pool@example.com', 'Y');
        $this->seedTable();
        $this->seedPoolAssignment(9530);

        $response = $this->actingAs($this->admin())
            ->post('/admin/judging/pool-assign/table', [
                'action' => 'remove',
                'id' => 9530,
                'role' => 'judges',
                'table' => $this->tableId,
            ]);

        $response->assertOk();
        $response->assertJsonPath('status', '1');
        self::assertTrue(DB::table('judging_assignments')->where('bid', 9530)->doesntExist());
    }

    public function test_inline_assign_requires_admin(): void
    {
        $this->seedBrewer(9530, 'Alpha', 'Amy', 'alpha.pool@example.com', 'Y');
        $this->seedTable();

        $response = $this->actingAs($this->participant())
            ->post('/admin/judging/pool-assign/table', [
                'action' => 'assign',
                'id' => 9530,
                'role' => 'judges',
                'table' => $this->tableId,
                'flight' => 1,
            ]);

        $response->assertOk();
        $response->assertJsonPath('status', '0');
        self::assertTrue(DB::table('judging_assignments')->where('bid', 9530)->doesntExist());
    }

    public function test_inline_assign_anonymous_reports_status_9(): void
    {
        $this->seedBrewer(9530, 'Alpha', 'Amy', 'alpha.pool@example.com', 'Y');
        $this->seedTable();

        $response = $this->post('/admin/judging/pool-assign/table', [
            'action' => 'assign',
            'id' => 9530,
            'role' => 'judges',
            'table' => $this->tableId,
            'flight' => 1,
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', '9');
    }
    }
}
