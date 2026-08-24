<?php

declare(strict_types=1);

/**
 * Router for `php -S` so the parity harness can boot the Laravel app with
 * environment-provided database settings (artisan serve isolates its child
 * process env, which breaks the harness's throwaway-DB wiring).
 */

$uri = urldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

// Serve real static assets (css/js/images) straight from public/.
$path = dirname(__DIR__, 2).'/public'.$uri;
if ($uri !== '/' && is_file($path)) {
    return false; // let the built-in server handle it
}

$_SERVER['SCRIPT_NAME'] = '/index.php';

require dirname(__DIR__, 2).'/public/index.php';
