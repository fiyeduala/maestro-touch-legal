<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Where the application lives. Normally one folder up. When the host only serves the main domain from
// public_html, the contents of public/ are copied there and a small app-path.php next to this file returns
// the application folder, e.g. <?php return '/home/USER/mtl_app'; (docs/DEPLOYMENT.md, layout B).
$base = is_file(__DIR__.'/app-path.php') ? rtrim((string) require __DIR__.'/app-path.php', '/\\') : __DIR__.'/..';

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = $base.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require $base.'/vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once $base.'/bootstrap/app.php';

if ($base !== __DIR__.'/..') {
    $app->usePublicPath(__DIR__);
}

$app->handleRequest(Request::capture());
