<?php

declare(strict_types=1);

use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Database\Migration;
use App\Infrastructure\Database\MigrationRunner;
use App\Infrastructure\Database\Seed;

return new class implements DatabaseTest {
    private const TABLES = [
        'conceptos_movimiento_inventario',
        'movimientos_inventario',
        'movimientos_inventario_detalle',
        'existencias_producto',
    ];

    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $database = (string) $pdo->query(
            'SELECT DATABASE()'
        )->fetchColumn();

        if ($database !== $expectedDatabase) {
            throw new RuntimeException(
                'The active database does not match DB-INVENTARIO-1.'
            );
        }

        $migration = require BASE_PATH
            . '/database/migrations/'
            . 'inventario_1_001_create_inventory_core.php';
        $seed = require BASE_PATH
            . '/database/seeds/inventario_1_seed_conceptos.php';

        if (!$migration instanceof Migration || !$seed instanceof Seed) {
            throw new RuntimeException(
                'DB-INVENTARIO-1 artifacts have an invalid contract.'
            );
        }

        $runner = new MigrationRunner($pdo);
        $firstMigration = $runner->migrate($migration);
        $secondMigration = $runner->migrate($migration);
        $migrationRows = $this->migrationRows($pdo, $migration->id());
        $seedBefore = $this->seedActiveCount($pdo);
        $seed->run($pdo);
        $seedAfterFirst = $this->seedActiveCount($pdo);
        $seed->run($pdo);
        $seedAfterSecond = $this->seedActiveCount($pdo);

        $insertedFirst = max(0, $seedAfterFirst - $seedBefore);
        $insertedSecond = max(0, $seedAfterSecond - $seedAfterFirst);

        $tables = $this->tableMetadata($pdo);
        $columns = $this->columns($pdo);
        $indexes = $this->indexNames($pdo);
        $foreignKeys = $this->foreignKeys($pdo);
        $constraints = $this->constraintNames($pdo);
        $seedEvidence = $this->seedEvidence($pdo);
        $before = $this->counts($pdo);

        $this->assertStructure(
            $firstMigration,
            $secondMigration,
            $migrationRows,
            $insertedSecond,
            $tables,
            $columns,
            $indexes,
            $foreignKeys,
            $constraints,
            $seedEvidence
        );

        $functional = $this->functionalTest($pdo);

        if ($this->counts($pdo) !== $before) {
            throw new RuntimeException(
                'DB-INVENTARIO-1 left transient QA inventory data.'
            );
        }

        return [
            'database' => $database,
            'mysql_version' => (string) $pdo->query(
                'SELECT VERSION()'
            )->fetchColumn(),
            'migration' => [
                'id' => $migration->id(),
                'first_run' => $firstMigration,
                'second_run' => $secondMigration,
                'migration_rows' => $migrationRows,
            ],
            'tables' => array_column($tables, 'table_name'),
            'engines' => array_values(array_unique(array_column(
                $tables,
                'engine'
            ))),
            'collations' => array_values(array_unique(array_column(
                $tables,
                'table_collation'
            ))),
            'columns' => $columns,
            'indexes' => $indexes,
            'foreign_keys' => $foreignKeys,
            'constraints' => $constraints,
            'seed' => $seedEvidence + [
                'inserted_first_run' => $insertedFirst,
                'inserted_second_run' => $insertedSecond,
            ],
            'functional' => $functional,
            'persistent_counts_before' => $before,
            'persistent_counts_after' => $this->counts($pdo),
            'cleanup' => 'transaction_rolled_back',
            'automatic_sync' => false,
        ];
    }

    private function migrationRows(PDO $pdo, string $migration): int
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM schema_migrations
             WHERE migration = :migration'
        );
        $statement->execute(['migration' => $migration]);

        return (int) $statement->fetchColumn();
    }

    private function seedActiveCount(PDO $pdo): int
    {
        return (int) $pdo->query(
            "SELECT COUNT(*)
             FROM conceptos_movimiento_inventario
             WHERE codigo IN ('ENTRADA_AJUSTE', 'SALIDA_AJUSTE')
               AND activo = 1
               AND eliminado_en IS NULL"
        )->fetchColumn();
    }

    /**
     * @return array{
     *     codes: list<string>,
     *     active_rows: int,
     *     total_rows: int,
     *     duplicate_codes: int,
     *     nature_by_code: array<string, string>
     * }
     */
    private function seedEvidence(PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT codigo, naturaleza
             FROM conceptos_movimiento_inventario
             WHERE codigo IN ('ENTRADA_AJUSTE', 'SALIDA_AJUSTE')
               AND activo = 1
               AND eliminado_en IS NULL
             ORDER BY codigo"
        )->fetchAll();

        return [
            'codes' => array_column($rows, 'codigo'),
            'active_rows' => count($rows),
            'total_rows' => (int) $pdo->query(
                "SELECT COUNT(*)
                 FROM conceptos_movimiento_inventario
                 WHERE codigo IN ('ENTRADA_AJUSTE', 'SALIDA_AJUSTE')"
            )->fetchColumn(),
            'duplicate_codes' => (int) $pdo->query(
                'SELECT COUNT(*) FROM (
                    SELECT codigo
                    FROM conceptos_movimiento_inventario
                    GROUP BY codigo
                    HAVING COUNT(*) > 1
                 ) duplicates'
            )->fetchColumn(),
            'nature_by_code' => array_column($rows, 'naturaleza', 'codigo'),
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private function tableMetadata(PDO $pdo): array
    {
        $placeholders = implode(
            ', ',
            array_fill(0, count(self::TABLES), '?')
        );
        $statement = $pdo->prepare(
            "SELECT
                TABLE_NAME AS table_name,
                ENGINE AS engine,
                TABLE_COLLATION AS table_collation
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name IN ($placeholders)
             ORDER BY table_name"
        );
        $statement->execute(self::TABLES);

        return $statement->fetchAll();
    }

    /**
     * @return array<string, array<string, string|null>>
     */
    private function columns(PDO $pdo): array
    {
        $placeholders = implode(
            ', ',
            array_fill(0, count(self::TABLES), '?')
        );
        $statement = $pdo->prepare(
            "SELECT
                TABLE_NAME AS table_name,
                COLUMN_NAME AS column_name,
                COLUMN_TYPE AS column_type,
                IS_NULLABLE AS is_nullable,
                COLUMN_DEFAULT AS column_default,
                CHARACTER_SET_NAME AS character_set,
                COLLATION_NAME AS collation_name,
                EXTRA AS extra
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name IN ($placeholders)
             ORDER BY table_name, ordinal_position"
        );
        $statement->execute(self::TABLES);
        $result = [];

        foreach ($statement->fetchAll() as $row) {
            $result[$row['table_name'] . '.' . $row['column_name']] = [
                'column_type' => $row['column_type'],
                'is_nullable' => $row['is_nullable'],
                'column_default' => $row['column_default'],
                'character_set' => $row['character_set'],
                'collation_name' => $row['collation_name'],
                'extra' => $row['extra'],
            ];
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function indexNames(PDO $pdo): array
    {
        $placeholders = implode(
            ', ',
            array_fill(0, count(self::TABLES), '?')
        );
        $statement = $pdo->prepare(
            "SELECT DISTINCT CONCAT(table_name, '.', index_name)
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name IN ($placeholders)
             ORDER BY 1"
        );
        $statement->execute(self::TABLES);

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @return array<string, string>
     */
    private function foreignKeys(PDO $pdo): array
    {
        $placeholders = implode(
            ', ',
            array_fill(0, count(self::TABLES), '?')
        );
        $statement = $pdo->prepare(
            "SELECT
                CONSTRAINT_NAME AS constraint_name,
                TABLE_NAME AS table_name,
                GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION)
                    AS columns_list,
                REFERENCED_TABLE_NAME AS referenced_table_name,
                GROUP_CONCAT(
                    REFERENCED_COLUMN_NAME ORDER BY ORDINAL_POSITION
                ) AS referenced_columns
             FROM information_schema.key_column_usage
             WHERE table_schema = DATABASE()
               AND table_name IN ($placeholders)
               AND referenced_table_name IS NOT NULL
             GROUP BY
                constraint_name,
                table_name,
                referenced_table_name
             ORDER BY constraint_name"
        );
        $statement->execute(self::TABLES);
        $result = [];

        foreach ($statement->fetchAll() as $row) {
            $result[$row['constraint_name']] =
                $row['table_name'] . '.' . $row['columns_list']
                . '->' . $row['referenced_table_name']
                . '.' . $row['referenced_columns'];
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function constraintNames(PDO $pdo): array
    {
        $placeholders = implode(
            ', ',
            array_fill(0, count(self::TABLES), '?')
        );
        $statement = $pdo->prepare(
            "SELECT DISTINCT constraint_name
             FROM information_schema.table_constraints
             WHERE constraint_schema = DATABASE()
               AND table_name IN ($placeholders)
             ORDER BY constraint_name"
        );
        $statement->execute(self::TABLES);

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @param list<array<string, string>> $tables
     * @param array<string, array<string, string|null>> $columns
     * @param list<string> $indexes
     * @param array<string, string> $foreignKeys
     * @param list<string> $constraints
     * @param array<string, mixed> $seed
     */
    private function assertStructure(
        string $firstMigration,
        string $secondMigration,
        int $migrationRows,
        int $insertedSecond,
        array $tables,
        array $columns,
        array $indexes,
        array $foreignKeys,
        array $constraints,
        array $seed
    ): void {
        if (
            !in_array($firstMigration, ['applied', 'already_applied'], true)
            || $secondMigration !== 'already_applied'
            || $migrationRows !== 1
            || count($tables) !== count(self::TABLES)
            || $insertedSecond !== 0
            || array_column($tables, 'engine') !== array_fill(
                0,
                count(self::TABLES),
                'InnoDB'
            )
            || array_values(array_unique(array_column(
                $tables,
                'table_collation'
            ))) !== ['utf8mb4_unicode_ci']
        ) {
            throw new RuntimeException(
                'DB-INVENTARIO-1 migration or seed evidence is incomplete.'
            );
        }

        foreach ([
            'conceptos_movimiento_inventario.codigo' => ['varchar(32)', 'ascii', 'ascii_bin'],
            'conceptos_movimiento_inventario.naturaleza' => ['varchar(16)', 'ascii', 'ascii_bin'],
            'movimientos_inventario.estado' => ['varchar(16)', 'ascii', 'ascii_bin'],
            'movimientos_inventario_detalle.id_producto' => ['varchar(16)', 'ascii', 'ascii_bin'],
            'existencias_producto.id_producto' => ['varchar(16)', 'ascii', 'ascii_bin'],
        ] as $column => [$type, $charset, $collation]) {
            if (
                ($columns[$column]['column_type'] ?? null) !== $type
                || ($columns[$column]['character_set'] ?? null) !== $charset
                || ($columns[$column]['collation_name'] ?? null) !== $collation
            ) {
                throw new RuntimeException(
                    'DB-INVENTARIO-1 column contract is invalid: ' . $column
                );
            }
        }

        foreach ([
            'movimientos_inventario_detalle.cantidad' => 'decimal(18,6)',
            'existencias_producto.cantidad_actual' => 'decimal(18,6)',
            'movimientos_inventario.fecha_movimiento' => 'datetime',
        ] as $column => $type) {
            if (($columns[$column]['column_type'] ?? null) !== $type) {
                throw new RuntimeException(
                    'DB-INVENTARIO-1 type contract is invalid: ' . $column
                );
            }
        }

        foreach ([
            'conceptos_movimiento_inventario.uq_conceptos_movimiento_inventario_codigo',
            'movimientos_inventario.idx_movimientos_inventario_almacen_fecha',
            'movimientos_inventario.idx_movimientos_inventario_concepto',
            'movimientos_inventario.idx_movimientos_inventario_estado',
            'movimientos_inventario_detalle.uq_movimientos_inventario_detalle_producto',
            'existencias_producto.uq_existencias_producto_almacen_producto',
        ] as $required) {
            if (!in_array($required, $indexes, true)) {
                throw new RuntimeException(
                    'DB-INVENTARIO-1 missing index: ' . $required
                );
            }
        }

        foreach ([
            'fk_movimientos_inventario_empresa_almacen'
                => 'movimientos_inventario.empresa_id,almacen_id->almacenes.empresa_id,id',
            'fk_movimientos_inventario_concepto'
                => 'movimientos_inventario.concepto_movimiento_id->conceptos_movimiento_inventario.id',
            'fk_movimientos_inventario_detalle_movimiento'
                => 'movimientos_inventario_detalle.movimiento_id->movimientos_inventario.id',
            'fk_movimientos_inventario_detalle_producto'
                => 'movimientos_inventario_detalle.id_producto->productos.id_producto',
            'fk_existencias_producto_almacen'
                => 'existencias_producto.almacen_id->almacenes.id',
            'fk_existencias_producto_producto'
                => 'existencias_producto.id_producto->productos.id_producto',
        ] as $key => $value) {
            if (($foreignKeys[$key] ?? null) !== $value) {
                throw new RuntimeException(
                    'DB-INVENTARIO-1 missing FK: ' . $key
                );
            }
        }

        foreach ([
            'chk_conceptos_movimiento_inventario_codigo',
            'chk_conceptos_movimiento_inventario_naturaleza',
            'chk_movimientos_inventario_estado',
            'chk_movimientos_inventario_detalle_cantidad',
        ] as $required) {
            if (!in_array($required, $constraints, true)) {
                throw new RuntimeException(
                    'DB-INVENTARIO-1 missing constraint: ' . $required
                );
            }
        }

        if (
            $seed['codes'] !== ['ENTRADA_AJUSTE', 'SALIDA_AJUSTE']
            || $seed['active_rows'] !== 2
            || $seed['total_rows'] !== 2
            || $seed['duplicate_codes'] !== 0
            || $seed['nature_by_code'] !== [
                'ENTRADA_AJUSTE' => 'ENTRADA',
                'SALIDA_AJUSTE' => 'SALIDA',
            ]
        ) {
            throw new RuntimeException(
                'DB-INVENTARIO-1 seed evidence is invalid.'
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function functionalTest(PDO $pdo): array
    {
        $adminId = $this->adminId($pdo);
        $scope = $this->scope($pdo);
        $unitId = $this->unitId($pdo);
        $productTypeId = $this->productTypeId($pdo, 'PRODUCTO');
        $serviceTypeId = $this->productTypeId($pdo, 'SERVICIO');
        $conceptId = $this->conceptId($pdo, 'ENTRADA_AJUSTE');
        $exitConceptId = $this->conceptId($pdo, 'SALIDA_AJUSTE');
        $decimalResults = [];
        $failures = [];
        $deletionRestrictions = [];

        $pdo->beginTransaction();

        try {
            $this->insertProduct(
                $pdo,
                'QAINVPROD1',
                'Producto inv QA',
                $unitId,
                $productTypeId,
                $adminId
            );
            $this->insertProduct(
                $pdo,
                'QAINVSERV1',
                'Servicio inv QA',
                $unitId,
                $serviceTypeId,
                $adminId
            );

            $movementId = $this->insertMovement(
                $pdo,
                $scope['empresa_id'],
                $scope['almacen_id'],
                $conceptId,
                $adminId
            );
            $detailId = $this->insertDetail(
                $pdo,
                $movementId,
                'QAINVPROD1',
                '1.000000',
                $adminId
            );
            $existenceId = $this->insertExistence(
                $pdo,
                $scope['almacen_id'],
                'QAINVPROD1',
                '1.000000'
            );

            foreach ([
                'QAINVDEC000001' => '0.000001',
                'QAINVDEC000002' => '1.000000',
                'QAINVDEC000003' => '123456789.123456',
            ] as $productId => $value) {
                $this->insertProduct(
                    $pdo,
                    $productId,
                    'Decimal inv QA',
                    $unitId,
                    $productTypeId,
                    $adminId
                );
                $decimalMovementId = $this->insertMovement(
                    $pdo,
                    $scope['empresa_id'],
                    $scope['almacen_id'],
                    $exitConceptId,
                    $adminId
                );
                $this->insertDetail(
                    $pdo,
                    $decimalMovementId,
                    $productId,
                    $value,
                    $adminId
                );
                $this->insertExistence(
                    $pdo,
                    $scope['almacen_id'],
                    $productId,
                    $value
                );
                $decimalResults[$value] = [
                    'detalle' => $this->decimalValue(
                        $pdo,
                        'movimientos_inventario_detalle',
                        'cantidad',
                        'movimiento_id',
                        $decimalMovementId
                    ),
                    'existencia' => $this->decimalValue(
                        $pdo,
                        'existencias_producto',
                        'cantidad_actual',
                        'id_producto',
                        $productId
                    ),
                ];
            }

            foreach ([
                'concept_code_invalid' => fn () => $this->insertConcept(
                    $pdo,
                    'entrada',
                    'Entrada inválida',
                    'ENTRADA',
                    $adminId
                ),
                'concept_nature_invalid' => fn () => $this->insertConcept(
                    $pdo,
                    'QA_NAT',
                    'Naturaleza inválida',
                    'AJUSTE',
                    $adminId
                ),
                'movement_invalid_state' => fn () => $this->insertMovement(
                    $pdo,
                    $scope['empresa_id'],
                    $scope['almacen_id'],
                    $conceptId,
                    $adminId,
                    'EDITADO'
                ),
                'movement_unknown_concept' => fn () => $this->insertMovement(
                    $pdo,
                    $scope['empresa_id'],
                    $scope['almacen_id'],
                    999999999,
                    $adminId
                ),
                'movement_unknown_warehouse' => fn () => $this->insertMovement(
                    $pdo,
                    $scope['empresa_id'],
                    999999999,
                    $conceptId,
                    $adminId
                ),
                'movement_inconsistent_company' => fn () => $this->insertMovement(
                    $pdo,
                    999999999,
                    $scope['almacen_id'],
                    $conceptId,
                    $adminId
                ),
                'detail_zero_quantity' => fn () => $this->insertDetail(
                    $pdo,
                    $movementId,
                    'QAINVPROD1',
                    '0.000000',
                    $adminId
                ),
                'detail_negative_quantity' => fn () => $this->insertDetail(
                    $pdo,
                    $movementId,
                    'QAINVPROD1',
                    '-1.000000',
                    $adminId
                ),
                'detail_unknown_product' => fn () => $this->insertDetail(
                    $pdo,
                    $movementId,
                    'QAINV404',
                    '1.000000',
                    $adminId
                ),
                'detail_unknown_movement' => fn () => $this->insertDetail(
                    $pdo,
                    999999999,
                    'QAINVPROD1',
                    '1.000000',
                    $adminId
                ),
                'detail_duplicate_product' => fn () => $this->insertDetail(
                    $pdo,
                    $movementId,
                    'QAINVPROD1',
                    '2.000000',
                    $adminId
                ),
                'existence_duplicate' => fn () => $this->insertExistence(
                    $pdo,
                    $scope['almacen_id'],
                    'QAINVPROD1',
                    '2.000000'
                ),
                'existence_unknown_product' => fn () => $this->insertExistence(
                    $pdo,
                    $scope['almacen_id'],
                    'QAINV404',
                    '1.000000'
                ),
                'existence_unknown_warehouse' => fn () => $this->insertExistence(
                    $pdo,
                    999999999,
                    'QAINVPROD1',
                    '1.000000'
                ),
            ] as $label => $operation) {
                $this->expectFailure($operation, $label, $failures);
            }

            foreach ([
                'product_referenced_restricted' => fn () =>
                    $this->deleteProduct($pdo, 'QAINVPROD1'),
                'warehouse_referenced_restricted' => fn () =>
                    $this->deleteById($pdo, 'almacenes', $scope['almacen_id']),
                'concept_referenced_restricted' => fn () =>
                    $this->deleteById(
                        $pdo,
                        'conceptos_movimiento_inventario',
                        $conceptId
                    ),
                'movement_with_detail_restricted' => fn () =>
                    $this->deleteById(
                        $pdo,
                        'movimientos_inventario',
                        $movementId
                    ),
            ] as $label => $operation) {
                $this->expectFailure(
                    $operation,
                    $label,
                    $deletionRestrictions
                );
            }

            $validRows = [
                'movimiento_id' => $movementId,
                'detalle_id' => $detailId,
                'existencia_id' => $existenceId,
                'fecha_hora_persistida' => $this->hasDatetime(
                    $pdo,
                    $movementId
                ),
                'servicio_insertable_en_db' => $this->serviceCanBeReferenced(
                    $pdo,
                    $movementId,
                    'QAINVSERV1',
                    $adminId
                ),
            ];

            if (
                $validRows['fecha_hora_persistida'] !== true
                || $validRows['servicio_insertable_en_db'] !== true
            ) {
                throw new RuntimeException(
                    'DB-INVENTARIO-1 valid inventory rows are incomplete.'
                );
            }

            foreach ($decimalResults as $expected => $values) {
                if (
                    $values['detalle'] !== $expected
                    || $values['existencia'] !== $expected
                ) {
                    throw new RuntimeException(
                        'DB-INVENTARIO-1 decimal persistence failed.'
                    );
                }
            }

            $transientCounts = $this->counts($pdo);
            $pdo->rollBack();

            return [
                'concepts' => [
                    'ENTRADA_AJUSTE' => 'ENTRADA',
                    'SALIDA_AJUSTE' => 'SALIDA',
                    'invalid_code_rejected' =>
                        isset($failures['concept_code_invalid']),
                    'invalid_nature_rejected' =>
                        isset($failures['concept_nature_invalid']),
                ],
                'movements' => [
                    'valid_warehouse_accepted' => $movementId > 0,
                    'valid_concept_accepted' => $movementId > 0,
                    'datetime_persisted' =>
                        $validRows['fecha_hora_persistida'],
                    'valid_state_accepted' => true,
                    'invalid_state_rejected' =>
                        isset($failures['movement_invalid_state']),
                    'unknown_concept_rejected' =>
                        isset($failures['movement_unknown_concept']),
                    'unknown_warehouse_rejected' =>
                        isset($failures['movement_unknown_warehouse']),
                    'inconsistent_company_rejected' =>
                        isset($failures['movement_inconsistent_company']),
                ],
                'details' => [
                    'valid_product_accepted' => $detailId > 0,
                    'decimal_quantity_accepted' => true,
                    'zero_rejected' =>
                        isset($failures['detail_zero_quantity']),
                    'negative_rejected' =>
                        isset($failures['detail_negative_quantity']),
                    'unknown_product_rejected' =>
                        isset($failures['detail_unknown_product']),
                    'unknown_movement_rejected' =>
                        isset($failures['detail_unknown_movement']),
                    'duplicate_product_rejected' =>
                        isset($failures['detail_duplicate_product']),
                ],
                'existences' => [
                    'product_warehouse_accepted' => $existenceId > 0,
                    'single_row_per_product_warehouse' => true,
                    'duplicate_rejected' =>
                        isset($failures['existence_duplicate']),
                    'decimal_quantity_exact' => $decimalResults,
                    'unknown_product_rejected' =>
                        isset($failures['existence_unknown_product']),
                    'unknown_warehouse_rejected' =>
                        isset($failures['existence_unknown_warehouse']),
                ],
                'history_protected' => $deletionRestrictions,
                'service_product_rule_deferred' =>
                    $validRows['servicio_insertable_en_db'],
                'transient_counts' => $transientCounts,
            ];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    private function adminId(PDO $pdo): int
    {
        $id = (int) $pdo->query(
            "SELECT u.id
             FROM usuarios u
             INNER JOIN usuario_roles ur
                 ON ur.usuario_id = u.id
                AND ur.activo = 1
                AND ur.eliminado_en IS NULL
             INNER JOIN roles r
                 ON r.id = ur.rol_id
                AND r.codigo = 'ADMIN'
                AND r.activo = 1
                AND r.eliminado_en IS NULL
             WHERE u.activo = 1
               AND u.eliminado_en IS NULL
             ORDER BY u.id
             LIMIT 1"
        )->fetchColumn();

        if ($id < 1) {
            throw new RuntimeException('Active ADMIN user is required.');
        }

        return $id;
    }

    /**
     * @return array{empresa_id: int, almacen_id: int}
     */
    private function scope(PDO $pdo): array
    {
        $row = $pdo->query(
            'SELECT a.empresa_id, a.id AS almacen_id
             FROM almacenes a
             INNER JOIN empresas e ON e.id = a.empresa_id
             WHERE a.activo = 1
               AND a.eliminado_en IS NULL
               AND e.activo = 1
               AND e.eliminado_en IS NULL
             ORDER BY a.id
             LIMIT 1'
        )->fetch();

        if (!is_array($row)) {
            throw new RuntimeException('Active company/warehouse is required.');
        }

        return [
            'empresa_id' => (int) $row['empresa_id'],
            'almacen_id' => (int) $row['almacen_id'],
        ];
    }

    private function unitId(PDO $pdo): int
    {
        return $this->idByCode($pdo, 'unidades_medida', 'PIEZA');
    }

    private function productTypeId(PDO $pdo, string $code): int
    {
        return $this->idByCode($pdo, 'tipos_producto', $code);
    }

    private function conceptId(PDO $pdo, string $code): int
    {
        return $this->idByCode(
            $pdo,
            'conceptos_movimiento_inventario',
            $code
        );
    }

    private function idByCode(PDO $pdo, string $table, string $code): int
    {
        if (!in_array(
            $table,
            [
                'unidades_medida',
                'tipos_producto',
                'conceptos_movimiento_inventario',
            ],
            true
        )) {
            throw new InvalidArgumentException('Unsupported code table.');
        }

        $statement = $pdo->prepare(
            'SELECT id
             FROM ' . $table . '
             WHERE codigo = :codigo
               AND activo = 1
               AND eliminado_en IS NULL'
        );
        $statement->execute(['codigo' => $code]);
        $id = (int) $statement->fetchColumn();

        if ($id < 1) {
            throw new RuntimeException('Required code is missing: ' . $code);
        }

        return $id;
    }

    private function insertConcept(
        PDO $pdo,
        string $code,
        string $name,
        string $nature,
        int $actorId
    ): void {
        $statement = $pdo->prepare(
            'INSERT INTO conceptos_movimiento_inventario (
                codigo,
                nombre,
                naturaleza,
                creado_por
             )
             VALUES (
                :codigo,
                :nombre,
                :naturaleza,
                :creado_por
             )'
        );
        $statement->execute([
            'codigo' => $code,
            'nombre' => $name,
            'naturaleza' => $nature,
            'creado_por' => $actorId,
        ]);
    }

    private function insertProduct(
        PDO $pdo,
        string $productId,
        string $description,
        int $unitId,
        int $typeId,
        int $actorId
    ): void {
        $statement = $pdo->prepare(
            'INSERT INTO productos (
                id_producto,
                descripcion,
                unidad_medida_id,
                tipo_producto_id,
                creado_por
             )
             VALUES (
                :id_producto,
                :descripcion,
                :unidad_medida_id,
                :tipo_producto_id,
                :creado_por
             )'
        );
        $statement->execute([
            'id_producto' => $productId,
            'descripcion' => $description,
            'unidad_medida_id' => $unitId,
            'tipo_producto_id' => $typeId,
            'creado_por' => $actorId,
        ]);
    }

    private function insertMovement(
        PDO $pdo,
        int $companyId,
        int $warehouseId,
        int $conceptId,
        int $actorId,
        string $state = 'BORRADOR'
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO movimientos_inventario (
                empresa_id,
                almacen_id,
                concepto_movimiento_id,
                fecha_movimiento,
                estado,
                referencia,
                creado_por
             )
             VALUES (
                :empresa_id,
                :almacen_id,
                :concepto_movimiento_id,
                :fecha_movimiento,
                :estado,
                :referencia,
                :creado_por
             )'
        );
        $statement->execute([
            'empresa_id' => $companyId,
            'almacen_id' => $warehouseId,
            'concepto_movimiento_id' => $conceptId,
            'fecha_movimiento' => '2026-07-09 12:34:56',
            'estado' => $state,
            'referencia' => 'QA-INV-1',
            'creado_por' => $actorId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertDetail(
        PDO $pdo,
        int $movementId,
        string $productId,
        string $quantity,
        int $actorId
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO movimientos_inventario_detalle (
                movimiento_id,
                id_producto,
                cantidad,
                creado_por
             )
             VALUES (
                :movimiento_id,
                :id_producto,
                :cantidad,
                :creado_por
             )'
        );
        $statement->execute([
            'movimiento_id' => $movementId,
            'id_producto' => $productId,
            'cantidad' => $quantity,
            'creado_por' => $actorId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertExistence(
        PDO $pdo,
        int $warehouseId,
        string $productId,
        string $quantity
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO existencias_producto (
                almacen_id,
                id_producto,
                cantidad_actual
             )
             VALUES (
                :almacen_id,
                :id_producto,
                :cantidad_actual
             )'
        );
        $statement->execute([
            'almacen_id' => $warehouseId,
            'id_producto' => $productId,
            'cantidad_actual' => $quantity,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function decimalValue(
        PDO $pdo,
        string $table,
        string $column,
        string $filterColumn,
        int|string $filterValue
    ): string {
        if (
            !in_array(
                $table,
                ['movimientos_inventario_detalle', 'existencias_producto'],
                true
            )
            || !in_array($column, ['cantidad', 'cantidad_actual'], true)
            || !in_array(
                $filterColumn,
                ['movimiento_id', 'id_producto'],
                true
            )
        ) {
            throw new InvalidArgumentException('Unsupported decimal lookup.');
        }

        $statement = $pdo->prepare(
            'SELECT ' . $column . '
             FROM ' . $table . '
             WHERE ' . $filterColumn . ' = :filter_value
             LIMIT 1'
        );
        $statement->execute(['filter_value' => $filterValue]);

        return (string) $statement->fetchColumn();
    }

    private function hasDatetime(PDO $pdo, int $movementId): bool
    {
        $statement = $pdo->prepare(
            'SELECT fecha_movimiento
             FROM movimientos_inventario
             WHERE id = :id'
        );
        $statement->execute(['id' => $movementId]);

        return (string) $statement->fetchColumn() === '2026-07-09 12:34:56';
    }

    private function serviceCanBeReferenced(
        PDO $pdo,
        int $movementId,
        string $productId,
        int $actorId
    ): bool {
        $this->insertDetail(
            $pdo,
            $movementId,
            $productId,
            '3.000000',
            $actorId
        );

        return true;
    }

    private function deleteProduct(PDO $pdo, string $productId): void
    {
        $statement = $pdo->prepare(
            'DELETE FROM productos WHERE id_producto = :id_producto'
        );
        $statement->execute(['id_producto' => $productId]);
    }

    private function deleteById(PDO $pdo, string $table, int $id): void
    {
        if (!in_array(
            $table,
            [
                'almacenes',
                'conceptos_movimiento_inventario',
                'movimientos_inventario',
            ],
            true
        )) {
            throw new InvalidArgumentException('Unsupported delete table.');
        }

        $statement = $pdo->prepare(
            'DELETE FROM ' . $table . ' WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
    }

    /**
     * @param callable(): void $operation
     * @param array<string, array{sqlstate: string, driver_code: int|null}> $failures
     */
    private function expectFailure(
        callable $operation,
        string $label,
        array &$failures
    ): void {
        try {
            $operation();
        } catch (PDOException $exception) {
            $failures[$label] = [
                'sqlstate' => (string) $exception->getCode(),
                'driver_code' => isset($exception->errorInfo[1])
                    ? (int) $exception->errorInfo[1]
                    : null,
            ];
            return;
        }

        throw new RuntimeException(
            'DB-INVENTARIO-1 accepted invalid case: ' . $label
        );
    }

    /**
     * @return array<string, int>
     */
    private function counts(PDO $pdo): array
    {
        $counts = [];

        foreach (self::TABLES as $table) {
            $counts[$table] = (int) $pdo->query(
                'SELECT COUNT(*) FROM ' . $table
            )->fetchColumn();
        }

        foreach (['empresas', 'almacenes', 'productos'] as $table) {
            $counts[$table] = (int) $pdo->query(
                'SELECT COUNT(*) FROM ' . $table
            )->fetchColumn();
        }

        return $counts;
    }
};
