<?php

declare(strict_types=1);

$base = dirname(__DIR__, 2);
$ruleSource = (string) file_get_contents($base . '/database/migrations/tp_partidas_estados_correo_config_1_002_extend_activity_events.php');
$outboxSource = (string) file_get_contents($base . '/database/migrations/tp_partidas_estados_correo_outbox_1_003_extend_activity_events.php');

$events = [
    'TICKET_CREADO',
    'PARTIDA_APROBADA',
    'PARTIDA_RECHAZADA',
    'TICKET_RESUELTO_TOTAL',
    'TICKET_RESUELTO_PARCIAL',
    'TICKET_CANCELADO',
    'COMENTARIO_AGREGADO',
    'ADJUNTO_CARGADO',
];

$extract = static function (string $source, string $constraint): array {
    $pattern = '/ADD CONSTRAINT\s+' . preg_quote($constraint, '/') . '\s+CHECK\s*\(\s*evento\s+IN\s*\((.*?)\)\s*\)/is';
    if (preg_match($pattern, $source, $match) !== 1) {
        throw new RuntimeException('CHECK_NOT_FOUND:' . $constraint);
    }
    preg_match_all("/'([A-Z_]+)'/", $match[1], $values);
    return $values[1];
};

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$ruleEvents = $extract($ruleSource, 'chk_tickets_productos_correo_reglas_evento');
$outboxEvents = $extract($outboxSource, 'chk_tickets_productos_correos_evento');
$assert($ruleEvents === $events, 'RULE_EVENT_CONTRACT_MISMATCH');
$assert($outboxEvents === $events, 'OUTBOX_EVENT_CONTRACT_MISMATCH');
$assert($ruleEvents === $outboxEvents, 'CROSS_EVENT_CONTRACT_MISMATCH');

echo "TP_FLOW_4B2_MAIL_EVENT_CROSS_CONTRACT_DB_FREE=PASS\n";
