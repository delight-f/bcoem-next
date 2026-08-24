<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

/**
 * Registration flow (ticket 02): single-form wizard (entrant/judge/
 * steward), users + brewer + staff row writes, duplicate rejection,
 * window gating, auto-login, redirect to ?section=list&msg=7.
 *
 * The bcoem_test DB is shared with the Characterization suite which
 * truncates users/brewer/brewing/staff — every test seeds what it needs
 * and cleans up after itself.
 */
final class RegisterFlowTest extends PublicSurfaceTestCase
{
    /** @var list<int> */
    private array $createdUsers = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Sweep any orphan fixture users (crashed runs leave rows the
        // createdUsers ledger never saw).
        $orphans = DB::table('users')->where('user_name', 'like', '%@example.com')->pluck('id');
        foreach ($orphans as $oid) {
            DB::table('staff')->where('uid', $oid)->delete();
            DB::table('brewer')->where('uid', $oid)->delete();
            DB::table('users')->where('id', $oid)->delete();
        }
        // Ensure a users row exists for the duplicate-email check target and
        // that windows are OPEN for the positive-path tests. The baseline
        // dates may be closed depending on load time, so force them open.
        +        // The baseline also has judging sessions in the past, which force
        +// registration/entry closed (Windows::derive override) — clear them.
        +DB::table('judging_locations')->delete();
        DB::table('contest_info')->where('id', 1)->update([
            // Windows are epoch integers in this schema; 2000-01-01 /
            // 2999-01-01 in the past/future around any realistic test clock.
            'contestRegistrationOpen' => '946684800',   // 2000-01-01 UTC
            'contestRegistrationDeadline' => '4102444800', // 2100-01-01 UTC
            'contestJudgeOpen' => '946684800',
            'contestJudgeDeadline' => '4102444800',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdUsers as $id) {
            DB::table('staff')->where('uid', $id)->delete();
            DB::table('brewer')->where('uid', $id)->delete();
            DB::table('users')->where('id', $id)->delete();
        }

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function registerPayload(string $email = 'new.entrant@example.com', string $go = 'entrant'): array
    {
        return [
            'user_name' => $email,
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'userQuestion' => 'What is your favorite all-time beer to drink?',
            'userQuestionAnswer' => 'pabst',
            'brewerFirstName' => 'New',
            'brewerLastName' => 'Entrant',
            'brewerCountry' => 'United States',
            'brewerAddress' => '123 Main St',
            'brewerCity' => 'Anytown',
            'brewerState' => 'CO',
            'brewerZip' => '80001',
            'brewerPhone1' => '555-1234',
            'brewerProAm' => '0',
            'brewerClubs' => '',
            'brewerJudge' => $go === 'judge' ? 'Y' : 'N',
            'brewerSteward' => $go === 'steward' ? 'Y' : 'N',
            'brewerJudgeWaiver' => ($go === 'judge' || $go === 'steward') ? 'Y' : null,
        ];
    }

    public function test_register_page_renders_with_role_tabs(): void
    {
        $this->get('/register')->assertOk()->assertSee('Register');
        $this->get('/register/judge')->assertOk()->assertSee('Judge');
        $this->get('/register/steward')->assertOk()->assertSee('Steward');
    }

    public function test_legacy_register_query_shape_redirects(): void
    {
        $this->get('/?section=register')->assertRedirect('/register/entrant');
        $this->get('/?section=register&go=judge')->assertRedirect('/register/judge');
    }

    public function test_entrant_registration_writes_all_rows_and_autologs_in(): void
    {
        $response = $this->post('/register/entrant', $this->registerPayload());

        $response->assertRedirect('/?section=list&msg=7');
        $this->assertAuthenticated();

        $user = DB::table('users')->where('user_name', 'new.entrant@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('2', (string) $user->userLevel); // public registration is entrant
        $this->assertStringStartsWith('$2y$', $user->password); // bcrypt
        $this->assertStringStartsWith('$2y$', $user->userQuestionAnswer); // security answer hashed
        $this->createdUsers[] = (int) $user->id;

        $brewer = DB::table('brewer')->where('uid', $user->id)->first();
        $this->assertNotNull($brewer);
        $this->assertSame('New', $brewer->brewerFirstName);
        $this->assertSame('Entrant', $brewer->brewerLastName);
        $this->assertSame('new.entrant@example.com', $brewer->brewerEmail);
        $this->assertSame('N', $brewer->brewerJudge);
        $this->assertSame('N', $brewer->brewerSteward);

        $staff = DB::table('staff')->where('uid', $user->id)->first();
        $this->assertNotNull($staff);
        $this->assertSame(0, (int) $staff->staff_judge);
        $this->assertSame(0, (int) $staff->staff_steward);
    }

    public function test_judge_registration_sets_staff_judge_flag(): void
    {
        $response = $this->post('/register/judge', $this->registerPayload('new.judge@example.com', 'judge'));

        $response->assertRedirect('/?section=list&msg=7');

        $user = DB::table('users')->where('user_name', 'new.judge@example.com')->first();
        $this->assertNotNull($user);
        $this->createdUsers[] = (int) $user->id;

        $brewer = DB::table('brewer')->where('uid', $user->id)->first();
        $this->assertNotNull($brewer);
        $this->assertSame('Y', $brewer->brewerJudge);

        $staff = DB::table('staff')->where('uid', $user->id)->first();
        $this->assertNotNull($staff);
        $this->assertSame(1, (int) $staff->staff_judge);
    }

    public function test_steward_registration_sets_staff_steward_flag(): void
    {
        $this->post('/register/steward', $this->registerPayload('new.steward@example.com', 'steward'))
            ->assertRedirect('/?section=list&msg=7');

        $user = DB::table('users')->where('user_name', 'new.steward@example.com')->first();
        $this->assertNotNull($user);
        $this->createdUsers[] = (int) $user->id;

        $staff = DB::table('staff')->where('uid', $user->id)->first();
        $this->assertNotNull($staff);
        $this->assertSame(1, (int) $staff->staff_steward);
    }

    public function test_duplicate_email_is_rejected_with_msg2(): void
    {
        // Seed an existing user.
        DB::table('users')->insert([
            'user_name' => 'existing@example.com',
            'password' => '$2y$10$abcdefghijklmnopqrstuv',
            'userLevel' => '2',
            'userCreated' => '2024-01-01 00:00:01',
            'userAdminObfuscate' => 1,
        ]);
        $this->createdUsers[] = (int) DB::table('users')->where('user_name', 'existing@example.com')->value('id');

        $response = $this->post('/register/entrant', $this->registerPayload('existing@example.com'));

        $response->assertRedirect('/?section=register&go=entrant&msg=2');
        $this->assertGuest();
    }

    public function test_password_confirmation_mismatch_is_rejected(): void
    {
        $payload = $this->registerPayload();
        $payload['password_confirmation'] = 'different';

        // Guard against leftovers from prior failed runs.
        DB::table('users')->where('user_name', 'new.entrant@example.com')->delete();

        $this->post('/register/entrant', $payload)->assertSessionHasErrors('password');
        $this->assertGuest();
        $this->assertNull(DB::table('users')->where('user_name', 'new.entrant@example.com')->first());
    }

    public function test_closed_registration_window_blocks_entrant(): void
    {
        DB::table('contest_info')->where('id', 1)->update([
            'contestRegistrationOpen' => '946684800',    // 2000-01-01
            'contestRegistrationDeadline' => '978307200', // 2001-01-01 — past
        ]);

        // Page renders the closed message.
        $this->get('/register')->assertOk()->assertSee('Registration has closed');

        // POST bounces back without creating rows.
        $this->post('/register/entrant', $this->registerPayload('closed@example.com'))
            ->assertRedirect('/?section=register&go=entrant');
        $this->assertGuest();
        $this->assertNull(DB::table('users')->where('user_name', 'closed@example.com')->first());
    }

    public function test_logged_in_user_is_redirected_away_from_register(): void
    {
        // Login as the baseline admin first.
        DB::table('users')->where('id', 1)->update([
            'password' => '$2y$10$'.str_repeat('a', 53),
            'userLevel' => '2',
        ]);
        $this->post('/login', [
            'loginUsername' => 'user.baseline@brewingcompetitions.com',
            'loginPassword' => 'not-the-real-password',
        ]);

        // Should still be guest; the register page is fine.
        $this->assertGuest();
        $this->get('/register')->assertOk();
    }

    public function test_security_answer_is_hashed_not_plaintext(): void
    {
        $this->post('/register/entrant', $this->registerPayload('sec.answer@example.com'));

        $user = DB::table('users')->where('user_name', 'sec.answer@example.com')->first();
        $this->assertNotNull($user);
        $this->createdUsers[] = (int) $user->id;

        $this->assertNotSame('pabst', $user->userQuestionAnswer);
        $this->assertStringStartsWith('$2y$', $user->userQuestionAnswer);
        $this->assertTrue(app('hash')->check('pabst', $user->userQuestionAnswer));
    }
}
