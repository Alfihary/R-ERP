<?php

declare(strict_types=1);

namespace App\Http\Middlewares;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\Auth\AuthService;
use App\Domain\Security\PermissionService;

final class PermissionMiddleware implements Middleware
{
    private readonly string $permissionCode;

    public function __construct(
        private readonly AuthService $auth,
        private readonly PermissionService $permissions,
        string $permissionCode
    ) {
        $this->permissionCode = PermissionService::normalizeCode($permissionCode);
    }

    public function process(Request $request, callable $next): Response
    {
        $user = $this->auth->user();

        if ($user === null) {
            return Response::redirect('/login');
        }

        if (!$this->permissions->allows($user['user_id'], $this->permissionCode)) {
            return Response::html(View::render('errors/403'), 403);
        }

        return $next($request);
    }
}
