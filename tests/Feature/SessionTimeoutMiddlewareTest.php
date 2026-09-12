<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\ApplySessionTimeout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Upstream 3.1.0 `prefsSessionTimeout` must move the framework's idle expiry,
 * not just the visible countdown: an admin setting a LONGER timeout would
 * otherwise still be logged out at config('session.lifetime').
 *
 * Asserted mid-chain (inside $next), because the override is deliberately
 * restored once the request completes — config is process-global.
 */
final class SessionTimeoutMiddlewareTest extends PublicSurfaceTestCase
{
    public function test_preference_overrides_the_idle_window_during_the_request(): void
    {
        $origPref = DB::table('preferences')->where('id', 1)->value('prefsSessionTimeout');
        $origLifetime = config('session.lifetime');

        try {
            DB::table('preferences')->where('id', 1)->update(['prefsSessionTimeout' => 300]);

            $seen = null;
            $response = (new ApplySessionTimeout)->handle(
                Request::create('/', 'GET'),
                function () use (&$seen): Response {
                    $seen = config('session.lifetime');

                    return new Response('ok');
                },
            );

            self::assertSame(200, $response->getStatusCode());
            self::assertSame(300, $seen);
            // Restored after the request: no leak into the next one.
            self::assertSame($origLifetime, config('session.lifetime'));
        } finally {
            DB::table('preferences')->where('id', 1)->update(['prefsSessionTimeout' => $origPref]);
            config(['session.lifetime' => $origLifetime]);
        }
    }

    public function test_blank_preference_leaves_the_idle_window_untouched(): void
    {
        $origPref = DB::table('preferences')->where('id', 1)->value('prefsSessionTimeout');
        $origLifetime = config('session.lifetime');

        try {
            DB::table('preferences')->where('id', 1)->update(['prefsSessionTimeout' => null]);

            $seen = null;
            (new ApplySessionTimeout)->handle(
                Request::create('/', 'GET'),
                function () use (&$seen): Response {
                    $seen = config('session.lifetime');

                    return new Response('ok');
                },
            );

            self::assertSame($origLifetime, $seen);
        } finally {
            DB::table('preferences')->where('id', 1)->update(['prefsSessionTimeout' => $origPref]);
            config(['session.lifetime' => $origLifetime]);
        }
    }
}
