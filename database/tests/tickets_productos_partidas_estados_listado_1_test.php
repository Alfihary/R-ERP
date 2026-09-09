<?php

declare(strict_types=1);

use App\Core\Request;
use App\Core\Session;
use App\Domain\Auth\AuthService;
use App\Domain\Security\PermissionService;
use App\Domain\Tickets\ProductRequestTicketService;
use App\Http\Controllers\ProductRequestTicketController;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Database\Migration;
use App\Infrastructure\Database\MigrationRunner;
use App\Infrastructure\Database\Seed;
use App\Infrastructure\Repositories\PermissionRepository;
use App\Infrastructure\Repositories\ProductRequestTicketRepository;
use App\Infrastructure\Repositories\UserRepository;

return new class implements DatabaseTest {
    private const PASSWORD_HASH = '$2y$10$RsLhFAOsYD.HL.vWZXF43Of7Vzpbqu.0OEb6qniOtM7Kx3z5YKZN2';

    private PDO $pdo;
    private ProductRequestTicketRepository $repository;
    private ProductRequestTicketService $service;

    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $this->pdo = $pdo;
        $this->repository = new ProductRequestTicketRepository($GLOBALS['tp_product_ticket_listado_connection']);
        $this->service = new ProductRequestTicketService($this->repository);

        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
            throw new RuntimeException('Unexpected active database for TP-PARTIDAS-ESTADOS-LISTADO-1.');
        }

        $migration = require BASE_PATH
            . '/database/migrations/tp_partidas_estados_db_1_001_create_ticket_product_tables.php';
        $seed = require BASE_PATH
            . '/database/seeds/tickets_productos_partidas_estados_1_seed_permissions.php';

        if (!$migration instanceof Migration || !$seed instanceof Seed) {
            throw new RuntimeException('TP-PARTIDAS-ESTADOS-LISTADO-1 dependencies are invalid.');
        }

        $runner = new MigrationRunner($pdo);
        $migrationState = $runner->migrate($migration);
        $before = $this->operationalCounts();
        $results = [];

        try {
            $pdo->beginTransaction();
            $seed->run($pdo);
            $fixture = $this->fixture();
            $tickets = $this->createTickets($fixture);

            $results['repository'] = $this->repositoryCases($tickets, $fixture);
            $results['controller_index'] = $this->controllerCases($fixture, $tickets);
            $results['view_index'] = $this->viewCases();
            $results['security'] = $this->securityCases();
            $during = $this->operationalCounts();
            $results['guardrails'] = $this->guardrailCases($before, $during);
            $pdo->rollBack();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        } finally {
            $this->closeSession();

            if ($migrationState === 'applied') {
                $runner->rollback($migration);
            }
        }

        $after = $this->operationalCounts();
        $results['cleanup'] = [
            'transaction_rolled_back' => $before === $after,
            'no_operational_counts_changed' => $before === $after,
        ];

        if (!$this->allTrue($results)) {
            throw new RuntimeException(
                'TP-PARTIDAS-ESTADOS-LISTADO-1 assertions failed: '
                . json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        return [
            'database' => $expectedDatabase,
            'migration_state' => $migrationState,
            'repository' => 'app/Infrastructure/Repositories/ProductRequestTicketRepository.php',
            'controller' => 'app/Http/Controllers/ProductRequestTicketController.php',
            'view' => 'app/Views/tickets/productos/index.php',
            'cases' => $results,
            'operational_counts_before' => $before,
            'operational_counts_during' => $during ?? [],
            'operational_counts_after' => $after,
            'cleanup' => 'transaction_rolled_back_no_operational_data_written',
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $tickets
     * @param array<string, int> $fixture
     * @return array<string, bool>
     */
    private function repositoryCases(array $tickets, array $fixture): array
    {
        $fixtureScope = ['empresa_id' => (string) $fixture['empresa_id']];
        $all = $this->repository->listar($fixtureScope, 1, 20);
        $folio = $this->repository->listar(['folio' => (string) $tickets['middle']['folio']], 1, 20);
        $state = $this->repository->listar($fixtureScope + ['estado' => 'APROBADO'], 1, 20);
        $invalidState = $this->repository->listar($fixtureScope + ['estado' => 'DROP TABLE'], 1, 20);
        $company = $this->repository->listar(['empresa_id' => (string) $fixture['empresa_id']], 1, 20);
        $warehouse = $this->repository->listar(['almacen_id' => (string) $fixture['almacen_id']], 1, 20);
        $from = $this->repository->listar($fixtureScope + ['fecha_desde' => '2026-09-02'], 1, 20);
        $to = $this->repository->listar($fixtureScope + ['fecha_hasta' => '2026-09-02'], 1, 20);
        $invalidDates = $this->repository->listar(
            $fixtureScope + ['fecha_desde' => '2026-99-99', 'fecha_hasta' => 'bad'],
            1,
            20
        );
        $pageOne = $this->repository->listar($fixtureScope, 1, 10);
        $pageTwo = $this->repository->listar($fixtureScope, 2, 10);
        $maxPerPage = $this->repository->listar($fixtureScope, 1, 500);
        $repository = $this->read('app/Infrastructure/Repositories/ProductRequestTicketRepository.php');

        return [
            'method_exists' => method_exists($this->repository, 'listar'),
            'uses_prepared_statements' => substr_count($repository, '->prepare(') >= 12,
            'uses_bound_limit_offset' => str_contains($repository, "bindValue(':limit'")
                && str_contains($repository, "bindValue(':offset'"),
            'escapes_like_wildcards' => str_contains($repository, 'escapeLike('),
            'lists_documentary_tickets' => $all['pagination']['total'] >= 3,
            'orders_newest_first' => ($all['items'][0]['folio'] ?? '') === $tickets['newest']['folio'],
            'folio_filter_works' => $folio['pagination']['total'] === 1
                && ($folio['items'][0]['folio'] ?? '') === $tickets['middle']['folio'],
            'state_filter_works' => $state['pagination']['total'] === 1
                && ($state['items'][0]['estado'] ?? '') === 'APROBADO',
            'invalid_state_ignored' => $invalidState['pagination']['total'] === $all['pagination']['total'],
            'company_filter_works' => $company['pagination']['total'] >= 3,
            'warehouse_filter_works' => $warehouse['pagination']['total'] >= 3,
            'fecha_desde_works' => $from['pagination']['total'] === 2,
            'fecha_hasta_works' => !in_array($tickets['newest']['folio'], array_column($to['items'], 'folio'), true)
                && in_array($tickets['middle']['folio'], array_column($to['items'], 'folio'), true),
            'invalid_dates_ignored' => $invalidDates['pagination']['total'] === $all['pagination']['total'],
            'pagination_page_per_page_works' => count($pageOne['items']) === 10
                && count($pageTwo['items']) >= 1
                && $pageOne['pagination']['perPage'] === 10,
            'per_page_maximum_allowed_values' => $maxPerPage['pagination']['perPage'] === 20,
            'returns_joined_display_fields' =>
                array_key_exists('empresa_nombre', $all['items'][0] ?? [])
                && array_key_exists('almacen_nombre', $all['items'][0] ?? [])
                && array_key_exists('almacen_codigo', $all['items'][0] ?? [])
                && array_key_exists('solicitante_nombre', $all['items'][0] ?? []),
        ];
    }

    /**
     * @param array<string, int> $fixture
     * @return array<string, bool>
     */
    private function controllerCases(array $fixture, array $tickets): array
    {
        $controller = $this->controllerFor($fixture['user_id']);
        $filtered = $controller->index(new Request('GET', '/tickets/productos', [
            'folio' => (string) $tickets['newest']['folio'],
            'estado' => 'CANCELADO',
            'page' => '1',
            'per_page' => '10',
        ]));
        $empty = $controller->index(new Request('GET', '/tickets/productos', [
            'folio' => 'SIN-RESULTADOS',
        ]));
        $controllerSource = $this->read('app/Http/Controllers/ProductRequestTicketController.php');

        return [
            'index_status_200' => $filtered->status() === 200,
            'index_reads_query_params' => str_contains($controllerSource, '$request->query()'),
            'index_calls_repository_listar' => str_contains($controllerSource, '->listar('),
            'index_renders_filters' => str_contains($filtered->body(), 'name="folio"')
                && str_contains($filtered->body(), 'name="estado"')
                && str_contains($filtered->body(), 'name="empresa_id"')
                && str_contains($filtered->body(), 'name="almacen_id"')
                && str_contains($filtered->body(), 'name="fecha_desde"')
                && str_contains($filtered->body(), 'name="fecha_hasta"'),
            'index_displays_filtered_ticket' => str_contains($filtered->body(), (string) $tickets['newest']['folio']),
            'index_displays_total_and_pagination' => str_contains($filtered->body(), 'resultado')
                && str_contains($filtered->body(), 'Página'),
            'index_empty_state_works' => str_contains($empty->body(), 'No hay tickets de productos para mostrar.'),
            'new_ticket_permission_preserved' => str_contains($filtered->body(), 'href="/tickets/productos/crear"'),
            'detail_permission_preserved' => str_contains($filtered->body(), 'Ver detalle'),
            'documentary_warning_preserved' => str_contains($filtered->body(), 'Este módulo es documental y no crea productos reales.'),
            'controller_has_no_sql' => preg_match('/\b(SELECT|INSERT|UPDATE|DELETE)\b/i', $controllerSource) !== 1,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function viewCases(): array
    {
        $view = $this->read('app/Views/tickets/productos/index.php');

        return [
            'index_has_filter_form_get' => str_contains($view, 'method="get" action="/tickets/productos"'),
            'index_has_clear_filters' => str_contains($view, 'Limpiar filtros'),
            'index_has_state_selector' => str_contains($view, 'RESUELTO_PARCIAL')
                && str_contains($view, 'CANCELADO'),
            'index_has_result_summary' => str_contains($view, 'ticket-products__result-summary'),
            'index_has_previous_next_pagination' => str_contains($view, 'Anterior')
                && str_contains($view, 'Siguiente'),
            'index_uses_escape_helper' => str_contains($view, '<?= e('),
            'index_keeps_visual_permission_conditions' => str_contains($view, '$canCreate')
                && str_contains($view, '$canView'),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function securityCases(): array
    {
        $html = $this->render('app/Views/tickets/productos/index.php', [
            'tickets' => [[
                'id' => 99,
                'folio' => 'QA-<script>alert(1)</script>',
                'estado' => 'EN_REVISION',
                'empresa_id' => 1,
                'empresa_nombre' => 'Empresa <script>alert(1)</script>',
                'almacen_id' => 1,
                'almacen_nombre' => 'Almacén QA',
                'almacen_codigo' => 'GU',
                'solicitante_id' => 1,
                'solicitante_nombre' => 'qa_user',
                'created_at' => '2026-09-03 10:00:00',
                'updated_at' => null,
                'total_partidas' => 1,
                'partidas_en_revision' => 1,
                'partidas_aprobadas' => 0,
                'partidas_rechazadas' => 0,
            ]],
            'permissions' => ['canView' => true, 'canCreate' => true],
            'listing' => [
                'items' => [],
                'filters' => ['folio' => '<script>alert(1)</script>'],
                'pagination' => ['page' => 1, 'perPage' => 20, 'total' => 1, 'totalPages' => 1],
            ],
        ]);

        return [
            'outputs_escape_malicious_values' => str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;')
                && !str_contains($html, '<script>alert(1)</script>'),
            'no_storage_uploads_in_html' => !str_contains($html, 'storage/uploads'),
            'no_physical_paths_in_html' => !str_contains($html, 'C:\\')
                && !str_contains($html, '/var/')
                && !str_contains($html, 'BASE_PATH'),
            'no_sensitive_data_in_html' => !str_contains($html, 'password_hash')
                && !str_contains($html, 'token_hash')
                && !str_contains($html, 'auth_user')
                && !str_contains($html, '$_SESSION'),
            'no_js_created' => !$this->hasFiles('public/js', '/ticket|solicitud|alta/i'),
            'no_mail_runtime_created' => !$this->hasFiles('app/Domain/Mail', '/ticket|solicitud|alta/i')
                && !$this->hasFiles('app/Domain/Notifications', '/ticket|solicitud|alta/i'),
            'no_real_attachments_created' => !$this->hasFiles('storage', '/tickets-productos|ticket|solicitud/i'),
        ];
    }

    /**
     * @param array<string, int> $before
     * @param array<string, int> $during
     * @return array<string, bool>
     */
    private function guardrailCases(array $before, array $during): array
    {
        return [
            'routes_unchanged_for_phase' => !str_contains($this->read('routes/web.php'), 'TP-PARTIDAS-ESTADOS-LISTADO-1'),
            'bootstrap_unchanged_for_phase' => !str_contains($this->read('bootstrap/app.php'), 'TP-PARTIDAS-ESTADOS-LISTADO-1'),
            'service_not_modified_for_phase' => !str_contains(
                $this->read('app/Domain/Tickets/ProductRequestTicketService.php'),
                'listar('
            ),
            'no_product_created' => $before['productos'] === $during['productos'],
            'no_price_created' => $before['producto_precios'] === $during['producto_precios'],
            'no_stock_created' => $before['existencias_producto'] === $during['existencias_producto'],
            'no_inventory_created' => $before['inventario_existencias'] === $during['inventario_existencias'],
            'no_inventory_movement_created' => $before['movimientos_inventario'] === $during['movimientos_inventario'],
            'no_purchase_created' => $before['compras'] === $during['compras'],
            'no_supplier_created' => $before['proveedores'] === $during['proveedores'],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function fixture(): array
    {
        $userId = $this->createUser('qa_tp_listado_1');
        $this->assignAdminRole($userId);
        $companyId = $this->createCompany($userId);
        $warehouseId = $this->createWarehouse($companyId, $userId);

        return [
            'user_id' => $userId,
            'empresa_id' => $companyId,
            'almacen_id' => $warehouseId,
        ];
    }

    /**
     * @param array<string, int> $fixture
     * @return array<string, array<string, mixed>>
     */
    private function createTickets(array $fixture): array
    {
        $oldest = $this->createTicket($fixture, '2026-09-01 08:00:00', null);
        $middle = $this->createTicket($fixture, '2026-09-02 09:00:00', 'APROBADO');
        $newest = $this->createTicket($fixture, '2026-09-03 10:00:00', 'CANCELADO');

        for ($index = 4; $index <= 14; $index++) {
            $this->createTicket(
                $fixture,
                '2026-08-' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) . ' 08:00:00',
                null
            );
        }

        return [
            'oldest' => $oldest,
            'middle' => $middle,
            'newest' => $newest,
        ];
    }

    /**
     * @param array<string, int> $fixture
     * @return array<string, mixed>
     */
    private function createTicket(array $fixture, string $createdAt, ?string $state): array
    {
        $ticket = $this->service->crearTicket([
            'empresa_id' => $fixture['empresa_id'],
            'almacen_id' => $fixture['almacen_id'],
            'observaciones_generales' => 'Ticket listado documental.',
            'partidas' => [[
                'descripcion' => 'Partida documental para listado',
                'modelo' => 'M-LIST',
                'marca_texto' => 'Marca documental',
                'proveedor_texto' => 'Proveedor documental',
            ]],
        ], $fixture['user_id']);

        if ($state === 'APROBADO') {
            $partidas = $this->repository->listPartidas((int) $ticket['id']);
            $this->service->resolverPartida(
                (int) $ticket['id'],
                (int) ($partidas[0]['id'] ?? 0),
                'APROBAR',
                ['comentario_resolucion' => 'Aprobación documental para listado.'],
                $fixture['user_id']
            );
        } elseif ($state === 'CANCELADO') {
            $this->service->cancelarTicket(
                (int) $ticket['id'],
                'Cancelación documental para listado.',
                $fixture['user_id']
            );
        }

        $update = $this->pdo->prepare(
            'UPDATE tickets_productos
             SET created_at = :created_at,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $update->execute([
            'id' => $ticket['id'],
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $this->repository->findTicketById((int) $ticket['id']) ?? [];
    }

    private function controllerFor(int $userId): ProductRequestTicketController
    {
        $this->closeSession();
        session_save_path(sys_get_temp_dir());
        session_name('TP_LIST_' . bin2hex(random_bytes(4)));

        if (!session_start()) {
            throw new RuntimeException('Unable to start listado test session.');
        }

        $_SESSION['auth_user'] = [
            'user_id' => $userId,
            'username' => 'qa_tp_listado_1',
            'email' => 'qa_tp_listado_1@example.test',
        ];

        $session = new Session([]);
        $auth = new AuthService(
            new UserRepository($GLOBALS['tp_product_ticket_listado_connection']),
            $session
        );
        $permissions = new PermissionService(
            new PermissionRepository($GLOBALS['tp_product_ticket_listado_connection'])
        );

        return new ProductRequestTicketController(
            $auth,
            $this->service,
            $permissions,
            $this->repository
        );
    }

    private function createUser(string $username): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO usuarios (username, email, password_hash, activo, creado_en)
             VALUES (:username, :email, :password_hash, 1, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            'username' => $username,
            'email' => $username . '@example.test',
            'password_hash' => self::PASSWORD_HASH,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function assignAdminRole(int $userId): void
    {
        $roleId = $this->activeAdminRoleId();

        if ($roleId < 1) {
            throw new RuntimeException('ADMIN role is required for TP-PARTIDAS-ESTADOS-LISTADO-1.');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO usuario_roles (usuario_id, rol_id, activo)
             VALUES (:usuario_id, :rol_id, 1)'
        );
        $statement->execute([
            'usuario_id' => $userId,
            'rol_id' => $roleId,
        ]);
    }

    private function activeAdminRoleId(): int
    {
        $statement = $this->pdo->prepare(
            "SELECT id FROM roles WHERE codigo = 'ADMIN' AND activo = 1 AND eliminado_en IS NULL LIMIT 1"
        );
        $statement->execute();

        return (int) ($statement->fetchColumn() ?: 0);
    }

    private function createCompany(int $userId): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO empresas (codigo, nombre, activo, creado_por)
             VALUES (:codigo, :nombre, 1, :creado_por)'
        );
        $statement->execute([
            'codigo' => 'QATPLST',
            'nombre' => 'Empresa QA TP Listado',
            'creado_por' => $userId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createWarehouse(int $companyId, int $userId): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO almacenes (empresa_id, codigo, nombre, activo, creado_por)
             VALUES (:empresa_id, :codigo, :nombre, 1, :creado_por)'
        );
        $statement->execute([
            'empresa_id' => $companyId,
            'codigo' => 'GL',
            'nombre' => 'Almacén QA TP Listado',
            'creado_por' => $userId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function render(string $relativePath, array $variables): string
    {
        extract($variables, EXTR_SKIP);

        ob_start();
        try {
            require BASE_PATH . '/' . $relativePath;

            return (string) ob_get_clean();
        } catch (Throwable $exception) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }

            throw $exception;
        }
    }

    /**
     * @return array<string, int>
     */
    private function operationalCounts(): array
    {
        return [
            'productos' => $this->optionalCount('productos'),
            'producto_precios' => $this->optionalCount('producto_precios'),
            'existencias_producto' => $this->optionalCount('existencias_producto'),
            'inventario_existencias' => $this->optionalCount('inventario_existencias'),
            'movimientos_inventario' => $this->optionalCount('movimientos_inventario'),
            'compras' => $this->optionalCount('compras'),
            'proveedores' => $this->optionalCount('proveedores'),
        ];
    }

    private function optionalCount(string $table): int
    {
        if (!$this->tableExists($table)) {
            return 0;
        }

        return (int) $this->pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name'
        );
        $statement->execute(['table_name' => $table]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function hasFiles(string $relativeDirectory, string $pattern): bool
    {
        $directory = BASE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);

        if (!is_dir($directory)) {
            return false;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo
                && $file->isFile()
                && preg_match($pattern, str_replace('\\', '/', $file->getPathname())) === 1
            ) {
                return true;
            }
        }

        return false;
    }

    private function read(string $relativePath): string
    {
        $path = BASE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    private function closeSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    private function allTrue(array $value): bool
    {
        foreach ($value as $item) {
            if (is_array($item)) {
                if (!$this->allTrue($item)) {
                    return false;
                }
            } elseif ($item !== true) {
                return false;
            }
        }

        return true;
    }
};
