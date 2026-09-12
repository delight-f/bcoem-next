<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/**
 * Signup bot/spam protection (issue 15): honeypot, Turnstile, the named
 * signup rate limiter, and the opt-in email-verification gate.
 *
 * Each layer is asserted at its shipped default and when toggled, because the
 * whole point of the design is that a default install's registration flow is
 * unchanged and every layer is independently switchable.
 */
final class SignupProtectionTest extends PublicSurfaceTestCase
{
    /** @var list<int> */
    private array $createdUsers = [];

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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::table('preferences')->where('id', 1)->update(['prefsCAPTCHA' => null, 'prefsGoogleAccount' => null]);
        foreach ($this->createdUsers as $id) {
            DB::table('staff')->where('uid', $id)->delete();
            DB::table('brewer')->where('uid', $id)->delete();
            DB::table('users')->where('id', $id)->delete();
        }

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function payload(string $email = 'bot.test@example.com'): array
    {
        return [
            'user_name' => $email,
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'userQuestion' => 'What is your favorite all-time beer to drink?',
            'userQuestionAnswer' => 'pabst',
            'brewerFirstName' => 'Bot',
            'brewerLastName' => 'Test',
            'brewerCountry' => 'United States',
            'brewerProAm' => '0',
            'brewerJudge' => 'N',
            'brewerSteward' => 'N',
        ];
    }

    public function test_honeypot_fields_render_by_default(): void
    {
        $html = (string) $this->get('/register')->assertOk()->getContent();

        // Randomized field name prefixed "my_name" plus the time-trap field.
        $this->assertMatchesRegularExpression('/name="my_name_[^"]+"/', $html);
        $this->assertStringContainsString('name="valid_from"', $html);
    }

    public function test_honeypot_fields_absent_when_disabled(): void
    {
        config(['honeypot.enabled' => false]);

        $html = (string) $this->get('/register')->assertOk()->getContent();

        $this->assertStringNotContainsString('name="valid_from"', $html);
        $this->assertDoesNotMatchRegularExpression('/name="my_name_/', $html);
    }

    public function test_filled_honeypot_is_silently_rejected(): void
    {
        $html = (string) $this->get('/register')->assertOk()->getContent();
        preg_match('/name="(my_name_[^"]+)"/', $html, $m);
        $name = (string) ($m[1] ?? '');
        $this->assertNotSame('', $name, 'honeypot field not found');

        // A bot fills every field it sees, including the hidden one.
        $response = $this->post('/register/entrant', $this->payload() + [$name => 'spam']);

        // Silent blank 200 — no distinguishable error, no user created.
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
        $this->assertNull(DB::table('users')->where('user_name', 'bot.test@example.com')->first());
    }

    public function test_signup_throttle_blocks_sixth_attempt_and_resets_after_window(): void
    {
        // Invalid payload: still counts toward the limit, but writes nothing.
        for ($i = 1; $i <= 5; $i++) {
            $this->post('/register/entrant', [])->assertStatus(302);
        }

        $this->post('/register/entrant', [])->assertStatus(429);

        Carbon::setTestNow(now()->addMinutes(11));
        $this->post('/register/entrant', [])->assertStatus(302);
    }

    public function test_turnstile_widget_renders_only_when_enabled(): void
    {
        // Default: off, no widget.
        DB::table('preferences')->where('id', 1)->update(['prefsCAPTCHA' => null, 'prefsGoogleAccount' => null]);
        config(['services.turnstile.enabled' => false]);
        $this->assertStringNotContainsString('cf-turnstile', (string) $this->get('/register')->getContent());

        config(['services.turnstile.enabled' => true, 'services.turnstile.site_key' => 'site-key', 'services.turnstile.secret_key' => 'secret-key']);
        $this->assertStringContainsString('cf-turnstile', (string) $this->get('/register')->getContent());
    }

    public function test_turnstile_enabled_without_secret_rejects_and_logs(): void
    {
        DB::table('preferences')->where('id', 1)->update(['prefsCAPTCHA' => '1', 'prefsGoogleAccount' => null]);
        config(['services.turnstile.enabled' => false]);

        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn (string $message): bool => str_contains($message, 'secret key is missing'));

        $this->post('/register/entrant', $this->payload())
            ->assertSessionHasErrors('cf-turnstile-response');

        $this->assertNull(DB::table('users')->where('user_name', 'bot.test@example.com')->first());
    }

    public function test_entry_route_is_not_verified_gated_by_default(): void
    {
        $route = app('router')->getRoutes()->getByName('brew.create');
        self::assertNotNull($route);
        $middleware = $route->gatherMiddleware();

        $this->assertNotContains('verified', $middleware);
    }

    public function test_verified_middleware_redirects_unverified_user_to_notice(): void
    {
        Route::get('/_test-verified', fn () => 'ok')->middleware(['web', 'auth', 'verified']);

        DB::table('users')->where('id', 1)->update(['email_verified_at' => null]);
        $user = User::findOrFail(1);
        $this->actingAs($user);

        $this->get('/_test-verified')->assertRedirect(route('verification.notice'));
    }
}
