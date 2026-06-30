<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

return new class implements Migration {
    public function id(): string
    {
        return 'db_scope_1_001_create_scope_tables';
    }

    public function up(PDO $pdo): void
    {
        $coreMigration = $pdo->prepare(
            'SELECT COUNT(*)
             FROM schema_migrations
             WHERE migration = :migration'
        );
        $coreMigration->execute([
            'migration' => 'db_core_0_001_create_core_identity_tables',
        ]);

        if ((int) $coreMigration->fetchColumn() !== 1) {
            throw new RuntimeException('DB-SCOPE-1 requires DB-CORE-0.');
        }

        $existingTables = (int) $pdo->query(
            <<<'SQL'
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name IN (
                  'empresas',
                  'almacenes',
                  'usuario_empresas',
                  'usuario_almacenes'
              )
            SQL
        )->fetchColumn();

        if ($existingTables !== 0) {
            throw new RuntimeException(
                'DB-SCOPE-1 requires its four tables to be absent before migration.'
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
            'DROP TABLE IF EXISTS usuario_almacenes',
            'DROP TABLE IF EXISTS usuario_empresas',
            'DROP TABLE IF EXISTS almacenes',
            'DROP TABLE IF EXISTS empresas',
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
            CREATE TABLE empresas (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                codigo VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                nombre VARCHAR(150) NOT NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                creado_por BIGINT UNSIGNED NULL,
                actualizado_por BIGINT UNSIGNED NULL,
                eliminado_en DATETIME NULL,
                eliminado_por BIGINT UNSIGNED NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_empresas_codigo (codigo),
                KEY idx_empresas_activo (activo),
                KEY idx_empresas_eliminado_en (eliminado_en),
                CONSTRAINT chk_empresas_codigo CHECK (
                    CHAR_LENGTH(codigo) BETWEEN 3 AND 64
                    AND codigo = LOWER(codigo)
                    AND codigo REGEXP '^[a-z0-9]+([._-][a-z0-9]+)*$'
                ),
                CONSTRAINT chk_empresas_activo CHECK (activo IN (0, 1)),
                CONSTRAINT fk_empresas_creado_por
                    FOREIGN KEY (creado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_empresas_actualizado_por
                    FOREIGN KEY (actualizado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_empresas_eliminado_por
                    FOREIGN KEY (eliminado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE almacenes (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                empresa_id BIGINT UNSIGNED NOT NULL,
                codigo VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                nombre VARCHAR(150) NOT NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                creado_por BIGINT UNSIGNED NULL,
                actualizado_por BIGINT UNSIGNED NULL,
                eliminado_en DATETIME NULL,
                eliminado_por BIGINT UNSIGNED NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_almacenes_empresa_codigo (empresa_id, codigo),
                UNIQUE KEY uq_almacenes_empresa_id (empresa_id, id),
                KEY idx_almacenes_empresa_activo (empresa_id, activo),
                KEY idx_almacenes_eliminado_en (eliminado_en),
                CONSTRAINT chk_almacenes_codigo CHECK (
                    CHAR_LENGTH(codigo) BETWEEN 3 AND 64
                    AND codigo = LOWER(codigo)
                    AND codigo REGEXP '^[a-z0-9]+([._-][a-z0-9]+)*$'
                ),
                CONSTRAINT chk_almacenes_activo CHECK (activo IN (0, 1)),
                CONSTRAINT fk_almacenes_empresa
                    FOREIGN KEY (empresa_id) REFERENCES empresas (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_almacenes_creado_por
                    FOREIGN KEY (creado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_almacenes_actualizado_por
                    FOREIGN KEY (actualizado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_almacenes_eliminado_por
                    FOREIGN KEY (eliminado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE usuario_empresas (
                usuario_id BIGINT UNSIGNED NOT NULL,
                empresa_id BIGINT UNSIGNED NOT NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                creado_por BIGINT UNSIGNED NULL,
                actualizado_por BIGINT UNSIGNED NULL,
                eliminado_en DATETIME NULL,
                eliminado_por BIGINT UNSIGNED NULL,
                PRIMARY KEY (usuario_id, empresa_id),
                KEY idx_usuario_empresas_empresa_activo (empresa_id, activo),
                KEY idx_usuario_empresas_eliminado_en (eliminado_en),
                CONSTRAINT chk_usuario_empresas_activo CHECK (activo IN (0, 1)),
                CONSTRAINT fk_usuario_empresas_usuario
                    FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_usuario_empresas_empresa
                    FOREIGN KEY (empresa_id) REFERENCES empresas (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_usuario_empresas_creado_por
                    FOREIGN KEY (creado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_usuario_empresas_actualizado_por
                    FOREIGN KEY (actualizado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_usuario_empresas_eliminado_por
                    FOREIGN KEY (eliminado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE usuario_almacenes (
                usuario_id BIGINT UNSIGNED NOT NULL,
                empresa_id BIGINT UNSIGNED NOT NULL,
                almacen_id BIGINT UNSIGNED NOT NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                creado_por BIGINT UNSIGNED NULL,
                actualizado_por BIGINT UNSIGNED NULL,
                eliminado_en DATETIME NULL,
                eliminado_por BIGINT UNSIGNED NULL,
                PRIMARY KEY (usuario_id, almacen_id),
                KEY idx_usuario_almacenes_usuario_empresa (usuario_id, empresa_id),
                KEY idx_usuario_almacenes_empresa_almacen (empresa_id, almacen_id),
                KEY idx_usuario_almacenes_almacen_activo (almacen_id, activo),
                KEY idx_usuario_almacenes_eliminado_en (eliminado_en),
                CONSTRAINT chk_usuario_almacenes_activo CHECK (activo IN (0, 1)),
                CONSTRAINT fk_usuario_almacenes_usuario
                    FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_usuario_almacenes_almacen
                    FOREIGN KEY (almacen_id) REFERENCES almacenes (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_usuario_almacenes_usuario_empresa
                    FOREIGN KEY (usuario_id, empresa_id)
                    REFERENCES usuario_empresas (usuario_id, empresa_id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_usuario_almacenes_empresa_almacen
                    FOREIGN KEY (empresa_id, almacen_id)
                    REFERENCES almacenes (empresa_id, id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_usuario_almacenes_creado_por
                    FOREIGN KEY (creado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_usuario_almacenes_actualizado_por
                    FOREIGN KEY (actualizado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_usuario_almacenes_eliminado_por
                    FOREIGN KEY (eliminado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        ];
    }
};
