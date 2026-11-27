<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

return new class implements Migration {
    public function id(): string
    {
        return 'tp_partidas_estados_correo_config_1_003_clear_activity_static_recipients';
    }

    public function up(PDO $pdo): void
    {
        $events = ['COMENTARIO_AGREGADO', 'ADJUNTO_CARGADO'];
        $placeholders = implode(',', array_fill(0, count($events), '?'));

        $count = $pdo->prepare(
            "SELECT COUNT(*) FROM tickets_productos_correo_reglas WHERE evento IN ({$placeholders})"
        );
        $count->execute($events);
        if ((int) $count->fetchColumn() !== 2) {
            throw new RuntimeException(
                'Exactly two activity mail rules are required before recipient alignment.'
            );
        }

        $update = $pdo->prepare(
            "UPDATE tickets_productos_correo_reglas
             SET enviar_solicitante = 1,
                 enviar_responsables = 1,
                 to_json = NULL,
                 cc_json = NULL,
                 bcc_json = NULL
             WHERE evento IN ({$placeholders})"
        );
        $update->execute($events);
    }

    public function down(PDO $pdo): void
    {
        throw new RuntimeException(
            'This data migration is intentionally not reversible without the original static recipient payload.'
        );
    }
};
