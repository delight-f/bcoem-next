<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Tenant\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the installation's configured session (auto-logout) timeout to the
 * framework's idle expiry — upstream 3.1.0 `prefsSessionTimeout`
 * (update/run_update.php:5203-5205), which legacy applied in its own session
 * bootstrap (paths.php:293-297).
 *
 * The preference drives the visible countdown on its own, but without this the
 * SERVER would still expire the session at config('session.lifetime'). An admin
 * setting a longer timeout would then be logged out early, so the setting only
 * works if the framework idle window moves with it.
 *
 * Must run BEFORE StartSession resolves the session driver, hence
 * prependToGroup('web', ...) in bootstrap/app.php.
 */
final class ApplySessionTimeout
{
    public function handle(Request $request, Closure $next): Response
    {
        $ctx = TenantContext::load();
        $minutes = (int) ($ctx->prefsStr('prefsSessionTimeout') ?? '');

        if ($minutes <= 0) {
            return $next($request);
        }

        // Restore afterwards: config is process-global, so leaving the override
        // in place would leak the tenant's timeout into later requests handled
        // by the same process (visible in tests, which issue many requests).
        // The session driver is resolved during $next, so it still sees the
        // override; only requests after this one are affected by the reset.
        $original = config('session.lifetime');
        config(['session.lifetime' => $minutes]);

        try {
            return $next($request);
        } finally {
            config(['session.lifetime' => $original]);
        }
    }
}
