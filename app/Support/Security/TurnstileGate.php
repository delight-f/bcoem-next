<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Support\Tenant\TenantContext;

/**
 * Cloudflare Turnstile signup gate.
 *
 * Two ways to turn it on, one precedence rule: an explicit admin setting
 * (preferences.prefsCAPTCHA, saved from Site Preferences) wins; when that is
 * unset, the shipped `.env` default (TURNSTILE_ENABLED) applies. Keys come
 * from preferences.prefsGoogleAccount ("site|secret") when both are present,
 * else from the env keys.
 *
 * The effective keys are copied onto the package's `turnstile.*` config
 * because coderflex/laravel-turnstile reads them there (TurnstileCheck and
 * the widget both do). syncConfig() must run before the widget renders or the
 * rule validates.
 */
final class TurnstileGate
{
    public static function enabled(): bool
    {
        $pref = TenantContext::load()->prefsStr('prefsCAPTCHA');

        if ($pref === '1') {
            return true;
        }
        if ($pref === '0') {
            return false;
        }

        return (bool) config('services.turnstile.enabled', false);
    }

    public static function syncConfig(): void
    {
        [$site, $secret] = self::keys();

        if ($site !== null && $site !== '') {
            config(['turnstile.turnstile_site_key' => $site]);
        }
        if ($secret !== null && $secret !== '') {
            config(['turnstile.turnstile_secret_key' => $secret]);
        }
    }

    public static function hasSecret(): bool
    {
        return (string) config('turnstile.turnstile_secret_key') !== '';
    }

    /** @return array{0:?string,1:?string} site key, secret key */
    private static function keys(): array
    {
        $account = (string) (TenantContext::load()->prefsStr('prefsGoogleAccount') ?? '');
        if (str_contains($account, '|')) {
            [$site, $secret] = explode('|', $account, 2);
            if (trim($site) !== '' && trim($secret) !== '') {
                return [trim($site), trim($secret)];
            }
        }

        return [
            config('services.turnstile.site_key') ? (string) config('services.turnstile.site_key') : null,
            config('services.turnstile.secret_key') ? (string) config('services.turnstile.secret_key') : null,
        ];
    }
}
