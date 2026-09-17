<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Payments\StripeSettings;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $cfg = StripeSettings::config();

        return view('admin.stripe', [
            'ctx' => TenantContext::load(),
            'salutation' => __('site.my_account'),
            'accountId' => $cfg['account_id'] ?? null,
            'webhookSecretSet' => ($cfg['webhook_secret'] ?? '') !== '',
            'clientIdSet' => StripeSettings::hasClientId(),
            'secretKeySet' => StripeSettings::hasSecret(),
        ]);
    }

    /** Kick off the Connect Standard OAuth dance at Stripe. */
    public function connect(Request $request): RedirectResponse
    {
        $this->guard($request);

        $clientId = StripeSettings::get()['client_id'];
        if ($clientId === '') {
            return redirect()
                ->route('admin.stripe')
                ->with('error', 'The Stripe OAuth client id is not configured — add the platform keys on the Payment Setup screen.');
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
            $platform = StripeSettings::get();
            $resp = OAuth::token(
                [
                    'grant_type' => 'authorization_code',
                    'client_id' => $platform['client_id'],
                    'code' => (string) $request->input('code'),
                ],
                ['api_key' => $platform['secret']],
            );
            $accountId = (string) ($resp->stripe_user_id ?? '');
        } catch (Throwable) {
            $accountId = '';
        }

        if ($accountId === '') {
            return redirect()->route('admin.stripe')
                ->with('error', 'Stripe token exchange failed.');
        }

        StripeSettings::merge(['account_id' => $accountId]);

        return redirect()->route('admin.stripe')->with('status', 'Stripe account connected.');
    }

    /** The webhook signing secret is created in the dashboard; paste it in. */
    public function saveSecret(Request $request): RedirectResponse
    {
        $this->guard($request);

        $data = $request->validate(['webhook_secret' => ['required', 'string', 'starts_with:whsec_']]);

        StripeSettings::merge(['webhook_secret' => $data['webhook_secret']]);

        return redirect()->route('admin.stripe')->with('status', 'Webhook signing secret saved.');
    }

    private function guard(Request $request): void
    {
        abort_unless($request->user()?->isAdmin() ?? false, 403);
    }
}
