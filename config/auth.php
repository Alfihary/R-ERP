<?php

declare(strict_types=1);

use App\Core\Env;

return [
    'initial_admin' => [
        'username' => Env::get('APP_INITIAL_ADMIN_USERNAME', 'jesus.g'),
        'email' => Env::get('APP_INITIAL_ADMIN_EMAIL', ''),
        'password' => Env::get('APP_INITIAL_ADMIN_PASSWORD', ''),
    ],
];
