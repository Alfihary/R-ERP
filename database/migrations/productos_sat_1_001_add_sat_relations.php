<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

return new class implements Migration {
    public function id(): string
    {
        return 'productos_sat_1_001_add_sat_relations';
    }

    public function up(PDO $pdo): void
    {
        foreach ([
            'db_productos_2_001_extend_product_master',
            'db_sat_1_001_create_sat_catalogs',
        ] as $migration) {
            $statement = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM schema_migrations
                 WHERE migration = :migration'
            );
            $statement->execute(['migration' => $migration]);

            if ((int) $statement->fetchColumn() !== 1) {
                throw new RuntimeException(
                    'PRODUCTOS-SAT-1 requires ' . $migration . '.'
                );
            }
        }

        $existingColumns = (int) $pdo->query(
            "SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'productos'
               AND column_name IN ('clave_sat_id', 'unidad_sat_id')"
        )->fetchColumn();

        if ($existingColumns !== 0) {
            throw new RuntimeException(
                'PRODUCTOS-SAT-1 requires SAT product columns to be absent.'
            );
        }

        $pdo->exec(
            <<<'SQL'
            ALTER TABLE productos
                ADD COLUMN clave_sat_id BIGINT UNSIGNED NULL
                    AFTER clasificacion_producto_id,
                ADD COLUMN unidad_sat_id BIGINT UNSIGNED NULL
                    AFTER clave_sat_id,
                ADD KEY idx_productos_clave_sat (clave_sat_id),
                ADD KEY idx_productos_unidad_sat (unidad_sat_id),
                ADD CONSTRAINT fk_productos_clave_sat
                    FOREIGN KEY (clave_sat_id)
                    REFERENCES claves_sat (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                ADD CONSTRAINT fk_productos_unidad_sat
                    FOREIGN KEY (unidad_sat_id)
                    REFERENCES unidades_sat (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT
            SQL
        );
    }

    public function down(PDO $pdo): void
    {
        $columnCount = (int) $pdo->query(
            "SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'productos'
               AND column_name IN ('clave_sat_id', 'unidad_sat_id')"
        )->fetchColumn();

        if ($columnCount === 0) {
            return;
        }

        $pdo->exec(
            <<<'SQL'
            ALTER TABLE productos
                DROP FOREIGN KEY fk_productos_clave_sat,
                DROP FOREIGN KEY fk_productos_unidad_sat,
                DROP INDEX idx_productos_clave_sat,
                DROP INDEX idx_productos_unidad_sat,
                DROP COLUMN clave_sat_id,
                DROP COLUMN unidad_sat_id
            SQL
        );
    }
};
