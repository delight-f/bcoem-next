<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/app-data/storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/app-data/vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/app-data/bootstrap/app.php';

// This layout keeps the application in app-data/ while the document root is
// this directory, so public_path() must be pointed here explicitly. Without it
// public_path() resolves to app-data/public (which does not exist), breaking
// user image uploads and the compiled asset manifest.
$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
