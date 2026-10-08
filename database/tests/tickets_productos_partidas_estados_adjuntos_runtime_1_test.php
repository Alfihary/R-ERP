<?php

declare(strict_types=1);

use App\Core\Request;
use App\Core\Router;
use App\Core\Session;
use App\Domain\Auth\AuthService;
use App\Domain\Security\PermissionService;
use App\Domain\Tickets\ProductRequestTicketService;
use App\Domain\Tickets\ProductRequestTicketValidationException;
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
    private const PASSWORD = 'qa-tp-adjuntos-runtime';

    private PDO $pdo;
    private ProductRequestTicketRepository $repository;
    private ProductRequestTicketService $service;
    /** @var list<string> */
    private array $filesToDelete = [];

    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $this->pdo = $pdo;
        $this->repository = new ProductRequestTicketRepository(
            $GLOBALS['tp_product_ticket_adjuntos_runtime_connection']
        );
        $this->service = new ProductRequestTicketService($this->repository);

        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
            throw new RuntimeException('Unexpected active database for TP-PARTIDAS-ESTADOS-ADJUNTOS-RUNTIME-1.');
        }

        $migration = require BASE_PATH
            . '/database/migrations/tp_partidas_estados_db_1_001_create_ticket_product_tables.php';
        $seed = require BASE_PATH
            . '/database/seeds/tickets_productos_partidas_estados_1_seed_permissions.php';

        if (!$migration instanceof Migration || !$seed instanceof Seed) {
            throw new RuntimeException('TP-PARTIDAS-ESTADOS-ADJUNTOS-RUNTIME-1 dependencies are invalid.');
        }

        $runner = new MigrationRunner($pdo);
        $migrationState = $runner->migrate($migration);
        $before = $this->operationalCounts();
        $results = [];
        $during = [];

        try {
            $pdo->beginTransaction();
            $seed->run($pdo);
            $fixture = $this->fixture();
            $ticket = $this->createTicket($fixture);
            $otherTicket = $this->createTicket($fixture);
            $partida = $this->firstPartida((int) $ticket['id']);
            $foreignPartida = $this->firstPartida((int) $otherTicket['id']);
            $initialTicketState = (string) $ticket['estado'];
            $initialPartidaState = (string) $partida['estado'];

            $pdf = $this->service->agregarAdjunto(
                (int) $ticket['id'],
                null,
                $this->upload('soporte.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n"),
                $fixture['user_id']
            );
            $jpg = $this->service->agregarAdjunto(
                (int) $ticket['id'],
                (int) $partida['id'],
                $this->upload('foto.jpg', base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2w==') ?: ''),
                $fixture['user_id']
            );
            $jpeg = $this->service->agregarAdjunto(
                (int) $ticket['id'],
                (int) $partida['id'],
                $this->upload('foto.jpeg', base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2w==') ?: ''),
                $fixture['user_id']
            );
            $png = $this->service->agregarAdjunto(
                (int) $ticket['id'],
                null,
                $this->upload('captura.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=') ?: ''),
                $fixture['user_id']
            );
            $webp = $this->service->agregarAdjunto(
                (int) $ticket['id'],
                null,
                $this->upload('imagen.webp', base64_decode('UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEAAUAmJaQAA3AA/vuUAAA=') ?: ''),
                $fixture['user_id']
            );
            $invalidPhp = $this->fails(fn () => $this->service->agregarAdjunto(
                (int) $ticket['id'],
                null,
                $this->upload('script.php', "<?php echo 'x';"),
                $fixture['user_id']
            ));
            $invalidJs = $this->fails(fn () => $this->service->agregarAdjunto(
                (int) $ticket['id'],
                null,
                $this->upload('script.js', "alert('x');"),
                $fixture['user_id']
            ));
            $invalidHtml = $this->fails(fn () => $this->service->agregarAdjunto(
                (int) $ticket['id'],
                null,
                $this->upload('page.html', '<!doctype html><script></script>'),
                $fixture['user_id']
            ));
            $invalidSvg = $this->fails(fn () => $this->service->agregarAdjunto(
                (int) $ticket['id'],
                null,
                $this->upload('vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
                $fixture['user_id']
            ));
            $invalidZip = $this->fails(fn () => $this->service->agregarAdjunto(
                (int) $ticket['id'],
                null,
                $this->upload('archivo.zip', "PK\x03\x04"),
                $fixture['user_id']
            ));
            $doubleExtension = $this->fails(fn () => $this->service->agregarAdjunto(
                (int) $ticket['id'],
                null,
                $this->upload('factura.php.pdf', "%PDF-1.4\n%%EOF\n"),
                $fixture['user_id']
            ));
            $fakeMime = $this->fails(fn () => $this->service->agregarAdjunto(
                (int) $ticket['id'],
                null,
                $this->upload('falso.pdf', 'not a real pdf'),
                $fixture['user_id']
            ));
            $tooLarge = $this->fails(fn () => $this->service->agregarAdjunto(
                (int) $ticket['id'],
                null,
                $this->upload('grande.pdf', str_repeat('A', 5 * 1024 * 1024 + 1)),
                $fixture['user_id']
            ));
            $empty = $this->fails(fn () => $this->service->agregarAdjunto(
                (int) $ticket['id'],
                null,
                $this->upload('vacio.pdf', ''),
                $fixture['user_id']
            ));
            $missingTicket = $this->fails(fn () => $this->service->agregarAdjunto(
                999999999,
                null,
                $this->upload('soporte.pdf', "%PDF-1.4\n%%EOF\n"),
                $fixture['user_id']
            ));
            $foreignPartidaCase = $this->fails(fn () => $this->service->agregarAdjunto(
                (int) $ticket['id'],
                (int) $foreignPartida['id'],
                $this->upload('soporte.pdf', "%PDF-1.4\n%%EOF\n"),
                $fixture['user_id']
            ));
            $missingFile = $this->fails(fn () => $this->service->agregarAdjunto(
                (int) $ticket['id'],
                null,
                [],
                $fixture['user_id']
            ));

            [$auth, $csrf, $router] = $this->stack('runtime', $fixture['user_id']);
            $this->login($auth, $fixture['username']);
            $withoutCsrf = $router->dispatch(new Request(
                'POST',
                '/tickets/productos/' . (int) $ticket['id'] . '/adjuntos',
                [],
                [],
                [],
                ['adjunto' => $this->upload('csrf.pdf', "%PDF-1.4\n%%EOF\n")]
            ));
            $controllerUpload = $this->upload('controller.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n");
            $withCsrf = $router->dispatch(new Request(
                'POST',
                '/tickets/productos/' . (int) $ticket['id'] . '/adjuntos',
                [],
                ['_token' => $csrf->token()],
                [],
                ['adjunto' => $controllerUpload]
            ));

            $afterTicket = $this->repository->findTicketById((int) $ticket['id']) ?? [];
            $afterPartida = $this->firstPartida((int) $ticket['id']);
            $attachments = $this->repository->listAdjuntos((int) $ticket['id']);
            $events = $this->repository->listEventos((int) $ticket['id']);
            $show = $this->controllerFor($fixture['user_id'])->show(
                new Request('GET', '/tickets/productos/' . (int) $ticket['id']),
                ['id' => (string) $ticket['id']]
            );
            $routes = $this->read('routes/web.php');
            $controller = $this->read('app/Http/Controllers/ProductRequestTicketController.php');
            $service = $this->read('app/Domain/Tickets/ProductRequestTicketService.php');
            $repository = $this->read('app/Infrastructure/Repositories/ProductRequestTicketRepository.php');
            $safeUpload = $this->read('app/Support/SafeUpload.php');
            $view = $this->read('app/Views/tickets/productos/show.php');

            foreach ($attachments as $attachment) {
                if (is_array($attachment)) {
                    $this->filesToDelete[] = BASE_PATH . '/storage/' . (string) $attachment['ruta_relativa'];
                }
            }

            $results = [
                'route_security' => [
                    'post_route_exists' => str_contains($routes, '/tickets/productos/{id}/adjuntos'),
                    'route_uses_auth_permission_stack' =>
                        str_contains($routes, 'AuthMiddleware')
                        && str_contains($routes, 'PermissionMiddleware')
                        && str_contains($routes, 'tickets_productos.adjuntos.ver'),
                    'csrf_required' => $withoutCsrf->status() === 419,
                    'controller_route_redirects_after_upload' => $withCsrf->status() === 302,
                    'no_download_route' => !preg_match('#/tickets/productos/\{id\}/adjuntos/\{[^}]+\}/(?:descargar|download)#i', $routes),
                    'no_preview_route' => !preg_match('#/tickets/productos/\{id\}/adjuntos/\{[^}]+\}/(?:preview|ver)#i', $routes),
                ],
                'controller_contract' => [
                    'controller_has_attachment_method' => str_contains($controller, 'function attachment('),
                    'controller_reads_request_file' => str_contains($controller, 'uploadedFile($request,'),
                    'controller_calls_service' => str_contains($controller, 'agregarAdjunto('),
                    'controller_has_no_sql' => preg_match('/\\b(SELECT|INSERT|UPDATE|DELETE)\\b/i', $controller) !== 1,
                ],
                'service_repository_contract' => [
                    'service_has_method' => str_contains($service, 'function agregarAdjunto('),
                    'service_validates_user_ticket_partida' =>
                        str_contains($service, 'assertActiveUser')
                        && str_contains($service, 'assertTicketForUpdate')
                        && str_contains($service, 'assertPartidaForUpdate'),
                    'repository_has_insert_method' => str_contains($repository, 'function agregarAdjunto('),
                    'repository_uses_prepared_insert' =>
                        str_contains($repository, 'INSERT INTO tickets_productos_adjuntos')
                        && str_contains($repository, 'prepare('),
                    'safe_upload_created' => str_contains($safeUpload, 'final class SafeUpload'),
                    'safe_upload_uses_finfo' => str_contains($safeUpload, 'new \\finfo(FILEINFO_MIME_TYPE)'),
                    'safe_upload_uses_private_storage' => str_contains($safeUpload, 'storage/private/tickets_productos'),
                    'safe_upload_rejects_double_extension' => str_contains($safeUpload, 'hasDangerousDoubleExtension'),
                ],
                'upload_acceptance' => [
                    'accepts_pdf' => $this->hasAttachment($attachments, 'soporte.pdf', 'pdf', 'application/pdf'),
                    'accepts_jpg' => $this->hasAttachment($attachments, 'foto.jpg', 'jpg', 'image/jpeg'),
                    'accepts_jpeg' => $this->hasAttachment($attachments, 'foto.jpeg', 'jpeg', 'image/jpeg'),
                    'accepts_png' => $this->hasAttachment($attachments, 'captura.png', 'png', 'image/png'),
                    'accepts_webp' => $this->hasAttachment($attachments, 'imagen.webp', 'webp', 'image/webp'),
                    'stores_general_and_line_attachments' =>
                        $this->hasAttachmentPartida($attachments, null)
                        && $this->hasAttachmentPartida($attachments, (int) $partida['id']),
                    'stores_safe_relative_private_path' => $this->attachmentsHaveSafePrivatePaths($attachments),
                    'stores_hash_sha256' => $this->attachmentsHaveHashes($attachments),
                    'files_exist_outside_public' => $this->storedFilesExistOutsidePublic($attachments),
                ],
                'upload_rejections' => [
                    'rejects_php' => $invalidPhp,
                    'rejects_js' => $invalidJs,
                    'rejects_html' => $invalidHtml,
                    'rejects_svg' => $invalidSvg,
                    'rejects_zip' => $invalidZip,
                    'rejects_double_extension' => $doubleExtension,
                    'rejects_fake_mime' => $fakeMime,
                    'rejects_too_large' => $tooLarge,
                    'rejects_empty' => $empty,
                    'rejects_missing_ticket' => $missingTicket,
                    'rejects_foreign_partida' => $foreignPartidaCase,
                    'rejects_missing_file' => $missingFile,
                ],
                'show_contract' => [
                    'show_displays_metadata' =>
                        $show->status() === 200
                        && str_contains($show->body(), 'soporte.pdf')
                        && str_contains($show->body(), 'application/pdf')
                        && str_contains($show->body(), 'Adjuntos'),
                    'show_has_upload_forms' =>
                        str_contains($show->body(), 'enctype="multipart/form-data"')
                        && str_contains($show->body(), 'name="adjunto"')
                        && str_contains($show->body(), 'partida_id'),
                    'show_documents_future_download' => str_contains(
                        $show->body(),
                        'La descarga se habilitará en una fase posterior.'
                    ),
                    'show_exposes_no_routes_or_internal_names' =>
                        !str_contains($show->body(), 'private/tickets_productos')
                        && !str_contains($show->body(), 'ruta_relativa')
                        && !str_contains($show->body(), 'nombre_guardado')
                        && !str_contains($show->body(), 'storage/private')
                        && !str_contains($show->body(), 'C:\\')
                        && !str_contains($show->body(), '/var/'),
                    'show_has_no_download_or_preview_links' =>
                        !str_contains($show->body(), 'Descargar adjunto')
                        && !str_contains($show->body(), 'Vista previa')
                        && !str_contains($show->body(), '/descargar')
                        && !str_contains($show->body(), '/preview'),
                    'view_uses_escape_helper' => substr_count($view, 'e(') >= 25,
                ],
                'state_and_events' => [
                    'ticket_state_unchanged' => (string) ($afterTicket['estado'] ?? '') === $initialTicketState,
                    'partida_state_unchanged' => (string) ($afterPartida['estado'] ?? '') === $initialPartidaState,
                    'event_registered' => $this->hasEvent($events, 'ADJUNTO_CARGADO'),
                ],
                'guardrails' => [
                    'no_create_or_index_view_change_marker' =>
                        !str_contains($this->read('app/Views/tickets/productos/create.php'), 'ADJUNTOS-RUNTIME')
                        && !str_contains($this->read('app/Views/tickets/productos/index.php'), 'ADJUNTOS-RUNTIME'),
                    'no_public_product_storage' => !$this->pathExists(BASE_PATH . '/storage/uploads/productos/tickets_productos'),
                    'no_public_user_storage' => !$this->pathExists(BASE_PATH . '/storage/uploads/usuarios/tickets_productos'),
                    'no_product_created' => $before['productos'] === $this->operationalCounts()['productos'],
                    'no_price_created' => $before['producto_precios'] === $this->operationalCounts()['producto_precios'],
                    'no_stock_created' => $before['existencias_producto'] === $this->operationalCounts()['existencias_producto'],
                    'no_inventory_created' => $before['inventario_existencias'] === $this->operationalCounts()['inventario_existencias'],
                    'no_inventory_movement_created' => $before['movimientos_inventario'] === $this->operationalCounts()['movimientos_inventario'],
                    'no_purchase_created' => $before['compras'] === $this->operationalCounts()['compras'],
                    'no_supplier_created' => $before['proveedores'] === $this->operationalCounts()['proveedores'],
                ],
            ];
            $during = $this->operationalCounts();
            $pdo->rollBack();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        } finally {
            $this->closeSession();
            $this->cleanupFiles();

            if ($migrationState === 'applied') {
                $runner->rollback($migration);
            }
        }

        $after = $this->operationalCounts();

        if (!$this->allTrue($results)) {
            throw new RuntimeException(
                'TP-PARTIDAS-ESTADOS-ADJUNTOS-RUNTIME-1 assertions failed: '
                . json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        if ($before !== $after) {
            throw new RuntimeException('TP-PARTIDAS-ESTADOS-ADJUNTOS-RUNTIME-1 changed operational counts.');
        }

        return [
            'database' => $expectedDatabase,
            'migration_state' => $migrationState,
            'route' => 'POST /tickets/productos/{id}/adjuntos',
            'storage' => 'storage/private/tickets_productos/{ticket_id}/',
            'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
            'allowed_mime_types' => ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'],
            'event' => 'ADJUNTO_CARGADO',
            'cases' => $results,
            'operational_counts_before' => $before,
            'operational_counts_during' => $during,
            'operational_counts_after' => $after,
            'cleanup' => 'transaction_rolled_back_storage_files_deleted_no_operational_data_written',
        ];
    }

    /**
     * @return array{user_id: int, username: string, empresa_id: int, almacen_id: int}
     */
    private function fixture(): array
    {
        $username = 'qa.tp.adjuntos.runtime';
        $userId = $this->createUser($username);
        $this->assignAdminRole($userId);
        $companyId = $this->createCompany($userId);
        $warehouseId = $this->createWarehouse($companyId, $userId);
        $this->assignScope($userId, $companyId, $warehouseId);

        return [
            'user_id' => $userId,
            'username' => $username,
            'empresa_id' => $companyId,
            'almacen_id' => $warehouseId,
        ];
    }

    /**
     * @param array<string, int|string> $fixture
     * @return array<string, mixed>
     */
    private function createTicket(array $fixture): array
    {
        return $this->service->crearTicket([
            'empresa_id' => (int) $fixture['empresa_id'],
            'almacen_id' => (int) $fixture['almacen_id'],
            'observaciones_generales' => 'Ticket QA adjuntos runtime.',
            'partidas' => [[
                'descripcion' => 'Partida QA adjuntos runtime.',
                'modelo' => 'ADJ-RUNTIME',
            ]],
        ], (int) $fixture['user_id']);
    }

    private function firstPartida(int $ticketId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM tickets_productos_partidas WHERE ticket_producto_id = :ticket_id ORDER BY id ASC LIMIT 1'
        );
        $statement->execute(['ticket_id' => $ticketId]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            throw new RuntimeException('Expected QA ticket line was not created.');
        }

        return $row;
    }

    private function controllerFor(int $userId): ProductRequestTicketController
    {
        $this->closeSession();
        session_save_path(sys_get_temp_dir());
        session_name('TP_ADJ_' . bin2hex(random_bytes(4)));

        if (!session_start()) {
            throw new RuntimeException('Unable to start attachment test session.');
        }

        $_SESSION['auth_user'] = [
            'user_id' => $userId,
            'username' => 'qa.tp.adjuntos.runtime',
            'email' => 'qa.tp.adjuntos.runtime@example.test',
        ];

        return new ProductRequestTicketController(
            new AuthService(new UserRepository($GLOBALS['tp_product_ticket_adjuntos_runtime_connection']), new Session([])),
            $this->service,
            new PermissionService(new PermissionRepository($GLOBALS['tp_product_ticket_adjuntos_runtime_connection'])),
            $this->repository
        );
    }

    /**
     * @return array{0: AuthService, 1: CsrfTokenService, 2: Router}
     */
    private function stack(string $suffix, int $userId): array
    {
        $this->closeSession();
        session_save_path(sys_get_temp_dir());
        session_name('tpadj' . preg_replace('/[^a-z0-9]/', '', strtolower($suffix)));
        session_id('tpadj' . bin2hex(random_bytes(8)));
        session_start();

        $session = new Session([
            'name' => session_name(),
            'same_site' => 'Lax',
            'secure' => false,
            'gc_max_lifetime' => 7200,
        ]);
        $auth = new AuthService(
            new UserRepository($GLOBALS['tp_product_ticket_adjuntos_runtime_connection']),
            $session
        );
        $permissions = new PermissionService(
            new PermissionRepository($GLOBALS['tp_product_ticket_adjuntos_runtime_connection'])
        );
        $csrf = new CsrfTokenService($session, 7200);
        $controller = new ProductRequestTicketController($auth, $this->service, $permissions, $this->repository);
        $router = new Router();
        $router->middleware(new CsrfMiddleware($csrf));
        $middleware = [
            new AuthMiddleware($auth),
            new PermissionMiddleware($auth, $permissions, 'tickets_productos.adjuntos.ver'),
        ];
        $router->post(
            '/tickets/productos/{id}/adjuntos',
            static fn (Request $request, array $params) => $controller->attachment($request, $params),
            $middleware
        );

        return [$auth, $csrf, $router];
    }

    private function login(AuthService $auth, string $username): void
    {
        if (!$auth->attempt($username, self::PASSWORD)) {
            throw new RuntimeException('Unable to authenticate QA user for attachments.');
        }
    }

    /**
     * @return array{name: string, tmp_name: string, size: int, error: int, type: string}
     */
    private function upload(string $name, string $contents): array
    {
        $path = tempnam(sys_get_temp_dir(), 'tp_adj_');

        if (!is_string($path)) {
            throw new RuntimeException('Could not create upload fixture.');
        }

        file_put_contents($path, $contents);
        $this->filesToDelete[] = $path;

        return [
            'name' => $name,
            'tmp_name' => $path,
            'size' => strlen($contents),
            'error' => UPLOAD_ERR_OK,
            'type' => 'application/octet-stream',
        ];
    }

    private function fails(callable $callback): bool
    {
        try {
            $callback();
        } catch (ProductRequestTicketValidationException) {
            return true;
        }

        return false;
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

    private function assignAdminRole(int $userId): void
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO usuario_roles (usuario_id, rol_id, activo)
             SELECT :usuario_id, id, 1
             FROM roles
             WHERE codigo = 'ADMIN' AND activo = 1 AND eliminado_en IS NULL
             LIMIT 1"
        );
        $statement->execute(['usuario_id' => $userId]);
    }

    private function createCompany(int $userId): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO empresas (codigo, nombre, activo, creado_por)
             VALUES (:codigo, :nombre, 1, :creado_por)'
        );
        $statement->execute([
            'codigo' => 'QATPADJ',
            'nombre' => 'Empresa QA TP Adjuntos Runtime',
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
            'codigo' => 'ADJ',
            'nombre' => 'Almacén QA TP Adjuntos Runtime',
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

    /**
     * @param list<array<string, mixed>> $attachments
     */
    private function hasAttachment(array $attachments, string $name, string $extension, string $mime): bool
    {
        foreach ($attachments as $attachment) {
            if (
                (string) ($attachment['nombre_original'] ?? '') === $name
                && (string) ($attachment['extension'] ?? '') === $extension
                && (string) ($attachment['mime'] ?? '') === $mime
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $attachments
     */
    private function hasAttachmentPartida(array $attachments, ?int $partidaId): bool
    {
        foreach ($attachments as $attachment) {
            $value = $attachment['partida_id'] ?? null;

            if ($partidaId === null && ($value === null || $value === '')) {
                return true;
            }

            if ($partidaId !== null && (int) $value === $partidaId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $attachments
     */
    private function attachmentsHaveSafePrivatePaths(array $attachments): bool
    {
        foreach ($attachments as $attachment) {
            $path = (string) ($attachment['ruta_relativa'] ?? '');

            if (
                !str_starts_with($path, 'private/tickets_productos/')
                || str_contains($path, '..')
                || str_starts_with($path, '/')
                || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1
            ) {
                return false;
            }
        }

        return $attachments !== [];
    }

    /**
     * @param list<array<string, mixed>> $attachments
     */
    private function attachmentsHaveHashes(array $attachments): bool
    {
        foreach ($attachments as $attachment) {
            if (preg_match('/^[a-f0-9]{64}$/', (string) ($attachment['hash_sha256'] ?? '')) !== 1) {
                return false;
            }
        }

        return $attachments !== [];
    }

    /**
     * @param list<array<string, mixed>> $attachments
     */
    private function storedFilesExistOutsidePublic(array $attachments): bool
    {
        $publicRoot = str_replace('\\', '/', realpath(BASE_PATH . '/public') ?: '');

        foreach ($attachments as $attachment) {
            $real = realpath(BASE_PATH . '/storage/' . (string) ($attachment['ruta_relativa'] ?? ''));
            $normalized = str_replace('\\', '/', is_string($real) ? $real : '');

            if ($normalized === '' || str_starts_with($normalized, $publicRoot)) {
                return false;
            }
        }

        return $attachments !== [];
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function hasEvent(array $events, string $event): bool
    {
        foreach ($events as $row) {
            if ((string) ($row['evento'] ?? '') === $event) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, int>
     */
    private function operationalCounts(): array
    {
        $tables = [
            'productos',
            'producto_precios',
            'existencias_producto',
            'inventario_existencias',
            'movimientos_inventario',
            'compras',
            'proveedores',
        ];
        $counts = [];

        foreach ($tables as $table) {
            $counts[$table] = $this->tableExists($table)
                ? (int) $this->pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn()
                : 0;
        }

        return $counts;
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

    private function pathExists(string $path): bool
    {
        return file_exists($path);
    }

    private function read(string $path): string
    {
        $contents = file_get_contents(BASE_PATH . '/' . $path);

        if (!is_string($contents)) {
            throw new RuntimeException('Could not read ' . $path);
        }

        return $contents;
    }

    /**
     * @param array<string, mixed> $results
     */
    private function allTrue(array $results): bool
    {
        foreach ($results as $value) {
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

    private function cleanupFiles(): void
    {
        foreach (array_unique($this->filesToDelete) as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $root = BASE_PATH . '/storage/private/tickets_productos';
        if (is_dir($root)) {
            $this->removeEmptyDirectories($root);
        }
    }

    private function removeEmptyDirectories(string $path): void
    {
        foreach (glob($path . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $this->removeEmptyDirectories($directory);
        }

        if ((glob($path . '/*') ?: []) === []) {
            @rmdir($path);
        }
    }

    private function closeSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_write_close();
        }
    }
};
