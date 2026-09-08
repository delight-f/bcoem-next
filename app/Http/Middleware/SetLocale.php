<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Tenant\Language;
use App\Support\Tenant\TenantContext;
use Closure;
use Illuminate\Http\Request;

/**
 * PARITY-026 — Set the application locale from the tenant's language
 * preferences and the per-session userLanguage cookie.
 *
 * Maps legacy language.lang.php:42-78 (prefsLanguage + userLanguage
 * cookie override) to Laravel's setLocale(). Admin sections always
 * force en-US (language.lang.php:95-110).
 */
final class SetLocale
{
    public function handle(Request $request, Closure $next): mixed
    {
        $ctx = TenantContext::load();

        // ?lang= param → set 30-day cookie + redirect (nav.pub.php:142-152).
        $langParam = $request->query('lang');
        if (is_string($langParam) && $langParam !== '') {
            $toggle = $ctx->prefsStr('prefsLanguageToggle');
            $options = $this->availableOptions($ctx);
            if ($toggle === 'Y' && in_array($langParam, $options, true)) {
                cookie()->queue('userLanguage', $langParam, 60 * 24 * 30); // 30 days

                return redirect()->to($request->url());
            }
        }

        $locale = 'en';
        if (Language::isAdminSection($request)) {
            $locale = 'en';
        } else {
            $userLang = $request->cookie('userLanguage');
            $toggle = $ctx->prefsStr('prefsLanguageToggle');
            $options = $this->availableOptions($ctx);

            if ($toggle === 'Y' && is_string($userLang) && $userLang !== '' && in_array($userLang, $options, true)) {
                $locale = Language::folder($userLang);
            } else {
                $prefsLang = (string) ($ctx->prefsStr('prefsLanguage') ?? 'en-US');
                $locale = Language::folder($prefsLang);
            }
        }

        app()->setLocale($locale);

        return $next($request);
    }

    /** @return list<string> */
    private function availableOptions(TenantContext $ctx): array
    {
        $options = json_decode((string) ($ctx->prefsStr('prefsLanguageOptions') ?? ''), true);

        if (! is_array($options)) {
            return Language::availableCodes();
        }

        return array_values(array_filter($options, fn ($v): bool => is_string($v)));
    }
}
