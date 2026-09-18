<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Request;
use App\Core\Router;
use App\Core\Session;
use App\Domain\Auth\AuthService;
use App\Domain\Scope\ScopeContextService;
use App\Domain\Scope\UserScopeService;
use App\Domain\Security\PermissionService;
use App\Domain\Tickets\ProductRequestTicketService;
use App\Http\Controllers\ProductRequestTicketController;
use App\Http\Middlewares\AuthMiddleware;
use App\Http\Middlewares\PermissionMiddleware;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Database\Migration;
use App\Infrastructure\Database\MigrationRunner;
use App\Infrastructure\Database\Seed;
use App\Infrastructure\Repositories\PermissionRepository;
use App\Infrastructure\Repositories\ProductRequestTicketRepository;
use App\Infrastructure\Repositories\ScopeRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Support\Security\CsrfTokenService;

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
            throw new RuntimeException('Unexpected active database for TP-PARTIDAS-ESTADOS-LAYOUT-ERP-1.');
        }

        $baseMigration = require BASE_PATH
            . '/database/migrations/tp_partidas_estados_db_1_001_create_ticket_product_tables.php';
        $approvalMigration = require BASE_PATH
            . '/database/migrations/tp_partidas_estados_aprobacion_captura_1_001_add_authorized_line_fields.php';
        $seed = require BASE_PATH
            . '/database/seeds/tickets_productos_partidas_estados_1_seed_permissions.php';

        if (!$baseMigration instanceof Migration || !$approvalMigration instanceof Migration || !$seed instanceof Seed) {
            throw new RuntimeException('TP-PARTIDAS-ESTADOS-LAYOUT-ERP-1 dependencies are invalid.');
        }

        $runner = new MigrationRunner($pdo);
        $baseMigrationState = $runner->migrate($baseMigration);
        $approvalMigrationState = $runner->migrate($approvalMigration);
        $countsBefore = $this->operationalCounts();
        $results = [];

        try {
            $pdo->beginTransaction();
            $seed->run($pdo);
            $fixture = $this->fixture();
            [$auth, $csrf, $router] = $this->stack($fixture);
            $this->login($auth, $fixture);

            $created = $this->service()->crearTicket([
                'empresa_id' => $fixture['empresa_id'],
                'almacen_id' => $fixture['almacen_id'],
                'observaciones_generales' => 'Fixture layout ERP sin crear producto real.',
                'partidas' => [[
                    'descripcion' => 'Partida documental para layout ERP.',
                    'modelo' => 'LAYOUT-ERP-1',
                    'marca_texto' => 'Marca documental',
                    'proveedor_texto' => 'Proveedor documental',
                    'costo_sugerido' => '12.75',
                    'peso' => '0.300',
                ]],
            ], $fixture['user_id']);

            $index = $router->dispatch(new Request('GET', '/tickets/productos'));
            $create = $router->dispatch(new Request('GET', '/tickets/productos/crear'));
            $show = $router->dispatch(new Request('GET', '/tickets/productos/' . $created['id']));

            [$guestAuth, $guestCsrf, $guestRouter] = $this->stack($fixture, false);
            $anonymous = $guestRouter->dispatch(new Request('GET', '/tickets/productos'));

            $responses = [
                'index' => $index,
                'create' => $create,
                'show' => $show,
            ];
            $controller = $this->read('app/Http/Controllers/ProductRequestTicketController.php');
            $layout = $this->read('app/Views/layouts/app.php');
            $indexView = $this->read('app/Views/tickets/productos/index.php');
            $createView = $this->read('app/Views/tickets/productos/create.php');
            $showView = $this->read('app/Views/tickets/productos/show.php');
            $routes = $this->read('routes/web.php');

            $results = [
                'http' => [
                    'index_authenticated_200' => $index->status() === 200,
                    'create_authenticated_200' => $create->status() === 200,
                    'show_authenticated_200' => $show->status() === 200,
                    'anonymous_redirects_to_login' => $anonymous->status() === 302,
                ],
                'layout_rendering' => [
                    'controller_renders_reusable_private_layout' =>
                        str_contains($controller, "View::render('layouts/app'")
                        && str_contains($controller, "'contentView' => \$view"),
                    'index_contains_private_layout' => $this->hasLayout($index->body()),
                    'create_contains_private_layout' => $this->hasLayout($create->body()),
                    'show_contains_private_layout' => $this->hasLayout($show->body()),
                    'index_has_sidebar' => str_contains($index->body(), 'class="app-sidebar"'),
                    'create_has_sidebar' => str_contains($create->body(), 'class="app-sidebar"'),
                    'show_has_sidebar' => str_contains($show->body(), 'class="app-sidebar"'),
                    'index_has_topbar' => str_contains($index->body(), 'class="app-topbar"'),
                    'create_has_topbar' => str_contains($create->body(), 'class="app-topbar"'),
                    'show_has_topbar' => str_contains($show->body(), 'class="app-topbar"'),
                    'authenticated_user_visible_in_topbar' =>
                        str_contains($index->body(), $fixture['username'])
                        && str_contains($index->body(), $fixture['email']),
                    'logout_button_visible' => str_contains($index->body(), 'Cerrar sesión'),
                    'ticket_navigation_visible' => str_contains($index->body(), 'Tickets de productos'),
                    'ticket_content_inside_main' =>
                        strpos($index->body(), '<main class="app-main"') !== false
                        && strpos($index->body(), '<h1>Tickets de productos</h1>') !== false
                        && strpos($index->body(), '<main class="app-main"') < strpos($index->body(), '<h1>Tickets de productos</h1>'),
                ],
                'view_contract' => [
                    'index_is_content_view_not_document' => $this->isContentView($indexView),
                    'create_is_content_view_not_document' => $this->isContentView($createView),
                    'show_is_content_view_not_document' => $this->isContentView($showView),
                    'views_do_not_duplicate_layout_shell' =>
                        $this->shellCount($indexView . $createView . $showView) === 0,
                    'css_loaded_by_layout' =>
                        str_contains($controller, "'stylesheets' => ['/css/modules/tickets-productos.css']")
                        && !str_contains($indexView . $createView . $showView, '/css/core/app.css'),
                    'external_js_loaded_by_layout' =>
                        str_contains($controller, "'/js/modules/tickets-productos-create.js'")
                        && str_contains($create->body(), '/js/modules/tickets-productos-create.js'),
                    'create_keeps_json_data_script' =>
                        str_contains($createView, 'type="application/json"')
                        && str_contains($create->body(), 'ticket-products-warehouses-data'),
                    'no_new_inline_executable_js' =>
                        preg_match('/<script(?![^>]*type="application\\/json")[^>]*>\\s*[^<\\s]/i', $indexView . $createView . $showView) !== 1,
                    'no_cdn_or_framework' =>
                        preg_match('/https?:\\/\\//i', $indexView . $createView . $showView) !== 1
                        && preg_match('/\\b(jquery|bootstrap|react|vue|angular)\\b/i', $indexView . $createView . $showView) !== 1,
                ],
                'security' => [
                    'routes_keep_auth_and_permissions' => $this->containsAll($routes, [
                        "'/tickets/productos'",
                        "'/tickets/productos/crear'",
                        "'/tickets/productos/{id}'",
                        "tickets_productos.ver",
                        "tickets_productos.crear",
                        "tickets_productos.resolver",
                        "tickets_productos.cancelar",
                        "AuthMiddleware",
                        "PermissionMiddleware",
                    ]),
                    'csrf_still_present' =>
                        str_contains($create->body(), 'name="_token"')
                        && substr_count($show->body(), 'name="_token"') >= 3,
                    'views_use_escape_helper' =>
                        str_contains($indexView, '<?= e(')
                        && str_contains($createView, '<?= e(')
                        && str_contains($showView, '<?= e('),
                    'no_internal_paths_exposed' => !$this->containsAny(
                        $index->body() . $create->body() . $show->body(),
                        ['BASE_PATH', 'C:\\', '/var/', 'storage/private', 'storage/uploads']
                    ),
                ],
                'functional_regression_markers' => [
                    'empresa_almacen_fields_present' =>
                        str_contains($create->body(), 'data-company-select')
                        && str_contains($create->body(), 'data-warehouse-select'),
                    'multipartida_present' =>
                        str_contains($create->body(), 'data-partidas-section')
                        && str_contains($create->body(), 'data-add-partida'),
                    'decimal_weight_kept' =>
                        str_contains($create->body(), 'name="partidas[')
                        && str_contains($create->body(), 'step="0.001"'),
                    'runtime_attachments_present' => str_contains($show->body(), 'enctype="multipart/form-data"'),
                    'approval_capture_present' =>
                        str_contains($show->body(), 'clave_autorizada')
                        && str_contains($show->body(), 'descripcion_autorizada'),
                    'mail_placeholder_kept' => str_contains($show->body(), 'Reenvío de correo pendiente de fase posterior.'),
                    'no_attachment_download_preview' =>
                        !str_contains($show->body(), 'Descargar adjunto')
                        && !str_contains($show->body(), 'Vista previa'),
                ],
                'guardrails' => $this->guardrails($countsBefore),
            ];
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $this->closeSession();
        }

        $countsAfter = $this->operationalCounts();
        $results['cleanup'] = [
            'transaction_rolled_back' => $countsBefore === $countsAfter,
            'no_operational_counts_changed' => $countsBefore === $countsAfter,
        ];

        if (!$this->allTrue($results)) {
            throw new RuntimeException(
                'TP-PARTIDAS-ESTADOS-LAYOUT-ERP-1 assertions failed: '
                . json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        return [
            'database' => $expectedDatabase,
            'base_migration_state' => $baseMigrationState,
            'approval_migration_state' => $approvalMigrationState,
            'routes' => [
                'GET /tickets/productos',
                'GET /tickets/productos/crear',
                'GET /tickets/productos/{id}',
            ],
            'layout' => 'app/Views/layouts/app.php',
            'cases' => $results,
            'operational_counts_before' => $countsBefore,
            'operational_counts_after' => $countsAfter,
            'cleanup' => 'transaction_rolled_back_no_operational_data_written',
        ];
    }

    /**
     * @param array{user_id: int, username: string, email: string, empresa_id: int, almacen_id: int} $fixture
     * @return array{AuthService, CsrfTokenService, Router}
     */
    private function stack(array $fixture, bool $withUser = true): array
    {
        $this->closeSession();
        session_save_path(sys_get_temp_dir());
        session_name('TP_LAYOUT_' . bin2hex(random_bytes(4)));

        if (!session_start()) {
            throw new RuntimeException('Unable to start layout test session.');
        }

        $_SESSION = [];

        if ($withUser) {
            $_SESSION['auth_user'] = [
                'user_id' => $fixture['user_id'],
                'username' => $fixture['username'],
                'email' => $fixture['email'],
            ];
            $_SESSION[ScopeContextService::COMPANY_SESSION_KEY] = $fixture['empresa_id'];
            $_SESSION[ScopeContextService::WAREHOUSE_SESSION_KEY] = $fixture['almacen_id'];
        }

        $connection = $GLOBALS['tp_product_ticket_layout_connection'];
        $session = new Session([]);
        $auth = new AuthService(new UserRepository($connection), $session);
        $csrf = new CsrfTokenService($session);
        $permissions = new PermissionService(new PermissionRepository($connection));
        $scopeContext = new ScopeContextService(
            new UserScopeService(new ScopeRepository($connection)),
            $session
        );
        $controller = new ProductRequestTicketController(
            $auth,
            $this->service(),
            $permissions,
            new ProductRequestTicketRepository($connection),
            $this->config(),
            $scopeContext,
            $csrf
        );
        $authMiddleware = new AuthMiddleware($auth);
        $router = new Router();
        $ticketMiddleware = static fn (string $permission): array => [
            $authMiddleware,
            new PermissionMiddleware($auth, $permissions, $permission),
        ];

        $router->get(
            '/tickets/productos',
            static fn (Request $request) => $controller->index($request),
            $ticketMiddleware('tickets_productos.ver')
        );
        $router->get(
            '/tickets/productos/crear',
            static fn (Request $request) => $controller->create($request),
            $ticketMiddleware('tickets_productos.crear')
        );
        $router->get(
            '/tickets/productos/{id}',
            static fn (Request $request, array $params) => $controller->show($request, $params),
            $ticketMiddleware('tickets_productos.ver')
        );

        return [$auth, $csrf, $router];
    }

    /**
     * @param array{username: string} $fixture
     */
    private function login(AuthService $auth, array $fixture): void
    {
        if ($auth->user() === null || $auth->user()['username'] !== $fixture['username']) {
            throw new RuntimeException('Layout test user was not authenticated.');
        }
    }

    private function service(): ProductRequestTicketService
    {
        return new ProductRequestTicketService(
            new ProductRequestTicketRepository($GLOBALS['tp_product_ticket_layout_connection'])
        );
    }

    private function config(): Config
    {
        $config = $GLOBALS['tp_product_ticket_layout_config'] ?? null;

        if (!$config instanceof Config) {
            throw new RuntimeException('Layout test config is missing.');
        }

        return $config;
    }

    /**
     * @return array{user_id: int, username: string, email: string, empresa_id: int, almacen_id: int}
     */
    private function fixture(): array
    {
        $userId = $this->createUser();
        $this->assignAdminRole($userId);
        $companyId = $this->createCompany($userId);
        $warehouseId = $this->createWarehouse($companyId, $userId);
        $this->assignScope($userId, $companyId, $warehouseId);

        return [
            'user_id' => $userId,
            'username' => 'qa_tp_layout_1',
            'email' => 'qa_tp_layout_1@example.test',
            'empresa_id' => $companyId,
            'almacen_id' => $warehouseId,
        ];
    }

    private function createUser(): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO usuarios (username, email, password_hash, activo, creado_en)
             VALUES (:username, :email, :password_hash, 1, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            'username' => 'qa_tp_layout_1',
            'email' => 'qa_tp_layout_1@example.test',
            'password_hash' => self::PASSWORD_HASH,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function assignAdminRole(int $userId): void
    {
        $roleId = $this->activeAdminRoleId();

        if ($roleId < 1) {
            throw new RuntimeException('ADMIN role is required for TP-PARTIDAS-ESTADOS-LAYOUT-ERP-1.');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO usuario_roles (usuario_id, rol_id, activo)
             VALUES (:usuario_id, :rol_id, 1)'
        );
        $statement->execute(['usuario_id' => $userId, 'rol_id' => $roleId]);
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
            'codigo' => 'QATPLAY',
            'nombre' => 'Empresa QA Layout',
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
            'codigo' => 'LAY',
            'nombre' => 'Almacén QA Layout',
            'creado_por' => $userId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function assignScope(int $userId, int $companyId, int $warehouseId): void
    {
        $company = $this->pdo->prepare(
            'INSERT INTO usuario_empresas (usuario_id, empresa_id, activo, creado_por)
             VALUES (:usuario_id, :empresa_id, 1, :creado_por)'
        );
        $company->execute([
            'usuario_id' => $userId,
            'empresa_id' => $companyId,
            'creado_por' => $userId,
        ]);

        $warehouse = $this->pdo->prepare(
            'INSERT INTO usuario_almacenes (usuario_id, empresa_id, almacen_id, activo, creado_por)
             VALUES (:usuario_id, :empresa_id, :almacen_id, 1, :creado_por)'
        );
        $warehouse->execute([
            'usuario_id' => $userId,
            'empresa_id' => $companyId,
            'almacen_id' => $warehouseId,
            'creado_por' => $userId,
        ]);
    }

    private function hasLayout(string $html): bool
    {
        return str_contains($html, 'class="app-shell"')
            && str_contains($html, 'class="app-sidebar"')
            && str_contains($html, 'class="app-topbar"')
            && str_contains($html, 'id="main-content"')
            && str_contains($html, '/logout');
    }

    private function isContentView(string $view): bool
    {
        return !str_contains($view, '<!doctype')
            && !str_contains($view, '<html')
            && !str_contains($view, '<body')
            && !str_contains($view, '<main class="app-main"');
    }

    private function shellCount(string $view): int
    {
        return substr_count($view, 'app-sidebar')
            + substr_count($view, 'app-topbar')
            + substr_count($view, 'app-shell');
    }

    /**
     * @param array<string, int> $countsBefore
     * @return array<string, bool>
     */
    private function guardrails(array $countsBefore): array
    {
        $text = $this->read('app/Http/Controllers/ProductRequestTicketController.php')
            . $this->read('app/Views/tickets/productos/index.php')
            . $this->read('app/Views/tickets/productos/create.php')
            . $this->read('app/Views/tickets/productos/show.php')
            . $this->read('routes/web.php')
            . $this->read('bootstrap/app.php');
        $countsAfter = $this->operationalCounts();

        return [
            'no_real_email_send' => preg_match('/\\bmail\\s*\\(|PHPMailer|smtp|send\\s*\\(/i', $text) !== 1,
            'no_new_routes_for_phase' => !$this->containsAny($this->read('routes/web.php'), [
                '/tickets/productos/layout',
                '/tickets/productos/correos',
                '/tickets/productos/descargar',
            ]),
            'no_seed_modified_for_phase' => $this->noOpenPathExcept('database/seeds/', [
                'database/seeds/tp_partidas_estados_correo_config_1_seed_permissions.php',
            ]),
            'no_migration_modified_for_phase' => $this->noOpenPathExcept('database/migrations/', [
                'database/migrations/tp_partidas_estados_correo_config_1_001_create_mail_configuration.php',
            ]),
            'mail_config_not_modified' => $this->noOpenPath('config/mail.php'),
            'package_files_not_modified' =>
                $this->noOpenPath('package.json')
                && $this->noOpenPath('package-lock.json'),
            'outbox_files_not_modified' =>
                is_file(BASE_PATH . '/app/Domain/Tickets/ProductTicketEmailNotificationService.php')
                && (
                    $this->noOpenPath('app/Infrastructure/Repositories/ProductTicketEmailOutboxRepository.php')
                    || is_file(BASE_PATH . '/database/tests/tickets_productos_partidas_estados_correo_procesador_1_test.php')
                ),
            'product_service_not_modified' => is_file(
                BASE_PATH . '/database/tests/tickets_productos_partidas_estados_correo_orquestacion_1_test.php'
            ),
            'no_product_created' => $countsBefore['productos'] === $countsAfter['productos'],
            'no_price_created' => $countsBefore['producto_precios'] === $countsAfter['producto_precios'],
            'no_stock_created' => $countsBefore['existencias_producto'] === $countsAfter['existencias_producto'],
            'no_inventory_created' => $countsBefore['inventario_existencias'] === $countsAfter['inventario_existencias'],
            'no_purchase_created' => $countsBefore['compras'] === $countsAfter['compras'],
            'no_supplier_created' => $countsBefore['proveedores'] === $countsAfter['proveedores'],
        ];
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
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table'
        );
        $statement->execute(['table' => $table]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function noOpenPath(string $path): bool
    {
        exec('git status --short -- ' . escapeshellarg($path), $output, $code);

        return $code === 0 && $output === [];
    }

    /**
     * @param list<string> $allowedPaths
     */
    private function noOpenPathExcept(string $path, array $allowedPaths): bool
    {
        exec('git status --short -- ' . escapeshellarg($path), $output, $code);

        if ($code !== 0) {
            return false;
        }

        foreach ($output as $line) {
            $openPath = str_replace('\\', '/', trim(substr($line, 3)));

            if (!in_array($openPath, $allowedPaths, true)) {
                return false;
            }
        }

        return true;
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
     * @param list<string> $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
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
