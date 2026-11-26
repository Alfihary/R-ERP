<?php

declare(strict_types=1);

use App\Core\Env;

$common = [
    'host' => Env::get('APP_DB_HOST', '127.0.0.1'),
    'port' => (int) Env::get('APP_DB_PORT', '3306'),
    'database' => Env::get('APP_DB_NAME', ''),
    'charset' => Env::get('APP_DB_CHARSET', 'utf8mb4'),
    'collation' => Env::get('APP_DB_COLLATION', 'utf8mb4_unicode_ci'),
];

$readUser = trim((string) Env::get('APP_DB_READ_USER', ''));
if ($readUser === '') {
    $readUser = trim((string) Env::get('APP_DB_USER', ''));
}

$readPassword = Env::get('APP_DB_READ_PASSWORD');
if ($readPassword === null || $readPassword === '') {
    $readPassword = Env::get('APP_DB_PASSWORD', '');
}

return [
    'common' => $common,
    'read' => [
        'user' => $readUser,
        'password' => $readPassword,
    ],
    'write' => [
        'user' => trim((string) Env::get('APP_DB_WRITE_USER', '')),
        'password' => Env::get('APP_DB_WRITE_PASSWORD', ''),
    ],

    // Legacy consumers remain on the existing flat configuration during RW-1.
    'host' => $common['host'],
    'port' => $common['port'],
    'name' => $common['database'],
    'username' => Env::get('APP_DB_USER', ''),
    'password' => Env::get('APP_DB_PASSWORD', ''),
    'charset' => $common['charset'],
    'collation' => $common['collation'],
];
