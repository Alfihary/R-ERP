<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

return new class implements Migration {
    public function id(): string
    {
        return 'productos_similares_1_001_create_product_similar_tables';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
        CREATE TABLE producto_similares (
            producto_a_id VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            producto_b_id VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actualizado_en DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            creado_por BIGINT UNSIGNED NULL,
            actualizado_por BIGINT UNSIGNED NULL,
            eliminado_en DATETIME NULL,
            eliminado_por BIGINT UNSIGNED NULL,
            PRIMARY KEY (producto_a_id, producto_b_id),
            KEY idx_producto_similares_b (producto_b_id),
            KEY idx_producto_similares_eliminado (eliminado_en),
            CONSTRAINT chk_producto_similares_distintos CHECK (producto_a_id <> producto_b_id),
            CONSTRAINT chk_producto_similares_orden CHECK (producto_a_id < producto_b_id),
            CONSTRAINT fk_producto_similares_a FOREIGN KEY (producto_a_id)
                REFERENCES productos (id_producto) ON UPDATE RESTRICT ON DELETE RESTRICT,
            CONSTRAINT fk_producto_similares_b FOREIGN KEY (producto_b_id)
                REFERENCES productos (id_producto) ON UPDATE RESTRICT ON DELETE RESTRICT,
            CONSTRAINT fk_producto_similares_creado_por FOREIGN KEY (creado_por)
                REFERENCES usuarios (id) ON UPDATE RESTRICT ON DELETE SET NULL,
            CONSTRAINT fk_producto_similares_actualizado_por FOREIGN KEY (actualizado_por)
                REFERENCES usuarios (id) ON UPDATE RESTRICT ON DELETE SET NULL,
            CONSTRAINT fk_producto_similares_eliminado_por FOREIGN KEY (eliminado_por)
                REFERENCES usuarios (id) ON UPDATE RESTRICT ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS producto_similares');
    }
};
