<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) {
    throw new RuntimeException('BASE_PATH must be defined before registering the autoloader.');
}

$composerAutoload = BASE_PATH . '/vendor/autoload.php';
$composerAutoloadAvailable = is_file($composerAutoload);

if (!defined('COMPOSER_AUTOLOAD_AVAILABLE')) {
    define('COMPOSER_AUTOLOAD_AVAILABLE', $composerAutoloadAvailable);
}

if ($composerAutoloadAvailable) {
    require_once $composerAutoload;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
