<?php

declare(strict_types=1);

/**
 * bcoem-next test bootstrap.
 *
 * The ported domain layer (src/) still depends on legacy artifacts, vendored
 * under legacy/ until each is replaced by a Laravel-native equivalent:
 *   - legacy/MysqliDb.php       (data layer backend; replaced in Slice C+)
 *   - legacy/sanitize.lib.php   (sterilize()/is_https(); ported with Slice B)
 *   - legacy/common.lib.php et al. (function sources for characterization tests)
 */

// Legacy feature flags referenced throughout the vendored code. All off —
// the hosted platform mode was never part of this codebase's reality.
define('HOSTED', false);
define('NHC', false);
define('SINGLE', false);
define('EVALUATION', true);

/**
 * Legacy path constants still referenced by the ported domain layer and its
 * tests. ROOT points at legacy/ so file-based lookups resolve to vendored
 * files; each constant dies when its subsystem is replaced.
 */
define('ROOT', __DIR__.'/../legacy/');
define('DB', ROOT);
define('LIB', ROOT);
define('INCLUDES', ROOT);
define('CONFIG', ROOT);

if (session_status() !== PHP_SESSION_ACTIVE) {
    $tmp = sys_get_temp_dir().'/bcoem-next-test-sessions-'.getmypid();
    if (! is_dir($tmp)) {
        mkdir($tmp, 0700, true);
    }
    session_save_path($tmp);
    session_start();
}

// MysqliDb emits a PHP 8.4 deprecation notice at definition time
// (insertMulti() implicit nullable) — silenced only for this require.
set_error_handler(static fn (int $severity, string $message, string $file): bool => str_contains($message, 'Implicitly marking parameter $dataKeys as nullable'));
try {
    require_once ROOT.'MysqliDb.php';
} finally {
    restore_error_handler();
}

require_once ROOT.'sanitize.lib.php';

require __DIR__.'/../vendor/autoload.php';
