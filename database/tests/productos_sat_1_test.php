<?php

declare(strict_types=1);

use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Database\Migration;
use App\Infrastructure\Database\MigrationRunner;

return new class implements DatabaseTest {
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

        if ($database !== $expectedDatabase) {
            throw new RuntimeException(
                'The active database does not match PRODUCTOS-SAT-1 DB-TEST.'
            );
        }

        $migration = require BASE_PATH
            . '/database/migrations/productos_sat_1_001_add_sat_relations.php';

        if (!$migration instanceof Migration) {
            throw new RuntimeException(
                'PRODUCTOS-SAT-1 migration has an invalid contract.'
            );
        }

        $runner = new MigrationRunner($pdo);
        $firstMigration = $runner->migrate($migration);
        $secondMigration = $runner->migrate($migration);
        $migrationRows = $this->migrationRows($pdo);
        $columns = $this->columns($pdo, $database);
        $indexes = $this->indexes($pdo, $database);
        $foreignKeys = $this->foreignKeys($pdo, $database);
        $before = $this->counts($pdo);

        if (
            $migrationRows !== 1
            || !in_array($firstMigration, ['applied', 'already_applied'], true)
            || $secondMigration !== 'already_applied'
            || ($columns['clave_sat_id']['column_type'] ?? '') !== 'bigint unsigned'
            || ($columns['clave_sat_id']['is_nullable'] ?? '') !== 'YES'
            || ($columns['unidad_sat_id']['column_type'] ?? '') !== 'bigint unsigned'
            || ($columns['unidad_sat_id']['is_nullable'] ?? '') !== 'YES'
            || !in_array('idx_productos_clave_sat', $indexes, true)
            || !in_array('idx_productos_unidad_sat', $indexes, true)
            || ($foreignKeys['fk_productos_clave_sat'] ?? null)
                !== 'productos.clave_sat_id->claves_sat.id:RESTRICT/RESTRICT'
            || ($foreignKeys['fk_productos_unidad_sat'] ?? null)
                !== 'productos.unidad_sat_id->unidades_sat.id:RESTRICT/RESTRICT'
        ) {
            throw new RuntimeException(
                'PRODUCTOS-SAT-1 structural evidence is invalid.'
            );
        }

        $functional = $this->functional($pdo);

        if ($this->counts($pdo) !== $before) {
            throw new RuntimeException(
                'PRODUCTOS-SAT-1 transient data was not rolled back.'
            );
        }

        return [
            'database' => $database,
            'migration' => $migration->id(),
            'migration_first_run' => $firstMigration,
            'migration_second_run' => $secondMigration,
            'migration_rows' => $migrationRows,
            'columns' => $columns,
            'indexes' => $indexes,
            'foreign_keys' => $foreignKeys,
            'functional' => $functional,
            'persistent_counts_before' => $before,
            'persistent_counts_after' => $this->counts($pdo),
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
            'migration' => 'productos_sat_1_001_add_sat_relations',
        ]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return array<string, array{column_type: string, is_nullable: string}>
     */
    private function columns(PDO $pdo, string $database): array
    {
        $statement = $pdo->prepare(
            "SELECT COLUMN_NAME AS column_name,
                    COLUMN_TYPE AS column_type,
                    IS_NULLABLE AS is_nullable
             FROM information_schema.columns
             WHERE table_schema = :database
               AND table_name = 'productos'
               AND column_name IN ('clave_sat_id', 'unidad_sat_id')
             ORDER BY ORDINAL_POSITION"
        );
        $statement->execute(['database' => $database]);
        $result = [];

        foreach ($statement->fetchAll() as $row) {
            $result[(string) $row['column_name']] = [
                'column_type' => (string) $row['column_type'],
                'is_nullable' => (string) $row['is_nullable'],
            ];
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function indexes(PDO $pdo, string $database): array
    {
        $statement = $pdo->prepare(
            "SELECT DISTINCT INDEX_NAME AS index_name
             FROM information_schema.statistics
             WHERE table_schema = :database
               AND table_name = 'productos'
               AND index_name IN (
                   'idx_productos_clave_sat',
                   'idx_productos_unidad_sat'
               )
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
                k.CONSTRAINT_NAME AS constraint_name,
                k.TABLE_NAME AS table_name,
                k.COLUMN_NAME AS column_name,
                k.REFERENCED_TABLE_NAME AS referenced_table_name,
                k.REFERENCED_COLUMN_NAME AS referenced_column_name,
                r.UPDATE_RULE AS update_rule,
                r.DELETE_RULE AS delete_rule
             FROM information_schema.key_column_usage k
             INNER JOIN information_schema.referential_constraints r
                ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
               AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
             WHERE k.table_schema = :database
               AND k.table_name = 'productos'
               AND k.constraint_name IN (
                   'fk_productos_clave_sat',
                   'fk_productos_unidad_sat'
               )
             ORDER BY k.constraint_name"
        );
        $statement->execute(['database' => $database]);
        $result = [];

        foreach ($statement->fetchAll() as $row) {
            $result[(string) $row['constraint_name']] =
                $row['table_name'] . '.' . $row['column_name']
                . '->' . $row['referenced_table_name']
                . '.' . $row['referenced_column_name']
                . ':' . $row['update_rule']
                . '/' . $row['delete_rule'];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function functional(PDO $pdo): array
    {
        $actorId = (int) $pdo->query(
            'SELECT id FROM usuarios
             WHERE activo = 1 AND eliminado_en IS NULL
             ORDER BY id LIMIT 1'
        )->fetchColumn();
        $unitId = (int) $pdo->query(
            "SELECT id FROM unidades_medida
             WHERE codigo = 'PIEZA' AND activo = 1 AND eliminado_en IS NULL"
        )->fetchColumn();
        $typeId = (int) $pdo->query(
            "SELECT id FROM tipos_producto
             WHERE codigo = 'PRODUCTO' AND activo = 1 AND eliminado_en IS NULL"
        )->fetchColumn();

        if ($actorId < 1 || $unitId < 1 || $typeId < 1) {
            throw new RuntimeException(
                'PRODUCTOS-SAT-1 functional fixtures are unavailable.'
            );
        }

        $pdo->beginTransaction();

        try {
            $satKeyId = $this->insertSatKey(
                $pdo,
                '10101504',
                'Refrigerantes QA',
                1,
                $actorId
            );
            $satUnitId = $this->insertSatUnit(
                $pdo,
                'H87',
                'Pieza QA',
                1,
                $actorId
            );

            $this->insertProduct($pdo, [
                'id_producto' => 'QASATNULL',
                'descripcion' => 'Producto sin SAT',
                'unidad_medida_id' => $unitId,
                'tipo_producto_id' => $typeId,
                'clave_sat_id' => null,
                'unidad_sat_id' => null,
            ]);
            $this->insertProduct($pdo, [
                'id_producto' => 'QASATCLAVE',
                'descripcion' => 'Producto clave',
                'unidad_medida_id' => $unitId,
                'tipo_producto_id' => $typeId,
                'clave_sat_id' => $satKeyId,
                'unidad_sat_id' => null,
            ]);
            $this->insertProduct($pdo, [
                'id_producto' => 'QASATUNIDAD',
                'descripcion' => 'Producto unidad',
                'unidad_medida_id' => $unitId,
                'tipo_producto_id' => $typeId,
                'clave_sat_id' => null,
                'unidad_sat_id' => $satUnitId,
            ]);
            $this->insertProduct($pdo, [
                'id_producto' => 'QASATBOTH',
                'descripcion' => 'Producto SAT ambos',
                'unidad_medida_id' => $unitId,
                'tipo_producto_id' => $typeId,
                'clave_sat_id' => $satKeyId,
                'unidad_sat_id' => $satUnitId,
            ]);

            $this->expectFailure(
                fn () => $this->insertProduct($pdo, [
                    'id_producto' => 'QASATBADKEY',
                    'descripcion' => 'Clave inválida',
                    'unidad_medida_id' => $unitId,
                    'tipo_producto_id' => $typeId,
                    'clave_sat_id' => 999999999,
                    'unidad_sat_id' => null,
                ]),
                'nonexistent_clave_sat'
            );
            $this->expectFailure(
                fn () => $this->insertProduct($pdo, [
                    'id_producto' => 'QASATBADUNIT',
                    'descripcion' => 'Unidad inválida',
                    'unidad_medida_id' => $unitId,
                    'tipo_producto_id' => $typeId,
                    'clave_sat_id' => null,
                    'unidad_sat_id' => 999999999,
                ]),
                'nonexistent_unidad_sat'
            );
            $this->expectFailure(
                fn () => $this->deleteById($pdo, 'claves_sat', $satKeyId),
                'delete_referenced_clave_sat'
            );
            $this->expectFailure(
                fn () => $this->deleteById($pdo, 'unidades_sat', $satUnitId),
                'delete_referenced_unidad_sat'
            );

            $validRows = (int) $pdo->query(
                "SELECT COUNT(*)
                 FROM productos
                 WHERE id_producto IN (
                    'QASATNULL',
                    'QASATCLAVE',
                    'QASATUNIDAD',
                    'QASATBOTH'
                 )"
            )->fetchColumn();

            if ($validRows !== 4) {
                throw new RuntimeException(
                    'PRODUCTOS-SAT-1 valid product cases were not accepted.'
                );
            }

            $pdo->rollBack();

            return [
                'product_without_sat' => true,
                'product_with_key' => true,
                'product_with_unit' => true,
                'product_with_both' => true,
                'nonexistent_key_rejected' => true,
                'nonexistent_unit_rejected' => true,
                'delete_referenced_key_restricted' => true,
                'delete_referenced_unit_restricted' => true,
                'transient_valid_rows' => $validRows,
            ];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    private function insertSatKey(
        PDO $pdo,
        string $code,
        string $description,
        int $active,
        int $actorId
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO claves_sat (
                codigo, descripcion, activo, creado_por, actualizado_por
             ) VALUES (
                :codigo, :descripcion, :activo, :creado_por, :actualizado_por
             )'
        );
        $statement->execute([
            'codigo' => $code,
            'descripcion' => $description,
            'activo' => $active,
            'creado_por' => $actorId,
            'actualizado_por' => $actorId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertSatUnit(
        PDO $pdo,
        string $code,
        string $name,
        int $active,
        int $actorId
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO unidades_sat (
                codigo, nombre, activo, creado_por, actualizado_por
             ) VALUES (
                :codigo, :nombre, :activo, :creado_por, :actualizado_por
             )'
        );
        $statement->execute([
            'codigo' => $code,
            'nombre' => $name,
            'activo' => $active,
            'creado_por' => $actorId,
            'actualizado_por' => $actorId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string, int|string|null> $data
     */
    private function insertProduct(PDO $pdo, array $data): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO productos (
                id_producto,
                descripcion,
                unidad_medida_id,
                tipo_producto_id,
                clave_sat_id,
                unidad_sat_id
             ) VALUES (
                :id_producto,
                :descripcion,
                :unidad_medida_id,
                :tipo_producto_id,
                :clave_sat_id,
                :unidad_sat_id
             )'
        );
        $statement->execute($data);
    }

    private function deleteById(PDO $pdo, string $table, int $id): void
    {
        $statement = $pdo->prepare(
            'DELETE FROM ' . $table . ' WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
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
            'PRODUCTOS-SAT-1 accepted invalid case: ' . $label
        );
    }

    /**
     * @return array<string, int>
     */
    private function counts(PDO $pdo): array
    {
        $counts = [];

        foreach ([
            'productos',
            'claves_sat',
            'unidades_sat',
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
