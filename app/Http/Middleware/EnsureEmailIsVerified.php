<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Security\EmailVerificationGate;
use Closure;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified as BaseMiddleware;

/**
 * Laravel's `verified` middleware, made a no-op when the site has the
 * feature switched off.
 *
 * The routes that must require a confirmed address (adding or editing an
 * entry, paying) carry `verified` unconditionally, and this decides at
 * request time whether it bites. Gating the route list instead — building
 * the middleware array from the preference while routes register — would
 * freeze the choice into `route:cache` and need a database read during route
 * registration, before an install has a database at all.
 */
final class EnsureEmailIsVerified extends BaseMiddleware
{
    public function handle($request, Closure $next, $redirectToRoute = null)
    {
        if (! EmailVerificationGate::enabled()) {
            return $next($request);
        }

        return parent::handle($request, $next, $redirectToRoute);
    }
}
