<?php
declare(strict_types=1);

use App\Core\Env;

// Never derive the trusted origin from the Host header.
$production = strtolower((string) Env::get('APP_ENV', 'local')) === 'production';
return ['origin' => rtrim((string) Env::get('APP_WEBAUTHN_ORIGIN', $production ? '' : 'http://localhost:8000'), '/')];
