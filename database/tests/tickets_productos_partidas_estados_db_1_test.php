<?php

declare(strict_types=1);

use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Database\Migration;
use App\Infrastructure\Database\MigrationRunner;

return new class implements DatabaseTest {
    private const MIGRATION = 'tp_partidas_estados_db_1_001_create_ticket_product_tables';
    private const TABLES = [
        'tickets_productos',
        'tickets_productos_partidas',
        'tickets_productos_adjuntos',
        'tickets_productos_comentarios',
        'tickets_productos_eventos',
    ];

    private PDO $pdo;
    private string $database;

    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $this->pdo = $pdo;
        $this->database = $expectedDatabase;

        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
            throw new RuntimeException('Unexpected active database for TP-PARTIDAS-ESTADOS-DB-1.');
        }

        $migration = require BASE_PATH
            . '/database/migrations/tp_partidas_estados_db_1_001_create_ticket_product_tables.php';

        if (!$migration instanceof Migration) {
            throw new RuntimeException('TP-PARTIDAS-ESTADOS-DB-1 migration contract is invalid.');
        }

        $runner = new MigrationRunner($pdo);
        $this->cleanupMigration($runner, $migration);

        $countsBefore = $this->operationalCounts();
        $results = [];

        try {
            $migrateResult = $runner->migrate($migration);

            if ($migrateResult !== 'applied') {
                throw new RuntimeException('TP-PARTIDAS-ESTADOS-DB-1 migration was not applied.');
            }

            $results['schema'] = $this->schemaCases();
            $results['constraints'] = $this->constraintCases();
            $results['documentary_data'] = $this->documentaryDataCases();
            $results['guardrails'] = $this->guardrailCases($countsBefore);
            $results['documentation'] = $this->documentationCases();
            $countsDuring = $this->operationalCounts();
        } finally {
            $rollbackResult = $runner->rollback($migration);
        }

        $countsAfter = $this->operationalCounts();

        if ($rollbackResult !== 'rolled_back') {
            throw new RuntimeException('TP-PARTIDAS-ESTADOS-DB-1 rollback did not run.');
        }

        if (!$this->allTrue($results)) {
            throw new RuntimeException(
                'TP-PARTIDAS-ESTADOS-DB-1 assertions failed: '
                . json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        if ($countsBefore !== $countsAfter) {
            throw new RuntimeException('TP-PARTIDAS-ESTADOS-DB-1 changed operational counts.');
        }

        return [
            'database' => $expectedDatabase,
            'migration' => self::MIGRATION,
            'migrate' => $migrateResult,
            'rollback' => $rollbackResult,
            'cases' => $results,
            'tables_created' => self::TABLES,
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
            'operational_counts_before' => $countsBefore,
            'operational_counts_during' => $countsDuring ?? [],
            'operational_counts_after' => $countsAfter,
            'cleanup' => 'migration_rolled_back_no_operational_data_written',
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function schemaCases(): array
    {
        return [
            'tables_exist' => $this->tablesExist(self::TABLES),
            'ticket_columns_exist' => $this->columnsExist('tickets_productos', [
                'id',
                'folio',
                'empresa_id',
                'almacen_id',
                'solicitante_usuario_id',
                'estado',
                'observaciones_generales',
                'total_partidas',
                'partidas_en_revision',
                'partidas_aprobadas',
                'partidas_rechazadas',
                'cancelado_por_usuario_id',
                'cancelado_at',
                'motivo_cancelacion',
                'created_at',
                'updated_at',
                'deleted_at',
            ]),
            'line_columns_exist' => $this->columnsExist('tickets_productos_partidas', [
                'id',
                'ticket_producto_id',
                'numero_partida',
                'estado',
                'modelo',
                'marca_texto',
                'descripcion',
                'proveedor_id',
                'proveedor_texto',
                'unidad_sat_id',
                'clave_sat_id',
                'moneda_id',
                'costo_sugerido',
                'peso',
                'lleva_serie',
                'observaciones',
                'resuelto_por_usuario_id',
                'resuelto_at',
                'comentario_resolucion',
                'motivo_rechazo',
                'created_at',
                'updated_at',
                'deleted_at',
            ]),
            'attachment_columns_exist' => $this->columnsExist('tickets_productos_adjuntos', [
                'id',
                'ticket_producto_id',
                'partida_id',
                'subido_por_usuario_id',
                'nombre_original',
                'nombre_guardado',
                'ruta_relativa',
                'mime',
                'extension',
                'tamano_bytes',
                'hash_sha256',
                'created_at',
                'deleted_at',
            ]),
            'comment_columns_exist' => $this->columnsExist('tickets_productos_comentarios', [
                'id',
                'ticket_producto_id',
                'partida_id',
                'usuario_id',
                'comentario',
                'visibilidad',
                'created_at',
                'deleted_at',
            ]),
            'event_columns_exist' => $this->columnsExist('tickets_productos_eventos', [
                'id',
                'ticket_producto_id',
                'partida_id',
                'usuario_id',
                'evento',
                'descripcion',
                'metadata_json',
                'created_at',
            ]),
            'engines_are_innodb' => $this->enginesAreInnoDb(self::TABLES),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function constraintCases(): array
    {
        return [
            'unique_folio_exists' => $this->indexExists('tickets_productos', 'uq_tickets_productos_folio', true),
            'unique_line_number_exists' => $this->indexExists(
                'tickets_productos_partidas',
                'uq_tickets_productos_partidas_numero',
                true
            ),
            'ticket_indexes_exist' =>
                $this->indexExists('tickets_productos', 'idx_tickets_productos_empresa')
                && $this->indexExists('tickets_productos', 'idx_tickets_productos_almacen')
                && $this->indexExists('tickets_productos', 'idx_tickets_productos_solicitante')
                && $this->indexExists('tickets_productos', 'idx_tickets_productos_estado')
                && $this->indexExists('tickets_productos', 'idx_tickets_productos_created_at')
                && $this->indexExists('tickets_productos', 'idx_tickets_productos_deleted_at'),
            'line_indexes_exist' =>
                $this->indexExists('tickets_productos_partidas', 'idx_tickets_productos_partidas_ticket')
                && $this->indexExists('tickets_productos_partidas', 'idx_tickets_productos_partidas_estado')
                && $this->indexExists('tickets_productos_partidas', 'idx_tickets_productos_partidas_proveedor_id')
                && $this->indexExists('tickets_productos_partidas', 'idx_tickets_productos_partidas_unidad_sat')
                && $this->indexExists('tickets_productos_partidas', 'idx_tickets_productos_partidas_clave_sat')
                && $this->indexExists('tickets_productos_partidas', 'idx_tickets_productos_partidas_moneda')
                && $this->indexExists('tickets_productos_partidas', 'idx_tickets_productos_partidas_resuelto_por'),
            'attachment_comment_event_indexes_exist' =>
                $this->indexExists('tickets_productos_adjuntos', 'idx_tickets_productos_adjuntos_ticket')
                && $this->indexExists('tickets_productos_adjuntos', 'idx_tickets_productos_adjuntos_partida')
                && $this->indexExists('tickets_productos_comentarios', 'idx_tickets_productos_comentarios_ticket')
                && $this->indexExists('tickets_productos_comentarios', 'idx_tickets_productos_comentarios_partida')
                && $this->indexExists('tickets_productos_eventos', 'idx_tickets_productos_eventos_ticket')
                && $this->indexExists('tickets_productos_eventos', 'idx_tickets_productos_eventos_evento'),
            'foreign_keys_exist' => $this->containsAll($this->foreignKeys(), [
                'fk_tickets_productos_empresa',
                'fk_tickets_productos_almacen',
                'fk_tickets_productos_empresa_almacen',
                'fk_tickets_productos_solicitante',
                'fk_tickets_productos_cancelado_por',
                'fk_tickets_productos_partidas_ticket',
                'fk_tickets_productos_partidas_unidad_sat',
                'fk_tickets_productos_partidas_clave_sat',
                'fk_tickets_productos_partidas_moneda',
                'fk_tickets_productos_partidas_resuelto_por',
                'fk_tickets_productos_adjuntos_ticket',
                'fk_tickets_productos_adjuntos_partida',
                'fk_tickets_productos_adjuntos_subido_por',
                'fk_tickets_productos_comentarios_ticket',
                'fk_tickets_productos_comentarios_partida',
                'fk_tickets_productos_comentarios_usuario',
                'fk_tickets_productos_eventos_ticket',
                'fk_tickets_productos_eventos_partida',
                'fk_tickets_productos_eventos_usuario',
            ]),
            'checks_exist' => $this->containsAll($this->checks(), [
                'chk_tickets_productos_folio',
                'chk_tickets_productos_estado',
                'chk_tickets_productos_partidas_estado',
                'chk_tickets_productos_partidas_rechazo',
                'chk_tickets_productos_adjuntos_ruta',
                'chk_tickets_productos_eventos_evento',
            ]),
            'no_provider_fk_without_provider_table' => !$this->tableExists('proveedores')
                && !$this->containsAll($this->foreignKeys(), ['fk_tickets_productos_partidas_proveedor']),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function documentaryDataCases(): array
    {
        $this->pdo->beginTransaction();

        try {
            $adminId = $this->createQaUser();
            $companyId = $this->createCompany($adminId);
            $warehouseId = $this->createWarehouse($companyId, $adminId);
            $unitId = $this->createSatUnit($adminId);
            $satKeyId = $this->createSatKey($adminId);
            $currencyId = $this->currencyId();

            $ticketId = $this->insertTicket($companyId, $warehouseId, $adminId);
            $firstLineId = $this->insertLine($ticketId, 1, $unitId, $satKeyId, $currencyId);
            $secondLineId = $this->insertLine($ticketId, 2, $unitId, $satKeyId, $currencyId);

            $this->approveLine($firstLineId, $adminId);
            $this->rejectLine($secondLineId, $adminId);
            $this->insertAttachment($ticketId, $firstLineId, $adminId);
            $this->insertComment($ticketId, $secondLineId, $adminId);
            $this->insertEvent($ticketId, $firstLineId, $adminId);

            $results = [
                'can_insert_documentary_ticket' => $ticketId > 0,
                'folio_gu_000010_stored' => $this->scalar(
                    'SELECT folio FROM tickets_productos WHERE id = :id',
                    ['id' => $ticketId]
                ) === 'GU-000010',
                'can_insert_multiple_lines' => $this->countWhere(
                    'tickets_productos_partidas',
                    'ticket_producto_id = ' . (int) $ticketId
                ) === 2,
                'can_approve_line_as_data' => $this->scalar(
                    'SELECT estado FROM tickets_productos_partidas WHERE id = :id',
                    ['id' => $firstLineId]
                ) === 'APROBADA',
                'can_reject_line_with_reason' => $this->scalar(
                    'SELECT motivo_rechazo FROM tickets_productos_partidas WHERE id = :id',
                    ['id' => $secondLineId]
                ) === 'Ficha tecnica insuficiente.',
                'can_store_relative_attachment' => $this->scalar(
                    'SELECT ruta_relativa FROM tickets_productos_adjuntos WHERE ticket_producto_id = :id',
                    ['id' => $ticketId]
                ) === 'tickets-productos/GU-000010/ficha.pdf',
                'can_store_comment' => $this->countWhere(
                    'tickets_productos_comentarios',
                    'ticket_producto_id = ' . (int) $ticketId
                ) === 1,
                'can_store_event' => $this->countWhere(
                    'tickets_productos_eventos',
                    'ticket_producto_id = ' . (int) $ticketId
                ) === 1,
                'duplicate_folio_blocked' => $this->duplicateTicketBlocked($companyId, $warehouseId, $adminId),
                'duplicate_line_number_blocked' => $this->duplicateLineBlocked($ticketId),
                'invalid_rejection_without_reason_blocked' => $this->invalidRejectionBlocked($ticketId, $adminId),
            ];

            $this->pdo->rollBack();

            return $results;
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * @param array<string, int> $countsBefore
     * @return array<string, bool>
     */
    private function guardrailCases(array $countsBefore): array
    {
        $migrationSql = $this->read('database/migrations/tp_partidas_estados_db_1_001_create_ticket_product_tables.php');
        $countsAfter = $this->operationalCounts();

        return [
            'migration_does_not_insert_products' => !preg_match('/\\bINSERT\\s+INTO\\s+productos\\b/i', $migrationSql),
            'migration_does_not_insert_product_prices' =>
                !preg_match('/\\bINSERT\\s+INTO\\s+producto_precios\\b/i', $migrationSql),
            'migration_does_not_insert_inventory' =>
                !preg_match('/\\bINSERT\\s+INTO\\s+(existencias_producto|inventario_existencias|movimientos_inventario)\\b/i', $migrationSql),
            'migration_does_not_insert_purchases' => !preg_match('/\\bINSERT\\s+INTO\\s+compras\\b/i', $migrationSql),
            'migration_does_not_insert_suppliers' => !preg_match('/\\bINSERT\\s+INTO\\s+proveedores\\b/i', $migrationSql),
            'no_triggers_or_procedures' => $this->triggerCount() === 0 && $this->routineCount() === 0,
            'products_count_unchanged' => $countsBefore['productos'] === $countsAfter['productos'],
            'product_prices_count_unchanged' => $countsBefore['producto_precios'] === $countsAfter['producto_precios'],
            'stock_count_unchanged' => $countsBefore['existencias_producto'] === $countsAfter['existencias_producto'],
            'inventory_movements_count_unchanged' =>
                $countsBefore['movimientos_inventario'] === $countsAfter['movimientos_inventario'],
            'purchases_count_unchanged' => $countsBefore['compras'] === $countsAfter['compras'],
            'suppliers_count_unchanged' => $countsBefore['proveedores'] === $countsAfter['proveedores'],
            'allowed_private_routes_controller_for_ticket_products' =>
                $this->allowedTicketProductRoutes()
                && $this->fileExists('app/Http/Controllers/ProductRequestTicketController.php')
                && str_contains($this->read('bootstrap/app.php'), 'ProductRequestTicketController'),
            'no_unexpected_ticket_routes_or_controllers' =>
                $this->onlyExpectedFiles('app/Http/Controllers', '/Ticket|Solicitud|AltaProducto/i', [
                    'app/Http/Controllers/ProductRequestTicketController.php',
                ])
                && $this->allowedTicketProductRoutes(),
            'only_expected_ticket_ui_views_created' =>
                $this->onlyExpectedFiles('app/Views/tickets', '/\\.php$/i', [
                    'app/Views/tickets/productos/create.php',
                    'app/Views/tickets/productos/index.php',
                    'app/Views/tickets/productos/show.php',
                ]),
            'only_expected_ticket_css_created' =>
                $this->onlyExpectedFiles('public/css/modules', '/tickets.*productos|productos.*tickets/i', [
                    'public/css/modules/tickets-productos.css',
                ]),
            'no_ticket_js_or_mail_runtime' =>
                !$this->hasFiles('public/js', '/ticket|solicitud|alta/i'),
            'service_repository_allowed_after_service_phase' =>
                $this->onlyExpectedFiles('app/Domain/Tickets', '/\\.php$/i', [
                    'app/Domain/Tickets/ProductRequestTicketService.php',
                    'app/Domain/Tickets/ProductRequestTicketValidationException.php',
                ])
                && $this->onlyExpectedFiles('app/Infrastructure/Repositories', '/Ticket|Solicitud|AltaProducto/i', [
                    'app/Infrastructure/Repositories/ProductRequestTicketRepository.php',
                ]),
            'permission_seed_allowed' => $this->fileExists(
                'database/seeds/tickets_productos_partidas_estados_1_seed_permissions.php'
            ),
            'no_functional_ticket_seeds_created' =>
                $this->onlyExpectedFiles('database/seeds', '/ticket|solicitud|alta/i', [
                    'database/seeds/tickets_productos_partidas_estados_1_seed_permissions.php',
                ]),
            'no_ticket_mail_runtime_created' =>
                !$this->hasFiles('app/Domain/Mail', '/ticket|solicitud|alta/i')
                && !$this->hasFiles('app/Domain/Notifications', '/ticket|solicitud|alta/i'),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function documentationCases(): array
    {
        $doc = $this->read('docs/tickets-productos-partidas-estados-db-1.md');

        return [
            'documents_objective' => str_contains($doc, 'TP-PARTIDAS-ESTADOS-DB-1'),
            'documents_tables' => $this->containsAll($doc, self::TABLES),
            'documents_ticket_states' => $this->containsAll($doc, [
                'EN_REVISION',
                'RESUELTO_PARCIAL',
                'APROBADO',
                'RECHAZADO',
                'CANCELADO',
            ]),
            'documents_line_states' => $this->containsAll($doc, ['APROBADA', 'RECHAZADA']),
            'documents_folio' => str_contains($doc, 'GU-000010'),
            'documents_no_operational_creation' => $this->containsAll($doc, [
                'no crea productos reales',
                'no crea precios',
                'no crea inventario',
                'no crea compras',
                'no crea proveedores reales',
            ]),
            'documents_next_phase' => str_contains($doc, 'TP-PARTIDAS-ESTADOS-SERVICE-1'),
        ];
    }

    private function cleanupMigration(MigrationRunner $runner, Migration $migration): void
    {
        $runner->rollback($migration);

        foreach (self::TABLES as $table) {
            if ($this->tableExists($table)) {
                throw new RuntimeException('TP-PARTIDAS-ESTADOS-DB-1 cleanup failed for ' . $table . '.');
            }
        }
    }

    private function createQaUser(): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO usuarios (username, email, password_hash, activo)
             VALUES (:username, :email, :password_hash, 1)'
        );
        $statement->execute([
            'username' => 'qa_tp_db_1',
            'email' => 'qa_tp_db_1@example.test',
            'password_hash' => str_repeat('a', 60),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createCompany(int $adminId): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO empresas (codigo, nombre, activo, creado_por)
             VALUES (:codigo, :nombre, 1, :creado_por)'
        );
        $statement->execute([
            'codigo' => 'QA-TP-DB-1',
            'nombre' => 'QA TP DB 1',
            'creado_por' => $adminId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createWarehouse(int $companyId, int $adminId): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO almacenes (empresa_id, codigo, nombre, activo, creado_por)
             VALUES (:empresa_id, :codigo, :nombre, 1, :creado_por)'
        );
        $statement->execute([
            'empresa_id' => $companyId,
            'codigo' => 'GU',
            'nombre' => 'Guadalajara QA',
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
            'codigo' => 'QATPDB1',
            'nombre' => 'Unidad QA TP DB 1',
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
            'descripcion' => 'Clave QA TP DB 1',
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
            throw new RuntimeException('TP-PARTIDAS-ESTADOS-DB-1 requires MXN currency.');
        }

        return $id;
    }

    private function insertTicket(int $companyId, int $warehouseId, int $adminId): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO tickets_productos (
                folio,
                empresa_id,
                almacen_id,
                solicitante_usuario_id,
                estado,
                observaciones_generales,
                total_partidas,
                partidas_en_revision,
                created_at
             ) VALUES (
                :folio,
                :empresa_id,
                :almacen_id,
                :solicitante_usuario_id,
                :estado,
                :observaciones_generales,
                2,
                2,
                CURRENT_TIMESTAMP
             )'
        );
        $statement->execute([
            'folio' => 'GU-000010',
            'empresa_id' => $companyId,
            'almacen_id' => $warehouseId,
            'solicitante_usuario_id' => $adminId,
            'estado' => 'EN_REVISION',
            'observaciones_generales' => 'Solicitud documental QA.',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertLine(int $ticketId, int $number, int $unitId, int $satKeyId, int $currencyId): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO tickets_productos_partidas (
                ticket_producto_id,
                numero_partida,
                estado,
                modelo,
                marca_texto,
                descripcion,
                proveedor_texto,
                unidad_sat_id,
                clave_sat_id,
                moneda_id,
                costo_sugerido,
                peso,
                lleva_serie,
                observaciones,
                created_at
             ) VALUES (
                :ticket_producto_id,
                :numero_partida,
                :estado,
                :modelo,
                :marca_texto,
                :descripcion,
                :proveedor_texto,
                :unidad_sat_id,
                :clave_sat_id,
                :moneda_id,
                :costo_sugerido,
                :peso,
                :lleva_serie,
                :observaciones,
                CURRENT_TIMESTAMP
             )'
        );
        $statement->execute([
            'ticket_producto_id' => $ticketId,
            'numero_partida' => $number,
            'estado' => 'EN_REVISION',
            'modelo' => 'QA-MODELO-' . $number,
            'marca_texto' => 'Marca QA',
            'descripcion' => 'Producto documental solicitado ' . $number,
            'proveedor_texto' => 'Proveedor libre QA',
            'unidad_sat_id' => $unitId,
            'clave_sat_id' => $satKeyId,
            'moneda_id' => $currencyId,
            'costo_sugerido' => '123.450000',
            'peso' => '1.250000',
            'lleva_serie' => $number === 1 ? 1 : 0,
            'observaciones' => 'Partida documental.',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function approveLine(int $lineId, int $adminId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE tickets_productos_partidas
             SET estado = 'APROBADA',
                 resuelto_por_usuario_id = :usuario_id,
                 resuelto_at = CURRENT_TIMESTAMP,
                 comentario_resolucion = 'Aprobacion documental.'
             WHERE id = :id"
        );
        $statement->execute(['usuario_id' => $adminId, 'id' => $lineId]);
    }

    private function rejectLine(int $lineId, int $adminId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE tickets_productos_partidas
             SET estado = 'RECHAZADA',
                 resuelto_por_usuario_id = :usuario_id,
                 resuelto_at = CURRENT_TIMESTAMP,
                 comentario_resolucion = 'Rechazo documental.',
                 motivo_rechazo = 'Ficha tecnica insuficiente.'
             WHERE id = :id"
        );
        $statement->execute(['usuario_id' => $adminId, 'id' => $lineId]);
    }

    private function insertAttachment(int $ticketId, int $lineId, int $adminId): void
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
            'subido_por_usuario_id' => $adminId,
            'nombre_original' => 'ficha.pdf',
            'nombre_guardado' => 'qa-ficha.pdf',
            'ruta_relativa' => 'tickets-productos/GU-000010/ficha.pdf',
            'mime' => 'application/pdf',
            'extension' => 'pdf',
            'tamano_bytes' => 1024,
            'hash_sha256' => str_repeat('a', 64),
        ]);
    }

    private function insertComment(int $ticketId, int $lineId, int $adminId): void
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
            'usuario_id' => $adminId,
            'comentario' => 'Comentario documental QA.',
            'visibilidad' => 'INTERNA',
        ]);
    }

    private function insertEvent(int $ticketId, int $lineId, int $adminId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO tickets_productos_eventos (
                ticket_producto_id,
                partida_id,
                usuario_id,
                evento,
                descripcion,
                metadata_json,
                created_at
             ) VALUES (
                :ticket_producto_id,
                :partida_id,
                :usuario_id,
                :evento,
                :descripcion,
                :metadata_json,
                CURRENT_TIMESTAMP
             )'
        );
        $statement->execute([
            'ticket_producto_id' => $ticketId,
            'partida_id' => $lineId,
            'usuario_id' => $adminId,
            'evento' => 'PARTIDA_APROBADA',
            'descripcion' => 'Partida aprobada documentalmente.',
            'metadata_json' => '{"origen":"db-test"}',
        ]);
    }

    private function duplicateTicketBlocked(int $companyId, int $warehouseId, int $adminId): bool
    {
        try {
            $this->insertTicket($companyId, $warehouseId, $adminId);
        } catch (PDOException) {
            return true;
        }

        return false;
    }

    private function duplicateLineBlocked(int $ticketId): bool
    {
        try {
            $statement = $this->pdo->prepare(
                "INSERT INTO tickets_productos_partidas (
                    ticket_producto_id,
                    numero_partida,
                    estado,
                    descripcion,
                    created_at
                 ) VALUES (
                    :ticket_producto_id,
                    1,
                    'EN_REVISION',
                    'Duplicada',
                    CURRENT_TIMESTAMP
                 )"
            );
            $statement->execute(['ticket_producto_id' => $ticketId]);
        } catch (PDOException) {
            return true;
        }

        return false;
    }

    private function invalidRejectionBlocked(int $ticketId, int $adminId): bool
    {
        try {
            $statement = $this->pdo->prepare(
                "INSERT INTO tickets_productos_partidas (
                    ticket_producto_id,
                    numero_partida,
                    estado,
                    descripcion,
                    resuelto_por_usuario_id,
                    resuelto_at,
                    created_at
                 ) VALUES (
                    :ticket_producto_id,
                    99,
                    'RECHAZADA',
                    'Rechazo sin motivo',
                    :usuario_id,
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                 )"
            );
            $statement->execute([
                'ticket_producto_id' => $ticketId,
                'usuario_id' => $adminId,
            ]);
        } catch (PDOException) {
            return true;
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

    private function countWhere(string $table, string $where): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where)->fetchColumn();
    }

    /**
     * @param array<string, mixed> $params
     */
    private function scalar(string $sql, array $params): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    }

    /**
     * @param list<string> $tables
     */
    private function tablesExist(array $tables): bool
    {
        foreach ($tables as $table) {
            if (!$this->tableExists($table)) {
                return false;
            }
        }

        return true;
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

    /**
     * @param list<string> $columns
     */
    private function columnsExist(string $table, array $columns): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COLUMN_NAME
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = :schema_name
               AND TABLE_NAME = :table_name'
        );
        $statement->execute(['schema_name' => $this->database, 'table_name' => $table]);
        $existing = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));

        foreach ($columns as $column) {
            if (!in_array($column, $existing, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $tables
     */
    private function enginesAreInnoDb(array $tables): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT ENGINE
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = :schema_name
               AND TABLE_NAME = :table_name'
        );

        foreach ($tables as $table) {
            $statement->execute(['schema_name' => $this->database, 'table_name' => $table]);

            if ((string) $statement->fetchColumn() !== 'InnoDB') {
                return false;
            }
        }

        return true;
    }

    private function indexExists(string $table, string $index, bool $unique = false): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT NON_UNIQUE
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = :schema_name
               AND TABLE_NAME = :table_name
               AND INDEX_NAME = :index_name
             LIMIT 1'
        );
        $statement->execute([
            'schema_name' => $this->database,
            'table_name' => $table,
            'index_name' => $index,
        ]);
        $nonUnique = $statement->fetchColumn();

        if ($nonUnique === false) {
            return false;
        }

        return !$unique || (int) $nonUnique === 0;
    }

    /**
     * @return list<string>
     */
    private function foreignKeys(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT CONSTRAINT_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = :schema_name
               AND TABLE_NAME IN (
                   "tickets_productos",
                   "tickets_productos_partidas",
                   "tickets_productos_adjuntos",
                   "tickets_productos_comentarios",
                   "tickets_productos_eventos"
               )
               AND REFERENCED_TABLE_NAME IS NOT NULL'
        );
        $statement->execute(['schema_name' => $this->database]);

        return array_values(array_unique(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN))));
    }

    /**
     * @return list<string>
     */
    private function checks(): array
    {
        $statement = $this->pdo->prepare(
            'SELECT CONSTRAINT_NAME
             FROM information_schema.CHECK_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = :schema_name'
        );
        $statement->execute(['schema_name' => $this->database]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function triggerCount(): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = :schema_name
               AND EVENT_OBJECT_TABLE IN (
                   "tickets_productos",
                   "tickets_productos_partidas",
                   "tickets_productos_adjuntos",
                   "tickets_productos_comentarios",
                   "tickets_productos_eventos"
               )'
        );
        $statement->execute(['schema_name' => $this->database]);

        return (int) $statement->fetchColumn();
    }

    private function routineCount(): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.ROUTINES
             WHERE ROUTINE_SCHEMA = :schema_name
               AND ROUTINE_DEFINITION LIKE "%tickets_productos%"'
        );
        $statement->execute(['schema_name' => $this->database]);

        return (int) $statement->fetchColumn();
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

    private function fileExists(string $relativePath): bool
    {
        return is_file(BASE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
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
        ])
            && substr_count($routes, "'/tickets/productos'") === 2
            && substr_count($routes, "'/tickets/productos/crear'") === 1
            && substr_count($routes, "'/tickets/productos/{id}'") === 1
            && substr_count($routes, "'/tickets/productos/{id}/partidas/{partidaId}/aprobar'") === 1
            && substr_count($routes, "'/tickets/productos/{id}/partidas/{partidaId}/rechazar'") === 1
            && substr_count($routes, "'/tickets/productos/{id}/cancelar'") === 1
            && !str_contains($routes, '/tickets-productos');
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
     * @param list<string> $expected
     */
    private function onlyExpectedFiles(
        string $relativeDirectory,
        string $pattern,
        array $expected
    ): bool {
        $directory = BASE_PATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);

        if (!is_dir($directory)) {
            return $expected === [];
        }

        $files = [];
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
                $files[] = $relative;
            }
        }

        sort($files);
        sort($expected);

        return $files === $expected;
    }

    /**
     * @param list<string> $needles
     */
    private function containsAll(array|string $haystack, array $needles): bool
    {
        $text = is_array($haystack)
            ? "\n" . implode("\n", $haystack) . "\n"
            : $haystack;

        foreach ($needles as $needle) {
            if (!str_contains($text, $needle)) {
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
};
