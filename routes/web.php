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
use App\Http\Controllers\ExchangeRateController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SatCatalogController;
use App\Support\Security\CsrfTokenService;

return static function (
    Router $router,
    Config $config,
    AuthService $auth,
    PermissionService $permissions,
    ScopeContextService $scopeContext,
    CsrfTokenService $csrf,
    CatalogController $catalogController,
    ClassificationController $classificationController,
    ExchangeRateController $exchangeRateController,
    SatCatalogController $satCatalogController,
    ProductController $productController,
    InventoryController $inventoryController
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
        $canAccessProducts = $permissions->allows(
            (int) ($user['user_id'] ?? 0),
            'productos.acceder'
        );
        $canAccessInventory = $permissions->allows(
            (int) ($user['user_id'] ?? 0),
            'inventario.movimientos.acceder'
        );

        return Response::html(View::render('layouts/app', [
            'activeNavigation' => 'home',
            'appName' => (string) $config->get('app.name', 'SoporteGR ERP'),
            'canAccessCatalogs' => $canAccessCatalogs,
            'canAccessProducts' => $canAccessProducts,
            'canAccessInventory' => $canAccessInventory,
            'contentData' => [
                'canAccessCatalogs' => $canAccessCatalogs,
                'canAccessProducts' => $canAccessProducts,
                'canAccessInventory' => $canAccessInventory,
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

    $exchangeRatePermissionPrefix = 'catalogos.tipos_cambio.';
    $exchangeRateBasePath = '/catalogos/tipos-cambio';
    $exchangeRateViewMiddleware = array_merge($catalogBaseMiddleware, [
        new PermissionMiddleware(
            $auth,
            $permissions,
            $exchangeRatePermissionPrefix . 'ver'
        ),
    ]);
    $exchangeRateCreateMiddleware = array_merge($catalogBaseMiddleware, [
        new PermissionMiddleware(
            $auth,
            $permissions,
            $exchangeRatePermissionPrefix . 'crear'
        ),
    ]);
    $exchangeRateEditMiddleware = array_merge($catalogBaseMiddleware, [
        new PermissionMiddleware(
            $auth,
            $permissions,
            $exchangeRatePermissionPrefix . 'editar'
        ),
    ]);
    $exchangeRateStateMiddleware = array_merge($catalogBaseMiddleware, [
        new PermissionMiddleware(
            $auth,
            $permissions,
            $exchangeRatePermissionPrefix . 'estado'
        ),
    ]);

    $router->get(
        $exchangeRateBasePath,
        static fn (Request $request): Response =>
            $exchangeRateController->index($request),
        $exchangeRateViewMiddleware
    );
    $router->post(
        $exchangeRateBasePath,
        static fn (Request $request): Response =>
            $exchangeRateController->create($request),
        $exchangeRateCreateMiddleware
    );
    $router->post(
        $exchangeRateBasePath . '/actualizar',
        static fn (Request $request): Response =>
            $exchangeRateController->update($request),
        $exchangeRateEditMiddleware
    );
    $router->post(
        $exchangeRateBasePath . '/activar',
        static fn (Request $request): Response =>
            $exchangeRateController->state($request, true),
        $exchangeRateStateMiddleware
    );
    $router->post(
        $exchangeRateBasePath . '/desactivar',
        static fn (Request $request): Response =>
            $exchangeRateController->state($request, false),
        $exchangeRateStateMiddleware
    );

    foreach (
        [
            'unidades_sat' => [
                'base' => '/catalogos/unidades-sat',
                'index' => 'unitsIndex',
                'createForm' => 'unitsCreateForm',
                'create' => 'unitsCreate',
                'editForm' => 'unitsEditForm',
                'update' => 'unitsUpdate',
                'activate' => 'unitsActivate',
                'deactivate' => 'unitsDeactivate',
            ],
            'claves_sat' => [
                'base' => '/catalogos/claves-sat',
                'index' => 'keysIndex',
                'createForm' => 'keysCreateForm',
                'create' => 'keysCreate',
                'editForm' => 'keysEditForm',
                'update' => 'keysUpdate',
                'activate' => 'keysActivate',
                'deactivate' => 'keysDeactivate',
            ],
        ] as $permission => $satRoutes
    ) {
        $permissionPrefix = 'catalogos.' . $permission . '.';
        $satViewMiddleware = array_merge($catalogBaseMiddleware, [
            new PermissionMiddleware(
                $auth,
                $permissions,
                $permissionPrefix . 'acceder'
            ),
        ]);
        $satCreateMiddleware = array_merge($catalogBaseMiddleware, [
            new PermissionMiddleware(
                $auth,
                $permissions,
                $permissionPrefix . 'crear'
            ),
        ]);
        $satEditMiddleware = array_merge($catalogBaseMiddleware, [
            new PermissionMiddleware(
                $auth,
                $permissions,
                $permissionPrefix . 'editar'
            ),
        ]);
        $satStateMiddleware = array_merge($catalogBaseMiddleware, [
            new PermissionMiddleware(
                $auth,
                $permissions,
                $permissionPrefix . 'estado'
            ),
        ]);
        $basePath = $satRoutes['base'];

        if ($permission === 'claves_sat') {
            $router->get(
                $basePath . '/buscar',
                static fn (Request $request): Response =>
                    $satCatalogController->keysSearch($request),
                $satViewMiddleware
            );
        }

        $router->get(
            $basePath,
            static fn (Request $request): Response =>
                $satCatalogController->{$satRoutes['index']}($request),
            $satViewMiddleware
        );
        $router->get(
            $basePath . '/crear',
            static fn (Request $request): Response =>
                $satCatalogController->{$satRoutes['createForm']}($request),
            $satCreateMiddleware
        );
        $router->post(
            $basePath,
            static fn (Request $request): Response =>
                $satCatalogController->{$satRoutes['create']}($request),
            $satCreateMiddleware
        );
        $router->get(
            $basePath . '/editar',
            static fn (Request $request): Response =>
                $satCatalogController->{$satRoutes['editForm']}($request),
            $satEditMiddleware
        );
        $router->post(
            $basePath . '/actualizar',
            static fn (Request $request): Response =>
                $satCatalogController->{$satRoutes['update']}($request),
            $satEditMiddleware
        );
        $router->post(
            $basePath . '/activar',
            static fn (Request $request): Response =>
                $satCatalogController->{$satRoutes['activate']}($request),
            $satStateMiddleware
        );
        $router->post(
            $basePath . '/desactivar',
            static fn (Request $request): Response =>
                $satCatalogController->{$satRoutes['deactivate']}($request),
            $satStateMiddleware
        );
    }

    $productBaseMiddleware = [
        $authMiddleware,
        new PermissionMiddleware(
            $auth,
            $permissions,
            'productos.acceder'
        ),
    ];
    $productMiddleware = static function (string $permission) use (
        $productBaseMiddleware,
        $auth,
        $permissions
    ): array {
        return array_merge($productBaseMiddleware, [
            new PermissionMiddleware($auth, $permissions, $permission),
        ]);
    };

    $router->get(
        '/productos',
        static fn (Request $request): Response =>
            $productController->index($request),
        $productBaseMiddleware
    );
    $router->get(
        '/productos/crear',
        static fn (Request $request): Response =>
            $productController->createForm($request),
        $productMiddleware('productos.crear')
    );
    $router->post(
        '/productos',
        static fn (Request $request): Response =>
            $productController->create($request),
        $productMiddleware('productos.crear')
    );
    $router->get(
        '/productos/ver',
        static fn (Request $request): Response =>
            $productController->detail($request),
        $productMiddleware('productos.ver')
    );
    $router->get(
        '/productos/imagen',
        static fn (Request $request): Response =>
            $productController->image($request),
        $productMiddleware('productos.ver')
    );
    $router->get(
        '/productos/editar',
        static fn (Request $request): Response =>
            $productController->editForm($request),
        $productMiddleware('productos.editar')
    );
    $router->post(
        '/productos/actualizar',
        static fn (Request $request): Response =>
            $productController->update($request),
        $productMiddleware('productos.editar')
    );
    $router->post(
        '/productos/imagen/subir',
        static fn (Request $request): Response =>
            $productController->uploadImage($request),
        $productMiddleware('productos.editar')
    );
    $router->post(
        '/productos/imagen/eliminar',
        static fn (Request $request): Response =>
            $productController->deleteImage($request),
        $productMiddleware('productos.editar')
    );
    $router->post(
        '/productos/activar',
        static fn (Request $request): Response =>
            $productController->state($request, true),
        $productMiddleware('productos.estado')
    );
    $router->post(
        '/productos/desactivar',
        static fn (Request $request): Response =>
            $productController->state($request, false),
        $productMiddleware('productos.estado')
    );

    $inventoryBaseMiddleware = [
        $authMiddleware,
        new PermissionMiddleware(
            $auth,
            $permissions,
            'inventario.movimientos.acceder'
        ),
    ];
    $inventoryMiddleware = static function (string $permission) use (
        $inventoryBaseMiddleware,
        $auth,
        $permissions
    ): array {
        return array_merge($inventoryBaseMiddleware, [
            new PermissionMiddleware($auth, $permissions, $permission),
        ]);
    };

    $router->get(
        '/inventario/movimientos',
        static fn (Request $request): Response =>
            $inventoryController->index($request),
        $inventoryBaseMiddleware
    );
    $router->get(
        '/inventario/existencias',
        static fn (Request $request): Response =>
            $inventoryController->stock($request),
        [
            $authMiddleware,
            new PermissionMiddleware(
                $auth,
                $permissions,
                'inventario.existencias.acceder'
            ),
        ]
    );
    $router->get(
        '/inventario/movimientos/crear',
        static fn (Request $request): Response =>
            $inventoryController->createForm($request),
        $inventoryMiddleware('inventario.movimientos.crear')
    );
    $router->post(
        '/inventario/movimientos',
        static fn (Request $request): Response =>
            $inventoryController->create($request),
        $inventoryMiddleware('inventario.movimientos.crear')
    );
    $router->get(
        '/inventario/movimientos/ver',
        static fn (Request $request): Response =>
            $inventoryController->detail($request),
        $inventoryMiddleware('inventario.movimientos.ver')
    );
    $router->get(
        '/inventario/productos/buscar',
        static fn (Request $request): Response =>
            $inventoryController->searchProducts($request),
        $inventoryMiddleware('inventario.movimientos.crear')
    );
};
