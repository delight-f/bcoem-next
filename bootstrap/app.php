<?php

use App\Http\Middleware\ApplyMailSettings;
use App\Http\Middleware\ApplySessionTimeout;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureTopAdmin;
use App\Http\Middleware\SetLocale;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Admin authorization aliases. The whole admin/backoffice/judging/
        // output surface is gated here instead of by an ~143-copy inline
        // `isAdmin()` check in every controller action. `admin` is the
        // uniform gate (userLevel 0/1); `admin.top` is the stricter one
        // (userLevel 0) the destructive actions have always used. Both keep
        // the legacy contract: non-admin → /?msg=99, AJAX → JSON status 9.
        // Attach after `auth`, which resolves the user and bounces guests.
        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'admin.top' => EnsureTopAdmin::class,
        ]);

        // Legacy process.inc.php posts carry no CSRF token (legacy sent
        // bare POSTs); the redirect contract must issue its 307/302
        // before any token check. Downstream port forms stay protected.
        $middleware->validateCsrfTokens(except: [
            'includes/process.inc.php',
            // Provider webhooks are server-to-server: no CSRF token is (or can
            // be) sent. Authenticity is the signature check inside each webhook
            // controller (issue #24, B1 — without this both endpoints 419 in
            // production while tests pass).
            'webhooks/stripe',
            'webhooks/paypal',
        ]);

        // The upgrade wizard is the control plane that must stay reachable while
        // the site is down: the operator needs it to finish the upgrade that put
        // the site into maintenance. Access stays gated to a Top-Level
        // Administrator by EnsureInstalled, and the wizard exits maintenance on
        // its last step. The install wizard's own update path (offered right
        // after an adoption, before anyone can sign in) needs the same
        // exemption, or its progress poll gets a 503 mid-update.
        $middleware->preventRequestsDuringMaintenance([
            'upgrade', 'upgrade/*',
            'install/update', 'install/update/progress',
        ]);

        // Upstream 3.1.0 custom session timeout. PREPENDED, not appended:
        // StartSession resolves the session driver (and its idle window) from
        // config at startup, so the preference must be applied before it runs.
        $middleware->prependToGroup('web', ApplySessionTimeout::class);

        // Install/upgrade routing (issue 27, Task 2.3). Appended to `web`, so
        // the session (and therefore `$request->user()`) is available, and
        // given priority ahead of Authenticate: otherwise a route's `auth`
        // middleware would bounce an uninstalled site to /login instead of the
        // wizard. Still after StartSession/ShareErrorsFromSession.
        $middleware->appendToGroup('web', EnsureInstalled::class);
        $middleware->prependToPriorityList(
            AuthenticatesRequests::class,
            EnsureInstalled::class,
        );

        // PARITY-026: locale resolution per legacy language.lang.php:42-78.
        $middleware->appendToGroup('web', SetLocale::class);

        // Point the mailer at the transport chosen in site preferences
        // (SMTP / host mail program / HTTPS provider) for every request.
        $middleware->appendToGroup('web', ApplyMailSettings::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
