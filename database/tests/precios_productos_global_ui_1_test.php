<?php

declare(strict_types=1);

use App\Core\Request;
use App\Core\Session;
use App\Domain\Auth\AuthService;
use App\Domain\Pricing\ProductPriceService;
use App\Domain\Pricing\PricingValidationException;
use App\Domain\Products\ProductService;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContextService;
use App\Domain\Scope\UserScopeService;
use App\Http\Controllers\ProductPriceController;
use App\Http\Middlewares\PermissionMiddleware;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\PermissionRepository;
use App\Infrastructure\Repositories\PriceListRepository;
use App\Infrastructure\Repositories\ProductPriceHistoryRepository;
use App\Infrastructure\Repositories\ProductPriceRepository;
use App\Infrastructure\Repositories\ProductRepository;
use App\Infrastructure\Repositories\ScopeRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Support\Security\CsrfTokenService;

return new class implements DatabaseTest {
    private const PRODUCT_A = 'QAPGLOB001';
    private const PRODUCT_B = 'QAPGLOB002';
    private const PRODUCT_NO_CURRENCY = 'QAPGLOB003';

    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $activeDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

        if ($activeDatabase !== $expectedDatabase) {
            throw new RuntimeException('Unexpected active database.');
        }

        foreach ([
            'productos',
            'listas_precios',
            'producto_precios',
            'producto_precios_historial',
            'autorizaciones_precio',
        ] as $table) {
            if (!$this->tableExists($pdo, $table)) {
                throw new RuntimeException(
                    'PRECIOS-PRODUCTOS-GLOBAL-UI-1 requires table: ' . $table
                );
            }
        }

        $before = $this->counts($pdo);
        $connection = $GLOBALS['precios_productos_global_ui_connection'];
        $pricing = new ProductPriceService(
            new ProductPriceRepository($connection),
            new PriceListRepository($connection),
            new ProductPriceHistoryRepository($connection)
        );
        $products = new ProductService(new ProductRepository($connection), $pricing);
        $lists = new PriceListRepository($connection);
        $actorId = $this->adminId($pdo);
        $unitId = $this->unitId($pdo);
        $mxnId = $this->currencyId($pdo, 'MXN');
        $publicListId = $this->publicListId($pdo);
        $results = [
            'permissions' => $this->permissionAssertions($pdo, $actorId),
            'routes_and_views' => [
                'index_route' => $this->fileContains('routes/web.php', '/precios/productos'),
                'no_sales_route' => !$this->fileContains('routes/web.php', '/ventas'),
                'index_view' => $this->fileContains(
                    'app/Views/pricing/product-prices/index.php',
                    'Precios por producto'
                ),
                'form_view' => $this->fileContains(
                    'app/Views/pricing/product-prices/form.php',
                    'Crear precio de producto'
                ),
                'detail_view' => $this->fileContains(
                    'app/Views/pricing/product-prices/show.php',
                    'Detalle básico del precio'
                ),
                'history_view' => $this->fileContains(
                    'app/Views/pricing/product-prices/history.php',
                    'Historial de precio'
                ),
            ],
        ];

        $pdo->beginTransaction();

        try {
            $secondaryListId = $lists->insert([
                'clave' => 'QA_GLOBAL_UI',
                'nombre' => 'QA Global UI',
                'observaciones' => 'Lista QA transitoria.',
                'incluye_impuestos' => 0,
                'es_predeterminada' => 0,
                'activo' => 1,
                'creado_por' => $actorId,
                'actualizado_por' => $actorId,
            ]);
            $products->create($this->productInput(self::PRODUCT_A, $unitId, $mxnId), $actorId);
            $products->create($this->productInput(self::PRODUCT_B, $unitId, $mxnId), $actorId);
            $products->create($this->productInput(self::PRODUCT_NO_CURRENCY, $unitId, null), $actorId);

            $created = $pricing->crearPrecio([
                'id_producto' => self::PRODUCT_A,
                'lista_precio_id' => $publicListId,
                'precio_lista' => '100.0000',
                'precio_minimo' => '80.0000',
                'usuario_id' => $actorId,
                'motivo_cambio' => 'QA creación global.',
            ]);
            $results['create'] = [
                'valid_created' => (int) $created['id'] > 0,
                'history_creation' => $this->historyCount($pdo, (int) $created['id'], 'CREACION') === 1,
                'product_without_currency_rejected' => $this->fails(
                    fn () => $pricing->crearPrecio([
                        'id_producto' => self::PRODUCT_NO_CURRENCY,
                        'lista_precio_id' => $publicListId,
                        'precio_lista' => '100.0000',
                        'precio_minimo' => '80.0000',
                        'usuario_id' => $actorId,
                        'motivo_cambio' => 'QA rechazo.',
                    ])
                ),
                'duplicate_rejected' => $this->fails(
                    fn () => $pricing->crearPrecio([
                        'id_producto' => self::PRODUCT_A,
                        'lista_precio_id' => $publicListId,
                        'precio_lista' => '100.0000',
                        'precio_minimo' => '80.0000',
                        'usuario_id' => $actorId,
                        'motivo_cambio' => 'QA duplicado.',
                    ])
                ),
                'minimum_over_list_rejected' => $this->fails(
                    fn () => $pricing->crearPrecio([
                        'id_producto' => self::PRODUCT_B,
                        'lista_precio_id' => $publicListId,
                        'precio_lista' => '80.0000',
                        'precio_minimo' => '100.0000',
                        'usuario_id' => $actorId,
                        'motivo_cambio' => 'QA mínimo inválido.',
                    ])
                ),
            ];

            $updated = $pricing->actualizarPrecio([
                'producto_precio_id' => $created['id'],
                'precio_lista' => '120.0000',
                'precio_minimo' => '90.0000',
                'usuario_id' => $actorId,
                'motivo_cambio' => 'QA actualización global.',
            ]);
            $results['update'] = [
                'valid_updated' => $updated['precio_lista'] === '120.0000',
                'history_update' => $this->historyCount($pdo, (int) $created['id'], 'ACTUALIZACION') === 1,
            ];

            $revisionId = $this->insertRevisionPrice(
                $pdo,
                self::PRODUCT_B,
                $secondaryListId,
                $mxnId,
                $actorId
            );
            $revisionUpdated = $pricing->actualizarPrecio([
                'producto_precio_id' => $revisionId,
                'precio_lista' => '75.0000',
                'precio_minimo' => '60.0000',
                'usuario_id' => $actorId,
                'motivo_cambio' => 'QA limpia revisión.',
            ]);
            $results['update']['revision_cleared'] =
                (int) $revisionUpdated['requiere_revision'] === 0;

            $deactivated = $pricing->desactivarPrecio(
                (int) $created['id'],
                $actorId,
                'QA desactivación global.'
            );
            $reactivated = $pricing->reactivarPrecio(
                (int) $created['id'],
                $actorId,
                'QA reactivación global.'
            );
            $reviewForReject = $this->insertRevisionPrice(
                $pdo,
                self::PRODUCT_A,
                $secondaryListId,
                $mxnId,
                $actorId
            );
            $results['state'] = [
                'deactivated' => (int) $deactivated['activo'] === 0,
                'history_deactivation' => $this->historyCount($pdo, (int) $created['id'], 'DESACTIVACION') === 1,
                'reactivated' => (int) $reactivated['activo'] === 1,
                'history_reactivation' => $this->historyCount($pdo, (int) $created['id'], 'REACTIVACION') === 1,
                'reactivate_review_rejected' => $this->fails(
                    fn () => $pricing->reactivarPrecio(
                        $reviewForReject,
                        $actorId,
                        'QA rechazo revisión.'
                    )
                ),
            ];

            $results['read_filters'] = [
                'detail_works' => $pricing->getPriceDetail((int) $created['id']) !== null,
                'history_lists_changes' =>
                    count($pricing->listarHistorialProductoPrecio((int) $created['id'])) >= 4,
                'filter_by_list' =>
                    $pricing->listPrices(['lista_precio_id' => $publicListId])['total'] >= 1,
                'filter_by_active' =>
                    $pricing->listPrices(['activo' => 'active'])['total'] >= 1,
                'filter_by_revision' =>
                    $pricing->listPrices(['requiere_revision' => 'yes', 'activo' => 'all'])['total'] >= 1,
                'search_by_product' =>
                    $pricing->listPrices(['q' => self::PRODUCT_A])['total'] >= 1,
                'active_lists_available' => count($pricing->listarListasActivas()) >= 1,
                'active_currencies_available' => count($pricing->listarMonedasActivas()) >= 1,
            ];

            $results['controller_http'] = [
                'index_with_permission_200' => $this->controllerIndexStatus($actorId) === 200,
                'index_without_permission_403' =>
                    $this->permissionMiddlewareStatus($actorId, false) === 403,
            ];
            $results['scope'] = [
                'listas_precios_only_transient' =>
                    $this->countWhere($pdo, 'listas_precios', "clave LIKE 'QA_GLOBAL%'") === 1,
                'no_authorizations_created' =>
                    $this->authorizationCount($pdo) === $before['autorizaciones_precio'],
            ];
            $during = $this->counts($pdo);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        $after = $this->counts($pdo);
        $results['cleanup'] = [
            'qa_products_rolled_back' => $after['productos_qa'] === $before['productos_qa'],
            'qa_lists_rolled_back' => $after['listas_precios_qa'] === $before['listas_precios_qa'],
            'qa_prices_rolled_back' => $after['producto_precios_qa'] === $before['producto_precios_qa'],
            'qa_history_rolled_back' =>
                $after['producto_precios_historial_qa']
                === $before['producto_precios_historial_qa'],
            'authorizations_unchanged' =>
                $after['autorizaciones_precio'] === $before['autorizaciones_precio'],
        ];

        if (!$this->allTrue($results)) {
            throw new RuntimeException(
                'PRECIOS-PRODUCTOS-GLOBAL-UI-1 assertions failed: '
                . json_encode(
                    $results,
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                )
            );
        }

        return [
            'database' => $expectedDatabase,
            'cases' => $results,
            'persistent_counts_before' => $before,
            'transient_counts_during' => $during,
            'persistent_counts_after' => $after,
            'cleanup' => 'transaction_rolled_back',
            'productos_php_db_test_exception' =>
                'not_required_due_to_existing_real_product',
        ];
    }

    private function controllerIndexStatus(int $actorId): int
    {
        $connection = $GLOBALS['precios_productos_global_ui_connection'];
        $session = $this->startedSession();
        $session->put('auth_user', [
            'user_id' => $actorId,
            'username' => 'qa-admin',
            'email' => 'qa-admin@example.test',
        ]);
        $auth = new AuthService(new UserRepository($connection), $session);
        $permissions = new PermissionService(new PermissionRepository($connection));
        $controller = new ProductPriceController(
            $GLOBALS['precios_productos_global_ui_config'],
            $auth,
            $permissions,
            new ScopeContextService(
                new UserScopeService(new ScopeRepository($connection)),
                $session
            ),
            new CsrfTokenService($session),
            new ProductPriceService(
                new ProductPriceRepository($connection),
                new PriceListRepository($connection),
                new ProductPriceHistoryRepository($connection)
            )
        );

        return $controller->index(new Request('GET', '/precios/productos'))->status();
    }

    private function permissionMiddlewareStatus(int $actorId, bool $grant): int
    {
        $connection = $GLOBALS['precios_productos_global_ui_connection'];
        $session = $this->startedSession();
        $session->put('auth_user', [
            'user_id' => $grant ? $actorId : 999999,
            'username' => 'qa-user',
            'email' => 'qa-user@example.test',
        ]);
        $middleware = new PermissionMiddleware(
            new AuthService(new UserRepository($connection), $session),
            new PermissionService(new PermissionRepository($connection)),
            'precios.productos.acceder'
        );

        return $middleware
            ->process(
                new Request('GET', '/precios/productos'),
                static fn (Request $request) => \App\Core\Response::html('ok')
            )
            ->status();
    }

    private function startedSession(): Session
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_save_path(sys_get_temp_dir());
            session_id('precios-productos-global-ui-1');
            session_start();
        }

        $_SESSION = [];

        return new Session(['name' => session_name()]);
    }

    /**
     * @return array<string, bool>
     */
    private function permissionAssertions(PDO $pdo, int $adminId): array
    {
        $required = [
            'precios.productos.acceder',
            'precios.productos.ver',
            'precios.productos.crear',
            'precios.productos.editar',
            'precios.productos.desactivar',
            'precios.productos.reactivar',
            'precios.productos.historial',
        ];
        $results = [];

        foreach ($required as $permission) {
            $statement = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM permisos p
                 INNER JOIN rol_permisos rp ON rp.permiso_id = p.id
                 INNER JOIN usuario_roles ur ON ur.rol_id = rp.rol_id
                 INNER JOIN roles r ON r.id = rp.rol_id
                 WHERE ur.usuario_id = :usuario_id
                   AND p.codigo = :codigo
                   AND p.activo = 1
                   AND r.activo = 1
                   AND rp.activo = 1
                   AND ur.activo = 1'
            );
            $statement->execute([
                'usuario_id' => $adminId,
                'codigo' => $permission,
            ]);
            $results[$permission] = (int) $statement->fetchColumn() >= 1;
        }

        return $results;
    }

    private function insertRevisionPrice(
        PDO $pdo,
        string $productId,
        int $listId,
        int $currencyId,
        int $actorId
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO producto_precios (
                id_producto, lista_precio_id, moneda_id, precio_lista,
                precio_minimo, incluye_impuestos, requiere_revision, activo,
                creado_por, actualizado_por
             ) VALUES (
                :id_producto, :lista_precio_id, :moneda_id, 0.0000,
                0.0000, 0, 1, 0, :creado_por, :actualizado_por
             )'
        );
        $statement->execute([
            'id_producto' => $productId,
            'lista_precio_id' => $listId,
            'moneda_id' => $currencyId,
            'creado_por' => $actorId,
            'actualizado_por' => $actorId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>
     */
    private function productInput(string $productId, int $unitId, ?int $currencyId): array
    {
        return [
            'id_producto' => $productId,
            'descripcion' => 'Producto QA precios global',
            'descripcion_larga' => 'Producto transitorio de precios globales.',
            'tipo_producto' => 'PRODUCTO',
            'unidad_medida_id' => (string) $unitId,
            'moneda_id' => $currencyId === null ? '' : (string) $currencyId,
            'impuestos' => [],
            'codigos_barras' => '',
        ];
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name'
        );
        $statement->execute(['table_name' => $table]);

        return (int) $statement->fetchColumn() === 1;
    }

    /**
     * @return array<string, int>
     */
    private function counts(PDO $pdo): array
    {
        return [
            'productos_qa' => $this->countWhere(
                $pdo,
                'productos',
                "id_producto LIKE 'QAPGLOB%'"
            ),
            'listas_precios_qa' => $this->countWhere(
                $pdo,
                'listas_precios',
                "clave LIKE 'QA_GLOBAL%'"
            ),
            'producto_precios_qa' => $this->countWhere(
                $pdo,
                'producto_precios',
                "id_producto LIKE 'QAPGLOB%'"
            ),
            'producto_precios_historial_qa' => $this->countWhere(
                $pdo,
                'producto_precios_historial',
                "id_producto LIKE 'QAPGLOB%'"
            ),
            'autorizaciones_precio' => $this->authorizationCount($pdo),
        ];
    }

    private function countWhere(PDO $pdo, string $table, string $where): int
    {
        return (int) $pdo->query(
            'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where
        )->fetchColumn();
    }

    private function authorizationCount(PDO $pdo): int
    {
        return (int) $pdo->query(
            'SELECT COUNT(*) FROM autorizaciones_precio'
        )->fetchColumn();
    }

    private function historyCount(PDO $pdo, int $priceId, string $type): int
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM producto_precios_historial
             WHERE producto_precio_id = :producto_precio_id
               AND tipo_cambio = :tipo_cambio'
        );
        $statement->execute([
            'producto_precio_id' => $priceId,
            'tipo_cambio' => $type,
        ]);

        return (int) $statement->fetchColumn();
    }

    private function adminId(PDO $pdo): int
    {
        $id = (int) $pdo->query(
            "SELECT id FROM usuarios WHERE username = 'jesus.g' LIMIT 1"
        )->fetchColumn();

        if ($id < 1) {
            throw new RuntimeException(
                'PRECIOS-PRODUCTOS-GLOBAL-UI-1 requires admin user.'
            );
        }

        return $id;
    }

    private function unitId(PDO $pdo): int
    {
        return $this->idByCode($pdo, 'unidades_medida', 'PIEZA');
    }

    private function currencyId(PDO $pdo, string $code): int
    {
        return $this->idByCode($pdo, 'monedas', $code);
    }

    private function idByCode(PDO $pdo, string $table, string $code): int
    {
        $statement = $pdo->prepare(
            'SELECT id FROM ' . $table . ' WHERE codigo = :codigo LIMIT 1'
        );
        $statement->execute(['codigo' => $code]);
        $id = (int) $statement->fetchColumn();

        if ($id < 1) {
            throw new RuntimeException($table . ' code is required: ' . $code);
        }

        return $id;
    }

    private function publicListId(PDO $pdo): int
    {
        $id = (int) $pdo->query(
            "SELECT id FROM listas_precios WHERE clave = 'PUBLICO' LIMIT 1"
        )->fetchColumn();

        if ($id < 1) {
            throw new RuntimeException('PUBLICO price list is required.');
        }

        return $id;
    }

    private function fails(callable $operation): bool
    {
        try {
            $operation();
        } catch (PricingValidationException) {
            return true;
        }

        return false;
    }

    private function fileContains(string $relativePath, string $needle): bool
    {
        $contents = file_get_contents(BASE_PATH . '/' . $relativePath);

        return is_string($contents) && str_contains($contents, $needle);
    }

    private function allTrue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (!is_array($value)) {
            return true;
        }

        foreach ($value as $item) {
            if (!$this->allTrue($item)) {
                return false;
            }
        }

        return true;
    }
};
