<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

return new class implements Migration {
    public function id(): string
    {
        return 'perfil_vcard_1_001_create_profile_vcard_tables';
    }

    public function up(PDO $pdo): void
    {
        foreach ([
            'db_core_0_001_create_core_identity_tables',
            'db_productos_1_001_create_product_tables',
        ] as $migration) {
            $statement = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM schema_migrations
                 WHERE migration = :migration'
            );
            $statement->execute(['migration' => $migration]);

            if ((int) $statement->fetchColumn() !== 1) {
                throw new RuntimeException(
                    'PERFIL-VCARD-DB-1 requires ' . $migration . '.'
                );
            }
        }

        $existingTables = (int) $pdo->query(
            <<<'SQL'
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name IN (
                  'perfiles_usuario',
                  'usuarios_fotos',
                  'vcards_usuario',
                  'vcard_privacidad',
                  'vcard_productos',
                  'credenciales_usuario',
                  'credencial_tokens'
              )
            SQL
        )->fetchColumn();

        if ($existingTables !== 0) {
            throw new RuntimeException(
                'PERFIL-VCARD-DB-1 requires its tables to be absent.'
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
            'DROP TABLE IF EXISTS credencial_tokens',
            'DROP TABLE IF EXISTS credenciales_usuario',
            'DROP TABLE IF EXISTS vcard_productos',
            'DROP TABLE IF EXISTS vcard_privacidad',
            'DROP TABLE IF EXISTS vcards_usuario',
            'DROP TABLE IF EXISTS usuarios_fotos',
            'DROP TABLE IF EXISTS perfiles_usuario',
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
            CREATE TABLE perfiles_usuario (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                usuario_id BIGINT UNSIGNED NOT NULL,
                primer_nombre VARCHAR(80) NULL,
                segundo_nombre VARCHAR(80) NULL,
                apellido_paterno VARCHAR(80) NULL,
                apellido_materno VARCHAR(80) NULL,
                puesto VARCHAR(120) NULL,
                telefono_fijo VARCHAR(40) NULL,
                telefono_movil VARCHAR(40) NULL,
                sitio_web VARCHAR(255) NULL,
                linkedin_url VARCHAR(255) NULL,
                facebook_url VARCHAR(255) NULL,
                instagram_url VARCHAR(255) NULL,
                whatsapp VARCHAR(40) NULL,
                google_maps_url VARCHAR(500) NULL,
                ubicacion_publica VARCHAR(255) NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NULL DEFAULT NULL
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_perfiles_usuario_usuario (usuario_id),
                KEY idx_perfiles_usuario_puesto (puesto),
                CONSTRAINT chk_perfiles_usuario_telefono_fijo CHECK (
                    telefono_fijo IS NULL OR CHAR_LENGTH(telefono_fijo) BETWEEN 1 AND 40
                ),
                CONSTRAINT chk_perfiles_usuario_telefono_movil CHECK (
                    telefono_movil IS NULL OR CHAR_LENGTH(telefono_movil) BETWEEN 1 AND 40
                ),
                CONSTRAINT chk_perfiles_usuario_whatsapp CHECK (
                    whatsapp IS NULL OR CHAR_LENGTH(whatsapp) BETWEEN 1 AND 40
                ),
                CONSTRAINT fk_perfiles_usuario_usuario
                    FOREIGN KEY (usuario_id)
                    REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE usuarios_fotos (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                usuario_id BIGINT UNSIGNED NOT NULL,
                disco VARCHAR(32)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL DEFAULT 'local',
                ruta_relativa VARCHAR(500) NOT NULL,
                nombre_original VARCHAR(255) NULL,
                nombre_archivo VARCHAR(255) NOT NULL,
                mime VARCHAR(120)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL,
                extension VARCHAR(16)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL,
                tamano_bytes BIGINT UNSIGNED NOT NULL,
                sha256 CHAR(64)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL,
                ancho INT UNSIGNED NULL,
                alto INT UNSIGNED NULL,
                activa TINYINT(1) NOT NULL DEFAULT 1,
                creado_por BIGINT UNSIGNED NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reemplazada_en DATETIME NULL,
                eliminada_en DATETIME NULL,
                foto_activa_unica BIGINT UNSIGNED
                    GENERATED ALWAYS AS (
                        CASE
                            WHEN activa = 1
                             AND reemplazada_en IS NULL
                             AND eliminada_en IS NULL
                            THEN usuario_id
                            ELSE NULL
                        END
                    ) STORED,
                PRIMARY KEY (id),
                UNIQUE KEY uq_usuarios_fotos_activa (foto_activa_unica),
                UNIQUE KEY uq_usuarios_fotos_sha256 (sha256),
                KEY idx_usuarios_fotos_usuario_estado (
                    usuario_id,
                    activa,
                    eliminada_en
                ),
                KEY idx_usuarios_fotos_creado_por (creado_por),
                CONSTRAINT chk_usuarios_fotos_disco CHECK (
                    disco IN ('local')
                ),
                CONSTRAINT chk_usuarios_fotos_ruta CHECK (
                    CHAR_LENGTH(ruta_relativa) BETWEEN 1 AND 500
                ),
                CONSTRAINT chk_usuarios_fotos_nombre_archivo CHECK (
                    CHAR_LENGTH(nombre_archivo) BETWEEN 1 AND 255
                ),
                CONSTRAINT chk_usuarios_fotos_mime CHECK (
                    mime IN ('image/jpeg', 'image/png', 'image/webp')
                ),
                CONSTRAINT chk_usuarios_fotos_extension CHECK (
                    extension IN ('jpg', 'jpeg', 'png', 'webp')
                ),
                CONSTRAINT chk_usuarios_fotos_tamano CHECK (
                    tamano_bytes > 0
                ),
                CONSTRAINT chk_usuarios_fotos_sha256 CHECK (
                    REGEXP_LIKE(sha256, '^[a-f0-9]{64}$', 'c')
                ),
                CONSTRAINT chk_usuarios_fotos_dimensiones CHECK (
                    (ancho IS NULL AND alto IS NULL)
                    OR (ancho IS NOT NULL AND alto IS NOT NULL)
                ),
                CONSTRAINT chk_usuarios_fotos_activa CHECK (
                    activa IN (0, 1)
                ),
                CONSTRAINT chk_usuarios_fotos_estado CHECK (
                    (
                        activa = 1
                        AND reemplazada_en IS NULL
                        AND eliminada_en IS NULL
                    )
                    OR activa = 0
                ),
                CONSTRAINT fk_usuarios_fotos_usuario
                    FOREIGN KEY (usuario_id)
                    REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_usuarios_fotos_creado_por
                    FOREIGN KEY (creado_por)
                    REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE vcards_usuario (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                usuario_id BIGINT UNSIGNED NOT NULL,
                slug VARCHAR(80)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL,
                titulo_publico VARCHAR(160) NULL,
                descripcion_publica TEXT NULL,
                publicada TINYINT(1) NOT NULL DEFAULT 0,
                canal_contacto_preferido VARCHAR(32)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NULL DEFAULT NULL
                    ON UPDATE CURRENT_TIMESTAMP,
                publicado_en DATETIME NULL,
                despublicado_en DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_vcards_usuario_usuario (usuario_id),
                UNIQUE KEY uq_vcards_usuario_slug (slug),
                KEY idx_vcards_usuario_publicada (publicada, slug),
                CONSTRAINT chk_vcards_usuario_slug CHECK (
                    CHAR_LENGTH(slug) BETWEEN 3 AND 80
                    AND slug = LOWER(slug)
                    AND REGEXP_LIKE(
                        slug,
                        '^[a-z0-9]+([._-][a-z0-9]+)*$',
                        'c'
                    )
                ),
                CONSTRAINT chk_vcards_usuario_publicada CHECK (
                    publicada IN (0, 1)
                ),
                CONSTRAINT chk_vcards_usuario_canal CHECK (
                    canal_contacto_preferido IS NULL
                    OR canal_contacto_preferido IN (
                        'telefono_fijo',
                        'telefono_movil',
                        'whatsapp',
                        'correo',
                        'sitio_web'
                    )
                ),
                CONSTRAINT chk_vcards_usuario_publicacion CHECK (
                    (publicada = 1 AND despublicado_en IS NULL)
                    OR publicada = 0
                ),
                CONSTRAINT fk_vcards_usuario_usuario
                    FOREIGN KEY (usuario_id)
                    REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE vcard_privacidad (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                vcard_id BIGINT UNSIGNED NOT NULL,
                campo VARCHAR(64)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL,
                visible TINYINT(1) NOT NULL DEFAULT 0,
                actualizado_en DATETIME NULL DEFAULT NULL
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_vcard_privacidad_campo (vcard_id, campo),
                KEY idx_vcard_privacidad_visible (visible),
                CONSTRAINT chk_vcard_privacidad_campo CHECK (
                    campo IN (
                        'foto',
                        'correo',
                        'telefono_fijo',
                        'telefono_movil',
                        'puesto',
                        'empresa',
                        'almacen',
                        'ubicacion',
                        'sitio_web',
                        'linkedin',
                        'facebook',
                        'instagram',
                        'whatsapp',
                        'google_maps',
                        'productos'
                    )
                ),
                CONSTRAINT chk_vcard_privacidad_visible CHECK (
                    visible IN (0, 1)
                ),
                CONSTRAINT fk_vcard_privacidad_vcard
                    FOREIGN KEY (vcard_id)
                    REFERENCES vcards_usuario (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE vcard_productos (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                vcard_id BIGINT UNSIGNED NOT NULL,
                id_producto VARCHAR(16)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                destacado TINYINT(1) NOT NULL DEFAULT 0,
                orden INT UNSIGNED NOT NULL DEFAULT 0,
                texto_publico VARCHAR(255) NULL,
                creado_por BIGINT UNSIGNED NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NULL DEFAULT NULL
                    ON UPDATE CURRENT_TIMESTAMP,
                eliminado_en DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_vcard_productos_producto (vcard_id, id_producto),
                KEY idx_vcard_productos_publicos (
                    vcard_id,
                    activo,
                    eliminado_en,
                    orden
                ),
                KEY idx_vcard_productos_producto (id_producto),
                KEY idx_vcard_productos_creado_por (creado_por),
                CONSTRAINT chk_vcard_productos_activo CHECK (
                    activo IN (0, 1)
                ),
                CONSTRAINT chk_vcard_productos_destacado CHECK (
                    destacado IN (0, 1)
                ),
                CONSTRAINT chk_vcard_productos_estado CHECK (
                    (activo = 1 AND eliminado_en IS NULL)
                    OR activo = 0
                ),
                CONSTRAINT fk_vcard_productos_vcard
                    FOREIGN KEY (vcard_id)
                    REFERENCES vcards_usuario (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_vcard_productos_producto
                    FOREIGN KEY (id_producto)
                    REFERENCES productos (id_producto)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_vcard_productos_creado_por
                    FOREIGN KEY (creado_por)
                    REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE credenciales_usuario (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                usuario_id BIGINT UNSIGNED NOT NULL,
                estatus VARCHAR(32)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL DEFAULT 'VIGENTE',
                emitida_en DATETIME NULL,
                expira_en DATETIME NULL,
                revocada_en DATETIME NULL,
                revocada_por BIGINT UNSIGNED NULL,
                motivo_revocacion VARCHAR(255) NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NULL DEFAULT NULL
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_credenciales_usuario_usuario (usuario_id),
                KEY idx_credenciales_usuario_estatus (estatus),
                KEY idx_credenciales_usuario_revocada_por (revocada_por),
                CONSTRAINT chk_credenciales_usuario_estatus CHECK (
                    estatus IN ('VIGENTE', 'SUSPENDIDA', 'VENCIDA', 'REVOCADA')
                ),
                CONSTRAINT chk_credenciales_usuario_revocacion CHECK (
                    (
                        estatus = 'REVOCADA'
                        AND revocada_en IS NOT NULL
                        AND revocada_por IS NOT NULL
                    )
                    OR (
                        estatus <> 'REVOCADA'
                        AND revocada_en IS NULL
                        AND revocada_por IS NULL
                        AND motivo_revocacion IS NULL
                    )
                ),
                CONSTRAINT chk_credenciales_usuario_expira CHECK (
                    expira_en IS NULL
                    OR emitida_en IS NULL
                    OR expira_en > emitida_en
                ),
                CONSTRAINT fk_credenciales_usuario_usuario
                    FOREIGN KEY (usuario_id)
                    REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_credenciales_usuario_revocada_por
                    FOREIGN KEY (revocada_por)
                    REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE credencial_tokens (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                credencial_id BIGINT UNSIGNED NOT NULL,
                token_hash CHAR(64)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL,
                token_prefix CHAR(12)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expira_en DATETIME NULL,
                revocado_en DATETIME NULL,
                usado_ultimo_en DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_credencial_tokens_hash (token_hash),
                KEY idx_credencial_tokens_credencial_estado (
                    credencial_id,
                    activo,
                    revocado_en
                ),
                KEY idx_credencial_tokens_prefix (token_prefix),
                CONSTRAINT chk_credencial_tokens_hash CHECK (
                    REGEXP_LIKE(token_hash, '^[a-f0-9]{64}$', 'c')
                ),
                CONSTRAINT chk_credencial_tokens_prefix CHECK (
                    token_prefix IS NULL
                    OR REGEXP_LIKE(token_prefix, '^[A-Z0-9]{12}$', 'c')
                ),
                CONSTRAINT chk_credencial_tokens_activo CHECK (
                    activo IN (0, 1)
                ),
                CONSTRAINT chk_credencial_tokens_estado CHECK (
                    (activo = 1 AND revocado_en IS NULL)
                    OR activo = 0
                ),
                CONSTRAINT chk_credencial_tokens_expira CHECK (
                    expira_en IS NULL OR expira_en > creado_en
                ),
                CONSTRAINT fk_credencial_tokens_credencial
                    FOREIGN KEY (credencial_id)
                    REFERENCES credenciales_usuario (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
            SQL,
        ];
    }
};
