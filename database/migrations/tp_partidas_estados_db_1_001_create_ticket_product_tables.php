<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

return new class implements Migration {
    public function id(): string
    {
        return 'tp_partidas_estados_db_1_001_create_ticket_product_tables';
    }

    public function up(PDO $pdo): void
    {
        foreach ([
            'db_core_0_001_create_core_identity_tables',
            'db_scope_1_001_create_scope_tables',
            'db_catalogos_1_001_create_base_catalog_tables',
            'db_sat_1_001_create_sat_catalogs',
            'folios_1_001_create_document_numbering_tables',
        ] as $migration) {
            $statement = $pdo->prepare(
                'SELECT COUNT(*) FROM schema_migrations WHERE migration = :migration'
            );
            $statement->execute(['migration' => $migration]);

            if ((int) $statement->fetchColumn() !== 1) {
                throw new RuntimeException('TP-PARTIDAS-ESTADOS-DB-1 requires ' . $migration . '.');
            }
        }

        $existingTables = (int) $pdo->query(
            <<<'SQL'
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name IN (
                  'tickets_productos',
                  'tickets_productos_partidas',
                  'tickets_productos_adjuntos',
                  'tickets_productos_comentarios',
                  'tickets_productos_eventos'
              )
            SQL
        )->fetchColumn();

        if ($existingTables !== 0) {
            throw new RuntimeException('TP-PARTIDAS-ESTADOS-DB-1 requires its tables to be absent.');
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
            'DROP TABLE IF EXISTS tickets_productos_eventos',
            'DROP TABLE IF EXISTS tickets_productos_comentarios',
            'DROP TABLE IF EXISTS tickets_productos_adjuntos',
            'DROP TABLE IF EXISTS tickets_productos_partidas',
            'DROP TABLE IF EXISTS tickets_productos',
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
            CREATE TABLE tickets_productos (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                folio VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                empresa_id BIGINT UNSIGNED NOT NULL,
                almacen_id BIGINT UNSIGNED NOT NULL,
                solicitante_usuario_id BIGINT UNSIGNED NOT NULL,
                estado VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'EN_REVISION',
                observaciones_generales TEXT NULL,
                total_partidas INT UNSIGNED NOT NULL DEFAULT 0,
                partidas_en_revision INT UNSIGNED NOT NULL DEFAULT 0,
                partidas_aprobadas INT UNSIGNED NOT NULL DEFAULT 0,
                partidas_rechazadas INT UNSIGNED NOT NULL DEFAULT 0,
                cancelado_por_usuario_id BIGINT UNSIGNED NULL,
                cancelado_at DATETIME NULL,
                motivo_cancelacion TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_tickets_productos_folio (folio),
                KEY idx_tickets_productos_empresa (empresa_id),
                KEY idx_tickets_productos_almacen (almacen_id),
                KEY idx_tickets_productos_solicitante (solicitante_usuario_id),
                KEY idx_tickets_productos_estado (estado),
                KEY idx_tickets_productos_created_at (created_at),
                KEY idx_tickets_productos_deleted_at (deleted_at),
                CONSTRAINT chk_tickets_productos_folio CHECK (
                    CHAR_LENGTH(folio) BETWEEN 9 AND 30
                    AND folio = UPPER(folio)
                    AND folio REGEXP '^[A-Z0-9]+-[0-9]{6}$'
                ),
                CONSTRAINT chk_tickets_productos_estado CHECK (
                    estado IN (
                        'EN_REVISION',
                        'RESUELTO_PARCIAL',
                        'APROBADO',
                        'RECHAZADO',
                        'CANCELADO'
                    )
                ),
                CONSTRAINT chk_tickets_productos_contadores CHECK (
                    total_partidas >= 0
                    AND partidas_en_revision >= 0
                    AND partidas_aprobadas >= 0
                    AND partidas_rechazadas >= 0
                    AND total_partidas >= (
                        partidas_en_revision
                        + partidas_aprobadas
                        + partidas_rechazadas
                    )
                ),
                CONSTRAINT chk_tickets_productos_cancelacion CHECK (
                    (estado = 'CANCELADO' AND cancelado_at IS NOT NULL)
                    OR (estado <> 'CANCELADO')
                ),
                CONSTRAINT fk_tickets_productos_empresa
                    FOREIGN KEY (empresa_id) REFERENCES empresas (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_tickets_productos_almacen
                    FOREIGN KEY (almacen_id) REFERENCES almacenes (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_tickets_productos_empresa_almacen
                    FOREIGN KEY (empresa_id, almacen_id) REFERENCES almacenes (empresa_id, id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_tickets_productos_solicitante
                    FOREIGN KEY (solicitante_usuario_id) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_tickets_productos_cancelado_por
                    FOREIGN KEY (cancelado_por_usuario_id) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE tickets_productos_partidas (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                ticket_producto_id BIGINT UNSIGNED NOT NULL,
                numero_partida INT UNSIGNED NOT NULL,
                estado VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'EN_REVISION',
                modelo VARCHAR(120) NULL,
                marca_texto VARCHAR(120) NULL,
                descripcion TEXT NOT NULL,
                proveedor_id BIGINT UNSIGNED NULL,
                proveedor_texto VARCHAR(180) NULL,
                unidad_sat_id BIGINT UNSIGNED NULL,
                clave_sat_id BIGINT UNSIGNED NULL,
                moneda_id BIGINT UNSIGNED NULL,
                costo_sugerido DECIMAL(18,6) NULL,
                peso DECIMAL(18,6) NULL,
                lleva_serie TINYINT(1) NOT NULL DEFAULT 0,
                observaciones TEXT NULL,
                resuelto_por_usuario_id BIGINT UNSIGNED NULL,
                resuelto_at DATETIME NULL,
                comentario_resolucion TEXT NULL,
                motivo_rechazo TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_tickets_productos_partidas_numero (ticket_producto_id, numero_partida),
                KEY idx_tickets_productos_partidas_ticket (ticket_producto_id),
                KEY idx_tickets_productos_partidas_estado (estado),
                KEY idx_tickets_productos_partidas_proveedor_id (proveedor_id),
                KEY idx_tickets_productos_partidas_unidad_sat (unidad_sat_id),
                KEY idx_tickets_productos_partidas_clave_sat (clave_sat_id),
                KEY idx_tickets_productos_partidas_moneda (moneda_id),
                KEY idx_tickets_productos_partidas_resuelto_por (resuelto_por_usuario_id),
                KEY idx_tickets_productos_partidas_deleted_at (deleted_at),
                CONSTRAINT chk_tickets_productos_partidas_numero CHECK (numero_partida >= 1),
                CONSTRAINT chk_tickets_productos_partidas_estado CHECK (
                    estado IN ('EN_REVISION', 'APROBADA', 'RECHAZADA')
                ),
                CONSTRAINT chk_tickets_productos_partidas_descripcion CHECK (
                    CHAR_LENGTH(TRIM(descripcion)) > 0
                ),
                CONSTRAINT chk_tickets_productos_partidas_costo CHECK (
                    costo_sugerido IS NULL OR costo_sugerido >= 0
                ),
                CONSTRAINT chk_tickets_productos_partidas_peso CHECK (
                    peso IS NULL OR peso >= 0
                ),
                CONSTRAINT chk_tickets_productos_partidas_lleva_serie CHECK (
                    lleva_serie IN (0, 1)
                ),
                CONSTRAINT chk_tickets_productos_partidas_resolucion CHECK (
                    estado = 'EN_REVISION'
                    OR (resuelto_por_usuario_id IS NOT NULL AND resuelto_at IS NOT NULL)
                ),
                CONSTRAINT chk_tickets_productos_partidas_rechazo CHECK (
                    estado <> 'RECHAZADA'
                    OR (motivo_rechazo IS NOT NULL AND CHAR_LENGTH(TRIM(motivo_rechazo)) > 0)
                ),
                CONSTRAINT fk_tickets_productos_partidas_ticket
                    FOREIGN KEY (ticket_producto_id) REFERENCES tickets_productos (id)
                    ON UPDATE RESTRICT ON DELETE CASCADE,
                CONSTRAINT fk_tickets_productos_partidas_unidad_sat
                    FOREIGN KEY (unidad_sat_id) REFERENCES unidades_sat (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_tickets_productos_partidas_clave_sat
                    FOREIGN KEY (clave_sat_id) REFERENCES claves_sat (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_tickets_productos_partidas_moneda
                    FOREIGN KEY (moneda_id) REFERENCES monedas (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_tickets_productos_partidas_resuelto_por
                    FOREIGN KEY (resuelto_por_usuario_id) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE tickets_productos_adjuntos (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                ticket_producto_id BIGINT UNSIGNED NOT NULL,
                partida_id BIGINT UNSIGNED NULL,
                subido_por_usuario_id BIGINT UNSIGNED NOT NULL,
                nombre_original VARCHAR(255) NOT NULL,
                nombre_guardado VARCHAR(255) NOT NULL,
                ruta_relativa VARCHAR(500) NOT NULL,
                mime VARCHAR(120) NOT NULL,
                extension VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                tamano_bytes BIGINT UNSIGNED NOT NULL,
                hash_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                PRIMARY KEY (id),
                KEY idx_tickets_productos_adjuntos_ticket (ticket_producto_id),
                KEY idx_tickets_productos_adjuntos_partida (partida_id),
                KEY idx_tickets_productos_adjuntos_subido_por (subido_por_usuario_id),
                KEY idx_tickets_productos_adjuntos_mime (mime),
                KEY idx_tickets_productos_adjuntos_created_at (created_at),
                CONSTRAINT chk_tickets_productos_adjuntos_ruta CHECK (
                    CHAR_LENGTH(TRIM(ruta_relativa)) > 0
                    AND ruta_relativa NOT LIKE '/%'
                    AND ruta_relativa NOT REGEXP '^[A-Za-z]:[\\\\/]'
                    AND ruta_relativa NOT LIKE '%..%'
                ),
                CONSTRAINT chk_tickets_productos_adjuntos_extension CHECK (
                    extension REGEXP '^[a-z0-9]{1,20}$'
                ),
                CONSTRAINT chk_tickets_productos_adjuntos_tamano CHECK (tamano_bytes > 0),
                CONSTRAINT chk_tickets_productos_adjuntos_hash CHECK (
                    hash_sha256 IS NULL OR hash_sha256 REGEXP '^[a-f0-9]{64}$'
                ),
                CONSTRAINT fk_tickets_productos_adjuntos_ticket
                    FOREIGN KEY (ticket_producto_id) REFERENCES tickets_productos (id)
                    ON UPDATE RESTRICT ON DELETE CASCADE,
                CONSTRAINT fk_tickets_productos_adjuntos_partida
                    FOREIGN KEY (partida_id) REFERENCES tickets_productos_partidas (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_tickets_productos_adjuntos_subido_por
                    FOREIGN KEY (subido_por_usuario_id) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE tickets_productos_comentarios (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                ticket_producto_id BIGINT UNSIGNED NOT NULL,
                partida_id BIGINT UNSIGNED NULL,
                usuario_id BIGINT UNSIGNED NOT NULL,
                comentario TEXT NOT NULL,
                visibilidad VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'INTERNA',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                PRIMARY KEY (id),
                KEY idx_tickets_productos_comentarios_ticket (ticket_producto_id),
                KEY idx_tickets_productos_comentarios_partida (partida_id),
                KEY idx_tickets_productos_comentarios_usuario (usuario_id),
                KEY idx_tickets_productos_comentarios_visibilidad (visibilidad),
                KEY idx_tickets_productos_comentarios_created_at (created_at),
                CONSTRAINT chk_tickets_productos_comentarios_comentario CHECK (
                    CHAR_LENGTH(TRIM(comentario)) > 0
                ),
                CONSTRAINT chk_tickets_productos_comentarios_visibilidad CHECK (
                    visibilidad IN ('INTERNA', 'SOLICITANTE')
                ),
                CONSTRAINT fk_tickets_productos_comentarios_ticket
                    FOREIGN KEY (ticket_producto_id) REFERENCES tickets_productos (id)
                    ON UPDATE RESTRICT ON DELETE CASCADE,
                CONSTRAINT fk_tickets_productos_comentarios_partida
                    FOREIGN KEY (partida_id) REFERENCES tickets_productos_partidas (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_tickets_productos_comentarios_usuario
                    FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE tickets_productos_eventos (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                ticket_producto_id BIGINT UNSIGNED NOT NULL,
                partida_id BIGINT UNSIGNED NULL,
                usuario_id BIGINT UNSIGNED NULL,
                evento VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                descripcion TEXT NULL,
                metadata_json JSON NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_tickets_productos_eventos_ticket (ticket_producto_id),
                KEY idx_tickets_productos_eventos_partida (partida_id),
                KEY idx_tickets_productos_eventos_usuario (usuario_id),
                KEY idx_tickets_productos_eventos_evento (evento),
                KEY idx_tickets_productos_eventos_created_at (created_at),
                CONSTRAINT chk_tickets_productos_eventos_evento CHECK (
                    evento IN (
                        'TICKET_CREADO',
                        'PARTIDA_AGREGADA',
                        'PARTIDA_APROBADA',
                        'PARTIDA_RECHAZADA',
                        'TICKET_CANCELADO',
                        'ADJUNTO_CARGADO',
                        'COMENTARIO_AGREGADO',
                        'CORREO_ENVIADO',
                        'CORREO_FALLIDO'
                    )
                ),
                CONSTRAINT fk_tickets_productos_eventos_ticket
                    FOREIGN KEY (ticket_producto_id) REFERENCES tickets_productos (id)
                    ON UPDATE RESTRICT ON DELETE CASCADE,
                CONSTRAINT fk_tickets_productos_eventos_partida
                    FOREIGN KEY (partida_id) REFERENCES tickets_productos_partidas (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_tickets_productos_eventos_usuario
                    FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        ];
    }
};
