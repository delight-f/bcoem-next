<?php

declare(strict_types=1);
use Composer\Semver\Semver;

/**
 * Bundled platform check for the SSH installer.
 *
 * This is the one place the shell script asks about PHP and the required
 * extensions. It reads the constraint and the `ext-*` list straight from the
 * application's own composer.json, which is the same list
 * App\Services\Installation\InstallationService::checkPreconditions() reads -
 * so a version bump or a new extension requirement cannot drift between the
 * VPS installer, the CLI, the health check and CI.
 *
 * The constraint is evaluated by composer/semver (shipped in the release's
 * vendor/), never by string-comparing `php -v` against a version number.
 *
 * Usage: php php-check.php [app-root]
 * Exit 0 when everything is present, 1 when something is missing, 2 on usage
 * or setup errors.
 */
$root = rtrim($argv[1] ?? dirname(__DIR__), '/');
$composerPath = $root.'/composer.json';

if (! is_file($composerPath)) {
    fwrite(STDERR, "php-check: composer.json not found at {$composerPath}\n");

    exit(2);
}

$composer = json_decode((string) file_get_contents($composerPath), true);
$require = is_array($composer) && is_array($composer['require'] ?? null) ? $composer['require'] : [];

if ($require === []) {
    fwrite(STDERR, "php-check: composer.json has no require section\n");

    exit(2);
}

$ok = true;

$report = static function (bool $passed, string $message) use (&$ok): void {
    fwrite(STDOUT, ($passed ? '  [ok] ' : '  [!!] ').$message."\n");
    $ok = $ok && $passed;
};

// --- PHP version, evaluated against composer.json's constraint ---------------
$constraint = isset($require['php']) ? (string) $require['php'] : null;
if ($constraint !== null) {
    $autoload = $root.'/vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }

    if (class_exists(Semver::class)) {
        $satisfied = Semver::satisfies(PHP_VERSION, $constraint);
        $report(
            $satisfied,
            $satisfied
                ? 'PHP '.PHP_VERSION.' meets the requirement ('.$constraint.').'
                : 'This site needs PHP '.$constraint.'. This server runs PHP '.PHP_VERSION.'.',
        );
    } else {
        // No vendor/ (or no semver). Let Composer evaluate the constraint the
        // same way it would for install, rather than hand-parsing it here.
        $output = [];
        $code = 1;
        if (is_callable('exec')) {
            exec('cd '.escapeshellarg($root).' && composer check-platform-reqs --no-dev 2>&1', $output, $code);
        }
        $report(
            $code === 0,
            $code === 0
                ? 'PHP '.PHP_VERSION.' satisfies composer.json (checked by Composer).'
                : 'This server does not satisfy composer.json: '.implode(' ', $output),
        );
    }
}

// --- Required PHP extensions -------------------------------------------------
foreach (array_keys($require) as $package) {
    if (! is_string($package) || ! str_starts_with($package, 'ext-')) {
        continue;
    }
    $extension = substr($package, 4);
    $loaded = extension_loaded($extension);
    $report(
        $loaded,
        $loaded
            ? 'The '.$extension.' extension is installed.'
            : 'The '.$extension.' PHP extension is missing. Ask your host to enable '.$package.'.',
    );
}

exit($ok ? 0 : 1);
