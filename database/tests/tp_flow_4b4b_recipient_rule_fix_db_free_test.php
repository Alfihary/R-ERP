<?php

declare(strict_types=1);

$base = dirname(__DIR__, 2);
$path = $base . '/database/migrations/tp_partidas_estados_correo_config_1_003_clear_activity_static_recipients.php';
$source = (string) file_get_contents($path);
$upSource = explode('public function down', $source, 2)[0];
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(is_file($path), 'MIGRATION_MISSING');
$assert(str_contains($source, 'tp_partidas_estados_correo_config_1_003_clear_activity_static_recipients'), 'MIGRATION_ID_MISSING');
$assert(substr_count($upSource, 'COMENTARIO_AGREGADO') === 1, 'COMMENT_TARGET_SCOPE_INVALID');
$assert(substr_count($upSource, 'ADJUNTO_CARGADO') === 1, 'ATTACHMENT_TARGET_SCOPE_INVALID');
$assert(str_contains($upSource, 'WHERE evento IN'), 'EXACT_EVENT_WHERE_MISSING');
$assert(str_contains($upSource, 'to_json = NULL'), 'STATIC_TO_NOT_CLEARED');
$assert(str_contains($upSource, 'cc_json = NULL'), 'STATIC_CC_NOT_CLEARED');
$assert(str_contains($upSource, 'bcc_json = NULL'), 'STATIC_BCC_NOT_CLEARED');
$assert(str_contains($upSource, 'enviar_solicitante = 1'), 'REQUESTER_FLAG_NOT_SET');
$assert(str_contains($upSource, 'enviar_responsables = 1'), 'RESPONSIBLE_FLAG_NOT_SET');
$assert(!str_contains($upSource, 'mail_account_id'), 'MAIL_ACCOUNT_MUST_REMAIN_UNCHANGED');
$assert(!str_contains($upSource, 'activo'), 'ACTIVE_FLAG_MUST_REMAIN_UNCHANGED');
$assert(!str_contains($upSource, 'ALTER TABLE'), 'SCHEMA_CHANGE_FORBIDDEN');
$assert(!str_contains($upSource, 'CREATE TABLE'), 'SCHEMA_CHANGE_FORBIDDEN');
$assert(!str_contains($upSource, 'INSERT INTO'), 'RULE_INSERT_FORBIDDEN');
$assert(!str_contains($upSource, 'DELETE '), 'RULE_DELETE_FORBIDDEN');
$assert(!preg_match('/@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $upSource), 'HARDCODED_EMAIL_FORBIDDEN');

echo "TP_FLOW_4B4B_RECIPIENT_RULE_FIX_DB_FREE=PASS\n";
