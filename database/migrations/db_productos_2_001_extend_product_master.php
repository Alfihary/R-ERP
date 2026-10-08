<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

return new class implements Migration {
    public function id(): string
    {
        return 'db_productos_2_001_extend_product_master';
    }

    public function up(PDO $pdo): void
    {
        $prerequisite = $pdo->prepare(
            'SELECT COUNT(*)
             FROM schema_migrations
             WHERE migration = :migration'
        );
        $prerequisite->execute([
            'migration' => 'db_productos_1_001_create_product_tables',
        ]);

        if ((int) $prerequisite->fetchColumn() !== 1) {
            throw new RuntimeException(
                'DB-PRODUCTOS-2 requires DB-PRODUCTOS-1.'
            );
        }

        $tableExists = (int) $pdo->query(
            "SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = 'tipos_producto'"
        )->fetchColumn();
        $columnCount = (int) $pdo->query(
            "SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'productos'
               AND column_name IN (
                   'tipo_producto_id',
                   'peso_kg',
                   'largo_cm',
                   'ancho_cm',
                   'alto_cm',
                   'controla_series',
                   'controla_lotes',
                   'controla_pedimentos'
               )"
        )->fetchColumn();

        if ($tableExists !== 0 || $columnCount !== 0) {
            throw new RuntimeException(
                'DB-PRODUCTOS-2 requires its table and columns to be absent.'
            );
        }

        try {
            $pdo->exec($this->createProductTypesTable());
            $pdo->exec(
                <<<'SQL'
                INSERT INTO tipos_producto (
                    codigo,
                    nombre,
                    descripcion,
                    activo
                )
                VALUES (
                    'PRODUCTO',
                    'Producto',
                    'Producto físico o mercancía.',
                    1
                )
                SQL
            );
            $productTypeId = (int) $pdo->lastInsertId();

            if ($productTypeId < 1) {
                throw new RuntimeException(
                    'DB-PRODUCTOS-2 could not create its compatibility type.'
                );
            }

            $pdo->exec($this->extendProductsTable($productTypeId));
        } catch (Throwable $exception) {
            try {
                $this->down($pdo);
            } catch (Throwable) {
                // Preserve the original migration failure.
            }

            throw $exception;
        }
    }

    public function down(PDO $pdo): void
    {
        $hasTypeColumn = (int) $pdo->query(
            "SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'productos'
               AND column_name = 'tipo_producto_id'"
        )->fetchColumn();

        if ($hasTypeColumn === 1) {
            $pdo->exec(
                <<<'SQL'
                ALTER TABLE productos
                    DROP FOREIGN KEY fk_productos_tipo_producto,
                    DROP INDEX idx_productos_tipo_producto,
                    DROP COLUMN controla_pedimentos,
                    DROP COLUMN controla_lotes,
                    DROP COLUMN controla_series,
                    DROP COLUMN alto_cm,
                    DROP COLUMN ancho_cm,
                    DROP COLUMN largo_cm,
                    DROP COLUMN peso_kg,
                    DROP COLUMN tipo_producto_id
                SQL
            );
        }

        $pdo->exec('DROP TABLE IF EXISTS tipos_producto');
    }

    private function createProductTypesTable(): string
    {
        return <<<'SQL'
            CREATE TABLE tipos_producto (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                codigo VARCHAR(32)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NOT NULL,
                nombre VARCHAR(100) NOT NULL,
                descripcion VARCHAR(255) NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                creado_por BIGINT UNSIGNED NULL,
                actualizado_por BIGINT UNSIGNED NULL,
                eliminado_en DATETIME NULL,
                eliminado_por BIGINT UNSIGNED NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_tipos_producto_codigo (codigo),
                KEY idx_tipos_producto_activo (activo),
                KEY idx_tipos_producto_eliminado_en (eliminado_en),
                CONSTRAINT chk_tipos_producto_codigo CHECK (
                    REGEXP_LIKE(
                        codigo,
                        '^[A-Z0-9]+(?:_[A-Z0-9]+)*$',
                        'c'
                    )
                ),
                CONSTRAINT chk_tipos_producto_nombre CHECK (
                    CHAR_LENGTH(nombre) BETWEEN 1 AND 100
                ),
                CONSTRAINT chk_tipos_producto_activo CHECK (
                    activo IN (0, 1)
                ),
                CONSTRAINT fk_tipos_producto_creado_por
                    FOREIGN KEY (creado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_tipos_producto_actualizado_por
                    FOREIGN KEY (actualizado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_tipos_producto_eliminado_por
                    FOREIGN KEY (eliminado_por) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
            SQL;
    }

    private function extendProductsTable(int $productTypeId): string
    {
        return sprintf(
            <<<'SQL'
            ALTER TABLE productos
                ADD COLUMN tipo_producto_id BIGINT UNSIGNED
                    NOT NULL DEFAULT %d
                    AFTER descripcion_larga,
                ADD COLUMN peso_kg DECIMAL(12,4) NULL
                    AFTER clasificacion_producto_id,
                ADD COLUMN largo_cm DECIMAL(12,3) NULL
                    AFTER peso_kg,
                ADD COLUMN ancho_cm DECIMAL(12,3) NULL
                    AFTER largo_cm,
                ADD COLUMN alto_cm DECIMAL(12,3) NULL
                    AFTER ancho_cm,
                ADD COLUMN controla_series TINYINT(1) NOT NULL DEFAULT 0
                    AFTER alto_cm,
                ADD COLUMN controla_lotes TINYINT(1) NOT NULL DEFAULT 0
                    AFTER controla_series,
                ADD COLUMN controla_pedimentos TINYINT(1) NOT NULL DEFAULT 0
                    AFTER controla_lotes,
                ADD KEY idx_productos_tipo_producto (tipo_producto_id),
                ADD CONSTRAINT chk_productos_peso_kg CHECK (
                    peso_kg IS NULL OR peso_kg > 0
                ),
                ADD CONSTRAINT chk_productos_largo_cm CHECK (
                    largo_cm IS NULL OR largo_cm > 0
                ),
                ADD CONSTRAINT chk_productos_ancho_cm CHECK (
                    ancho_cm IS NULL OR ancho_cm > 0
                ),
                ADD CONSTRAINT chk_productos_alto_cm CHECK (
                    alto_cm IS NULL OR alto_cm > 0
                ),
                ADD CONSTRAINT chk_productos_controla_series CHECK (
                    controla_series IN (0, 1)
                ),
                ADD CONSTRAINT chk_productos_controla_lotes CHECK (
                    controla_lotes IN (0, 1)
                ),
                ADD CONSTRAINT chk_productos_controla_pedimentos CHECK (
                    controla_pedimentos IN (0, 1)
                ),
                ADD CONSTRAINT fk_productos_tipo_producto
                    FOREIGN KEY (tipo_producto_id)
                    REFERENCES tipos_producto (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT
            SQL,
            $productTypeId
        );
    }
};
