<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

$application = null;

try {
    $application = require BASE_PATH . '/bootstrap/app.php';
    $application->run(\App\Core\Request::capture())->send();
} catch (\Throwable $exception) {
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');

    if ($application instanceof \App\Core\App && $application->isDebug()) {
        echo '<h1>Error de aplicación</h1><pre>';
        echo htmlspecialchars((string) $exception, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo '</pre>';
        return;
    }

    echo '<h1>Error interno del servidor</h1>';
}
