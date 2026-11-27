<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

return new class implements Migration {
    public function id(): string
    {
        return 'tp_partidas_estados_correo_outbox_1_004_extend_template_keys';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec(
            <<<'SQL'
            ALTER TABLE tickets_productos_correos
                DROP CHECK chk_tickets_productos_correos_plantilla,
                ADD CONSTRAINT chk_tickets_productos_correos_plantilla CHECK (
                    plantilla IN (
                        'ticket_created',
                        'line_approved',
                        'line_rejected',
                        'ticket_resolved',
                        'ticket_cancelled',
                        'ticket_activity'
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
                DROP CHECK chk_tickets_productos_correos_plantilla,
                ADD CONSTRAINT chk_tickets_productos_correos_plantilla CHECK (
                    plantilla IN (
                        'ticket_created',
                        'line_approved',
                        'line_rejected',
                        'ticket_resolved',
                        'ticket_cancelled'
                    )
                )
            SQL
        );
    }
};
