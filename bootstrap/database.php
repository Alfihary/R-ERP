<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Env;

if (!defined('BASE_PATH')) {
    throw new RuntimeException('BASE_PATH must be defined before bootstrapping the database.');
}

require BASE_PATH . '/bootstrap/autoload.php';

Env::load(BASE_PATH . '/.env');

$paths = require BASE_PATH . '/config/paths.php';

foreach ($paths as $constant => $path) {
    if (!defined($constant)) {
        define($constant, $path);
    }
}

return new Config([
    'app' => require CONFIG_PATH . '/app.php',
    'auth' => require CONFIG_PATH . '/auth.php',
    'database' => require CONFIG_PATH . '/database.php',
    'paths' => $paths,
]);
