<?php

namespace App\Providers;

use App\Support\Payments\GatewayAdapter;
use App\Support\Payments\StripeGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Live wiring (payments plan W1): the tenant's connected Stripe
        // account (preferences.prefsStripe) is the only online gateway.
        // Bound ONLY when connected — PayController's app()->bound() gate
        // then renders the legacy "payment unavailable" state otherwise;
        // manual marking stays admin-side. One tenant per request/database,
        // so a registration-time read is safe (classic FPM lifecycle).
        try {
            $cfg = json_decode((string) DB::table('preferences')->where('id', 1)->value('prefsStripe'), true) ?: [];
        } catch (\Throwable) {
            $cfg = []; // console/no-DB contexts: no online gateway
        }

        if (($cfg['account_id'] ?? '') !== '') {
            $this->app->bind(GatewayAdapter::class, function () {
                $success = URL::route('pay.callback').'?session_id={CHECKOUT_SESSION_ID}';

                return StripeGateway::forTenant($success, URL::route('pay.cancel'));
            });
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
