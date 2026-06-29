<?php

declare(strict_types=1);

namespace App\Http\Middlewares;

use App\Core\Request;
use App\Core\Response;
use App\Domain\Auth\AuthService;

final class AuthMiddleware implements Middleware
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login');
        }

        return $next($request);
    }
}
