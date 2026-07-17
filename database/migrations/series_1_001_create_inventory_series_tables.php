<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

return new class implements Migration {
    public function id(): string
    {
        return 'series_1_001_create_inventory_series_tables';
    }

    public function up(PDO $pdo): void
    {
        foreach ([
            'inventario_1_001_create_inventory_core',
            'db_productos_2_001_extend_product_master',
            'db_scope_1_001_create_scope_tables',
        ] as $migration) {
            $statement = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM schema_migrations
                 WHERE migration = :migration'
            );
            $statement->execute(['migration' => $migration]);

            if ((int) $statement->fetchColumn() !== 1) {
                throw new RuntimeException(
                    'SERIES-DB-1 requires ' . $migration . '.'
                );
            }
        }

        $pkEvidence = $this->primaryKeys($pdo);

        if (
            ($pkEvidence['productos'] ?? []) !== ['id_producto']
            || ($pkEvidence['almacenes'] ?? []) !== ['id']
            || ($pkEvidence['movimientos_inventario_detalle'] ?? []) !== ['id']
        ) {
            throw new RuntimeException(
                'SERIES-DB-1 cannot create safe FKs with the current PKs.'
            );
        }

        $existingTables = (int) $pdo->query(
            <<<'SQL'
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name IN (
                  'producto_series',
                  'existencias_serie',
                  'movimiento_detalle_series'
              )
            SQL
        )->fetchColumn();

        if ($existingTables !== 0) {
            throw new RuntimeException(
                'SERIES-DB-1 requires its series tables to be absent.'
            );
        }

        try {
            foreach ($this->upStatements() as $statement) {
                $pdo->exec($statement);
            }
        } catch (Throwable $exception) {
            $this->down($pdo);
            throw $exception;
        }
    }

    public function down(PDO $pdo): void
    {
        foreach ([
            'DROP TABLE IF EXISTS movimiento_detalle_series',
            'DROP TABLE IF EXISTS existencias_serie',
            'DROP TABLE IF EXISTS producto_series',
        ] as $statement) {
            $pdo->exec($statement);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function primaryKeys(PDO $pdo): array
    {
        $statement = $pdo->query(
            <<<'SQL'
            SELECT
                TABLE_NAME AS table_name,
                COLUMN_NAME AS column_name
            FROM information_schema.key_column_usage
            WHERE table_schema = DATABASE()
              AND constraint_name = 'PRIMARY'
              AND table_name IN (
                  'productos',
                  'almacenes',
                  'movimientos_inventario_detalle'
              )
            ORDER BY TABLE_NAME, ORDINAL_POSITION
            SQL
        );
        $result = [];

        foreach ($statement->fetchAll() as $row) {
            $result[(string) $row['table_name']][] =
                (string) $row['column_name'];
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function upStatements(): array
    {
        return [
            <<<'SQL'
            CREATE TABLE producto_series (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                id_producto VARCHAR(16)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL,
                numero_serie VARCHAR(80)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                eliminado_en DATETIME NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_producto_series_producto_numero (
                    id_producto,
                    numero_serie
                ),
                KEY idx_producto_series_numero_serie (numero_serie),
                KEY idx_producto_series_producto (id_producto),
                KEY idx_producto_series_activo (activo),
                KEY idx_producto_series_eliminado_en (eliminado_en),
                CONSTRAINT chk_producto_series_numero_serie CHECK (
                    CHAR_LENGTH(numero_serie) BETWEEN 1 AND 80
                ),
                CONSTRAINT chk_producto_series_activo CHECK (
                    activo IN (0, 1)
                ),
                CONSTRAINT fk_producto_series_producto
                    FOREIGN KEY (id_producto)
                    REFERENCES productos (id_producto)
                    ON UPDATE RESTRICT ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE existencias_serie (
                serie_id BIGINT UNSIGNED NOT NULL,
                almacen_id BIGINT UNSIGNED NULL,
                estado VARCHAR(24)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (serie_id),
                KEY idx_existencias_serie_almacen_estado (
                    almacen_id,
                    estado
                ),
                KEY idx_existencias_serie_estado (estado),
                CONSTRAINT chk_existencias_serie_estado CHECK (
                    estado IN ('EN_EXISTENCIA', 'FUERA_EXISTENCIA')
                ),
                CONSTRAINT chk_existencias_serie_estado_almacen CHECK (
                    (
                        estado = 'EN_EXISTENCIA'
                        AND almacen_id IS NOT NULL
                    )
                    OR (
                        estado = 'FUERA_EXISTENCIA'
                        AND almacen_id IS NULL
                    )
                ),
                CONSTRAINT fk_existencias_serie_serie
                    FOREIGN KEY (serie_id)
                    REFERENCES producto_series (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_existencias_serie_almacen
                    FOREIGN KEY (almacen_id)
                    REFERENCES almacenes (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE movimiento_detalle_series (
                movimiento_detalle_id BIGINT UNSIGNED NOT NULL,
                serie_id BIGINT UNSIGNED NOT NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (movimiento_detalle_id, serie_id),
                KEY idx_movimiento_detalle_series_serie (serie_id),
                CONSTRAINT fk_movimiento_detalle_series_detalle
                    FOREIGN KEY (movimiento_detalle_id)
                    REFERENCES movimientos_inventario_detalle (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_movimiento_detalle_series_serie
                    FOREIGN KEY (serie_id)
                    REFERENCES producto_series (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
            SQL,
        ];
    }
};
