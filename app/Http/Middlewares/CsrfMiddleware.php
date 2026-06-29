<?php

declare(strict_types=1);

namespace App\Http\Middlewares;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Support\Security\CsrfTokenService;

final class CsrfMiddleware implements Middleware
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private readonly CsrfTokenService $csrf)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        if (in_array($request->method(), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        $token = $request->input('_token');

        if (!is_string($token) || $token === '') {
            $token = $request->header('X-CSRF-Token');
        }

        if (!$this->csrf->validate($token)) {
            return Response::html(View::render('errors/419'), 419);
        }

        return $next($request);
    }
}
