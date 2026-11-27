<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

return new class implements Migration {
    public function id(): string
    {
        return 'tp_partidas_estados_correo_outbox_1_003_extend_activity_events';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            <<<'SQL'
            ALTER TABLE tickets_productos_correos
                DROP CHECK chk_tickets_productos_correos_evento,
                ADD CONSTRAINT chk_tickets_productos_correos_evento CHECK (
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
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec(
            <<<'SQL'
            ALTER TABLE tickets_productos_correos
                DROP CHECK chk_tickets_productos_correos_evento,
                ADD CONSTRAINT chk_tickets_productos_correos_evento CHECK (
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
