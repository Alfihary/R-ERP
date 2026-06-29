<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
header_remove('X-Powered-By');

$application = null;

try {
    $application = require BASE_PATH . '/bootstrap/app.php';
    $application->run(\App\Core\Request::capture())->send();
} catch (\Throwable $exception) {
    if ($application instanceof \App\Core\App) {
        $application->handleException($exception)->send();
        return;
    }

    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
    header('Permissions-Policy: camera=(), geolocation=(), microphone=()');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    echo '<h1>Error interno del servidor</h1>';
}
