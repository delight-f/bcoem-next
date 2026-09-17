<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Payments\PaymentProviderRegistry;
use App\Support\Payments\PaymentService;
use App\Support\Payments\PayPalSettings;
use App\Support\Payments\StripeSettings;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * Payment provider setup screen (issue #24 follow-up): admin-gated, PayPal
 * credentials stored encrypted and never echoed back, saving enables the
 * provider, and removing disables it again.
 */
final class PaymentSetupTest extends PublicSurfaceTestCase
{
    private const ADMIN = 'payments-admin@brewingcompetitions.com';

    private const ENTRANT = 'payments-entrant@brewingcompetitions.com';

    private const HASH = '$2a$08$2qgODWiSaYfLTVhu.2qVSer30aG7cLQZX0To01CqinyFyUbwdO64C';

    /** @var array<string, mixed> */
    private array $origPrefs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $prefs = DB::table('preferences')->where('id', 1)->first(['prefsStripe', 'prefsPaypalConfig']);
        $this->origPrefs = $prefs === null ? [] : (array) $prefs;

        DB::table('preferences')->where('id', 1)->update([
            'prefsStripe' => '',
            'prefsPaypalConfig' => null,
        ]);

        foreach ([9401 => [self::ADMIN, '0'], 9402 => [self::ENTRANT, '2']] as $id => [$email, $level]) {
            if (! DB::table('users')->where('id', $id)->exists()) {
                DB::table('users')->insert([
                    'id' => $id,
                    'user_name' => $email,
                    'password' => self::HASH,
                    'userLevel' => $level,
                    'userCreated' => '2024-01-01 00:00:01',
                    'userAdminObfuscate' => 0,
                ]);
            }
        }
    }

    protected function tearDown(): void
    {
        DB::table('users')->whereIn('id', [9401, 9402])->delete();

        if ($this->origPrefs !== []) {
            DB::table('preferences')->where('id', 1)->update($this->origPrefs);
        }

        parent::tearDown();
    }

    public function test_setup_screen_requires_login(): void
    {
        $this->get('/admin/payments/setup')->assertRedirect('/login');
    }

    public function test_setup_screen_is_forbidden_for_entrants(): void
    {
        $this->login(self::ENTRANT);

        $this->get('/admin/payments/setup')->assertStatus(Response::HTTP_FORBIDDEN);
    }

    public function test_setup_screen_renders_for_admins(): void
    {
        $this->login(self::ADMIN);

        $this->get('/admin/payments/setup')
            ->assertOk()
            ->assertSee('Payment Setup')
            ->assertSee('PayPal');
    }

    public function test_saved_credentials_are_encrypted_and_enable_the_provider(): void
    {
        $this->login(self::ADMIN);

        $this->savePayPal()->assertRedirect(route('admin.payments.setup'));

        $raw = (string) DB::table('preferences')->where('id', 1)->value('prefsPaypalConfig');

        self::assertStringContainsString('AZ-client-id', $raw);
        self::assertStringContainsString('WH-1', $raw);
        self::assertStringNotContainsString('super-secret-value', $raw, 'client secret must be encrypted at rest');

        self::assertTrue(PayPalSettings::configured());
        self::assertSame('super-secret-value', PayPalSettings::get()['client_secret']);

        self::assertContains(
            PaymentService::METHOD_PAYPAL,
            array_keys(app(PaymentProviderRegistry::class)->enabled()),
        );
    }

    public function test_blank_secret_keeps_the_stored_secret(): void
    {
        $this->login(self::ADMIN);

        $this->savePayPal();
        $this->post('/admin/payments/setup/paypal', [
            'mode' => 'live',
            'client_id' => 'AZ-client-id-updated',
            'client_secret' => '',
            'webhook_id' => 'WH-1',
        ])->assertRedirect(route('admin.payments.setup'));

        $settings = PayPalSettings::get();

        self::assertSame('live', $settings['mode']);
        self::assertSame('AZ-client-id-updated', $settings['client_id']);
        self::assertSame('super-secret-value', $settings['client_secret'], 'blank secret must keep the stored one');
    }

    public function test_secret_is_never_rendered_back(): void
    {
        $this->login(self::ADMIN);
        $this->savePayPal();

        $html = (string) $this->get('/admin/payments/setup')->assertOk()->getContent();

        self::assertStringNotContainsString('super-secret-value', $html);
        self::assertStringContainsString('Saved — leave blank to keep', $html);
    }

    public function test_removing_settings_disables_the_provider(): void
    {
        $this->login(self::ADMIN);
        $this->savePayPal();

        $this->post('/admin/payments/setup/paypal/remove')->assertRedirect(route('admin.payments.setup'));

        self::assertNull(DB::table('preferences')->where('id', 1)->value('prefsPaypalConfig'));
        self::assertFalse(PayPalSettings::configured());
        self::assertNotContains(
            PaymentService::METHOD_PAYPAL,
            array_keys(app(PaymentProviderRegistry::class)->enabled()),
        );
    }

    // -----------------------------------------------------------------
    // Stripe platform keys (issue #47: in-app entry, not env-only)
    // -----------------------------------------------------------------

    public function test_stripe_platform_keys_are_saved_encrypted_and_enable_connect(): void
    {
        $this->login(self::ADMIN);

        $this->saveStripe()->assertRedirect(route('admin.payments.setup'));

        $raw = (string) DB::table('preferences')->where('id', 1)->value('prefsStripe');
        self::assertStringContainsString('ca_platform_id', $raw);
        self::assertStringNotContainsString('sk_platform_secret', $raw, 'secret key must be encrypted at rest');

        $settings = StripeSettings::get();
        self::assertSame('ca_platform_id', $settings['client_id']);
        self::assertSame('sk_platform_secret', $settings['secret']);
        self::assertSame('database', $settings['source']);
        self::assertTrue(StripeSettings::hasSecret());

        // Both screens now offer the Connect flow.
        $this->get('/admin/payments/setup')->assertOk()->assertSee('Connect with Stripe');
        $this->get('/admin/stripe')->assertOk()->assertSee('Connect with Stripe');
    }

    public function test_stripe_blank_secret_keeps_the_stored_key(): void
    {
        $this->login(self::ADMIN);
        $this->saveStripe();

        $this->post('/admin/payments/setup/stripe', [
            'client_id' => 'ca_platform_id_updated',
            'client_secret' => '',
        ])->assertRedirect(route('admin.payments.setup'));

        $settings = StripeSettings::get();
        self::assertSame('ca_platform_id_updated', $settings['client_id']);
        self::assertSame('sk_platform_secret', $settings['secret'], 'blank secret must keep the stored one');
    }

    public function test_stripe_first_time_secret_is_required(): void
    {
        $this->login(self::ADMIN);

        $this->post('/admin/payments/setup/stripe', [
            'client_id' => 'ca_platform_id',
            'client_secret' => '',
        ])->assertSessionHasErrors('client_secret');
    }

    public function test_stripe_secret_is_never_rendered_back(): void
    {
        $this->login(self::ADMIN);
        $this->saveStripe();

        $html = (string) $this->get('/admin/payments/setup')->assertOk()->getContent();

        self::assertStringNotContainsString('sk_platform_secret', $html);
        self::assertStringContainsString('Saved — leave blank to keep', $html);
    }

    public function test_saving_stripe_keys_preserves_the_connect_values(): void
    {
        DB::table('preferences')->where('id', 1)->update([
            'prefsStripe' => json_encode(['account_id' => 'acct_1', 'webhook_secret' => 'whsec_1']),
        ]);

        $this->login(self::ADMIN);
        $this->saveStripe();

        /** @var array<string, string> $raw */
        $raw = json_decode((string) DB::table('preferences')->where('id', 1)->value('prefsStripe'), true);

        self::assertSame('acct_1', $raw['account_id']);
        self::assertSame('whsec_1', $raw['webhook_secret']);
        self::assertSame('ca_platform_id', $raw['client_id']);
    }

    public function test_stripe_keys_fall_back_to_env_when_nothing_is_saved(): void
    {
        config(['services.stripe.client_id' => 'ca_env', 'services.stripe.secret' => 'sk_env']);

        $settings = StripeSettings::get();

        self::assertSame('ca_env', $settings['client_id']);
        self::assertSame('sk_env', $settings['secret']);
        self::assertSame('env', $settings['source']);
    }

    /** @return TestResponse<Response> */
    private function saveStripe(): TestResponse
    {
        return $this->post('/admin/payments/setup/stripe', [
            'client_id' => 'ca_platform_id',
            'client_secret' => 'sk_platform_secret',
        ]);
    }

    /** @return TestResponse<Response> */
    private function savePayPal(): TestResponse
    {
        return $this->post('/admin/payments/setup/paypal', [
            'mode' => 'sandbox',
            'client_id' => 'AZ-client-id',
            'client_secret' => 'super-secret-value',
            'webhook_id' => 'WH-1',
        ]);
    }

    private function login(string $email): void
    {
        $this->post('/login', [
            'loginUsername' => $email,
            'loginPassword' => 'bcoem',
        ]);
    }
}
