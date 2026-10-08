<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

return new class implements Migration {
    public function id(): string
    {
        return 'tp_partidas_estados_correo_outbox_db_1_001_create_ticket_product_email_outbox';
    }

    public function up(PDO $pdo): void
    {
        foreach ([
            'tp_partidas_estados_db_1_001_create_ticket_product_tables',
        ] as $migration) {
            $statement = $pdo->prepare(
                'SELECT COUNT(*) FROM schema_migrations WHERE migration = :migration'
            );
            $statement->execute(['migration' => $migration]);

            if ((int) $statement->fetchColumn() !== 1) {
                throw new RuntimeException('TP-PARTIDAS-ESTADOS-CORREO-OUTBOX-DB-1 requires ' . $migration . '.');
            }
        }

        $existingTables = (int) $pdo->query(
            <<<'SQL'
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = 'tickets_productos_correos'
            SQL
        )->fetchColumn();

        if ($existingTables !== 0) {
            throw new RuntimeException('TP-PARTIDAS-ESTADOS-CORREO-OUTBOX-DB-1 requires tickets_productos_correos to be absent.');
        }

        $pdo->exec(
            <<<'SQL'
            CREATE TABLE tickets_productos_correos (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                ticket_id BIGINT UNSIGNED NOT NULL,
                partida_id BIGINT UNSIGNED NULL,
                evento VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                plantilla VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                destinatario_email VARCHAR(190) NOT NULL,
                cc_json JSON NULL,
                subject VARCHAR(190) NOT NULL,
                html MEDIUMTEXT NULL,
                text MEDIUMTEXT NULL,
                status VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'PENDIENTE',
                intentos SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                max_intentos SMALLINT UNSIGNED NOT NULL DEFAULT 3,
                error_mensaje_seguro VARCHAR(500) NULL,
                ultimo_intento_at DATETIME NULL,
                enviado_at DATETIME NULL,
                cancelado_at DATETIME NULL,
                creado_por_usuario_id BIGINT UNSIGNED NULL,
                dedupe_key VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_tickets_productos_correos_dedupe (dedupe_key),
                KEY idx_tickets_productos_correos_ticket (ticket_id),
                KEY idx_tickets_productos_correos_partida (partida_id),
                KEY idx_tickets_productos_correos_status (status),
                KEY idx_tickets_productos_correos_evento (evento),
                KEY idx_tickets_productos_correos_created_at (created_at),
                KEY idx_tickets_productos_correos_destinatario (destinatario_email),
                KEY idx_tickets_productos_correos_pendientes (status, created_at),
                CONSTRAINT chk_tickets_productos_correos_evento CHECK (
                    evento IN (
                        'TICKET_CREADO',
                        'PARTIDA_APROBADA',
                        'PARTIDA_RECHAZADA',
                        'TICKET_RESUELTO_TOTAL',
                        'TICKET_RESUELTO_PARCIAL',
                        'TICKET_CANCELADO'
                    )
                ),
                CONSTRAINT chk_tickets_productos_correos_plantilla CHECK (
                    plantilla IN (
                        'ticket_created',
                        'line_approved',
                        'line_rejected',
                        'ticket_resolved',
                        'ticket_cancelled'
                    )
                ),
                CONSTRAINT chk_tickets_productos_correos_status CHECK (
                    status IN (
                        'PENDIENTE',
                        'ENVIANDO',
                        'ENVIADO',
                        'ERROR',
                        'CANCELADO'
                    )
                ),
                CONSTRAINT chk_tickets_productos_correos_email CHECK (
                    CHAR_LENGTH(TRIM(destinatario_email)) > 3
                    AND destinatario_email NOT REGEXP '[[:space:]]'
                    AND destinatario_email REGEXP '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'
                ),
                CONSTRAINT chk_tickets_productos_correos_subject CHECK (
                    CHAR_LENGTH(TRIM(subject)) BETWEEN 1 AND 190
                ),
                CONSTRAINT chk_tickets_productos_correos_intentos CHECK (
                    intentos <= max_intentos
                ),
                CONSTRAINT chk_tickets_productos_correos_fechas CHECK (
                    (status = 'ENVIADO' AND enviado_at IS NOT NULL AND cancelado_at IS NULL)
                    OR (status = 'CANCELADO' AND cancelado_at IS NOT NULL AND enviado_at IS NULL)
                    OR (status NOT IN ('ENVIADO', 'CANCELADO'))
                ),
                CONSTRAINT chk_tickets_productos_correos_dedupe CHECK (
                    CHAR_LENGTH(TRIM(dedupe_key)) BETWEEN 1 AND 190
                    AND dedupe_key NOT REGEXP '[[:space:]]'
                ),
                CONSTRAINT chk_tickets_productos_correos_no_sensitive CHECK (
                    LOWER(CONCAT_WS(' ', subject, error_mensaje_seguro, html, text)) NOT REGEXP
                    '(storage/private|storage/uploads|dsn|password|secret|token|[a-z]:[\\\\/])'
                ),
                CONSTRAINT fk_tickets_productos_correos_ticket
                    FOREIGN KEY (ticket_id) REFERENCES tickets_productos (id)
                    ON UPDATE RESTRICT ON DELETE CASCADE,
                CONSTRAINT fk_tickets_productos_correos_partida
                    FOREIGN KEY (partida_id) REFERENCES tickets_productos_partidas (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT fk_tickets_productos_correos_creado_por
                    FOREIGN KEY (creado_por_usuario_id) REFERENCES usuarios (id)
                    ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS tickets_productos_correos');
    }
};
