<?php

declare(strict_types=1);

namespace App\Domain\Tickets;

use App\Infrastructure\Repositories\ProductTicketEmailOutboxRepository;
use RuntimeException;

final class ProductTicketEmailOutboxService
{
    private const EVENT_TEMPLATES = [
        'TICKET_CREADO' => 'ticket_created',
        'PARTIDA_APROBADA' => 'line_approved',
        'PARTIDA_RECHAZADA' => 'line_rejected',
        'TICKET_RESUELTO_TOTAL' => 'ticket_resolved',
        'TICKET_RESUELTO_PARCIAL' => 'ticket_resolved',
        'TICKET_CANCELADO' => 'ticket_cancelled',
    ];

    private const TEMPLATE_SUBJECTS = [
        'ticket_created' => 'Solicitud de alta de producto {folio} recibida',
        'line_approved' => 'Partida aprobada en solicitud {folio}',
        'line_rejected' => 'Partida rechazada en solicitud {folio}',
        'ticket_resolved' => 'Solicitud de alta de producto {folio} resuelta',
        'ticket_cancelled' => 'Solicitud de alta de producto {folio} cancelada',
    ];

    public function __construct(private readonly ProductTicketEmailOutboxRepository $outbox)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function enqueueTicketCreated(int $ticketId, int $usuarioId): ?array
    {
        return $this->enqueue([
            'ticket_id' => $ticketId,
            'partida_id' => null,
            'evento' => 'TICKET_CREADO',
            'creado_por_usuario_id' => $usuarioId,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function enqueueLineApproved(int $ticketId, int $partidaId, int $usuarioId): ?array
    {
        return $this->enqueue([
            'ticket_id' => $ticketId,
            'partida_id' => $partidaId,
            'evento' => 'PARTIDA_APROBADA',
            'creado_por_usuario_id' => $usuarioId,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function enqueueLineRejected(int $ticketId, int $partidaId, int $usuarioId): ?array
    {
        return $this->enqueue([
            'ticket_id' => $ticketId,
            'partida_id' => $partidaId,
            'evento' => 'PARTIDA_RECHAZADA',
            'creado_por_usuario_id' => $usuarioId,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function enqueueTicketResolved(int $ticketId, string $evento, int $usuarioId): ?array
    {
        return $this->enqueue([
            'ticket_id' => $ticketId,
            'partida_id' => null,
            'evento' => $evento,
            'creado_por_usuario_id' => $usuarioId,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function enqueueTicketCancelled(int $ticketId, int $usuarioId): ?array
    {
        return $this->enqueue([
            'ticket_id' => $ticketId,
            'partida_id' => null,
            'evento' => 'TICKET_CANCELADO',
            'creado_por_usuario_id' => $usuarioId,
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|null
     */
    public function enqueue(array $input): ?array
    {
        $result = $this->enqueueConfigured($input, [
            'enviar_solicitante' => 1,
            'enviar_responsables' => 0,
            'to_json' => null,
            'cc_json' => null,
            'bcc_json' => null,
        ]);

        return is_array($result['outbox']) ? $result['outbox'] : null;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $rule
     * @return array{notification_enqueued:bool,notification_reason:string,outbox:array<string,mixed>|null}
     */
    public function enqueueConfigured(array $input, array $rule): array
    {
        $ticketId = $this->positiveId($input['ticket_id'] ?? null, 'ticket_id');
        $partidaId = $this->nullablePositiveId($input['partida_id'] ?? null, 'partida_id');
        $event = strtoupper(trim((string) ($input['evento'] ?? '')));
        $createdBy = $this->nullablePositiveId($input['creado_por_usuario_id'] ?? null, 'creado_por_usuario_id');

        if (!isset(self::EVENT_TEMPLATES[$event])) {
            throw new RuntimeException('Unsupported ticket product email event.');
        }

        if (
            in_array($event, ['PARTIDA_APROBADA', 'PARTIDA_RECHAZADA'], true)
            && $partidaId === null
        ) {
            throw new RuntimeException('Line email events require partida_id.');
        }

        $ticket = $this->outbox->findTicketContext($ticketId);

        if ($ticket === null) {
            throw new RuntimeException('Ticket was not found for email outbox.');
        }

        $line = null;
        if ($partidaId !== null) {
            $line = $this->outbox->findLineContext($ticketId, $partidaId);

            if ($line === null) {
                throw new RuntimeException('Ticket line was not found for email outbox.');
            }
        }

        $recipients = $this->resolveRecipients(
            $ticket,
            $rule,
            $input['responsable_email'] ?? null
        );

        if ($recipients['to'] === []) {
            return [
                'notification_enqueued' => false,
                'notification_reason' => 'no_valid_recipients',
                'outbox' => null,
            ];
        }

        $template = self::EVENT_TEMPLATES[$event];
        $dedupeKey = $this->dedupeKey($ticketId, $partidaId, $event);
        $existing = $this->outbox->findByDedupeKey($dedupeKey);

        if ($existing !== null) {
            return [
                'notification_enqueued' => false,
                'notification_reason' => 'duplicate_dedupe_key',
                'outbox' => $existing,
            ];
        }

        $payload = $this->payload($ticket, $line, $event, $template);

        $this->assertSafeText($payload['subject'] . "\n" . $payload['html'] . "\n" . $payload['text']);

        $row = $this->outbox->insertPending([
            'ticket_id' => $ticketId,
            'partida_id' => $partidaId,
            'evento' => $event,
            'plantilla' => $template,
            'destinatario_email' => $recipients['to'][0],
            'cc_json' => json_encode($recipients, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'subject' => $payload['subject'],
            'html' => $payload['html'],
            'text' => $payload['text'],
            'max_intentos' => 3,
            'creado_por_usuario_id' => $createdBy,
            'dedupe_key' => $dedupeKey,
        ]);

        return [
            'notification_enqueued' => true,
            'notification_reason' => 'pending_created',
            'outbox' => $row,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function markSent(int $outboxId): array
    {
        return $this->outbox->markSent($this->positiveId($outboxId, 'outbox_id'));
    }

    /**
     * @return array<string, mixed>
     */
    public function markError(int $outboxId, string $safeMessage): array
    {
        $message = trim($safeMessage);

        if ($message === '' || mb_strlen($message, 'UTF-8') > 500) {
            throw new RuntimeException('Safe email error message is required.');
        }

        $this->assertSafeText($message);

        return $this->outbox->markError($this->positiveId($outboxId, 'outbox_id'), $message);
    }

    /**
     * @param array<string, mixed> $ticket
     * @param array<string, mixed>|null $line
     * @return array{subject:string, html:string, text:string}
     */
    private function payload(array $ticket, ?array $line, string $event, string $template): array
    {
        $folio = trim((string) ($ticket['folio'] ?? ''));
        $subject = str_replace('{folio}', $folio, self::TEMPLATE_SUBJECTS[$template]);
        if ($event === 'TICKET_RESUELTO_PARCIAL') {
            $subject = 'Solicitud de alta de producto ' . $folio . ' resuelta parcialmente';
        }
        $company = trim((string) ($ticket['empresa_nombre'] ?? ''));
        $warehouse = trim((string) ($ticket['almacen_nombre'] ?? $ticket['almacen_codigo'] ?? ''));
        $username = trim((string) ($ticket['solicitante_username'] ?? ''));
        $state = $line !== null ? (string) ($line['estado'] ?? '') : (string) ($ticket['estado'] ?? '');
        $description = $line !== null
            ? trim((string) ($line['descripcion'] ?? ''))
            : 'Solicitud documental de alta de productos.';
        $lineNumber = $line !== null ? (string) ($line['numero_partida'] ?? '') : '';
        $detailUrl = '/tickets/productos/' . (int) ($ticket['id'] ?? 0);

        $textLines = [
            $subject,
            'Folio: ' . $folio,
            'Evento: ' . $event,
            'Empresa: ' . $company,
            'Almacén: ' . $warehouse,
            'Solicitante: ' . $username,
            'Estado: ' . $state,
            $lineNumber !== '' ? 'Partida: ' . $lineNumber : null,
            'Resumen: ' . $description,
            'Detalle interno: ' . $detailUrl,
            'Este es un aviso automático; no respondas este correo.',
        ];

        $text = implode("\n", array_values(array_filter($textLines, static fn (?string $line): bool => $line !== null)));
        $html = '<article class="mail-card">'
            . '<h1>' . $this->escape($subject) . '</h1>'
            . '<p>Folio: <strong>' . $this->escape($folio) . '</strong></p>'
            . '<p>Evento: ' . $this->escape($event) . '</p>'
            . '<p>Empresa: ' . $this->escape($company) . '</p>'
            . '<p>Almacén: ' . $this->escape($warehouse) . '</p>'
            . '<p>Solicitante: ' . $this->escape($username) . '</p>'
            . '<p>Estado: ' . $this->escape($state) . '</p>'
            . ($lineNumber !== '' ? '<p>Partida: ' . $this->escape($lineNumber) . '</p>' : '')
            . '<p>Resumen: ' . $this->escape($description) . '</p>'
            . '<p><a href="' . $this->escape($detailUrl) . '">Ver detalle interno</a></p>'
            . '<p>Este es un aviso automático; no respondas este correo.</p>'
            . '</article>';

        return [
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
        ];
    }

    private function dedupeKey(int $ticketId, ?int $partidaId, string $event): string
    {
        return 'ticket:' . $ticketId . ':partida:' . ($partidaId === null ? 'null' : (string) $partidaId)
            . ':evento:' . $event;
    }

    /**
     * `cc_json` is the existing outbox envelope field. It stores the complete
     * normalized TO/CC/BCC envelope while destinatario_email remains the
     * primary TO for backwards compatibility.
     *
     * @param array<string, mixed> $ticket
     * @param array<string, mixed> $rule
     * @return array{to:list<string>,cc:list<string>,bcc:list<string>}
     */
    private function resolveRecipients(array $ticket, array $rule, mixed $responsibleEmail): array
    {
        $to = $this->jsonEmails($rule['to_json'] ?? null);
        $cc = $this->jsonEmails($rule['cc_json'] ?? null);
        $bcc = $this->jsonEmails($rule['bcc_json'] ?? null);

        if ((int) ($rule['enviar_solicitante'] ?? 0) === 1) {
            $this->appendEmail($to, $ticket['solicitante_email'] ?? null);
        }
        if ((int) ($rule['enviar_responsables'] ?? 0) === 1) {
            $this->appendEmail($cc, $responsibleEmail);
        }

        $seen = [];
        foreach (['to' => &$to, 'cc' => &$cc, 'bcc' => &$bcc] as &$emails) {
            $emails = array_values(array_filter($emails, static function (string $email) use (&$seen): bool {
                if (isset($seen[$email])) {
                    return false;
                }
                $seen[$email] = true;
                return true;
            }));
        }
        unset($emails);

        if ($to === [] && $cc !== []) {
            $to[] = array_shift($cc);
        }
        if ($to === [] && $bcc !== []) {
            $to[] = array_shift($bcc);
        }

        return ['to' => $to, 'cc' => $cc, 'bcc' => $bcc];
    }

    /** @return list<string> */
    private function jsonEmails(mixed $json): array
    {
        if (!is_string($json) || trim($json) === '') {
            return [];
        }

        try {
            $values = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($values)) {
            return [];
        }

        $emails = [];
        foreach ($values as $value) {
            $this->appendEmail($emails, $value);
        }

        return $emails;
    }

    /** @param list<string> $emails */
    private function appendEmail(array &$emails, mixed $value): void
    {
        $email = strtolower(trim((string) $value));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $emails[$email] = $email;
        }
    }

    private function positiveId(mixed $value, string $field): int
    {
        if ((!is_int($value) && !is_string($value)) || preg_match('/^[1-9]\d*$/', (string) $value) !== 1) {
            throw new RuntimeException($field . ' must be a positive integer.');
        }

        return (int) $value;
    }

    private function nullablePositiveId(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->positiveId($value, $field);
    }

    private function assertSafeText(string $value): void
    {
        if (preg_match('#storage/private|storage/uploads|dsn|password|secret|token|[a-z]:[\\\\/]#i', $value) === 1) {
            throw new RuntimeException('Email outbox payload contains disallowed sensitive content.');
        }
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
