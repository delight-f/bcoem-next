<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Tenant\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stripe\OAuth;
use Throwable;

/**
 * Admin Connect Standard onboarding (P3.5b): the organizer connects the
 * competition's own Stripe account via OAuth; the resulting connected
 * account id and (pasted) webhook signing secret are stored in
 * preferences.prefsStripe — see StripeGateway::forTenant().
 */
final class StripeConnectController extends Controller
{
    public function show(Request $request): object
    {
        $this->guard($request);

        $cfg = self::stripeConfig();

        return view('admin.stripe', [
            'ctx' => TenantContext::load(),
            'salutation' => __('site.my_account'),
            'accountId' => $cfg['account_id'] ?? null,
            'webhookSecretSet' => ($cfg['webhook_secret'] ?? '') !== '',
            'clientIdSet' => (bool) config('services.stripe.client_id'),
            'secretKeySet' => (bool) config('services.stripe.secret'),
        ]);
    }

    /** Kick off the Connect Standard OAuth dance at Stripe. */
    public function connect(Request $request): RedirectResponse
    {
        $this->guard($request);

        $clientId = (string) config('services.stripe.client_id');
        if ($clientId === '') {
            return redirect()
                ->route('admin.stripe')
                ->with('error', 'STRIPE_CLIENT_ID is not configured.');
        }

        $query = http_build_query([
            'response_type' => 'code',
            'scope' => 'read_write',
            'client_id' => $clientId,
            'redirect_uri' => route('admin.stripe.callback'),
        ]);

        return redirect()->away('https://connect.stripe.com/oauth/authorize?'.$query);
    }

    public function callback(Request $request): RedirectResponse
    {
        $this->guard($request);

        if ($request->filled('error') || ! $request->filled('code')) {
            return redirect()->route('admin.stripe')
                ->with('error', 'Stripe authorization was not completed.');
        }

        // ponytail: SDK's static OAuth helper hits the live token
        // endpoint; untestable without a stub — kept to a single call.
        try {
            $resp = OAuth::token(
                [
                    'grant_type' => 'authorization_code',
                    'client_id' => (string) config('services.stripe.client_id'),
                    'code' => (string) $request->input('code'),
                ],
                ['api_key' => (string) config('services.stripe.secret')],
            );
            $accountId = (string) ($resp->stripe_user_id ?? '');
        } catch (Throwable) {
            $accountId = '';
        }

        if ($accountId === '') {
            return redirect()->route('admin.stripe')
                ->with('error', 'Stripe token exchange failed.');
        }

        self::mergePrefs(['account_id' => $accountId]);

        return redirect()->route('admin.stripe')->with('status', 'connected');
    }

    /** The webhook signing secret is created in the dashboard; paste it in. */
    public function saveSecret(Request $request): RedirectResponse
    {
        $this->guard($request);

        $data = $request->validate(['webhook_secret' => ['required', 'string', 'starts_with:whsec_']]);

        self::mergePrefs(['webhook_secret' => $data['webhook_secret']]);

        return redirect()->route('admin.stripe')->with('status', 'webhook-secret-saved');
    }

    private function guard(Request $request): void
    {
        abort_unless($request->user()?->isAdmin() ?? false, 403);
    }

    /**
     * @return array<string, mixed>
     */
    private static function stripeConfig(): array
    {
        $prefs = DB::table('preferences')->where('id', 1)->value('prefsStripe');

        return json_decode((string) ($prefs ?? ''), true) ?: [];
    }

    /**
     * @param  array<string, string>  $patch
     */
    private static function mergePrefs(array $patch): void
    {
        DB::table('preferences')->where('id', 1)->update([
            'prefsStripe' => json_encode(self::stripeConfig() + $patch, JSON_THROW_ON_ERROR),
            // Legacy rows have no updated_at; leave timestamps alone.
        ]);
    }
}
