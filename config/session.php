<?php

declare(strict_types=1);

use App\Core\Env;

$environment = strtolower((string) Env::get('APP_ENV', 'local'));
$appUrl = (string) Env::get('APP_URL', 'http://localhost:8000');
$secureDefault = $environment === 'production' || str_starts_with($appUrl, 'https://');
$gcMaxLifetime = (int) Env::get('APP_SESSION_GC_MAX_LIFETIME', '7200');

return [
    'name' => Env::get('APP_SESSION_NAME', 'soportegr_session'),
    'secure' => $secureDefault || Env::bool('APP_SESSION_SECURE_COOKIE', false),
    'same_site' => Env::get('APP_SESSION_SAME_SITE', 'Lax'),
    'gc_max_lifetime' => max(300, min($gcMaxLifetime, 86400)),
];
