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
        DB::table('staff')->whereBetween('uid', [self::UID_MIN, self::UID_MAX])->delete();
        DB::table('brewer')->whereBetween('uid', [self::UID_MIN, self::UID_MAX])->delete();
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
}
