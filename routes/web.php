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
use App\Http\Controllers\AuditController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ClassificationController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CredentialController;
use App\Http\Controllers\ExchangeRateController;
use App\Http\Controllers\FolioSeriesController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InventoryTransferController;
use App\Http\Controllers\PriceListController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProductPriceController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PublicCredentialController;
use App\Http\Controllers\PublicVcardController;
use App\Http\Controllers\SatCatalogController;
use App\Http\Controllers\WarehouseController;
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
    CompanyController $companyController,
    WarehouseController $warehouseController,
    FolioSeriesController $folioSeriesController,
    ProfileController $profileController,
    CredentialController $credentialController,
    PublicVcardController $publicVcardController,
    PublicCredentialController $publicCredentialController,
    ProductController $productController,
    PriceListController $priceListController,
    ProductPriceController $productPriceController,
    InventoryController $inventoryController,
    InventoryTransferController $inventoryTransferController,
    AuditController $auditController
): void {
    $router->get('/', static function (Request $request) use ($config): Response {
        return Response::html(View::render('welcome', [
            'appName' => (string) $config->get('app.name', 'SoporteGR ERP'),
        ]));
    });

    $router->get('/health', static function (Request $request): Response {
        return Response::json(['status' => 'ok']);
    });

    $router->get(
        '/v/{slug}',
        static fn (Request $request, array $params): Response =>
            $publicVcardController->show($request, $params)
    );
    $router->get(
        '/v/{slug}/foto',
        static fn (Request $request, array $params): Response =>
            $publicVcardController->photo($request, $params)
    );
    $router->get(
        '/v/{slug}/' . 'vcf',
        static fn (Request $request, array $params): Response =>
            $publicVcardController->vcf($request, $params)
    );
    $router->get(
        '/v/{slug}/' . 'qr',
        static fn (Request $request, array $params): Response =>
            $publicVcardController->qr($request, $params)
    );
    $router->get(
        '/credencial/' . 'verificar/{token}',
        static fn (Request $request, array $params): Response =>
            $publicCredentialController->verify($request, $params)
    );

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
        $canAccessProductPrices = $permissions->allows(
            (int) ($user['user_id'] ?? 0),
            'precios.productos.acceder'
        );
        $canAccessProfile = $permissions->allows(
            (int) ($user['user_id'] ?? 0),
            'perfil.ver'
        );
        $canAccessCredential = $permissions->allows(
            (int) ($user['user_id'] ?? 0),
            'credencial.ver'
        );
        $canAccessInventory = $permissions->allows(
            (int) ($user['user_id'] ?? 0),
            'inventario.movimientos.acceder'
        );
        $canAccessInventoryTransfers = $permissions->allows(
            (int) ($user['user_id'] ?? 0),
            'inventario.transferencias.acceder'
        );
        $canAccessConfiguration = $permissions->allows(
            (int) ($user['user_id'] ?? 0),
            'configuracion.empresas.acceder'
        ) || $permissions->allows(
            (int) ($user['user_id'] ?? 0),
            'configuracion.almacenes.acceder'
        ) || $permissions->allows(
            (int) ($user['user_id'] ?? 0),
            'configuracion.folios.acceder'
        ) || $permissions->allows(
            (int) ($user['user_id'] ?? 0),
            'precios.listas.acceder'
        ) || $permissions->allows(
            (int) ($user['user_id'] ?? 0),
            AuditController::PERMISSION
        );

        return Response::html(View::render('layouts/app', [
            'activeNavigation' => 'home',
            'appName' => (string) $config->get('app.name', 'SoporteGR ERP'),
            'canAccessCatalogs' => $canAccessCatalogs,
            'canAccessProfile' => $canAccessProfile,
            'canAccessCredential' => $canAccessCredential,
            'canAccessProducts' => $canAccessProducts,
            'canAccessProductPrices' => $canAccessProductPrices,
            'canAccessInventory' => $canAccessInventory,
            'canAccessInventoryTransfers' => $canAccessInventoryTransfers,
            'canAccessConfiguration' => $canAccessConfiguration,
            'canAccessConfigCompanies' => $permissions->allows(
                (int) ($user['user_id'] ?? 0),
                'configuracion.empresas.acceder'
            ),
            'canAccessConfigWarehouses' => $permissions->allows(
                (int) ($user['user_id'] ?? 0),
                'configuracion.almacenes.acceder'
            ),
            'canAccessConfigFolios' => $permissions->allows(
                (int) ($user['user_id'] ?? 0),
                'configuracion.folios.acceder'
            ),
            'canAccessPriceLists' => $permissions->allows(
                (int) ($user['user_id'] ?? 0),
                'precios.listas.acceder'
            ),
            'canAccessAudit' => $permissions->allows(
                (int) ($user['user_id'] ?? 0),
                AuditController::PERMISSION
            ),
            'contentData' => [
                'canAccessCatalogs' => $canAccessCatalogs,
                'canAccessProducts' => $canAccessProducts,
                'canAccessInventory' => $canAccessInventory,
                'canAccessInventoryTransfers' => $canAccessInventoryTransfers,
            ],
            'context' => $context->toArray(),
            'contentView' => 'auth/private',
            'csrf' => $csrf,
            'pageTitle' => 'Inicio',
            'user' => $user,
        ]));
    }, [$authMiddleware, $appPermissionMiddleware]);

    $profileBaseMiddleware = [
        $authMiddleware,
        new PermissionMiddleware($auth, $permissions, 'perfil.ver'),
    ];
    $profileMiddleware = static function (string $permission) use (
        $profileBaseMiddleware,
        $auth,
        $permissions
    ): array {
        return array_merge($profileBaseMiddleware, [
            new PermissionMiddleware($auth, $permissions, $permission),
        ]);
    };

    $router->get(
        '/perfil',
        static fn (Request $request): Response =>
            $profileController->index($request),
        $profileBaseMiddleware
    );
    $router->post(
        '/perfil/actualizar',
        static fn (Request $request): Response =>
            $profileController->update($request),
        $profileMiddleware('perfil.editar')
    );
    $router->get(
        '/perfil/password',
        static fn (Request $request): Response =>
            $profileController->passwordForm($request),
        $profileMiddleware('perfil.password.cambiar')
    );
    $router->post(
        '/perfil/password',
        static fn (Request $request): Response =>
            $profileController->updatePassword($request),
        $profileMiddleware('perfil.password.cambiar')
    );
    $router->post(
        '/perfil/foto',
        static fn (Request $request): Response =>
            $profileController->uploadPhoto($request),
        $profileMiddleware('perfil.foto.actualizar')
    );
    $router->post(
        '/perfil/foto/eliminar',
        static fn (Request $request): Response =>
            $profileController->deletePhoto($request),
        $profileMiddleware('perfil.foto.eliminar')
    );
    $router->post(
        '/perfil/vcard/configuracion',
        static fn (Request $request): Response =>
            $profileController->updateVcard($request),
        $profileMiddleware('vcard.editar')
    );
    $router->post(
        '/perfil/vcard/privacidad',
        static fn (Request $request): Response =>
            $profileController->updateVcardPrivacy($request),
        $profileMiddleware('vcard.privacidad.editar')
    );
    $router->post(
        '/perfil/vcard/publicar',
        static fn (Request $request): Response =>
            $profileController->publishVcard($request),
        $profileMiddleware('vcard.publicar')
    );
    $router->post(
        '/perfil/vcard/despublicar',
        static fn (Request $request): Response =>
            $profileController->unpublishVcard($request),
        $profileMiddleware('vcard.publicar')
    );
    $router->get(
        '/perfil/credencial',
        static fn (Request $request): Response =>
            $credentialController->show($request),
        [
            $authMiddleware,
            new PermissionMiddleware($auth, $permissions, 'credencial.ver'),
        ]
    );
    $router->get(
        '/perfil/credencial/foto',
        static fn (Request $request): Response =>
            $credentialController->photo($request),
        [
            $authMiddleware,
            new PermissionMiddleware($auth, $permissions, 'credencial.ver'),
        ]
    );
    $router->get(
        '/perfil/credencial/' . 'qr',
        static fn (Request $request): Response =>
            $credentialController->showQr($request),
        [
            $authMiddleware,
            new PermissionMiddleware($auth, $permissions, 'credencial.ver'),
            new PermissionMiddleware($auth, $permissions, 'credencial.qr.ver'),
        ]
    );
    $router->get(
        '/perfil/credencial/' . 'qr/descargar',
        static fn (Request $request): Response =>
            $credentialController->downloadQr($request),
        [
            $authMiddleware,
            new PermissionMiddleware($auth, $permissions, 'credencial.ver'),
            new PermissionMiddleware($auth, $permissions, 'credencial.qr.ver'),
            new PermissionMiddleware($auth, $permissions, 'credencial.qr.descargar'),
        ]
    );
    $router->post(
        '/perfil/credencial/token/renovar',
        static fn (Request $request): Response =>
            $credentialController->renewToken($request),
        [
            $authMiddleware,
            new PermissionMiddleware($auth, $permissions, 'credencial.ver'),
            new PermissionMiddleware($auth, $permissions, 'credencial.qr.ver'),
        ]
    );
    $router->post(
        '/perfil/credencial/token/revocar',
        static fn (Request $request): Response =>
            $credentialController->revokeToken($request),
        [
            $authMiddleware,
            new PermissionMiddleware($auth, $permissions, 'credencial.ver'),
            new PermissionMiddleware($auth, $permissions, 'credencial.qr.ver'),
        ]
    );

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

    $auditBaseMiddleware = [
        $authMiddleware,
        new PermissionMiddleware($auth, $permissions, AuditController::PERMISSION),
    ];

    $router->get(
        '/auditoria',
        static fn (Request $request): Response =>
            $auditController->index($request),
        $auditBaseMiddleware
    );

    $companyBaseMiddleware = [
        $authMiddleware,
        new PermissionMiddleware($auth, $permissions, 'configuracion.empresas.acceder'),
    ];
    $companyMiddleware = static function (string $permission) use (
        $companyBaseMiddleware,
        $auth,
        $permissions
    ): array {
        return array_merge($companyBaseMiddleware, [
            new PermissionMiddleware($auth, $permissions, $permission),
        ]);
    };

    $router->get(
        '/configuracion/empresas',
        static fn (Request $request): Response =>
            $companyController->index($request),
        $companyBaseMiddleware
    );
    $router->get(
        '/configuracion/empresas/crear',
        static fn (Request $request): Response =>
            $companyController->createForm($request),
        $companyMiddleware('configuracion.empresas.crear')
    );
    $router->post(
        '/configuracion/empresas',
        static fn (Request $request): Response =>
            $companyController->create($request),
        $companyMiddleware('configuracion.empresas.crear')
    );
    $router->get(
        '/configuracion/empresas/ver',
        static fn (Request $request): Response =>
            $companyController->show($request),
        $companyMiddleware('configuracion.empresas.ver')
    );
    $router->get(
        '/configuracion/empresas/editar',
        static fn (Request $request): Response =>
            $companyController->editForm($request),
        $companyMiddleware('configuracion.empresas.editar')
    );
    $router->post(
        '/configuracion/empresas/actualizar',
        static fn (Request $request): Response =>
            $companyController->update($request),
        $companyMiddleware('configuracion.empresas.editar')
    );
    $router->post(
        '/configuracion/empresas/desactivar',
        static fn (Request $request): Response =>
            $companyController->deactivate($request),
        $companyMiddleware('configuracion.empresas.desactivar')
    );
    $router->post(
        '/configuracion/empresas/activar',
        static fn (Request $request): Response =>
            $companyController->activate($request),
        $companyMiddleware('configuracion.empresas.desactivar')
    );

    $warehouseBaseMiddleware = [
        $authMiddleware,
        new PermissionMiddleware($auth, $permissions, 'configuracion.almacenes.acceder'),
    ];
    $warehouseMiddleware = static function (string $permission) use (
        $warehouseBaseMiddleware,
        $auth,
        $permissions
    ): array {
        return array_merge($warehouseBaseMiddleware, [
            new PermissionMiddleware($auth, $permissions, $permission),
        ]);
    };

    $router->get(
        '/configuracion/almacenes',
        static fn (Request $request): Response =>
            $warehouseController->index($request),
        $warehouseBaseMiddleware
    );
    $router->get(
        '/configuracion/almacenes/crear',
        static fn (Request $request): Response =>
            $warehouseController->createForm($request),
        $warehouseMiddleware('configuracion.almacenes.crear')
    );
    $router->post(
        '/configuracion/almacenes',
        static fn (Request $request): Response =>
            $warehouseController->create($request),
        $warehouseMiddleware('configuracion.almacenes.crear')
    );
    $router->get(
        '/configuracion/almacenes/ver',
        static fn (Request $request): Response =>
            $warehouseController->show($request),
        $warehouseMiddleware('configuracion.almacenes.ver')
    );
    $router->get(
        '/configuracion/almacenes/editar',
        static fn (Request $request): Response =>
            $warehouseController->editForm($request),
        $warehouseMiddleware('configuracion.almacenes.editar')
    );
    $router->post(
        '/configuracion/almacenes/actualizar',
        static fn (Request $request): Response =>
            $warehouseController->update($request),
        $warehouseMiddleware('configuracion.almacenes.editar')
    );
    $router->post(
        '/configuracion/almacenes/desactivar',
        static fn (Request $request): Response =>
            $warehouseController->deactivate($request),
        $warehouseMiddleware('configuracion.almacenes.desactivar')
    );
    $router->post(
        '/configuracion/almacenes/activar',
        static fn (Request $request): Response =>
            $warehouseController->activate($request),
        $warehouseMiddleware('configuracion.almacenes.desactivar')
    );

    $folioBaseMiddleware = [
        $authMiddleware,
        new PermissionMiddleware($auth, $permissions, 'configuracion.folios.acceder'),
    ];
    $folioMiddleware = static function (string $permission) use (
        $folioBaseMiddleware,
        $auth,
        $permissions
    ): array {
        return array_merge($folioBaseMiddleware, [
            new PermissionMiddleware($auth, $permissions, $permission),
        ]);
    };

    $router->get(
        '/configuracion/folios',
        static fn (Request $request): Response =>
            $folioSeriesController->index($request),
        $folioBaseMiddleware
    );
    $router->get(
        '/configuracion/folios/crear',
        static fn (Request $request): Response =>
            $folioSeriesController->createForm($request),
        $folioMiddleware('configuracion.folios.crear')
    );
    $router->post(
        '/configuracion/folios',
        static fn (Request $request): Response =>
            $folioSeriesController->create($request),
        $folioMiddleware('configuracion.folios.crear')
    );
    $router->get(
        '/configuracion/folios/ver',
        static fn (Request $request): Response =>
            $folioSeriesController->show($request),
        $folioMiddleware('configuracion.folios.ver')
    );
    $router->get(
        '/configuracion/folios/editar',
        static fn (Request $request): Response =>
            $folioSeriesController->editForm($request),
        $folioMiddleware('configuracion.folios.editar')
    );
    $router->post(
        '/configuracion/folios/actualizar',
        static fn (Request $request): Response =>
            $folioSeriesController->update($request),
        $folioMiddleware('configuracion.folios.editar')
    );
    $router->post(
        '/configuracion/folios/desactivar',
        static fn (Request $request): Response =>
            $folioSeriesController->deactivate($request),
        $folioMiddleware('configuracion.folios.desactivar')
    );
    $router->post(
        '/configuracion/folios/activar',
        static fn (Request $request): Response =>
            $folioSeriesController->activate($request),
        $folioMiddleware('configuracion.folios.desactivar')
    );

    $priceListBaseMiddleware = [
        $authMiddleware,
        new PermissionMiddleware($auth, $permissions, 'precios.listas.acceder'),
    ];
    $priceListMiddleware = static function (string $permission) use (
        $priceListBaseMiddleware,
        $auth,
        $permissions
    ): array {
        return array_merge($priceListBaseMiddleware, [
            new PermissionMiddleware($auth, $permissions, $permission),
        ]);
    };

    $router->get(
        '/configuracion/listas-precios',
        static fn (Request $request): Response =>
            $priceListController->index($request),
        $priceListBaseMiddleware
    );
    $router->get(
        '/configuracion/listas-precios/crear',
        static fn (Request $request): Response =>
            $priceListController->createForm($request),
        $priceListMiddleware('precios.listas.crear')
    );
    $router->post(
        '/configuracion/listas-precios',
        static fn (Request $request): Response =>
            $priceListController->create($request),
        $priceListMiddleware('precios.listas.crear')
    );
    $router->get(
        '/configuracion/listas-precios/ver',
        static fn (Request $request): Response =>
            $priceListController->show($request),
        $priceListMiddleware('precios.listas.ver')
    );
    $router->get(
        '/configuracion/listas-precios/editar',
        static fn (Request $request): Response =>
            $priceListController->editForm($request),
        $priceListMiddleware('precios.listas.editar')
    );
    $router->post(
        '/configuracion/listas-precios/actualizar',
        static fn (Request $request): Response =>
            $priceListController->update($request),
        $priceListMiddleware('precios.listas.editar')
    );
    $router->post(
        '/configuracion/listas-precios/activar',
        static fn (Request $request): Response =>
            $priceListController->activate($request),
        $priceListMiddleware('precios.listas.activar')
    );
    $router->post(
        '/configuracion/listas-precios/desactivar',
        static fn (Request $request): Response =>
            $priceListController->deactivate($request),
        $priceListMiddleware('precios.listas.activar')
    );
    $router->post(
        '/configuracion/listas-precios/predeterminada',
        static fn (Request $request): Response =>
            $priceListController->setDefault($request),
        $priceListMiddleware('precios.listas.predeterminada')
    );

    $productPriceBaseMiddleware = [
        $authMiddleware,
        new PermissionMiddleware($auth, $permissions, 'precios.productos.acceder'),
    ];
    $productPriceMiddleware = static function (string $permission) use (
        $productPriceBaseMiddleware,
        $auth,
        $permissions
    ): array {
        return array_merge($productPriceBaseMiddleware, [
            new PermissionMiddleware($auth, $permissions, $permission),
        ]);
    };

    $router->get(
        '/precios/productos',
        static fn (Request $request): Response =>
            $productPriceController->index($request),
        $productPriceBaseMiddleware
    );
    $router->get(
        '/precios/productos/crear',
        static fn (Request $request): Response =>
            $productPriceController->createForm($request),
        $productPriceMiddleware('precios.productos.crear')
    );
    $router->post(
        '/precios/productos',
        static fn (Request $request): Response =>
            $productPriceController->create($request),
        $productPriceMiddleware('precios.productos.crear')
    );
    $router->get(
        '/precios/productos/ver',
        static fn (Request $request): Response =>
            $productPriceController->show($request),
        $productPriceMiddleware('precios.productos.ver')
    );
    $router->get(
        '/precios/productos/editar',
        static fn (Request $request): Response =>
            $productPriceController->editForm($request),
        $productPriceMiddleware('precios.productos.editar')
    );
    $router->post(
        '/precios/productos/actualizar',
        static fn (Request $request): Response =>
            $productPriceController->update($request),
        $productPriceMiddleware('precios.productos.editar')
    );
    $router->post(
        '/precios/productos/desactivar',
        static fn (Request $request): Response =>
            $productPriceController->deactivate($request),
        $productPriceMiddleware('precios.productos.desactivar')
    );
    $router->post(
        '/precios/productos/reactivar',
        static fn (Request $request): Response =>
            $productPriceController->reactivate($request),
        $productPriceMiddleware('precios.productos.reactivar')
    );
    $router->get(
        '/precios/productos/historial',
        static fn (Request $request): Response =>
            $productPriceController->history($request),
        $productPriceMiddleware('precios.productos.historial')
    );

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
        '/inventario/existencias-series',
        static fn (Request $request): Response =>
            $inventoryController->serialStock($request),
        [
            $authMiddleware,
            new PermissionMiddleware(
                $auth,
                $permissions,
                'inventario.existencias_series.acceder'
            ),
        ]
    );
    $router->get(
        '/inventario/kardex',
        static fn (Request $request): Response =>
            $inventoryController->kardex($request),
        [
            $authMiddleware,
            new PermissionMiddleware(
                $auth,
                $permissions,
                'inventario.kardex.acceder'
            ),
        ]
    );
    $router->get(
        '/inventario/kardex-series',
        static fn (Request $request): Response =>
            $inventoryController->serialKardex($request),
        [
            $authMiddleware,
            new PermissionMiddleware(
                $auth,
                $permissions,
                'inventario.kardex_series.acceder'
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
    $router->get(
        '/inventario/kardex/productos/buscar',
        static fn (Request $request): Response =>
            $inventoryController->searchKardexProducts($request),
        [
            $authMiddleware,
            new PermissionMiddleware(
                $auth,
                $permissions,
                'inventario.kardex.acceder'
            ),
        ]
    );

    $transferMiddleware = static function (string $permission) use (
        $authMiddleware,
        $auth,
        $permissions
    ): array {
        return [
            $authMiddleware,
            new PermissionMiddleware($auth, $permissions, $permission),
        ];
    };

    $router->get(
        '/inventario/transferencias',
        static fn (Request $request): Response =>
            $inventoryTransferController->index($request),
        $transferMiddleware('inventario.transferencias.acceder')
    );
    $router->get(
        '/inventario/transferencias/crear',
        static fn (Request $request): Response =>
            $inventoryTransferController->createForm($request),
        $transferMiddleware('inventario.transferencias.crear')
    );
    $router->post(
        '/inventario/transferencias',
        static fn (Request $request): Response =>
            $inventoryTransferController->create($request),
        $transferMiddleware('inventario.transferencias.crear')
    );
    $router->get(
        '/inventario/transferencias/ver',
        static fn (Request $request): Response =>
            $inventoryTransferController->detail($request),
        $transferMiddleware('inventario.transferencias.ver')
    );
    $router->get(
        '/inventario/transferencias/productos/buscar',
        static fn (Request $request): Response =>
            $inventoryTransferController->searchProducts($request),
        $transferMiddleware('inventario.transferencias.crear')
    );
};
