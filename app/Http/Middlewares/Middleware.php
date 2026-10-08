<?php

declare(strict_types=1);

namespace App\Http\Middlewares;

use App\Core\Request;
use App\Core\Response;

interface Middleware
{
    /**
     * @param callable(Request): Response $next
     */
    public function process(Request $request, callable $next): Response;
}
