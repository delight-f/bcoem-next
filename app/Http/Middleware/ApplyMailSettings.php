<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Installation\InstallationService;
use App\Support\Mail\MailSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the site-preferences email transport to the mailer for the
 * current request, so every send site (registration confirmations,
 * password resets, payment receipts, the contact form, test email) uses
 * the admin's chosen transport without each one knowing about it.
 */
final class ApplyMailSettings
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            MailSettings::apply();
        } catch (\Throwable $e) {
            // A bare upload has no database yet, so there is no saved transport
            // to apply and the .env mailer is the sensible default. On an
            // installed site the failure is rethrown rather than masked.
            if (app(InstallationService::class)->isAlreadyInstalled()) {
                throw $e;
            }
        }

        return $next($request);
    }
}
