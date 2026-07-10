<?php

use Illuminate\Http\Request;

// Laravel 11's bundled default config (vendor/laravel/framework/config/*.php)
// still references PDO::MYSQL_ATTR_SSL_CA, deprecated by PHP 8.5. Silencing
// E_DEPRECATED keeps that framework-internal notice from being echoed as HTML
// before headers are sent (which was corrupting every JSON/API response).
error_reporting(E_ALL & ~E_DEPRECATED);

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
(require_once __DIR__.'/../bootstrap/app.php')
    ->handleRequest(Request::capture());
