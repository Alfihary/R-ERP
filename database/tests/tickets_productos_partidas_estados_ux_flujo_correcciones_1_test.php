<?php

declare(strict_types=1);

use App\Core\Request;
use App\Core\Session;
use App\Core\View;
use App\Domain\Auth\AuthService;
use App\Domain\Security\PermissionService;
use App\Http\Controllers\ProductRequestTicketController;
use App\Domain\Tickets\ProductRequestTicketService;
use App\Domain\Tickets\ProductRequestTicketValidationException;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\PermissionRepository;
use App\Infrastructure\Repositories\ProductRequestTicketRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Support\Security\CsrfTokenService;

return new class implements DatabaseTest {
    private const PASSWORD = 'qa-tp-ux-flow';

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
            $GLOBALS['tp_product_ticket_ux_flow_connection']
        );
        $this->service = new ProductRequestTicketService($this->repository);

        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
            throw new RuntimeException('Unexpected active database for TP-PARTIDAS-ESTADOS-UX-FLUJO-CORRECCIONES-1.');
        }

        $before = $this->operationalCounts();
        $beforeStorage = $this->storageFiles();
        $results = [];

        try {
            $pdo->beginTransaction();
            $fixture = $this->fixture();
            $initialUploads = [
                $this->upload('soporte.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n"),
                $this->upload('foto.jpg', $this->imageContents('jpg')),
                $this->upload('foto.jpeg', $this->imageContents('jpg')),
                $this->upload('captura.png', $this->imageContents('png')),
                $this->upload('imagen.webp', $this->imageContents('webp')),
            ];

            $ticket = $this->service->crearTicket([
                'empresa_id' => $fixture['empresa_a_id'],
                'almacen_id' => $fixture['warehouse_a_id'],
                'observaciones_generales' => 'Ticket QA UX flujo.',
                'partidas' => [[
                    'descripcion' => 'Mini split solicitado UX',
                    'modelo' => 'UX-001',
                    'unidad_sat_id' => $fixture['unit_id'],
                    'clave_sat_id' => $fixture['sat_key_id'],
                    'moneda_id' => $fixture['currency_id'],
                ]],
                'adjuntos' => $initialUploads,
            ], $fixture['user_id']);
            $ticketId = (int) $ticket['id'];
            $partida = $this->partidas($ticketId)[0];

            $approved = $this->service->resolverPartida(
                $ticketId,
                (int) $partida['id'],
                'APROBAR',
                [
                    'clave_autorizada' => 'UX-001',
                    'descripcion_autorizada' => 'Mini split autorizado UX',
                    'unidad_sat_autorizada' => $fixture['unit_code'] . ' - ' . $fixture['unit_name'],
                    'clave_sat_autorizada' => $fixture['sat_key_code'] . ' - ' . $fixture['sat_key_description'],
                    'comentario_resolucion' => 'Producto autorizado para captura manual en catálogo.',
                ],
                $fixture['user_id']
            );

            $showHtml = $this->renderShow($approved);
            $createHtml = $this->renderCreate($fixture, [
                'empresa_id' => (string) $fixture['empresa_a_id'],
                'almacen_id' => (string) $fixture['warehouse_a_id'],
            ]);
            $ticketCreateJs = $this->read('public/js/modules/tickets-productos-create.js');
            $attachments = $this->attachments($ticketId);
            $afterCreateCounts = $this->operationalCounts();

            $results['readable_detail'] = [
                'company_is_readable' => str_contains($showHtml, 'UXA00001 · Empresa UX Flujo A')
                    && !str_contains($showHtml, '<dt>Empresa</dt><dd>' . $fixture['empresa_a_id'] . '</dd>'),
                'warehouse_is_readable' => str_contains($showHtml, 'UXA · Almacén UX Flujo A')
                    && !str_contains($showHtml, '<dt>Almacén</dt><dd>' . $fixture['warehouse_a_id'] . '</dd>'),
                'requester_is_readable' => str_contains($showHtml, 'qa.tp.ux.flow / Jesus QA Flujo')
                    && !str_contains($showHtml, '<dt>Solicitante</dt><dd>' . $fixture['user_id'] . '</dd>'),
                'requested_unit_is_readable' => str_contains($showHtml, 'Unidad SAT solicitada')
                    && str_contains($showHtml, $fixture['unit_code'])
                    && !str_contains($showHtml, '<dt>Unidad SAT solicitada</dt><dd>' . $fixture['unit_id'] . '</dd>'),
                'requested_sat_key_is_readable' => str_contains($showHtml, $fixture['sat_key_code'] . ' · ' . $fixture['sat_key_description']),
                'authorized_unit_is_readable' => str_contains($showHtml, 'Unidad SAT autorizada')
                    && str_contains($showHtml, $fixture['unit_code'])
                    && !str_contains($showHtml, '<dt>Unidad SAT autorizada</dt><dd>' . $fixture['unit_id'] . '</dd>'),
                'authorized_sat_key_is_readable' => str_contains($showHtml, 'Clave SAT autorizada')
                    && str_contains($showHtml, $fixture['sat_key_code'] . ' · ' . $fixture['sat_key_description']),
            ];

            $results['initial_attachments'] = [
                'create_allows_initial_attachments' => str_contains($createHtml, 'Adjuntos de soporte')
                    && str_contains($createHtml, 'name="adjuntos[]"')
                    && str_contains($createHtml, 'multiple')
                    && str_contains($createHtml, 'enctype="multipart/form-data"'),
                'accepts_pdf' => $this->hasAttachment($attachments, 'soporte.pdf', 'pdf', 'application/pdf'),
                'accepts_jpg' => $this->hasAttachment($attachments, 'foto.jpg', 'jpg', 'image/jpeg'),
                'accepts_jpeg' => $this->hasAttachment($attachments, 'foto.jpeg', 'jpeg', 'image/jpeg'),
                'accepts_png' => $this->hasAttachment($attachments, 'captura.png', 'png', 'image/png'),
                'accepts_webp' => $this->hasAttachment($attachments, 'imagen.webp', 'webp', 'image/webp'),
                'stored_in_private_storage' => $this->attachmentsHaveSafePrivatePaths($attachments),
                'metadata_inserted' => count($attachments) === 5
                    && $this->attachmentsHaveHashes($attachments),
                'uses_safe_upload' => str_contains(
                    $this->read('app/Domain/Tickets/ProductRequestTicketService.php'),
                    '(new SafeUpload())->storeTicketAttachment($file, $ticketId)'
                ),
                'no_download_in_create' => !$this->containsAny($createHtml, ['Descargar', '/descargar', 'download']),
                'no_preview_in_create' => !$this->containsAny($createHtml, ['Preview', 'Vista previa', '/preview']),
                'no_internal_paths_in_create' => !$this->containsAny($createHtml, [
                    'storage/private',
                    'ruta_relativa',
                    'nombre_guardado',
                    'C:\\',
                    '/var/',
                ]),
            ];

            $results['initial_attachment_rejections'] = [
                'rejects_php' => $this->createFailsWithAttachment($fixture, 'malicioso.php', '<?php echo 1;'),
                'rejects_js' => $this->createFailsWithAttachment($fixture, 'malicioso.js', 'alert(1);'),
                'rejects_html' => $this->createFailsWithAttachment($fixture, 'malicioso.html', '<html></html>'),
                'rejects_svg' => $this->createFailsWithAttachment($fixture, 'malicioso.svg', '<svg></svg>'),
                'rejects_zip' => $this->createFailsWithAttachment($fixture, 'archivo.zip', "PK\x03\x04"),
                'rejects_fake_mime' => $this->createFailsWithAttachment($fixture, 'fake.png', 'not an image'),
                'rejects_double_extension' => $this->createFailsWithAttachment($fixture, 'factura.php.pdf', "%PDF-1.4\n%%EOF\n"),
                'rejects_too_large' => $this->createFailsWithAttachment($fixture, 'grande.pdf', "%PDF-1.4\n" . str_repeat('A', 5 * 1024 * 1024 + 1)),
                'failed_attachment_does_not_create_ticket' => $this->ticketCountByObservation('Ticket QA UX flujo inválido.') === 0,
                'failed_attachment_leaves_no_orphan_file' => $this->noStorageFileNamed('factura.php.pdf'),
            ];

            $results['company_warehouse_filter'] = [
                'json_has_real_company_id' => str_contains($createHtml, '"empresa_id":' . $fixture['empresa_a_id'])
                    && str_contains($createHtml, '"empresa_id":' . $fixture['empresa_b_id']),
                'select_company_uses_real_id' => str_contains($createHtml, 'value="' . $fixture['empresa_a_id'] . '" selected'),
                'no_initial_mixed_visible_options' => !str_contains($createHtml, '<option value="' . $fixture['warehouse_b_id'] . '"'),
                'no_executable_inline_script_for_filter' =>
                    !str_contains($createHtml, "document.addEventListener('DOMContentLoaded'")
                    && str_contains($createHtml, 'type="application/json" id="ticket-products-warehouses-data"')
                    && str_contains($createHtml, 'src="/js/modules/tickets-productos-create.js" defer'),
                'external_local_script_exists' => is_file(BASE_PATH . '/public/js/modules/tickets-productos-create.js'),
                'external_script_has_change_listener' => str_contains($ticketCreateJs, "company.addEventListener('change'"),
                'external_script_filters_on_load' => str_contains($ticketCreateJs, 'refreshWarehouses();'),
                'external_script_uses_dom_ready' => str_contains($ticketCreateJs, "document.addEventListener('DOMContentLoaded'"),
                'external_script_filters_by_real_company_id' => str_contains($ticketCreateJs, 'item.empresa_id === selectedCompanyId'),
                'external_script_clears_foreign_warehouse_on_change' =>
                    str_contains($ticketCreateJs, "warehouse.dataset.selectedWarehouse = '';"),
                'external_script_uses_safe_dom_options' =>
                    str_contains($ticketCreateJs, 'document.createElement(\'option\')')
                    && str_contains($ticketCreateJs, 'option.textContent = text')
                    && str_contains($ticketCreateJs, 'warehouse.replaceChildren')
                    && !str_contains($ticketCreateJs, 'innerHTML')
                    && !str_contains($ticketCreateJs, 'eval('),
                'external_script_has_no_cdn_framework_or_debug_log' =>
                    !$this->containsAny($ticketCreateJs, ['https://', 'http://', 'jquery', 'React', 'Vue', 'Angular', 'console.log']),
                'empty_state_exists' => str_contains($ticketCreateJs, 'Sin almacenes asignados para esta empresa')
                    && str_contains($ticketCreateJs, 'No tienes almacenes asignados para esta empresa.')
                    && str_contains($ticketCreateJs, 'warehouse.disabled = true')
                    && str_contains($ticketCreateJs, 'warehouse.disabled = false'),
                'backend_rejects_foreign_company_warehouse' => $this->storeFailsForScope(
                    $fixture,
                    $fixture['empresa_a_id'],
                    $fixture['warehouse_b_id']
                ),
                'backend_rejects_out_of_scope_warehouse' => $this->storeFailsForScope(
                    $fixture,
                    $fixture['empresa_a_id'],
                    $fixture['warehouse_out_scope_id']
                ),
            ];

            $results['guardrails'] = [
                'detail_runtime_attachments_still_work' => is_file(BASE_PATH . '/database/tickets-productos-partidas-estados-adjuntos-runtime.php'),
                'approval_capture_still_present' => str_contains($this->read('app/Views/tickets/productos/show.php'), 'Descripción autorizada')
                    && str_contains($this->read('app/Views/tickets/productos/show.php'), 'name="clave_autorizada"'),
                'counts_unchanged_for_products_prices_inventory_purchases_suppliers' => $before === $afterCreateCounts,
                'no_product_created' => $before['productos'] === $afterCreateCounts['productos'],
                'no_price_created' => $before['producto_precios'] === $afterCreateCounts['producto_precios'],
                'no_stock_created' => $before['existencias_producto'] === $afterCreateCounts['existencias_producto'],
                'no_inventory_created' => $before['inventario_existencias'] === $afterCreateCounts['inventario_existencias'],
                'no_inventory_movement_created' => $before['movimientos_inventario'] === $afterCreateCounts['movimientos_inventario'],
                'no_purchase_created' => $before['compras'] === $afterCreateCounts['compras'],
                'no_supplier_created' => $before['proveedores'] === $afterCreateCounts['proveedores'],
            ];

            $duringStorage = $this->storageFiles();
            $pdo->rollBack();
            $this->deleteCreatedStorageFiles($duringStorage, $beforeStorage);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $this->deleteCreatedStorageFiles($this->storageFiles(), $beforeStorage);
            $this->deleteTempFiles();

            throw $exception;
        }

        $after = $this->operationalCounts();
        $afterStorage = $this->storageFiles();
        $this->deleteTempFiles();

        return [
            'database' => $expectedDatabase,
            'cases' => $results + [
                'cleanup' => [
                    'transaction_rolled_back' => true,
                    'no_operational_counts_changed' => $before === $after,
                    'no_storage_files_left_by_test' => $beforeStorage === $afterStorage,
                ],
            ],
            'operational_counts_before' => $before,
            'operational_counts_after' => $after,
            'cleanup' => 'transaction_rolled_back_storage_files_deleted_no_operational_data_written',
        ];
    }

    /**
     * @return array<string, int|string>
     */
    private function fixture(): array
    {
        $userId = $this->createUser();
        $this->createProfile($userId);
        $companyAId = $this->createCompany('UXA00001', 'Empresa UX Flujo A', $userId);
        $companyBId = $this->createCompany('UXB00002', 'Empresa UX Flujo B', $userId);
        $warehouseAId = $this->createWarehouse($companyAId, 'UXA', 'Almacén UX Flujo A', $userId);
        $warehouseBId = $this->createWarehouse($companyBId, 'UXB', 'Almacén UX Flujo B', $userId);
        $warehouseOutScopeId = $this->createWarehouse($companyAId, 'UXO', 'Almacén UX Fuera Scope', $userId);
        $this->assignScope($userId, $companyAId, $warehouseAId);
        $this->assignScope($userId, $companyBId, $warehouseBId);
        $unit = $this->firstSatUnit();
        $satKey = $this->firstSatKey();
        $currency = $this->firstCurrency();

        return [
            'user_id' => $userId,
            'empresa_a_id' => $companyAId,
            'empresa_b_id' => $companyBId,
            'warehouse_a_id' => $warehouseAId,
            'warehouse_b_id' => $warehouseBId,
            'warehouse_out_scope_id' => $warehouseOutScopeId,
            'unit_id' => (int) $unit['id'],
            'unit_code' => (string) $unit['codigo'],
            'unit_name' => (string) $unit['nombre'],
            'sat_key_id' => (int) $satKey['id'],
            'sat_key_code' => (string) $satKey['codigo'],
            'sat_key_description' => (string) $satKey['descripcion'],
            'currency_id' => (int) $currency['id'],
        ];
    }

    /**
     * @param array<string, int|string> $fixture
     * @param array<string, mixed> $values
     */
    private function renderCreate(array $fixture, array $values): string
    {
        return View::render('tickets/productos/create', [
            'csrf' => $this->csrf(),
            'permissions' => ['canCreate' => true],
            'errors' => [],
            'values' => $values,
            'catalogs' => [
                'companies' => $this->repository->availableCompaniesForUser((int) $fixture['user_id']),
                'warehouses' => $this->repository->availableWarehousesForUser((int) $fixture['user_id'], (int) $fixture['empresa_a_id']),
                'all_warehouses' => $this->repository->availableWarehousesForUser((int) $fixture['user_id']),
                'brands' => $this->repository->activeBrands(),
                'currencies' => $this->repository->activeCurrencies(),
                'sat_units' => $this->repository->activeSatUnits(),
                'sat_keys' => $this->repository->activeSatKeys(),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $ticket
     */
    private function renderShow(array $ticket): string
    {
        return View::render('tickets/productos/show', [
            'csrf' => $this->csrf(),
            'permissions' => [
                'canResolve' => true,
                'canCancel' => true,
                'canViewAttachments' => true,
                'canCreateComments' => true,
                'canResendEmail' => true,
                'canViewEvents' => true,
            ],
            'errors' => [],
            'ticket' => $ticket,
            'approvalCatalogs' => [
                'sat_units' => $this->repository->activeSatUnits(),
                'sat_keys' => $this->repository->activeSatKeys(),
            ],
        ]);
    }

    private function createFailsWithAttachment(array $fixture, string $name, string $contents): bool
    {
        $this->pdo->exec('SAVEPOINT tp_ux_failed_attachment');

        try {
            $this->service->crearTicket([
                'empresa_id' => $fixture['empresa_a_id'],
                'almacen_id' => $fixture['warehouse_a_id'],
                'observaciones_generales' => 'Ticket QA UX flujo inválido.',
                'partidas' => [[
                    'descripcion' => 'Partida inválida por adjunto.',
                ]],
                'adjuntos' => [$this->upload($name, $contents)],
            ], (int) $fixture['user_id']);

            $this->pdo->exec('ROLLBACK TO SAVEPOINT tp_ux_failed_attachment');

            return false;
        } catch (ProductRequestTicketValidationException) {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT tp_ux_failed_attachment');

            return true;
        }
    }

    private function csrf(): CsrfTokenService
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_save_path(sys_get_temp_dir());
            session_name('TPUX' . bin2hex(random_bytes(4)));
            session_start();
        }

        return new CsrfTokenService(new Session([]));
    }

    private function controllerFor(int $userId): ProductRequestTicketController
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_save_path(sys_get_temp_dir());
            session_name('TPUX' . bin2hex(random_bytes(4)));
            session_start();
        }

        $_SESSION['auth_user'] = [
            'user_id' => $userId,
            'username' => 'qa.tp.ux.flow',
            'email' => 'qa.tp.ux.flow@example.test',
        ];

        return new ProductRequestTicketController(
            new AuthService(new UserRepository($GLOBALS['tp_product_ticket_ux_flow_connection']), new Session([])),
            $this->service,
            new PermissionService(new PermissionRepository($GLOBALS['tp_product_ticket_ux_flow_connection'])),
            $this->repository
        );
    }

    private function storeFailsForScope(array $fixture, int|string $companyId, int|string $warehouseId): bool
    {
        $this->pdo->exec('SAVEPOINT tp_ux_scope');

        try {
            $response = $this->controllerFor((int) $fixture['user_id'])->store(new Request(
                'POST',
                '/tickets/productos',
                [],
                [
                    'empresa_id' => (string) $companyId,
                    'almacen_id' => (string) $warehouseId,
                    'observaciones_generales' => 'Ticket QA UX alcance inválido.',
                    'partidas' => [[
                        'descripcion' => 'Partida alcance inválido.',
                    ]],
                ]
            ));
            $this->pdo->exec('ROLLBACK TO SAVEPOINT tp_ux_scope');

            return $response->status() === 422;
        } catch (ProductRequestTicketValidationException) {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT tp_ux_scope');

            return true;
        }
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

    /**
     * @return list<array<string, mixed>>
     */
    private function partidas(int $ticketId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM tickets_productos_partidas WHERE ticket_producto_id = :ticket_id ORDER BY id ASC'
        );
        $statement->execute(['ticket_id' => $ticketId]);

        return $statement->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function attachments(int $ticketId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM tickets_productos_adjuntos WHERE ticket_producto_id = :ticket_id ORDER BY id ASC'
        );
        $statement->execute(['ticket_id' => $ticketId]);

        return $statement->fetchAll();
    }

    private function hasAttachment(array $attachments, string $name, string $extension, string $mime): bool
    {
        foreach ($attachments as $attachment) {
            if (
                (string) ($attachment['nombre_original'] ?? '') === $name
                && (string) ($attachment['extension'] ?? '') === $extension
                && (string) ($attachment['mime'] ?? '') === $mime
                && ($attachment['partida_id'] ?? null) === null
            ) {
                return true;
            }
        }

        return false;
    }

    private function attachmentsHaveSafePrivatePaths(array $attachments): bool
    {
        foreach ($attachments as $attachment) {
            $path = (string) ($attachment['ruta_relativa'] ?? '');

            if (
                !str_starts_with($path, 'private/tickets_productos/')
                || str_contains($path, '..')
                || str_starts_with($path, '/')
                || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1
                || !is_file(BASE_PATH . '/storage/' . $path)
            ) {
                return false;
            }
        }

        return $attachments !== [];
    }

    private function attachmentsHaveHashes(array $attachments): bool
    {
        foreach ($attachments as $attachment) {
            if (preg_match('/^[a-f0-9]{64}$/', (string) ($attachment['hash_sha256'] ?? '')) !== 1) {
                return false;
            }
        }

        return $attachments !== [];
    }

    private function ticketCountByObservation(string $observation): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM tickets_productos WHERE observaciones_generales = :observation'
        );
        $statement->execute(['observation' => $observation]);

        return (int) $statement->fetchColumn();
    }

    private function noStorageFileNamed(string $name): bool
    {
        $files = $this->storageFiles();

        foreach ($files as $file) {
            if (str_contains($file, $name)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{name: string, tmp_name: string, size: int, error: int, type: string}
     */
    private function upload(string $name, string $contents): array
    {
        $path = tempnam(sys_get_temp_dir(), 'tp_ux_');

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

    private function imageContents(string $type): string
    {
        $map = [
            'png' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=',
            'jpg' => '/9j/4AAQSkZJRgABAQAAAQABAAD/2w==',
            'webp' => 'UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEAAUAmJaQAA3AA/vuUAAA=',
        ];

        return base64_decode($map[$type] ?? '', true) ?: '';
    }

    private function createUser(): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO usuarios (username, email, password_hash, activo, creado_en)
             VALUES (:username, :email, :password_hash, 1, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            'username' => 'qa.tp.ux.flow',
            'email' => 'qa.tp.ux.flow@example.test',
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createProfile(int $userId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO perfiles_usuario (usuario_id, primer_nombre, segundo_nombre, apellido_paterno, apellido_materno)
             VALUES (:usuario_id, :primer_nombre, :segundo_nombre, :apellido_paterno, :apellido_materno)'
        );
        $statement->execute([
            'usuario_id' => $userId,
            'primer_nombre' => 'Jesus',
            'segundo_nombre' => 'QA',
            'apellido_paterno' => 'Flujo',
            'apellido_materno' => null,
        ]);
    }

    private function createCompany(string $code, string $name, int $userId): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO empresas (codigo, nombre, activo, creado_por)
             VALUES (:codigo, :nombre, 1, :creado_por)'
        );
        $statement->execute([
            'codigo' => $code,
            'nombre' => $name,
            'creado_por' => $userId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createWarehouse(int $companyId, string $code, string $name, int $userId): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO almacenes (empresa_id, codigo, nombre, activo, creado_por)
             VALUES (:empresa_id, :codigo, :nombre, 1, :creado_por)'
        );
        $statement->execute([
            'empresa_id' => $companyId,
            'codigo' => $code,
            'nombre' => $name,
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
     * @return array<string, mixed>
     */
    private function firstSatUnit(): array
    {
        $row = $this->pdo->query(
            'SELECT id, codigo, nombre, descripcion FROM unidades_sat WHERE activo = 1 AND eliminado_en IS NULL ORDER BY id ASC LIMIT 1'
        )->fetch();

        if (!is_array($row)) {
            throw new RuntimeException('No SAT unit available for UX flow test.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function firstSatKey(): array
    {
        $row = $this->pdo->query(
            'SELECT id, codigo, descripcion FROM claves_sat WHERE activo = 1 AND eliminado_en IS NULL ORDER BY id ASC LIMIT 1'
        )->fetch();

        if (!is_array($row)) {
            throw new RuntimeException('No SAT key available for UX flow test.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function firstCurrency(): array
    {
        $row = $this->pdo->query(
            'SELECT id FROM monedas WHERE activo = 1 AND eliminado_en IS NULL ORDER BY es_base DESC, id ASC LIMIT 1'
        )->fetch();

        if (!is_array($row)) {
            throw new RuntimeException('No currency available for UX flow test.');
        }

        return $row;
    }

    /**
     * @return array<string, int>
     */
    private function operationalCounts(): array
    {
        return [
            'productos' => $this->countRows('productos'),
            'producto_precios' => $this->countRows('producto_precios'),
            'existencias_producto' => $this->countRows('existencias_producto'),
            'inventario_existencias' => $this->countRows('inventario_existencias'),
            'movimientos_inventario' => $this->countRows('movimientos_inventario'),
            'compras' => $this->countRows('compras'),
            'proveedores' => $this->countRows('proveedores'),
        ];
    }

    private function countRows(string $table): int
    {
        $exists = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table'
        );
        $exists->execute(['table' => $table]);

        if ((int) $exists->fetchColumn() !== 1) {
            return 0;
        }

        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    /**
     * @return list<string>
     */
    private function storageFiles(): array
    {
        $root = BASE_PATH . '/storage/private/tickets_productos';

        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @param list<string> $current
     * @param list<string> $baseline
     */
    private function deleteCreatedStorageFiles(array $current, array $baseline): void
    {
        $baselineMap = array_fill_keys($baseline, true);

        foreach ($current as $path) {
            if (!isset($baselineMap[$path]) && is_file($path)) {
                unlink($path);
            }
        }
    }

    private function deleteTempFiles(): void
    {
        foreach (array_unique($this->filesToDelete) as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->filesToDelete = [];
    }

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
        $path = BASE_PATH . '/' . ltrim($relativePath, '/');
        $contents = is_file($path) ? file_get_contents($path) : false;

        return is_string($contents) ? $contents : '';
    }
};
