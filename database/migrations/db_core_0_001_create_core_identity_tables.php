<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

return new class implements Migration {
    public function id(): string
    {
        return 'db_core_0_001_create_core_identity_tables';
    }

    public function up(PDO $pdo): void
    {
        $existingTables = (int) $pdo->query(
            <<<'SQL'
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name IN (
                  'usuarios',
                  'roles',
                  'permisos',
                  'usuario_roles',
                  'rol_permisos',
                  'auditoria_eventos'
              )
            SQL
        )->fetchColumn();

        if ($existingTables !== 0) {
            throw new RuntimeException(
                'DB-CORE-0 requires an exclusive database without pre-existing core tables.'
            );
        }

        foreach ($this->upStatements() as $statement) {
            $pdo->exec($statement);
        }
    }

    public function down(PDO $pdo): void
    {
        foreach ([
            'DROP TABLE IF EXISTS auditoria_eventos',
            'DROP TABLE IF EXISTS rol_permisos',
            'DROP TABLE IF EXISTS usuario_roles',
            'DROP TABLE IF EXISTS permisos',
            'DROP TABLE IF EXISTS roles',
            'DROP TABLE IF EXISTS usuarios',
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
            CREATE TABLE usuarios (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                username VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                email VARCHAR(254) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                ultimo_acceso_en DATETIME NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                creado_por BIGINT UNSIGNED NULL,
                actualizado_por BIGINT UNSIGNED NULL,
                eliminado_en DATETIME NULL,
                eliminado_por BIGINT UNSIGNED NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_usuarios_username (username),
                UNIQUE KEY uq_usuarios_email (email),
                KEY idx_usuarios_activo (activo),
                KEY idx_usuarios_eliminado_en (eliminado_en),
                CONSTRAINT chk_usuarios_username CHECK (
                    CHAR_LENGTH(username) BETWEEN 3 AND 50
                    AND username = LOWER(username)
                    AND username REGEXP '^[a-z0-9._-]+$'
                ),
                CONSTRAINT chk_usuarios_activo CHECK (activo IN (0, 1)),
                CONSTRAINT chk_usuarios_password_hash CHECK (CHAR_LENGTH(password_hash) >= 60),
                CONSTRAINT fk_usuarios_creado_por
                    FOREIGN KEY (creado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_usuarios_actualizado_por
                    FOREIGN KEY (actualizado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_usuarios_eliminado_por
                    FOREIGN KEY (eliminado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE roles (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                codigo VARCHAR(64) NOT NULL,
                nombre VARCHAR(120) NOT NULL,
                descripcion VARCHAR(255) NULL,
                es_sistema TINYINT(1) NOT NULL DEFAULT 0,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                creado_por BIGINT UNSIGNED NULL,
                actualizado_por BIGINT UNSIGNED NULL,
                eliminado_en DATETIME NULL,
                eliminado_por BIGINT UNSIGNED NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_roles_codigo (codigo),
                KEY idx_roles_activo (activo),
                KEY idx_roles_eliminado_en (eliminado_en),
                CONSTRAINT chk_roles_es_sistema CHECK (es_sistema IN (0, 1)),
                CONSTRAINT chk_roles_activo CHECK (activo IN (0, 1)),
                CONSTRAINT fk_roles_creado_por
                    FOREIGN KEY (creado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_roles_actualizado_por
                    FOREIGN KEY (actualizado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_roles_eliminado_por
                    FOREIGN KEY (eliminado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE permisos (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                codigo VARCHAR(120) NOT NULL,
                modulo VARCHAR(64) NOT NULL,
                nombre VARCHAR(120) NOT NULL,
                descripcion VARCHAR(255) NULL,
                es_sistema TINYINT(1) NOT NULL DEFAULT 1,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                creado_por BIGINT UNSIGNED NULL,
                actualizado_por BIGINT UNSIGNED NULL,
                eliminado_en DATETIME NULL,
                eliminado_por BIGINT UNSIGNED NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_permisos_codigo (codigo),
                KEY idx_permisos_modulo_activo (modulo, activo),
                KEY idx_permisos_eliminado_en (eliminado_en),
                CONSTRAINT chk_permisos_es_sistema CHECK (es_sistema IN (0, 1)),
                CONSTRAINT chk_permisos_activo CHECK (activo IN (0, 1)),
                CONSTRAINT fk_permisos_creado_por
                    FOREIGN KEY (creado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_permisos_actualizado_por
                    FOREIGN KEY (actualizado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_permisos_eliminado_por
                    FOREIGN KEY (eliminado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE usuario_roles (
                usuario_id BIGINT UNSIGNED NOT NULL,
                rol_id BIGINT UNSIGNED NOT NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                creado_por BIGINT UNSIGNED NULL,
                actualizado_por BIGINT UNSIGNED NULL,
                eliminado_en DATETIME NULL,
                eliminado_por BIGINT UNSIGNED NULL,
                PRIMARY KEY (usuario_id, rol_id),
                KEY idx_usuario_roles_rol_activo (rol_id, activo),
                KEY idx_usuario_roles_eliminado_en (eliminado_en),
                CONSTRAINT chk_usuario_roles_activo CHECK (activo IN (0, 1)),
                CONSTRAINT fk_usuario_roles_usuario
                    FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_usuario_roles_rol
                    FOREIGN KEY (rol_id) REFERENCES roles (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_usuario_roles_creado_por
                    FOREIGN KEY (creado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_usuario_roles_actualizado_por
                    FOREIGN KEY (actualizado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_usuario_roles_eliminado_por
                    FOREIGN KEY (eliminado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE rol_permisos (
                rol_id BIGINT UNSIGNED NOT NULL,
                permiso_id BIGINT UNSIGNED NOT NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                creado_por BIGINT UNSIGNED NULL,
                actualizado_por BIGINT UNSIGNED NULL,
                eliminado_en DATETIME NULL,
                eliminado_por BIGINT UNSIGNED NULL,
                PRIMARY KEY (rol_id, permiso_id),
                KEY idx_rol_permisos_permiso_activo (permiso_id, activo),
                KEY idx_rol_permisos_eliminado_en (eliminado_en),
                CONSTRAINT chk_rol_permisos_activo CHECK (activo IN (0, 1)),
                CONSTRAINT fk_rol_permisos_rol
                    FOREIGN KEY (rol_id) REFERENCES roles (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_rol_permisos_permiso
                    FOREIGN KEY (permiso_id) REFERENCES permisos (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_rol_permisos_creado_por
                    FOREIGN KEY (creado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_rol_permisos_actualizado_por
                    FOREIGN KEY (actualizado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_rol_permisos_eliminado_por
                    FOREIGN KEY (eliminado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE auditoria_eventos (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                actor_usuario_id BIGINT UNSIGNED NULL,
                accion VARCHAR(120) NOT NULL,
                entidad VARCHAR(80) NOT NULL,
                entidad_id VARCHAR(64) NULL,
                resultado VARCHAR(32) NOT NULL,
                ip VARCHAR(45) NULL,
                user_agent VARCHAR(500) NULL,
                metadata_json JSON NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_auditoria_actor_fecha (actor_usuario_id, creado_en),
                KEY idx_auditoria_accion_fecha (accion, creado_en),
                KEY idx_auditoria_entidad_recurso (entidad, entidad_id),
                KEY idx_auditoria_fecha (creado_en),
                CONSTRAINT fk_auditoria_actor
                    FOREIGN KEY (actor_usuario_id) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        ];
    }
};
