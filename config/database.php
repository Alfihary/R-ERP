<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'host' => Env::get('APP_DB_HOST', '127.0.0.1'),
    'port' => (int) Env::get('APP_DB_PORT', '3306'),
    'name' => Env::get('APP_DB_NAME', ''),
    'username' => Env::get('APP_DB_USER', ''),
    'password' => Env::get('APP_DB_PASSWORD', ''),
    'charset' => Env::get('APP_DB_CHARSET', 'utf8mb4'),
    'collation' => Env::get(
        'APP_DB_COLLATION',
        'utf8mb4_unicode_ci'
    ),
];