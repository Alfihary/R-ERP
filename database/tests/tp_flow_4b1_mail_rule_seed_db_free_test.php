<?php

declare(strict_types=1);

$base = dirname(__DIR__, 2);
$migrationPath = $base
    . '/database/migrations/tp_partidas_estados_correo_config_1_002_extend_activity_events.php';
$source = file_get_contents($migrationPath);

if (!is_string($source)) {
    throw new RuntimeException('4B1 mail rule migration could not be read.');
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(str_contains($source, 'enviar_solicitante'), 'Requester flag is missing.');
$assert(str_contains($source, 'enviar_responsables'), 'Responsible flag is missing.');
$assert(str_contains($source, '1,') && substr_count($source, 'enviar_solicitante') >= 1 && substr_count($source, 'enviar_responsables') >= 1, 'Both activity recipient flags must be represented.');
$assert(str_contains($source, 'source.to_json'), 'Existing configured recipient envelope is not reused.');
$assert(str_contains($source, 'source.cc_json'), 'Existing CC envelope is not preserved.');
$assert(str_contains($source, 'source.bcc_json'), 'Existing BCC envelope is not preserved.');
$assert(str_contains($source, 'source.mail_account_id'), 'Existing TICKETS_PRODUCTOS account is not reused.');
$assert(substr_count($source, "'COMENTARIO_AGREGADO'") >= 2, 'Comment rule seed is missing.');
$assert(substr_count($source, "'ADJUNTO_CARGADO'") >= 2, 'Attachment rule seed is missing.');
$assert(str_contains($source, 'NOT EXISTS'), 'Natural-key idempotency is missing.');
$assert(!str_contains($source, 'INSERT INTO mail_accounts'), 'A second SMTP account must not be created.');

echo "PASS tp_flow_4b1_mail_rule_seed_db_free\n";
