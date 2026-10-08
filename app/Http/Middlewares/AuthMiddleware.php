<?php
declare(strict_types=1);

namespace App\Http\Middlewares;

use App\Core\Request;
use App\Core\Response;
use App\Domain\Auth\AuthService;

final class AuthMiddleware implements Middleware
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly bool $allowLocked = false,
        private readonly bool $trackActivity = true
    ) {}

    public function process(Request $request, callable $next): Response
    {
        if (!$this->auth->check()) { return Response::redirect('/login'); }
        if (!$this->allowLocked && $this->auth->isLocked()
            && !($request->method() === 'POST' && $request->path() === '/logout')) {
            return Response::redirect('/desbloquear');
        }
        if ($this->trackActivity) { $this->auth->touchSession(); }
        return $next($request);
    }
}
