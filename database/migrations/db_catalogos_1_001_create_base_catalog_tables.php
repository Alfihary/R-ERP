<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

return new class implements Migration {
    public function id(): string
    {
        return 'db_catalogos_1_001_create_base_catalog_tables';
    }

    public function up(PDO $pdo): void
    {
        $prerequisite = $pdo->prepare(
            'SELECT COUNT(*)
             FROM schema_migrations
             WHERE migration = :migration'
        );
        $prerequisite->execute([
            'migration' => 'db_core_0_001_create_core_identity_tables',
        ]);

        if ((int) $prerequisite->fetchColumn() !== 1) {
            throw new RuntimeException('DB-CATALOGOS-1 requires DB-CORE-0.');
        }

        $existingTables = (int) $pdo->query(
            <<<'SQL'
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name IN (
                  'monedas',
                  'tipos_cambio',
                  'unidades_medida',
                  'impuestos',
                  'lineas_producto',
                  'marcas',
                  'clasificaciones_producto'
              )
            SQL
        )->fetchColumn();

        if ($existingTables !== 0) {
            throw new RuntimeException(
                'DB-CATALOGOS-1 requires its seven tables to be absent before migration.'
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
            'DROP TABLE IF EXISTS clasificaciones_producto',
            'DROP TABLE IF EXISTS marcas',
            'DROP TABLE IF EXISTS lineas_producto',
            'DROP TABLE IF EXISTS impuestos',
            'DROP TABLE IF EXISTS unidades_medida',
            'DROP TABLE IF EXISTS tipos_cambio',
            'DROP TABLE IF EXISTS monedas',
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
            CREATE TABLE monedas (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                codigo CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                nombre VARCHAR(100) NOT NULL,
                simbolo VARCHAR(10) NOT NULL,
                decimales TINYINT UNSIGNED NOT NULL DEFAULT 2,
                es_base TINYINT(1) NOT NULL DEFAULT 0,
                base_unica TINYINT
                    GENERATED ALWAYS AS (
                        CASE
                            WHEN es_base = 1 AND eliminado_en IS NULL THEN 1
                            ELSE NULL
                        END
                    ) STORED,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                creado_por BIGINT UNSIGNED NULL,
                actualizado_por BIGINT UNSIGNED NULL,
                eliminado_en DATETIME NULL,
                eliminado_por BIGINT UNSIGNED NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_monedas_codigo (codigo),
                UNIQUE KEY uq_monedas_base_unica (base_unica),
                KEY idx_monedas_activo (activo),
                KEY idx_monedas_eliminado_en (eliminado_en),
                CONSTRAINT chk_monedas_codigo CHECK (
                    codigo REGEXP '^[A-Z]{3}$'
                ),
                CONSTRAINT chk_monedas_decimales CHECK (
                    decimales BETWEEN 0 AND 6
                ),
                CONSTRAINT chk_monedas_es_base CHECK (es_base IN (0, 1)),
                CONSTRAINT chk_monedas_activo CHECK (activo IN (0, 1)),
                CONSTRAINT chk_monedas_base_activa CHECK (
                    es_base = 0 OR (activo = 1 AND eliminado_en IS NULL)
                ),
                CONSTRAINT fk_monedas_creado_por
                    FOREIGN KEY (creado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_monedas_actualizado_por
                    FOREIGN KEY (actualizado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_monedas_eliminado_por
                    FOREIGN KEY (eliminado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE tipos_cambio (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                moneda_origen_id BIGINT UNSIGNED NOT NULL,
                moneda_destino_id BIGINT UNSIGNED NOT NULL,
                fecha DATE NOT NULL,
                valor DECIMAL(20, 8) NOT NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                creado_por BIGINT UNSIGNED NULL,
                actualizado_por BIGINT UNSIGNED NULL,
                eliminado_en DATETIME NULL,
                eliminado_por BIGINT UNSIGNED NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_tipos_cambio_par_fecha (
                    moneda_origen_id,
                    moneda_destino_id,
                    fecha
                ),
                KEY idx_tipos_cambio_destino_fecha (
                    moneda_destino_id,
                    fecha
                ),
                KEY idx_tipos_cambio_activo (activo),
                KEY idx_tipos_cambio_eliminado_en (eliminado_en),
                CONSTRAINT chk_tipos_cambio_monedas_distintas CHECK (
                    moneda_origen_id <> moneda_destino_id
                ),
                CONSTRAINT chk_tipos_cambio_valor CHECK (valor > 0),
                CONSTRAINT chk_tipos_cambio_activo CHECK (activo IN (0, 1)),
                CONSTRAINT fk_tipos_cambio_moneda_origen
                    FOREIGN KEY (moneda_origen_id) REFERENCES monedas (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_tipos_cambio_moneda_destino
                    FOREIGN KEY (moneda_destino_id) REFERENCES monedas (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_tipos_cambio_creado_por
                    FOREIGN KEY (creado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_tipos_cambio_actualizado_por
                    FOREIGN KEY (actualizado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_tipos_cambio_eliminado_por
                    FOREIGN KEY (eliminado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE unidades_medida (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                codigo VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                nombre VARCHAR(100) NOT NULL,
                abreviatura VARCHAR(20) NOT NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                creado_por BIGINT UNSIGNED NULL,
                actualizado_por BIGINT UNSIGNED NULL,
                eliminado_en DATETIME NULL,
                eliminado_por BIGINT UNSIGNED NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_unidades_medida_codigo (codigo),
                KEY idx_unidades_medida_activo (activo),
                KEY idx_unidades_medida_eliminado_en (eliminado_en),
                CONSTRAINT chk_unidades_medida_codigo CHECK (
                    CHAR_LENGTH(codigo) BETWEEN 1 AND 32
                    AND codigo REGEXP '^[A-Z0-9]+([_-][A-Z0-9]+)*$'
                ),
                CONSTRAINT chk_unidades_medida_activo CHECK (activo IN (0, 1)),
                CONSTRAINT fk_unidades_medida_creado_por
                    FOREIGN KEY (creado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_unidades_medida_actualizado_por
                    FOREIGN KEY (actualizado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_unidades_medida_eliminado_por
                    FOREIGN KEY (eliminado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE impuestos (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                codigo VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                nombre VARCHAR(100) NOT NULL,
                tasa DECIMAL(7, 4) NOT NULL,
                tipo VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                creado_por BIGINT UNSIGNED NULL,
                actualizado_por BIGINT UNSIGNED NULL,
                eliminado_en DATETIME NULL,
                eliminado_por BIGINT UNSIGNED NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_impuestos_codigo (codigo),
                KEY idx_impuestos_tipo_activo (tipo, activo),
                KEY idx_impuestos_eliminado_en (eliminado_en),
                CONSTRAINT chk_impuestos_codigo CHECK (
                    CHAR_LENGTH(codigo) BETWEEN 1 AND 32
                    AND codigo REGEXP '^[A-Z0-9]+([_-][A-Z0-9]+)*$'
                ),
                CONSTRAINT chk_impuestos_tasa CHECK (
                    tasa >= 0 AND tasa <= 100
                ),
                CONSTRAINT chk_impuestos_tipo CHECK (
                    tipo IN ('IVA', 'IEPS', 'EXENTO')
                ),
                CONSTRAINT chk_impuestos_exento CHECK (
                    tipo <> 'EXENTO' OR tasa = 0
                ),
                CONSTRAINT chk_impuestos_activo CHECK (activo IN (0, 1)),
                CONSTRAINT fk_impuestos_creado_por
                    FOREIGN KEY (creado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_impuestos_actualizado_por
                    FOREIGN KEY (actualizado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_impuestos_eliminado_por
                    FOREIGN KEY (eliminado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE lineas_producto (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                codigo VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                nombre VARCHAR(150) NOT NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                creado_por BIGINT UNSIGNED NULL,
                actualizado_por BIGINT UNSIGNED NULL,
                eliminado_en DATETIME NULL,
                eliminado_por BIGINT UNSIGNED NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_lineas_producto_codigo (codigo),
                KEY idx_lineas_producto_activo (activo),
                KEY idx_lineas_producto_eliminado_en (eliminado_en),
                CONSTRAINT chk_lineas_producto_codigo CHECK (
                    CHAR_LENGTH(codigo) BETWEEN 1 AND 64
                    AND codigo REGEXP '^[A-Z0-9]+([_-][A-Z0-9]+)*$'
                ),
                CONSTRAINT chk_lineas_producto_activo CHECK (activo IN (0, 1)),
                CONSTRAINT fk_lineas_producto_creado_por
                    FOREIGN KEY (creado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_lineas_producto_actualizado_por
                    FOREIGN KEY (actualizado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_lineas_producto_eliminado_por
                    FOREIGN KEY (eliminado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE marcas (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                codigo VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                nombre VARCHAR(150) NOT NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                creado_por BIGINT UNSIGNED NULL,
                actualizado_por BIGINT UNSIGNED NULL,
                eliminado_en DATETIME NULL,
                eliminado_por BIGINT UNSIGNED NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_marcas_codigo (codigo),
                KEY idx_marcas_activo (activo),
                KEY idx_marcas_eliminado_en (eliminado_en),
                CONSTRAINT chk_marcas_codigo CHECK (
                    CHAR_LENGTH(codigo) BETWEEN 1 AND 64
                    AND codigo REGEXP '^[A-Z0-9]+([_-][A-Z0-9]+)*$'
                ),
                CONSTRAINT chk_marcas_activo CHECK (activo IN (0, 1)),
                CONSTRAINT fk_marcas_creado_por
                    FOREIGN KEY (creado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_marcas_actualizado_por
                    FOREIGN KEY (actualizado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_marcas_eliminado_por
                    FOREIGN KEY (eliminado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE clasificaciones_producto (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                parent_id BIGINT UNSIGNED NULL,
                codigo VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                nombre VARCHAR(150) NOT NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                creado_por BIGINT UNSIGNED NULL,
                actualizado_por BIGINT UNSIGNED NULL,
                eliminado_en DATETIME NULL,
                eliminado_por BIGINT UNSIGNED NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_clasificaciones_producto_codigo (codigo),
                KEY idx_clasificaciones_producto_parent (parent_id),
                KEY idx_clasificaciones_producto_activo (activo),
                KEY idx_clasificaciones_producto_eliminado_en (eliminado_en),
                CONSTRAINT chk_clasificaciones_producto_codigo CHECK (
                    CHAR_LENGTH(codigo) BETWEEN 1 AND 64
                    AND codigo REGEXP '^[A-Z0-9]+([_-][A-Z0-9]+)*$'
                ),
                CONSTRAINT chk_clasificaciones_producto_activo CHECK (
                    activo IN (0, 1)
                ),
                CONSTRAINT fk_clasificaciones_producto_parent
                    FOREIGN KEY (parent_id) REFERENCES clasificaciones_producto (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_clasificaciones_producto_creado_por
                    FOREIGN KEY (creado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_clasificaciones_producto_actualizado_por
                    FOREIGN KEY (actualizado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_clasificaciones_producto_eliminado_por
                    FOREIGN KEY (eliminado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        ];
    }
};
