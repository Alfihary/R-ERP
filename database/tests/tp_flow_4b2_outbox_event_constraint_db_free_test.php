<?php

declare(strict_types=1);

$base = dirname(__DIR__, 2);
$migrationPath = $base . '/database/migrations/tp_partidas_estados_correo_outbox_1_003_extend_activity_events.php';
$source = (string) file_get_contents($migrationPath);
$upSource = explode('public function down', $source, 2)[0];

$expected = [
    'TICKET_CREADO',
    'PARTIDA_APROBADA',
    'PARTIDA_RECHAZADA',
    'TICKET_RESUELTO_TOTAL',
    'TICKET_RESUELTO_PARCIAL',
    'TICKET_CANCELADO',
    'COMENTARIO_AGREGADO',
    'ADJUNTO_CARGADO',
];

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(is_file($migrationPath), 'OUTBOX_MIGRATION_MISSING');
$assert(str_contains($source, "tp_partidas_estados_correo_outbox_1_003_extend_activity_events"), 'MIGRATION_ID_MISSING');
$assert(substr_count($source, 'ALTER TABLE tickets_productos_correos') === 2, 'UNEXPECTED_ALTER_TABLE_COUNT');
$assert(substr_count($source, 'DROP CHECK chk_tickets_productos_correos_evento') === 2, 'CHECK_DROP_CONTRACT_FAILED');
$assert(substr_count($source, 'ADD CONSTRAINT chk_tickets_productos_correos_evento') === 2, 'CHECK_ADD_CONTRACT_FAILED');
$assert(!str_contains($source, 'INSERT INTO'), 'UNEXPECTED_DML');
$assert(!str_contains($source, 'UPDATE '), 'UNEXPECTED_UPDATE');
$assert(!str_contains($source, 'DELETE '), 'UNEXPECTED_DELETE');

foreach ($expected as $event) {
    $assert(substr_count($upSource, "'{$event}'") === 1, 'EVENT_MISSING_OR_DUPLICATED:' . $event);
}

$assert(!str_contains($upSource, 'CORREO_ENVIADO'), 'UNAPPROVED_EVENT_PRESENT');
$assert(!str_contains($upSource, 'CORREO_FALLIDO'), 'UNAPPROVED_EVENT_PRESENT');
$assert(!str_contains($upSource, 'status'), 'UNRELATED_STATUS_STRUCTURE_CHANGE');
$assert(!str_contains($upSource, 'dedupe_key'), 'UNRELATED_DEDUPE_STRUCTURE_CHANGE');
$assert(!str_contains($upSource, 'mail_account_id'), 'UNRELATED_MAIL_ACCOUNT_STRUCTURE_CHANGE');
$assert(!str_contains($upSource, 'FOREIGN KEY'), 'UNRELATED_FOREIGN_KEY_CHANGE');

echo "TP_FLOW_4B2_OUTBOX_EVENT_CONSTRAINT_DB_FREE=PASS\n";
