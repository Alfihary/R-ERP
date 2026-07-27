<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

return new class implements Migration {
    public function id(): string
    {
        return 'precios_1_001_create_price_tables';
    }

    public function up(PDO $pdo): void
    {
        foreach ([
            'db_core_0_001_create_core_identity_tables',
            'db_scope_1_001_create_scope_tables',
            'db_productos_1_001_create_product_tables',
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
                    'PRECIOS-DB-1 requires ' . $migration . '.'
                );
            }
        }

        $existingTables = (int) $pdo->query(
            <<<'SQL'
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name IN (
                  'listas_precios',
                  'producto_precios',
                  'producto_precios_historial',
                  'autorizaciones_precio'
              )
            SQL
        )->fetchColumn();

        if ($existingTables !== 0) {
            throw new RuntimeException(
                'PRECIOS-DB-1 requires its tables to be absent.'
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
            'DROP TABLE IF EXISTS autorizaciones_precio',
            'DROP TABLE IF EXISTS producto_precios_historial',
            'DROP TABLE IF EXISTS producto_precios',
            'DROP TABLE IF EXISTS listas_precios',
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
            CREATE TABLE listas_precios (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                clave VARCHAR(32)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL,
                nombre VARCHAR(100) NOT NULL,
                observaciones VARCHAR(500) NULL,
                incluye_impuestos TINYINT(1) NOT NULL DEFAULT 0,
                es_predeterminada TINYINT(1) NOT NULL DEFAULT 0,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NULL,
                creado_por BIGINT UNSIGNED NULL,
                actualizado_por BIGINT UNSIGNED NULL,
                eliminado_en DATETIME NULL,
                eliminado_por BIGINT UNSIGNED NULL,
                predeterminada_unica TINYINT
                    GENERATED ALWAYS AS (
                        CASE
                            WHEN es_predeterminada = 1
                             AND activo = 1
                             AND eliminado_en IS NULL
                            THEN 1
                            ELSE NULL
                        END
                    ) STORED,
                PRIMARY KEY (id),
                UNIQUE KEY uq_listas_precios_clave (clave),
                UNIQUE KEY uq_listas_precios_predeterminada (
                    predeterminada_unica
                ),
                KEY idx_listas_precios_catalogo (
                    activo,
                    eliminado_en,
                    nombre
                ),
                KEY idx_listas_precios_eliminado (eliminado_en),
                CONSTRAINT chk_listas_precios_clave CHECK (
                    CHAR_LENGTH(clave) BETWEEN 2 AND 32
                    AND clave = UPPER(clave)
                    AND REGEXP_LIKE(
                        clave,
                        '^[A-Z0-9]+([._-][A-Z0-9]+)*$',
                        'c'
                    )
                ),
                CONSTRAINT chk_listas_precios_incluye_impuestos CHECK (
                    incluye_impuestos IN (0, 1)
                ),
                CONSTRAINT chk_listas_precios_predeterminada CHECK (
                    es_predeterminada IN (0, 1)
                ),
                CONSTRAINT chk_listas_precios_activo CHECK (
                    activo IN (0, 1)
                ),
                CONSTRAINT chk_listas_precios_predeterminada_activa CHECK (
                    es_predeterminada = 0
                    OR (activo = 1 AND eliminado_en IS NULL)
                ),
                CONSTRAINT chk_listas_precios_eliminado_usuario CHECK (
                    (eliminado_en IS NULL AND eliminado_por IS NULL)
                    OR (eliminado_en IS NOT NULL AND eliminado_por IS NOT NULL)
                ),
                CONSTRAINT fk_listas_precios_creado_por
                    FOREIGN KEY (creado_por)
                    REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_listas_precios_actualizado_por
                    FOREIGN KEY (actualizado_por)
                    REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_listas_precios_eliminado_por
                    FOREIGN KEY (eliminado_por)
                    REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE producto_precios (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                id_producto VARCHAR(16)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL,
                lista_precio_id BIGINT UNSIGNED NOT NULL,
                moneda_id BIGINT UNSIGNED NOT NULL,
                precio_lista DECIMAL(14,4) NOT NULL,
                precio_minimo DECIMAL(14,4) NOT NULL,
                incluye_impuestos TINYINT(1) NOT NULL DEFAULT 0,
                requiere_revision TINYINT(1) NOT NULL DEFAULT 0,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NULL,
                creado_por BIGINT UNSIGNED NULL,
                actualizado_por BIGINT UNSIGNED NULL,
                eliminado_en DATETIME NULL,
                eliminado_por BIGINT UNSIGNED NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_producto_precios_producto_lista (
                    id_producto,
                    lista_precio_id
                ),
                UNIQUE KEY uq_producto_precios_referencia (
                    id,
                    id_producto,
                    lista_precio_id,
                    moneda_id
                ),
                KEY idx_producto_precios_producto (id_producto),
                KEY idx_producto_precios_lista (lista_precio_id),
                KEY idx_producto_precios_moneda (moneda_id),
                KEY idx_producto_precios_estado (activo, eliminado_en),
                KEY idx_producto_precios_revision (
                    requiere_revision,
                    activo,
                    eliminado_en
                ),
                KEY idx_producto_precios_consulta (
                    id_producto,
                    lista_precio_id,
                    activo,
                    eliminado_en,
                    requiere_revision
                ),
                CONSTRAINT chk_producto_precios_precio_lista CHECK (
                    precio_lista >= 0
                ),
                CONSTRAINT chk_producto_precios_precio_minimo CHECK (
                    precio_minimo >= 0
                ),
                CONSTRAINT chk_producto_precios_minimo_lista CHECK (
                    precio_minimo <= precio_lista
                ),
                CONSTRAINT chk_producto_precios_incluye_impuestos CHECK (
                    incluye_impuestos IN (0, 1)
                ),
                CONSTRAINT chk_producto_precios_revision CHECK (
                    requiere_revision IN (0, 1)
                ),
                CONSTRAINT chk_producto_precios_activo CHECK (
                    activo IN (0, 1)
                ),
                CONSTRAINT chk_producto_precios_revision_cero CHECK (
                    requiere_revision = 0
                    OR (precio_lista = 0 AND precio_minimo = 0)
                ),
                CONSTRAINT chk_producto_precios_eliminado_usuario CHECK (
                    (eliminado_en IS NULL AND eliminado_por IS NULL)
                    OR (eliminado_en IS NOT NULL AND eliminado_por IS NOT NULL)
                ),
                CONSTRAINT fk_producto_precios_producto
                    FOREIGN KEY (id_producto)
                    REFERENCES productos (id_producto)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_producto_precios_lista
                    FOREIGN KEY (lista_precio_id)
                    REFERENCES listas_precios (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_producto_precios_moneda
                    FOREIGN KEY (moneda_id)
                    REFERENCES monedas (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_producto_precios_creado_por
                    FOREIGN KEY (creado_por)
                    REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_producto_precios_actualizado_por
                    FOREIGN KEY (actualizado_por)
                    REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_producto_precios_eliminado_por
                    FOREIGN KEY (eliminado_por)
                    REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE producto_precios_historial (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                producto_precio_id BIGINT UNSIGNED NOT NULL,
                id_producto VARCHAR(16)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL,
                lista_precio_id BIGINT UNSIGNED NOT NULL,
                moneda_id_anterior BIGINT UNSIGNED NULL,
                moneda_id_nueva BIGINT UNSIGNED NOT NULL,
                precio_lista_anterior DECIMAL(14,4) NULL,
                precio_minimo_anterior DECIMAL(14,4) NULL,
                incluye_impuestos_anterior TINYINT(1) NULL,
                requiere_revision_anterior TINYINT(1) NULL,
                activo_anterior TINYINT(1) NULL,
                precio_lista_nuevo DECIMAL(14,4) NOT NULL,
                precio_minimo_nuevo DECIMAL(14,4) NOT NULL,
                incluye_impuestos_nuevo TINYINT(1) NOT NULL,
                requiere_revision_nuevo TINYINT(1) NOT NULL,
                activo_nuevo TINYINT(1) NOT NULL,
                tipo_cambio VARCHAR(32)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL DEFAULT 'ACTUALIZACION',
                motivo_cambio VARCHAR(500) NOT NULL,
                cambiado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                cambiado_por BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (id),
                KEY idx_producto_precios_historial_precio (
                    producto_precio_id,
                    cambiado_en
                ),
                KEY idx_producto_precios_historial_producto (
                    id_producto,
                    lista_precio_id,
                    cambiado_en
                ),
                KEY idx_producto_precios_historial_usuario (
                    cambiado_por,
                    cambiado_en
                ),
                KEY idx_producto_precios_historial_tipo (
                    tipo_cambio,
                    cambiado_en
                ),
                KEY idx_producto_precios_historial_moneda_anterior (
                    moneda_id_anterior
                ),
                KEY idx_producto_precios_historial_moneda_nueva (
                    moneda_id_nueva
                ),
                CONSTRAINT chk_producto_precios_historial_nuevo_lista CHECK (
                    precio_lista_nuevo >= 0
                ),
                CONSTRAINT chk_producto_precios_historial_nuevo_minimo CHECK (
                    precio_minimo_nuevo >= 0
                ),
                CONSTRAINT chk_producto_precios_historial_nuevo_minimo_lista CHECK (
                    precio_minimo_nuevo <= precio_lista_nuevo
                ),
                CONSTRAINT chk_producto_precios_historial_anterior_pares CHECK (
                    (
                        precio_lista_anterior IS NULL
                        AND precio_minimo_anterior IS NULL
                    )
                    OR (
                        precio_lista_anterior IS NOT NULL
                        AND precio_minimo_anterior IS NOT NULL
                    )
                ),
                CONSTRAINT chk_producto_precios_historial_anterior_valores CHECK (
                    precio_lista_anterior IS NULL
                    OR (
                        precio_lista_anterior >= 0
                        AND precio_minimo_anterior >= 0
                        AND precio_minimo_anterior <= precio_lista_anterior
                    )
                ),
                CONSTRAINT chk_producto_precios_historial_incluye_anterior CHECK (
                    incluye_impuestos_anterior IS NULL
                    OR incluye_impuestos_anterior IN (0, 1)
                ),
                CONSTRAINT chk_producto_precios_historial_incluye_nuevo CHECK (
                    incluye_impuestos_nuevo IN (0, 1)
                ),
                CONSTRAINT chk_producto_precios_historial_revision_anterior CHECK (
                    requiere_revision_anterior IS NULL
                    OR requiere_revision_anterior IN (0, 1)
                ),
                CONSTRAINT chk_producto_precios_historial_revision_nuevo CHECK (
                    requiere_revision_nuevo IN (0, 1)
                ),
                CONSTRAINT chk_producto_precios_historial_activo_anterior CHECK (
                    activo_anterior IS NULL OR activo_anterior IN (0, 1)
                ),
                CONSTRAINT chk_producto_precios_historial_activo_nuevo CHECK (
                    activo_nuevo IN (0, 1)
                ),
                CONSTRAINT chk_producto_precios_historial_revision_cero CHECK (
                    requiere_revision_nuevo = 0
                    OR (precio_lista_nuevo = 0 AND precio_minimo_nuevo = 0)
                ),
                CONSTRAINT chk_producto_precios_historial_tipo CHECK (
                    tipo_cambio IN (
                        'CREACION',
                        'ACTUALIZACION',
                        'CAMBIO_MONEDA',
                        'DESACTIVACION',
                        'REACTIVACION',
                        'ELIMINACION_LOGICA'
                    )
                ),
                CONSTRAINT fk_producto_precios_historial_precio
                    FOREIGN KEY (producto_precio_id)
                    REFERENCES producto_precios (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_producto_precios_historial_producto
                    FOREIGN KEY (id_producto)
                    REFERENCES productos (id_producto)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_producto_precios_historial_lista
                    FOREIGN KEY (lista_precio_id)
                    REFERENCES listas_precios (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_producto_precios_historial_moneda_anterior
                    FOREIGN KEY (moneda_id_anterior)
                    REFERENCES monedas (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_producto_precios_historial_moneda_nueva
                    FOREIGN KEY (moneda_id_nueva)
                    REFERENCES monedas (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_producto_precios_historial_cambiado_por
                    FOREIGN KEY (cambiado_por)
                    REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE autorizaciones_precio (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                folio VARCHAR(32)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL,
                empresa_id BIGINT UNSIGNED NOT NULL,
                documento_tipo VARCHAR(32)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL,
                documento_id BIGINT UNSIGNED NOT NULL,
                documento_partida_id BIGINT UNSIGNED NOT NULL,
                documento_folio VARCHAR(40) NULL,
                producto_precio_id BIGINT UNSIGNED NOT NULL,
                id_producto VARCHAR(16)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL,
                lista_precio_id BIGINT UNSIGNED NOT NULL,
                moneda_id BIGINT UNSIGNED NOT NULL,
                precio_lista_referencia DECIMAL(14,4) NOT NULL,
                precio_minimo_referencia DECIMAL(14,4) NOT NULL,
                precio_solicitado DECIMAL(14,4) NOT NULL,
                cantidad DECIMAL(14,4) NOT NULL,
                incluye_impuestos TINYINT(1) NOT NULL DEFAULT 0,
                motivo_solicitud VARCHAR(1000) NOT NULL,
                estatus VARCHAR(16)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL DEFAULT 'PENDIENTE',
                solicitado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                solicitado_por BIGINT UNSIGNED NOT NULL,
                decidido_en DATETIME NULL,
                decidido_por BIGINT UNSIGNED NULL,
                comentario_decision VARCHAR(1000) NULL,
                vence_en DATETIME NULL,
                cancelado_en DATETIME NULL,
                cancelado_por BIGINT UNSIGNED NULL,
                motivo_cancelacion VARCHAR(1000) NULL,
                utilizado_en DATETIME NULL,
                utilizado_por BIGINT UNSIGNED NULL,
                actualizado_en DATETIME NULL,
                actualizado_por BIGINT UNSIGNED NULL,
                autorizacion_activa_unica TINYINT
                    GENERATED ALWAYS AS (
                        CASE
                            WHEN estatus IN ('PENDIENTE', 'APROBADA')
                            THEN 1
                            ELSE NULL
                        END
                    ) STORED,
                PRIMARY KEY (id),
                UNIQUE KEY uq_autorizaciones_precio_folio (folio),
                UNIQUE KEY uq_autorizaciones_precio_activa_partida (
                    documento_tipo,
                    documento_id,
                    documento_partida_id,
                    autorizacion_activa_unica
                ),
                KEY idx_autorizaciones_precio_bandeja (
                    empresa_id,
                    estatus,
                    solicitado_en
                ),
                KEY idx_autorizaciones_precio_documento (
                    documento_tipo,
                    documento_id
                ),
                KEY idx_autorizaciones_precio_partida (
                    documento_tipo,
                    documento_id,
                    documento_partida_id
                ),
                KEY idx_autorizaciones_precio_producto (
                    id_producto,
                    lista_precio_id,
                    solicitado_en
                ),
                KEY idx_autorizaciones_precio_solicitante (
                    solicitado_por,
                    estatus,
                    solicitado_en
                ),
                KEY idx_autorizaciones_precio_decisor (
                    decidido_por,
                    estatus,
                    decidido_en
                ),
                KEY idx_autorizaciones_precio_utilizacion (
                    utilizado_por,
                    utilizado_en
                ),
                CONSTRAINT chk_autorizaciones_precio_folio CHECK (
                    CHAR_LENGTH(folio) BETWEEN 5 AND 32
                    AND folio = UPPER(folio)
                    AND REGEXP_LIKE(
                        folio,
                        '^[A-Z0-9]+([._-][A-Z0-9]+)*$',
                        'c'
                    )
                ),
                CONSTRAINT chk_autorizaciones_precio_documento_tipo CHECK (
                    CHAR_LENGTH(documento_tipo) BETWEEN 3 AND 32
                    AND documento_tipo = UPPER(documento_tipo)
                    AND REGEXP_LIKE(documento_tipo, '^[A-Z0-9_]+$', 'c')
                ),
                CONSTRAINT chk_autorizaciones_precio_cantidad CHECK (
                    cantidad > 0
                ),
                CONSTRAINT chk_autorizaciones_precio_lista CHECK (
                    precio_lista_referencia >= 0
                ),
                CONSTRAINT chk_autorizaciones_precio_minimo CHECK (
                    precio_minimo_referencia >= 0
                ),
                CONSTRAINT chk_autorizaciones_precio_minimo_lista CHECK (
                    precio_minimo_referencia <= precio_lista_referencia
                ),
                CONSTRAINT chk_autorizaciones_precio_solicitado_minimo CHECK (
                    precio_solicitado >= precio_minimo_referencia
                ),
                CONSTRAINT chk_autorizaciones_precio_solicitado_lista CHECK (
                    precio_solicitado < precio_lista_referencia
                ),
                CONSTRAINT chk_autorizaciones_precio_incluye CHECK (
                    incluye_impuestos IN (0, 1)
                ),
                CONSTRAINT chk_autorizaciones_precio_estatus CHECK (
                    estatus IN (
                        'PENDIENTE',
                        'APROBADA',
                        'RECHAZADA',
                        'CANCELADA',
                        'VENCIDA',
                        'UTILIZADA'
                    )
                ),
                CONSTRAINT chk_autorizaciones_precio_decision_par CHECK (
                    (decidido_en IS NULL AND decidido_por IS NULL)
                    OR (decidido_en IS NOT NULL AND decidido_por IS NOT NULL)
                ),
                CONSTRAINT chk_autorizaciones_precio_cancelacion_par CHECK (
                    (
                        cancelado_en IS NULL
                        AND cancelado_por IS NULL
                        AND motivo_cancelacion IS NULL
                    )
                    OR (
                        cancelado_en IS NOT NULL
                        AND cancelado_por IS NOT NULL
                        AND motivo_cancelacion IS NOT NULL
                    )
                ),
                CONSTRAINT chk_autorizaciones_precio_utilizacion_par CHECK (
                    (utilizado_en IS NULL AND utilizado_por IS NULL)
                    OR (utilizado_en IS NOT NULL AND utilizado_por IS NOT NULL)
                ),
                CONSTRAINT chk_autorizaciones_precio_decisor_distinto CHECK (
                    decidido_por IS NULL OR decidido_por <> solicitado_por
                ),
                CONSTRAINT chk_autorizaciones_precio_fecha_decision CHECK (
                    decidido_en IS NULL OR decidido_en >= solicitado_en
                ),
                CONSTRAINT chk_autorizaciones_precio_vencimiento CHECK (
                    vence_en IS NULL
                    OR (decidido_en IS NOT NULL AND vence_en > decidido_en)
                ),
                CONSTRAINT chk_autorizaciones_precio_fecha_cancelacion CHECK (
                    cancelado_en IS NULL OR cancelado_en >= solicitado_en
                ),
                CONSTRAINT chk_autorizaciones_precio_fecha_utilizacion CHECK (
                    utilizado_en IS NULL
                    OR (
                        decidido_en IS NOT NULL
                        AND utilizado_en >= decidido_en
                    )
                ),
                CONSTRAINT chk_autorizaciones_precio_estatus_flujo CHECK (
                    (
                        estatus = 'PENDIENTE'
                        AND decidido_en IS NULL
                        AND cancelado_en IS NULL
                        AND utilizado_en IS NULL
                    )
                    OR (
                        estatus = 'APROBADA'
                        AND decidido_en IS NOT NULL
                        AND cancelado_en IS NULL
                        AND utilizado_en IS NULL
                    )
                    OR (
                        estatus = 'RECHAZADA'
                        AND decidido_en IS NOT NULL
                        AND cancelado_en IS NULL
                        AND utilizado_en IS NULL
                    )
                    OR (
                        estatus = 'CANCELADA'
                        AND cancelado_en IS NOT NULL
                        AND utilizado_en IS NULL
                    )
                    OR (
                        estatus = 'VENCIDA'
                        AND decidido_en IS NOT NULL
                        AND vence_en IS NOT NULL
                        AND cancelado_en IS NULL
                        AND utilizado_en IS NULL
                    )
                    OR (
                        estatus = 'UTILIZADA'
                        AND decidido_en IS NOT NULL
                        AND cancelado_en IS NULL
                        AND utilizado_en IS NOT NULL
                    )
                ),
                CONSTRAINT fk_autorizaciones_precio_empresa
                    FOREIGN KEY (empresa_id)
                    REFERENCES empresas (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_autorizaciones_precio_producto_precio
                    FOREIGN KEY (
                        producto_precio_id,
                        id_producto,
                        lista_precio_id,
                        moneda_id
                    )
                    REFERENCES producto_precios (
                        id,
                        id_producto,
                        lista_precio_id,
                        moneda_id
                    )
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_autorizaciones_precio_solicitado_por
                    FOREIGN KEY (solicitado_por)
                    REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_autorizaciones_precio_decidido_por
                    FOREIGN KEY (decidido_por)
                    REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_autorizaciones_precio_cancelado_por
                    FOREIGN KEY (cancelado_por)
                    REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_autorizaciones_precio_utilizado_por
                    FOREIGN KEY (utilizado_por)
                    REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_autorizaciones_precio_actualizado_por
                    FOREIGN KEY (actualizado_por)
                    REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
            SQL,
        ];
    }
};
