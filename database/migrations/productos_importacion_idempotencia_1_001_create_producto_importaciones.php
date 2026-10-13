<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

return new class implements Migration {
    public function id(): string
    {
        return 'productos_importacion_idempotencia_1_001_create_producto_importaciones';
    }

    public function up(PDO $pdo): void
    {
        $existing = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = 'producto_importaciones'"
        )->fetchColumn();

        if ($existing !== 0) {
            throw new RuntimeException(
                'PRODUCTOS-4 idempotency requires producto_importaciones to be absent.'
            );
        }

        $users = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = 'usuarios'"
        )->fetchColumn();

        if ($users !== 1) {
            throw new RuntimeException(
                'PRODUCTOS-4 idempotency requires the usuarios table.'
            );
        }

        $pdo->exec(<<<'SQL'
        CREATE TABLE producto_importaciones (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            confirmation_token CHAR(64)
                CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            preview_id CHAR(64)
                CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            usuario_id BIGINT UNSIGNED NOT NULL,
            source_sha256 CHAR(64)
                CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            estado VARCHAR(20)
                CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            total_filas INT UNSIGNED NOT NULL,
            creados INT UNSIGNED NOT NULL DEFAULT 0,
            resultado_resumen_json JSON NULL,
            creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completado_en DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_producto_importaciones_confirmation_token
                (confirmation_token),
            KEY idx_producto_importaciones_usuario_creado
                (usuario_id, creado_en),
            KEY idx_producto_importaciones_preview (preview_id),
            KEY idx_producto_importaciones_digest (source_sha256),
            CONSTRAINT chk_producto_importaciones_estado
                CHECK (estado IN ('PROCESSING', 'SUCCEEDED')),
            CONSTRAINT chk_producto_importaciones_estado_consistente
                CHECK (
                    (
                        estado = 'PROCESSING'
                        AND completado_en IS NULL
                        AND resultado_resumen_json IS NULL
                    )
                    OR
                    (
                        estado = 'SUCCEEDED'
                        AND completado_en IS NOT NULL
                        AND resultado_resumen_json IS NOT NULL
                    )
                ),
            CONSTRAINT fk_producto_importaciones_usuario
                FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
                ON UPDATE RESTRICT ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS producto_importaciones');
    }
};
