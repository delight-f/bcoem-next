<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Services\Installation\InstallationService;
use App\Support\Tenant\TenantContext;

/**
 * "New signups must confirm their email" gate.
 *
 * Same two-sources-one-precedence rule as TurnstileGate: an explicit admin
 * setting (preferences.prefsEmailVerify, saved from Site Preferences) wins,
 * and when that is unset the shipped `.env` default
 * (EMAIL_VERIFICATION_ENABLED) applies — so an install that never opened the
 * email tab keeps whatever it had.
 *
 * Read wherever the feature is gated: the `verified` route middleware
 * (App\Http\Middleware\EnsureEmailIsVerified) and the signup mail that sends
 * the confirmation link.
 */
final class EmailVerificationGate
{
    /** preferences column holding the admin's on/off choice. */
    public const PREF = 'prefsEmailVerify';

    public static function enabled(): bool
    {
        $pref = self::pref();

        if ($pref === '1') {
            return true;
        }
        if ($pref === '0') {
            return false;
        }

        return (bool) config('services.email_verification.enabled', false);
    }

    /**
     * The saved choice, or null when it is unset — or when there is no
     * preferences row to read yet. A bare upload has no database, so the
     * `.env` default decides there rather than the gate breaking page loads.
     */
    private static function pref(): ?string
    {
        try {
            return TenantContext::load()->prefsStr(self::PREF);
        } catch (\Throwable $e) {
            if (app(InstallationService::class)->isAlreadyInstalled()) {
                throw $e;
            }

            return null;
        }
    }
}
