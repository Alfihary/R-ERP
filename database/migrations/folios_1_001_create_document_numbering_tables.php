<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

return new class implements Migration {
    public function id(): string
    {
        return 'folios_1_001_create_document_numbering_tables';
    }

    public function up(PDO $pdo): void
    {
        foreach ([
            'db_core_0_001_create_core_identity_tables',
            'db_scope_1_001_create_scope_tables',
            'config_operacion_1_001_extend_companies_warehouses',
        ] as $migration) {
            $statement = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM schema_migrations
                 WHERE migration = :migration'
            );
            $statement->execute(['migration' => $migration]);

            if ((int) $statement->fetchColumn() !== 1) {
                throw new RuntimeException(
                    'FOLIOS-DB-1 requires ' . $migration . '.'
                );
            }
        }

        $existingTables = (int) $pdo->query(
            <<<'SQL'
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name IN (
                  'series_documentales',
                  'documentos_folios'
              )
            SQL
        )->fetchColumn();

        if ($existingTables !== 0) {
            throw new RuntimeException(
                'FOLIOS-DB-1 requires its tables to be absent.'
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
            'DROP TABLE IF EXISTS documentos_folios',
            'DROP TABLE IF EXISTS series_documentales',
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
            CREATE TABLE series_documentales (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                empresa_id BIGINT UNSIGNED NOT NULL,
                almacen_id BIGINT UNSIGNED NOT NULL,
                tipo_documento VARCHAR(60)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL,
                codigo_serie VARCHAR(20)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL,
                prefijo VARCHAR(20)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL,
                codigo_almacen_snapshot VARCHAR(50)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL,
                formato VARCHAR(80)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL DEFAULT '{PREFIJO}-{ALMACEN}{NUMERO}',
                separador VARCHAR(5)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL DEFAULT '-',
                siguiente_numero BIGINT UNSIGNED NOT NULL DEFAULT 1,
                longitud TINYINT UNSIGNED NOT NULL DEFAULT 6,
                reinicio_anual TINYINT(1) NOT NULL DEFAULT 0,
                anio_actual SMALLINT UNSIGNED NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                creado_por BIGINT UNSIGNED NULL,
                actualizado_por BIGINT UNSIGNED NULL,
                eliminado_en DATETIME NULL,
                eliminado_por BIGINT UNSIGNED NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_series_documentales_scope (
                    empresa_id,
                    almacen_id,
                    tipo_documento,
                    codigo_serie
                ),
                KEY idx_series_documentales_empresa (empresa_id),
                KEY idx_series_documentales_almacen (almacen_id),
                KEY idx_series_documentales_tipo_documento (tipo_documento),
                KEY idx_series_documentales_codigo_serie (codigo_serie),
                KEY idx_series_documentales_prefijo (prefijo),
                KEY idx_series_documentales_almacen_snapshot (
                    codigo_almacen_snapshot
                ),
                KEY idx_series_documentales_activo (activo),
                KEY idx_series_documentales_eliminado_en (eliminado_en),
                CONSTRAINT chk_series_documentales_tipo_documento CHECK (
                    CHAR_LENGTH(tipo_documento) > 0
                ),
                CONSTRAINT chk_series_documentales_codigo_serie CHECK (
                    CHAR_LENGTH(codigo_serie) > 0
                ),
                CONSTRAINT chk_series_documentales_prefijo CHECK (
                    CHAR_LENGTH(prefijo) > 0
                ),
                CONSTRAINT chk_series_documentales_almacen_snapshot CHECK (
                    CHAR_LENGTH(codigo_almacen_snapshot) > 0
                ),
                CONSTRAINT chk_series_documentales_formato CHECK (
                    CHAR_LENGTH(formato) > 0
                ),
                CONSTRAINT chk_series_documentales_siguiente_numero CHECK (
                    siguiente_numero >= 1
                ),
                CONSTRAINT chk_series_documentales_longitud CHECK (
                    longitud BETWEEN 1 AND 12
                ),
                CONSTRAINT chk_series_documentales_reinicio_anual CHECK (
                    reinicio_anual IN (0, 1)
                ),
                CONSTRAINT chk_series_documentales_activo CHECK (
                    activo IN (0, 1)
                ),
                CONSTRAINT fk_series_documentales_empresa
                    FOREIGN KEY (empresa_id)
                    REFERENCES empresas (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_series_documentales_almacen
                    FOREIGN KEY (almacen_id)
                    REFERENCES almacenes (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_series_documentales_empresa_almacen
                    FOREIGN KEY (empresa_id, almacen_id)
                    REFERENCES almacenes (empresa_id, id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_series_documentales_creado_por
                    FOREIGN KEY (creado_por)
                    REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_series_documentales_actualizado_por
                    FOREIGN KEY (actualizado_por)
                    REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_series_documentales_eliminado_por
                    FOREIGN KEY (eliminado_por)
                    REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE documentos_folios (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                serie_documental_id BIGINT UNSIGNED NOT NULL,
                empresa_id BIGINT UNSIGNED NOT NULL,
                almacen_id BIGINT UNSIGNED NOT NULL,
                tipo_documento VARCHAR(60)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL,
                codigo_serie VARCHAR(20)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL,
                prefijo_documento VARCHAR(20)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL,
                codigo_almacen_snapshot VARCHAR(50)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL,
                formato VARCHAR(80)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL,
                folio VARCHAR(100)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL,
                numero BIGINT UNSIGNED NOT NULL,
                anio SMALLINT UNSIGNED NULL,
                anio_scope SMALLINT UNSIGNED
                    GENERATED ALWAYS AS (COALESCE(anio, 0)) STORED,
                documento_tipo_origen VARCHAR(80)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NULL,
                documento_id_origen BIGINT UNSIGNED NULL,
                referencia_externa VARCHAR(100) NULL,
                creado_por_usuario_id BIGINT UNSIGNED NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_documentos_folios_serie_numero (
                    serie_documental_id,
                    anio_scope,
                    numero
                ),
                UNIQUE KEY uq_documentos_folios_scope_folio (
                    empresa_id,
                    almacen_id,
                    tipo_documento,
                    folio
                ),
                KEY idx_documentos_folios_empresa (empresa_id),
                KEY idx_documentos_folios_almacen (almacen_id),
                KEY idx_documentos_folios_tipo_documento (tipo_documento),
                KEY idx_documentos_folios_codigo_serie (codigo_serie),
                KEY idx_documentos_folios_prefijo_documento (
                    prefijo_documento
                ),
                KEY idx_documentos_folios_almacen_snapshot (
                    codigo_almacen_snapshot
                ),
                KEY idx_documentos_folios_folio (folio),
                KEY idx_documentos_folios_documento_origen (
                    documento_tipo_origen,
                    documento_id_origen
                ),
                KEY idx_documentos_folios_creado_en (creado_en),
                CONSTRAINT chk_documentos_folios_tipo_documento CHECK (
                    CHAR_LENGTH(tipo_documento) > 0
                ),
                CONSTRAINT chk_documentos_folios_codigo_serie CHECK (
                    CHAR_LENGTH(codigo_serie) > 0
                ),
                CONSTRAINT chk_documentos_folios_prefijo_documento CHECK (
                    CHAR_LENGTH(prefijo_documento) > 0
                ),
                CONSTRAINT chk_documentos_folios_almacen_snapshot CHECK (
                    CHAR_LENGTH(codigo_almacen_snapshot) > 0
                ),
                CONSTRAINT chk_documentos_folios_formato CHECK (
                    CHAR_LENGTH(formato) > 0
                ),
                CONSTRAINT chk_documentos_folios_folio CHECK (
                    CHAR_LENGTH(folio) > 0
                ),
                CONSTRAINT chk_documentos_folios_numero CHECK (
                    numero >= 1
                ),
                CONSTRAINT fk_documentos_folios_serie
                    FOREIGN KEY (serie_documental_id)
                    REFERENCES series_documentales (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_documentos_folios_empresa
                    FOREIGN KEY (empresa_id)
                    REFERENCES empresas (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_documentos_folios_almacen
                    FOREIGN KEY (almacen_id)
                    REFERENCES almacenes (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_documentos_folios_empresa_almacen
                    FOREIGN KEY (empresa_id, almacen_id)
                    REFERENCES almacenes (empresa_id, id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_documentos_folios_creado_por_usuario
                    FOREIGN KEY (creado_por_usuario_id)
                    REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
            SQL,
        ];
    }
};
