<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\PasswordResetMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Password reset flow (ticket 03): forgot → security question → token +
 * email → reset form → bcrypt + token cleared. Token storage reuses
 * users.userToken/userTokenTime (D2 — no password_reset_tokens table).
 *
 * Fixture: baseline_users id=1 (user.baseline@brewingcompetitions.com)
 * with userQuestionAnswer = $2a$ hash of md5('pabst').
 */
final class PasswordResetFlowTest extends PublicSurfaceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Seed the baseline admin with known credentials + clear token.
        DB::table('users')->where('id', 1)->update([
            'password' => '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C',
            'userLevel' => '0',
            'userQuestion' => 'What is your favorite all-time beer to drink?',
            'userQuestionAnswer' => '$2a$08$gImDLllgw/nned4kVWDAD.394FXpXeoEip85oqEQ.fIy8s4U3lwx.',
            'userToken' => null,
            'userTokenTime' => null,
        ]);
        // Ensure a brewer row exists for the name in the reset mail.
        if (! DB::table('brewer')->where('uid', 1)->exists()) {
            DB::table('brewer')->insert([
                'uid' => 1,
                'brewerFirstName' => 'Default',
                'brewerLastName' => 'Admin',
                'brewerEmail' => 'user.baseline@brewingcompetitions.com',
            ]);
        }
    }

    public function test_forgot_page_renders(): void
    {
        $this->get('/forgot-password')->assertOk()->assertSee('Forgot Password');
    }

    public function test_forgot_unknown_email_shows_error(): void
    {
        $this->post('/forgot-password', ['email' => 'nobody@example.com'])
            ->assertSessionHasErrors('email');
    }

    public function test_forgot_known_email_redirects_to_security_question(): void
    {
        $this->post('/forgot-password', ['email' => 'user.baseline@brewingcompetitions.com'])
            ->assertRedirect(route('password.verify', [
                'email' => 'user.baseline@brewingcompetitions.com',
                'question' => 'What is your favorite all-time beer to drink?',
            ]));
    }

    public function test_verify_page_renders_question(): void
    {
        $this->get(route('password.verify', [
            'email' => 'user.baseline@brewingcompetitions.com',
            'question' => 'What is your favorite all-time beer to drink?',
        ]))->assertOk()->assertSee('What is your favorite all-time beer to drink?');
    }

    public function test_wrong_security_answer_is_rejected(): void
    {
        Mail::fake();

        $this->post(route('password.verify.post'), [
            'email' => 'user.baseline@brewingcompetitions.com',
            'answer' => 'wrong-answer',
        ])->assertSessionHasErrors('answer');

        Mail::assertNothingSent();
    }

    public function test_correct_answer_issues_token_and_emails_reset_link(): void
    {
        Mail::fake();

        $this->post(route('password.verify.post'), [
            'email' => 'user.baseline@brewingcompetitions.com',
            'answer' => 'pabst',
        ])->assertRedirect(route('password.forgot'));

        $user = DB::table('users')->where('id', 1)->first();
        $this->assertNotNull($user);
        $this->assertNotNull($user->userToken);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $user->userToken);
        $this->assertNotNull($user->userTokenTime);

        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) use ($user) {
            $this->assertStringContainsString('user.baseline@brewingcompetitions.com', $mail->to[0]['address']);
            $this->assertStringContainsString($user->userToken, $mail->resetUrl);

            return true;
        });
    }

    public function test_reset_page_with_valid_token_renders_form(): void
    {
        DB::table('users')->where('id', 1)->update([
            'userToken' => str_repeat('ab', 16),
            'userTokenTime' => time(),
        ]);

        $this->get(route('password.reset', ['token' => str_repeat('ab', 16)]))
            ->assertOk()->assertSee('Reset Password');
    }

    public function test_reset_page_with_invalid_token_shows_error(): void
    {
        $this->get(route('password.reset', ['token' => 'deadbeef']))
            ->assertOk()->assertSee('invalid');
    }

    public function test_reset_page_with_expired_token_shows_expired(): void
    {
        DB::table('users')->where('id', 1)->update([
            'userToken' => str_repeat('cd', 16),
            'userTokenTime' => time() - 90000, // > 24h ago
        ]);

        $this->get(route('password.reset', ['token' => str_repeat('cd', 16)]))
            ->assertOk()->assertSee('expired');
    }

    public function test_reset_sets_bcrypt_password_and_clears_token(): void
    {
        $token = str_repeat('ef', 16);
        DB::table('users')->where('id', 1)->update([
            'userToken' => $token,
            'userTokenTime' => time(),
        ]);

        $this->post(route('password.reset.post'), [
            'token' => $token,
            'loginUsername' => 'user.baseline@brewingcompetitions.com',
            'newPassword1' => 'brand-new-password-123',
            'newPassword2' => 'brand-new-password-123',
        ])->assertRedirect('/?msg=18');

        $user = DB::table('users')->where('id', 1)->first();
        $this->assertNotNull($user);
        $this->assertStringStartsWith('$2y$', $user->password);
        $this->assertNull($user->userToken);
        $this->assertNull($user->userTokenTime);

        // New password works for login.
        $this->post('/login', [
            'loginUsername' => 'user.baseline@brewingcompetitions.com',
            'loginPassword' => 'brand-new-password-123',
        ])->assertRedirect('/?section=admin');
    }

    public function test_reset_with_mismatched_email_and_token_is_rejected(): void
    {
        DB::table('users')->where('id', 1)->update([
            'userToken' => str_repeat('12', 16),
            'userTokenTime' => time(),
        ]);

        $this->post(route('password.reset.post'), [
            'token' => str_repeat('12', 16),
            'loginUsername' => 'other@example.com',
            'newPassword1' => 'brand-new-password-123',
            'newPassword2' => 'brand-new-password-123',
        ])->assertSessionHasErrors('token');
    }
}
