<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Payments\PaymentProviderRegistry;
use App\Support\Payments\PaymentService;
use Illuminate\Support\Facades\DB;

/**
 * Provider registry (issue #24 P3): enabled() decides which providers the pay
 * page offers AND which a checkout request may name. A partially configured or
 * disabled provider must never appear or be selectable.
 */
final class PaymentProviderRegistryTest extends PublicSurfaceTestCase
{
    /** @var array<string, mixed> */
    private const PAYPAL = [
        'mode' => 'sandbox',
        'client_id' => 'client-id',
        'client_secret' => 'client-secret',
        'webhook_id' => 'webhook-id',
        'currency' => 'USD',
        'app_id' => '',
    ];

    /** @var array<string, mixed> */
    private array $origPrefs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $prefs = DB::table('preferences')->where('id', 1)->first(['prefsStripe', 'prefsPaypalConfig']);
        $this->origPrefs = $prefs === null ? [] : (array) $prefs;

        DB::table('preferences')->where('id', 1)->update(['prefsStripe' => '', 'prefsPaypalConfig' => null]);
        config(['services.paypal' => []]);
    }

    protected function tearDown(): void
    {
        if ($this->origPrefs !== []) {
            DB::table('preferences')->where('id', 1)->update($this->origPrefs);
        }

        parent::tearDown();
    }

    public function test_nothing_configured_means_no_enabled_providers(): void
    {
        $registry = app(PaymentProviderRegistry::class);

        self::assertSame([], $registry->enabled());
        self::assertNull($registry->default());
        self::assertNull($registry->get(PaymentService::METHOD_PAYPAL));
        self::assertNull($registry->get(PaymentService::METHOD_STRIPE));
    }

    public function test_paypal_only_install_enables_paypal(): void
    {
        config(['services.paypal' => self::PAYPAL]);

        $registry = app(PaymentProviderRegistry::class);

        self::assertSame([PaymentService::METHOD_PAYPAL], array_keys($registry->enabled()));
        self::assertSame(PaymentService::METHOD_PAYPAL, $registry->default()?->method());
    }

    public function test_partial_paypal_config_is_not_enabled(): void
    {
        config(['services.paypal' => array_merge(self::PAYPAL, ['webhook_id' => ''])]);

        self::assertSame([], app(PaymentProviderRegistry::class)->enabled());
        self::assertNull(app(PaymentProviderRegistry::class)->get(PaymentService::METHOD_PAYPAL));
    }

    public function test_stripe_connected_plus_paypal_enables_both_with_stripe_default(): void
    {
        DB::table('preferences')->where('id', 1)->update([
            'prefsStripe' => json_encode(['account_id' => 'acct_connected', 'webhook_secret' => 'whsec_test'], JSON_THROW_ON_ERROR),
        ]);
        config(['services.paypal' => self::PAYPAL]);

        $registry = app(PaymentProviderRegistry::class);

        self::assertSame(
            [PaymentService::METHOD_STRIPE, PaymentService::METHOD_PAYPAL],
            array_keys($registry->enabled()),
        );
        self::assertSame(PaymentService::METHOD_STRIPE, $registry->default()?->method());
        self::assertNull($registry->get('bogus'));
    }

    public function test_disabled_provider_is_not_selectable(): void
    {
        DB::table('preferences')->where('id', 1)->update([
            'prefsStripe' => json_encode(['account_id' => 'acct_connected', 'webhook_secret' => 'whsec_test'], JSON_THROW_ON_ERROR),
        ]);
        config(['services.paypal' => []]);

        self::assertNull(app(PaymentProviderRegistry::class)->get(PaymentService::METHOD_PAYPAL));
    }
}
