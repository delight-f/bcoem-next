<?php

declare(strict_types=1);

namespace App\Support\Tenant;

use Illuminate\Http\Request;

/**
 * PARITY-026 — Language resolution helpers for the runtime toggle.
 *
 * Maps the legacy language selection contract (language.lang.php:42-78 +
 * pub/nav.pub.php:130-152) to Laravel's locale system. The legacy stores
 * prefsLanguage as a full code (e.g. "cs-CZ") and the folder is the
 * lowercase first segment ("cs"). The port's lang/ files are keyed by
 * that folder name.
 */
final class Language
{
    /**
     * Canonical codes for the installed lang/ folders, keyed by folder name
     * (legacy constants.inc.php $languages). Unknown folders are skipped.
     */
    private const CANONICAL = [
        'cs' => 'cs-CZ',
        'en' => 'en-US',
        'es' => 'es-419',
        'fr' => 'fr-FR',
        'hu' => 'hu-HU',
        'pt' => 'pt-BR',
    ];

    /**
     * Available language codes from the installed lang/ packs.
     * Mirrors legacy's get_available_language_codes() (common.lib.php:160-170)
     * which globs the lang folders.
     *
     * @return list<string>
     */
    public static function availableCodes(): array
    {
        $base = lang_path();
        $codes = [];
        $files = glob("$base/*/site.php");
        if ($files === false) {
            return [];
        }
        foreach ($files as $file) {
            $folder = basename(dirname($file));
            if (isset(self::CANONICAL[$folder])) {
                $codes[] = self::CANONICAL[$folder];
            }
        }
        sort($codes);

        return $codes;
    }

    /**
     * Convert a full language code (e.g. "cs-CZ") to the lang/ folder
     * name (e.g. "cs"). Legacy: $prefsLanguageFolder = strtolower(first segment).
     */
    public static function folder(string $code): string
    {
        $parts = explode('-', $code);
        $folder = strtolower($parts[0] ?? 'en');

        // Legacy special case: "English"/"english" → "en" folder.
        if ($folder === 'english') {
            return 'en';
        }

        // Fall back to 'en' if the folder doesn't exist.
        if (! is_dir(lang_path($folder))) {
            return 'en';
        }

        return $folder;
    }

    /**
     * Whether the current request is on an admin/evaluation/setup/update
     * section that must force en-US (language.lang.php:95-110).
     */
    public static function isAdminSection(Request $request): bool
    {
        return $request->is('admin')
            || $request->is('admin/*')
            || $request->is('backoffice*')
            || $request->is('eval*')
            || $request->is('setup*')
            || $request->is('update*');
    }
}
