<?php

declare(strict_types=1);

$base = dirname(__DIR__, 2);
$renderer = (string) file_get_contents($base . '/app/Domain/Mail/ProductTicketEmailTemplateRenderer.php');
$migration = (string) file_get_contents($base . '/database/migrations/tp_partidas_estados_correo_outbox_1_004_extend_template_keys.php');

$backendKeys = [
    'ticket_created',
    'line_approved',
    'line_rejected',
    'ticket_resolved',
    'ticket_cancelled',
    'ticket_activity',
];
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

foreach ($backendKeys as $key) {
    $assert(str_contains($renderer, "'{$key}'"), 'BACKEND_TEMPLATE_KEY_MISSING:' . $key);
    $assert(str_contains($migration, "'{$key}'"), 'DB_TEMPLATE_KEY_MISSING:' . $key);
}
$assert(
    str_contains($renderer, "'COMENTARIO_AGREGADO', 'ADJUNTO_CARGADO' => 'ticket_activity'")
    || str_contains($renderer, "'COMENTARIO_AGREGADO', 'ADJUNTO_CARGADO' => 'ticket_activity',"),
    'ACTIVITY_EVENT_TEMPLATE_MAPPING_MISSING'
);
$assert(substr_count($migration, "'ticket_activity'") === 1, 'ACTIVITY_TEMPLATE_NOT_EXACT');

echo "TP_FLOW_4B3_BACKEND_TEMPLATE_DB_CONTRACT_DB_FREE=PASS\n";
