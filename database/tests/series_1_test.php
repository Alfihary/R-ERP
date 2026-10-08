<?php

declare(strict_types=1);

use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Database\Migration;
use App\Infrastructure\Database\MigrationRunner;

return new class implements DatabaseTest {
    private const TABLES = [
        'producto_series',
        'existencias_serie',
        'movimiento_detalle_series',
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
                'The active database does not match SERIES-DB-1.'
            );
        }

        $migration = require BASE_PATH
            . '/database/migrations/'
            . 'series_1_001_create_inventory_series_tables.php';

        if (!$migration instanceof Migration) {
            throw new RuntimeException(
                'SERIES-DB-1 migration has an invalid contract.'
            );
        }

        $runner = new MigrationRunner($pdo);
        $firstMigration = $runner->migrate($migration);
        $secondMigration = $runner->migrate($migration);
        $migrationRows = $this->migrationRows($pdo, $migration->id());
        $tables = $this->tableMetadata($pdo);
        $columns = $this->columns($pdo);
        $indexes = $this->indexNames($pdo);
        $foreignKeys = $this->foreignKeys($pdo);
        $constraints = $this->constraintNames($pdo);
        $before = $this->counts($pdo);

        $this->assertStructure(
            $firstMigration,
            $secondMigration,
            $migrationRows,
            $tables,
            $columns,
            $indexes,
            $foreignKeys,
            $constraints
        );

        $functional = $this->functionalTest($pdo);

        if ($this->counts($pdo) !== $before) {
            throw new RuntimeException(
                'SERIES-DB-1 left transient QA inventory data.'
            );
        }

        $rollbackResult = $runner->rollback($migration);
        $tablesAfterRollback = $this->existingTableCount($pdo);
        $migrationRowsAfterRollback = $this->migrationRows(
            $pdo,
            $migration->id()
        );
        $cleanRollbackResult = $runner->rollback($migration);
        $finalMigrateResult = $runner->migrate($migration);

        if (
            $rollbackResult !== 'rolled_back'
            || $tablesAfterRollback !== 0
            || $migrationRowsAfterRollback !== 0
            || $cleanRollbackResult !== 'not_applied'
            || $finalMigrateResult !== 'applied'
            || $this->migrationRows($pdo, $migration->id()) !== 1
            || $this->existingTableCount($pdo) !== count(self::TABLES)
        ) {
            throw new RuntimeException(
                'SERIES-DB-1 rollback/idempotence evidence is invalid.'
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
                'rollback' => $rollbackResult,
                'tables_after_rollback' => $tablesAfterRollback,
                'migration_rows_after_rollback' => $migrationRowsAfterRollback,
                'rollback_when_clean' => $cleanRollbackResult,
                'final_migrate' => $finalMigrateResult,
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
            'functional' => $functional,
            'persistent_counts_before' => $before,
            'persistent_counts_after' => $this->counts($pdo),
            'cleanup' => 'transaction_rolled_back',
            'functional_series_enforcement_active' => false,
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

    private function existingTableCount(PDO $pdo): int
    {
        $placeholders = implode(
            ', ',
            array_fill(0, count(self::TABLES), '?')
        );
        $statement = $pdo->prepare(
            "SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name IN ($placeholders)"
        );
        $statement->execute(self::TABLES);

        return (int) $statement->fetchColumn();
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
     */
    private function assertStructure(
        string $firstMigration,
        string $secondMigration,
        int $migrationRows,
        array $tables,
        array $columns,
        array $indexes,
        array $foreignKeys,
        array $constraints
    ): void {
        if (
            !in_array($firstMigration, ['applied', 'already_applied'], true)
            || $secondMigration !== 'already_applied'
            || $migrationRows !== 1
            || count($tables) !== count(self::TABLES)
            || array_values(array_unique(array_column(
                $tables,
                'engine'
            ))) !== ['InnoDB']
            || array_values(array_unique(array_column(
                $tables,
                'table_collation'
            ))) !== ['utf8mb4_unicode_ci']
        ) {
            throw new RuntimeException(
                'SERIES-DB-1 migration evidence is incomplete.'
            );
        }

        foreach ([
            'producto_series.id',
            'producto_series.id_producto',
            'producto_series.numero_serie',
            'producto_series.activo',
            'producto_series.eliminado_en',
            'producto_series.creado_en',
            'producto_series.actualizado_en',
            'existencias_serie.serie_id',
            'existencias_serie.almacen_id',
            'existencias_serie.estado',
            'existencias_serie.creado_en',
            'existencias_serie.actualizado_en',
            'movimiento_detalle_series.movimiento_detalle_id',
            'movimiento_detalle_series.serie_id',
            'movimiento_detalle_series.creado_en',
        ] as $column) {
            if (!array_key_exists($column, $columns)) {
                throw new RuntimeException(
                    'SERIES-DB-1 missing column: ' . $column
                );
            }
        }

        foreach ([
            'producto_series.id_producto' => ['varchar(16)', 'ascii', 'ascii_bin'],
            'producto_series.numero_serie' => ['varchar(80)', 'ascii', 'ascii_bin'],
            'existencias_serie.estado' => ['varchar(24)', 'ascii', 'ascii_bin'],
        ] as $column => [$type, $charset, $collation]) {
            if (
                ($columns[$column]['column_type'] ?? null) !== $type
                || ($columns[$column]['character_set'] ?? null) !== $charset
                || ($columns[$column]['collation_name'] ?? null) !== $collation
            ) {
                throw new RuntimeException(
                    'SERIES-DB-1 column contract is invalid: ' . $column
                );
            }
        }

        foreach ([
            'producto_series.PRIMARY',
            'producto_series.uq_producto_series_producto_numero',
            'producto_series.idx_producto_series_numero_serie',
            'existencias_serie.PRIMARY',
            'existencias_serie.idx_existencias_serie_almacen_estado',
            'movimiento_detalle_series.PRIMARY',
            'movimiento_detalle_series.idx_movimiento_detalle_series_serie',
        ] as $required) {
            if (!in_array($required, $indexes, true)) {
                throw new RuntimeException(
                    'SERIES-DB-1 missing index: ' . $required
                );
            }
        }

        foreach ([
            'fk_producto_series_producto'
                => 'producto_series.id_producto->productos.id_producto',
            'fk_existencias_serie_serie'
                => 'existencias_serie.serie_id->producto_series.id',
            'fk_existencias_serie_almacen'
                => 'existencias_serie.almacen_id->almacenes.id',
            'fk_movimiento_detalle_series_detalle'
                => 'movimiento_detalle_series.movimiento_detalle_id->movimientos_inventario_detalle.id',
            'fk_movimiento_detalle_series_serie'
                => 'movimiento_detalle_series.serie_id->producto_series.id',
        ] as $key => $value) {
            if (($foreignKeys[$key] ?? null) !== $value) {
                throw new RuntimeException(
                    'SERIES-DB-1 missing FK: ' . $key
                );
            }
        }

        foreach ([
            'chk_producto_series_numero_serie',
            'chk_producto_series_activo',
            'chk_existencias_serie_estado',
            'chk_existencias_serie_estado_almacen',
        ] as $required) {
            if (!in_array($required, $constraints, true)) {
                throw new RuntimeException(
                    'SERIES-DB-1 missing constraint: ' . $required
                );
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function functionalTest(PDO $pdo): array
    {
        $adminId = $this->adminId($pdo);
        $scope = $this->scope($pdo);
        $unitId = $this->idByCode($pdo, 'unidades_medida', 'PIEZA');
        $productTypeId = $this->idByCode($pdo, 'tipos_producto', 'PRODUCTO');
        $conceptId = $this->idByCode(
            $pdo,
            'conceptos_movimiento_inventario',
            'ENTRADA_AJUSTE'
        );
        $failures = [];

        $pdo->beginTransaction();

        try {
            $this->insertProduct(
                $pdo,
                'QASERPROD1',
                'Producto serie QA',
                $unitId,
                $productTypeId,
                $adminId,
                1
            );
            $this->insertProduct(
                $pdo,
                'QASERPROD2',
                'Producto serie QA 2',
                $unitId,
                $productTypeId,
                $adminId,
                1
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
                'QASERPROD1',
                '1.000000',
                $adminId
            );

            $seriesOne = $this->insertSeries(
                $pdo,
                'QASERPROD1',
                'SERIEQA001'
            );
            $seriesSameNumberOtherProduct = $this->insertSeries(
                $pdo,
                'QASERPROD2',
                'SERIEQA001'
            );
            $seriesOut = $this->insertSeries(
                $pdo,
                'QASERPROD1',
                'SERIEQA002'
            );
            $seriesForInvalidState = $this->insertSeries(
                $pdo,
                'QASERPROD1',
                'SERIEQA003'
            );
            $seriesForInvalidPair = $this->insertSeries(
                $pdo,
                'QASERPROD1',
                'SERIEQA004'
            );

            $this->insertSeriesExistence(
                $pdo,
                $seriesOne,
                $scope['almacen_id'],
                'EN_EXISTENCIA'
            );
            $this->insertSeriesExistence(
                $pdo,
                $seriesOut,
                null,
                'FUERA_EXISTENCIA'
            );
            $this->insertDetailSeries($pdo, $detailId, $seriesOne);

            foreach ([
                'duplicate_series_same_product' => fn () =>
                    $this->insertSeries($pdo, 'QASERPROD1', 'SERIEQA001'),
                'series_unknown_product' => fn () =>
                    $this->insertSeries($pdo, 'QASER404', 'SERIEQA404'),
                'series_empty_number' => fn () =>
                    $this->insertSeries($pdo, 'QASERPROD1', ''),
                'series_invalid_active' => fn () =>
                    $this->insertSeries(
                        $pdo,
                        'QASERPROD1',
                        'SERIEQA999',
                        2
                    ),
                'existence_invalid_state' => fn () =>
                    $this->insertSeriesExistence(
                        $pdo,
                        $seriesForInvalidState,
                        $scope['almacen_id'],
                        'RESERVADA'
                    ),
                'existence_in_stock_without_warehouse' => fn () =>
                    $this->insertSeriesExistence(
                        $pdo,
                        $seriesForInvalidPair,
                        null,
                        'EN_EXISTENCIA'
                    ),
                'existence_unknown_warehouse' => fn () =>
                    $this->insertSeriesExistence(
                        $pdo,
                        $seriesForInvalidPair,
                        999999999,
                        'EN_EXISTENCIA'
                    ),
                'existence_unknown_series' => fn () =>
                    $this->insertSeriesExistence(
                        $pdo,
                        999999999,
                        null,
                        'FUERA_EXISTENCIA'
                    ),
                'detail_series_unknown_series' => fn () =>
                    $this->insertDetailSeries($pdo, $detailId, 999999999),
                'detail_series_unknown_detail' => fn () =>
                    $this->insertDetailSeries($pdo, 999999999, $seriesOne),
                'detail_series_duplicate' => fn () =>
                    $this->insertDetailSeries($pdo, $detailId, $seriesOne),
            ] as $label => $operation) {
                $this->expectFailure($operation, $label, $failures);
            }

            $transientCounts = $this->counts($pdo);
            $pdo->rollBack();

            return [
                'same_serial_different_product_accepted' =>
                    $seriesSameNumberOtherProduct > 0,
                'duplicate_serial_same_product_rejected' =>
                    isset($failures['duplicate_series_same_product']),
                'valid_in_stock_state_accepted' => true,
                'valid_out_of_stock_state_accepted' => true,
                'invalid_state_rejected' =>
                    isset($failures['existence_invalid_state']),
                'in_stock_without_warehouse_rejected' =>
                    isset($failures['existence_in_stock_without_warehouse']),
                'unknown_warehouse_rejected' =>
                    isset($failures['existence_unknown_warehouse']),
                'unknown_series_rejected' =>
                    isset($failures['existence_unknown_series']),
                'detail_series_accepted' => true,
                'detail_series_unknown_series_rejected' =>
                    isset($failures['detail_series_unknown_series']),
                'detail_series_unknown_detail_rejected' =>
                    isset($failures['detail_series_unknown_detail']),
                'detail_series_duplicate_rejected' =>
                    isset($failures['detail_series_duplicate']),
                'expected_failures' => $failures,
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

    private function insertProduct(
        PDO $pdo,
        string $productId,
        string $description,
        int $unitId,
        int $typeId,
        int $actorId,
        int $tracksSeries
    ): void {
        $statement = $pdo->prepare(
            'INSERT INTO productos (
                id_producto,
                descripcion,
                unidad_medida_id,
                tipo_producto_id,
                controla_series,
                creado_por
             )
             VALUES (
                :id_producto,
                :descripcion,
                :unidad_medida_id,
                :tipo_producto_id,
                :controla_series,
                :creado_por
             )'
        );
        $statement->execute([
            'id_producto' => $productId,
            'descripcion' => $description,
            'unidad_medida_id' => $unitId,
            'tipo_producto_id' => $typeId,
            'controla_series' => $tracksSeries,
            'creado_por' => $actorId,
        ]);
    }

    private function insertMovement(
        PDO $pdo,
        int $companyId,
        int $warehouseId,
        int $conceptId,
        int $actorId
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
            'fecha_movimiento' => '2026-07-16 12:00:00',
            'estado' => 'BORRADOR',
            'referencia' => 'QA-SERIES-1',
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

    private function insertSeries(
        PDO $pdo,
        string $productId,
        string $number,
        int $active = 1
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO producto_series (
                id_producto,
                numero_serie,
                activo
             )
             VALUES (
                :id_producto,
                :numero_serie,
                :activo
             )'
        );
        $statement->execute([
            'id_producto' => $productId,
            'numero_serie' => $number,
            'activo' => $active,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertSeriesExistence(
        PDO $pdo,
        int $seriesId,
        ?int $warehouseId,
        string $state
    ): void {
        $statement = $pdo->prepare(
            'INSERT INTO existencias_serie (
                serie_id,
                almacen_id,
                estado
             )
             VALUES (
                :serie_id,
                :almacen_id,
                :estado
             )'
        );
        $statement->execute([
            'serie_id' => $seriesId,
            'almacen_id' => $warehouseId,
            'estado' => $state,
        ]);
    }

    private function insertDetailSeries(
        PDO $pdo,
        int $detailId,
        int $seriesId
    ): void {
        $statement = $pdo->prepare(
            'INSERT INTO movimiento_detalle_series (
                movimiento_detalle_id,
                serie_id
             )
             VALUES (
                :movimiento_detalle_id,
                :serie_id
             )'
        );
        $statement->execute([
            'movimiento_detalle_id' => $detailId,
            'serie_id' => $seriesId,
        ]);
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
            'SERIES-DB-1 accepted invalid case: ' . $label
        );
    }

    /**
     * @return array<string, int>
     */
    private function counts(PDO $pdo): array
    {
        return [
            'productos_qa' => $this->countLike(
                $pdo,
                'productos',
                'id_producto'
            ),
            'series_qa' => $this->countLike(
                $pdo,
                'producto_series',
                'numero_serie',
                'SERIEQA%'
            ),
            'existencias_serie_qa' => (int) $pdo->query(
                'SELECT COUNT(*)
                 FROM existencias_serie es
                 INNER JOIN producto_series ps ON ps.id = es.serie_id
                 WHERE ps.numero_serie LIKE \'SERIEQA%\''
            )->fetchColumn(),
            'movimiento_detalle_series_qa' => (int) $pdo->query(
                'SELECT COUNT(*)
                 FROM movimiento_detalle_series mds
                 INNER JOIN producto_series ps ON ps.id = mds.serie_id
                 WHERE ps.numero_serie LIKE \'SERIEQA%\''
            )->fetchColumn(),
            'movimientos_qa' => (int) $pdo->query(
                "SELECT COUNT(*)
                 FROM movimientos_inventario
                 WHERE referencia = 'QA-SERIES-1'"
            )->fetchColumn(),
            'detalles_qa' => (int) $pdo->query(
                "SELECT COUNT(*)
                 FROM movimientos_inventario_detalle mid
                 INNER JOIN movimientos_inventario mi
                    ON mi.id = mid.movimiento_id
                 WHERE mi.referencia = 'QA-SERIES-1'"
            )->fetchColumn(),
            'existencias_producto_qa' => $this->countLike(
                $pdo,
                'existencias_producto',
                'id_producto',
                'QASER%'
            ),
        ];
    }

    private function countLike(
        PDO $pdo,
        string $table,
        string $column,
        string $pattern = 'QASER%'
    ): int {
        if (!in_array(
            $table,
            [
                'productos',
                'producto_series',
                'existencias_producto',
            ],
            true
        ) || !in_array($column, ['id_producto', 'numero_serie'], true)) {
            throw new InvalidArgumentException('Unsupported QA count.');
        }

        $statement = $pdo->query(
            'SELECT COUNT(*)
             FROM ' . $table . '
             WHERE ' . $column . ' LIKE ' . $pdo->quote($pattern)
        );

        return (int) $statement->fetchColumn();
    }
};
