<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\BrewerForm2Controller;
use App\Models\User;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Brewer form 2 (ticket 07): judge/steward/staff preferences, judge-window
 * gating with the already-volunteered opt-out bypass, waiver consent, and
 * wizard completion → /list (brewer_info landing).
 *
 * The bcoem_test DB is shared: every test seeds what it needs and cleans
 * up after itself.
 */
final class BrewerForm2Test extends PublicSurfaceTestCase
{
    /** @var list<int> */
    private array $createdUsers = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Windows open by default; individual tests close them.
        $this->openWindows();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdUsers as $id) {
            DB::table('staff')->where('uid', $id)->delete();
            DB::table('judging_assignments')->where('bid', $id)->delete();
            DB::table('brewer')->where('uid', $id)->delete();
            DB::table('users')->where('id', $id)->delete();
        }

        parent::tearDown();
    }

    private function openWindows(): void
    {
        DB::table('contest_info')->where('id', 1)->update([
            'contestRegistrationOpen' => '946684800',
            'contestRegistrationDeadline' => '4102444800',
            'contestJudgeOpen' => '946684800',
            'contestJudgeDeadline' => '4102444800',
        ]);
        DB::table('judging_locations')->delete();
    }

    private function closeJudgeWindow(): void
    {
        DB::table('contest_info')->where('id', 1)->update([
            'contestJudgeOpen' => '946684800',
            'contestJudgeDeadline' => '946684900',
        ]);
    }

    /** @param array<string, mixed> $brewerOverrides */
    private function seedUser(string $email = 'pref.user@example.com', array $brewerOverrides = []): int
    {
        $uid = (int) DB::table('users')->insertGetId([
            'user_name' => $email,
            'userLevel' => '2',
            'password' => app('hash')->make('correct-horse-battery'),
            'userQuestion' => 'q?',
            'userQuestionAnswer' => app('hash')->make('a'),
            'userCreated' => now()->format('Y-m-d H:i:s'),
            'userAdminObfuscate' => 1,
        ]);

        DB::table('brewer')->insert(array_merge([
            'uid' => $uid,
            'brewerFirstName' => 'Pref',
            'brewerLastName' => 'User',
            'brewerEmail' => $email,
            'brewerStaff' => 'N',
            'brewerSteward' => 'N',
            'brewerJudge' => 'N',
            'brewerJudgeWaiver' => null,
        ], $brewerOverrides));

        DB::table('staff')->insert([
            'uid' => $uid,
            'staff_judge' => 0,
            'staff_judge_bos' => 0,
            'staff_steward' => 0,
            'staff_organizer' => 0,
            'staff_staff' => 0,
        ]);

        $this->createdUsers[] = $uid;

        return $uid;
    }

    private function user(int $uid): User
    {
        return User::query()->findOrFail($uid);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'brewerJudge' => 'Y',
            'brewerSteward' => 'Y',
            'brewerStaff' => 'N',
            'brewerJudgeID' => 'D1234',
            'brewerJudgeMead' => 'Y',
            'brewerJudgeCider' => 'N',
            'brewerJudgeRank' => ['Certified', 'Professional Brewer'],
            'brewerJudgeExp' => '6-10',
            'brewerJudgeNotes' => 'No allergies',
            'brewerJudgeLocation' => ['Y-1'],
            'brewerStewardLocation' => ['N-1'],
            'brewerJudgeWaiver' => 'Y',
        ], $overrides);
    }

    public function test_form_renders_judge_steward_and_waiver_blocks(): void
    {
        $uid = $this->seedUser();
        DB::table('judging_locations')->insert(['judgingLocName' => 'Main Hall']);

        $response = $this->actingAs($this->user($uid))->get('/list/edit-judging');

        $response->assertOk();
        $response->assertSee('BJCP Rank');
        $response->assertSee('Designations');
        $response->assertSee('Competitions Judged');
        $response->assertSee('Judging Session Availability');
        $response->assertSee('Stewarding Session Availability');
        $response->assertSee('Notes to Organizer');
    }

    public function test_opting_in_writes_role_flags_rank_and_waiver(): void
    {
        $uid = $this->seedUser();

        $response = $this->actingAs($this->user($uid))
            ->post('/list/edit-judging', $this->payload());

        // Wizard completion lands on the brewer_info page (/list).
        $response->assertRedirect('/list?msg=2');

        $row = DB::table('brewer')->where('uid', $uid)->first();
        $this->assertNotNull($row);
        $this->assertSame('Y', $row->brewerJudge);
        $this->assertSame('Y', $row->brewerSteward);
        $this->assertSame('N', $row->brewerStaff);
        $this->assertSame('D1234', $row->brewerJudgeID);
        $this->assertSame('Y', $row->brewerJudgeMead);
        $this->assertSame('N', $row->brewerJudgeCider);
        $this->assertSame('Certified,Professional Brewer', $row->brewerJudgeRank);
        $this->assertSame('6-10', $row->brewerJudgeExp);
        $this->assertSame('No allergies', $row->brewerJudgeNotes);
        $this->assertSame('Y-1', $row->brewerJudgeLocation);
        $this->assertSame('N-1', $row->brewerStewardLocation);
        $this->assertSame('Y', $row->brewerJudgeWaiver);
    }

    public function test_judge_id_is_uppercased_like_legacy(): void
    {
        $uid = $this->seedUser();

        $this->actingAs($this->user($uid))
            ->post('/list/edit-judging', $this->payload(['brewerJudgeID' => 'b274x']));

        $row = DB::table('brewer')->where('uid', $uid)->first();
        $this->assertNotNull($row);
        $this->assertSame('B274X', $row->brewerJudgeID);
    }

    public function test_staff_flag_write(): void
    {
        $uid = $this->seedUser();

        $this->actingAs($this->user($uid))
            ->post('/list/edit-judging', $this->payload(['brewerStaff' => 'Y']));

        $row = DB::table('brewer')->where('uid', $uid)->first();
        $this->assertNotNull($row);
        $this->assertSame('Y', $row->brewerStaff);

        // Legacy's public edit path never flips the *staff pool* flag on
        // opting in — only registration and the admin assignment screens do.
        // (The judge/steward pool flags ARE set here; see
        // test_opting_in_adds_the_role_to_the_judging_pool.)
        $staff = DB::table('staff')->where('uid', $uid)->first();
        $this->assertNotNull($staff);
        $this->assertSame(0, (int) $staff->staff_staff);
    }

    public function test_opting_in_adds_the_role_to_the_judging_pool(): void
    {
        $uid = $this->seedUser();

        $this->actingAs($this->user($uid))
            ->post('/list/edit-judging', $this->payload());

        // An entrant who turns on judge/steward availability joins the pool
        // automatically, so the pool screen lists them without an admin
        // checking the box by hand.
        $staff = (array) DB::table('staff')->where('uid', $uid)->first();
        $this->assertSame(1, (int) $staff['staff_judge']);
        $this->assertSame(1, (int) $staff['staff_steward']);
        $this->assertSame(0, (int) $staff['staff_staff']);
    }

    public function test_waiver_is_required_to_volunteer(): void
    {
        $uid = $this->seedUser();

        $response = $this->actingAs($this->user($uid))
            ->post('/list/edit-judging', $this->payload(['brewerJudgeWaiver' => null]));

        $response->assertSessionHasErrors('brewerJudgeWaiver');
        $row = DB::table('brewer')->where('uid', $uid)->first();
        $this->assertNotNull($row);
        $this->assertSame('N', $row->brewerJudge); // unchanged
        $this->assertSame('N', $row->brewerSteward);
    }

    public function test_waiver_not_required_for_entrant_only_save(): void
    {
        $uid = $this->seedUser();

        $response = $this->actingAs($this->user($uid))
            ->post('/list/edit-judging', $this->payload([
                'brewerJudge' => 'N', 'brewerSteward' => 'N', 'brewerJudgeWaiver' => null,
            ]));

        $response->assertRedirect('/list?msg=2');
        $row = DB::table('brewer')->where('uid', $uid)->first();
        $this->assertNotNull($row);
        $this->assertSame('N', $row->brewerJudge);
    }

    public function test_assignment_json_matches_registration_shape(): void
    {
        $uid = $this->seedUser();

        $this->actingAs($this->user($uid))
            ->post('/list/edit-judging', $this->payload([
                'brewerAssignment' => ['Club Volunteer'],
                'brewerAssignmentOther' => 'homebrew guild',
            ]));

        $row = DB::table('brewer')->where('uid', $uid)->first();
        $this->assertNotNull($row);
        $decoded = json_decode((string) $row->brewerAssignment, true);
        $this->assertSame(['Club Volunteer'], $decoded['affilliated']);
        $this->assertSame(['Homebrew Guild'], $decoded['affilliatedOther']);
    }

    public function test_closed_judge_window_hides_block_and_ignores_posted_fields(): void
    {
        $this->closeJudgeWindow();
        $uid = $this->seedUser();

        $get = $this->actingAs($this->user($uid))->get('/list/edit-judging');
        $get->assertOk();
        $get->assertDontSee('BJCP Rank');

        $this->actingAs($this->user($uid))
            ->post('/list/edit-judging', $this->payload());

        // Gated fields ignored wholesale — row keeps its prior values;
        // ungated staff flag still writes.
        $row = DB::table('brewer')->where('uid', $uid)->first();
        $this->assertNotNull($row);
        $this->assertSame('N', $row->brewerJudge);
        $this->assertSame('N', $row->brewerSteward);
        $this->assertNull($row->brewerJudgeRank);
        $this->assertSame('N', $row->brewerStaff);
    }

    public function test_already_judge_can_edit_when_window_closed(): void
    {
        $this->closeJudgeWindow();
        $uid = $this->seedUser('pref.user@example.com', [
            'brewerJudge' => 'Y',
            'brewerJudgeRank' => 'Recognized',
            'brewerJudgeWaiver' => 'Y',
        ]);
        DB::table('staff')->where('uid', $uid)->update(['staff_judge' => 1]);
        DB::table('judging_assignments')->insert(['bid' => $uid, 'assignment' => 'J', 'assignLocation' => 1]);

        $get = $this->actingAs($this->user($uid))->get('/list/edit-judging');
        $get->assertOk();
        $get->assertSee('BJCP Rank'); // block stays reachable to opt back out

        $this->actingAs($this->user($uid))
            ->post('/list/edit-judging', $this->payload([
                'brewerJudge' => 'N',
                'brewerJudgeRank' => ['National'],
                'confirmDeregisterJudgeAll' => 'Y',
            ]));

        $row = DB::table('brewer')->where('uid', $uid)->first();
        $this->assertNotNull($row);
        $this->assertSame('N', $row->brewerJudge);
        $this->assertSame(0, (int) DB::table('staff')->where('uid', $uid)->value('staff_judge'));
        $this->assertSame(0, DB::table('judging_assignments')->where('bid', $uid)->where('assignment', 'J')->count());
    }

    public function test_completion_lands_on_brewer_info_with_auth_nav(): void
    {
        $uid = $this->seedUser();

        $response = $this->actingAs($this->user($uid))
            ->post('/list/edit-judging', $this->payload());

        $response->assertRedirect('/list?msg=2');

        // Correct nav state: authenticated landing shows My Account in the nav.
        $followed = $this->actingAs($this->user($uid))->get('/list');
        $followed->assertOk();
        $followed->assertSee('My Account');

        // The brewer_info partial renders the legacy thank-you lead.
        $partial = view('brewer.info', BrewerForm2Controller::infoData(TenantContext::load()))->render();
        $this->assertStringContainsString('Thank you for participating in the', $partial);
        $this->assertStringContainsString('Pref User', $partial);
    }

    public function test_anonymous_user_cannot_reach_the_form(): void
    {
        $this->get('/list/edit-judging')->assertRedirect('/login');
        $this->post('/list/edit-judging', $this->payload())->assertRedirect('/login');
    }

    // -----------------------------------------------------------------
    // Issue #1752 — a profile save must never silently wipe assignments
    // -----------------------------------------------------------------

    public function test_issue_1752_absent_judge_fields_do_not_delete_when_window_closed(): void
    {
        $this->closeJudgeWindow();

        // brewer.id deliberately differs from brewer.uid (issue check 1).
        $uid = $this->seedUser('issue1752.closed@example.com', [
            'id' => 990001,
            'brewerJudge' => 'N',
            'brewerSteward' => 'N',
        ]);
        DB::table('judging_assignments')->insert([
            'bid' => $uid, 'assignment' => 'J', 'assignTable' => 9001,
            'assignFlight' => 1, 'assignRound' => 1, 'assignLocation' => 1,
        ]);

        // Judge/steward fields omitted entirely from the save.
        $this->actingAs($this->user($uid))
            ->post('/list/edit-judging', ['brewerStaff' => 'N'])
            ->assertRedirect('/list?msg=2');

        self::assertSame(
            1,
            DB::table('judging_assignments')->where('bid', $uid)->where('assignment', 'J')->count(),
        );
    }

    public function test_issue_1752_absent_judge_fields_do_not_delete_when_window_open(): void
    {
        $uid = $this->seedUser('issue1752.open@example.com', [
            'brewerJudge' => 'N',
            'brewerSteward' => 'N',
        ]);
        DB::table('judging_assignments')->insert([
            'bid' => $uid, 'assignment' => 'J', 'assignTable' => 9001,
            'assignFlight' => 1, 'assignRound' => 1, 'assignLocation' => 1,
        ]);

        $this->actingAs($this->user($uid))
            ->post('/list/edit-judging', ['brewerStaff' => 'N'])
            ->assertRedirect('/list?msg=2');

        self::assertSame(
            1,
            DB::table('judging_assignments')->where('bid', $uid)->where('assignment', 'J')->count(),
        );
    }

    public function test_issue_1752_availability_no_removes_only_that_location(): void
    {
        $uid = $this->seedUser('issue1752.loc@example.com', [
            'brewerJudge' => 'Y',
            'brewerJudgeWaiver' => 'Y',
            'brewerJudgeLocation' => 'Y-1,Y-2',
        ]);
        DB::table('judging_assignments')->insert([
            'bid' => $uid, 'assignment' => 'J', 'assignTable' => 9001,
            'assignFlight' => 1, 'assignRound' => 1, 'assignLocation' => 1,
        ]);
        DB::table('judging_assignments')->insert([
            'bid' => $uid, 'assignment' => 'J', 'assignTable' => 9002,
            'assignFlight' => 1, 'assignRound' => 1, 'assignLocation' => 2,
        ]);

        $this->actingAs($this->user($uid))
            ->post('/list/edit-judging', [
                'brewerJudge' => 'Y',
                'brewerJudgeWaiver' => 'Y',
                'brewerJudgeLocation' => ['N-1', 'Y-2'],
                'brewerStaff' => 'N',
            ])
            ->assertRedirect('/list?msg=2');

        self::assertSame(0, DB::table('judging_assignments')->where('bid', $uid)->where('assignLocation', 1)->count());
        self::assertSame(1, DB::table('judging_assignments')->where('bid', $uid)->where('assignLocation', 2)->count());
        self::assertSame('N-1,Y-2', DB::table('brewer')->where('uid', $uid)->value('brewerJudgeLocation'));
    }

    public function test_issue_1752_location_never_marked_available_is_not_wiped(): void
    {
        // Judge flag on but availability never set; an admin assigned them.
        // The form renders every unset location as "N-<id>", so a plain save
        // must not be read as withdrawing from that location.
        $uid = $this->seedUser('issue1752.noavail@example.com', [
            'brewerJudge' => 'Y',
            'brewerJudgeWaiver' => 'Y',
            'brewerJudgeLocation' => null,
        ]);
        DB::table('judging_assignments')->insert([
            'bid' => $uid, 'assignment' => 'J', 'assignTable' => 9001,
            'assignFlight' => 1, 'assignRound' => 1, 'assignLocation' => 1,
        ]);

        $this->actingAs($this->user($uid))
            ->post('/list/edit-judging', [
                'brewerJudge' => 'Y',
                'brewerJudgeWaiver' => 'Y',
                'brewerJudgeLocation' => ['N-1'],
                'brewerStaff' => 'N',
            ])
            ->assertRedirect('/list?msg=2');

        self::assertSame(1, DB::table('judging_assignments')->where('bid', $uid)->where('assignLocation', 1)->count());
    }

    public function test_issue_1752_withdrawing_assigned_judge_requires_confirmation(): void
    {
        $this->closeJudgeWindow();
        $uid = $this->seedUser('issue1752.block@example.com', [
            'brewerJudge' => 'Y',
            'brewerJudgeRank' => 'Recognized',
            'brewerJudgeWaiver' => 'Y',
        ]);
        DB::table('staff')->where('uid', $uid)->update(['staff_judge' => 1]);
        DB::table('judging_assignments')->insert([
            'bid' => $uid, 'assignment' => 'J', 'assignTable' => 9001,
            'assignFlight' => 1, 'assignRound' => 1, 'assignLocation' => 1,
        ]);

        // Withdrawal without the explicit confirmation: blocked, nothing wiped.
        $this->actingAs($this->user($uid))
            ->post('/list/edit-judging', [
                'brewerJudge' => 'N',
                'brewerJudgeWaiver' => 'Y',
                'brewerStaff' => 'N',
            ])
            ->assertSessionHasErrors('brewerJudge');

        self::assertSame(1, DB::table('judging_assignments')->where('bid', $uid)->where('assignment', 'J')->count());
        self::assertSame('Y', DB::table('brewer')->where('uid', $uid)->value('brewerJudge'));
        self::assertSame(1, (int) DB::table('staff')->where('uid', $uid)->value('staff_judge'));
    }

    public function test_issue_1752_confirmed_withdrawal_removes_role_rows(): void
    {
        $uid = $this->seedUser('issue1752.confirm@example.com', [
            'brewerJudge' => 'Y',
            'brewerJudgeRank' => 'Recognized',
            'brewerJudgeWaiver' => 'Y',
        ]);
        DB::table('staff')->where('uid', $uid)->update(['staff_judge' => 1]);
        DB::table('judging_assignments')->insert([
            'bid' => $uid, 'assignment' => 'J', 'assignTable' => 9001,
            'assignFlight' => 1, 'assignRound' => 1, 'assignLocation' => 1,
        ]);
        DB::table('judging_assignments')->insert([
            'bid' => $uid, 'assignment' => 'J', 'assignTable' => 9002,
            'assignFlight' => 1, 'assignRound' => 1, 'assignLocation' => 2,
        ]);

        $this->actingAs($this->user($uid))
            ->post('/list/edit-judging', [
                'brewerJudge' => 'N',
                'brewerJudgeWaiver' => 'Y',
                'brewerStaff' => 'N',
                'confirmDeregisterJudgeAll' => 'Y',
            ])
            ->assertRedirect('/list?msg=2');

        self::assertSame(0, DB::table('judging_assignments')->where('bid', $uid)->where('assignment', 'J')->count());
        self::assertSame(0, (int) DB::table('staff')->where('uid', $uid)->value('staff_judge'));
        self::assertSame('N', DB::table('brewer')->where('uid', $uid)->value('brewerJudge'));
    }
}
