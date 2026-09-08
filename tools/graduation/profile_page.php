<?php

declare(strict_types=1);

/**
 * profile_page.php — hotspot profiler for perf_smoke.sh misses.
 *
 * Boots the Laravel app in-process against the harness's throwaway schema
 * (DB_* come from the environment), renders ONE page with the query log
 * enabled, and reports wall time plus per-query breakdown so a p95 miss can
 * name its hotspot (slow query or N+1) instead of just failing.
 *
 * Usage: php tools/graduation/profile_page.php <uri> [<cookie header>]
 *   uri    e.g. /admin/judging/scores
 *   cookie optional HTTP_COOKIE value ("laravel_session=...; XSRF-TOKEN=...")
 */

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

$uri = $argv[1] ?? null;
$cookie = $argv[2] ?? '';
if ($uri === null) {
    fwrite(STDERR, "usage: php profile_page.php <uri> [cookie-header]\n");
    exit(2);
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);

$server = ['HTTP_HOST' => '127.0.0.1:8094'];
if ($cookie !== '') {
    $server['HTTP_COOKIE'] = $cookie;
}

// Run the kernel's bootstrappers up front so the db binding exists, then
// switch on the query log BEFORE the page renders.
$request = Request::create('/', 'GET');
$bootstrap = new ReflectionMethod($kernel, 'bootstrap');
$bootstrap->invoke($kernel, $request);
$app->make('db')->connection()->enableQueryLog();

$request = Request::create($uri, 'GET', [], [], [], $server);
$t0 = hrtime(true) / 1e6;
$response = $kernel->handle($request);
$wallMs = hrtime(true) / 1e6 - $t0;

$log = $app->make('db')->connection()->getQueryLog();
$queryMs = 0.0;
foreach ($log as $q) {
    $queryMs += (float) $q['time'];
}
usort($log, fn ($a, $b) => $b['time'] <=> $a['time']);

// N+1 hint: identical SQL (bindings normalized out) issued many times.
$groups = [];
foreach ($log as $q) {
    $sql = preg_replace('/\?/', '?', (string) $q['query']);
    $groups[$sql] = ($groups[$sql] ?? 0) + 1;
}
arsort($groups);
$nplus1 = array_filter($groups, fn ($n) => $n >= 10, ARRAY_FILTER_USE_BOTH);

printf("PROFILE %s\n", $uri);
printf("status: %d | wall: %.0fms | queries: %d | query time: %.0fms (%.0f%% of wall)\n",
    $response->getStatusCode(), $wallMs, count($log), $queryMs,
    $wallMs > 0 ? 100.0 * $queryMs / $wallMs : 0.0);
if ($log !== []) {
    echo "slowest queries:\n";
    foreach (array_slice($log, 0, 5) as $q) {
        printf("  %6.1fms  %s\n", (float) $q['time'], $q['query']);
    }
}
if ($nplus1 !== []) {
    echo "N+1 suspects (same SQL >=10x):\n";
    foreach (array_slice($nplus1, 0, 5, true) as $sql => $count) {
        printf("  %4dx  %s\n", $count, $sql);
    }
}
