<?php

declare(strict_types=1);

/**
 * DB-free contract checks for ticket/outbox transaction ownership.
 * This test intentionally inspects source contracts only; it never boots PDO.
 */

$base = dirname(__DIR__, 2);
$service = file_get_contents($base . '/app/Domain/Tickets/ProductRequestTicketService.php');
$notification = file_get_contents($base . '/app/Domain/Tickets/ProductTicketEmailNotificationService.php');
$outbox = file_get_contents($base . '/app/Domain/Tickets/ProductTicketEmailOutboxService.php');

if (!is_string($service) || !is_string($notification) || !is_string($outbox)) {
    throw new RuntimeException('Atomicity DB-free sources could not be read.');
}

$functionBody = static function (string $source, string $name): string {
    $pattern = '/public function ' . preg_quote($name, '/') . '\\s*\\([^)]*\\).*?\\n    \\}/s';
    if (preg_match($pattern, $source, $matches) !== 1) {
        throw new RuntimeException('Atomicity function not found: ' . $name);
    }

    return $matches[0];
};

$create = $functionBody($service, 'crearTicket');
$resolve = $functionBody($service, 'resolverPartida');
$cancel = $functionBody($service, 'cancelarTicket');
$comment = $functionBody($service, 'agregarComentario');
$attachment = $functionBody($service, 'agregarAdjunto');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(str_contains($create, 'this->notify('), 'Create must call notify.');
$assert(str_contains($resolve, 'this->notify('), 'Resolve must call notify.');
$assert(str_contains($cancel, 'this->notify('), 'Cancel must call notify.');
$assert(str_contains($create, "'TICKET_CREADO'"), 'Create event contract is missing.');
$assert(str_contains($resolve, "'PARTIDA_APROBADA'") && str_contains($resolve, "'PARTIDA_RECHAZADA'"), 'Resolve events are incomplete.');
$assert(str_contains($cancel, "'TICKET_CANCELADO'"), 'Cancel event contract is missing.');
$assert(str_contains($comment, 'this->notify('), 'Comments must create policy-controlled notifications.');
$assert(str_contains($attachment, 'this->notify('), 'Attachments must create policy-controlled notifications.');
$assert(str_contains($comment, 'No se pueden agregar comentarios a un ticket cancelado.'), 'Cancelled comments must be rejected.');

$assert(!preg_match('/catch\\s*\\(\\s*Throwable/', $notification), 'Notification must not swallow hard failures.');
$assert(str_contains($notification, "'no_active_mail_account'"), 'Legitimate skip contract is missing.');
$assert(str_contains($outbox, 'enqueueConfiguredInternal'), 'Outbox enqueue contract is missing.');
$assert(str_contains($outbox, "'no_valid_recipients'"), 'No-recipient skip contract is missing.');
$assert(!str_contains($outbox, 'beginTransaction('), 'Outbox service must not begin transactions.');
$assert(!str_contains($outbox, '->commit('), 'Outbox service must not commit transactions.');
$assert(!str_contains($outbox, '->rollBack('), 'Outbox service must not roll back transactions.');
$assert(str_contains($outbox, 'dedupeKey('), 'Dedupe key contract is missing.');

echo "PASS tickets_productos_partidas_estados_correo_atomicity_db_free\n";
