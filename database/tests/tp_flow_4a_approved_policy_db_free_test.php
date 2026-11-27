<?php

declare(strict_types=1);

$base = dirname(__DIR__, 2);

spl_autoload_register(static function (string $class) use ($base): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }

    $path = $base . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

$read = static function (string $path) use ($base): string {
    $source = file_get_contents($base . '/' . $path);
    if (!is_string($source)) {
        throw new RuntimeException('Could not read ' . $path);
    }

    return $source;
};

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$serviceSource = $read('app/Domain/Tickets/ProductRequestTicketService.php');
$notificationSource = $read('app/Domain/Tickets/ProductTicketEmailNotificationService.php');
$outboxSource = $read('app/Domain/Tickets/ProductTicketEmailOutboxService.php');
$mailConfigSource = $read('app/Domain/Mail/MailConfigurationService.php');

$assert(str_contains($mailConfigSource, "'COMENTARIO_AGREGADO'"), 'Comment notification key is missing.');
$assert(str_contains($mailConfigSource, "'ADJUNTO_CARGADO'"), 'Attachment notification key is missing.');
$assert(str_contains($serviceSource, 'No se pueden agregar comentarios a un ticket cancelado.'), 'Cancelled comment guard is missing.');
$assert(str_contains($serviceSource, "'COMENTARIO_AGREGADO'"), 'Comment notification is not wired.');
$assert(str_contains($serviceSource, "'ADJUNTO_CARGADO'"), 'Attachment notification is not wired.');
$assert(str_contains($serviceSource, "elseif (\$event === 'PARTIDA_RECHAZADA')"), 'Non-final rejection notification is missing.');
$assert(str_contains($serviceSource, '$eventNotifications = [];'), 'Final resolution notification consolidation is missing.');
$assert(str_contains($notificationSource, 'array $activity = []'), 'Activity payload contract is missing.');
$assert(str_contains($outboxSource, ':ocurrencia:'), 'Activity occurrence dedupe is missing.');

$builder = new App\Domain\Mail\ProductTicketEmailTemplatePayloadBuilder(
    'http://localhost',
    'UTC',
    true
);
$renderer = new App\Domain\Mail\ProductTicketEmailTemplateRenderer();
$ticket = [
    'id' => 901,
    'folio' => 'BO-000901',
    'estado' => 'EN_REVISION',
    'created_at' => '2026-11-27 12:00:00',
    'cancelado_at' => null,
    'empresa_nombre' => 'Empresa QA',
    'almacen_nombre' => 'Almacén QA',
    'observaciones_generales' => 'Prueba',
    'partidas_aprobadas' => 0,
    'partidas_rechazadas' => 0,
    'total_partidas' => 1,
    'motivo_cancelacion' => null,
    'cancelado_por_username' => null,
    'solicitante_username' => 'solicitante',
];

$commentPayload = $builder->build(
    'COMENTARIO_AGREGADO',
    $ticket,
    [],
    0,
    ['actor_name' => 'responsable@example.invalid', 'text' => 'Comentario QA']
);
$commentEmail = $renderer->render('COMENTARIO_AGREGADO', $commentPayload);
$assert($commentEmail->subject === '[R-ERP] Ticket BO-000901: comentario nuevo', 'Comment subject is invalid.');
$assert(str_contains($commentEmail->textBody, 'Comentario QA'), 'Comment payload is missing from rendered text.');

$attachmentPayload = $builder->build(
    'ADJUNTO_CARGADO',
    $ticket,
    [],
    1,
    ['actor_name' => 'solicitante@example.invalid', 'filename' => 'evidencia.pdf']
);
$attachmentEmail = $renderer->render('ADJUNTO_CARGADO', $attachmentPayload);
$assert($attachmentEmail->subject === '[R-ERP] Ticket BO-000901: adjunto nuevo', 'Attachment subject is invalid.');
$assert(str_contains($attachmentEmail->textBody, 'evidencia.pdf'), 'Attachment filename is missing from rendered text.');
$assert(!str_contains($attachmentEmail->textBody, 'storage/private'), 'Physical storage path leaked into email payload.');

$outbox = (new ReflectionClass(App\Domain\Tickets\ProductTicketEmailOutboxService::class))
    ->newInstanceWithoutConstructor();
$dedupe = new ReflectionMethod($outbox, 'dedupeKey');
$dedupe->setAccessible(true);
$commentKeyA = $dedupe->invoke($outbox, 901, null, 'COMENTARIO_AGREGADO', 1);
$commentKeyB = $dedupe->invoke($outbox, 901, null, 'COMENTARIO_AGREGADO', 2);
$attachmentKey = $dedupe->invoke($outbox, 901, null, 'ADJUNTO_CARGADO', 1);
$assert($commentKeyA !== $commentKeyB, 'Multiple comments must have independent dedupe keys.');
$assert($commentKeyA !== $attachmentKey, 'Comment and attachment dedupe keys must differ.');

$recipients = new ReflectionMethod($outbox, 'resolveRecipients');
$recipients->setAccessible(true);
$ticketRecipients = ['solicitante_email' => 'solicitante@example.invalid'];
$rule = [
    'enviar_solicitante' => 1,
    'enviar_responsables' => 0,
    'to_json' => '["actor@example.invalid","responsable@example.invalid"]',
    'cc_json' => null,
    'bcc_json' => null,
];
$resolved = $recipients->invoke($outbox, $ticketRecipients, $rule, null, 'REQUESTER', 'actor@example.invalid');
$assert(!in_array('actor@example.invalid', $resolved['to'], true), 'Actor must be excluded from recipients.');
$assert(in_array('solicitante@example.invalid', $resolved['to'], true), 'Requester must remain the primary recipient.');

echo "PASS tp_flow_4a_approved_policy_db_free\n";
