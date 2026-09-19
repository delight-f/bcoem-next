<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Tenant\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The /eval/* sub-app only exists while "Electronic Scoresheets" is enabled
 * (preferences.prefsEval). Disabling it hides the links; this makes the
 * routes unavailable too, so a bookmark or typed URL cannot still open the
 * scoresheets or hit the process/import endpoints (B3-06).
 */
final class EnsureEvalEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if ((int) TenantContext::load()->prefsStr('prefsEval') !== 1) {
            abort(404);
        }

        return $next($request);
    }
}
