<?php

declare(strict_types=1);

use App\Support\Security\CsrfTokenService;

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(CsrfTokenService $csrf): string
    {
        return '<input type="hidden" name="_token" value="' . e($csrf->token()) . '">';
    }
}
