<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

return new class implements Migration {
    public function id(): string
    {
        return 'inventario_concurrencia_auditoria_implementacion_1_001_create_idempotency';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS inventario_operaciones_idempotencia (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                scope_key VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                idempotency_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                estado VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'PENDIENTE',
                resultado_json LONGTEXT NULL,
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                completado_en DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_inventario_idempotencia_scope_key (scope_key, idempotency_key),
                KEY idx_inventario_idempotencia_estado (estado),
                CONSTRAINT chk_inventario_idempotencia_key CHECK (
                    CHAR_LENGTH(idempotency_key) BETWEEN 16 AND 128
                    AND idempotency_key NOT REGEXP '[[:space:]]'
                ),
                CONSTRAINT chk_inventario_idempotencia_hash CHECK (
                    payload_hash REGEXP _ascii'^[a-f0-9]{64}$'
                ),
                CONSTRAINT chk_inventario_idempotencia_estado CHECK (
                    estado IN ('PENDIENTE', 'COMPLETADA')
                )
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS inventario_operaciones_idempotencia');
    }
};
