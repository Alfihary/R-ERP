<?php

declare(strict_types=1);

$base = dirname(__DIR__, 2);
$migrationPath = $base . '/database/migrations/tp_partidas_estados_correo_outbox_1_004_extend_template_keys.php';
$source = (string) file_get_contents($migrationPath);
$upSource = explode('public function down', $source, 2)[0];
$expected = [
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

$assert(is_file($migrationPath), 'MIGRATION_MISSING');
$assert(str_contains($source, 'tp_partidas_estados_correo_outbox_1_004_extend_template_keys'), 'MIGRATION_ID_MISSING');
$assert(substr_count($source, 'ALTER TABLE tickets_productos_correos') === 2, 'UNEXPECTED_ALTER_TABLE_COUNT');
$assert(substr_count($source, 'DROP CHECK chk_tickets_productos_correos_plantilla') === 2, 'CHECK_DROP_MISSING');
$assert(substr_count($source, 'ADD CONSTRAINT chk_tickets_productos_correos_plantilla') === 2, 'CHECK_ADD_MISSING');
$assert(!str_contains($upSource, 'INSERT INTO'), 'UNEXPECTED_DML');
$assert(!str_contains($upSource, 'UPDATE '), 'UNEXPECTED_UPDATE');
$assert(!str_contains($upSource, 'DELETE '), 'UNEXPECTED_DELETE');
foreach ($expected as $key) {
    $assert(substr_count($upSource, "'{$key}'") === 1, 'TEMPLATE_KEY_MISSING_OR_DUPLICATED:' . $key);
}
$assert(!str_contains($upSource, 'ticket_comment'), 'UNSUPPORTED_TEMPLATE_PRESENT');
$assert(!str_contains($upSource, 'ticket_attachment'), 'UNSUPPORTED_TEMPLATE_PRESENT');
$assert(!str_contains($upSource, 'evento'), 'UNRELATED_EVENT_STRUCTURE_CHANGE');
$assert(!str_contains($upSource, 'status'), 'UNRELATED_STATUS_STRUCTURE_CHANGE');
$assert(!str_contains($upSource, 'dedupe_key'), 'UNRELATED_DEDUPE_STRUCTURE_CHANGE');
$assert(!str_contains($upSource, 'FOREIGN KEY'), 'UNRELATED_FOREIGN_KEY_CHANGE');

echo "TP_FLOW_4B3_OUTBOX_TEMPLATE_CONSTRAINT_DB_FREE=PASS\n";
