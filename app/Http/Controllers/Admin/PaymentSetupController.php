<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Payments\PayPalSettings;
use App\Support\Tenant\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Payment provider setup (issue #24 follow-up). One plain-language screen
 * where a non-technical organizer enables the online payment options:
 *
 *   - Stripe Connect: status + the one-click Connect button, with the webhook
 *     endpoint and signing-secret steps (the form itself stays on
 *     StripeConnectController's page, which this screen links to).
 *   - PayPal: inline credential form. Values are stored encrypted via
 *     PayPalSettings; blank secret keeps the existing one.
 *
 * Admin-gated in-controller, like StripeConnectController.
 */
final class PaymentSetupController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $this->guard($request);

        /** @var array<string, mixed> $stripe */
        $stripe = json_decode((string) DB::table('preferences')->where('id', 1)->value('prefsStripe'), true) ?: [];
        $paypal = PayPalSettings::get();

        return view('admin.payment-setup', [
            'ctx' => TenantContext::load(),
            'salutation' => __('site.my_account'),
            'stripe' => [
                'accountId' => (string) ($stripe['account_id'] ?? ''),
                'webhookSecretSet' => (string) ($stripe['webhook_secret'] ?? '') !== '',
                'clientIdSet' => (bool) Config::get('services.stripe.client_id'),
                'secretKeySet' => (bool) Config::get('services.stripe.secret'),
            ],
            'paypal' => [
                'mode' => $paypal['mode'],
                'clientId' => $paypal['client_id'],
                'webhookId' => $paypal['webhook_id'],
                'secretSet' => $paypal['client_secret'] !== '',
                'configured' => PayPalSettings::configured(),
                'fromEnv' => $paypal['source'] === 'env',
            ],
            'stripeWebhookUrl' => url('/webhooks/stripe'),
            'paypalWebhookUrl' => url('/webhooks/paypal'),
        ]);
    }

    public function savePayPal(Request $request): RedirectResponse
    {
        $this->guard($request);

        $data = $request->validate([
            'mode' => ['required', 'in:sandbox,live'],
            'client_id' => ['required', 'string', 'max:255'],
            // First-time setup needs the secret; later saves may leave it blank
            // to keep the stored one (the field is never pre-filled).
            'client_secret' => [PayPalSettings::hasSecret() ? 'nullable' : 'required', 'string', 'max:1000'],
            'webhook_id' => ['required', 'string', 'max:255'],
        ]);

        PayPalSettings::save([
            'mode' => (string) $data['mode'],
            'client_id' => trim((string) $data['client_id']),
            'client_secret' => trim((string) ($data['client_secret'] ?? '')),
            'webhook_id' => trim((string) $data['webhook_id']),
        ]);

        return redirect()->route('admin.payments.setup')->with('status', 'PayPal settings saved.');
    }

    /** Remove saved PayPal credentials (reverts to env, or disables PayPal). */
    public function removePayPal(Request $request): RedirectResponse
    {
        $this->guard($request);

        PayPalSettings::forget();

        return redirect()->route('admin.payments.setup')->with('status', 'PayPal settings removed.');
    }

    private function guard(Request $request): void
    {
        abort_unless($request->user()?->isAdmin() ?? false, 403);
    }
}
