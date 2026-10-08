<?php

declare(strict_types=1);

use App\Infrastructure\Database\DatabaseTest;

return new class implements DatabaseTest {
    private const TABLES = ['unidades_sat', 'claves_sat'];

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
                'The active database does not match DB-SAT-1 DB-TEST.'
            );
        }

        $migrationRows = $this->migrationRows($pdo);
        $tables = $this->tableMetadata($pdo, $database);
        $columns = $this->columnMetadata($pdo, $database);
        $indexes = $this->indexNames($pdo, $database);
        $constraints = $this->constraintNames($pdo, $database);
        $foreignKeys = $this->foreignKeys($pdo, $database);

        if (
            $migrationRows !== 1
            || count($tables) !== count(self::TABLES)
            || count($columns) !== count(self::TABLES)
        ) {
            throw new RuntimeException(
                'DB-SAT-1 migration evidence is incomplete.'
            );
        }

        foreach ($tables as $table) {
            if (
                strtoupper((string) $table['engine']) !== 'INNODB'
                || strtolower((string) $table['table_collation'])
                    !== 'utf8mb4_unicode_ci'
            ) {
                throw new RuntimeException(
                    'DB-SAT-1 tables require InnoDB and utf8mb4_unicode_ci.'
                );
            }
        }

        $this->assertColumns($columns);
        $this->assertIndexes($indexes);
        $this->assertConstraints($constraints);
        $this->assertForeignKeys($foreignKeys);

        $before = $this->counts($pdo);
        $expectedCounts = array_fill_keys(self::TABLES, 0);

        if ($before !== $expectedCounts) {
            throw new RuntimeException(
                'DB-SAT-1 requires empty persistent SAT catalogs.'
            );
        }

        $functional = $this->functionalTest($pdo);

        if ($this->counts($pdo) !== $before) {
            throw new RuntimeException(
                'DB-SAT-1 transient data was not rolled back.'
            );
        }

        return [
            'database' => $database,
            'mysql_version' => (string) $pdo->query(
                'SELECT VERSION()'
            )->fetchColumn(),
            'migration_rows' => $migrationRows,
            'tables' => $tables,
            'columns' => $columns,
            'indexes' => $indexes,
            'constraints' => $constraints,
            'foreign_keys' => $foreignKeys,
            'functional' => $functional,
            'persistent_counts_before' => $before,
            'persistent_counts_after' => $this->counts($pdo),
            'physical_delete_routes' => 0,
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
            'migration' => 'db_sat_1_001_create_sat_catalogs',
        ]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return array<string, array{engine: string, table_collation: string}>
     */
    private function tableMetadata(PDO $pdo, string $database): array
    {
        $statement = $pdo->prepare(
            "SELECT
                TABLE_NAME AS table_name,
                ENGINE AS engine,
                TABLE_COLLATION AS table_collation
             FROM information_schema.tables
             WHERE table_schema = :database
               AND table_name IN ('unidades_sat', 'claves_sat')
             ORDER BY table_name"
        );
        $statement->execute(['database' => $database]);
        $result = [];

        foreach ($statement->fetchAll() as $row) {
            $result[(string) $row['table_name']] = [
                'engine' => (string) $row['engine'],
                'table_collation' => (string) $row['table_collation'],
            ];
        }

        return $result;
    }

    /**
     * @return array<string, array<string, array<string, string|null>>>
     */
    private function columnMetadata(PDO $pdo, string $database): array
    {
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
             WHERE table_schema = :database
               AND table_name IN ('unidades_sat', 'claves_sat')
             ORDER BY table_name, ORDINAL_POSITION"
        );
        $statement->execute(['database' => $database]);
        $result = [];

        foreach ($statement->fetchAll() as $row) {
            $table = (string) $row['table_name'];
            $result[$table][(string) $row['column_name']] = [
                'column_type' => (string) $row['column_type'],
                'is_nullable' => (string) $row['is_nullable'],
                'column_default' => $row['column_default'] === null
                    ? null
                    : (string) $row['column_default'],
                'character_set' => $row['character_set'] === null
                    ? null
                    : (string) $row['character_set'],
                'collation_name' => $row['collation_name'] === null
                    ? null
                    : (string) $row['collation_name'],
                'extra' => (string) $row['extra'],
            ];
        }

        return $result;
    }

    /**
     * @param array<string, array<string, array<string, string|null>>> $columns
     */
    private function assertColumns(array $columns): void
    {
        $expected = [
            'unidades_sat' => [
                'id',
                'codigo',
                'nombre',
                'descripcion',
                'activo',
                'eliminado_en',
                'creado_por',
                'actualizado_por',
                'eliminado_por',
                'creado_en',
                'actualizado_en',
            ],
            'claves_sat' => [
                'id',
                'codigo',
                'descripcion',
                'activo',
                'eliminado_en',
                'creado_por',
                'actualizado_por',
                'eliminado_por',
                'creado_en',
                'actualizado_en',
            ],
        ];

        foreach ($expected as $table => $columnNames) {
            if (array_keys($columns[$table] ?? []) !== $columnNames) {
                throw new RuntimeException(
                    'DB-SAT-1 column order is invalid for ' . $table . '.'
                );
            }

            if (
                ($columns[$table]['codigo']['character_set'] ?? null)
                    !== 'ascii'
                || ($columns[$table]['codigo']['collation_name'] ?? null)
                    !== 'ascii_bin'
                || !str_contains(
                    (string) ($columns[$table]['id']['extra'] ?? ''),
                    'auto_increment'
                )
            ) {
                throw new RuntimeException(
                    'DB-SAT-1 structural column contract failed for ' . $table . '.'
                );
            }
        }

        if (
            ($columns['unidades_sat']['nombre']['is_nullable'] ?? null) !== 'NO'
            || ($columns['unidades_sat']['descripcion']['is_nullable'] ?? null)
                !== 'YES'
            || ($columns['claves_sat']['descripcion']['is_nullable'] ?? null)
                !== 'NO'
        ) {
            throw new RuntimeException(
                'DB-SAT-1 required descriptive fields are invalid.'
            );
        }
    }

    /**
     * @return list<string>
     */
    private function indexNames(PDO $pdo, string $database): array
    {
        $statement = $pdo->prepare(
            "SELECT CONCAT(TABLE_NAME, '.', INDEX_NAME) AS index_name
             FROM information_schema.statistics
             WHERE table_schema = :database
               AND table_name IN ('unidades_sat', 'claves_sat')
             ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX"
        );
        $statement->execute(['database' => $database]);

        return array_values(array_unique(array_map(
            'strval',
            $statement->fetchAll(PDO::FETCH_COLUMN)
        )));
    }

    /**
     * @param list<string> $indexes
     */
    private function assertIndexes(array $indexes): void
    {
        $required = [
            'claves_sat.PRIMARY',
            'claves_sat.idx_claves_sat_activo',
            'claves_sat.idx_claves_sat_eliminado_en',
            'claves_sat.uq_claves_sat_codigo',
            'unidades_sat.PRIMARY',
            'unidades_sat.idx_unidades_sat_activo',
            'unidades_sat.idx_unidades_sat_eliminado_en',
            'unidades_sat.uq_unidades_sat_codigo',
        ];

        if (array_diff($required, $indexes) !== []) {
            throw new RuntimeException(
                'DB-SAT-1 required indexes are incomplete.'
            );
        }
    }

    /**
     * @return list<string>
     */
    private function constraintNames(PDO $pdo, string $database): array
    {
        $statement = $pdo->prepare(
            "SELECT CONSTRAINT_NAME AS constraint_name
             FROM information_schema.table_constraints
             WHERE constraint_schema = :database
               AND table_name IN ('unidades_sat', 'claves_sat')
             ORDER BY table_name, constraint_name"
        );
        $statement->execute(['database' => $database]);

        return array_values(array_unique(array_map(
            'strval',
            $statement->fetchAll(PDO::FETCH_COLUMN)
        )));
    }

    /**
     * @param list<string> $constraints
     */
    private function assertConstraints(array $constraints): void
    {
        $required = [
            'chk_claves_sat_activo',
            'chk_claves_sat_codigo',
            'chk_claves_sat_descripcion',
            'chk_unidades_sat_activo',
            'chk_unidades_sat_codigo',
            'chk_unidades_sat_nombre',
            'fk_claves_sat_actualizado_por',
            'fk_claves_sat_creado_por',
            'fk_claves_sat_eliminado_por',
            'fk_unidades_sat_actualizado_por',
            'fk_unidades_sat_creado_por',
            'fk_unidades_sat_eliminado_por',
            'PRIMARY',
            'uq_claves_sat_codigo',
            'uq_unidades_sat_codigo',
        ];

        if (array_diff($required, $constraints) !== []) {
            throw new RuntimeException(
                'DB-SAT-1 required constraints are incomplete.'
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function foreignKeys(PDO $pdo, string $database): array
    {
        $statement = $pdo->prepare(
            "SELECT
                CONSTRAINT_NAME AS constraint_name,
                CONCAT(
                    TABLE_NAME,
                    '.',
                    COLUMN_NAME,
                    '->',
                    REFERENCED_TABLE_NAME,
                    '.',
                    REFERENCED_COLUMN_NAME
                ) AS relation
             FROM information_schema.key_column_usage
             WHERE constraint_schema = :database
               AND table_name IN ('unidades_sat', 'claves_sat')
               AND referenced_table_name IS NOT NULL
             ORDER BY constraint_name"
        );
        $statement->execute(['database' => $database]);
        $result = [];

        foreach ($statement->fetchAll() as $row) {
            $result[(string) $row['constraint_name']]
                = (string) $row['relation'];
        }

        return $result;
    }

    /**
     * @param array<string, string> $foreignKeys
     */
    private function assertForeignKeys(array $foreignKeys): void
    {
        $required = [
            'fk_claves_sat_actualizado_por'
                => 'claves_sat.actualizado_por->usuarios.id',
            'fk_claves_sat_creado_por'
                => 'claves_sat.creado_por->usuarios.id',
            'fk_claves_sat_eliminado_por'
                => 'claves_sat.eliminado_por->usuarios.id',
            'fk_unidades_sat_actualizado_por'
                => 'unidades_sat.actualizado_por->usuarios.id',
            'fk_unidades_sat_creado_por'
                => 'unidades_sat.creado_por->usuarios.id',
            'fk_unidades_sat_eliminado_por'
                => 'unidades_sat.eliminado_por->usuarios.id',
        ];

        foreach ($required as $name => $relation) {
            if (($foreignKeys[$name] ?? null) !== $relation) {
                throw new RuntimeException(
                    'DB-SAT-1 foreign key is invalid: ' . $name . '.'
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

    /**
     * @return array<string, mixed>
     */
    private function functionalTest(PDO $pdo): array
    {
        $failures = [];
        $pdo->beginTransaction();

        try {
            $this->insertUnidad($pdo, 'H87', 'Pieza');
            $this->insertClave($pdo, '01010101', 'No existe en el catálogo');

            $this->expectFailure(
                'unidad_duplicate_code',
                fn () => $this->insertUnidad($pdo, 'H87', 'Duplicada'),
                $failures
            );
            $this->expectFailure(
                'unidad_empty_name',
                fn () => $this->insertUnidad($pdo, 'KGM', ''),
                $failures
            );
            $this->expectFailure(
                'unidad_invalid_active',
                fn () => $this->insertUnidad($pdo, 'MTR', 'Metro', 2),
                $failures
            );
            $this->expectFailure(
                'clave_duplicate_code',
                fn () => $this->insertClave(
                    $pdo,
                    '01010101',
                    'Duplicada'
                ),
                $failures
            );
            $this->expectFailure(
                'clave_empty_description',
                fn () => $this->insertClave($pdo, '01010102', ''),
                $failures
            );
            $this->expectFailure(
                'clave_invalid_active',
                fn () => $this->insertClave(
                    $pdo,
                    '01010103',
                    'Activo inválido',
                    2
                ),
                $failures
            );

            $transientCounts = $this->counts($pdo);
        } finally {
            $pdo->rollBack();
        }

        return [
            'valid_unidad_sat' => true,
            'valid_clave_sat' => true,
            'expected_failures' => array_keys($failures),
            'transient_counts' => $transientCounts ?? [],
            'physical_delete_implemented' => false,
        ];
    }

    private function insertUnidad(
        PDO $pdo,
        string $codigo,
        string $nombre,
        int $activo = 1
    ): void {
        $statement = $pdo->prepare(
            'INSERT INTO unidades_sat (codigo, nombre, activo)
             VALUES (:codigo, :nombre, :activo)'
        );
        $statement->execute([
            'codigo' => $codigo,
            'nombre' => $nombre,
            'activo' => $activo,
        ]);
    }

    private function insertClave(
        PDO $pdo,
        string $codigo,
        string $descripcion,
        int $activo = 1
    ): void {
        $statement = $pdo->prepare(
            'INSERT INTO claves_sat (codigo, descripcion, activo)
             VALUES (:codigo, :descripcion, :activo)'
        );
        $statement->execute([
            'codigo' => $codigo,
            'descripcion' => $descripcion,
            'activo' => $activo,
        ]);
    }

    /**
     * @param array<string, true> $failures
     */
    private function expectFailure(
        string $label,
        callable $operation,
        array &$failures
    ): void {
        try {
            $operation();
        } catch (PDOException) {
            $failures[$label] = true;
            return;
        }

        throw new RuntimeException(
            'DB-SAT-1 expected failure did not occur: ' . $label . '.'
        );
    }
};
