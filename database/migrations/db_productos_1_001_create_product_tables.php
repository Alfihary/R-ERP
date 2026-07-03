<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

return new class implements Migration {
    public function id(): string
    {
        return 'db_productos_1_001_create_product_tables';
    }

    public function up(PDO $pdo): void
    {
        $prerequisite = $pdo->prepare(
            'SELECT COUNT(*)
             FROM schema_migrations
             WHERE migration = :migration'
        );
        $prerequisite->execute([
            'migration' => 'db_catalogos_1_001_create_base_catalog_tables',
        ]);

        if ((int) $prerequisite->fetchColumn() !== 1) {
            throw new RuntimeException(
                'DB-PRODUCTOS-1 requires DB-CATALOGOS-1.'
            );
        }

        $existingTables = (int) $pdo->query(
            <<<'SQL'
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name IN (
                  'productos',
                  'producto_codigos_barras',
                  'producto_impuestos',
                  'producto_documentos'
              )
            SQL
        )->fetchColumn();

        if ($existingTables !== 0) {
            throw new RuntimeException(
                'DB-PRODUCTOS-1 requires its four tables to be absent.'
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
            'DROP TABLE IF EXISTS producto_documentos',
            'DROP TABLE IF EXISTS producto_impuestos',
            'DROP TABLE IF EXISTS producto_codigos_barras',
            'DROP TABLE IF EXISTS productos',
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
            CREATE TABLE productos (
                id_producto VARCHAR(16)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL,
                descripcion VARCHAR(40) NOT NULL,
                descripcion_larga VARCHAR(255) NULL,
                unidad_medida_id BIGINT UNSIGNED NOT NULL,
                moneda_id BIGINT UNSIGNED NULL,
                linea_producto_id BIGINT UNSIGNED NULL,
                marca_id BIGINT UNSIGNED NULL,
                clasificacion_producto_id BIGINT UNSIGNED NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NULL DEFAULT NULL
                    ON UPDATE CURRENT_TIMESTAMP,
                creado_por BIGINT UNSIGNED NULL,
                actualizado_por BIGINT UNSIGNED NULL,
                eliminado_en DATETIME NULL,
                eliminado_por BIGINT UNSIGNED NULL,
                PRIMARY KEY (id_producto),
                KEY idx_productos_unidad (unidad_medida_id),
                KEY idx_productos_moneda (moneda_id),
                KEY idx_productos_linea (linea_producto_id),
                KEY idx_productos_marca (marca_id),
                KEY idx_productos_clasificacion (
                    clasificacion_producto_id
                ),
                KEY idx_productos_activo (activo),
                KEY idx_productos_eliminado_en (eliminado_en),
                CONSTRAINT chk_productos_id_producto CHECK (
                    REGEXP_LIKE(
                        id_producto,
                        '^[A-Z0-9]{1,16}$',
                        'c'
                    )
                ),
                CONSTRAINT chk_productos_descripcion CHECK (
                    CHAR_LENGTH(descripcion) BETWEEN 1 AND 40
                ),
                CONSTRAINT chk_productos_activo CHECK (activo IN (0, 1)),
                CONSTRAINT fk_productos_unidad
                    FOREIGN KEY (unidad_medida_id)
                    REFERENCES unidades_medida (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_productos_moneda
                    FOREIGN KEY (moneda_id) REFERENCES monedas (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_productos_linea
                    FOREIGN KEY (linea_producto_id)
                    REFERENCES lineas_producto (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_productos_marca
                    FOREIGN KEY (marca_id) REFERENCES marcas (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_productos_clasificacion
                    FOREIGN KEY (clasificacion_producto_id)
                    REFERENCES clasificaciones_producto (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_productos_creado_por
                    FOREIGN KEY (creado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_productos_actualizado_por
                    FOREIGN KEY (actualizado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_productos_eliminado_por
                    FOREIGN KEY (eliminado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE producto_codigos_barras (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                id_producto VARCHAR(16)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL,
                codigo_barras VARCHAR(64)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL,
                tipo VARCHAR(32)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NULL,
                es_principal TINYINT(1) NOT NULL DEFAULT 0,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NULL DEFAULT NULL
                    ON UPDATE CURRENT_TIMESTAMP,
                creado_por BIGINT UNSIGNED NULL,
                actualizado_por BIGINT UNSIGNED NULL,
                eliminado_en DATETIME NULL,
                eliminado_por BIGINT UNSIGNED NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_producto_codigos_barras_codigo (
                    codigo_barras
                ),
                KEY idx_producto_codigos_barras_producto (id_producto),
                KEY idx_producto_codigos_barras_activo (activo),
                CONSTRAINT chk_producto_codigos_barras_codigo CHECK (
                    REGEXP_LIKE(
                        codigo_barras,
                        '^[A-Z0-9]{1,64}$',
                        'c'
                    )
                ),
                CONSTRAINT chk_producto_codigos_barras_principal CHECK (
                    es_principal IN (0, 1)
                ),
                CONSTRAINT chk_producto_codigos_barras_activo CHECK (
                    activo IN (0, 1)
                ),
                CONSTRAINT fk_producto_codigos_barras_producto
                    FOREIGN KEY (id_producto)
                    REFERENCES productos (id_producto)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_producto_codigos_barras_creado_por
                    FOREIGN KEY (creado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_producto_codigos_barras_actualizado_por
                    FOREIGN KEY (actualizado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_producto_codigos_barras_eliminado_por
                    FOREIGN KEY (eliminado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE producto_impuestos (
                id_producto VARCHAR(16)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL,
                impuesto_id BIGINT UNSIGNED NOT NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NULL DEFAULT NULL
                    ON UPDATE CURRENT_TIMESTAMP,
                creado_por BIGINT UNSIGNED NULL,
                actualizado_por BIGINT UNSIGNED NULL,
                eliminado_en DATETIME NULL,
                eliminado_por BIGINT UNSIGNED NULL,
                PRIMARY KEY (id_producto, impuesto_id),
                KEY idx_producto_impuestos_impuesto (impuesto_id),
                KEY idx_producto_impuestos_activo (activo),
                CONSTRAINT chk_producto_impuestos_activo CHECK (
                    activo IN (0, 1)
                ),
                CONSTRAINT fk_producto_impuestos_producto
                    FOREIGN KEY (id_producto)
                    REFERENCES productos (id_producto)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_producto_impuestos_impuesto
                    FOREIGN KEY (impuesto_id) REFERENCES impuestos (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_producto_impuestos_creado_por
                    FOREIGN KEY (creado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_producto_impuestos_actualizado_por
                    FOREIGN KEY (actualizado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_producto_impuestos_eliminado_por
                    FOREIGN KEY (eliminado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE producto_documentos (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                id_producto VARCHAR(16)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL,
                tipo_documento VARCHAR(32)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL,
                nombre_original VARCHAR(255) NOT NULL,
                ruta_relativa VARCHAR(500) NOT NULL,
                mime_type VARCHAR(100)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL,
                tamano_bytes BIGINT UNSIGNED NOT NULL,
                es_principal TINYINT(1) NOT NULL DEFAULT 0,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NULL DEFAULT NULL
                    ON UPDATE CURRENT_TIMESTAMP,
                creado_por BIGINT UNSIGNED NULL,
                actualizado_por BIGINT UNSIGNED NULL,
                eliminado_en DATETIME NULL,
                eliminado_por BIGINT UNSIGNED NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_producto_documentos_ruta (ruta_relativa),
                KEY idx_producto_documentos_producto (id_producto),
                KEY idx_producto_documentos_tipo (tipo_documento),
                KEY idx_producto_documentos_activo (activo),
                CONSTRAINT chk_producto_documentos_tipo CHECK (
                    REGEXP_LIKE(
                        tipo_documento,
                        '^[A-Z0-9]{1,32}$',
                        'c'
                    )
                ),
                CONSTRAINT chk_producto_documentos_nombre CHECK (
                    CHAR_LENGTH(nombre_original) BETWEEN 1 AND 255
                ),
                CONSTRAINT chk_producto_documentos_ruta CHECK (
                    CHAR_LENGTH(ruta_relativa) BETWEEN 1 AND 500
                ),
                CONSTRAINT chk_producto_documentos_mime CHECK (
                    CHAR_LENGTH(mime_type) BETWEEN 3 AND 100
                ),
                CONSTRAINT chk_producto_documentos_tamano CHECK (
                    tamano_bytes > 0
                ),
                CONSTRAINT chk_producto_documentos_principal CHECK (
                    es_principal IN (0, 1)
                ),
                CONSTRAINT chk_producto_documentos_activo CHECK (
                    activo IN (0, 1)
                ),
                CONSTRAINT fk_producto_documentos_producto
                    FOREIGN KEY (id_producto)
                    REFERENCES productos (id_producto)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_producto_documentos_creado_por
                    FOREIGN KEY (creado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_producto_documentos_actualizado_por
                    FOREIGN KEY (actualizado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_producto_documentos_eliminado_por
                    FOREIGN KEY (eliminado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
            SQL,
        ];
    }
};
