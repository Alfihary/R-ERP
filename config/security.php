<?php

declare(strict_types=1);

use App\Core\Env;

$csrfTtl = (int) Env::get('APP_CSRF_TTL_SECONDS', '7200');

return [
    'csrf_ttl_seconds' => max(300, min($csrfTtl, 86400)),
];
