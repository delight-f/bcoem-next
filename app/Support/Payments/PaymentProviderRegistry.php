<?php

declare(strict_types=1);

namespace App\Support\Payments;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Which payment providers are configured on this install (issue #24 P3).
 *
 * enabled() drives both the pay-page button list and the server-side
 * re-validation in PayController@checkout — a crafted `provider=` for an
 * unconfigured install must be rejected, never selected.
 *
 * Stripe is enabled per-tenant (preferences.prefsStripe.account_id, written by
 * the Connect flow); PayPal is enabled per-install from env (D3). Manual
 * marking is deliberately absent: it is an admin action, not a checkout.
 */
final class PaymentProviderRegistry
{
    /**
     * @return array<string, GatewayAdapter> keyed by PaymentService::METHOD_*
     */
    public function enabled(): array
    {
        $success = URL::route('pay.callback');
        $cancel = URL::route('pay.cancel');

        $providers = [];

        if (self::stripeConnected()) {
            $providers[PaymentService::METHOD_STRIPE] = StripeGateway::forTenant(
                $success.'?session_id={CHECKOUT_SESSION_ID}',
                $cancel,
            );
        }

        if (PayPalGateway::configured()) {
            $providers[PaymentService::METHOD_PAYPAL] = PayPalGateway::forTenant(
                $success.'?provider=paypal',
                $cancel,
            );
        }

        return $providers;
    }

    /** Null when the key is unknown or that provider is not enabled. */
    public function get(string $key): ?GatewayAdapter
    {
        return $this->enabled()[$key] ?? null;
    }

    /** Default selection when a request names no provider (D2): Stripe, then PayPal. */
    public function default(): ?GatewayAdapter
    {
        $enabled = $this->enabled();

        return $enabled[PaymentService::METHOD_STRIPE]
            ?? $enabled[PaymentService::METHOD_PAYPAL]
            ?? null;
    }

    private static function stripeConnected(): bool
    {
        try {
            $cfg = json_decode(
                (string) DB::table('preferences')->where('id', 1)->value('prefsStripe'),
                true,
            );
        } catch (\Throwable) {
            return false; // console / no-DB contexts
        }

        return is_array($cfg) && ($cfg['account_id'] ?? '') !== '';
    }
}
