<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

return new class implements Migration {
    public function id(): string
    {
        return 'inventario_1_001_create_inventory_core';
    }

    public function up(PDO $pdo): void
    {
        foreach ([
            'db_scope_1_001_create_scope_tables',
            'db_productos_2_001_extend_product_master',
        ] as $migration) {
            $statement = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM schema_migrations
                 WHERE migration = :migration'
            );
            $statement->execute(['migration' => $migration]);

            if ((int) $statement->fetchColumn() !== 1) {
                throw new RuntimeException(
                    'DB-INVENTARIO-1 requires ' . $migration . '.'
                );
            }
        }

        $existingTables = (int) $pdo->query(
            <<<'SQL'
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name IN (
                  'conceptos_movimiento_inventario',
                  'movimientos_inventario',
                  'movimientos_inventario_detalle',
                  'existencias_producto'
              )
            SQL
        )->fetchColumn();

        if ($existingTables !== 0) {
            throw new RuntimeException(
                'DB-INVENTARIO-1 requires its inventory tables to be absent.'
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
            'DROP TABLE IF EXISTS existencias_producto',
            'DROP TABLE IF EXISTS movimientos_inventario_detalle',
            'DROP TABLE IF EXISTS movimientos_inventario',
            'DROP TABLE IF EXISTS conceptos_movimiento_inventario',
        ] as $statement) {
            $pdo->exec($statement);
        }
    }

    /**
     * @return list<string>
     */
    private function upStatements(): array
    {
        return [
            <<<'SQL'
            CREATE TABLE conceptos_movimiento_inventario (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                codigo VARCHAR(32)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL,
                nombre VARCHAR(100) NOT NULL,
                descripcion VARCHAR(255) NULL,
                naturaleza VARCHAR(16)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                creado_por BIGINT UNSIGNED NULL,
                actualizado_por BIGINT UNSIGNED NULL,
                eliminado_en DATETIME NULL,
                eliminado_por BIGINT UNSIGNED NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_conceptos_movimiento_inventario_codigo (
                    codigo
                ),
                KEY idx_conceptos_movimiento_inventario_activo (activo),
                KEY idx_conceptos_movimiento_inventario_eliminado_en (
                    eliminado_en
                ),
                CONSTRAINT chk_conceptos_movimiento_inventario_codigo CHECK (
                    REGEXP_LIKE(
                        codigo,
                        '^[A-Z0-9_]{1,32}$',
                        'c'
                    )
                ),
                CONSTRAINT chk_conceptos_movimiento_inventario_nombre CHECK (
                    CHAR_LENGTH(nombre) BETWEEN 1 AND 100
                ),
                CONSTRAINT chk_conceptos_movimiento_inventario_naturaleza CHECK (
                    naturaleza IN ('ENTRADA', 'SALIDA')
                ),
                CONSTRAINT chk_conceptos_movimiento_inventario_activo CHECK (
                    activo IN (0, 1)
                ),
                CONSTRAINT fk_conceptos_movimiento_inventario_creado_por
                    FOREIGN KEY (creado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_conceptos_movimiento_inventario_actualizado_por
                    FOREIGN KEY (actualizado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_conceptos_movimiento_inventario_eliminado_por
                    FOREIGN KEY (eliminado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE movimientos_inventario (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                empresa_id BIGINT UNSIGNED NOT NULL,
                almacen_id BIGINT UNSIGNED NOT NULL,
                concepto_movimiento_id BIGINT UNSIGNED NOT NULL,
                fecha_movimiento DATETIME NOT NULL,
                estado VARCHAR(16)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL DEFAULT 'BORRADOR',
                referencia VARCHAR(100) NULL,
                observaciones VARCHAR(500) NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                creado_por BIGINT UNSIGNED NULL,
                aplicado_en DATETIME NULL,
                aplicado_por BIGINT UNSIGNED NULL,
                PRIMARY KEY (id),
                KEY idx_movimientos_inventario_empresa_almacen (
                    empresa_id,
                    almacen_id
                ),
                KEY idx_movimientos_inventario_almacen_fecha (
                    almacen_id,
                    fecha_movimiento
                ),
                KEY idx_movimientos_inventario_concepto (
                    concepto_movimiento_id
                ),
                KEY idx_movimientos_inventario_estado (estado),
                KEY idx_movimientos_inventario_fecha (fecha_movimiento),
                CONSTRAINT chk_movimientos_inventario_estado CHECK (
                    estado IN ('BORRADOR', 'APLICADO', 'ANULADO')
                ),
                CONSTRAINT chk_movimientos_inventario_referencia CHECK (
                    referencia IS NULL
                    OR CHAR_LENGTH(referencia) BETWEEN 1 AND 100
                ),
                CONSTRAINT chk_movimientos_inventario_observaciones CHECK (
                    observaciones IS NULL
                    OR CHAR_LENGTH(observaciones) BETWEEN 1 AND 500
                ),
                CONSTRAINT fk_movimientos_inventario_empresa
                    FOREIGN KEY (empresa_id) REFERENCES empresas (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_movimientos_inventario_empresa_almacen
                    FOREIGN KEY (empresa_id, almacen_id)
                    REFERENCES almacenes (empresa_id, id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_movimientos_inventario_concepto
                    FOREIGN KEY (concepto_movimiento_id)
                    REFERENCES conceptos_movimiento_inventario (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_movimientos_inventario_creado_por
                    FOREIGN KEY (creado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_movimientos_inventario_aplicado_por
                    FOREIGN KEY (aplicado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE movimientos_inventario_detalle (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                movimiento_id BIGINT UNSIGNED NOT NULL,
                id_producto VARCHAR(16)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL,
                cantidad DECIMAL(18,6) NOT NULL,
                observaciones VARCHAR(500) NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                creado_por BIGINT UNSIGNED NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_movimientos_inventario_detalle_producto (
                    movimiento_id,
                    id_producto
                ),
                KEY idx_movimientos_inventario_detalle_producto (
                    id_producto
                ),
                CONSTRAINT chk_movimientos_inventario_detalle_cantidad CHECK (
                    cantidad > 0
                ),
                CONSTRAINT chk_movimientos_inventario_detalle_observaciones CHECK (
                    observaciones IS NULL
                    OR CHAR_LENGTH(observaciones) BETWEEN 1 AND 500
                ),
                CONSTRAINT fk_movimientos_inventario_detalle_movimiento
                    FOREIGN KEY (movimiento_id)
                    REFERENCES movimientos_inventario (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_movimientos_inventario_detalle_producto
                    FOREIGN KEY (id_producto)
                    REFERENCES productos (id_producto)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_movimientos_inventario_detalle_creado_por
                    FOREIGN KEY (creado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE existencias_producto (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                almacen_id BIGINT UNSIGNED NOT NULL,
                id_producto VARCHAR(16)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL,
                cantidad_actual DECIMAL(18,6) NOT NULL DEFAULT 0.000000,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_existencias_producto_almacen_producto (
                    almacen_id,
                    id_producto
                ),
                KEY idx_existencias_producto_producto (id_producto),
                CONSTRAINT fk_existencias_producto_almacen
                    FOREIGN KEY (almacen_id)
                    REFERENCES almacenes (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_existencias_producto_producto
                    FOREIGN KEY (id_producto)
                    REFERENCES productos (id_producto)
                    ON UPDATE RESTRICT ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
            SQL,
        ];
    }
};
