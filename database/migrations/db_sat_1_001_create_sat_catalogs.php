<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

return new class implements Migration {
    public function id(): string
    {
        return 'db_sat_1_001_create_sat_catalogs';
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
            throw new RuntimeException('DB-SAT-1 requires DB-CORE-0.');
        }

        $existingTables = (int) $pdo->query(
            <<<'SQL'
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name IN ('unidades_sat', 'claves_sat')
            SQL
        )->fetchColumn();

        if ($existingTables !== 0) {
            throw new RuntimeException(
                'DB-SAT-1 requires SAT catalog tables to be absent before migration.'
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
            'DROP TABLE IF EXISTS claves_sat',
            'DROP TABLE IF EXISTS unidades_sat',
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
            CREATE TABLE unidades_sat (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                codigo VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                nombre VARCHAR(120) NOT NULL,
                descripcion VARCHAR(255) NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                eliminado_en DATETIME NULL,
                creado_por BIGINT UNSIGNED NULL,
                actualizado_por BIGINT UNSIGNED NULL,
                eliminado_por BIGINT UNSIGNED NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NULL DEFAULT NULL
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_unidades_sat_codigo (codigo),
                KEY idx_unidades_sat_activo (activo),
                KEY idx_unidades_sat_eliminado_en (eliminado_en),
                CONSTRAINT chk_unidades_sat_codigo CHECK (
                    CHAR_LENGTH(codigo) BETWEEN 1 AND 16
                    AND codigo REGEXP '^[A-Z0-9]+$'
                ),
                CONSTRAINT chk_unidades_sat_nombre CHECK (
                    CHAR_LENGTH(TRIM(nombre)) BETWEEN 1 AND 120
                ),
                CONSTRAINT chk_unidades_sat_activo CHECK (activo IN (0, 1)),
                CONSTRAINT fk_unidades_sat_creado_por
                    FOREIGN KEY (creado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_unidades_sat_actualizado_por
                    FOREIGN KEY (actualizado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_unidades_sat_eliminado_por
                    FOREIGN KEY (eliminado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
            <<<'SQL'
            CREATE TABLE claves_sat (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                codigo VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                descripcion VARCHAR(255) NOT NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                eliminado_en DATETIME NULL,
                creado_por BIGINT UNSIGNED NULL,
                actualizado_por BIGINT UNSIGNED NULL,
                eliminado_por BIGINT UNSIGNED NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NULL DEFAULT NULL
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_claves_sat_codigo (codigo),
                KEY idx_claves_sat_activo (activo),
                KEY idx_claves_sat_eliminado_en (eliminado_en),
                CONSTRAINT chk_claves_sat_codigo CHECK (
                    CHAR_LENGTH(codigo) BETWEEN 1 AND 16
                    AND codigo REGEXP '^[0-9]+$'
                ),
                CONSTRAINT chk_claves_sat_descripcion CHECK (
                    CHAR_LENGTH(TRIM(descripcion)) BETWEEN 1 AND 255
                ),
                CONSTRAINT chk_claves_sat_activo CHECK (activo IN (0, 1)),
                CONSTRAINT fk_claves_sat_creado_por
                    FOREIGN KEY (creado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_claves_sat_actualizado_por
                    FOREIGN KEY (actualizado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_claves_sat_eliminado_por
                    FOREIGN KEY (eliminado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL,
        ];
    }
};
