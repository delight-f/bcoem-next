<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Security\EmailVerificationGate;
use Illuminate\Support\Facades\DB;

/**
 * Email verification is switched on from Site Preferences -> Spam Protection,
 * not from .env alone: it shipped env-only, so the admin page could describe
 * the feature but not turn it on.
 *
 * One precedence rule, the same as TurnstileGate: the saved preference wins,
 * and EMAIL_VERIFICATION_ENABLED is only the default for an install that has
 * never saved that tab. The middleware behaviour this switch drives is
 * asserted in SignupProtectionTest.
 */
final class EmailVerificationGateTest extends PublicSurfaceTestCase
{
    /** @var array<string, mixed> */
    private array $origPrefs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->origPrefs = (array) DB::table('preferences')->where('id', 1)->first();
        config(['services.email_verification.enabled' => false]);
    }

    protected function tearDown(): void
    {
        DB::table('preferences')->where('id', 1)->update($this->origPrefs);

        parent::tearDown();
    }

    private function setPref(?string $value): void
    {
        DB::table('preferences')->where('id', 1)->update([EmailVerificationGate::PREF => $value]);
    }

    public function test_preference_turns_the_feature_on_and_off(): void
    {
        $this->setPref('1');
        self::assertTrue(EmailVerificationGate::enabled());

        $this->setPref('0');
        self::assertFalse(EmailVerificationGate::enabled());
    }

    public function test_preference_overrides_the_env_default(): void
    {
        config(['services.email_verification.enabled' => true]);
        $this->setPref('0');
        self::assertFalse(EmailVerificationGate::enabled());
    }

    public function test_unset_preference_falls_back_to_the_env_default(): void
    {
        $this->setPref(null);

        config(['services.email_verification.enabled' => false]);
        self::assertFalse(EmailVerificationGate::enabled());

        config(['services.email_verification.enabled' => true]);
        self::assertTrue(EmailVerificationGate::enabled());
    }
}
