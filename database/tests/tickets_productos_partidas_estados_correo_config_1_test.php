<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Request;
use App\Core\Router;
use App\Core\Session;
use App\Domain\Auth\AuthService;
use App\Domain\Mail\MailConfigurationService;
use App\Domain\Scope\ScopeContextService;
use App\Domain\Scope\UserScopeService;
use App\Domain\Security\PermissionService;
use App\Http\Controllers\MailConfigurationController;
use App\Http\Middlewares\AuthMiddleware;
use App\Http\Middlewares\CsrfMiddleware;
use App\Http\Middlewares\PermissionMiddleware;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Database\Migration;
use App\Infrastructure\Database\MigrationRunner;
use App\Infrastructure\Database\Seed;
use App\Infrastructure\Repositories\MailConfigurationRepository;
use App\Infrastructure\Repositories\PermissionRepository;
use App\Infrastructure\Repositories\ScopeRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Support\Security\CsrfTokenService;

require_once BASE_PATH . '/app/Support/Security/helpers.php';

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
            throw new RuntimeException('Unexpected active database for TP-PARTIDAS-ESTADOS-CORREO-CONFIG-1.');
        }

        $migration = require BASE_PATH
            . '/database/migrations/tp_partidas_estados_correo_config_1_001_create_mail_configuration.php';
        $seed = require BASE_PATH
            . '/database/seeds/tp_partidas_estados_correo_config_1_seed_permissions.php';

        if (!$migration instanceof Migration || !$seed instanceof Seed) {
            throw new RuntimeException('TP-PARTIDAS-ESTADOS-CORREO-CONFIG-1 dependencies are invalid.');
        }

        $runner = new MigrationRunner($pdo);
        $migrationState = $runner->migrate($migration);
        $seed->run($pdo);
        $countsBefore = $this->operationalCounts();
        $results = [];

        try {
            $pdo->beginTransaction();
            $fixture = $this->fixture();
            $service = $this->service();

            $accountId = $service->saveAccount([
                'nombre' => 'Tickets QA',
                'from_email' => 'tickets@example.test',
                'from_name' => 'SoporteGR Tickets',
                'reply_to_email' => 'respuestas@example.test',
                'smtp_host' => 'smtp.example.test',
                'smtp_port' => '587',
                'smtp_encryption' => 'tls',
                'smtp_username' => 'tickets@example.test',
                'smtp_secret_ref' => 'MAIL_TICKETS_PRIMARY_PASSWORD',
                'activo' => '1',
            ]);
            $service->saveRules([
                'rules' => [
                    'TICKET_CREADO' => [
                        'enviar_solicitante' => '1',
                        'enviar_responsables' => '1',
                        'to' => "qa.to@example.test\nqa.alt@example.test",
                        'cc' => 'qa.cc@example.test',
                        'bcc' => 'qa.bcc@example.test',
                        'activo' => '1',
                    ],
                    'PARTIDA_RECHAZADA' => [
                        'enviar_solicitante' => '1',
                        'enviar_responsables' => '0',
                        'cc' => '',
                        'bcc' => '',
                        'activo' => '0',
                    ],
                ],
            ]);

            [$auth, $csrf, $router] = $this->stack($fixture);
            $index = $router->dispatch(new Request('GET', '/admin/correo'));
            $saveAccount = $router->dispatch(new Request('POST', '/admin/correo/cuentas', [], [
                '_token' => $csrf->token(),
                'nombre' => 'Tickets QA guardado',
                'from_email' => 'tickets@example.test',
                'from_name' => 'SoporteGR Tickets',
                'reply_to_email' => 'respuestas@example.test',
                'smtp_host' => 'smtp.example.test',
                'smtp_port' => '465',
                'smtp_encryption' => 'ssl',
                'smtp_username' => 'tickets@example.test',
                'smtp_secret_ref' => 'MAIL_TICKETS_PRIMARY_PASSWORD',
                'activo' => '1',
            ]));
            $saveRules = $router->dispatch(new Request('POST', '/admin/correo/reglas', [], [
                '_token' => $csrf->token(),
                'rules' => [
                    'TICKET_CREADO' => [
                        'enviar_solicitante' => '1',
                        'enviar_responsables' => '1',
                        'to' => 'qa.to@example.test',
                        'cc' => 'qa.cc@example.test',
                        'bcc' => 'qa.bcc@example.test',
                        'activo' => '1',
                    ],
                ],
            ]));
            $missingCsrf = $router->dispatch(new Request('POST', '/admin/correo/cuentas', [], []));
            [$guestAuth, $guestCsrf, $guestRouter] = $this->stack($fixture, false);
            $guest = $guestRouter->dispatch(new Request('GET', '/admin/correo'));

            $account = $this->account($accountId);
            $rule = $this->rule('TICKET_CREADO');
            $controller = $this->read('app/Http/Controllers/MailConfigurationController.php');
            $serviceFile = $this->read('app/Domain/Mail/MailConfigurationService.php');
            $repositoryFile = $this->read('app/Infrastructure/Repositories/MailConfigurationRepository.php');
            $view = $this->read('app/Views/admin/mail/index.php');
            $routes = $this->read('routes/web.php');
            $bootstrap = $this->read('bootstrap/app.php');
            $layout = $this->read('app/Views/layouts/app.php');

            $results = [
                'schema' => $this->schemaCases($expectedDatabase),
                'account' => [
                    'created' => $account !== null,
                    'from_email_normalized' => ($account['from_email'] ?? '') === 'tickets@example.test',
                    'reply_to_saved' => ($account['reply_to_email'] ?? '') === 'respuestas@example.test',
                    'smtp_port_saved' => (int) ($account['smtp_port'] ?? 0) === 465,
                    'smtp_encryption_saved' => ($account['smtp_encryption'] ?? '') === 'ssl',
                    'secret_ref_saved' => ($account['smtp_secret_ref'] ?? '') === 'MAIL_TICKETS_PRIMARY_PASSWORD',
                    'does_not_have_password_column' => !$this->columnExists($expectedDatabase, 'mail_accounts', 'smtp_password'),
                ],
                'rules' => [
                    'event_rule_created' => $rule !== null,
                    'requester_enabled' => (int) ($rule['enviar_solicitante'] ?? 0) === 1,
                    'responsibles_enabled' => (int) ($rule['enviar_responsables'] ?? 0) === 1,
                    'cc_saved' => str_contains((string) ($rule['cc_json'] ?? ''), 'qa.cc@example.test'),
                    'bcc_saved' => str_contains((string) ($rule['bcc_json'] ?? ''), 'qa.bcc@example.test'),
                    'rule_active' => (int) ($rule['activo'] ?? 0) === 1,
                    'all_events_supported' => $this->allEventsSupported(),
                ],
                'validation' => [
                    'invalid_from_email_rejected' => $this->rejects(fn () => $service->saveAccount($this->validAccount(['from_email' => 'mal']))),
                    'invalid_reply_to_rejected' => $this->rejects(fn () => $service->saveAccount($this->validAccount(['reply_to_email' => 'mal']))),
                    'invalid_port_rejected' => $this->rejects(fn () => $service->saveAccount($this->validAccount(['smtp_port' => '70000']))),
                    'invalid_encryption_rejected' => $this->rejects(fn () => $service->saveAccount($this->validAccount(['smtp_encryption' => 'starttls']))),
                    'plain_password_rejected' => $this->rejects(fn () => $service->saveAccount($this->validAccount(['smtp_password' => 'super-secret']))),
                    'invalid_secret_ref_rejected' => $this->rejects(fn () => $service->saveAccount($this->validAccount(['smtp_secret_ref' => 'mail password']))),
                    'invalid_recipient_rejected' => $this->rejects(fn () => $service->saveRules(['rules' => ['TICKET_CREADO' => ['to' => 'bad-email']]])),
                    'duplicate_to_cc_bcc_rejected' => $this->rejects(fn () => $service->saveRules(['rules' => ['TICKET_CREADO' => ['to' => 'dup@example.test', 'cc' => 'dup@example.test']]])),
                ],
                'http_ui' => [
                    'index_200' => $index->status() === 200,
                    'save_account_redirect' => $saveAccount->status() === 302,
                    'save_rules_redirect' => $saveRules->status() === 302,
                    'missing_csrf_419' => $missingCsrf->status() === 419,
                    'guest_redirects_to_login' => $guest->status() === 302,
                    'layout_erp_present' => str_contains($index->body(), 'class="app-sidebar"')
                        && str_contains($index->body(), 'class="app-topbar"'),
                    'secret_value_not_rendered' => !str_contains($index->body(), 'super-secret')
                        && !str_contains($index->body(), 'password_hash'),
                    'secret_status_only' => str_contains($index->body(), 'Secreto configurado'),
                    'mail_navigation_visible' => str_contains($index->body(), 'Correo'),
                    'forms_have_csrf' => substr_count($index->body(), 'name="_token"') >= 2,
                ],
                'security_contract' => [
                    'routes_have_auth_permission' => $this->containsAll($routes, [
                        "'/admin/correo'",
                        "'/admin/correo/cuentas'",
                        "'/admin/correo/reglas'",
                        'PermissionMiddleware',
                        'configuracion.correo.administrar',
                    ]),
                    'bootstrap_registers_controller' => str_contains($bootstrap, 'MailConfigurationController'),
                    'layout_has_navigation_flag' => str_contains($layout, 'canAccessMailConfiguration'),
                    'repository_uses_prepared_statements' => str_contains($repositoryFile, 'prepare('),
                    'view_escapes_output' => str_contains($view, '<?= e('),
                    'no_secret_output' => !$this->containsAny($view . $controller . $repositoryFile, [
                        'smtp_password',
                        'password_hash',
                        'getenv(',
                        'Env::',
                    ]),
                ],
                'mail_runtime_absence' => [
                    'no_smtp_send' =>
                        preg_match('/\bPHPMailer\b|\bmail\s*\(|\bfsockopen\s*\(|\bstream_socket_client\s*\(|\bSwift_Mailer\b/', $serviceFile . $controller . $repositoryFile) !== 1,
                    'outbox_not_integrated' => !str_contains($serviceFile . $controller, 'ProductTicketEmailOutboxService'),
                    'env_not_modified_by_runtime' => !str_contains($serviceFile . $controller . $repositoryFile, '.env'),
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
            'transaction_rolled_back' => true,
            'no_product_created' => $countsBefore['productos'] === $countsAfter['productos'],
            'no_price_created' => $countsBefore['producto_precios'] === $countsAfter['producto_precios'],
            'no_stock_created' => $countsBefore['existencias_producto'] === $countsAfter['existencias_producto'],
            'no_inventory_created' => $countsBefore['movimientos_inventario'] === $countsAfter['movimientos_inventario'],
            'no_purchase_created' => $countsBefore['compras'] === $countsAfter['compras'],
            'no_supplier_created' => $countsBefore['proveedores'] === $countsAfter['proveedores'],
        ];

        if (!$this->allTrue($results)) {
            throw new RuntimeException(
                'TP-PARTIDAS-ESTADOS-CORREO-CONFIG-1 assertions failed: '
                . json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        return [
            'database' => $expectedDatabase,
            'migration_state' => $migrationState,
            'tables' => ['mail_accounts', 'tickets_productos_correo_reglas'],
            'permission' => MailConfigurationService::PERMISSION,
            'events' => MailConfigurationService::EVENTS,
            'secret_ref_strategy' => 'smtp_secret_ref stores only the environment variable name; secret value stays outside DB.',
            'cases' => $results,
            'operational_counts_before' => $countsBefore,
            'operational_counts_after' => $countsAfter,
            'cleanup' => 'transaction_rolled_back_no_operational_data_written',
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function schemaCases(string $database): array
    {
        $checks = implode("\n", $this->checks($database));

        return [
            'mail_accounts_exists' => $this->tableExists($database, 'mail_accounts'),
            'rules_exists' => $this->tableExists($database, 'tickets_productos_correo_reglas'),
            'mail_accounts_engine_innodb' => $this->engine($database, 'mail_accounts') === 'InnoDB',
            'rules_engine_innodb' => $this->engine($database, 'tickets_productos_correo_reglas') === 'InnoDB',
            'account_fields_exist' => $this->columnsContain($database, 'mail_accounts', [
                'id',
                'codigo',
                'nombre',
                'from_email',
                'from_name',
                'reply_to_email',
                'smtp_host',
                'smtp_port',
                'smtp_encryption',
                'smtp_username',
                'smtp_secret_ref',
                'activo',
                'created_at',
                'updated_at',
            ]),
            'rule_fields_exist' => $this->columnsContain($database, 'tickets_productos_correo_reglas', [
                'id',
                'evento',
                'mail_account_id',
                'enviar_solicitante',
                'enviar_responsables',
                'to_json',
                'cc_json',
                'bcc_json',
                'activo',
                'created_at',
                'updated_at',
            ]),
            'account_unique_index_exists' => in_array('uq_mail_accounts_codigo', $this->indexes($database, 'mail_accounts'), true),
            'rule_unique_event_index_exists' => in_array(
                'uq_tickets_productos_correo_reglas_evento',
                $this->indexes($database, 'tickets_productos_correo_reglas'),
                true
            ),
            'rule_fk_exists' => in_array(
                'mail_account_id->mail_accounts.id',
                $this->foreignKeys($database, 'tickets_productos_correo_reglas'),
                true
            ),
            'checks_include_email_port_encryption_secret_ref' =>
                str_contains($checks, 'from_email')
                && str_contains($checks, 'smtp_port')
                && str_contains($checks, 'smtp_encryption')
                && str_contains($checks, 'smtp_secret_ref'),
            'no_smtp_password_column' => !$this->columnExists($database, 'mail_accounts', 'smtp_password'),
        ];
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function validAccount(array $override = []): array
    {
        return array_replace([
            'nombre' => 'Tickets QA',
            'from_email' => 'tickets@example.test',
            'from_name' => 'SoporteGR Tickets',
            'reply_to_email' => 'respuestas@example.test',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => '587',
            'smtp_encryption' => 'tls',
            'smtp_username' => 'tickets@example.test',
            'smtp_secret_ref' => 'MAIL_TICKETS_PRIMARY_PASSWORD',
            'activo' => '1',
        ], $override);
    }

    /**
     * @return array<string, int|string>
     */
    private function fixture(): array
    {
        $userId = $this->createUser();
        $permissionId = $this->permissionId(MailConfigurationService::PERMISSION);
        $roleId = $this->createRole();
        $this->assignRole($userId, $roleId);
        $this->assignPermission($roleId, $permissionId);

        return [
            'user_id' => $userId,
            'username' => 'qa_mail_config',
            'email' => 'qa.mail.config@example.test',
        ];
    }

    /**
     * @param array<string, int|string> $fixture
     * @return array{AuthService, CsrfTokenService, Router}
     */
    private function stack(array $fixture, bool $withUser = true): array
    {
        $this->closeSession();
        session_save_path(sys_get_temp_dir());
        session_name('TP_MAILCFG_' . bin2hex(random_bytes(4)));

        if (!session_start()) {
            throw new RuntimeException('Unable to start mail config test session.');
        }

        $_SESSION = [];

        if ($withUser) {
            $_SESSION['auth_user'] = [
                'user_id' => $fixture['user_id'],
                'username' => $fixture['username'],
                'email' => $fixture['email'],
            ];
        }

        $connection = $GLOBALS['tp_mail_config_connection'];
        $session = new Session([]);
        $auth = new AuthService(new UserRepository($connection), $session);
        $csrf = new CsrfTokenService($session);
        $permissions = new PermissionService(new PermissionRepository($connection));
        $controller = new MailConfigurationController(
            $this->config(),
            $auth,
            $permissions,
            new ScopeContextService(new UserScopeService(new ScopeRepository($connection)), $session),
            $csrf,
            $this->service()
        );
        $router = new Router();
        $router->middleware(new CsrfMiddleware($csrf));
        $middleware = [
            new AuthMiddleware($auth),
            new PermissionMiddleware($auth, $permissions, MailConfigurationService::PERMISSION),
        ];

        $router->get('/admin/correo', static fn (Request $request) => $controller->index($request), $middleware);
        $router->post('/admin/correo/cuentas', static fn (Request $request) => $controller->saveAccount($request), $middleware);
        $router->post('/admin/correo/reglas', static fn (Request $request) => $controller->saveRules($request), $middleware);

        return [$auth, $csrf, $router];
    }

    private function service(): MailConfigurationService
    {
        return new MailConfigurationService(
            new MailConfigurationRepository($GLOBALS['tp_mail_config_connection'])
        );
    }

    private function config(): Config
    {
        $config = $GLOBALS['tp_mail_config_config'] ?? null;

        if (!$config instanceof Config) {
            throw new RuntimeException('Mail configuration test config is missing.');
        }

        return $config;
    }

    private function createUser(): int
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO usuarios (
                username,
                email,
                password_hash,
                activo
            ) VALUES (
                'qa_mail_config',
                'qa.mail.config@example.test',
                :password_hash,
                1
            )
            SQL
        );
        $statement->execute(['password_hash' => self::PASSWORD_HASH]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createRole(): int
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            INSERT INTO roles (codigo, nombre, descripcion, activo)
            VALUES ('QA_MAIL_CONFIG', 'QA Mail Config', 'Rol temporal QA correo config', 1)
            SQL
        );
        $statement->execute();

        return (int) $this->pdo->lastInsertId();
    }

    private function assignRole(int $userId, int $roleId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO usuario_roles (usuario_id, rol_id, activo) VALUES (:usuario_id, :rol_id, 1)'
        );
        $statement->execute(['usuario_id' => $userId, 'rol_id' => $roleId]);
    }

    private function assignPermission(int $roleId, int $permissionId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO rol_permisos (rol_id, permiso_id, activo) VALUES (:rol_id, :permiso_id, 1)'
        );
        $statement->execute(['rol_id' => $roleId, 'permiso_id' => $permissionId]);
    }

    private function permissionId(string $code): int
    {
        $statement = $this->pdo->prepare('SELECT id FROM permisos WHERE codigo = :codigo LIMIT 1');
        $statement->execute(['codigo' => $code]);
        $id = $statement->fetchColumn();

        if ($id === false) {
            throw new RuntimeException('Mail configuration permission is missing.');
        }

        return (int) $id;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function account(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM mail_accounts WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rule(string $event): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM tickets_productos_correo_reglas WHERE evento = :evento LIMIT 1');
        $statement->execute(['evento' => $event]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function rejects(callable $callback): bool
    {
        try {
            $callback();
        } catch (InvalidArgumentException|PDOException) {
            return true;
        }

        return false;
    }

    private function allEventsSupported(): bool
    {
        foreach (MailConfigurationService::EVENTS as $event) {
            $this->service()->saveRules(['rules' => [$event => ['activo' => '1']]]);
        }

        $statement = $this->pdo->query('SELECT COUNT(*) FROM tickets_productos_correo_reglas');

        return (int) $statement->fetchColumn() === count(MailConfigurationService::EVENTS);
    }

    /**
     * @return array<string, int>
     */
    private function operationalCounts(): array
    {
        return [
            'productos' => $this->countTable('productos'),
            'producto_precios' => $this->countTable('producto_precios'),
            'existencias_producto' => $this->countTable('existencias_producto'),
            'movimientos_inventario' => $this->countTable('movimientos_inventario'),
            'compras' => $this->countTable('compras'),
            'proveedores' => $this->countTable('proveedores'),
        ];
    }

    /**
     * @param array<string, int> $countsBefore
     * @return array<string, bool>
     */
    private function guardrails(array $countsBefore): array
    {
        $paths = [
            'ProductRequestTicketService.php' => 'app/Domain/Tickets/ProductRequestTicketService.php',
            'ProductTicketEmailOutboxService.php' => 'app/Domain/Tickets/ProductTicketEmailOutboxService.php',
            'ProductTicketEmailOutboxRepository.php' => 'app/Infrastructure/Repositories/ProductTicketEmailOutboxRepository.php',
            'config/mail.php' => 'config/mail.php',
            'package.json' => 'package.json',
            'package-lock.json' => 'package-lock.json',
        ];
        $diff = trim((string) shell_exec('git diff --name-only'));

        return [
            'no_product_created' => $countsBefore['productos'] === $this->countTable('productos'),
            'no_price_created' => $countsBefore['producto_precios'] === $this->countTable('producto_precios'),
            'no_stock_created' => $countsBefore['existencias_producto'] === $this->countTable('existencias_producto'),
            'no_inventory_created' => $countsBefore['movimientos_inventario'] === $this->countTable('movimientos_inventario'),
            'no_purchase_created' => $countsBefore['compras'] === $this->countTable('compras'),
            'no_supplier_created' => $countsBefore['proveedores'] === $this->countTable('proveedores'),
            'ticket_runtime_service_not_modified' => !str_contains($diff, $paths['ProductRequestTicketService.php'])
                && is_file(BASE_PATH . '/app/Domain/Tickets/ProductTicketEmailNotificationService.php'),
            'outbox_service_not_modified' => !str_contains($diff, $paths['ProductTicketEmailOutboxService.php'])
                && is_file(BASE_PATH . '/database/tests/tickets_productos_partidas_estados_correo_orquestacion_1_test.php'),
            'outbox_repository_not_modified' => !str_contains($diff, $paths['ProductTicketEmailOutboxRepository.php'])
                || is_file(BASE_PATH . '/database/tests/tickets_productos_partidas_estados_correo_procesador_1_test.php'),
            'mail_config_file_not_modified' => !str_contains($diff, $paths['config/mail.php']),
            'package_files_not_modified' => trim((string) shell_exec(
                'git diff --name-only -- package.json package-lock.json'
            )) === '',
        ];
    }

    private function countTable(string $table): int
    {
        if (!$this->tableExists((string) $this->pdo->query('SELECT DATABASE()')->fetchColumn(), $table)) {
            return 0;
        }

        $safe = str_replace('`', '``', $table);
        $statement = $this->pdo->query('SELECT COUNT(*) FROM `' . $safe . '`');

        return (int) $statement->fetchColumn();
    }

    private function tableExists(string $database, string $table): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :db AND table_name = :table'
        );
        $statement->execute(['db' => $database, 'table' => $table]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function columnExists(string $database, string $table, string $column): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = :db AND table_name = :table AND column_name = :column'
        );
        $statement->execute(['db' => $database, 'table' => $table, 'column' => $column]);

        return (int) $statement->fetchColumn() === 1;
    }

    /**
     * @param list<string> $columns
     */
    private function columnsContain(string $database, string $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (!$this->columnExists($database, $table, $column)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function indexes(string $database, string $table): array
    {
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT index_name FROM information_schema.statistics WHERE table_schema = :db AND table_name = :table'
        );
        $statement->execute(['db' => $database, 'table' => $table]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return list<string>
     */
    private function foreignKeys(string $database, string $table): array
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT CONCAT(column_name, '->', referenced_table_name, '.', referenced_column_name)
            FROM information_schema.key_column_usage
            WHERE table_schema = :db
              AND table_name = :table
              AND referenced_table_name IS NOT NULL
            SQL
        );
        $statement->execute(['db' => $database, 'table' => $table]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return list<string>
     */
    private function checks(string $database): array
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
            SELECT check_clause
            FROM information_schema.check_constraints
            WHERE constraint_schema = :db_mail
              AND constraint_name LIKE 'chk_mail_accounts_%'
               OR constraint_schema = :db_rules
              AND constraint_name LIKE 'chk_tickets_productos_correo_reglas_%'
            SQL
        );
        $statement->execute(['db_mail' => $database, 'db_rules' => $database]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function engine(string $database, string $table): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT engine FROM information_schema.tables WHERE table_schema = :db AND table_name = :table'
        );
        $statement->execute(['db' => $database, 'table' => $table]);
        $engine = $statement->fetchColumn();

        return $engine === false ? null : (string) $engine;
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

    private function read(string $path): string
    {
        $content = file_get_contents(BASE_PATH . '/' . $path);

        if (!is_string($content)) {
            throw new RuntimeException('Unable to read ' . $path);
        }

        return $content;
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
            $_SESSION = [];
            session_write_close();
        }
    }
};
