<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;

/**
 * Quick vs standard admin registration, and the per-role form shape.
 *
 * Legacy sections/register.sec.php gates the contact block behind
 * $view == "default" and the judge/steward profile fields behind $go. The
 * port destructured quickView but the blade never used it, so every
 * dashboard register option rendered the same full form.
 */
final class RegisterQuickStandardTest extends PublicSurfaceTestCase
{
    private const ADMIN_ID = 9701;

    private const ADMIN_EMAIL = 'quick.standard.admin@brewingcompetitions.com';

    /** bcrypt hash whose plaintext is "bcoem" (shared by the backoffice tests). */
    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    private int $createdUserId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('judging_locations')->delete();
        DB::table('contest_info')->where('id', 1)->update([
            'contestRegistrationOpen' => '946684800',
            'contestRegistrationDeadline' => '4102444800',
            'contestJudgeOpen' => '946684800',
            'contestJudgeDeadline' => '4102444800',
        ]);

        DB::table('users')->where('id', self::ADMIN_ID)->delete();
        DB::table('users')->insert([
            'id' => self::ADMIN_ID,
            'user_name' => self::ADMIN_EMAIL,
            'password' => self::HASH,
            'userLevel' => '0',
            'userCreated' => '2024-01-01 00:00:01',
            'userAdminObfuscate' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->createdUserId !== 0) {
            DB::table('staff')->where('uid', $this->createdUserId)->delete();
            DB::table('brewer')->where('uid', $this->createdUserId)->delete();
            DB::table('users')->where('id', $this->createdUserId)->delete();
        }
        DB::table('users')->where('id', self::ADMIN_ID)->delete();

        parent::tearDown();
    }

    private function loginAdmin(): void
    {
        $this->post('/login', ['loginUsername' => self::ADMIN_EMAIL, 'loginPassword' => 'bcoem']);
        $this->assertAuthenticated();
    }

    public function test_quick_admin_form_omits_contact_block_and_posts_dummies(): void
    {
        $this->loginAdmin();

        $this->get('/register/judge?view=quick')
            ->assertOk()
            ->assertSee('Quickly add a participant', false)
            ->assertSee('name="brewerAddress" value="1234 Main Street"', false)
            ->assertSee('name="brewerCountry" value="United States"', false)
            ->assertSee('name="brewerJudgeID"', false)
            ->assertDontSee('id="brewerAddress"', false)
            ->assertDontSee('id="password"', false);
    }

    public function test_standard_admin_form_keeps_contact_block_and_password(): void
    {
        $this->loginAdmin();

        $this->get('/register/judge')
            ->assertOk()
            ->assertDontSee('Quickly add a participant', false)
            ->assertSee('id="brewerAddress"', false)
            ->assertSee('id="password"', false);
    }

    public function test_role_forms_render_different_fields(): void
    {
        $this->get('/register/judge')->assertOk()
            ->assertSee('name="brewerJudgeID"', false)
            ->assertSee('name="brewerJudgeRank[]"', false)
            ->assertSee('name="brewerJudgeWaiver"', false);

        $this->get('/register/steward')->assertOk()
            ->assertSee('name="brewerJudgeID"', false)
            ->assertDontSee('name="brewerJudgeRank[]"', false)
            ->assertSee('name="brewerJudgeWaiver"', false);

        $this->get('/register/entrant')->assertOk()
            ->assertDontSee('name="brewerJudgeID"', false)
            ->assertDontSee('name="brewerJudgeWaiver"', false);
    }

    public function test_admin_quick_registration_falls_back_to_legacy_defaults(): void
    {
        $this->loginAdmin();

        // The quick form posts neither password nor security Q/A.
        $this->post('/register/judge', [
            'user_name' => 'quick.added@example.com',
            'brewerFirstName' => 'Quick',
            'brewerLastName' => 'Added',
            'brewerJudge' => 'Y',
        ])->assertRedirect('/backoffice/participants?msg=1');

        $user = DB::table('users')->where('user_name', 'quick.added@example.com')->first();
        $this->assertNotNull($user);
        $this->createdUserId = (int) $user->id;

        // Legacy fixed default password (register.sec.php:375).
        $this->assertTrue(app('hash')->check('bcoem', $user->password));
        $this->assertSame('Randomly generated.', $user->userQuestion);
        $this->assertNotSame('', (string) $user->userQuestionAnswer);

        $brewer = DB::table('brewer')->where('uid', $user->id)->first();
        $this->assertNotNull($brewer);
        $this->assertSame('Y', $brewer->brewerJudge);

        $staff = DB::table('staff')->where('uid', $user->id)->first();
        $this->assertNotNull($staff);
        $this->assertSame(1, (int) $staff->staff_judge);
    }
}
