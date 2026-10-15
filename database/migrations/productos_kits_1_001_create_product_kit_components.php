<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

return new class implements Migration {
    public function id(): string
    {
        return 'productos_kits_1_001_create_product_kit_components';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
        CREATE TABLE productos_kit_componentes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            kit_id_producto VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            componente_id_producto VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            cantidad DECIMAL(18,6) NOT NULL,
            activo TINYINT(1) NOT NULL DEFAULT 1,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            eliminado_en DATETIME NULL,
            creado_por BIGINT UNSIGNED NULL,
            actualizado_por BIGINT UNSIGNED NULL,
            eliminado_por BIGINT UNSIGNED NULL,
            active_relation_marker TINYINT GENERATED ALWAYS AS (
                CASE WHEN activo = 1 AND eliminado_en IS NULL THEN 1 ELSE NULL END
            ) STORED,
            PRIMARY KEY (id),
            UNIQUE KEY uq_productos_kit_componentes_active (
                kit_id_producto, componente_id_producto, active_relation_marker
            ),
            KEY idx_productos_kit_componentes_kit_active (kit_id_producto, activo, eliminado_en),
            KEY idx_productos_kit_componentes_component_active (componente_id_producto, activo, eliminado_en),
            CONSTRAINT chk_productos_kit_componentes_not_self CHECK (kit_id_producto <> componente_id_producto),
            CONSTRAINT chk_productos_kit_componentes_quantity CHECK (cantidad > 0),
            CONSTRAINT chk_productos_kit_componentes_active CHECK (activo IN (0, 1)),
            CONSTRAINT fk_productos_kit_componentes_kit FOREIGN KEY (kit_id_producto)
                REFERENCES productos (id_producto) ON UPDATE RESTRICT ON DELETE RESTRICT,
            CONSTRAINT fk_productos_kit_componentes_component FOREIGN KEY (componente_id_producto)
                REFERENCES productos (id_producto) ON UPDATE RESTRICT ON DELETE RESTRICT,
            CONSTRAINT fk_productos_kit_componentes_created_by FOREIGN KEY (creado_por)
                REFERENCES usuarios (id) ON UPDATE RESTRICT ON DELETE SET NULL,
            CONSTRAINT fk_productos_kit_componentes_updated_by FOREIGN KEY (actualizado_por)
                REFERENCES usuarios (id) ON UPDATE RESTRICT ON DELETE SET NULL,
            CONSTRAINT fk_productos_kit_componentes_deleted_by FOREIGN KEY (eliminado_por)
                REFERENCES usuarios (id) ON UPDATE RESTRICT ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=ascii COLLATE=ascii_bin
        SQL);
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS productos_kit_componentes');
    }
};
