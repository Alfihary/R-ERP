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
use App\Infrastructure\Repositories\ProductRequestTicketRepository;
use App\Infrastructure\Repositories\PermissionRepository;
use App\Infrastructure\Repositories\UserRepository;

return new class implements DatabaseTest {
    private const PASSWORD_HASH = '$2y$10$RsLhFAOsYD.HL.vWZXF43Of7Vzpbqu.0OEb6qniOtM7Kx3z5YKZN2';

    private PDO $pdo;

    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $this->pdo = $pdo;

        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
            throw new RuntimeException('Unexpected active database for TP-PARTIDAS-ESTADOS-UI-1.');
        }

        $migration = require BASE_PATH
            . '/database/migrations/tp_partidas_estados_db_1_001_create_ticket_product_tables.php';
        $seed = require BASE_PATH
            . '/database/seeds/tickets_productos_partidas_estados_1_seed_permissions.php';

        if (!$migration instanceof Migration || !$seed instanceof Seed) {
            throw new RuntimeException('TP-PARTIDAS-ESTADOS-UI-1 migration dependency is invalid.');
        }

        $runner = new MigrationRunner($pdo);
        $migrateResult = $runner->migrate($migration);
        $migrationAppliedForTest = $migrateResult === 'applied';
        $countsBefore = $this->operationalCounts();
        $results = [];
        $countsDuring = [];

        $pdo->beginTransaction();

        try {
            $seed->run($pdo);
            $fixture = $this->fixture();
            $controller = $this->controllerFor($fixture['user_id']);

            $index = $controller->index(new Request('GET', '/tickets/productos'));
            $create = $controller->create(new Request('GET', '/tickets/productos/crear'));
            $created = $controller->store(new Request('POST', '/tickets/productos', [], [
                'empresa_id' => (string) $fixture['empresa_id'],
                'almacen_id' => (string) $fixture['almacen_id'],
                'observaciones_generales' => 'Ticket documental <seguro>.',
                'partidas' => [[
                    'descripcion' => 'Partida documental <script>alert(1)</script>',
                    'modelo' => 'M-UI',
                    'marca_texto' => 'Marca UI',
                    'proveedor_texto' => 'Proveedor documental UI',
                    'unidad_sat_id' => '',
                    'clave_sat_id' => '',
                    'moneda_id' => '',
                    'costo_sugerido' => '12.50',
                    'peso' => '1.25',
                    'lleva_serie' => '1',
                    'observaciones' => 'Observación documental.',
                ]],
            ]));

            $ticket = $this->latestTicket();
            $show = $controller->show(
                new Request('GET', '/tickets/productos/' . $ticket['id']),
                ['id' => (string) $ticket['id']]
            );

            $partida = $this->firstPartida((int) $ticket['id']);
            $approve = $controller->approveLine(
                new Request('POST', '/tickets/productos/' . $ticket['id'] . '/partidas/' . $partida['id'] . '/aprobar', [], [
                    'comentario_resolucion' => 'Aprobación documental UI.',
                ]),
                ['id' => (string) $ticket['id'], 'partidaId' => (string) $partida['id']]
            );
            $rejectError = $controller->rejectLine(
                new Request('POST', '/tickets/productos/' . $ticket['id'] . '/partidas/' . $partida['id'] . '/rechazar', [], [
                    'motivo_rechazo' => '',
                ]),
                ['id' => (string) $ticket['id'], 'partidaId' => (string) $partida['id']]
            );

            $cancelTicket = $this->service()->crearTicket([
                'empresa_id' => $fixture['empresa_id'],
                'almacen_id' => $fixture['almacen_id'],
                'observaciones_generales' => 'Ticket para cancelar.',
                'partidas' => [[
                    'descripcion' => 'Partida para cancelar.',
                ]],
            ], $fixture['user_id']);
            $cancel = $controller->cancel(
                new Request('POST', '/tickets/productos/' . $cancelTicket['id'] . '/cancelar', [], [
                    'motivo' => 'Cancelación documental UI.',
                ]),
                ['id' => (string) $cancelTicket['id']]
            );

            $results = [
                'views' => $this->viewCases(),
                'controller_rendering' => [
                    'index_status_200' => $index->status() === 200,
                    'index_title' => str_contains($index->body(), 'Tickets de productos'),
                    'index_new_ticket' => str_contains($index->body(), 'Nuevo ticket'),
                    'create_status_200' => $create->status() === 200,
                    'create_form_post' => str_contains($create->body(), 'method="post" action="/tickets/productos"'),
                    'create_has_csrf' => str_contains($create->body(), 'name="_token"'),
                    'create_documentary_warning' => str_contains($create->body(), 'Este ticket es documental y no crea productos reales.'),
                    'store_redirects_to_detail' => $created->status() === 302,
                    'show_status_200' => $show->status() === 200,
                    'show_displays_folio_state_lines' =>
                        str_contains($show->body(), (string) $ticket['folio'])
                        && str_contains($show->body(), 'EN_REVISION')
                        && str_contains($show->body(), 'Partidas'),
                    'show_action_forms_have_csrf' =>
                        str_contains($show->body(), '/aprobar')
                        && str_contains($show->body(), '/rechazar')
                        && str_contains($show->body(), '/cancelar')
                        && substr_count($show->body(), 'name="_token"') >= 3,
                    'show_documentary_warning' => str_contains($show->body(), 'Autorizar una partida no crea el producto en el catálogo.'),
                    'approve_redirects' => $approve->status() === 302,
                    'reject_error_renders_show' =>
                        $rejectError->status() === 422
                        && str_contains($rejectError->body(), 'Revisa la operación'),
                    'cancel_redirects' => $cancel->status() === 302,
                ],
                'security' => [
                    'views_use_escape_helper' => $this->viewsUseEscapeHelper(),
                    'malicious_description_escaped' =>
                        str_contains($show->body(), '&lt;script&gt;alert(1)&lt;/script&gt;')
                        && !str_contains($show->body(), '<script>alert(1)</script>'),
                    'no_storage_uploads_in_html' => !$this->containsAnyHtml([$index, $create, $show, $rejectError], ['storage/uploads']),
                    'no_physical_paths_in_html' => !$this->containsAnyHtml([$index, $create, $show, $rejectError], [
                        'C:\\',
                        '/var/',
                        'BASE_PATH',
                    ]),
                    'no_sensitive_tokens_in_html' => !$this->containsAnyHtml([$index, $create, $show, $rejectError], [
                        'password_hash',
                        'token_hash',
                        'auth_user',
                    ]),
                ],
                'guardrails' => $this->guardrailCases($countsBefore),
                'routes_permissions' => [
                    'previous_ticket_routes_still_declared' => $this->allowedTicketProductRoutes(),
                    'controller_delegates_to_service' =>
                        str_contains($this->read('app/Http/Controllers/ProductRequestTicketController.php'), '$this->tickets->crearTicket')
                        && str_contains($this->read('app/Http/Controllers/ProductRequestTicketController.php'), '$this->tickets->resolverPartida')
                        && str_contains($this->read('app/Http/Controllers/ProductRequestTicketController.php'), '$this->tickets->cancelarTicket')
                        && preg_match('/\\b(SELECT|INSERT|UPDATE|DELETE)\\b/i', $this->read('app/Http/Controllers/ProductRequestTicketController.php')) !== 1,
                ],
            ];
            $countsDuring = $this->operationalCounts();
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $this->closeSession();

            if ($migrationAppliedForTest) {
                $runner->rollback($migration);
            }
        }

        $countsAfter = $this->operationalCounts();

        if (!$this->allTrue($results)) {
            throw new RuntimeException(
                'TP-PARTIDAS-ESTADOS-UI-1 assertions failed: '
                . json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        if ($countsBefore !== $countsAfter) {
            throw new RuntimeException('TP-PARTIDAS-ESTADOS-UI-1 changed operational counts.');
        }

        return [
            'database' => $expectedDatabase,
            'migration_state' => $migrateResult,
            'views' => [
                'app/Views/tickets/productos/index.php',
                'app/Views/tickets/productos/create.php',
                'app/Views/tickets/productos/show.php',
            ],
            'cases' => $results,
            'operational_counts_before' => $countsBefore,
            'operational_counts_during' => $countsDuring,
            'operational_counts_after' => $countsAfter,
            'cleanup' => 'transaction_rolled_back_no_operational_data_written',
        ];
    }

    /**
     * @return array{user_id: int, empresa_id: int, almacen_id: int}
     */
    private function fixture(): array
    {
        $userId = $this->createUser();
        $this->assignAdminRole($userId);
        $companyId = $this->createCompany($userId);
        $warehouseId = $this->createWarehouse($companyId, $userId);

        return [
            'user_id' => $userId,
            'empresa_id' => $companyId,
            'almacen_id' => $warehouseId,
        ];
    }

    private function controllerFor(int $userId): ProductRequestTicketController
    {
        $this->closeSession();
        session_save_path(sys_get_temp_dir());
        session_name('TP_UI_' . bin2hex(random_bytes(4)));

        if (!session_start()) {
            throw new RuntimeException('Unable to start UI test session.');
        }

        $_SESSION['auth_user'] = [
            'user_id' => $userId,
            'username' => 'qa_tp_ui_1',
            'email' => 'qa_tp_ui_1@example.test',
        ];

        $session = new Session([]);
        $auth = new AuthService(
            new UserRepository($GLOBALS['tp_product_ticket_ui_connection']),
            $session
        );

        return new ProductRequestTicketController(
            $auth,
            $this->service(),
            new PermissionService(new PermissionRepository($GLOBALS['tp_product_ticket_ui_connection']))
        );
    }

    private function service(): ProductRequestTicketService
    {
        return new ProductRequestTicketService(
            new ProductRequestTicketRepository($GLOBALS['tp_product_ticket_ui_connection'])
        );
    }

    /**
     * @return array<string, bool>
     */
    private function viewCases(): array
    {
        return [
            'index_exists' => $this->fileExists('app/Views/tickets/productos/index.php'),
            'create_exists' => $this->fileExists('app/Views/tickets/productos/create.php'),
            'show_exists' => $this->fileExists('app/Views/tickets/productos/show.php'),
            'create_includes_csrf_field' => str_contains(
                $this->read('app/Views/tickets/productos/create.php'),
                'csrf_field($csrf)'
            ),
            'show_includes_csrf_field' => substr_count(
                $this->read('app/Views/tickets/productos/show.php'),
                'csrf_field($csrf)'
            ) >= 3,
        ];
    }

    private function viewsUseEscapeHelper(): bool
    {
        foreach ([
            'app/Views/tickets/productos/index.php',
            'app/Views/tickets/productos/create.php',
            'app/Views/tickets/productos/show.php',
        ] as $view) {
            if (!str_contains($this->read($view), '<?= e(')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, int> $countsBefore
     * @return array<string, bool>
     */
    private function guardrailCases(array $countsBefore): array
    {
        $countsAfter = $this->operationalCounts();

        return [
            'only_expected_ticket_css_created' => $this->onlyExpectedFiles(
                'public/css/modules',
                '/tickets.*productos|productos.*tickets/i',
                ['public/css/modules/tickets-productos.css']
            ),
            'no_js_created' => !$this->hasFiles('public/js', '/ticket|solicitud|alta/i'),
            'no_mail_runtime_created' =>
                !$this->hasFiles('app/Domain/Mail', '/ticket|solicitud|alta/i')
                && !$this->hasFiles('app/Domain/Notifications', '/ticket|solicitud|alta/i'),
            'no_real_attachments_created' => !$this->hasFiles('storage', '/tickets-productos|ticket|solicitud/i'),
            'no_product_created' => $countsBefore['productos'] === $countsAfter['productos'],
            'no_price_created' => $countsBefore['producto_precios'] === $countsAfter['producto_precios'],
            'no_stock_created' => $countsBefore['existencias_producto'] === $countsAfter['existencias_producto'],
            'no_inventory_created' => $countsBefore['inventario_existencias'] === $countsAfter['inventario_existencias'],
            'no_inventory_movement_created' =>
                $countsBefore['movimientos_inventario'] === $countsAfter['movimientos_inventario'],
            'no_purchase_created' => $countsBefore['compras'] === $countsAfter['compras'],
            'no_supplier_created' => $countsBefore['proveedores'] === $countsAfter['proveedores'],
        ];
    }

    private function allowedTicketProductRoutes(): bool
    {
        $routes = $this->read('routes/web.php');

        return $this->containsAll($routes, [
            "'/tickets/productos'",
            "'/tickets/productos/crear'",
            "'/tickets/productos/{id}'",
            "'/tickets/productos/{id}/partidas/{partidaId}/aprobar'",
            "'/tickets/productos/{id}/partidas/{partidaId}/rechazar'",
            "'/tickets/productos/{id}/cancelar'",
            "tickets_productos.ver",
            "tickets_productos.crear",
            "tickets_productos.resolver",
            "tickets_productos.cancelar",
            "AuthMiddleware",
            "PermissionMiddleware",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function latestTicket(): array
    {
        $row = $this->pdo->query(
            'SELECT * FROM tickets_productos ORDER BY id DESC LIMIT 1'
        )->fetch();

        if (!is_array($row)) {
            throw new RuntimeException('UI test ticket was not created.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function firstPartida(int $ticketId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM tickets_productos_partidas WHERE ticket_producto_id = :ticket_id ORDER BY id ASC LIMIT 1'
        );
        $statement->execute(['ticket_id' => $ticketId]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            throw new RuntimeException('UI test line was not created.');
        }

        return $row;
    }

    private function createUser(): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO usuarios (username, email, password_hash, activo, creado_en)
             VALUES (:username, :email, :password_hash, 1, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            'username' => 'qa_tp_ui_1',
            'email' => 'qa_tp_ui_1@example.test',
            'password_hash' => self::PASSWORD_HASH,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function assignAdminRole(int $userId): void
    {
        $roleId = $this->activeAdminRoleId();

        if ($roleId < 1) {
            throw new RuntimeException('ADMIN role is required for TP-PARTIDAS-ESTADOS-UI-1 visual permissions.');
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
        if (!$this->tableExists('roles')) {
            return 0;
        }

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
            'codigo' => 'QATPUI',
            'nombre' => 'Empresa QA TP UI',
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
            'nombre' => 'Almacén QA TP UI',
            'creado_por' => $userId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param list<object> $responses
     * @param list<string> $needles
     */
    private function containsAnyHtml(array $responses, array $needles): bool
    {
        foreach ($responses as $response) {
            $body = method_exists($response, 'body') ? $response->body() : '';

            foreach ($needles as $needle) {
                if (str_contains($body, $needle)) {
                    return true;
                }
            }
        }

        return false;
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

        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
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

    private function fileExists(string $relativePath): bool
    {
        return is_file(BASE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
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

    /**
     * @param array<int, string> $expected
     */
    private function onlyExpectedFiles(string $relativeDirectory, string $pattern, array $expected): bool
    {
        $directory = BASE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);

        if (!is_dir($directory)) {
            return $expected === [];
        }

        $actual = [];
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
                $actual[] = $relative;
            }
        }

        sort($actual);
        sort($expected);

        return $actual === $expected;
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

    /**
     * @param array<string, mixed> $values
     */
    private function allTrue(array $values): bool
    {
        foreach ($values as $value) {
            if (is_array($value)) {
                if (!$this->allTrue($value)) {
                    return false;
                }

                continue;
            }

            if ($value !== true) {
                return false;
            }
        }

        return true;
    }

    private function closeSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }
};
