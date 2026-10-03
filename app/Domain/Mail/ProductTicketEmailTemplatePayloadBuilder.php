<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final readonly class ProductTicketEmailTemplatePayloadBuilder
{
    private const EVENTS = [
        'TICKET_CREADO',
        'PARTIDA_APROBADA',
        'PARTIDA_RECHAZADA',
        'TICKET_RESUELTO_TOTAL',
        'TICKET_RESUELTO_PARCIAL',
        'TICKET_CANCELADO',
    ];

    private string $baseUrl;
    private DateTimeZone $dateTimeZone;

    public function __construct(string $baseUrl, string $timezone, bool $allowHttp = false)
    {
        $this->baseUrl = $this->normalizeBaseUrl($baseUrl, $allowHttp);

        try {
            $this->dateTimeZone = new DateTimeZone($timezone);
        } catch (Throwable) {
            throw new ProductTicketEmailTemplateValidationException('render_context.timezone is invalid.');
        }
    }

    /**
     * @param array<string, mixed> $ticket
     * @param list<array<string, mixed>> $lines
     */
    public function build(
        string $event,
        array $ticket,
        array $lines,
        int $attachmentsCount
    ): ProductTicketEmailTemplatePayload {
        $event = strtoupper(trim($event));
        if (!in_array($event, self::EVENTS, true)) {
            throw new ProductTicketEmailTemplateValidationException('event is unsupported.');
        }

        $ticketId = $this->positiveInt($ticket['id'] ?? null, 'ticket.ticket_id');
        if ($attachmentsCount < 0) {
            throw new ProductTicketEmailTemplateValidationException('attachments_count must be non-negative.');
        }

        $items = match ($event) {
            'TICKET_CREADO', 'TICKET_CANCELADO' => [],
            'PARTIDA_APROBADA', 'PARTIDA_RECHAZADA' => $this->oneItem($lines),
            'TICKET_RESUELTO_TOTAL', 'TICKET_RESUELTO_PARCIAL' => $this->items($lines),
        };

        $totalItems = $this->nonNegativeInt($ticket['total_partidas'] ?? null, 'ticket.total_items');
        $createdAt = $this->date($ticket['created_at'] ?? null, 'ticket.created_at');
        $cancelledAt = $this->date($ticket['cancelado_at'] ?? null, 'ticket.cancelled_at');

        return new ProductTicketEmailTemplatePayload(
            $event,
            [
                'ticket_id' => $ticketId,
                'folio' => $this->text($ticket['folio'] ?? null),
                'estado' => $this->text($ticket['estado'] ?? null),
                'created_at' => $createdAt,
                'cancelled_at' => $cancelledAt,
                'empresa_nombre' => $this->text($ticket['empresa_nombre'] ?? null),
                'almacen_nombre' => $this->text(
                    $ticket['almacen_nombre'] ?? $ticket['almacen_codigo'] ?? null
                ),
                'observaciones_generales' => $this->nullableText(
                    $ticket['observaciones_generales'] ?? null
                ),
                'partidas_aprobadas' => $this->nonNegativeInt(
                    $ticket['partidas_aprobadas'] ?? null,
                    'ticket.partidas_aprobadas'
                ),
                'partidas_rechazadas' => $this->nonNegativeInt(
                    $ticket['partidas_rechazadas'] ?? null,
                    'ticket.partidas_rechazadas'
                ),
                'motivo_cancelacion' => $this->nullableText(
                    $ticket['motivo_cancelacion'] ?? null
                ),
                'cancelado_por_nombre' => $this->nullableText(
                    $ticket['cancelado_por_username'] ?? null
                ),
            ],
            $this->text($ticket['solicitante_username'] ?? null),
            $items,
            $attachmentsCount,
            $totalItems,
            null,
            $this->baseUrl . '/tickets/productos/' . $ticketId,
            $this->dateTimeZone->getName()
        );
    }

    /** @param list<array<string, mixed>> $lines @return list<array<string, mixed>> */
    private function oneItem(array $lines): array
    {
        if (count($lines) !== 1) {
            throw new ProductTicketEmailTemplateValidationException('items must contain exactly one line.');
        }

        return $this->items($lines);
    }

    /** @param list<array<string, mixed>> $lines @return list<array<string, mixed>> */
    private function items(array $lines): array
    {
        $items = [];
        foreach ($lines as $line) {
            $items[] = [
                'partida_numero' => $this->positiveInt(
                    $line['numero_partida'] ?? null,
                    'items.partida_numero'
                ),
                'reference' => $this->nullableText($line['clave_autorizada'] ?? null),
                'description' => $this->text($line['descripcion'] ?? null),
                'item_status' => $this->text($line['estado'] ?? null),
                'unidad_sat_label' => $this->catalogLabel(
                    $line['unidad_sat_autorizada_codigo'] ?? $line['unidad_sat_codigo'] ?? null,
                    $line['unidad_sat_autorizada_nombre'] ?? $line['unidad_sat_nombre'] ?? null
                ),
                'clave_sat_label' => $this->catalogLabel(
                    $line['clave_sat_autorizada_codigo'] ?? $line['clave_sat_codigo'] ?? null,
                    $line['clave_sat_autorizada_descripcion'] ?? $line['clave_sat_descripcion'] ?? null
                ),
                'response' => $this->nullableText($line['comentario_resolucion'] ?? null),
                'reason' => $this->nullableText($line['motivo_rechazo'] ?? null),
                'resolved_at' => $this->date($line['resuelto_at'] ?? null, 'items.resolved_at'),
            ];
        }

        return $items;
    }

    private function catalogLabel(mixed $code, mixed $description): ?string
    {
        $code = $this->nullableText($code);
        $description = $this->nullableText($description);

        if ($code === null) {
            return $description;
        }

        return $description === null ? $code : $code . ' · ' . $description;
    }

    private function normalizeBaseUrl(string $value, bool $allowHttp): string
    {
        $value = rtrim(trim($value), '/');
        if ($value === '' || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new ProductTicketEmailTemplateValidationException('cta base URL is invalid.');
        }

        $parts = parse_url($value);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new ProductTicketEmailTemplateValidationException('cta base URL must be absolute.');
        }
        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'https' && !($allowHttp && $scheme === 'http')) {
            throw new ProductTicketEmailTemplateValidationException('cta base URL scheme is not allowed.');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new ProductTicketEmailTemplateValidationException('cta base URL contains disallowed components.');
        }

        return $value;
    }

    private function date(mixed $value, string $field): ?DateTimeImmutable
    {
        $value = $this->nullableText($value);
        if ($value === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($value, $this->dateTimeZone);
        } catch (Throwable) {
            throw new ProductTicketEmailTemplateValidationException($field . ' is invalid.');
        }
    }

    private function text(mixed $value): string
    {
        return trim((string) $value);
    }

    private function nullableText(mixed $value): ?string
    {
        $value = $this->text($value);
        return $value === '' ? null : $value;
    }

    private function positiveInt(mixed $value, string $field): int
    {
        if ((!is_int($value) && !is_string($value)) || preg_match('/^[1-9]\d*$/', (string) $value) !== 1) {
            throw new ProductTicketEmailTemplateValidationException($field . ' must be a positive integer.');
        }

        return (int) $value;
    }

    private function nonNegativeInt(mixed $value, string $field): int
    {
        if ((!is_int($value) && !is_string($value)) || preg_match('/^\d+$/', (string) $value) !== 1) {
            throw new ProductTicketEmailTemplateValidationException($field . ' must be a non-negative integer.');
        }

        return (int) $value;
    }
}
