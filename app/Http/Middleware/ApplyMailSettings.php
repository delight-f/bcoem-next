<?php

declare(strict_types=1);

namespace App\Http\Middleware;

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
        MailSettings::apply();

        return $next($request);
    }
}
