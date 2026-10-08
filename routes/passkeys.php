<?php

declare(strict_types=1);

use App\Core\Request;
use App\Core\Router;
use App\Domain\Auth\AuthService;
use App\Domain\Security\PermissionService;
use App\Http\Controllers\PasskeyController;
use App\Http\Middlewares\AuthMiddleware;

return static function (
    Router $router,
    AuthService $auth,
    PermissionService $permissions,
    PasskeyController $controller
): void {
    $authOnly = [
        new AuthMiddleware($auth),
    ];

    // Estado de passkeys en login público
    $router->get('/auth/passkeys/estado', [$controller, 'status']);

    // Estado de passkeys dentro del perfil
    $router->get('/perfil/passkeys/estado', [$controller, 'manageStatus'], $authOnly);

    // Login con passkey
    $router->post(
        '/auth/passkeys/opciones',
        static fn (Request $request) => $controller->operation($request, 'login-options')
    );

    $router->post(
        '/auth/passkeys/verificar',
        static fn (Request $request) => $controller->operation($request, 'login-verify')
    );

    // Administración de passkeys del usuario autenticado
    $router->post(
        '/perfil/passkeys/opciones',
        static fn (Request $request) => $controller->operation($request, 'register-options'),
        $authOnly
    );

    $router->post(
        '/perfil/passkeys/registrar',
        static fn (Request $request) => $controller->operation($request, 'register-verify'),
        $authOnly
    );

    $router->post(
        '/perfil/passkeys/revocar',
        static fn (Request $request) => $controller->operation($request, 'revoke'),
        $authOnly
    );
};