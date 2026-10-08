<?php

declare(strict_types=1);

use App\Infrastructure\Database\DatabaseTest;

return new class implements DatabaseTest {
    private const TABLES = [
        'productos',
        'producto_codigos_barras',
        'producto_impuestos',
        'producto_documentos',
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
                'The active database does not match DB-TEST-PRODUCTOS.'
            );
        }

        $migrationRows = $this->migrationRows($pdo);
        $tables = $this->tableMetadata($pdo);

        if ($migrationRows !== 1 || count($tables) !== count(self::TABLES)) {
            throw new RuntimeException(
                'DB-PRODUCTOS-1 migration evidence is incomplete.'
            );
        }

        foreach ($tables as $table) {
            if (
                strtoupper((string) $table['engine']) !== 'INNODB'
                || strtolower((string) $table['table_collation'])
                    !== 'utf8mb4_unicode_ci'
            ) {
                throw new RuntimeException(
                    'DB-PRODUCTOS-1 tables require InnoDB and utf8mb4_unicode_ci.'
                );
            }
        }

        $identity = $this->identityMetadata($pdo);
        $this->assertIdentityContract($identity);
        $indexes = $this->indexNames($pdo);
        $foreignKeys = $this->foreignKeys($pdo);
        $this->assertIndexes($indexes);
        $this->assertForeignKeys($foreignKeys);

        $before = $this->counts($pdo);
        $expectedCounts = array_fill_keys(self::TABLES, 0);

        if ($before !== $expectedCounts) {
            throw new RuntimeException(
                'DB-PRODUCTOS-1 requires empty persistent product tables.'
            );
        }

        $unitId = $this->catalogId($pdo, 'unidades_medida', 'PIEZA');
        $currencyId = $this->catalogId($pdo, 'monedas', 'MXN');
        $taxId = $this->catalogId($pdo, 'impuestos', 'IVA_16');
        $failures = [];
        $validChildren = [];
        $pdo->beginTransaction();

        try {
            $this->insertProduct(
                $pdo,
                'ABC123',
                'Producto QA',
                $unitId,
                $currencyId
            );

            $invalidIds = [
                'duplicate_id' => 'ABC123',
                'empty_id' => '',
                'lowercase_id' => 'abc123',
                'leading_space_id' => ' ABC123',
                'trailing_space_id' => 'ABC123 ',
                'internal_space_id' => 'ABC 123',
                'accented_id' => 'ÁBC123',
                'hyphen_id' => 'ABC-123',
                'underscore_id' => 'ABC_123',
                'special_id' => 'ABC@123',
                'too_long_id' => 'ABCDEFGHIJKLMNOPQ',
            ];

            foreach ($invalidIds as $label => $id) {
                $this->expectFailure(
                    $label,
                    fn () => $this->insertProduct(
                        $pdo,
                        $id,
                        'Inválido QA',
                        $unitId,
                        $currencyId
                    ),
                    $failures
                );
            }

            $this->expectFailure(
                'description_too_long',
                fn () => $this->insertProduct(
                    $pdo,
                    'QADESC41',
                    str_repeat('X', 41),
                    $unitId,
                    $currencyId
                ),
                $failures
            );
            $this->expectFailure(
                'unknown_unit',
                fn () => $this->insertProduct(
                    $pdo,
                    'QAUNIT404',
                    'Unidad inexistente',
                    999999999,
                    $currencyId
                ),
                $failures
            );
            $this->expectFailure(
                'unknown_currency',
                fn () => $this->insertProduct(
                    $pdo,
                    'QACURR404',
                    'Moneda inexistente',
                    $unitId,
                    999999999
                ),
                $failures
            );
            $this->expectFailure(
                'unknown_line',
                fn () => $this->insertProductWithOptional(
                    $pdo,
                    'QALINE404',
                    $unitId,
                    'linea_producto_id',
                    999999999
                ),
                $failures
            );
            $this->expectFailure(
                'unknown_brand',
                fn () => $this->insertProductWithOptional(
                    $pdo,
                    'QABRAND404',
                    $unitId,
                    'marca_id',
                    999999999
                ),
                $failures
            );
            $this->expectFailure(
                'unknown_classification',
                fn () => $this->insertProductWithOptional(
                    $pdo,
                    'QACLASS404',
                    $unitId,
                    'clasificacion_producto_id',
                    999999999
                ),
                $failures
            );
            $this->expectFailure(
                'invalid_product_active',
                function () use ($pdo, $unitId): void {
                    $statement = $pdo->prepare(
                        'INSERT INTO productos (
                            id_producto,
                            descripcion,
                            unidad_medida_id,
                            activo
                         )
                         VALUES (
                            :id_producto,
                            :descripcion,
                            :unidad_medida_id,
                            :activo
                         )'
                    );
                    $statement->execute([
                        'id_producto' => 'QAACTIVE2',
                        'descripcion' => 'Activo inválido',
                        'unidad_medida_id' => $unitId,
                        'activo' => 2,
                    ]);
                },
                $failures
            );

            $this->insertBarcode($pdo, 'ABC123', '7501234567890');
            $validChildren['barcode'] = 1;
            $this->expectFailure(
                'duplicate_barcode',
                fn () => $this->insertBarcode(
                    $pdo,
                    'ABC123',
                    '7501234567890'
                ),
                $failures
            );
            $this->expectFailure(
                'barcode_unknown_product',
                fn () => $this->insertBarcode(
                    $pdo,
                    'MISSING01',
                    '7501234567891'
                ),
                $failures
            );

            $this->insertProductTax($pdo, 'ABC123', $taxId);
            $validChildren['tax'] = 1;
            $this->expectFailure(
                'duplicate_product_tax',
                fn () => $this->insertProductTax(
                    $pdo,
                    'ABC123',
                    $taxId
                ),
                $failures
            );
            $this->expectFailure(
                'unknown_tax',
                fn () => $this->insertProductTax(
                    $pdo,
                    'ABC123',
                    999999999
                ),
                $failures
            );
            $this->expectFailure(
                'tax_unknown_product',
                fn () => $this->insertProductTax(
                    $pdo,
                    'MISSING01',
                    $taxId
                ),
                $failures
            );

            $this->insertDocument($pdo, 'ABC123', 'qa/producto.pdf');
            $validChildren['document'] = 1;
            $this->expectFailure(
                'document_unknown_product',
                fn () => $this->insertDocument(
                    $pdo,
                    'MISSING01',
                    'qa/inexistente.pdf'
                ),
                $failures
            );

            $transient = $this->counts($pdo);

            if (
                $transient !== [
                    'productos' => 1,
                    'producto_codigos_barras' => 1,
                    'producto_impuestos' => 1,
                    'producto_documentos' => 1,
                ]
            ) {
                throw new RuntimeException(
                    'DB-PRODUCTOS-1 valid transient rows are incomplete.'
                );
            }

            $pdo->rollBack();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        $after = $this->counts($pdo);

        if ($after !== $before) {
            throw new RuntimeException(
                'DB-PRODUCTOS-1 left persistent transient data.'
            );
        }

        return [
            'database' => $database,
            'mysql_version' => $pdo->getAttribute(
                PDO::ATTR_SERVER_VERSION
            ),
            'migration_rows' => $migrationRows,
            'tables' => array_column($tables, 'table_name'),
            'engines' => array_values(array_unique(array_column(
                $tables,
                'engine'
            ))),
            'collations' => array_values(array_unique(array_column(
                $tables,
                'table_collation'
            ))),
            'identity' => $identity,
            'required_indexes' => $indexes,
            'foreign_keys' => $foreignKeys,
            'expected_failures' => array_keys($failures),
            'valid_children' => $validChildren,
            'persistent_counts_before' => $before,
            'persistent_counts_after' => $after,
            'cleanup' => 'transaction_rolled_back',
        ];
    }

    private function migrationRows(PDO $pdo): int
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM schema_migrations
             WHERE migration = :migration'
        );
        $statement->execute([
            'migration' => 'db_productos_1_001_create_product_tables',
        ]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tableMetadata(PDO $pdo): array
    {
        return $pdo->query(
            "SELECT
                TABLE_NAME AS table_name,
                ENGINE AS engine,
                TABLE_COLLATION AS table_collation
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name IN (
                   'productos',
                   'producto_codigos_barras',
                   'producto_impuestos',
                   'producto_documentos'
               )
             ORDER BY table_name"
        )->fetchAll();
    }

    /**
     * @return array<string, mixed>
     */
    private function identityMetadata(PDO $pdo): array
    {
        $statement = $pdo->prepare(
            'SELECT
                DATA_TYPE AS data_type,
                CHARACTER_MAXIMUM_LENGTH AS character_maximum_length,
                CHARACTER_SET_NAME AS character_set_name,
                COLLATION_NAME AS collation_name,
                IS_NULLABLE AS is_nullable,
                EXTRA AS extra
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name'
        );
        $statement->execute([
            'table_name' => 'productos',
            'column_name' => 'id_producto',
        ]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            throw new RuntimeException('Product identity column is missing.');
        }

        $columns = $pdo->query(
            "SELECT column_name
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'productos'
             ORDER BY ordinal_position"
        )->fetchAll(PDO::FETCH_COLUMN);
        $primary = $pdo->query(
            "SELECT column_name
             FROM information_schema.key_column_usage
             WHERE table_schema = DATABASE()
               AND table_name = 'productos'
               AND constraint_name = 'PRIMARY'
             ORDER BY ordinal_position"
        )->fetchAll(PDO::FETCH_COLUMN);
        $childColumns = $pdo->query(
            "SELECT
                    TABLE_NAME AS table_name,
                    DATA_TYPE AS data_type,
                    CHARACTER_MAXIMUM_LENGTH AS character_maximum_length,
                    CHARACTER_SET_NAME AS character_set_name,
                    COLLATION_NAME AS collation_name
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name IN (
                   'producto_codigos_barras',
                   'producto_impuestos',
                   'producto_documentos'
               )
               AND column_name = 'id_producto'
             ORDER BY table_name"
        )->fetchAll();

        return $row + [
            'primary_key' => $primary,
            'has_id_column' => in_array('id', $columns, true),
            'has_producto_id_column' => in_array(
                'producto_id',
                $columns,
                true
            ),
            'child_columns' => $childColumns,
        ];
    }

    /**
     * @param array<string, mixed> $identity
     */
    private function assertIdentityContract(array $identity): void
    {
        if (
            strtolower((string) $identity['data_type']) !== 'varchar'
            || (int) $identity['character_maximum_length'] !== 16
            || strtolower((string) $identity['character_set_name']) !== 'ascii'
            || strtolower((string) $identity['collation_name']) !== 'ascii_bin'
            || strtoupper((string) $identity['is_nullable']) !== 'NO'
            || $identity['primary_key'] !== ['id_producto']
            || $identity['has_id_column'] !== false
            || $identity['has_producto_id_column'] !== false
            || str_contains(
                strtolower((string) $identity['extra']),
                'auto_increment'
            )
            || count($identity['child_columns']) !== 3
        ) {
            throw new RuntimeException(
                'Product identity contract is invalid.'
            );
        }

        foreach ($identity['child_columns'] as $column) {
            if (
                strtolower((string) $column['data_type']) !== 'varchar'
                || (int) $column['character_maximum_length'] !== 16
                || strtolower((string) $column['character_set_name'])
                    !== 'ascii'
                || strtolower((string) $column['collation_name'])
                    !== 'ascii_bin'
            ) {
                throw new RuntimeException(
                    'A child product identity column is incompatible.'
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    private function indexNames(PDO $pdo): array
    {
        return $pdo->query(
            "SELECT DISTINCT CONCAT(table_name, '.', index_name)
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name IN (
                   'productos',
                   'producto_codigos_barras',
                   'producto_impuestos',
                   'producto_documentos'
               )
             ORDER BY 1"
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @param list<string> $indexes
     */
    private function assertIndexes(array $indexes): void
    {
        foreach ([
            'productos.PRIMARY',
            'producto_codigos_barras.PRIMARY',
            'producto_codigos_barras.uq_producto_codigos_barras_codigo',
            'producto_impuestos.PRIMARY',
            'producto_documentos.PRIMARY',
            'producto_documentos.uq_producto_documentos_ruta',
        ] as $required) {
            if (!in_array($required, $indexes, true)) {
                throw new RuntimeException(
                    'Required product index is missing: ' . $required
                );
            }
        }
    }

    /**
     * @return list<array<string, string>>
     */
    private function foreignKeys(PDO $pdo): array
    {
        return $pdo->query(
            "SELECT
                rc.CONSTRAINT_NAME AS constraint_name,
                kcu.TABLE_NAME AS table_name,
                kcu.COLUMN_NAME AS column_name,
                kcu.REFERENCED_TABLE_NAME AS referenced_table_name,
                kcu.REFERENCED_COLUMN_NAME AS referenced_column_name,
                rc.UPDATE_RULE AS update_rule,
                rc.DELETE_RULE AS delete_rule
             FROM information_schema.referential_constraints rc
             INNER JOIN information_schema.key_column_usage kcu
                ON kcu.constraint_schema = rc.constraint_schema
               AND kcu.constraint_name = rc.constraint_name
             WHERE rc.constraint_schema = DATABASE()
               AND kcu.table_name IN (
                   'productos',
                   'producto_codigos_barras',
                   'producto_impuestos',
                   'producto_documentos'
               )
             ORDER BY rc.constraint_name"
        )->fetchAll();
    }

    /**
     * @param list<array<string, string>> $foreignKeys
     */
    private function assertForeignKeys(array $foreignKeys): void
    {
        $names = array_column($foreignKeys, 'constraint_name');

        foreach ([
            'fk_productos_unidad',
            'fk_productos_moneda',
            'fk_productos_linea',
            'fk_productos_marca',
            'fk_productos_clasificacion',
            'fk_producto_codigos_barras_producto',
            'fk_producto_impuestos_producto',
            'fk_producto_impuestos_impuesto',
            'fk_producto_documentos_producto',
        ] as $required) {
            if (!in_array($required, $names, true)) {
                throw new RuntimeException(
                    'Required product foreign key is missing: ' . $required
                );
            }
        }
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

        return $counts;
    }

    private function catalogId(PDO $pdo, string $table, string $code): int
    {
        if (!in_array(
            $table,
            ['unidades_medida', 'monedas', 'impuestos'],
            true
        )) {
            throw new InvalidArgumentException('Unsupported catalog table.');
        }

        $statement = $pdo->prepare(
            'SELECT id FROM ' . $table . ' WHERE codigo = :codigo'
        );
        $statement->execute(['codigo' => $code]);
        $id = $statement->fetchColumn();

        if ($id === false) {
            throw new RuntimeException(
                'Required product catalog record is missing.'
            );
        }

        return (int) $id;
    }

    private function insertProduct(
        PDO $pdo,
        string $id,
        string $description,
        int $unitId,
        ?int $currencyId
    ): void {
        $statement = $pdo->prepare(
            'INSERT INTO productos (
                id_producto,
                descripcion,
                unidad_medida_id,
                moneda_id
             )
             VALUES (
                :id_producto,
                :descripcion,
                :unidad_medida_id,
                :moneda_id
             )'
        );
        $statement->execute([
            'id_producto' => $id,
            'descripcion' => $description,
            'unidad_medida_id' => $unitId,
            'moneda_id' => $currencyId,
        ]);
    }

    private function insertProductWithOptional(
        PDO $pdo,
        string $id,
        int $unitId,
        string $column,
        int $value
    ): void {
        if (!in_array(
            $column,
            [
                'linea_producto_id',
                'marca_id',
                'clasificacion_producto_id',
            ],
            true
        )) {
            throw new InvalidArgumentException(
                'Unsupported optional product column.'
            );
        }

        $statement = $pdo->prepare(
            'INSERT INTO productos (
                id_producto,
                descripcion,
                unidad_medida_id,
                ' . $column . '
             )
             VALUES (
                :id_producto,
                :descripcion,
                :unidad_medida_id,
                :optional_id
             )'
        );
        $statement->execute([
            'id_producto' => $id,
            'descripcion' => 'FK inexistente',
            'unidad_medida_id' => $unitId,
            'optional_id' => $value,
        ]);
    }

    private function insertBarcode(
        PDO $pdo,
        string $productId,
        string $barcode
    ): void {
        $statement = $pdo->prepare(
            'INSERT INTO producto_codigos_barras (
                id_producto,
                codigo_barras
             )
             VALUES (:id_producto, :codigo_barras)'
        );
        $statement->execute([
            'id_producto' => $productId,
            'codigo_barras' => $barcode,
        ]);
    }

    private function insertProductTax(
        PDO $pdo,
        string $productId,
        int $taxId
    ): void {
        $statement = $pdo->prepare(
            'INSERT INTO producto_impuestos (
                id_producto,
                impuesto_id
             )
             VALUES (:id_producto, :impuesto_id)'
        );
        $statement->execute([
            'id_producto' => $productId,
            'impuesto_id' => $taxId,
        ]);
    }

    private function insertDocument(
        PDO $pdo,
        string $productId,
        string $path
    ): void {
        $statement = $pdo->prepare(
            'INSERT INTO producto_documentos (
                id_producto,
                tipo_documento,
                nombre_original,
                ruta_relativa,
                mime_type,
                tamano_bytes
             )
             VALUES (
                :id_producto,
                :tipo_documento,
                :nombre_original,
                :ruta_relativa,
                :mime_type,
                :tamano_bytes
             )'
        );
        $statement->execute([
            'id_producto' => $productId,
            'tipo_documento' => 'FICHA',
            'nombre_original' => 'producto.pdf',
            'ruta_relativa' => $path,
            'mime_type' => 'application/pdf',
            'tamano_bytes' => 1024,
        ]);
    }

    /**
     * @param callable(): void $operation
     * @param array<string, array{sqlstate: string, driver_code: int|null}> $failures
     */
    private function expectFailure(
        string $label,
        callable $operation,
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
            'Expected DB-PRODUCTOS-1 failure was accepted: ' . $label
        );
    }
};
