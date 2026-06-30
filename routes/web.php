<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Domain\Auth\AuthService;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\UserScopeService;
use App\Http\Middlewares\AuthMiddleware;
use App\Http\Middlewares\PermissionMiddleware;
use App\Support\Security\CsrfTokenService;

return static function (
    Router $router,
    Config $config,
    AuthService $auth,
    PermissionService $permissions,
    UserScopeService $userScope,
    CsrfTokenService $csrf
): void {
    $router->get('/', static function (Request $request) use ($config): Response {
        return Response::html(View::render('welcome', [
            'appName' => (string) $config->get('app.name', 'SoporteGR ERP'),
        ]));
    });

    $router->get('/health', static function (Request $request): Response {
        return Response::json(['status' => 'ok']);
    });

    $router->get('/login', static function (Request $request) use ($auth, $csrf): Response {
        if ($auth->check()) {
            return Response::redirect('/app');
        }

        return Response::html(View::render('auth/login', [
            'csrf' => $csrf,
            'error' => null,
        ]));
    });

    $router->post('/login', static function (Request $request) use ($auth, $csrf): Response {
        $login = $request->input('login');
        $password = $request->input('password');

        if (is_string($login)
            && is_string($password)
            && $auth->attempt($login, $password)
        ) {
            return Response::redirect('/app');
        }

        return Response::html(View::render('auth/login', [
            'csrf' => $csrf,
            'error' => 'Credenciales inválidas.',
        ]), 422);
    });

    $authMiddleware = new AuthMiddleware($auth);
    $appPermissionMiddleware = new PermissionMiddleware(
        $auth,
        $permissions,
        'sistema.app.ver'
    );

    $router->get('/app', static function (Request $request) use (
        $auth,
        $config,
        $csrf,
        $userScope
    ): Response {
        $user = $auth->user();
        $scope = $userScope->resolveForUser((int) ($user['user_id'] ?? 0));

        return Response::html(View::render('layouts/app', [
            'appName' => (string) $config->get('app.name', 'SoporteGR ERP'),
            'contentView' => 'auth/private',
            'csrf' => $csrf,
            'pageTitle' => 'Inicio',
            'scope' => $scope->toArray(),
            'user' => $user,
        ]));
    }, [$authMiddleware, $appPermissionMiddleware]);

    $router->post('/logout', static function (Request $request) use ($auth): Response {
        $auth->logout();

        return Response::redirect('/login');
    }, [$authMiddleware]);
};
