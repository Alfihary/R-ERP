<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

return new class implements Migration {
    public function id(): string
    {
        return 'tp_partidas_estados_correo_config_1_002_extend_activity_events';
    }

    public function up(PDO $pdo): void
    {
        // One DDL statement replaces the allowlist without an intervening DML
        // window where an arbitrary event could be inserted.
        $pdo->exec(
            <<<'SQL'
            ALTER TABLE tickets_productos_correo_reglas
                DROP CHECK chk_tickets_productos_correo_reglas_evento,
                ADD CONSTRAINT chk_tickets_productos_correo_reglas_evento CHECK (
                    evento IN (
                        'TICKET_CREADO',
                        'PARTIDA_APROBADA',
                        'PARTIDA_RECHAZADA',
                        'TICKET_RESUELTO_TOTAL',
                        'TICKET_RESUELTO_PARCIAL',
                        'TICKET_CANCELADO',
                        'COMENTARIO_AGREGADO',
                        'ADJUNTO_CARGADO'
                    )
                )
            SQL
        );

        $source = $pdo->query(
            <<<'SQL'
            SELECT mail_account_id, to_json, cc_json, bcc_json
            FROM tickets_productos_correo_reglas
            WHERE evento = 'TICKET_CREADO'
            LIMIT 1
            SQL
        )->fetch(PDO::FETCH_ASSOC);

        if (!is_array($source)) {
            throw new RuntimeException(
                'The TICKET_CREADO mail rule is required to seed activity rules.'
            );
        }

        $insert = $pdo->prepare(
            <<<'SQL'
            INSERT INTO tickets_productos_correo_reglas (
                evento,
                mail_account_id,
                enviar_solicitante,
                enviar_responsables,
                to_json,
                cc_json,
                bcc_json,
                activo
            )
            SELECT
                :evento,
                source.mail_account_id,
                1,
                1,
                source.to_json,
                source.cc_json,
                source.bcc_json,
                1
            FROM tickets_productos_correo_reglas source
            WHERE source.evento = 'TICKET_CREADO'
              AND NOT EXISTS (
                  SELECT 1
                  FROM tickets_productos_correo_reglas existing
                  WHERE existing.evento = :existing_evento
              )
            LIMIT 1
            SQL
        );

        foreach (['COMENTARIO_AGREGADO', 'ADJUNTO_CARGADO'] as $event) {
            $insert->execute([
                'evento' => $event,
                'existing_evento' => $event,
            ]);
        }
    }

    public function down(PDO $pdo): void
    {
        $delete = $pdo->prepare(
            <<<'SQL'
            DELETE FROM tickets_productos_correo_reglas
            WHERE evento IN ('COMENTARIO_AGREGADO', 'ADJUNTO_CARGADO')
            SQL
        );
        $delete->execute();

        $pdo->exec(
            <<<'SQL'
            ALTER TABLE tickets_productos_correo_reglas
                DROP CHECK chk_tickets_productos_correo_reglas_evento,
                ADD CONSTRAINT chk_tickets_productos_correo_reglas_evento CHECK (
                    evento IN (
                        'TICKET_CREADO',
                        'PARTIDA_APROBADA',
                        'PARTIDA_RECHAZADA',
                        'TICKET_RESUELTO_TOTAL',
                        'TICKET_RESUELTO_PARCIAL',
                        'TICKET_CANCELADO'
                    )
                )
            SQL
        );
    }
};
