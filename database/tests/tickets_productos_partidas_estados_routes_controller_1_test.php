<?php

declare(strict_types=1);

use App\Core\Request;
use App\Core\Router;
use App\Core\Session;
use App\Domain\Auth\AuthService;
use App\Domain\Security\PermissionService;
use App\Domain\Tickets\ProductRequestTicketService;
use App\Http\Controllers\ProductRequestTicketController;
use App\Http\Middlewares\AuthMiddleware;
use App\Http\Middlewares\CsrfMiddleware;
use App\Http\Middlewares\PermissionMiddleware;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Database\Migration;
use App\Infrastructure\Database\MigrationRunner;
use App\Infrastructure\Database\Seed;
use App\Infrastructure\Repositories\PermissionRepository;
use App\Infrastructure\Repositories\ProductRequestTicketRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Support\Security\CsrfTokenService;

return new class implements DatabaseTest {
    private const PASSWORD = 'TpRoutesController123!';
    private const ADMIN_USERNAME = 'qa_tp_routes_admin';
    private const LIMITED_USERNAME = 'qa_tp_routes_limited';

    private PDO $pdo;
    private ProductRequestTicketService $service;

    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $this->pdo = $pdo;

        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
            throw new RuntimeException(
                'Unexpected active database for TP-PARTIDAS-ESTADOS-ROUTES-CONTROLLER-1.'
            );
        }

        $migration = require BASE_PATH
            . '/database/migrations/tp_partidas_estados_db_1_001_create_ticket_product_tables.php';
        $seed = require BASE_PATH
            . '/database/seeds/tickets_productos_partidas_estados_1_seed_permissions.php';

        if (!$migration instanceof Migration || !$seed instanceof Seed) {
            throw new RuntimeException('Ticket routes/controller test dependencies are invalid.');
        }

        $runner = new MigrationRunner($pdo);
        $migrationState = $this->ensureTicketTables($runner, $migration);
        $this->service = new ProductRequestTicketService(
            new ProductRequestTicketRepository(
                $GLOBALS['tp_product_ticket_routes_controller_connection']
            )
        );

        $before = $this->operationalCounts();
        $results = [];

        try {
            $pdo->beginTransaction();
            $seed->run($pdo);
            $fixture = $this->fixture();

            $results['static_contract'] = $this->staticContractCases();
            $results['route_security'] = $this->routeSecurityCases($fixture);
            $results['controller_flow'] = $this->controllerFlowCases($fixture);
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

            if ($migrationState === 'applied_for_test') {
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
                'TP-PARTIDAS-ESTADOS-ROUTES-CONTROLLER-1 assertions failed: '
                . json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        return [
            'database' => $expectedDatabase,
            'migration_state' => $migrationState,
            'routes' => [
                'GET /tickets/productos',
                'GET /tickets/productos/crear',
                'POST /tickets/productos',
                'GET /tickets/productos/{id}',
                'POST /tickets/productos/{id}/partidas/{partidaId}/aprobar',
                'POST /tickets/productos/{id}/partidas/{partidaId}/rechazar',
                'POST /tickets/productos/{id}/cancelar',
            ],
            'controller' => ProductRequestTicketController::class,
            'cases' => $results,
            'operational_counts_before' => $before,
            'operational_counts_during' => $during ?? [],
            'operational_counts_after' => $after,
            'cleanup' => 'transaction_rolled_back_no_operational_data_written',
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function staticContractCases(): array
    {
        $routes = $this->read('routes/web.php');
        $controller = $this->read('app/Http/Controllers/ProductRequestTicketController.php');
        $bootstrap = $this->read('bootstrap/app.php');

        return [
            'routes_declared' => $this->containsAll($routes, [
                '/tickets/productos',
                '/tickets/productos/crear',
                '/tickets/productos/{id}',
                '/tickets/productos/{id}/partidas/{partidaId}/aprobar',
                '/tickets/productos/{id}/partidas/{partidaId}/rechazar',
                '/tickets/productos/{id}/cancelar',
            ]),
            'permissions_declared' => $this->containsAll($routes, [
                'tickets_productos.ver',
                'tickets_productos.crear',
                'tickets_productos.resolver',
                'tickets_productos.cancelar',
            ]),
            'auth_and_permission_middleware_declared' =>
                str_contains($routes, 'AuthMiddleware')
                && str_contains($routes, 'PermissionMiddleware'),
            'csrf_global_middleware_registered' =>
                str_contains($bootstrap, 'new CsrfMiddleware($csrf)'),
            'controller_created' =>
                str_contains($controller, 'final class ProductRequestTicketController'),
            'controller_methods_created' => $this->containsAll($controller, [
                'function index(',
                'function create(',
                'function store(',
                'function show(',
                'function approveLine(',
                'function rejectLine(',
                'function cancel(',
            ]),
            'controller_uses_service' =>
                str_contains($controller, 'ProductRequestTicketService')
                && str_contains($controller, 'crearTicket(')
                && str_contains($controller, 'resolverPartida(')
                && str_contains($controller, 'cancelarTicket('),
            'controller_has_no_sql' =>
                preg_match('/\\b(SELECT|INSERT|UPDATE|DELETE)\\b/i', $controller) !== 1,
            'bootstrap_registers_controller' =>
                str_contains($bootstrap, 'ProductRequestTicketController')
                && str_contains($bootstrap, 'ProductRequestTicketRepository'),
        ];
    }

    /**
     * @param array<string, int> $fixture
     * @return array<string, bool>
     */
    private function routeSecurityCases(array $fixture): array
    {
        [$auth, $csrf, $router] = $this->stack('guest');
        $unauthenticated = $router->dispatch(new Request('GET', '/tickets/productos'));

        [$adminAuth, $adminCsrf, $adminRouter] = $this->stack('admin');
        $this->login($adminAuth, self::ADMIN_USERNAME);
        $index = $adminRouter->dispatch(new Request('GET', '/tickets/productos'));
        $create = $adminRouter->dispatch(new Request('GET', '/tickets/productos/crear'));
        $withoutCsrf = $adminRouter->dispatch(new Request('POST', '/tickets/productos', [], []));
        $invalidStore = $adminRouter->dispatch(new Request('POST', '/tickets/productos', [], [
            '_token' => $adminCsrf->token(),
            'empresa_id' => $fixture['company_id'],
            'almacen_id' => $fixture['warehouse_id'],
            'partidas' => [],
        ]));

        [$limitedAuth, $limitedCsrf, $limitedRouter] = $this->stack('limited');
        $this->login($limitedAuth, self::LIMITED_USERNAME);
        $limitedCreate = $limitedRouter->dispatch(new Request('POST', '/tickets/productos', [], [
            '_token' => $limitedCsrf->token(),
        ]));
        $limitedResolve = $limitedRouter->dispatch(new Request(
            'POST',
            '/tickets/productos/1/partidas/1/aprobar',
            [],
            ['_token' => $limitedCsrf->token()]
        ));
        $limitedCancel = $limitedRouter->dispatch(new Request(
            'POST',
            '/tickets/productos/1/cancelar',
            [],
            ['_token' => $limitedCsrf->token()]
        ));

        return [
            'unauthenticated_redirects_to_login' => $unauthenticated->status() === 302,
            'index_200_with_permission' =>
                $index->status() === 200
                && str_contains($index->body(), 'Tickets de productos')
                && !str_contains($index->body(), 'storage/uploads'),
            'create_200_with_permission' =>
                $create->status() === 200
                && str_contains($create->body(), 'Crear ticket de productos')
                && !str_contains($create->body(), 'storage/uploads'),
            'post_store_requires_csrf' => $withoutCsrf->status() === 419,
            'post_store_validates_minimum_line' =>
                $invalidStore->status() === 422
                && str_contains($invalidStore->body(), 'Agrega al menos una partida'),
            'user_without_create_permission_403' => $limitedCreate->status() === 403,
            'user_without_resolve_permission_403' => $limitedResolve->status() === 403,
            'user_without_cancel_permission_403' => $limitedCancel->status() === 403,
            'unused_guest_stack_created' => $auth instanceof AuthService && $csrf instanceof CsrfTokenService,
        ];
    }

    /**
     * @param array<string, int> $fixture
     * @return array<string, bool>
     */
    private function controllerFlowCases(array $fixture): array
    {
        [$auth, $csrf, $router] = $this->stack('flow');
        $this->login($auth, self::ADMIN_USERNAME);
        $token = $csrf->token();
        $store = $router->dispatch(new Request('POST', '/tickets/productos', [], [
            '_token' => $token,
            'empresa_id' => $fixture['company_id'],
            'almacen_id' => $fixture['warehouse_id'],
            'observaciones_generales' => 'Ticket documental desde controlador.',
            'partidas' => [
                ['descripcion' => 'Partida para aprobar'],
                ['descripcion' => 'Partida para rechazar'],
            ],
        ]));
        $ticket = $this->latestTicket($fixture['admin_user_id']);
        $parts = $this->partidas((int) $ticket['id']);
        $show = $router->dispatch(new Request('GET', '/tickets/productos/' . $ticket['id']));
        $approveWithoutCsrf = $router->dispatch(new Request(
            'POST',
            '/tickets/productos/' . $ticket['id'] . '/partidas/' . $parts[0]['id'] . '/aprobar'
        ));
        $approve = $router->dispatch(new Request(
            'POST',
            '/tickets/productos/' . $ticket['id'] . '/partidas/' . $parts[0]['id'] . '/aprobar',
            [],
            ['_token' => $token]
        ));
        $rejectWithoutCsrf = $router->dispatch(new Request(
            'POST',
            '/tickets/productos/' . $ticket['id'] . '/partidas/' . $parts[1]['id'] . '/rechazar'
        ));
        $rejectWithoutReason = $router->dispatch(new Request(
            'POST',
            '/tickets/productos/' . $ticket['id'] . '/partidas/' . $parts[1]['id'] . '/rechazar',
            [],
            ['_token' => $token]
        ));
        $reject = $router->dispatch(new Request(
            'POST',
            '/tickets/productos/' . $ticket['id'] . '/partidas/' . $parts[1]['id'] . '/rechazar',
            [],
            ['_token' => $token, 'motivo_rechazo' => 'Falta ficha técnica.']
        ));

        $cancelTicket = $this->service->crearTicket([
            'empresa_id' => $fixture['company_id'],
            'almacen_id' => $fixture['warehouse_id'],
            'partidas' => [['descripcion' => 'Partida para cancelar']],
        ], $fixture['admin_user_id']);
        $cancelWithoutReason = $router->dispatch(new Request(
            'POST',
            '/tickets/productos/' . $cancelTicket['id'] . '/cancelar',
            [],
            ['_token' => $token]
        ));
        $cancelWithoutCsrf = $router->dispatch(new Request(
            'POST',
            '/tickets/productos/' . $cancelTicket['id'] . '/cancelar'
        ));
        $cancel = $router->dispatch(new Request(
            'POST',
            '/tickets/productos/' . $cancelTicket['id'] . '/cancelar',
            [],
            ['_token' => $token, 'motivo' => 'Solicitud duplicada.']
        ));
        $cancelled = $this->service->obtenerTicket((int) $cancelTicket['id']);
        $resolvedParts = $this->partidas((int) $ticket['id']);

        return [
            'store_creates_documentary_ticket' =>
                $store->status() === 302
                && str_starts_with((string) $ticket['folio'], 'GU-')
                && (string) $ticket['estado'] === 'EN_REVISION',
            'show_displays_folio_state_and_lines' =>
                $show->status() === 200
                && str_contains($show->body(), (string) $ticket['folio'])
                && str_contains($show->body(), 'EN_REVISION')
                && str_contains($show->body(), 'Partida para aprobar')
                && !str_contains($show->body(), 'storage/uploads'),
            'approve_line_requires_csrf' => $approveWithoutCsrf->status() === 419,
            'approve_line_works' =>
                $approve->status() === 302
                && (string) $resolvedParts[0]['estado'] === 'APROBADA',
            'reject_line_requires_csrf' => $rejectWithoutCsrf->status() === 419,
            'reject_line_requires_reason' =>
                $rejectWithoutReason->status() === 422
                && str_contains($rejectWithoutReason->body(), 'motivo'),
            'reject_line_works' =>
                $reject->status() === 302
                && (string) $resolvedParts[1]['estado'] === 'RECHAZADA'
                && (string) $resolvedParts[1]['motivo_rechazo'] === 'Falta ficha técnica.',
            'cancel_requires_reason' =>
                $cancelWithoutReason->status() === 422
                && str_contains($cancelWithoutReason->body(), 'motivo'),
            'cancel_requires_csrf' => $cancelWithoutCsrf->status() === 419,
            'cancel_works' =>
                $cancel->status() === 302
                && is_array($cancelled)
                && (string) $cancelled['estado'] === 'CANCELADO',
        ];
    }

    /**
     * @return array<string, int>
     */
    private function fixture(): array
    {
        $adminUserId = $this->createUser(self::ADMIN_USERNAME);
        $limitedUserId = $this->createUser(self::LIMITED_USERNAME);
        $adminRoleId = $this->roleId('ADMIN');
        $limitedRoleId = $this->createRole('QA_TP_ROUTES_LIMITED');
        $this->assignRole($adminUserId, $adminRoleId);
        $this->assignRole($limitedUserId, $limitedRoleId);
        $this->assignPermissionToRole($limitedRoleId, 'tickets_productos.ver');
        $companyId = $this->createCompany($adminUserId);
        $warehouseId = $this->createWarehouse($companyId, $adminUserId);

        return [
            'admin_user_id' => $adminUserId,
            'limited_user_id' => $limitedUserId,
            'company_id' => $companyId,
            'warehouse_id' => $warehouseId,
        ];
    }

    /**
     * @return array{0: AuthService, 1: CsrfTokenService, 2: Router}
     */
    private function stack(string $suffix): array
    {
        $session = $this->session($suffix);
        $auth = new AuthService(
            new UserRepository($GLOBALS['tp_product_ticket_routes_controller_connection']),
            $session
        );
        $permissions = new PermissionService(
            new PermissionRepository($GLOBALS['tp_product_ticket_routes_controller_connection'])
        );
        $csrf = new CsrfTokenService($session, 7200);
        $controller = new ProductRequestTicketController($auth, $this->service);
        $router = new Router();
        $router->middleware(new CsrfMiddleware($csrf));
        $authMiddleware = new AuthMiddleware($auth);
        $productTicketMiddleware = static fn (string $permission): array => [
            $authMiddleware,
            new PermissionMiddleware($auth, $permissions, $permission),
        ];

        $router->get('/tickets/productos', static fn (Request $request) => $controller->index($request), $productTicketMiddleware('tickets_productos.ver'));
        $router->get('/tickets/productos/crear', static fn (Request $request) => $controller->create($request), $productTicketMiddleware('tickets_productos.crear'));
        $router->post('/tickets/productos', static fn (Request $request) => $controller->store($request), $productTicketMiddleware('tickets_productos.crear'));
        $router->get('/tickets/productos/{id}', static fn (Request $request, array $params) => $controller->show($request, $params), $productTicketMiddleware('tickets_productos.ver'));
        $router->post('/tickets/productos/{id}/partidas/{partidaId}/aprobar', static fn (Request $request, array $params) => $controller->approveLine($request, $params), $productTicketMiddleware('tickets_productos.resolver'));
        $router->post('/tickets/productos/{id}/partidas/{partidaId}/rechazar', static fn (Request $request, array $params) => $controller->rejectLine($request, $params), $productTicketMiddleware('tickets_productos.resolver'));
        $router->post('/tickets/productos/{id}/cancelar', static fn (Request $request, array $params) => $controller->cancel($request, $params), $productTicketMiddleware('tickets_productos.cancelar'));

        return [$auth, $csrf, $router];
    }

    private function session(string $suffix): Session
    {
        $this->closeSession();
        session_save_path(sys_get_temp_dir());
        session_name('tproutes' . preg_replace('/[^a-z0-9]/', '', strtolower($suffix)));
        session_id('tproutes' . bin2hex(random_bytes(8)));
        session_start();

        return new Session([
            'name' => session_name(),
            'same_site' => 'Lax',
            'secure' => false,
            'gc_max_lifetime' => 7200,
        ]);
    }

    private function login(AuthService $auth, string $username): void
    {
        if (!$auth->attempt($username, self::PASSWORD)) {
            throw new RuntimeException('Unable to authenticate QA user: ' . $username);
        }
    }

    private function closeSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
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
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createCompany(int $userId): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO empresas (codigo, nombre, activo, creado_por)
             VALUES (:codigo, :nombre, 1, :creado_por)'
        );
        $statement->execute([
            'codigo' => 'QATPRC',
            'nombre' => 'Empresa QA TP Routes',
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
            'codigo' => 'GU',
            'nombre' => 'Almacén QA TP Routes',
            'creado_por' => $userId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createRole(string $code): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO roles (codigo, nombre, descripcion, es_sistema, activo)
             VALUES (:codigo, :nombre, :descripcion, 0, 1)'
        );
        $statement->execute([
            'codigo' => $code,
            'nombre' => $code,
            'descripcion' => 'Rol QA transaccional para tickets documentales.',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function roleId(string $code): int
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM roles WHERE codigo = :codigo AND activo = 1 AND eliminado_en IS NULL LIMIT 1'
        );
        $statement->execute(['codigo' => $code]);
        $id = (int) $statement->fetchColumn();

        if ($id < 1) {
            throw new RuntimeException('Role not found: ' . $code);
        }

        return $id;
    }

    private function permissionId(string $code): int
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM permisos WHERE codigo = :codigo AND activo = 1 AND eliminado_en IS NULL LIMIT 1'
        );
        $statement->execute(['codigo' => $code]);
        $id = (int) $statement->fetchColumn();

        if ($id < 1) {
            throw new RuntimeException('Permission not found: ' . $code);
        }

        return $id;
    }

    private function assignRole(int $userId, int $roleId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO usuario_roles (usuario_id, rol_id, activo)
             VALUES (:usuario_id, :rol_id, 1)'
        );
        $statement->execute([
            'usuario_id' => $userId,
            'rol_id' => $roleId,
        ]);
    }

    private function assignPermissionToRole(int $roleId, string $permission): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO rol_permisos (rol_id, permiso_id, activo)
             VALUES (:rol_id, :permiso_id, 1)'
        );
        $statement->execute([
            'rol_id' => $roleId,
            'permiso_id' => $this->permissionId($permission),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function latestTicket(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT *
             FROM tickets_productos
             WHERE solicitante_usuario_id = :usuario_id
             ORDER BY id DESC
             LIMIT 1'
        );
        $statement->execute(['usuario_id' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            throw new RuntimeException('Expected QA ticket was not created.');
        }

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function partidas(int $ticketId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT *
             FROM tickets_productos_partidas
             WHERE ticket_producto_id = :ticket_id
             ORDER BY numero_partida'
        );
        $statement->execute(['ticket_id' => $ticketId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string, bool>
     */
    private function guardrailCases(array $before, array $during): array
    {
        return [
            'no_complete_ticket_views_created' => !$this->hasFiles('app/Views/tickets', '/\\.php$/i'),
            'no_css_or_js_created' =>
                !$this->hasFiles('public/css', '/tickets.*productos|productos.*tickets/i')
                && !$this->hasFiles('public/js', '/tickets.*productos|productos.*tickets/i'),
            'no_mail_runtime_created' =>
                !$this->hasFiles('app/Domain/Mail', '/ticket|solicitud|alta/i')
                && !$this->hasFiles('app/Domain/Notifications', '/ticket|solicitud|alta/i'),
            'no_product_created' => $before['productos'] === $during['productos'],
            'no_price_created' => $before['producto_precios'] === $during['producto_precios'],
            'no_stock_created' => $before['existencias_producto'] === $during['existencias_producto'],
            'no_inventory_created' => $before['inventario_existencias'] === $during['inventario_existencias'],
            'no_inventory_movement_created' =>
                $before['movimientos_inventario'] === $during['movimientos_inventario'],
            'no_purchase_created' => $before['compras'] === $during['compras'],
            'no_supplier_created' => $before['proveedores'] === $during['proveedores'],
        ];
    }

    private function ensureTicketTables(MigrationRunner $runner, Migration $migration): string
    {
        if ($this->tableExists('tickets_productos')) {
            return 'already_available';
        }

        $result = $runner->migrate($migration);

        if ($result !== 'applied') {
            throw new RuntimeException('Could not prepare ticket product tables.');
        }

        return 'applied_for_test';
    }

    /**
     * @return array<string, int>
     */
    private function operationalCounts(): array
    {
        return [
            'productos' => $this->countTable('productos'),
            'producto_precios' => $this->countTable('producto_precios'),
            'existencias_producto' => $this->countTableIfExists('existencias_producto'),
            'inventario_existencias' => $this->countTableIfExists('inventario_existencias'),
            'movimientos_inventario' => $this->countTable('movimientos_inventario'),
            'compras' => $this->countTableIfExists('compras'),
            'proveedores' => $this->countTableIfExists('proveedores'),
        ];
    }

    private function countTable(string $table): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    private function countTableIfExists(string $table): int
    {
        return $this->tableExists($table) ? $this->countTable($table) : 0;
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

    private function read(string $relativePath): string
    {
        $path = BASE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @param list<string> $needles
     */
    private function containsAll(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (!str_contains($haystack, $needle)) {
                return false;
            }
        }

        return true;
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
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }

            $relative = str_replace(
                [BASE_PATH . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR],
                ['', '/'],
                $file->getPathname()
            );

            if (preg_match($pattern, $relative) === 1) {
                return true;
            }
        }

        return false;
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
