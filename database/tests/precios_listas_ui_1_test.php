<?php

declare(strict_types=1);

use App\Core\Request;
use App\Core\Session;
use App\Domain\Auth\AuthService;
use App\Domain\Pricing\PriceListService;
use App\Domain\Pricing\PricingValidationException;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContextService;
use App\Domain\Scope\UserScopeService;
use App\Http\Controllers\PriceListController;
use App\Http\Middlewares\PermissionMiddleware;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\PermissionRepository;
use App\Infrastructure\Repositories\PriceListRepository;
use App\Infrastructure\Repositories\ScopeRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Support\Security\CsrfTokenService;

return new class implements DatabaseTest {
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $activeDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

        if ($activeDatabase !== $expectedDatabase) {
            throw new RuntimeException('Unexpected active database.');
        }

        foreach (['listas_precios', 'producto_precios', 'permisos'] as $table) {
            if (!$this->tableExists($pdo, $table)) {
                throw new RuntimeException(
                    'PRECIOS-LISTAS-UI-1 requires table: ' . $table
                );
            }
        }

        $before = $this->counts($pdo);
        $connection = $GLOBALS['precios_listas_ui_connection'];
        $service = new PriceListService(new PriceListRepository($connection));
        $actorId = $this->adminId($pdo);
        $public = $this->publicList($pdo);
        $publicProductPricesBefore = $this->productPriceCount($pdo);
        $results = [
            'publico_initial' => [
                'exists' => $public !== null,
                'active' => (int) ($public['activo'] ?? 0) === 1,
                'default' => (int) ($public['es_predeterminada'] ?? 0) === 1,
            ],
            'permissions' => $this->permissionAssertions($pdo, $actorId),
            'routes_and_views' => [
                'index_route' => $this->fileContains(
                    'routes/web.php',
                    '/configuracion/listas-precios'
                ),
                'no_product_price_global_route' => !$this->fileContains(
                    'routes/web.php',
                    '/precios/productos'
                ),
                'index_view' => $this->fileContains(
                    'app/Views/pricing/lists/index.php',
                    'Listas de precios'
                ),
                'form_view' => $this->fileContains(
                    'app/Views/pricing/lists/form.php',
                    'Crear lista de precios'
                ),
                'show_view' => $this->fileContains(
                    'app/Views/pricing/lists/show.php',
                    'Detalle básico'
                ),
            ],
        ];

        $pdo->beginTransaction();

        try {
            $createdId = $service->create([
                'clave' => 'qa-lista-ui',
                'nombre' => 'QA Lista UI',
                'observaciones' => 'Lista QA transitoria.',
                'incluye_impuestos' => '1',
                'activo' => '1',
                'es_predeterminada' => '0',
            ], $actorId);
            $created = $service->get($createdId);
            $results['create_valid'] = [
                'created' => $createdId > 0,
                'uppercase_normalized' => $created['clave'] === 'QA-LISTA-UI',
                'tax_flag' => (int) $created['incluye_impuestos'] === 1,
            ];

            $results['create_rejections'] = [
                'duplicate_key' => $this->fails(
                    fn () => $service->create([
                        'clave' => 'QA-LISTA-UI',
                        'nombre' => 'Duplicada',
                        'activo' => '1',
                    ], $actorId)
                ),
                'empty_name' => $this->fails(
                    fn () => $service->create([
                        'clave' => 'QA-SIN-NOMBRE',
                        'nombre' => '',
                        'activo' => '1',
                    ], $actorId)
                ),
                'invalid_key' => $this->fails(
                    fn () => $service->create([
                        'clave' => 'QA LISTA',
                        'nombre' => 'Inválida',
                        'activo' => '1',
                    ], $actorId)
                ),
                'inactive_default' => $this->fails(
                    fn () => $service->create([
                        'clave' => 'QA-INACTIVA-PRED',
                        'nombre' => 'Inválida',
                        'activo' => '0',
                        'es_predeterminada' => '1',
                    ], $actorId)
                ),
            ];

            $service->update($createdId, [
                'clave' => 'QA-LISTA-UI-EDIT',
                'nombre' => 'QA Lista UI Editada',
                'observaciones' => 'Actualizada.',
                'incluye_impuestos' => '0',
                'activo' => '1',
                'es_predeterminada' => '0',
            ], $actorId);
            $updated = $service->get($createdId);
            $results['edit_valid'] = [
                'key_updated' => $updated['clave'] === 'QA-LISTA-UI-EDIT',
                'name_updated' => $updated['nombre'] === 'QA Lista UI Editada',
                'tax_updated' => (int) $updated['incluye_impuestos'] === 0,
            ];

            $service->deactivate($createdId, $actorId);
            $inactive = $service->get($createdId);
            $service->activate($createdId, $actorId);
            $active = $service->get($createdId);
            $results['activate_deactivate'] = [
                'non_default_deactivated' => (int) $inactive['activo'] === 0,
                'activated' => (int) $active['activo'] === 1,
            ];

            $results['default_rules'] = [
                'deactivate_current_default_rejected' => $this->fails(
                    fn () => $service->deactivate((int) $public['id'], $actorId)
                ),
            ];

            $service->setDefault($createdId, $actorId);
            $newDefault = $service->get($createdId);
            $oldPublic = $service->get((int) $public['id']);
            $results['default_rules'] += [
                'set_active_default' =>
                    (int) $newDefault['es_predeterminada'] === 1,
                'previous_default_unset' =>
                    (int) $oldPublic['es_predeterminada'] === 0,
                'single_default' => $this->activeDefaultCount($pdo) === 1,
            ];

            $inactiveDefaultId = $service->create([
                'clave' => 'QA-INACTIVA',
                'nombre' => 'QA Inactiva',
                'activo' => '0',
                'es_predeterminada' => '0',
            ], $actorId);
            $results['default_rules']['inactive_default_rejected'] =
                $this->fails(fn () => $service->setDefault($inactiveDefaultId, $actorId));

            $search = $service->search(['search' => 'QA Lista', 'status' => 'all']);
            $results['search_and_detail'] = [
                'index_lists_created' => (int) $search['total'] >= 1,
                'detail_loads' => $service->get($createdId)['id'] === $createdId,
            ];

            $results['controller_http'] = [
                'index_with_admin_permission_200' =>
                    $this->controllerIndexStatus($actorId) === 200,
                'index_without_permission_403' =>
                    $this->permissionMiddlewareStatus($actorId, false) === 403,
            ];

            $during = $this->counts($pdo);
            $results['no_product_prices_created'] =
                $this->productPriceCount($pdo) === $publicProductPricesBefore;
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        $after = $this->counts($pdo);
        $afterPublic = $this->publicList($pdo);

        $results['cleanup'] = [
            'qa_rows_rolled_back' => $after['listas_precios_qa'] === $before['listas_precios_qa'],
            'publico_still_default' =>
                (int) ($afterPublic['es_predeterminada'] ?? 0) === 1
                && (int) ($afterPublic['activo'] ?? 0) === 1,
            'producto_precios_unchanged' =>
                $this->productPriceCount($pdo) === $publicProductPricesBefore,
        ];

        if (!$this->allTrue($results)) {
            throw new RuntimeException(
                'PRECIOS-LISTAS-UI-1 assertions failed: '
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
        $connection = $GLOBALS['precios_listas_ui_connection'];
        $session = $this->startedSession();
        $session->put('auth_user', [
            'user_id' => $actorId,
            'username' => 'qa-admin',
            'email' => 'qa-admin@example.test',
        ]);
        $auth = new AuthService(new UserRepository($connection), $session);
        $permissions = new PermissionService(new PermissionRepository($connection));
        $controller = new PriceListController(
            $GLOBALS['precios_listas_ui_config'],
            $auth,
            $permissions,
            new ScopeContextService(
                new UserScopeService(new ScopeRepository($connection)),
                $session
            ),
            new CsrfTokenService($session),
            new PriceListService(new PriceListRepository($connection))
        );

        return $controller->index(new Request('GET', '/configuracion/listas-precios'))->status();
    }

    private function permissionMiddlewareStatus(int $actorId, bool $grant): int
    {
        $connection = $GLOBALS['precios_listas_ui_connection'];
        $session = $this->startedSession();
        $session->put('auth_user', [
            'user_id' => $grant ? $actorId : 999999,
            'username' => 'qa-user',
            'email' => 'qa-user@example.test',
        ]);
        $middleware = new PermissionMiddleware(
            new AuthService(new UserRepository($connection), $session),
            new PermissionService(new PermissionRepository($connection)),
            'precios.listas.acceder'
        );

        return $middleware
            ->process(
                new Request('GET', '/configuracion/listas-precios'),
                static fn (Request $request) => \App\Core\Response::html('ok')
            )
            ->status();
    }

    private function startedSession(): Session
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_save_path(sys_get_temp_dir());
            session_id('precios-listas-ui-1');
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
            'precios.listas.acceder',
            'precios.listas.ver',
            'precios.listas.crear',
            'precios.listas.editar',
            'precios.listas.activar',
            'precios.listas.eliminar',
            'precios.listas.predeterminada',
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
            'listas_precios_total' =>
                (int) $pdo->query('SELECT COUNT(*) FROM listas_precios')->fetchColumn(),
            'listas_precios_qa' => $this->countWhere(
                $pdo,
                'listas_precios',
                "clave LIKE 'QA-%'"
            ),
            'producto_precios' => $this->productPriceCount($pdo),
        ];
    }

    private function countWhere(PDO $pdo, string $table, string $where): int
    {
        return (int) $pdo->query(
            'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where
        )->fetchColumn();
    }

    private function productPriceCount(PDO $pdo): int
    {
        return (int) $pdo->query(
            'SELECT COUNT(*) FROM producto_precios'
        )->fetchColumn();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function publicList(PDO $pdo): ?array
    {
        $row = $pdo->query(
            "SELECT * FROM listas_precios WHERE clave = 'PUBLICO' LIMIT 1"
        )->fetch();

        return is_array($row) ? $row : null;
    }

    private function activeDefaultCount(PDO $pdo): int
    {
        return (int) $pdo->query(
            'SELECT COUNT(*)
             FROM listas_precios
             WHERE es_predeterminada = 1
               AND activo = 1
               AND eliminado_en IS NULL'
        )->fetchColumn();
    }

    private function adminId(PDO $pdo): int
    {
        $id = (int) $pdo->query(
            "SELECT id FROM usuarios WHERE username = 'jesus.g' LIMIT 1"
        )->fetchColumn();

        if ($id < 1) {
            throw new RuntimeException(
                'PRECIOS-LISTAS-UI-1 requires admin user.'
            );
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
