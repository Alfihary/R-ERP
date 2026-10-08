<?php

declare(strict_types=1);

use App\Infrastructure\Database\DatabaseTest;

return new class implements DatabaseTest {
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $database = (string) $pdo->query(
            'SELECT DATABASE()'
        )->fetchColumn();

        if ($database !== $expectedDatabase) {
            throw new RuntimeException(
                'The active database does not match DB-PRODUCTOS-2 DB-TEST.'
            );
        }

        $migration = $pdo->prepare(
            'SELECT COUNT(*) FROM schema_migrations
             WHERE migration = :migration'
        );
        $migration->execute([
            'migration' => 'db_productos_2_001_extend_product_master',
        ]);
        $migrationRows = (int) $migration->fetchColumn();
        $identifierMigration = $pdo->prepare(
            'SELECT COUNT(*) FROM schema_migrations
             WHERE migration = :migration'
        );
        $identifierMigration->execute([
            'migration' =>
                'productos_identificadores_imagen_1_001_add_product_identifiers',
        ]);
        $identifierMigrationRows = (int) $identifierMigration->fetchColumn();
        $table = $this->tableEvidence($pdo, $database);
        $columns = $this->productColumns($pdo, $database);
        $identity = $this->identityEvidence($pdo, $database);
        $constraints = $this->constraintNames($pdo, $database);
        $indexes = $this->indexNames($pdo, $database);
        $foreignKeys = $this->foreignKeys($pdo, $database);
        $seed = $this->seedEvidence($pdo);
        $before = $this->persistentCounts($pdo);

        $requiredColumns = [
            'sku',
            'sku_alterno',
            'upc',
            'ean',
            'gtin',
            'codigo_fabricante',
            'modelo',
            'tipo_producto_id',
            'peso_kg',
            'largo_cm',
            'ancho_cm',
            'alto_cm',
            'controla_series',
            'controla_lotes',
            'controla_pedimentos',
        ];
        $requiredConstraints = [
            'chk_productos_alto_cm',
            'chk_productos_ancho_cm',
            'chk_productos_codigo_fabricante',
            'chk_productos_controla_lotes',
            'chk_productos_controla_pedimentos',
            'chk_productos_controla_series',
            'chk_productos_ean',
            'chk_productos_gtin',
            'chk_productos_largo_cm',
            'chk_productos_modelo',
            'chk_productos_peso_kg',
            'chk_productos_sku',
            'chk_productos_sku_alterno',
            'chk_productos_upc',
            'chk_tipos_producto_activo',
            'chk_tipos_producto_codigo',
            'chk_tipos_producto_nombre',
            'fk_productos_tipo_producto',
            'uq_tipos_producto_codigo',
        ];
        $requiredIndexes = [
            'idx_productos_codigo_fabricante',
            'idx_productos_ean',
            'idx_productos_gtin',
            'idx_productos_modelo',
            'idx_productos_sku_alterno',
            'idx_productos_upc',
            'uq_productos_sku',
        ];

        if (
            $migrationRows !== 1
            || $identifierMigrationRows !== 1
            || $table !== [
                'engine' => 'InnoDB',
                'table_collation' => 'utf8mb4_unicode_ci',
            ]
            || array_keys($columns) !== $requiredColumns
            || $identity['data_type'] !== 'varchar'
            || $identity['maximum_length'] !== 16
            || $identity['character_set'] !== 'ascii'
            || $identity['collation'] !== 'ascii_bin'
            || $identity['primary_key'] !== ['id_producto']
            || $identity['alternative_identity_columns'] !== []
            || $identity['id_check'] !== 1
            || array_diff($requiredConstraints, $constraints) !== []
            || array_diff($requiredIndexes, $indexes) !== []
            || ($foreignKeys['fk_productos_tipo_producto'] ?? null)
                !== 'productos.tipo_producto_id->tipos_producto.id'
            || count(array_filter(
                $foreignKeys,
                static fn (string $value): bool =>
                    str_contains(
                        $value,
                        '.id_producto->productos.id_producto'
                    )
            )) !== 3
            || $seed['codes'] !== ['KIT', 'PRODUCTO', 'SERVICIO']
            || $seed['active_rows'] !== 3
            || $seed['total_rows'] !== 3
            || $seed['duplicate_codes'] !== 0
        ) {
            throw new RuntimeException(
                'DB-PRODUCTOS-2 structural evidence is invalid.'
            );
        }

        foreach ($requiredColumns as $column) {
            $definition = $columns[$column];

            if ($definition['is_nullable'] !== (
                str_starts_with($column, 'controla_')
                || $column === 'tipo_producto_id'
                    ? 'NO'
                    : 'YES'
            )) {
                throw new RuntimeException(
                    'DB-PRODUCTOS-2 nullability is invalid for ' . $column . '.'
                );
            }
        }

        $functional = $this->functionalTest($pdo);

        if ($this->persistentCounts($pdo) !== $before) {
            throw new RuntimeException(
                'DB-PRODUCTOS-2 transient data was not rolled back.'
            );
        }

        return [
            'database' => $database,
            'mysql_version' => (string) $pdo->query(
                'SELECT VERSION()'
            )->fetchColumn(),
            'migration_rows' => $migrationRows,
            'identifier_migration_rows' => $identifierMigrationRows,
            'table' => $table,
            'product_columns' => $columns,
            'identity' => $identity,
            'constraints' => $constraints,
            'indexes' => $indexes,
            'foreign_keys' => $foreignKeys,
            'seed' => $seed,
            'functional' => $functional,
            'persistent_counts_before' => $before,
            'persistent_counts_after' => $this->persistentCounts($pdo),
            'cleanup' => 'transaction_rolled_back',
        ];
    }

    /**
     * @return array{engine: string, table_collation: string}
     */
    private function tableEvidence(PDO $pdo, string $database): array
    {
        $statement = $pdo->prepare(
            "SELECT ENGINE AS engine, TABLE_COLLATION AS table_collation
             FROM information_schema.tables
             WHERE table_schema = :database
               AND table_name = 'tipos_producto'"
        );
        $statement->execute(['database' => $database]);
        $row = $statement->fetch();

        return $row === false ? [] : [
            'engine' => (string) $row['engine'],
            'table_collation' => (string) $row['table_collation'],
        ];
    }

    /**
     * @return array<string, array<string, string|null>>
     */
    private function productColumns(PDO $pdo, string $database): array
    {
        $statement = $pdo->prepare(
            "SELECT
                COLUMN_NAME AS column_name,
                COLUMN_TYPE AS column_type,
                IS_NULLABLE AS is_nullable,
                COLUMN_DEFAULT AS column_default
             FROM information_schema.columns
             WHERE table_schema = :database
               AND table_name = 'productos'
               AND column_name IN (
                   'sku',
                   'sku_alterno',
                   'upc',
                   'ean',
                   'gtin',
                   'codigo_fabricante',
                   'modelo',
                   'tipo_producto_id',
                   'peso_kg',
                   'largo_cm',
                   'ancho_cm',
                   'alto_cm',
                   'controla_series',
                   'controla_lotes',
                   'controla_pedimentos'
               )
             ORDER BY ORDINAL_POSITION"
        );
        $statement->execute(['database' => $database]);
        $result = [];

        foreach ($statement->fetchAll() as $row) {
            $result[(string) $row['column_name']] = [
                'column_type' => (string) $row['column_type'],
                'is_nullable' => (string) $row['is_nullable'],
                'column_default' => $row['column_default'] === null
                    ? null
                    : (string) $row['column_default'],
            ];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function identityEvidence(PDO $pdo, string $database): array
    {
        $column = $pdo->prepare(
            "SELECT
                DATA_TYPE AS data_type,
                CHARACTER_MAXIMUM_LENGTH AS maximum_length,
                CHARACTER_SET_NAME AS character_set,
                COLLATION_NAME AS collation
             FROM information_schema.columns
             WHERE table_schema = :database
               AND table_name = 'productos'
               AND column_name = 'id_producto'"
        );
        $column->execute(['database' => $database]);
        $identity = $column->fetch();
        $primary = $pdo->prepare(
            "SELECT COLUMN_NAME AS column_name
             FROM information_schema.key_column_usage
             WHERE table_schema = :database
               AND table_name = 'productos'
               AND constraint_name = 'PRIMARY'
             ORDER BY ORDINAL_POSITION"
        );
        $primary->execute(['database' => $database]);
        $alternatives = $pdo->prepare(
            "SELECT COLUMN_NAME AS column_name
             FROM information_schema.columns
             WHERE table_schema = :database
               AND table_name = 'productos'
               AND column_name IN ('id', 'producto_id', 'uuid', 'slug')
             ORDER BY column_name"
        );
        $alternatives->execute(['database' => $database]);
        $check = $pdo->prepare(
            "SELECT COUNT(*)
             FROM information_schema.table_constraints
             WHERE constraint_schema = :database
               AND table_name = 'productos'
               AND constraint_name = 'chk_productos_id_producto'
               AND constraint_type = 'CHECK'"
        );
        $check->execute(['database' => $database]);

        return [
            'data_type' => (string) ($identity['data_type'] ?? ''),
            'maximum_length' => (int) ($identity['maximum_length'] ?? 0),
            'character_set' => (string) ($identity['character_set'] ?? ''),
            'collation' => (string) ($identity['collation'] ?? ''),
            'primary_key' => array_column(
                $primary->fetchAll(),
                'column_name'
            ),
            'alternative_identity_columns' => array_column(
                $alternatives->fetchAll(),
                'column_name'
            ),
            'id_check' => (int) $check->fetchColumn(),
        ];
    }

    /**
     * @return list<string>
     */
    private function constraintNames(PDO $pdo, string $database): array
    {
        $statement = $pdo->prepare(
            "SELECT DISTINCT CONSTRAINT_NAME AS constraint_name
             FROM information_schema.table_constraints
             WHERE constraint_schema = :database
               AND table_name IN ('productos', 'tipos_producto')
             ORDER BY constraint_name"
        );
        $statement->execute(['database' => $database]);

        return array_column($statement->fetchAll(), 'constraint_name');
    }

    /**
     * @return list<string>
     */
    private function indexNames(PDO $pdo, string $database): array
    {
        $statement = $pdo->prepare(
            "SELECT DISTINCT INDEX_NAME AS index_name
             FROM information_schema.statistics
             WHERE table_schema = :database
               AND table_name = 'productos'
             ORDER BY index_name"
        );
        $statement->execute(['database' => $database]);

        return array_column($statement->fetchAll(), 'index_name');
    }

    /**
     * @return array<string, string>
     */
    private function foreignKeys(PDO $pdo, string $database): array
    {
        $statement = $pdo->prepare(
            "SELECT
                CONSTRAINT_NAME AS constraint_name,
                TABLE_NAME AS table_name,
                COLUMN_NAME AS column_name,
                REFERENCED_TABLE_NAME AS referenced_table_name,
                REFERENCED_COLUMN_NAME AS referenced_column_name
             FROM information_schema.key_column_usage
             WHERE table_schema = :database
               AND referenced_table_name IS NOT NULL
               AND (
                   constraint_name = 'fk_productos_tipo_producto'
                   OR (
                       table_name IN (
                           'producto_codigos_barras',
                           'producto_impuestos',
                           'producto_documentos'
                       )
                       AND column_name = 'id_producto'
                   )
               )
             ORDER BY constraint_name"
        );
        $statement->execute(['database' => $database]);
        $result = [];

        foreach ($statement->fetchAll() as $row) {
            $result[(string) $row['constraint_name']] =
                $row['table_name'] . '.' . $row['column_name']
                . '->' . $row['referenced_table_name']
                . '.' . $row['referenced_column_name'];
        }

        return $result;
    }

    /**
     * @return array{
     *     codes: list<string>,
     *     active_rows: int,
     *     total_rows: int,
     *     duplicate_codes: int
     * }
     */
    private function seedEvidence(PDO $pdo): array
    {
        return [
            'codes' => $pdo->query(
                'SELECT codigo FROM tipos_producto
                 WHERE activo = 1 AND eliminado_en IS NULL
                 ORDER BY codigo'
            )->fetchAll(PDO::FETCH_COLUMN),
            'active_rows' => (int) $pdo->query(
                'SELECT COUNT(*) FROM tipos_producto
                 WHERE activo = 1 AND eliminado_en IS NULL'
            )->fetchColumn(),
            'total_rows' => (int) $pdo->query(
                'SELECT COUNT(*) FROM tipos_producto'
            )->fetchColumn(),
            'duplicate_codes' => (int) $pdo->query(
                'SELECT COUNT(*) FROM (
                    SELECT codigo FROM tipos_producto
                    GROUP BY codigo HAVING COUNT(*) > 1
                 ) duplicates'
            )->fetchColumn(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function functionalTest(PDO $pdo): array
    {
        $unitId = (int) $pdo->query(
            "SELECT id FROM unidades_medida
             WHERE codigo = 'PIEZA' AND activo = 1 AND eliminado_en IS NULL"
        )->fetchColumn();
        $types = $pdo->query(
            "SELECT codigo, id FROM tipos_producto
             WHERE codigo IN ('PRODUCTO', 'SERVICIO', 'KIT')"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        if ($unitId < 1 || count($types) !== 3) {
            throw new RuntimeException(
                'DB-PRODUCTOS-2 functional fixtures are unavailable.'
            );
        }

        $pdo->beginTransaction();

        try {
            $this->insertProduct($pdo, [
                'id_producto' => 'QAP2PRODUCT',
                'descripcion' => 'Producto físico QA',
                'sku' => 'SKU-P2/001',
                'sku_alterno' => 'ALT_P2.001',
                'upc' => '123456789012',
                'ean' => '1234567890123',
                'gtin' => '12345678901234',
                'codigo_fabricante' => 'FAB-P2/001',
                'modelo' => 'Modelo QA',
                'unidad_medida_id' => $unitId,
                'tipo_producto_id' => (int) $types['PRODUCTO'],
                'peso_kg' => '12.5000',
                'largo_cm' => '40.000',
                'ancho_cm' => '30.000',
                'alto_cm' => '20.000',
                'controla_series' => 1,
                'controla_lotes' => 1,
                'controla_pedimentos' => 1,
            ]);
            $this->insertProduct($pdo, [
                'id_producto' => 'QAP2SERVICE',
                'descripcion' => 'Servicio QA',
                'sku' => null,
                'sku_alterno' => null,
                'upc' => null,
                'ean' => null,
                'gtin' => null,
                'codigo_fabricante' => null,
                'modelo' => null,
                'unidad_medida_id' => $unitId,
                'tipo_producto_id' => (int) $types['SERVICIO'],
                'peso_kg' => null,
                'largo_cm' => null,
                'ancho_cm' => null,
                'alto_cm' => null,
                'controla_series' => 0,
                'controla_lotes' => 0,
                'controla_pedimentos' => 0,
            ]);
            $this->insertProduct($pdo, [
                'id_producto' => 'QAP2KIT',
                'descripcion' => 'Kit QA',
                'sku' => 'KIT-P2/001',
                'sku_alterno' => null,
                'upc' => null,
                'ean' => null,
                'gtin' => null,
                'codigo_fabricante' => 'KIT-FAB/001',
                'modelo' => 'Kit QA',
                'unidad_medida_id' => $unitId,
                'tipo_producto_id' => (int) $types['KIT'],
                'peso_kg' => '5.2500',
                'largo_cm' => '25.000',
                'ancho_cm' => '15.000',
                'alto_cm' => '10.000',
                'controla_series' => 0,
                'controla_lotes' => 0,
                'controla_pedimentos' => 0,
            ]);

            $failures = [];
            $invalidCases = [
                'unknown_type' => ['tipo_producto_id' => 999999999],
                'negative_weight' => ['peso_kg' => '-1.0000'],
                'zero_weight' => ['peso_kg' => '0.0000'],
                'negative_length' => ['largo_cm' => '-1.000'],
                'zero_length' => ['largo_cm' => '0.000'],
                'negative_width' => ['ancho_cm' => '-1.000'],
                'zero_width' => ['ancho_cm' => '0.000'],
                'negative_height' => ['alto_cm' => '-1.000'],
                'zero_height' => ['alto_cm' => '0.000'],
                'invalid_series_flag' => ['controla_series' => 2],
                'invalid_lots_flag' => ['controla_lotes' => 2],
                'invalid_customs_flag' => ['controla_pedimentos' => 2],
                'trailing_space_id' => ['id_producto' => 'QAP2SPACE '],
                'duplicate_sku' => ['sku' => 'SKU-P2/001'],
                'invalid_lowercase_sku' => ['sku' => 'sku-bad'],
                'invalid_sku_space' => ['sku' => 'SKU BAD'],
                'invalid_upc_letters' => ['upc' => '12345678901A'],
                'invalid_upc_length' => ['upc' => '12345678901'],
                'invalid_ean_length' => ['ean' => '123456789012'],
                'invalid_gtin_length' => ['gtin' => '123456789'],
                'invalid_manufacturer_code' => [
                    'codigo_fabricante' => 'FAB BAD',
                ],
                'invalid_model_length' => ['modelo' => str_repeat('M', 81)],
            ];

            foreach ($invalidCases as $label => $override) {
                $data = array_replace([
                    'id_producto' => 'QAP2' . strtoupper(substr(md5($label), 0, 8)),
                    'descripcion' => 'Inválido QA',
                    'sku' => null,
                    'sku_alterno' => null,
                    'upc' => null,
                    'ean' => null,
                    'gtin' => null,
                    'codigo_fabricante' => null,
                    'modelo' => null,
                    'unidad_medida_id' => $unitId,
                    'tipo_producto_id' => (int) $types['PRODUCTO'],
                    'peso_kg' => '1.0000',
                    'largo_cm' => '1.000',
                    'ancho_cm' => '1.000',
                    'alto_cm' => '1.000',
                    'controla_series' => 0,
                    'controla_lotes' => 0,
                    'controla_pedimentos' => 0,
                ], $override);
                $this->expectFailure(
                    fn () => $this->insertProduct($pdo, $data),
                    $label
                );
                $failures[] = $label;
            }

            $transientRows = (int) $pdo->query(
                "SELECT COUNT(*) FROM productos
                 WHERE id_producto IN (
                    'QAP2PRODUCT',
                    'QAP2SERVICE',
                    'QAP2KIT'
                 )"
            )->fetchColumn();

            if ($transientRows !== 3) {
                throw new RuntimeException(
                    'DB-PRODUCTOS-2 valid products were not accepted.'
                );
            }

            $pdo->rollBack();

            return [
                'valid_product' => true,
                'valid_service' => true,
                'valid_kit' => true,
                'expected_failures' => $failures,
                'transient_valid_rows' => $transientRows,
            ];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @param array<string, int|string|null> $data
     */
    private function insertProduct(PDO $pdo, array $data): void
    {
        $statement = $pdo->prepare(
            <<<'SQL'
            INSERT INTO productos (
                id_producto,
                descripcion,
                sku,
                sku_alterno,
                upc,
                ean,
                gtin,
                codigo_fabricante,
                modelo,
                unidad_medida_id,
                tipo_producto_id,
                peso_kg,
                largo_cm,
                ancho_cm,
                alto_cm,
                controla_series,
                controla_lotes,
                controla_pedimentos
            )
            VALUES (
                :id_producto,
                :descripcion,
                :sku,
                :sku_alterno,
                :upc,
                :ean,
                :gtin,
                :codigo_fabricante,
                :modelo,
                :unidad_medida_id,
                :tipo_producto_id,
                :peso_kg,
                :largo_cm,
                :ancho_cm,
                :alto_cm,
                :controla_series,
                :controla_lotes,
                :controla_pedimentos
            )
            SQL
        );
        $statement->execute($data);
    }

    /**
     * @param callable(): void $operation
     */
    private function expectFailure(callable $operation, string $label): void
    {
        try {
            $operation();
        } catch (PDOException) {
            return;
        }

        throw new RuntimeException(
            'DB-PRODUCTOS-2 accepted invalid case: ' . $label
        );
    }

    /**
     * @return array<string, int>
     */
    private function persistentCounts(PDO $pdo): array
    {
        $counts = [];

        foreach ([
            'tipos_producto',
            'productos',
            'producto_codigos_barras',
            'producto_impuestos',
            'producto_documentos',
        ] as $table) {
            $counts[$table] = (int) $pdo->query(
                'SELECT COUNT(*) FROM ' . $table
            )->fetchColumn();
        }

        return $counts;
    }
};
