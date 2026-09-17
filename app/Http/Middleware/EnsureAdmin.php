<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Single admin gate for the admin surface. Replaces the ~143 inline
 * `if (! ($request->user()?->isAdmin() ?? false)) { return redirect('/?msg=99'); }`
 * checks every admin/judging/output controller used to carry — one place to
 * keep the rule instead of one per action.
 *
 * Contract is deliberately identical to the inline checks it replaces:
 *  - a guest never reaches here: `auth` runs first and redirects to /login;
 *  - an authenticated non-admin is bounced to the legacy /?msg=99 notice;
 *  - an AJAX caller gets the JSON `status:9` envelope instead of a redirect,
 *    so an endpoint that answers JSON never turns its refusal into HTML.
 */
final class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (self::passes($request)) {
            return $next($request);
        }

        return self::deny($request);
    }

    public static function passes(Request $request): bool
    {
        return $request->user()?->isAdmin() ?? false;
    }

    public static function deny(Request $request): Response
    {
        return $request->expectsJson()
            ? response()->json(['status' => 9], 403)
            : redirect('/?msg=99');
    }
}
