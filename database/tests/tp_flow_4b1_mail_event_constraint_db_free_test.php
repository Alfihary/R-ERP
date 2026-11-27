<?php

declare(strict_types=1);

$base = dirname(__DIR__, 2);
$migrationPath = $base
    . '/database/migrations/tp_partidas_estados_correo_config_1_002_extend_activity_events.php';
$source = file_get_contents($migrationPath);

if (!is_string($source)) {
    throw new RuntimeException('4B1 migration could not be read.');
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$historical = [
    'TICKET_CREADO',
    'PARTIDA_APROBADA',
    'PARTIDA_RECHAZADA',
    'TICKET_RESUELTO_TOTAL',
    'TICKET_RESUELTO_PARCIAL',
    'TICKET_CANCELADO',
];
$activity = ['COMENTARIO_AGREGADO', 'ADJUNTO_CARGADO'];
$allowed = array_merge($historical, $activity);

$assert(str_contains($source, 'tp_partidas_estados_correo_config_1_002_extend_activity_events'), 'Migration id is missing.');
$assert(str_contains($source, 'DROP CHECK chk_tickets_productos_correo_reglas_evento'), 'The expected CHECK is not replaced.');
$assert(substr_count($source, 'ADD CONSTRAINT chk_tickets_productos_correo_reglas_evento CHECK') === 2, 'Up and down CHECK contracts are incomplete.');
$assert(str_contains($source, 'ALTER TABLE tickets_productos_correo_reglas'), 'Unexpected table target.');
$assert(!str_contains($source, 'ADD COLUMN'), 'The migration must not add columns.');
$assert(!str_contains($source, 'DROP TABLE'), 'The migration must not change table structure.');

foreach ($allowed as $event) {
    $assert(substr_count($source, "'$event'") >= 2, 'Event is missing from up/down contract: ' . $event);
}

$assert(str_contains($source, "WHERE evento = 'TICKET_CREADO'"), 'The existing functional rule is not reused.');
$assert(str_contains($source, 'NOT EXISTS'), 'Rule seed is not idempotent by natural key.');
$assert(str_contains($source, 'source.mail_account_id'), 'Mail account is not reused from the existing rule.');
$assert(str_contains($source, "'COMENTARIO_AGREGADO', 'ADJUNTO_CARGADO'"), 'Activity seed events are incomplete.');

foreach (['password', 'secret', 'token', '@gruporefrigerantes', '@example'] as $forbidden) {
    $assert(!str_contains(strtolower($source), $forbidden), 'Sensitive or personal recipient literal found: ' . $forbidden);
}

echo "PASS tp_flow_4b1_mail_event_constraint_db_free\n";
