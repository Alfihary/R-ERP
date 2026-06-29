<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Domain\Auth\AuthService;
use App\Domain\Security\PermissionService;
use App\Http\Middlewares\AuthMiddleware;
use App\Http\Middlewares\PermissionMiddleware;
use App\Support\Security\CsrfTokenService;

return static function (
    Router $router,
    Config $config,
    AuthService $auth,
    PermissionService $permissions,
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

    $router->get('/app', static function (Request $request) use ($auth, $csrf): Response {
        return Response::html(View::render('auth/private', [
            'csrf' => $csrf,
            'user' => $auth->user(),
        ]));
    }, [$authMiddleware, $appPermissionMiddleware]);

    $router->post('/logout', static function (Request $request) use ($auth): Response {
        $auth->logout();

        return Response::redirect('/login');
    }, [$authMiddleware]);
};
