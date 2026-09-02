<?php

declare(strict_types=1);

use App\Domain\Tickets\ProductRequestTicketService;
use App\Domain\Tickets\ProductRequestTicketValidationException;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Database\Migration;
use App\Infrastructure\Database\MigrationRunner;
use App\Infrastructure\Repositories\ProductRequestTicketRepository;

return new class implements DatabaseTest {
    private const MIGRATION = 'tp_partidas_estados_db_1_001_create_ticket_product_tables';

    private PDO $pdo;
    private string $database;
    private ProductRequestTicketService $service;

    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $this->pdo = $pdo;
        $this->database = $expectedDatabase;

        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
            throw new RuntimeException(
                'Unexpected active database for TP-PARTIDAS-ESTADOS-SERVICE-1.'
            );
        }

        $migration = require BASE_PATH
            . '/database/migrations/tp_partidas_estados_db_1_001_create_ticket_product_tables.php';

        if (!$migration instanceof Migration) {
            throw new RuntimeException('TP-PARTIDAS-ESTADOS-DB-1 migration contract is invalid.');
        }

        $runner = new MigrationRunner($pdo);
        $migrationState = $this->ensureTicketTables($runner, $migration);
        $this->service = new ProductRequestTicketService(
            new ProductRequestTicketRepository(
                $GLOBALS['tp_product_ticket_service_connection']
            )
        );

        $before = $this->operationalCounts();
        $results = [];

        try {
            $this->pdo->beginTransaction();

            try {
                $fixture = $this->fixture();

                $results['static_contract'] = $this->staticContractCases();
                $results['create_ticket'] = $this->createTicketCases($fixture);
                $results['resolve_ticket'] = $this->resolveTicketCases($fixture);
                $results['state_recalculation'] = $this->stateCases($fixture);
                $results['cancel_ticket'] = $this->cancelCases($fixture);
                $results['validation'] = $this->validationCases($fixture);
                $results['read_model'] = $this->readModelCases($fixture);
                $results['events'] = $this->eventCases();
                $during = $this->operationalCounts();
                $results['guardrails'] = $this->guardrailCases($before, $during);
            } finally {
                $this->pdo->rollBack();
            }

            $after = $this->operationalCounts();
            $results['cleanup'] = [
                'transaction_rolled_back' => $before === $after,
                'no_operational_counts_changed' => $before === $after,
            ];

            if (!$this->allTrue($results)) {
                throw new RuntimeException(
                    'TP-PARTIDAS-ESTADOS-SERVICE-1 assertions failed: '
                    . json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                );
            }

            return [
                'database' => $expectedDatabase,
                'migration' => self::MIGRATION,
                'migration_state' => $migrationState,
                'cases' => $results,
                'folio_sample' => 'GU-000010',
                'ticket_states' => [
                    'EN_REVISION',
                    'RESUELTO_PARCIAL',
                    'APROBADO',
                    'RECHAZADO',
                    'CANCELADO',
                ],
                'line_states' => [
                    'EN_REVISION',
                    'APROBADA',
                    'RECHAZADA',
                ],
                'events' => [
                    'TICKET_CREADO',
                    'PARTIDA_AGREGADA',
                    'PARTIDA_APROBADA',
                    'PARTIDA_RECHAZADA',
                    'TICKET_CANCELADO',
                ],
                'operational_counts_before' => $before,
                'operational_counts_during' => $during ?? [],
                'operational_counts_after' => $after,
                'cleanup' => 'transaction_rolled_back_no_operational_data_written',
            ];
        } finally {
            if ($migrationState === 'applied_for_test') {
                $runner->rollback($migration);
            }
        }
    }

    /**
     * @return array<string, bool>
     */
    private function staticContractCases(): array
    {
        $service = $this->read('app/Domain/Tickets/ProductRequestTicketService.php');
        $repository = $this->read('app/Infrastructure/Repositories/ProductRequestTicketRepository.php');

        return [
            'service_created' => str_contains($service, 'final class ProductRequestTicketService'),
            'repository_created' => str_contains($repository, 'final class ProductRequestTicketRepository'),
            'has_crear_ticket' => str_contains($service, 'function crearTicket('),
            'has_resolver_partida' => str_contains($service, 'function resolverPartida('),
            'has_cancelar_ticket' => str_contains($service, 'function cancelarTicket('),
            'has_obtener_ticket' => str_contains($service, 'function obtenerTicket('),
            'uses_prepared_statements' => substr_count($repository, '->prepare(') >= 10,
            'uses_for_update' => str_contains($repository, 'FOR UPDATE'),
            'documents_folio_fallback' => $this->fileContains(
                'docs/tickets-productos-partidas-estados-service-1.md',
                'GU-000010'
            ),
            'no_routes_created' => !$this->hasFiles('routes', '/ticket.*producto/i'),
            'no_controllers_created' => !$this->hasFiles('app/Http/Controllers', '/Ticket.*Product/i'),
            'only_expected_ticket_ui_views_created' => $this->onlyExpectedFiles(
                'app/Views/tickets',
                '/\\.php$/i',
                [
                    'app/Views/tickets/productos/index.php',
                    'app/Views/tickets/productos/create.php',
                    'app/Views/tickets/productos/show.php',
                ]
            ),
            'no_mail_implemented' => !preg_match('/mail|smtp|correo/i', $service . $repository),
            'no_operational_writes' => !preg_match(
                '/\\b(INSERT|UPDATE|DELETE)\\s+(?:INTO\\s+)?(?:productos|producto_precios|existencias_producto|movimientos_inventario|compras|proveedores)\\b/i',
                $service . "\n" . $repository
            ),
        ];
    }

    /**
     * @param array<string, int> $fixture
     * @return array<string, bool>
     */
    private function createTicketCases(array $fixture): array
    {
        $ticket = $this->createTicket($fixture, [
            ['descripcion' => 'Producto documental uno', 'costo_sugerido' => '10.5', 'peso' => '1.25', 'lleva_serie' => true],
            ['descripcion' => 'Producto documental dos', 'modelo' => 'MDL-2'],
            ['descripcion' => 'Producto documental tres', 'marca_texto' => 'Marca QA'],
        ]);

        return [
            'folio_gu_000010' => $ticket['folio'] === 'GU-000010',
            'ticket_id_created' => (int) $ticket['id'] > 0,
            'initial_state' => $ticket['estado'] === 'EN_REVISION',
            'three_lines' => count($ticket['partidas']) === 3,
            'line_numbers' => array_column($ticket['partidas'], 'numero_partida') === [1, 2, 3],
            'line_initial_states' => $this->columnAll($ticket['partidas'], 'estado', 'EN_REVISION'),
            'initial_counters' =>
                (int) $ticket['total_partidas'] === 3
                && (int) $ticket['partidas_en_revision'] === 3
                && (int) $ticket['partidas_aprobadas'] === 0
                && (int) $ticket['partidas_rechazadas'] === 0,
            'folio_sequence_second_ticket' => $this->createTicket($fixture, [
                ['descripcion' => 'Producto documental cuatro'],
            ])['folio'] === 'GU-000011',
        ];
    }

    /**
     * @param array<string, int> $fixture
     * @return array<string, bool>
     */
    private function resolveTicketCases(array $fixture): array
    {
        $ticket = $this->createTicket($fixture, [
            ['descripcion' => 'Partida aprobable'],
            ['descripcion' => 'Partida rechazable'],
        ]);
        $firstLineId = (int) $ticket['partidas'][0]['id'];
        $secondLineId = (int) $ticket['partidas'][1]['id'];
        $afterApprove = $this->service->resolverPartida(
            (int) $ticket['id'],
            $firstLineId,
            'APROBAR',
            ['comentario_resolucion' => 'Ficha validada.'],
            $fixture['active_user_id']
        );
        $afterReject = $this->service->resolverPartida(
            (int) $ticket['id'],
            $secondLineId,
            'RECHAZAR',
            [
                'motivo_rechazo' => 'Ficha técnica insuficiente.',
                'comentario_resolucion' => 'No incluye capacidad.',
            ],
            $fixture['active_user_id']
        );

        return [
            'approve_line' => $afterApprove['partidas'][0]['estado'] === 'APROBADA',
            'approve_null_reject_reason' =>
                $afterApprove['partidas'][0]['motivo_rechazo'] === null,
            'with_pending_ticket_stays_review' => $afterApprove['estado'] === 'EN_REVISION',
            'reject_line' => $afterReject['partidas'][1]['estado'] === 'RECHAZADA',
            'reject_reason_stored' =>
                $afterReject['partidas'][1]['motivo_rechazo'] === 'Ficha técnica insuficiente.',
            'partial_when_no_pending' => $afterReject['estado'] === 'RESUELTO_PARCIAL',
            'counters_after_resolution' =>
                (int) $afterReject['partidas_en_revision'] === 0
                && (int) $afterReject['partidas_aprobadas'] === 1
                && (int) $afterReject['partidas_rechazadas'] === 1,
            'reject_without_reason_fails' => $this->fails(
                fn () => $this->service->resolverPartida(
                    (int) $ticket['id'],
                    $secondLineId,
                    'RECHAZAR',
                    [],
                    $fixture['active_user_id']
                )
            ),
            'already_resolved_fails' => $this->fails(
                fn () => $this->service->resolverPartida(
                    (int) $ticket['id'],
                    $firstLineId,
                    'APROBAR',
                    [],
                    $fixture['active_user_id']
                )
            ),
        ];
    }

    /**
     * @param array<string, int> $fixture
     * @return array<string, bool>
     */
    private function stateCases(array $fixture): array
    {
        $approved = $this->createTicket($fixture, [
            ['descripcion' => 'Aprobada uno'],
            ['descripcion' => 'Aprobada dos'],
        ]);
        foreach ($approved['partidas'] as $line) {
            $approved = $this->service->resolverPartida(
                (int) $approved['id'],
                (int) $line['id'],
                'APROBAR',
                [],
                $fixture['active_user_id']
            );
        }

        $rejected = $this->createTicket($fixture, [
            ['descripcion' => 'Rechazada uno'],
            ['descripcion' => 'Rechazada dos'],
        ]);
        foreach ($rejected['partidas'] as $line) {
            $rejected = $this->service->resolverPartida(
                (int) $rejected['id'],
                (int) $line['id'],
                'RECHAZAR',
                ['motivo_rechazo' => 'No procede documentalmente.'],
                $fixture['active_user_id']
            );
        }

        $pending = $this->createTicket($fixture, [
            ['descripcion' => 'Pendiente uno'],
            ['descripcion' => 'Pendiente dos'],
        ]);
        $pending = $this->service->resolverPartida(
            (int) $pending['id'],
            (int) $pending['partidas'][0]['id'],
            'APROBAR',
            [],
            $fixture['active_user_id']
        );

        return [
            'all_approved' => $approved['estado'] === 'APROBADO',
            'all_rejected' => $rejected['estado'] === 'RECHAZADO',
            'pending_keeps_review' => $pending['estado'] === 'EN_REVISION',
        ];
    }

    /**
     * @param array<string, int> $fixture
     * @return array<string, bool>
     */
    private function cancelCases(array $fixture): array
    {
        $ticket = $this->createTicket($fixture, [
            ['descripcion' => 'Partida para cancelar'],
        ]);
        $cancelled = $this->service->cancelarTicket(
            (int) $ticket['id'],
            'Solicitud duplicada.',
            $fixture['active_user_id']
        );

        return [
            'cancel_with_reason' => $cancelled['estado'] === 'CANCELADO',
            'cancel_reason_stored' =>
                $cancelled['motivo_cancelacion'] === 'Solicitud duplicada.',
            'cancel_user_stored' =>
                (int) $cancelled['cancelado_por_usuario_id'] === $fixture['active_user_id'],
            'cancel_without_reason_fails' => $this->fails(
                fn () => $this->service->cancelarTicket(
                    (int) $cancelled['id'],
                    '',
                    $fixture['active_user_id']
                )
            ),
            'resolve_cancelled_fails' => $this->fails(
                fn () => $this->service->resolverPartida(
                    (int) $cancelled['id'],
                    (int) $cancelled['partidas'][0]['id'],
                    'APROBAR',
                    [],
                    $fixture['active_user_id']
                )
            ),
        ];
    }

    /**
     * @param array<string, int> $fixture
     * @return array<string, bool>
     */
    private function validationCases(array $fixture): array
    {
        $otherTicket = $this->createTicket($fixture, [
            ['descripcion' => 'Ticket dueño'],
        ]);
        $foreignTicket = $this->createTicket($fixture, [
            ['descripcion' => 'Ticket ajeno'],
        ]);

        return [
            'inactive_user_create_fails' => $this->fails(
                fn () => $this->service->crearTicket([
                    'empresa_id' => $fixture['company_id'],
                    'almacen_id' => $fixture['warehouse_id'],
                    'partidas' => [['descripcion' => 'No debe crear']],
                ], $fixture['inactive_user_id'])
            ),
            'inactive_user_resolve_fails' => $this->fails(
                fn () => $this->service->resolverPartida(
                    (int) $otherTicket['id'],
                    (int) $otherTicket['partidas'][0]['id'],
                    'APROBAR',
                    [],
                    $fixture['inactive_user_id']
                )
            ),
            'inactive_user_cancel_fails' => $this->fails(
                fn () => $this->service->cancelarTicket(
                    (int) $otherTicket['id'],
                    'No debe cancelar.',
                    $fixture['inactive_user_id']
                )
            ),
            'wrong_warehouse_scope_fails' => $this->fails(
                fn () => $this->service->crearTicket([
                    'empresa_id' => $fixture['company_id'],
                    'almacen_id' => $fixture['foreign_warehouse_id'],
                    'partidas' => [['descripcion' => 'Almacén ajeno']],
                ], $fixture['active_user_id'])
            ),
            'empty_description_fails' => $this->fails(
                fn () => $this->service->crearTicket([
                    'empresa_id' => $fixture['company_id'],
                    'almacen_id' => $fixture['warehouse_id'],
                    'partidas' => [['descripcion' => '']],
                ], $fixture['active_user_id'])
            ),
            'negative_cost_fails' => $this->fails(
                fn () => $this->service->crearTicket([
                    'empresa_id' => $fixture['company_id'],
                    'almacen_id' => $fixture['warehouse_id'],
                    'partidas' => [['descripcion' => 'Costo negativo', 'costo_sugerido' => '-1']],
                ], $fixture['active_user_id'])
            ),
            'negative_weight_fails' => $this->fails(
                fn () => $this->service->crearTicket([
                    'empresa_id' => $fixture['company_id'],
                    'almacen_id' => $fixture['warehouse_id'],
                    'partidas' => [['descripcion' => 'Peso negativo', 'peso' => '-1']],
                ], $fixture['active_user_id'])
            ),
            'without_lines_fails' => $this->fails(
                fn () => $this->service->crearTicket([
                    'empresa_id' => $fixture['company_id'],
                    'almacen_id' => $fixture['warehouse_id'],
                    'partidas' => [],
                ], $fixture['active_user_id'])
            ),
            'more_than_50_lines_fails' => $this->fails(
                fn () => $this->service->crearTicket([
                    'empresa_id' => $fixture['company_id'],
                    'almacen_id' => $fixture['warehouse_id'],
                    'partidas' => array_fill(0, 51, ['descripcion' => 'Exceso']),
                ], $fixture['active_user_id'])
            ),
            'missing_line_fails' => $this->fails(
                fn () => $this->service->resolverPartida(
                    (int) $otherTicket['id'],
                    999999999,
                    'APROBAR',
                    [],
                    $fixture['active_user_id']
                )
            ),
            'foreign_line_fails' => $this->fails(
                fn () => $this->service->resolverPartida(
                    (int) $otherTicket['id'],
                    (int) $foreignTicket['partidas'][0]['id'],
                    'APROBAR',
                    [],
                    $fixture['active_user_id']
                )
            ),
        ];
    }

    /**
     * @param array<string, int> $fixture
     * @return array<string, bool>
     */
    private function readModelCases(array $fixture): array
    {
        $ticket = $this->createTicket($fixture, [
            ['descripcion' => 'Con comentario y adjunto'],
        ]);
        $lineId = (int) $ticket['partidas'][0]['id'];
        $this->insertComment((int) $ticket['id'], $lineId, $fixture['active_user_id']);
        $this->insertAttachment((int) $ticket['id'], $lineId, $fixture['active_user_id']);
        $found = $this->service->obtenerTicket((int) $ticket['id']);

        return [
            'returns_ticket' => $found !== null,
            'returns_lines' => count($found['partidas'] ?? []) === 1,
            'returns_comments' => count($found['comentarios'] ?? []) === 1,
            'returns_attachments' => count($found['adjuntos'] ?? []) === 1,
            'returns_events' => count($found['eventos'] ?? []) >= 2,
            'attachment_no_absolute_path' =>
                !isset($found['adjuntos'][0]['ruta_absoluta'])
                && !preg_match('#^[A-Za-z]:[\\\\/]|^/#', (string) $found['adjuntos'][0]['ruta_relativa']),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function eventCases(): array
    {
        return [
            'ticket_created_event' => $this->eventCount('TICKET_CREADO') >= 1,
            'line_added_event' => $this->eventCount('PARTIDA_AGREGADA') >= 1,
            'line_approved_event' => $this->eventCount('PARTIDA_APROBADA') >= 1,
            'line_rejected_event' => $this->eventCount('PARTIDA_RECHAZADA') >= 1,
            'ticket_cancelled_event' => $this->eventCount('TICKET_CANCELADO') >= 1,
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
            'create_resolve_cancel_no_products' => $before['productos'] === $during['productos'],
            'create_resolve_cancel_no_prices' => $before['producto_precios'] === $during['producto_precios'],
            'create_resolve_cancel_no_stock' => $before['existencias_producto'] === $during['existencias_producto'],
            'create_resolve_cancel_no_inventory_movements' =>
                $before['movimientos_inventario'] === $during['movimientos_inventario'],
            'create_resolve_cancel_no_purchases' => $before['compras'] === $during['compras'],
            'create_resolve_cancel_no_suppliers' => $before['proveedores'] === $during['proveedores'],
        ];
    }

    /**
     * @param array<string, int> $fixture
     * @param list<array<string, mixed>> $parts
     * @return array<string, mixed>
     */
    private function createTicket(array $fixture, array $parts): array
    {
        return $this->service->crearTicket([
            'empresa_id' => $fixture['company_id'],
            'almacen_id' => $fixture['warehouse_id'],
            'observaciones_generales' => 'Solicitud documental QA.',
            'partidas' => array_map(
                fn (array $part): array => [
                    'modelo' => $part['modelo'] ?? 'QA-MODELO',
                    'marca_texto' => $part['marca_texto'] ?? 'Marca QA',
                    'descripcion' => $part['descripcion'],
                    'proveedor_texto' => $part['proveedor_texto'] ?? 'Proveedor documental',
                    'unidad_sat_id' => $fixture['unit_id'],
                    'clave_sat_id' => $fixture['sat_key_id'],
                    'moneda_id' => $fixture['currency_id'],
                    'costo_sugerido' => $part['costo_sugerido'] ?? '100.000000',
                    'peso' => $part['peso'] ?? '2.000000',
                    'lleva_serie' => $part['lleva_serie'] ?? false,
                    'observaciones' => $part['observaciones'] ?? 'Partida documental.',
                ],
                $parts
            ),
        ], $fixture['active_user_id']);
    }

    /**
     * @return array<string, int>
     */
    private function fixture(): array
    {
        $activeUserId = $this->createUser('qa.tp.service.active', 1);
        $inactiveUserId = $this->createUser('qa.tp.service.inactive', 0);
        $companyId = $this->createCompany($activeUserId, 'QATPSVC');
        $otherCompanyId = $this->createCompany($activeUserId, 'QATPSV2');
        $warehouseId = $this->createWarehouse($companyId, $activeUserId, 'GU');
        $foreignWarehouseId = $this->createWarehouse($otherCompanyId, $activeUserId, 'MT');
        $unitId = $this->createSatUnit($activeUserId);
        $satKeyId = $this->createSatKey($activeUserId);
        $currencyId = $this->currencyId();

        return [
            'active_user_id' => $activeUserId,
            'inactive_user_id' => $inactiveUserId,
            'company_id' => $companyId,
            'other_company_id' => $otherCompanyId,
            'warehouse_id' => $warehouseId,
            'foreign_warehouse_id' => $foreignWarehouseId,
            'unit_id' => $unitId,
            'sat_key_id' => $satKeyId,
            'currency_id' => $currencyId,
        ];
    }

    private function ensureTicketTables(
        MigrationRunner $runner,
        Migration $migration
    ): string {
        if ($this->tableExists('tickets_productos')) {
            return 'already_available';
        }

        $result = $runner->migrate($migration);

        if ($result !== 'applied') {
            throw new RuntimeException('TP-PARTIDAS-ESTADOS-SERVICE-1 could not prepare ticket tables.');
        }

        return 'applied_for_test';
    }

    private function createUser(string $username, int $active): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO usuarios (
                username,
                email,
                password_hash,
                activo,
                creado_en
             ) VALUES (
                :username,
                :email,
                :password_hash,
                :activo,
                CURRENT_TIMESTAMP
             )'
        );
        $statement->execute([
            'username' => $username,
            'email' => $username . '@example.test',
            'password_hash' => password_hash($username, PASSWORD_DEFAULT),
            'activo' => $active,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createCompany(int $adminId, string $code): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO empresas (codigo, nombre, activo, creado_por)
             VALUES (:codigo, :nombre, 1, :creado_por)'
        );
        $statement->execute([
            'codigo' => $code,
            'nombre' => 'Empresa QA TP Service ' . $code,
            'creado_por' => $adminId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createWarehouse(int $companyId, int $adminId, string $code): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO almacenes (empresa_id, codigo, nombre, activo, creado_por)
             VALUES (:empresa_id, :codigo, :nombre, 1, :creado_por)'
        );
        $statement->execute([
            'empresa_id' => $companyId,
            'codigo' => $code,
            'nombre' => 'Almacén QA TP Service ' . $code,
            'creado_por' => $adminId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createSatUnit(int $adminId): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO unidades_sat (codigo, nombre, creado_por)
             VALUES (:codigo, :nombre, :creado_por)'
        );
        $statement->execute([
            'codigo' => 'QATPSV',
            'nombre' => 'Unidad QA TP Service',
            'creado_por' => $adminId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createSatKey(int $adminId): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO claves_sat (codigo, descripcion, creado_por)
             VALUES (:codigo, :descripcion, :creado_por)'
        );
        $statement->execute([
            'codigo' => '01010199',
            'descripcion' => 'Clave QA TP Service',
            'creado_por' => $adminId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function currencyId(): int
    {
        $id = (int) $this->pdo->query(
            "SELECT id FROM monedas WHERE codigo = 'MXN' ORDER BY id LIMIT 1"
        )->fetchColumn();

        if ($id <= 0) {
            throw new RuntimeException('TP-PARTIDAS-ESTADOS-SERVICE-1 requires MXN currency.');
        }

        return $id;
    }

    private function insertComment(int $ticketId, int $lineId, int $userId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO tickets_productos_comentarios (
                ticket_producto_id,
                partida_id,
                usuario_id,
                comentario,
                visibilidad,
                created_at
             ) VALUES (
                :ticket_producto_id,
                :partida_id,
                :usuario_id,
                :comentario,
                :visibilidad,
                CURRENT_TIMESTAMP
             )'
        );
        $statement->execute([
            'ticket_producto_id' => $ticketId,
            'partida_id' => $lineId,
            'usuario_id' => $userId,
            'comentario' => 'Comentario QA.',
            'visibilidad' => 'INTERNA',
        ]);
    }

    private function insertAttachment(int $ticketId, int $lineId, int $userId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO tickets_productos_adjuntos (
                ticket_producto_id,
                partida_id,
                subido_por_usuario_id,
                nombre_original,
                nombre_guardado,
                ruta_relativa,
                mime,
                extension,
                tamano_bytes,
                hash_sha256,
                created_at
             ) VALUES (
                :ticket_producto_id,
                :partida_id,
                :subido_por_usuario_id,
                :nombre_original,
                :nombre_guardado,
                :ruta_relativa,
                :mime,
                :extension,
                :tamano_bytes,
                :hash_sha256,
                CURRENT_TIMESTAMP
             )'
        );
        $statement->execute([
            'ticket_producto_id' => $ticketId,
            'partida_id' => $lineId,
            'subido_por_usuario_id' => $userId,
            'nombre_original' => 'ficha.pdf',
            'nombre_guardado' => 'qa-ficha.pdf',
            'ruta_relativa' => 'tickets-productos/GU-000010/ficha.pdf',
            'mime' => 'application/pdf',
            'extension' => 'pdf',
            'tamano_bytes' => 1024,
            'hash_sha256' => str_repeat('b', 64),
        ]);
    }

    private function eventCount(string $event): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM tickets_productos_eventos
             WHERE evento = :evento'
        );
        $statement->execute(['evento' => $event]);

        return (int) $statement->fetchColumn();
    }

    private function fails(callable $operation): bool
    {
        try {
            $operation();
        } catch (ProductRequestTicketValidationException) {
            return true;
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function columnAll(array $rows, string $column, mixed $expected): bool
    {
        foreach ($rows as $row) {
            if (($row[$column] ?? null) !== $expected) {
                return false;
            }
        }

        return true;
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
             WHERE TABLE_SCHEMA = :schema_name
               AND TABLE_NAME = :table_name'
        );
        $statement->execute(['schema_name' => $this->database, 'table_name' => $table]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function read(string $relativePath): string
    {
        $path = BASE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    private function fileContains(string $relativePath, string $needle): bool
    {
        return str_contains($this->read($relativePath), $needle);
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
