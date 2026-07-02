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
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ClassificationController;
use App\Support\Security\CsrfTokenService;

return static function (
    Router $router,
    Config $config,
    AuthService $auth,
    PermissionService $permissions,
    ScopeContextService $scopeContext,
    CsrfTokenService $csrf,
    CatalogController $catalogController,
    ClassificationController $classificationController
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
        $scopeContext,
        $permissions
    ): Response {
        $user = $auth->user();
        $context = $scopeContext->resolveForUser((int) ($user['user_id'] ?? 0));
        $canAccessCatalogs = $permissions->allows(
            (int) ($user['user_id'] ?? 0),
            'catalogos.acceder'
        );

        return Response::html(View::render('layouts/app', [
            'activeNavigation' => 'home',
            'appName' => (string) $config->get('app.name', 'SoporteGR ERP'),
            'canAccessCatalogs' => $canAccessCatalogs,
            'contentData' => [
                'canAccessCatalogs' => $canAccessCatalogs,
            ],
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

    $catalogAccessMiddleware = new PermissionMiddleware(
        $auth,
        $permissions,
        'catalogos.acceder'
    );
    $catalogBaseMiddleware = [$authMiddleware, $catalogAccessMiddleware];

    $router->get(
        '/catalogos',
        static fn (Request $request): Response =>
            $catalogController->index($request),
        $catalogBaseMiddleware
    );

    foreach (
        [
            'monedas' => 'monedas',
            'unidades' => 'unidades',
            'impuestos' => 'impuestos',
            'lineas' => 'lineas',
            'marcas' => 'marcas',
        ] as $slug => $catalog
    ) {
        $permissionPrefix = 'catalogos.' . $catalog . '.';
        $viewMiddleware = array_merge($catalogBaseMiddleware, [
            new PermissionMiddleware(
                $auth,
                $permissions,
                $permissionPrefix . 'ver'
            ),
        ]);
        $createMiddleware = array_merge($catalogBaseMiddleware, [
            new PermissionMiddleware(
                $auth,
                $permissions,
                $permissionPrefix . 'crear'
            ),
        ]);
        $editMiddleware = array_merge($catalogBaseMiddleware, [
            new PermissionMiddleware(
                $auth,
                $permissions,
                $permissionPrefix . 'editar'
            ),
        ]);
        $stateMiddleware = array_merge($catalogBaseMiddleware, [
            new PermissionMiddleware(
                $auth,
                $permissions,
                $permissionPrefix . 'estado'
            ),
        ]);
        $basePath = '/catalogos/' . $slug;

        $router->get(
            $basePath,
            static fn (Request $request): Response =>
                $catalogController->show($request, $catalog),
            $viewMiddleware
        );
        $router->post(
            $basePath,
            static fn (Request $request): Response =>
                $catalogController->create($request, $catalog),
            $createMiddleware
        );
        $router->post(
            $basePath . '/actualizar',
            static fn (Request $request): Response =>
                $catalogController->update($request, $catalog),
            $editMiddleware
        );
        $router->post(
            $basePath . '/activar',
            static fn (Request $request): Response =>
                $catalogController->state($request, $catalog, true),
            $stateMiddleware
        );
        $router->post(
            $basePath . '/desactivar',
            static fn (Request $request): Response =>
                $catalogController->state($request, $catalog, false),
            $stateMiddleware
        );
    }

    $classificationPermissionPrefix = 'catalogos.clasificaciones.';
    $classificationBasePath = '/catalogos/clasificaciones';
    $classificationViewMiddleware = array_merge($catalogBaseMiddleware, [
        new PermissionMiddleware(
            $auth,
            $permissions,
            $classificationPermissionPrefix . 'ver'
        ),
    ]);
    $classificationCreateMiddleware = array_merge($catalogBaseMiddleware, [
        new PermissionMiddleware(
            $auth,
            $permissions,
            $classificationPermissionPrefix . 'crear'
        ),
    ]);
    $classificationEditMiddleware = array_merge($catalogBaseMiddleware, [
        new PermissionMiddleware(
            $auth,
            $permissions,
            $classificationPermissionPrefix . 'editar'
        ),
    ]);
    $classificationStateMiddleware = array_merge($catalogBaseMiddleware, [
        new PermissionMiddleware(
            $auth,
            $permissions,
            $classificationPermissionPrefix . 'estado'
        ),
    ]);

    $router->get(
        $classificationBasePath,
        static fn (Request $request): Response =>
            $classificationController->index($request),
        $classificationViewMiddleware
    );
    $router->post(
        $classificationBasePath,
        static fn (Request $request): Response =>
            $classificationController->create($request),
        $classificationCreateMiddleware
    );
    $router->post(
        $classificationBasePath . '/actualizar',
        static fn (Request $request): Response =>
            $classificationController->update($request),
        $classificationEditMiddleware
    );
    $router->post(
        $classificationBasePath . '/activar',
        static fn (Request $request): Response =>
            $classificationController->state($request, true),
        $classificationStateMiddleware
    );
    $router->post(
        $classificationBasePath . '/desactivar',
        static fn (Request $request): Response =>
            $classificationController->state($request, false),
        $classificationStateMiddleware
    );
};
