<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Leaves a long-lived "was signed in" hint cookie after any authenticated
 * request.
 *
 * Laravel destroys the session data (and its cookie) when the idle window
 * expires, so by the time the next request arrives nothing is left to tell
 * "your session just expired" apart from "you were never signed in": the
 * guest gets bounced to a bare /login with no explanation. This cookie is
 * the surviving breadcrumb.
 *
 * Read back by the `redirectGuestsTo` closure in bootstrap/app.php (present
 * → /login?timeout=1, which renders the inactivity notice) and cleared on
 * logout and once the notice has been shown. It carries no identity and is
 * never trusted for authorization — a forged value can only display a
 * message.
 */
final class RememberSignedInSession
{
    /** Cookie name, also read by bootstrap/app.php's guest redirect. */
    public const COOKIE = 'bcoem_signed_in';

    /** Longer than any configurable session timeout (30 days). */
    private const MINUTES = 60 * 24 * 30;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Peek at the session key the guard writes on login rather than
        // Auth::check(), so guest/public requests never resolve a user.
        $signedIn = $request->session()->has(Auth::guard()->getName());

        if ($signedIn && $request->cookie(self::COOKIE) !== '1') {
            Cookie::queue(self::COOKIE, '1', self::MINUTES);
        }

        return $response;
    }
}
