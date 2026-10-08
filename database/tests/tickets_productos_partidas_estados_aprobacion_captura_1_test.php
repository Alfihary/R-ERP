<?php

declare(strict_types=1);

use App\Core\View;
use App\Domain\Tickets\ProductRequestTicketService;
use App\Domain\Tickets\ProductRequestTicketValidationException;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\ProductRequestTicketRepository;
use App\Support\Security\CsrfTokenService;

return new class implements DatabaseTest {
    private PDO $pdo;
    private ProductRequestTicketRepository $repository;
    private ProductRequestTicketService $service;

    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $this->pdo = $pdo;
        $this->repository = new ProductRequestTicketRepository(
            $GLOBALS['tp_product_ticket_approval_capture_connection']
        );
        $this->service = new ProductRequestTicketService($this->repository);

        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
            throw new RuntimeException('Unexpected active database for TP-PARTIDAS-ESTADOS-APROBACION-CAPTURA-1.');
        }

        $before = $this->operationalCounts();
        $results = [];

        try {
            $pdo->beginTransaction();
            $fixture = $this->fixture();
            $ticket = $this->createTicket($fixture, [
                ['descripcion' => 'Evaporador solicitado'],
                ['descripcion' => 'Compresor para rechazo'],
            ]);
            $foreignTicket = $this->createTicket($fixture, [
                ['descripcion' => 'Partida ajena'],
            ]);
            $partidas = $this->partidas((int) $ticket['id']);
            $foreignPartida = $this->partidas((int) $foreignTicket['id'])[0];

            $html = [
                'allowed' => $this->renderShow($ticket, true),
                'limited' => $this->renderShow($ticket, false),
                'resolved' => $this->renderShow($this->ticketWithState($ticket, $partidas[0], 'APROBADA'), true),
            ];

            $results['ui'] = [
                'approval_section_exists' => str_contains($html['allowed'], 'Aprobar partidas')
                    && str_contains($html['allowed'], 'Completa la respuesta de aprobación por cada partida antes de aprobar el ticket.'),
                'only_en_revision_lines_show_approval' =>
                    str_contains($html['allowed'], '/partidas/' . $partidas[0]['id'] . '/aprobar')
                    && str_contains($html['allowed'], '/partidas/' . $partidas[1]['id'] . '/aprobar')
                    && !str_contains($html['resolved'], '/partidas/' . $partidas[0]['id'] . '/aprobar'),
                'hidden_without_resolve_permission' =>
                    !str_contains($html['limited'], 'Aprobar partidas')
                    && !str_contains($html['limited'], '/aprobar'),
                'uses_existing_approval_route' => str_contains(
                    $html['allowed'],
                    'action="/tickets/productos/' . $ticket['id'] . '/partidas/' . $partidas[0]['id'] . '/aprobar"'
                ),
                'csrf_present' => str_contains($html['allowed'], 'name="_token"'),
                'authorized_key_field' => str_contains($html['allowed'], 'name="clave_autorizada"')
                    && str_contains($html['allowed'], 'maxlength="16"'),
                'authorized_description_field' => str_contains($html['allowed'], 'name="descripcion_autorizada"')
                    && str_contains($html['allowed'], 'required'),
                'authorized_unit_single_searchable_field' =>
                    str_contains($html['allowed'], 'name="unidad_sat_autorizada"')
                    && str_contains($html['allowed'], 'list="ticket-producto-unidades-sat-autorizadas"'),
                'authorized_sat_key_single_searchable_field' =>
                    str_contains($html['allowed'], 'name="clave_sat_autorizada"')
                    && str_contains($html['allowed'], 'list="ticket-producto-claves-sat-autorizadas"'),
                'no_separate_sat_search_fields' =>
                    !str_contains($html['allowed'], 'unidad_sat_busqueda')
                    && !str_contains($html['allowed'], 'clave_sat_busqueda'),
                'default_response_text' => str_contains(
                    $html['allowed'],
                    'Producto autorizado para captura manual en catálogo.'
                ),
                'no_internal_paths' => !$this->containsAny($html['allowed'], [
                    'storage/private',
                    'ruta_relativa',
                    'nombre_guardado',
                    'C:\\',
                    '/var/',
                ]),
            ];

            $invalidUnit = $this->failsWithField(
                fn () => $this->service->resolverPartida(
                    (int) $ticket['id'],
                    (int) $partidas[0]['id'],
                    'APROBAR',
                    [
                        'descripcion_autorizada' => 'Evaporador autorizado',
                        'unidad_sat_autorizada' => 'UNIDAD-SAT-INEXISTENTE',
                        'clave_sat_autorizada' => $fixture['sat_key_code'],
                    ],
                    $fixture['user_id']
                ),
                'unidad_sat_autorizada'
            );
            $invalidSatKey = $this->failsWithField(
                fn () => $this->service->resolverPartida(
                    (int) $ticket['id'],
                    (int) $partidas[0]['id'],
                    'APROBAR',
                    [
                        'descripcion_autorizada' => 'Evaporador autorizado',
                        'unidad_sat_autorizada' => $fixture['unit_code'],
                        'clave_sat_autorizada' => '99999999',
                    ],
                    $fixture['user_id']
                ),
                'clave_sat_autorizada'
            );
            $invalidKey = $this->failsWithField(
                fn () => $this->service->resolverPartida(
                    (int) $ticket['id'],
                    (int) $partidas[0]['id'],
                    'APROBAR',
                    [
                        'clave_autorizada' => 'CLAVE CON ESPACIO',
                        'descripcion_autorizada' => 'Evaporador autorizado',
                    ],
                    $fixture['user_id']
                ),
                'clave_autorizada'
            );
            $foreignLine = $this->failsWithField(
                fn () => $this->service->resolverPartida(
                    (int) $ticket['id'],
                    (int) $foreignPartida['id'],
                    'APROBAR',
                    [
                        'descripcion_autorizada' => 'Partida ajena autorizada',
                    ],
                    $fixture['user_id']
                ),
                'partida_id'
            );

            $approved = $this->service->resolverPartida(
                (int) $ticket['id'],
                (int) $partidas[0]['id'],
                'APROBAR',
                [
                    'clave_autorizada' => 'EVAP-001',
                    'descripcion_autorizada' => 'Evaporador autorizado final',
                    'unidad_sat_autorizada' => $fixture['unit_code'] . ' - ' . $fixture['unit_name'],
                    'clave_sat_autorizada' => $fixture['sat_key_code'] . ' - ' . $fixture['sat_key_description'],
                    'comentario_resolucion' => 'Producto autorizado para captura manual en catálogo.',
                ],
                $fixture['user_id']
            );
            $approvedLine = $this->partidaById((int) $partidas[0]['id']);
            $cannotApproveTwice = $this->failsWithField(
                fn () => $this->service->resolverPartida(
                    (int) $ticket['id'],
                    (int) $partidas[0]['id'],
                    'APROBAR',
                    [
                        'descripcion_autorizada' => 'Segundo intento',
                    ],
                    $fixture['user_id']
                ),
                'partida_id'
            );
            $rejected = $this->service->resolverPartida(
                (int) $ticket['id'],
                (int) $partidas[1]['id'],
                'RECHAZAR',
                [
                    'motivo_rechazo' => 'No cumple ficha técnica.',
                    'comentario_resolucion' => 'Revisar especificación técnica.',
                ],
                $fixture['user_id']
            );
            $during = $this->operationalCounts();

            $results['backend'] = [
                'invalid_unit_rejected_422_contract' => $invalidUnit,
                'invalid_sat_key_rejected_422_contract' => $invalidSatKey,
                'invalid_authorized_key_rejected' => $invalidKey,
                'foreign_line_rejected' => $foreignLine,
                'already_resolved_line_rejected' => $cannotApproveTwice,
                'authorized_data_saved' =>
                    (string) $approvedLine['clave_autorizada'] === 'EVAP-001'
                    && (string) $approvedLine['descripcion_autorizada'] === 'Evaporador autorizado final'
                    && (int) $approvedLine['unidad_sat_id_autorizada'] === $fixture['unit_id']
                    && (int) $approvedLine['clave_sat_id_autorizada'] === $fixture['sat_key_id'],
                'resolution_comment_saved' =>
                    (string) $approvedLine['comentario_resolucion'] === 'Producto autorizado para captura manual en catálogo.',
                'line_is_approved' => (string) $approvedLine['estado'] === 'APROBADA',
                'ticket_recalculated_after_partial_resolution' =>
                    (string) $approved['estado'] === 'EN_REVISION'
                    && (int) $approved['partidas_aprobadas'] === 1
                    && (int) $approved['partidas_en_revision'] === 1,
                'ticket_recalculated_after_all_resolved' =>
                    (string) $rejected['estado'] === 'RESUELTO_PARCIAL'
                    && (int) $rejected['partidas_aprobadas'] === 1
                    && (int) $rejected['partidas_rechazadas'] === 1,
                'reject_still_works' => (string) $this->partidaById((int) $partidas[1]['id'])['estado'] === 'RECHAZADA',
            ];

            $results['static_contract'] = [
                'repository_uses_prepared_statements' => substr_count(
                    $this->read('app/Infrastructure/Repositories/ProductRequestTicketRepository.php'),
                    '->prepare('
                ) >= 10,
                'controller_has_no_sql' => preg_match(
                    '/\b(SELECT|INSERT|UPDATE|DELETE|ALTER)\b/i',
                    $this->read('app/Http/Controllers/ProductRequestTicketController.php')
                ) !== 1,
                'existing_approval_route_preserved' => str_contains(
                    $this->read('routes/web.php'),
                    "'/tickets/productos/{id}/partidas/{partidaId}/aprobar'"
                ),
                'no_external_ticket_js_created' => !$this->hasFiles(
                    'public/js',
                    '/tickets.*productos|productos.*tickets/i'
                ),
                'attachments_runtime_not_removed' =>
                    is_file(BASE_PATH . '/database/tickets-productos-partidas-estados-adjuntos-runtime.php')
                    && str_contains($this->read('app/Views/tickets/productos/show.php'), 'enctype="multipart/form-data"'),
            ];

            $results['guardrails'] = [
                'no_product_created' => $before['productos'] === $during['productos'],
                'no_price_created' => $before['producto_precios'] === $during['producto_precios'],
                'no_stock_created' => $before['existencias_producto'] === $during['existencias_producto'],
                'no_inventory_created' => $before['inventario_existencias'] === $during['inventario_existencias'],
                'no_inventory_movement_created' => $before['movimientos_inventario'] === $during['movimientos_inventario'],
                'no_purchase_created' => $before['compras'] === $during['compras'],
                'no_supplier_created' => $before['proveedores'] === $during['proveedores'],
            ];

            $pdo->rollBack();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }

        $after = $this->operationalCounts();

        return [
            'database' => $expectedDatabase,
            'migration_columns' => $this->columnPresence(),
            'cases' => $results,
            'operational_counts_before' => $before,
            'operational_counts_after' => $after,
            'cleanup' => 'transaction_rolled_back_no_operational_data_written',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);
        $userId = $this->createUser('qa.tp.aprobacion.' . $suffix);
        $companyId = $this->createCompany($userId, 'TPAC' . substr(strtoupper($suffix), 0, 4));
        $warehouseId = $this->createWarehouse($companyId, $userId, 'AC' . substr(strtoupper($suffix), 0, 3));
        $unitId = $this->createSatUnit($userId, 'UA' . substr(strtoupper($suffix), 0, 4));
        $satKeyCode = '91' . substr((string) random_int(100000, 999999), 0, 6);
        $satKeyId = $this->createSatKey($userId, $satKeyCode);
        $currencyId = $this->currencyId();

        return [
            'user_id' => $userId,
            'company_id' => $companyId,
            'warehouse_id' => $warehouseId,
            'unit_id' => $unitId,
            'unit_code' => 'UA' . substr(strtoupper($suffix), 0, 4),
            'unit_name' => 'Unidad autorizada QA',
            'sat_key_id' => $satKeyId,
            'sat_key_code' => $satKeyCode,
            'sat_key_description' => 'Clave autorizada QA',
            'currency_id' => $currencyId,
        ];
    }

    /**
     * @param list<array<string, mixed>> $parts
     * @return array<string, mixed>
     */
    private function createTicket(array $fixture, array $parts): array
    {
        return $this->service->crearTicket([
            'empresa_id' => $fixture['company_id'],
            'almacen_id' => $fixture['warehouse_id'],
            'observaciones_generales' => 'Solicitud QA aprobación captura.',
            'partidas' => array_map(
                fn (array $part): array => [
                    'modelo' => $part['modelo'] ?? 'APR-CAP',
                    'marca_texto' => $part['marca_texto'] ?? 'Marca QA',
                    'descripcion' => $part['descripcion'],
                    'proveedor_texto' => $part['proveedor_texto'] ?? 'Proveedor QA',
                    'unidad_sat_id' => $fixture['unit_id'],
                    'clave_sat_id' => $fixture['sat_key_id'],
                    'moneda_id' => $fixture['currency_id'],
                    'costo_sugerido' => $part['costo_sugerido'] ?? '100.000000',
                    'peso' => $part['peso'] ?? '1.000000',
                    'lleva_serie' => $part['lleva_serie'] ?? false,
                    'observaciones' => $part['observaciones'] ?? 'Observación QA.',
                ],
                $parts
            ),
        ], $fixture['user_id']);
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
             ORDER BY numero_partida ASC'
        );
        $statement->execute(['ticket_id' => $ticketId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string, mixed>
     */
    private function partidaById(int $partidaId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT *
             FROM tickets_productos_partidas
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $partidaId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            throw new RuntimeException('Expected ticket line was not found.');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $ticket
     */
    private function renderShow(array $ticket, bool $canResolve): string
    {
        $this->ensureSession();
        $csrf = new CsrfTokenService(new App\Core\Session([
            'name' => session_name(),
            'same_site' => 'Lax',
            'secure' => false,
            'gc_max_lifetime' => 7200,
        ]), 7200);

        return View::render('tickets/productos/show', [
            'csrf' => $csrf,
            'errors' => [],
            'ticket' => $ticket,
            'permissions' => [
                'canResolve' => $canResolve,
                'canCancel' => false,
                'canViewAttachments' => true,
                'canCreateComments' => true,
                'canResendEmail' => false,
                'canViewEvents' => true,
            ],
            'approvalCatalogs' => [
                'sat_units' => $this->repository->activeSatUnits(),
                'sat_keys' => $this->repository->activeSatKeys(),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $ticket
     * @param array<string, mixed> $partida
     * @return array<string, mixed>
     */
    private function ticketWithState(array $ticket, array $partida, string $state): array
    {
        $ticket['partidas'] = [$partida + ['estado' => $state]];
        $ticket['partidas_en_revision'] = 0;
        $ticket['partidas_aprobadas'] = $state === 'APROBADA' ? 1 : 0;
        $ticket['partidas_rechazadas'] = $state === 'RECHAZADA' ? 1 : 0;

        return $ticket;
    }

    private function failsWithField(callable $callback, string $field): bool
    {
        try {
            $callback();
        } catch (ProductRequestTicketValidationException $exception) {
            return isset($exception->errors()[$field]);
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
            'password_hash' => password_hash($username, PASSWORD_DEFAULT),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createCompany(int $userId, string $code): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO empresas (codigo, nombre, activo, creado_por)
             VALUES (:codigo, :nombre, 1, :creado_por)'
        );
        $statement->execute([
            'codigo' => $code,
            'nombre' => 'Empresa QA aprobación captura',
            'creado_por' => $userId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createWarehouse(int $companyId, int $userId, string $code): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO almacenes (empresa_id, codigo, nombre, activo, creado_por)
             VALUES (:empresa_id, :codigo, :nombre, 1, :creado_por)'
        );
        $statement->execute([
            'empresa_id' => $companyId,
            'codigo' => $code,
            'nombre' => 'Almacén QA aprobación captura',
            'creado_por' => $userId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createSatUnit(int $userId, string $code): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO unidades_sat (codigo, nombre, creado_por)
             VALUES (:codigo, :nombre, :creado_por)'
        );
        $statement->execute([
            'codigo' => $code,
            'nombre' => 'Unidad autorizada QA',
            'creado_por' => $userId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createSatKey(int $userId, string $code): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO claves_sat (codigo, descripcion, creado_por)
             VALUES (:codigo, :descripcion, :creado_por)'
        );
        $statement->execute([
            'codigo' => $code,
            'descripcion' => 'Clave autorizada QA',
            'creado_por' => $userId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function currencyId(): int
    {
        $id = (int) $this->pdo->query(
            "SELECT id FROM monedas WHERE codigo = 'MXN' ORDER BY id LIMIT 1"
        )->fetchColumn();

        if ($id < 1) {
            throw new RuntimeException('TP-PARTIDAS-ESTADOS-APROBACION-CAPTURA-1 requires MXN currency.');
        }

        return $id;
    }

    /**
     * @return array<string, bool>
     */
    private function columnPresence(): array
    {
        return [
            'clave_autorizada' => $this->columnExists('tickets_productos_partidas', 'clave_autorizada'),
            'descripcion_autorizada' => $this->columnExists('tickets_productos_partidas', 'descripcion_autorizada'),
            'unidad_sat_id_autorizada' => $this->columnExists('tickets_productos_partidas', 'unidad_sat_id_autorizada'),
            'clave_sat_id_autorizada' => $this->columnExists('tickets_productos_partidas', 'clave_sat_id_autorizada'),
        ];
    }

    private function columnExists(string $table, string $column): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table
               AND column_name = :column'
        );
        $statement->execute(['table' => $table, 'column' => $column]);

        return (int) $statement->fetchColumn() === 1;
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
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table'
        );
        $statement->execute(['table' => $table]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        session_save_path(sys_get_temp_dir());
        session_name('tpappcap' . bin2hex(random_bytes(4)));
        session_id('tpappcap' . bin2hex(random_bytes(8)));
        session_start();
    }

    private function read(string $relativePath): string
    {
        $path = BASE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        return is_file($path) ? (string) file_get_contents($path) : '';
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
            if ($file instanceof SplFileInfo && preg_match($pattern, $file->getFilename()) === 1) {
                return true;
            }
        }

        return false;
    }
};
