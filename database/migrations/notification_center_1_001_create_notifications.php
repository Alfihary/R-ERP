<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

return new class implements Migration {
    public function id(): string
    {
        return 'notification_center_1_001_create_notifications';
    }

    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE notificaciones (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id BIGINT UNSIGNED NOT NULL,
    rol_id BIGINT UNSIGNED NOT NULL,
    tipo VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    titulo VARCHAR(160) NOT NULL,
    mensaje TEXT NOT NULL,
    prioridad VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'normal',
    entidad_tipo VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    entidad_id BIGINT UNSIGNED NULL,
    accion_url VARCHAR(500) NULL,
    origen VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    idempotency_key VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    idempotency_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    leida_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_notificaciones_idempotency (idempotency_key),
    KEY ix_notificaciones_usuario_rol_leida_fecha (usuario_id, rol_id, leida_at, created_at),
    CONSTRAINT fk_notificaciones_usuario FOREIGN KEY (usuario_id)
        REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_notificaciones_rol FOREIGN KEY (rol_id)
        REFERENCES roles(id) ON DELETE RESTRICT,
    CONSTRAINT ck_notificaciones_prioridad CHECK (prioridad IN ('baja', 'normal', 'alta', 'urgente'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE notificaciones');
    }
};
