<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Domain\Auth\AuthService;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContextService;
use App\Http\Middlewares\AuthMiddleware;
use App\Http\Middlewares\PermissionMiddleware;
use App\Support\Security\CsrfTokenService;

return static function (
    Router $router,
    Config $config,
    AuthService $auth,
    PermissionService $permissions,
    ScopeContextService $scopeContext,
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
        $scopeContext
    ): Response {
        $user = $auth->user();
        $context = $scopeContext->resolveForUser((int) ($user['user_id'] ?? 0));

        return Response::html(View::render('layouts/app', [
            'appName' => (string) $config->get('app.name', 'SoporteGR ERP'),
            'context' => $context->toArray(),
            'contentView' => 'auth/private',
            'csrf' => $csrf,
            'pageTitle' => 'Inicio',
            'user' => $user,
        ]));
    }, [$authMiddleware, $appPermissionMiddleware]);

    $router->post('/app/contexto', static function (Request $request) use (
        $auth,
        $scopeContext
    ): Response {
        $user = $auth->user();
        $companyId = filter_var(
            $request->input('active_company_id'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $warehouseId = filter_var(
            $request->input('active_warehouse_id'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($companyId !== false && $warehouseId !== false) {
            $scopeContext->changeForUser(
                (int) ($user['user_id'] ?? 0),
                $companyId,
                $warehouseId
            );
        }

        return Response::redirect('/app');
    }, [$authMiddleware, $appPermissionMiddleware]);

    $router->post('/logout', static function (Request $request) use ($auth): Response {
        $auth->logout();

        return Response::redirect('/login');
    }, [$authMiddleware]);
};
