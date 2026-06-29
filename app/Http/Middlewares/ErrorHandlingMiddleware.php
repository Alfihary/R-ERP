<?php

declare(strict_types=1);

namespace App\Http\Middlewares;

use App\Core\ErrorHandler;
use App\Core\Request;
use App\Core\Response;

final class ErrorHandlingMiddleware implements Middleware
{
    public function __construct(private readonly ErrorHandler $errorHandler)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        try {
            return $next($request);
        } catch (\Throwable $exception) {
            return $this->errorHandler->render($exception);
        }
    }
}
