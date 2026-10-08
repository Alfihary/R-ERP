<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;
return new class implements Migration {
    public function id(): string
    {
        return 'solicitudes_cotizacion_1_001_create_request_tables';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
        CREATE TABLE solicitudes_cotizacion (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            solicitud_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            folio VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            nombre_cliente VARCHAR(160) NOT NULL,
            telefono VARCHAR(64) NULL,
            correo VARCHAR(190) NULL,
            solicitud TEXT NOT NULL,
            vendedor VARCHAR(160) NULL,
            vendedor_usuario_id BIGINT UNSIGNED NULL,
            estado VARCHAR(32) NOT NULL DEFAULT 'recibida',
            producto_id_externo VARCHAR(100) NULL,
            producto_codigo VARCHAR(100) NULL,
            producto_descripcion VARCHAR(255) NULL,
            producto_marca VARCHAR(120) NULL,
            producto_unidad VARCHAR(80) NULL,
            match_score DECIMAL(5,2) NULL,
            match_motivo VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_solicitudes_cotizacion_solicitud_id (solicitud_id),
            UNIQUE KEY uq_solicitudes_cotizacion_folio (folio),
            KEY ix_solicitudes_cotizacion_vendedor_estado (vendedor_usuario_id, estado, created_at),
            CONSTRAINT fk_solicitudes_cotizacion_vendedor
                FOREIGN KEY (vendedor_usuario_id) REFERENCES usuarios(id)
                ON UPDATE RESTRICT ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS solicitudes_cotizacion');
    }
};
