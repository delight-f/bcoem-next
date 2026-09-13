<?php

namespace App\Providers;

use App\Services\Installation\Fixups\SyncCentralClubsList;
use App\Services\Installation\UpgradeFixups;
use App\Support\Payments\GatewayAdapter;
use App\Support\Payments\PaymentProviderRegistry;
use App\Support\Payments\PayPalGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PaymentProviderRegistry::class);

        // Live wiring (payments plan W1 + issue #24): the tenant's connected
        // Stripe account (preferences.prefsStripe) and/or this install's PayPal
        // env config. Bound ONLY when at least one is available — PayController's
        // app()->bound() gate then renders the legacy "payment unavailable"
        // state otherwise; manual marking stays admin-side. One tenant per
        // request/database, so a registration-time read is safe (classic FPM
        // lifecycle).
        try {
            $cfg = json_decode((string) DB::table('preferences')->where('id', 1)->value('prefsStripe'), true) ?: [];
            $stripeConnected = ($cfg['account_id'] ?? '') !== '';
        } catch (\Throwable) {
            $stripeConnected = false; // console/no-DB contexts: no tenant gateway
        }

        if ($stripeConnected || PayPalGateway::configured()) {
            $this->app->bind(GatewayAdapter::class, function () {
                return app(PaymentProviderRegistry::class)->default()
                    ?? throw new \RuntimeException('No payment provider is configured.');
            });
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Central clubs list (issue #22). The sync is schedule-driven, but a
        // shared/FTP host has no cron, so the first sync is also registered as
        // an upgrade fixup: the wizard the operator already runs populates the
        // picker on completion. Keyed to the release that ships the clubs
        // tables; a skipped or renumbered release is covered by the picker's
        // lazy refresh instead.
        UpgradeFixups::register('4.0.0', '4.1.0', SyncCentralClubsList::class);

        // Signup throttle: 5 attempts per 10 minutes per IP. Generous enough
        // for a person fixing a validation error, tight enough to slow
        // scripted abuse. Named (not an inline throttle:6,1) so the value is
        // documented in one place; applied only to the register route.
        RateLimiter::for('signup', fn (Request $request) => Limit::perMinutes(10, 5)->by($request->ip()));
    }
}
