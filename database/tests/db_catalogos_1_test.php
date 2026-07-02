<?php

declare(strict_types=1);

use App\Infrastructure\Database\DatabaseTest;

if (!isset($initialAdminUsername, $initialAdminEmail)
    || !is_string($initialAdminUsername)
    || !is_string($initialAdminEmail)
) {
    throw new RuntimeException(
        'DB-CATALOGOS-1 DB-TEST requires the configured administrator.'
    );
}

return new class($initialAdminUsername, $initialAdminEmail) implements DatabaseTest {
    private const TABLES = [
        'monedas',
        'tipos_cambio',
        'unidades_medida',
        'impuestos',
        'lineas_producto',
        'marcas',
        'clasificaciones_producto',
    ];

    public function __construct(
        private readonly string $adminUsername,
        private readonly string $adminEmail
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $currentDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

        if ($currentDatabase !== $expectedDatabase) {
            throw new RuntimeException(
                'The active database does not match DB-TEST-CATALOGOS.'
            );
        }

        $this->assertPrerequisites($pdo);
        $operationalTables = (int) $pdo->query(
            <<<'SQL'
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name IN (
                  'productos',
                  'existencias',
                  'inventario_movimientos',
                  'tickets',
                  'compras',
                  'ventas',
                  'clientes',
                  'proveedores'
              )
            SQL
        )->fetchColumn();

        if ($operationalTables !== 0) {
            throw new RuntimeException(
                'DB-CATALOGOS-1 must not create operational module tables.'
            );
        }

        $metadata = $this->tableMetadata($pdo, $expectedDatabase);

        if (count($metadata) !== count(self::TABLES)) {
            throw new RuntimeException(
                'DB-CATALOGOS-1 does not contain every expected table.'
            );
        }

        foreach ($metadata as $table) {
            if (strtoupper((string) $table['engine']) !== 'INNODB') {
                throw new RuntimeException(
                    'Every DB-CATALOGOS-1 table must use InnoDB.'
                );
            }

            if (strtolower((string) $table['table_collation'])
                !== 'utf8mb4_unicode_ci'
            ) {
                throw new RuntimeException(
                    'Every DB-CATALOGOS-1 table must use utf8mb4_unicode_ci.'
                );
            }
        }

        $requiredIndexes = [
            'monedas.PRIMARY',
            'monedas.uq_monedas_codigo',
            'monedas.uq_monedas_base_unica',
            'tipos_cambio.PRIMARY',
            'tipos_cambio.uq_tipos_cambio_par_fecha',
            'tipos_cambio.idx_tipos_cambio_destino_fecha',
            'unidades_medida.PRIMARY',
            'unidades_medida.uq_unidades_medida_codigo',
            'impuestos.PRIMARY',
            'impuestos.uq_impuestos_codigo',
            'lineas_producto.PRIMARY',
            'lineas_producto.uq_lineas_producto_codigo',
            'marcas.PRIMARY',
            'marcas.uq_marcas_codigo',
            'clasificaciones_producto.PRIMARY',
            'clasificaciones_producto.uq_clasificaciones_producto_codigo',
            'clasificaciones_producto.idx_clasificaciones_producto_parent',
        ];
        $indexes = $this->indexNames($pdo, $expectedDatabase);

        foreach ($requiredIndexes as $requiredIndex) {
            if (!in_array($requiredIndex, $indexes, true)) {
                throw new RuntimeException(
                    'Required index is missing: ' . $requiredIndex
                );
            }
        }

        $requiredForeignKeys = [
            'fk_tipos_cambio_moneda_origen',
            'fk_tipos_cambio_moneda_destino',
            'fk_clasificaciones_producto_parent',
        ];
        $foreignKeys = $this->foreignKeyNames($pdo, $expectedDatabase);

        foreach ($requiredForeignKeys as $requiredForeignKey) {
            if (!in_array($requiredForeignKey, $foreignKeys, true)) {
                throw new RuntimeException(
                    'Required foreign key is missing: ' . $requiredForeignKey
                );
            }
        }

        $auditForeignKeys = array_filter(
            $foreignKeys,
            static fn (string $key): bool =>
                str_ends_with($key, '_creado_por')
                || str_ends_with($key, '_actualizado_por')
                || str_ends_with($key, '_eliminado_por')
        );

        if (count($auditForeignKeys) !== 21) {
            throw new RuntimeException(
                'DB-CATALOGOS-1 must contain 21 audit foreign keys.'
            );
        }

        $seedBefore = $this->persistentCounts($pdo);
        $expectedPersistent = [
            'monedas' => 3,
            'tipos_cambio' => 0,
            'unidades_medida' => 5,
            'impuestos' => 3,
            'lineas_producto' => 0,
            'marcas' => 0,
            'clasificaciones_producto' => 0,
        ];

        if ($seedBefore !== $expectedPersistent) {
            throw new RuntimeException(
                'DB-CATALOGOS-1 persistent seed counts are invalid.'
            );
        }

        $seedCodes = $this->seedCodes($pdo);

        if ($seedCodes !== [
            'monedas' => ['EUR', 'MXN', 'USD'],
            'unidades_medida' => ['KG', 'LITRO', 'METRO', 'PIEZA', 'SERVICIO'],
            'impuestos' => ['EXENTO', 'IVA_0', 'IVA_16'],
        ]) {
            throw new RuntimeException('DB-CATALOGOS-1 seed codes are invalid.');
        }

        $baseCurrency = $pdo->query(
            'SELECT codigo
             FROM monedas
             WHERE es_base = 1
               AND activo = 1
               AND eliminado_en IS NULL'
        )->fetchAll(PDO::FETCH_COLUMN);

        if ($baseCurrency !== ['MXN']) {
            throw new RuntimeException('MXN must be the only effective base currency.');
        }

        $duplicateCounts = $this->duplicateCounts($pdo);

        if (array_sum($duplicateCounts) !== 0) {
            throw new RuntimeException(
                'DB-CATALOGOS-1 contains duplicate persistent records.'
            );
        }

        $actorId = $this->initialAdminId($pdo);
        $expectedFailures = [];
        $transientCounts = [];
        $pdo->beginTransaction();

        try {
            $mxnId = $this->currencyId($pdo, 'MXN');
            $usdId = $this->currencyId($pdo, 'USD');
            $cadId = $this->insertCurrency(
                $pdo,
                'CAD',
                'Dólar canadiense',
                'C$',
                2,
                0,
                $actorId
            );
            $this->insertUnit($pdo, 'CAJA', 'Caja', 'caja', $actorId);
            $this->insertTax(
                $pdo,
                'IEPS_8',
                'IEPS 8%',
                '8.0000',
                'IEPS',
                $actorId
            );
            $this->insertNamedCatalog(
                $pdo,
                'lineas_producto',
                'DB_TEST_LINEA',
                'Línea DB-TEST',
                $actorId
            );
            $this->insertNamedCatalog(
                $pdo,
                'marcas',
                'DB_TEST_MARCA',
                'Marca DB-TEST',
                $actorId
            );
            $rootId = $this->insertClassification(
                $pdo,
                null,
                'DB_TEST_RAIZ',
                'Raíz DB-TEST',
                $actorId
            );
            $this->insertClassification(
                $pdo,
                $rootId,
                'DB_TEST_HIJA',
                'Hija DB-TEST',
                $actorId
            );
            $this->insertExchangeRate(
                $pdo,
                $cadId,
                $mxnId,
                '2099-01-01',
                '12.50000000',
                $actorId
            );

            $this->expectConstraintFailure(
                fn () => $this->insertCurrency(
                    $pdo,
                    'MXN',
                    'Duplicada',
                    '$',
                    2,
                    0,
                    $actorId
                ),
                'duplicate_currency_code',
                $expectedFailures
            );
            $this->expectConstraintFailure(
                function () use ($pdo, $cadId, $actorId): void {
                    $statement = $pdo->prepare(
                        'UPDATE monedas
                         SET es_base = 1, actualizado_por = :actor_id
                         WHERE id = :id'
                    );
                    $statement->execute([
                        'actor_id' => $actorId,
                        'id' => $cadId,
                    ]);
                },
                'second_base_currency',
                $expectedFailures
            );
            $this->expectConstraintFailure(
                fn () => $this->insertExchangeRate(
                    $pdo,
                    $usdId,
                    $mxnId,
                    '2099-01-02',
                    '0.00000000',
                    $actorId
                ),
                'zero_exchange_rate',
                $expectedFailures
            );
            $this->expectConstraintFailure(
                fn () => $this->insertExchangeRate(
                    $pdo,
                    $usdId,
                    $mxnId,
                    '2099-01-03',
                    '-1.00000000',
                    $actorId
                ),
                'negative_exchange_rate',
                $expectedFailures
            );
            $this->expectConstraintFailure(
                fn () => $this->insertExchangeRate(
                    $pdo,
                    $cadId,
                    $mxnId,
                    '2099-01-01',
                    '12.60000000',
                    $actorId
                ),
                'duplicate_exchange_rate',
                $expectedFailures
            );
            $this->expectConstraintFailure(
                fn () => $this->insertExchangeRate(
                    $pdo,
                    $usdId,
                    $usdId,
                    '2099-01-04',
                    '1.00000000',
                    $actorId
                ),
                'same_currency_exchange_rate',
                $expectedFailures
            );
            $invalidCurrencyId = (int) $pdo->query(
                'SELECT COALESCE(MAX(id), 0) + 100000 FROM monedas'
            )->fetchColumn();
            $this->expectConstraintFailure(
                fn () => $this->insertExchangeRate(
                    $pdo,
                    $invalidCurrencyId,
                    $mxnId,
                    '2099-01-05',
                    '1.00000000',
                    $actorId
                ),
                'orphan_exchange_rate',
                $expectedFailures
            );
            $this->expectConstraintFailure(
                fn () => $this->insertUnit(
                    $pdo,
                    'PIEZA',
                    'Duplicada',
                    'dup',
                    $actorId
                ),
                'duplicate_unit_code',
                $expectedFailures
            );
            $this->expectConstraintFailure(
                fn () => $this->insertTax(
                    $pdo,
                    'IVA_16',
                    'Duplicado',
                    '16.0000',
                    'IVA',
                    $actorId
                ),
                'duplicate_tax_code',
                $expectedFailures
            );
            $this->expectConstraintFailure(
                fn () => $this->insertTax(
                    $pdo,
                    'TASA_NEGATIVA',
                    'Tasa negativa',
                    '-0.0100',
                    'IVA',
                    $actorId
                ),
                'negative_tax_rate',
                $expectedFailures
            );
            $this->expectConstraintFailure(
                fn () => $this->insertNamedCatalog(
                    $pdo,
                    'lineas_producto',
                    'DB_TEST_LINEA',
                    'Duplicada',
                    $actorId
                ),
                'duplicate_product_line_code',
                $expectedFailures
            );
            $this->expectConstraintFailure(
                fn () => $this->insertNamedCatalog(
                    $pdo,
                    'marcas',
                    'DB_TEST_MARCA',
                    'Duplicada',
                    $actorId
                ),
                'duplicate_brand_code',
                $expectedFailures
            );
            $this->expectConstraintFailure(
                fn () => $this->insertClassification(
                    $pdo,
                    null,
                    'DB_TEST_RAIZ',
                    'Duplicada',
                    $actorId
                ),
                'duplicate_classification_code',
                $expectedFailures
            );
            $invalidParentId = (int) $pdo->query(
                'SELECT COALESCE(MAX(id), 0) + 100000
                 FROM clasificaciones_producto'
            )->fetchColumn();
            $this->expectConstraintFailure(
                fn () => $this->insertClassification(
                    $pdo,
                    $invalidParentId,
                    'DB_TEST_ORPHAN',
                    'Huérfana DB-TEST',
                    $actorId
                ),
                'orphan_parent_classification',
                $expectedFailures
            );

            $transientCounts = [
                'monedas' => $this->countByCode($pdo, 'monedas', 'CAD'),
                'tipos_cambio' => (int) $pdo->query(
                    "SELECT COUNT(*) FROM tipos_cambio WHERE fecha = '2099-01-01'"
                )->fetchColumn(),
                'unidades_medida' => $this->countByCode(
                    $pdo,
                    'unidades_medida',
                    'CAJA'
                ),
                'impuestos' => $this->countByCode($pdo, 'impuestos', 'IEPS_8'),
                'lineas_producto' => $this->countByCode(
                    $pdo,
                    'lineas_producto',
                    'DB_TEST_LINEA'
                ),
                'marcas' => $this->countByCode(
                    $pdo,
                    'marcas',
                    'DB_TEST_MARCA'
                ),
                'clasificaciones_producto' => (int) $pdo->query(
                    "SELECT COUNT(*)
                     FROM clasificaciones_producto
                     WHERE codigo IN ('DB_TEST_RAIZ', 'DB_TEST_HIJA')"
                )->fetchColumn(),
            ];
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        $requiredFailures = [
            'duplicate_brand_code',
            'duplicate_classification_code',
            'duplicate_currency_code',
            'duplicate_exchange_rate',
            'duplicate_product_line_code',
            'duplicate_tax_code',
            'duplicate_unit_code',
            'negative_exchange_rate',
            'negative_tax_rate',
            'orphan_exchange_rate',
            'same_currency_exchange_rate',
            'second_base_currency',
            'orphan_parent_classification',
            'zero_exchange_rate',
        ];
        sort($expectedFailures);
        sort($requiredFailures);

        if ($expectedFailures !== $requiredFailures) {
            throw new RuntimeException(
                'DB-TEST-CATALOGOS did not reject every invalid case.'
            );
        }

        if ($transientCounts !== [
            'monedas' => 1,
            'tipos_cambio' => 1,
            'unidades_medida' => 1,
            'impuestos' => 1,
            'lineas_producto' => 1,
            'marcas' => 1,
            'clasificaciones_producto' => 2,
        ]) {
            throw new RuntimeException(
                'DB-TEST-CATALOGOS valid transient records were not accepted.'
            );
        }

        $seedAfter = $this->persistentCounts($pdo);

        if ($seedAfter !== $seedBefore) {
            throw new RuntimeException(
                'DB-TEST-CATALOGOS did not roll back every transient record.'
            );
        }

        return [
            'database' => $currentDatabase,
            'mysql_version' => (string) $pdo->query(
                'SELECT VERSION()'
            )->fetchColumn(),
            'migration_rows' => (int) $pdo->query(
                "SELECT COUNT(*)
                 FROM schema_migrations
                 WHERE migration =
                     'db_catalogos_1_001_create_base_catalog_tables'"
            )->fetchColumn(),
            'tables' => array_column($metadata, 'table_name'),
            'engines' => array_values(
                array_unique(array_column($metadata, 'engine'))
            ),
            'collations' => array_values(
                array_unique(array_column($metadata, 'table_collation'))
            ),
            'required_indexes' => $requiredIndexes,
            'required_foreign_keys' => $requiredForeignKeys,
            'audit_foreign_keys' => count($auditForeignKeys),
            'seed_codes' => $seedCodes,
            'base_currency' => 'MXN',
            'persistent_counts_before' => $seedBefore,
            'persistent_counts_after' => $seedAfter,
            'duplicate_counts' => $duplicateCounts,
            'valid_transient_counts' => $transientCounts,
            'expected_failures' => $expectedFailures,
            'hierarchy' => [
                'valid_parent_child' => true,
                'orphan_parent_rejected' => true,
                'cycle_validation' => 'pending_future_write_service',
            ],
            'operational_tables_absent' => true,
            'cleanup' => 'transaction_rolled_back',
        ];
    }

    private function assertPrerequisites(PDO $pdo): void
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM schema_migrations
             WHERE migration IN (
                 :core_migration,
                 :catalog_migration
             )'
        );
        $statement->execute([
            'core_migration' => 'db_core_0_001_create_core_identity_tables',
            'catalog_migration' =>
                'db_catalogos_1_001_create_base_catalog_tables',
        ]);

        if ((int) $statement->fetchColumn() !== 2) {
            throw new RuntimeException(
                'DB-TEST-CATALOGOS requires DB-CORE-0 and DB-CATALOGOS-1.'
            );
        }
    }

    /**
     * @return list<array{table_name: string, engine: string, table_collation: string}>
     */
    private function tableMetadata(PDO $pdo, string $database): array
    {
        $placeholders = [];
        $parameters = ['database' => $database];

        foreach (self::TABLES as $index => $table) {
            $key = 'table_' . $index;
            $placeholders[] = ':' . $key;
            $parameters[$key] = $table;
        }

        $statement = $pdo->prepare(
            'SELECT
                 table_name AS table_name,
                 engine AS engine,
                 table_collation AS table_collation
             FROM information_schema.tables
             WHERE table_schema = :database
               AND table_name IN (' . implode(', ', $placeholders) . ')
             ORDER BY table_name'
        );
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    /**
     * @return list<string>
     */
    private function indexNames(PDO $pdo, string $database): array
    {
        $statement = $pdo->prepare(
            "SELECT DISTINCT CONCAT(table_name, '.', index_name) AS index_key
             FROM information_schema.statistics
             WHERE table_schema = :database
               AND table_name IN (
                   'monedas',
                   'tipos_cambio',
                   'unidades_medida',
                   'impuestos',
                   'lineas_producto',
                   'marcas',
                   'clasificaciones_producto'
               )
             ORDER BY index_key"
        );
        $statement->execute(['database' => $database]);

        return array_column($statement->fetchAll(), 'index_key');
    }

    /**
     * @return list<string>
     */
    private function foreignKeyNames(PDO $pdo, string $database): array
    {
        $statement = $pdo->prepare(
            "SELECT DISTINCT constraint_name AS constraint_name
             FROM information_schema.referential_constraints
             WHERE constraint_schema = :database
               AND table_name IN (
                   'monedas',
                   'tipos_cambio',
                   'unidades_medida',
                   'impuestos',
                   'lineas_producto',
                   'marcas',
                   'clasificaciones_producto'
               )
             ORDER BY constraint_name"
        );
        $statement->execute(['database' => $database]);

        return array_column($statement->fetchAll(), 'constraint_name');
    }

    /**
     * @return array<string, int>
     */
    private function persistentCounts(PDO $pdo): array
    {
        $counts = [];

        foreach (self::TABLES as $table) {
            $counts[$table] = (int) $pdo->query(
                'SELECT COUNT(*) FROM ' . $table
            )->fetchColumn();
        }

        return $counts;
    }

    /**
     * @return array<string, list<string>>
     */
    private function seedCodes(PDO $pdo): array
    {
        $result = [];

        foreach (['monedas', 'unidades_medida', 'impuestos'] as $table) {
            $result[$table] = $pdo->query(
                'SELECT codigo FROM ' . $table . ' ORDER BY codigo'
            )->fetchAll(PDO::FETCH_COLUMN);
        }

        return $result;
    }

    /**
     * @return array<string, int>
     */
    private function duplicateCounts(PDO $pdo): array
    {
        $counts = [];

        foreach ([
            'monedas',
            'unidades_medida',
            'impuestos',
            'lineas_producto',
            'marcas',
            'clasificaciones_producto',
        ] as $table) {
            $counts[$table] = (int) $pdo->query(
                'SELECT COUNT(*)
                 FROM (
                     SELECT codigo
                     FROM ' . $table . '
                     GROUP BY codigo
                     HAVING COUNT(*) > 1
                 ) duplicates'
            )->fetchColumn();
        }

        $counts['tipos_cambio'] = (int) $pdo->query(
            'SELECT COUNT(*)
             FROM (
                 SELECT moneda_origen_id, moneda_destino_id, fecha
                 FROM tipos_cambio
                 GROUP BY moneda_origen_id, moneda_destino_id, fecha
                 HAVING COUNT(*) > 1
             ) duplicates'
        )->fetchColumn();
        $counts['moneda_base'] = (int) $pdo->query(
            'SELECT GREATEST(COUNT(*) - 1, 0)
             FROM monedas
             WHERE es_base = 1
               AND eliminado_en IS NULL'
        )->fetchColumn();

        return $counts;
    }

    private function initialAdminId(PDO $pdo): int
    {
        $statement = $pdo->prepare(
            'SELECT id
             FROM usuarios
             WHERE username = :username
               AND email = :email
               AND activo = 1
               AND eliminado_en IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'username' => strtolower(trim($this->adminUsername)),
            'email' => strtolower(trim($this->adminEmail)),
        ]);
        $userId = $statement->fetchColumn();

        if ($userId === false) {
            throw new RuntimeException(
                'The configured initial administrator is missing.'
            );
        }

        return (int) $userId;
    }

    private function currencyId(PDO $pdo, string $code): int
    {
        $statement = $pdo->prepare(
            'SELECT id FROM monedas WHERE codigo = :codigo'
        );
        $statement->execute(['codigo' => $code]);
        $id = $statement->fetchColumn();

        if ($id === false) {
            throw new RuntimeException('Required currency is missing: ' . $code);
        }

        return (int) $id;
    }

    private function insertCurrency(
        PDO $pdo,
        string $code,
        string $name,
        string $symbol,
        int $decimals,
        int $isBase,
        int $actorId
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO monedas (
                codigo,
                nombre,
                simbolo,
                decimales,
                es_base,
                activo,
                creado_por,
                actualizado_por
             )
             VALUES (
                :codigo,
                :nombre,
                :simbolo,
                :decimales,
                :es_base,
                1,
                :creado_por,
                :actualizado_por
             )'
        );
        $statement->execute([
            'codigo' => $code,
            'nombre' => $name,
            'simbolo' => $symbol,
            'decimales' => $decimals,
            'es_base' => $isBase,
            'creado_por' => $actorId,
            'actualizado_por' => $actorId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertExchangeRate(
        PDO $pdo,
        int $originId,
        int $destinationId,
        string $date,
        string $value,
        int $actorId
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO tipos_cambio (
                moneda_origen_id,
                moneda_destino_id,
                fecha,
                valor,
                activo,
                creado_por,
                actualizado_por
             )
             VALUES (
                :origen_id,
                :destino_id,
                :fecha,
                :valor,
                1,
                :creado_por,
                :actualizado_por
             )'
        );
        $statement->execute([
            'origen_id' => $originId,
            'destino_id' => $destinationId,
            'fecha' => $date,
            'valor' => $value,
            'creado_por' => $actorId,
            'actualizado_por' => $actorId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertUnit(
        PDO $pdo,
        string $code,
        string $name,
        string $abbreviation,
        int $actorId
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO unidades_medida (
                codigo,
                nombre,
                abreviatura,
                activo,
                creado_por,
                actualizado_por
             )
             VALUES (
                :codigo,
                :nombre,
                :abreviatura,
                1,
                :creado_por,
                :actualizado_por
             )'
        );
        $statement->execute([
            'codigo' => $code,
            'nombre' => $name,
            'abreviatura' => $abbreviation,
            'creado_por' => $actorId,
            'actualizado_por' => $actorId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertTax(
        PDO $pdo,
        string $code,
        string $name,
        string $rate,
        string $type,
        int $actorId
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO impuestos (
                codigo,
                nombre,
                tasa,
                tipo,
                activo,
                creado_por,
                actualizado_por
             )
             VALUES (
                :codigo,
                :nombre,
                :tasa,
                :tipo,
                1,
                :creado_por,
                :actualizado_por
             )'
        );
        $statement->execute([
            'codigo' => $code,
            'nombre' => $name,
            'tasa' => $rate,
            'tipo' => $type,
            'creado_por' => $actorId,
            'actualizado_por' => $actorId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertNamedCatalog(
        PDO $pdo,
        string $table,
        string $code,
        string $name,
        int $actorId
    ): int {
        if (!in_array($table, ['lineas_producto', 'marcas'], true)) {
            throw new InvalidArgumentException('Unsafe catalog table.');
        }

        $statement = $pdo->prepare(
            'INSERT INTO ' . $table . ' (
                codigo,
                nombre,
                activo,
                creado_por,
                actualizado_por
             )
             VALUES (
                :codigo,
                :nombre,
                1,
                :creado_por,
                :actualizado_por
             )'
        );
        $statement->execute([
            'codigo' => $code,
            'nombre' => $name,
            'creado_por' => $actorId,
            'actualizado_por' => $actorId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertClassification(
        PDO $pdo,
        ?int $parentId,
        string $code,
        string $name,
        int $actorId
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO clasificaciones_producto (
                parent_id,
                codigo,
                nombre,
                activo,
                creado_por,
                actualizado_por
             )
             VALUES (
                :parent_id,
                :codigo,
                :nombre,
                1,
                :creado_por,
                :actualizado_por
             )'
        );
        $statement->execute([
            'parent_id' => $parentId,
            'codigo' => $code,
            'nombre' => $name,
            'creado_por' => $actorId,
            'actualizado_por' => $actorId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function countByCode(PDO $pdo, string $table, string $code): int
    {
        if (!in_array(
            $table,
            [
                'monedas',
                'unidades_medida',
                'impuestos',
                'lineas_producto',
                'marcas',
            ],
            true
        )) {
            throw new InvalidArgumentException('Unsafe count table.');
        }

        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM ' . $table . ' WHERE codigo = :codigo'
        );
        $statement->execute(['codigo' => $code]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @param list<string> $failures
     */
    private function expectConstraintFailure(
        callable $operation,
        string $label,
        array &$failures
    ): void {
        try {
            $operation();
        } catch (PDOException $exception) {
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);

            if (!in_array($driverCode, [1062, 1452, 3819, 4025], true)) {
                throw new RuntimeException(
                    'Unexpected database error while testing: ' . $label,
                    0,
                    $exception
                );
            }

            $failures[] = $label;
            return;
        }

        throw new RuntimeException(
            'Expected constraint failure was accepted: ' . $label
        );
    }
};
