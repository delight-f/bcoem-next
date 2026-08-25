<?php

// Static-aware router for demo servers: serve real files from public/
// directly, everything else through Laravel front controller.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__.'/../../public'.$path;
if ($path !== '/' && is_file($file)) {
    return false; // let PHP's built-in server stream it
}
require __DIR__.'/../../public/index.php';
