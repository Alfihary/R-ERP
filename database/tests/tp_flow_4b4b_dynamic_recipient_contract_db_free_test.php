<?php

declare(strict_types=1);

$base = dirname(__DIR__, 2);
$outbox = (string) file_get_contents($base . '/app/Domain/Tickets/ProductTicketEmailOutboxService.php');
$ticket = (string) file_get_contents($base . '/app/Domain/Tickets/ProductRequestTicketService.php');
$migration = (string) file_get_contents($base . '/database/migrations/tp_partidas_estados_correo_config_1_003_clear_activity_static_recipients.php');
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

foreach ([
    "'to_json'" => 'STATIC_TO_INPUT_SUPPORTED',
    "'cc_json'" => 'STATIC_CC_INPUT_SUPPORTED',
    "'bcc_json'" => 'STATIC_BCC_INPUT_SUPPORTED',
    "'enviar_solicitante'" => 'REQUESTER_FLAG_SUPPORTED',
    "'enviar_responsables'" => 'RESPONSIBLE_FLAG_SUPPORTED',
    "recipientMode !== 'RESPONSIBLES'" => 'REQUESTER_DIRECTION_SUPPORTED',
    'responsibleEmail' => 'RESPONSIBLE_DIRECTION_SUPPORTED',
    'actorEmail' => 'ACTOR_EXCLUSION_SUPPORTED',
    'array_filter' => 'RECIPIENT_DEDUPE_SUPPORTED',
    'to_json = NULL' => 'NEUTRAL_TO_CONFIGURED',
    'cc_json = NULL' => 'NEUTRAL_CC_CONFIGURED',
    'bcc_json = NULL' => 'NEUTRAL_BCC_CONFIGURED',
] as $needle => $label) {
    $assert(str_contains($outbox . $migration, $needle), $label);
}

$assert(str_contains($ticket, "'RESPONSIBLES'"), 'RESPONSIBLES_MODE_MISSING');
$assert(str_contains($ticket, "'REQUESTER'"), 'REQUESTER_MODE_MISSING');
$assert(str_contains($ticket, "? 'RESPONSIBLES'"), 'REQUESTER_TO_RESPONSIBLES_DIRECTION_MISSING');
$assert(str_contains($ticket, ": 'REQUESTER'"), 'RESPONSIBLE_TO_REQUESTER_DIRECTION_MISSING');
$assert(str_contains($outbox, 'if ($to === [] && $cc !== [])'), 'ZERO_RECIPIENT_GUARD_MISSING');
$assert(str_contains($outbox, 'return [\'to\' => $to, \'cc\' => $cc, \'bcc\' => $bcc]'), 'RECIPIENT_ENVELOPE_MISSING');

echo "TP_FLOW_4B4B_DYNAMIC_RECIPIENT_CONTRACT_DB_FREE=PASS\n";
