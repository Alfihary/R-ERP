<?php

declare(strict_types=1);

use App\Core\Request;
use App\Core\Session;
use App\Domain\Auth\AuthService;
use App\Domain\Tickets\ProductRequestTicketService;
use App\Domain\Tickets\ProductRequestTicketValidationException;
use App\Http\Controllers\ProductRequestTicketController;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Database\Migration;
use App\Infrastructure\Database\MigrationRunner;
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
        $this->repository = new ProductRequestTicketRepository($GLOBALS['tp_product_ticket_comentarios_connection']);
        $this->service = new ProductRequestTicketService($this->repository);

        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
            throw new RuntimeException('Unexpected active database for TP-PARTIDAS-ESTADOS-COMENTARIOS-1.');
        }

        $migration = require BASE_PATH
            . '/database/migrations/tp_partidas_estados_db_1_001_create_ticket_product_tables.php';

        if (!$migration instanceof Migration) {
            throw new RuntimeException('TP-PARTIDAS-ESTADOS-COMENTARIOS-1 dependencies are invalid.');
        }

        $runner = new MigrationRunner($pdo);
        $migrationState = $runner->migrate($migration);
        $before = $this->operationalCounts();
        $results = [];

        try {
            $pdo->beginTransaction();
            $fixture = $this->fixture();
            $ticket = $this->createTicket($fixture);
            $otherTicket = $this->createTicket($fixture);
            $partida = $this->firstPartida((int) $ticket['id']);
            $foreignPartida = $this->firstPartida((int) $otherTicket['id']);
            $initialTicketState = (string) $ticket['estado'];
            $initialPartidaState = (string) $partida['estado'];

            $general = $this->service->agregarComentario(
                (int) $ticket['id'],
                null,
                'Comentario general documental.',
                $fixture['user_id']
            );
            $line = $this->service->agregarComentario(
                (int) $ticket['id'],
                (int) $partida['id'],
                'Comentario documental de partida.',
                $fixture['user_id']
            );
            $afterCommentTicket = $this->repository->findTicketById((int) $ticket['id']) ?? [];
            $afterCommentPartida = $this->firstPartida((int) $ticket['id']);
            $comments = $this->repository->listComentarios((int) $ticket['id']);
            $events = $this->repository->listEventos((int) $ticket['id']);
            $controllerResponse = $this->controllerFor($fixture['user_id'])->comment(
                new Request('POST', '/tickets/productos/' . (int) $ticket['id'] . '/comentarios', [], [
                    'comentario' => 'Comentario desde controlador.',
                ]),
                ['id' => (string) $ticket['id']]
            );
            $displayTicket = $this->service->agregarComentario(
                (int) $ticket['id'],
                null,
                '<script>alert(1)</script>',
                $fixture['user_id']
            );

            $results['route_security'] = $this->routeSecurityCases();
            $results['controller_contract'] = $this->controllerCases($controllerResponse);
            $results['service_repository'] = $this->serviceRepositoryCases(
                $ticket,
                $partida,
                $foreignPartida,
                $fixture['user_id'],
                $initialTicketState,
                $initialPartidaState,
                $afterCommentTicket,
                $afterCommentPartida,
                $general,
                $line,
                $comments,
                $events
            );
            $results['show'] = $this->showCases($displayTicket);
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
                'TP-PARTIDAS-ESTADOS-COMENTARIOS-1 assertions failed: '
                . json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        return [
            'database' => $expectedDatabase,
            'migration_state' => $migrationState,
            'route' => 'POST /tickets/productos/{id}/comentarios',
            'controller' => 'app/Http/Controllers/ProductRequestTicketController.php',
            'service' => 'app/Domain/Tickets/ProductRequestTicketService.php',
            'repository' => 'app/Infrastructure/Repositories/ProductRequestTicketRepository.php',
            'view' => 'app/Views/tickets/productos/show.php',
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
    private function routeSecurityCases(): array
    {
        $routes = $this->read('routes/web.php');

        return [
            'comment_route_exists' => str_contains($routes, "'/tickets/productos/{id}/comentarios'"),
            'comment_route_is_post' => str_contains($routes, '$router->post(')
                && str_contains($routes, '->comment($request, $params)'),
            'route_uses_required_permission' => str_contains(
                $routes,
                "\$productTicketMiddleware('tickets_productos.comentarios.crear')"
            ),
            'route_uses_existing_auth_permission_stack' => str_contains($routes, '$productTicketMiddleware = static function')
                && str_contains($routes, 'new PermissionMiddleware($auth, $permissions, $permission)'),
            'csrf_global_middleware_available_for_post' => str_contains($routes, 'CsrfMiddleware')
                || str_contains($this->read('bootstrap/app.php'), 'CsrfMiddleware'),
            'no_public_comment_route' => !str_contains($routes, '/v/{slug}/comentarios'),
            'authorized_attachment_runtime_route_allowed' => str_contains($routes, "'/tickets/productos/{id}/adjuntos'")
                && str_contains($routes, '->attachment($request, $params)')
                && str_contains($routes, "\$productTicketMiddleware('tickets_productos.adjuntos.ver')"),
            'no_email_routes_created' => !str_contains($routes, '/correo'),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function controllerCases(object $response): array
    {
        $controller = $this->read('app/Http/Controllers/ProductRequestTicketController.php');

        return [
            'controller_has_comment_method' => str_contains($controller, 'function comment(Request $request, array $params): Response'),
            'controller_reads_ticket_comment_and_optional_partida' => str_contains($controller, "input('comentario'")
                && str_contains($controller, "input('partida_id'"),
            'controller_calls_service' => str_contains($controller, '->agregarComentario('),
            'controller_redirects_to_detail' => method_exists($response, 'status')
                && $response->status() >= 300
                && $response->status() < 400
                && str_contains($controller, 'return Response::redirect(')
                && str_contains($controller, "'/tickets/productos/' . \$ticketId"),
            'controller_has_no_sql' => preg_match('/\b(SELECT|INSERT|UPDATE|DELETE)\b/i', $controller) !== 1,
            'controller_safe_validation_response' => str_contains($controller, 'actionValidationResponse($exception, $params)'),
        ];
    }

    /**
     * @param array<string, mixed> $ticket
     * @param array<string, mixed> $partida
     * @param array<string, mixed> $foreignPartida
     * @param array<string, mixed> $afterCommentTicket
     * @param array<string, mixed> $afterCommentPartida
     * @param array<string, mixed> $general
     * @param array<string, mixed> $line
     * @param list<array<string, mixed>> $comments
     * @param list<array<string, mixed>> $events
     * @return array<string, bool>
     */
    private function serviceRepositoryCases(
        array $ticket,
        array $partida,
        array $foreignPartida,
        int $userId,
        string $initialTicketState,
        string $initialPartidaState,
        array $afterCommentTicket,
        array $afterCommentPartida,
        array $general,
        array $line,
        array $comments,
        array $events
    ): array {
        $service = $this->read('app/Domain/Tickets/ProductRequestTicketService.php');
        $repository = $this->read('app/Infrastructure/Repositories/ProductRequestTicketRepository.php');

        return [
            'service_has_method' => method_exists($this->service, 'agregarComentario')
                && str_contains($service, 'function agregarComentario('),
            'service_validates_empty_comment' => $this->failsValidation(
                fn () => $this->service->agregarComentario((int) $ticket['id'], null, '   ', $userId),
                'comentario'
            ),
            'service_rejects_foreign_partida' => $this->failsValidation(
                fn () => $this->service->agregarComentario(
                    (int) $ticket['id'],
                    (int) $foreignPartida['id'],
                    'Comentario inválido.',
                    $userId
                ),
                'partida_id'
            ),
            'service_rejects_missing_ticket' => $this->failsValidation(
                fn () => $this->service->agregarComentario(999999999, null, 'Comentario inválido.', $userId),
                'ticket_id'
            ),
            'service_records_comment_event' => str_contains($service, 'COMENTARIO_AGREGADO')
                && in_array('COMENTARIO_AGREGADO', array_column($events, 'evento'), true),
            'repository_has_method' => method_exists($this->repository, 'agregarComentario')
                && str_contains($repository, 'function agregarComentario('),
            'repository_uses_prepared_statements' => str_contains($repository, 'INSERT INTO tickets_productos_comentarios')
                && str_contains($repository, '->prepare(')
                && str_contains($repository, ':comentario'),
            'general_comment_created' => count($comments) >= 2
                && in_array('Comentario general documental.', array_column($comments, 'comentario'), true),
            'line_comment_created' => in_array('Comentario documental de partida.', array_column($comments, 'comentario'), true)
                && ((int) ($line['partidas'][0]['id'] ?? 0) > 0 || count($line['comentarios'] ?? []) >= 2),
            'ticket_state_unchanged' => (string) ($afterCommentTicket['estado'] ?? '') === $initialTicketState,
            'partida_state_unchanged' => (string) ($afterCommentPartida['estado'] ?? '') === $initialPartidaState,
            'no_resolve_cancel_side_effect' => (string) ($afterCommentTicket['estado'] ?? '') === $initialTicketState
                && (string) ($afterCommentPartida['estado'] ?? '') === $initialPartidaState,
            'service_returns_normalized_ticket' => isset($general['comentarios']) && is_array($general['comentarios']),
        ];
    }

    /**
     * @param array<string, mixed> $ticket
     * @return array<string, bool>
     */
    private function showCases(array $ticket): array
    {
        $show = $this->read('app/Views/tickets/productos/show.php');
        $htmlAllowed = $this->renderShow($ticket, true);
        $htmlDenied = $this->renderShow($ticket, false);

        return [
            'show_displays_existing_comments' => str_contains($htmlAllowed, 'Comentario general documental.')
                && str_contains($htmlAllowed, 'Comentario documental de partida.'),
            'show_distinguishes_general_and_line_comments' => str_contains($htmlAllowed, 'Comentario general')
                && str_contains($htmlAllowed, 'Comentarios de partida'),
            'show_form_only_with_permission' => str_contains($htmlAllowed, 'Agregar comentario general')
                && str_contains($htmlAllowed, 'Comentar partida')
                && !str_contains($htmlDenied, 'Agregar comentario general')
                && !str_contains($htmlDenied, 'Comentar partida'),
            'show_keeps_documentary_warning' => str_contains(
                $htmlAllowed,
                'Los comentarios son documentales y no modifican el estado del ticket.'
            ),
            'show_keeps_csrf' => substr_count($htmlAllowed, 'name="_token"') >= 3,
            'outputs_escape_malicious_values' => str_contains($htmlAllowed, '&lt;script&gt;alert(1)&lt;/script&gt;')
                && !str_contains($htmlAllowed, '<script>alert(1)</script>'),
            'show_uses_escape_helper' => str_contains($show, '<?= e('),
            'no_storage_uploads_in_html' => !str_contains($htmlAllowed, 'storage/uploads'),
            'no_physical_paths_in_html' => !str_contains($htmlAllowed, 'C:\\')
                && !str_contains($htmlAllowed, '/var/')
                && !str_contains($htmlAllowed, 'BASE_PATH'),
            'no_sensitive_data_in_html' => !str_contains($htmlAllowed, 'password_hash')
                && !str_contains($htmlAllowed, 'token_hash')
                && !str_contains($htmlAllowed, 'auth_user')
                && !str_contains($htmlAllowed, '$_SESSION')
                && !str_contains($htmlAllowed, 'metadata_json'),
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
            'bootstrap_unchanged_for_phase' => !str_contains(
                $this->read('bootstrap/app.php'),
                'TP-PARTIDAS-ESTADOS-COMENTARIOS-1'
            ),
            'no_migrations_created_or_modified_marker' => !str_contains(
                $this->read('database/migrations/tp_partidas_estados_db_1_001_create_ticket_product_tables.php'),
                'TP-PARTIDAS-ESTADOS-COMENTARIOS-1'
            ),
            'no_seeds_created_or_modified_marker' => !str_contains(
                $this->read('database/seeds/tickets_productos_partidas_estados_1_seed_permissions.php'),
                'TP-PARTIDAS-ESTADOS-COMENTARIOS-1'
            ),
            'index_not_modified_for_phase_marker' => !str_contains(
                $this->read('app/Views/tickets/productos/index.php'),
                'TP-PARTIDAS-ESTADOS-COMENTARIOS-1'
            ),
            'create_not_modified_for_phase_marker' => !str_contains(
                $this->read('app/Views/tickets/productos/create.php'),
                'TP-PARTIDAS-ESTADOS-COMENTARIOS-1'
            ),
            'only_authorized_ticket_create_js_runtime' => $this->onlyAuthorizedTicketCreateJsRuntime(),
            'no_mail_runtime_created' =>
                !$this->hasFiles('app/Domain/Mail', '/ticket|solicitud|alta/i')
                && !$this->hasFiles('app/Domain/Notifications', '/ticket|solicitud|alta/i'),
            'no_real_attachments_created' => !$this->hasFiles('storage', '/tickets-productos|ticket|solicitud/i'),
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
        $userId = $this->createUser('qa_tp_comentarios_1');
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
     * @return array<string, mixed>
     */
    private function createTicket(array $fixture): array
    {
        return $this->service->crearTicket([
            'empresa_id' => $fixture['empresa_id'],
            'almacen_id' => $fixture['almacen_id'],
            'observaciones_generales' => 'Ticket documental para comentarios.',
            'partidas' => [[
                'descripcion' => 'Partida documental para comentarios',
                'modelo' => 'M-COM',
                'marca_texto' => 'Marca documental',
                'proveedor_texto' => 'Proveedor documental',
            ]],
        ], $fixture['user_id']);
    }

    /**
     * @return array<string, mixed>
     */
    private function firstPartida(int $ticketId): array
    {
        $partidas = $this->repository->listPartidas($ticketId);

        return $partidas[0] ?? [];
    }

    private function controllerFor(int $userId): ProductRequestTicketController
    {
        $this->closeSession();
        session_save_path(sys_get_temp_dir());
        session_name('TP_COM_' . bin2hex(random_bytes(4)));

        if (!session_start()) {
            throw new RuntimeException('Unable to start comments test session.');
        }

        $_SESSION['auth_user'] = [
            'user_id' => $userId,
            'username' => 'qa_tp_comentarios_1',
            'email' => 'qa_tp_comentarios_1@example.test',
        ];

        $session = new Session([]);
        $auth = new AuthService(
            new UserRepository($GLOBALS['tp_product_ticket_comentarios_connection']),
            $session
        );

        return new ProductRequestTicketController($auth, $this->service);
    }

    /**
     * @param callable(): mixed $operation
     */
    private function failsValidation(callable $operation, string $field): bool
    {
        try {
            $operation();
        } catch (ProductRequestTicketValidationException $exception) {
            return array_key_exists($field, $exception->errors());
        }

        return false;
    }

    /**
     * @param array<string, mixed> $ticket
     */
    private function renderShow(array $ticket, bool $canCreateComments): string
    {
        $this->startSession();
        $csrf = new App\Support\Security\CsrfTokenService(new Session([]));

        return $this->render('app/Views/tickets/productos/show.php', [
            'csrf' => $csrf,
            'errors' => [],
            'permissions' => [
                'canView' => true,
                'canCreate' => true,
                'canResolve' => true,
                'canCancel' => true,
                'canViewAttachments' => true,
                'canCreateComments' => $canCreateComments,
                'canResendEmail' => true,
                'canViewEvents' => true,
            ],
            'ticket' => $ticket,
        ]);
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

    private function createCompany(int $userId): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO empresas (codigo, nombre, activo, creado_por)
             VALUES (:codigo, :nombre, 1, :creado_por)'
        );
        $statement->execute([
            'codigo' => 'QATPCOM',
            'nombre' => 'Empresa QA TP Comentarios',
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
            'codigo' => 'GC',
            'nombre' => 'Almacén QA TP Comentarios',
            'creado_por' => $userId,
        ]);

        return (int) $this->pdo->lastInsertId();
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

    private function onlyAuthorizedTicketCreateJsRuntime(): bool
    {
        $allowedRelative = 'public/js/modules/tickets-productos-create.js';
        $allowedPath = BASE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $allowedRelative);

        if (!is_file($allowedPath)) {
            return false;
        }

        $js = (string) file_get_contents($allowedPath);

        $safeContent =
            !preg_match('/https?:\/\/|cdn/i', $js)
            && !preg_match('/\b(jquery|react|vue|angular|bootstrap)\b/i', $js)
            && !preg_match('/\beval\s*\(|new\s+Function\s*\(/', $js)
            && !str_contains($js, '.innerHTML')
            && str_contains($js, "document.createElement('option')")
            && str_contains($js, '.textContent')
            && !str_contains($js, 'console.log')
            && !preg_match('/\b(insert|update|delete|drop|alter)\b/i', $js)
            && !preg_match('/\bselect\s+.+\s+from\b/i', $js)
            && !preg_match('/\bcreate\s+table\b/i', $js)
            && !preg_match('/storage\/private|storage\/uploads|[A-Z]:\\\\/i', $js)
            && !preg_match('/\b(password|secret|dsn|api[_-]?key|token_hash|auth_user)\b/i', $js)
            && str_contains($js, 'empresa')
            && str_contains($js, 'almacen');

        if (!$safeContent) {
            return false;
        }

        return !$this->hasUnauthorizedTicketJsRuntime($allowedRelative);
    }

    private function hasUnauthorizedTicketJsRuntime(string $allowedRelative): bool
    {
        $directory = BASE_PATH . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'js';

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

            if ($relative === $allowedRelative) {
                continue;
            }

            if (preg_match('/ticket|solicitud|alta/i', $relative) === 1) {
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

    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_save_path(sys_get_temp_dir());
        session_name('TP_COM_VIEW_' . bin2hex(random_bytes(4)));

        if (!session_start()) {
            throw new RuntimeException('Unable to start comments view test session.');
        }
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
