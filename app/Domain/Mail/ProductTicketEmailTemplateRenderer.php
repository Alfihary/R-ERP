<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class ProductTicketEmailTemplateRenderer
{
    public const MAX_ITEMS_RENDERED = 10;

    private const EVENTS = [
        'TICKET_CREADO',
        'PARTIDA_APROBADA',
        'PARTIDA_RECHAZADA',
        'TICKET_RESUELTO_TOTAL',
        'TICKET_RESUELTO_PARCIAL',
        'TICKET_CANCELADO',
    ];

    private const TICKET_STATUS_LABELS = [
        'EN_REVISION' => 'En revisión',
        'RESUELTO_PARCIAL' => 'Resuelto parcialmente',
        'APROBADO' => 'Aprobado',
        'RECHAZADO' => 'Rechazado',
        'CANCELADO' => 'Cancelado',
    ];

    private const ITEM_STATUS = [
        'EN_REVISION' => ['label' => 'En revisión', 'tone' => 'registered'],
        'APROBADA' => ['label' => 'Partida aprobada', 'tone' => 'approved'],
        'RECHAZADA' => ['label' => 'Partida rechazada', 'tone' => 'rejected'],
    ];

    private const TONES = [
        'review' => ['accent' => '#0D9EC4', 'badge' => '#0B78D1'],
        'success' => ['accent' => '#0A7A58', 'badge' => '#0A7A58'],
        'danger' => ['accent' => '#B42318', 'badge' => '#B42318'],
        'warning' => ['accent' => '#9A5B00', 'badge' => '#9A5B00'],
        'cancelled' => ['accent' => '#B42318', 'badge' => '#B42318'],
    ];

    private const FORBIDDEN_KEYS = [
        'raw_html', 'html_note', 'html_description', 'style', 'class',
        'to', 'cc', 'bcc', 'smtp', 'smtp_config', 'outbox', 'dedupe_key',
    ];

    public function templateCode(string $event): string
    {
        return match (strtoupper(trim($event))) {
            'TICKET_CREADO' => 'ticket_created',
            'PARTIDA_APROBADA' => 'line_approved',
            'PARTIDA_RECHAZADA' => 'line_rejected',
            'TICKET_RESUELTO_TOTAL', 'TICKET_RESUELTO_PARCIAL' => 'ticket_resolved',
            'TICKET_CANCELADO' => 'ticket_cancelled',
            default => throw new ProductTicketEmailTemplateValidationException('event is unsupported.'),
        };
    }

    public function render(
        string $event,
        ProductTicketEmailTemplatePayload $payload
    ): RenderedEmail {
        $event = strtoupper(trim($event));
        if ($event !== $payload->event || !in_array($event, self::EVENTS, true)) {
            throw new ProductTicketEmailTemplateValidationException('event is unsupported or inconsistent.');
        }

        $this->validate($payload);
        $model = $this->viewModel($payload);

        return new RenderedEmail(
            $this->subject($payload),
            $this->html($model),
            $this->text($model)
        );
    }

    private function validate(ProductTicketEmailTemplatePayload $payload): void
    {
        $this->assertNoForbiddenKeys($payload->ticket, 'ticket');
        $this->assertNoForbiddenKeys($payload->items, 'items');

        $ticketId = $payload->ticket['ticket_id'] ?? null;
        if (!is_int($ticketId) || $ticketId < 1) {
            throw new ProductTicketEmailTemplateValidationException('ticket.ticket_id is invalid.');
        }

        $folio = $this->requiredText($payload->ticket['folio'] ?? null, 'ticket.folio');
        $this->assertHeaderValue($folio, 'ticket.folio');
        if (preg_match('/^[A-Z0-9]+-[0-9]{6}$/', $folio) !== 1) {
            throw new ProductTicketEmailTemplateValidationException('ticket.folio has an invalid format.');
        }

        $state = $this->requiredText($payload->ticket['estado'] ?? null, 'ticket.estado');
        if (!isset(self::TICKET_STATUS_LABELS[$state])) {
            throw new ProductTicketEmailTemplateValidationException('ticket.estado is unsupported.');
        }

        foreach (['empresa_nombre', 'almacen_nombre'] as $field) {
            $this->requiredText($payload->ticket[$field] ?? null, 'ticket.' . $field);
        }
        $this->requiredText($payload->displayName, 'recipient_context.display_name');

        if ($payload->attachmentsCount < 0 || $payload->totalItems < 0) {
            throw new ProductTicketEmailTemplateValidationException('item and attachment counts must be non-negative.');
        }
        if ($payload->totalItems < count($payload->items)) {
            throw new ProductTicketEmailTemplateValidationException('total_items cannot be less than rendered items.');
        }

        $this->validateUrl($payload->ctaUrl);
        $this->requiredText($payload->systemName, 'branding.system_name');
        $this->requiredText($payload->brandLabel, 'branding.brand_label');
        try {
            new DateTimeZone($payload->timezone);
        } catch (Throwable) {
            throw new ProductTicketEmailTemplateValidationException('render_context.timezone is invalid.');
        }

        if ($payload->event === 'TICKET_CREADO') {
            if ($payload->items !== []) {
                throw new ProductTicketEmailTemplateValidationException('TICKET_CREADO does not render item cards in v1.');
            }
            if (!$this->dateValue($payload->ticket['created_at'] ?? null) instanceof DateTimeImmutable) {
                throw new ProductTicketEmailTemplateValidationException('ticket.created_at is required.');
            }
        }

        if ($payload->event === 'TICKET_CANCELADO') {
            if ($payload->items !== []) {
                throw new ProductTicketEmailTemplateValidationException('TICKET_CANCELADO must not contain item cards.');
            }
            if (!$this->dateValue($payload->ticket['cancelled_at'] ?? null) instanceof DateTimeImmutable) {
                throw new ProductTicketEmailTemplateValidationException('ticket.cancelled_at is required.');
            }
        }

        if (in_array($payload->event, ['PARTIDA_APROBADA', 'PARTIDA_RECHAZADA'], true)) {
            if (count($payload->items) !== 1) {
                throw new ProductTicketEmailTemplateValidationException('line events require exactly one item.');
            }
        }

        if (in_array($payload->event, ['TICKET_RESUELTO_TOTAL', 'TICKET_RESUELTO_PARCIAL'], true)) {
            $minimum = min($payload->totalItems, self::MAX_ITEMS_RENDERED);
            if ($payload->totalItems < 1 || count($payload->items) < $minimum) {
                throw new ProductTicketEmailTemplateValidationException('resolved events require coherent items and total_items.');
            }
        }

        $statuses = [];
        foreach ($payload->items as $index => $item) {
            if (!is_array($item)) {
                throw new ProductTicketEmailTemplateValidationException('items must be normalized arrays.');
            }
            $number = $item['partida_numero'] ?? null;
            if ((!is_int($number) && !is_string($number)) || trim((string) $number) === '') {
                throw new ProductTicketEmailTemplateValidationException('items.partida_numero is required.');
            }
            $this->assertHeaderValue((string) $number, 'items.partida_numero');
            if (preg_match('/^[1-9]\d*$/', (string) $number) !== 1) {
                throw new ProductTicketEmailTemplateValidationException('items.partida_numero is invalid.');
            }

            $this->requiredText($item['description'] ?? null, 'items.description');
            $itemStatus = $this->requiredText($item['item_status'] ?? null, 'items.item_status');
            if (!isset(self::ITEM_STATUS[$itemStatus])) {
                throw new ProductTicketEmailTemplateValidationException('items.item_status is unsupported.');
            }
            $statuses[] = $itemStatus;

            foreach (['reference', 'unidad_sat_label', 'clave_sat_label', 'response', 'reason'] as $field) {
                $this->optionalText($item[$field] ?? null, 'items.' . $field);
            }

            if ($payload->event === 'PARTIDA_APROBADA' && $itemStatus !== 'APROBADA') {
                throw new ProductTicketEmailTemplateValidationException('PARTIDA_APROBADA requires an approved item.');
            }
            if ($payload->event === 'PARTIDA_RECHAZADA') {
                if ($itemStatus !== 'RECHAZADA') {
                    throw new ProductTicketEmailTemplateValidationException('PARTIDA_RECHAZADA requires a rejected item.');
                }
                $this->requiredText($item['reason'] ?? null, 'items.reason');
            }
        }

        if ($payload->event === 'TICKET_RESUELTO_PARCIAL'
            && (!in_array('APROBADA', $statuses, true) || !in_array('RECHAZADA', $statuses, true))) {
            throw new ProductTicketEmailTemplateValidationException('TICKET_RESUELTO_PARCIAL requires mixed item results.');
        }

        foreach ([
            'ticket.observaciones_generales' => $payload->ticket['observaciones_generales'] ?? null,
            'ticket.motivo_cancelacion' => $payload->ticket['motivo_cancelacion'] ?? null,
            'ticket.cancelado_por_nombre' => $payload->ticket['cancelado_por_nombre'] ?? null,
            'note' => $payload->note,
        ] as $field => $value) {
            $this->optionalText($value, $field);
        }
    }

    /** @return array<string, mixed> */
    private function viewModel(ProductTicketEmailTemplatePayload $payload): array
    {
        $folio = (string) $payload->ticket['folio'];
        $eventContent = match ($payload->event) {
            'TICKET_CREADO' => [
                'title' => 'Ticket creado',
                'status_label' => 'EN REVISIÓN',
                'status_tone' => 'review',
                'summary' => 'El ticket ' . $folio . ' fue recibido y se encuentra en revisión.',
                'section' => 'DATOS DEL TICKET',
            ],
            'PARTIDA_APROBADA' => [
                'title' => 'Partida aprobada',
                'status_label' => 'APROBADA',
                'status_tone' => 'success',
                'summary' => 'La partida fue aprobada correctamente durante la revisión del ticket.',
                'section' => 'PARTIDA APROBADA',
            ],
            'PARTIDA_RECHAZADA' => [
                'title' => 'Partida rechazada',
                'status_label' => 'RECHAZADA',
                'status_tone' => 'danger',
                'summary' => 'La partida fue rechazada durante la revisión. Consulta el motivo antes de continuar.',
                'section' => 'PARTIDA RECHAZADA',
            ],
            'TICKET_RESUELTO_TOTAL' => [
                'title' => 'Ticket resuelto',
                'status_label' => 'RESUELTO',
                'status_tone' => 'success',
                'summary' => 'El ticket fue revisado completamente y concluyó su proceso de revisión.',
                'section' => 'RESULTADO DEL TICKET',
            ],
            'TICKET_RESUELTO_PARCIAL' => [
                'title' => 'Ticket resuelto parcialmente',
                'status_label' => 'RESUELTO PARCIALMENTE',
                'status_tone' => 'warning',
                'summary' => 'El ticket concluyó con resultados mixtos: algunas partidas fueron aprobadas y otras requieren corrección.',
                'section' => 'RESULTADOS DEL TICKET',
            ],
            'TICKET_CANCELADO' => [
                'title' => 'Ticket cancelado',
                'status_label' => 'CANCELADO',
                'status_tone' => 'cancelled',
                'summary' => 'El ticket fue cancelado y ya no continuará su flujo de revisión.',
                'section' => 'ESTADO DEL TICKET',
            ],
        };

        $metadata = [
            ['label' => 'Empresa', 'value' => (string) $payload->ticket['empresa_nombre']],
            ['label' => 'Almacén', 'value' => (string) $payload->ticket['almacen_nombre']],
        ];
        if ($payload->event === 'TICKET_CREADO') {
            $metadata[] = [
                'label' => 'Creado',
                'value' => $this->formatDate($payload->ticket['created_at'], $payload->timezone),
            ];
            $metadata[] = ['label' => 'Total de partidas', 'value' => (string) $payload->totalItems];
        }
        if (in_array($payload->event, ['TICKET_RESUELTO_TOTAL', 'TICKET_RESUELTO_PARCIAL'], true)) {
            $metadata[] = [
                'label' => 'Aprobadas',
                'value' => (string) ($payload->ticket['partidas_aprobadas'] ?? 0),
            ];
            $metadata[] = [
                'label' => 'Rechazadas',
                'value' => (string) ($payload->ticket['partidas_rechazadas'] ?? 0),
            ];
            $metadata[] = ['label' => 'Total', 'value' => (string) $payload->totalItems];
        }
        if ($payload->event === 'TICKET_CANCELADO') {
            $metadata[] = [
                'label' => 'Cancelado',
                'value' => $this->formatDate($payload->ticket['cancelled_at'], $payload->timezone),
            ];
        }

        $items = [];
        foreach (array_slice($payload->items, 0, self::MAX_ITEMS_RENDERED) as $item) {
            $status = self::ITEM_STATUS[(string) $item['item_status']];
            $items[] = $item + [
                'item_status_label' => $status['label'],
                'item_tone' => $status['tone'],
            ];
        }

        $note = $payload->note;
        if ($note === null && $payload->event === 'TICKET_CREADO') {
            $note = $payload->ticket['observaciones_generales'] ?? null;
        }
        if ($note === null && $payload->event === 'TICKET_CANCELADO') {
            $note = $payload->ticket['motivo_cancelacion'] ?? null;
        }

        return $eventContent + [
            'event' => $payload->event,
            'subtitle' => 'Notificación automática del sistema',
            'folio' => $folio,
            'greeting' => 'Hola ' . $payload->displayName . ',',
            'metadata' => $metadata,
            'items' => $items,
            'attachments_count' => $payload->attachmentsCount,
            'note' => $note,
            'truncated' => $payload->totalItems > self::MAX_ITEMS_RENDERED,
            'cta_label' => 'Ver ticket',
            'cta_url' => $payload->ctaUrl,
            'system_name' => $payload->systemName,
            'brand_label' => $payload->brandLabel,
            'footer' => 'Este mensaje informa sobre una operación registrada en el sistema.',
        ];
    }

    private function subject(ProductTicketEmailTemplatePayload $payload): string
    {
        $folio = (string) $payload->ticket['folio'];
        $number = $payload->items[0]['partida_numero'] ?? null;

        return match ($payload->event) {
            'TICKET_CREADO' => '[R-ERP] Ticket ' . $folio . ' creado',
            'PARTIDA_APROBADA' => '[R-ERP] Ticket ' . $folio . ': partida ' . $number . ' aprobada',
            'PARTIDA_RECHAZADA' => '[R-ERP] Ticket ' . $folio . ': partida ' . $number . ' rechazada',
            'TICKET_RESUELTO_TOTAL' => '[R-ERP] Ticket ' . $folio . ' resuelto',
            'TICKET_RESUELTO_PARCIAL' => '[R-ERP] Ticket ' . $folio . ' resuelto parcialmente',
            'TICKET_CANCELADO' => '[R-ERP] Ticket ' . $folio . ' cancelado',
        };
    }

    /** @param array<string, mixed> $model */
    private function html(array $model): string
    {
        $tone = self::TONES[$model['status_tone']];
        $metadata = $this->htmlMetadata($model['metadata']);
        $items = $this->htmlItems($model['items']);
        $attachments = $model['attachments_count'] > 0
            ? '<table role="presentation" width="100%" style="margin-top:20px;border:1px solid #CBD7E6;background:#F4F7FB"><tr><td style="padding:18px 20px;color:#152238"><strong>Adjuntos</strong><br>Este ticket tiene ' . (int) $model['attachments_count'] . ' archivo(s) de soporte. Consulta el ticket para revisarlos.</td></tr></table>'
            : '';
        $note = is_string($model['note']) && trim($model['note']) !== ''
            ? '<table role="presentation" width="100%" style="margin-top:20px;border:1px solid #CBD7E6;border-left:5px solid ' . $tone['accent'] . ';background:#F4F7FB"><tr><td style="padding:16px 18px"><strong>Nota</strong><br>' . $this->escape($model['note']) . '</td></tr></table>'
            : '';
        $truncated = $model['truncated']
            ? '<p style="margin:20px 0 0;color:#465B74">Este ticket contiene más partidas. Consulta el ticket completo en R-ERP.</p>'
            : '';

        return '<!doctype html>' . "\n"
            . '<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<title>' . $this->escape($model['title']) . '</title>'
            . '<style>@media only screen and (max-width:600px){.mail-shell{width:100%!important}.mail-pad{padding:24px 18px!important}.head-cell{display:block!important;width:100%!important;text-align:left!important}.data-cell{display:block!important;width:auto!important;border-right:0!important}.cta{display:block!important;width:auto!important}}</style>'
            . '</head><body style="margin:0;padding:0;background:#E7EDF6;color:#152238;font-family:Arial,Helvetica,sans-serif">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#E7EDF6"><tr><td align="center" style="padding:24px 8px">'
            . '<table class="mail-shell" role="presentation" width="720" cellspacing="0" cellpadding="0" style="width:100%;max-width:720px;background:#FFFFFF;border:1px solid #CBD7E6">'
            . '<tr><td style="background:#0B2A4A;padding:0"><table role="presentation" width="100%"><tr>'
            . '<td class="head-cell" width="60%" style="padding:28px 30px;color:#FFFFFF;vertical-align:top"><p style="margin:0 0 12px;font-size:13px;letter-spacing:1px;font-weight:bold">' . $this->escape($model['brand_label']) . '</p><h1 style="margin:0 0 8px;font-size:30px;line-height:1.15">' . $this->escape($model['title']) . '</h1><p style="margin:0;color:#FFFFFF">' . $this->escape($model['subtitle']) . '</p></td>'
            . '<td class="head-cell" width="40%" style="padding:28px 30px;color:#FFFFFF;text-align:right;vertical-align:top"><p style="margin:0 0 12px;font-size:28px;font-weight:bold">' . $this->escape($model['folio']) . '</p><span style="display:inline-block;padding:7px 12px;border-radius:16px;background:' . $tone['badge'] . ';color:#FFFFFF;font-size:12px;font-weight:bold">' . $this->escape($model['status_label']) . '</span></td>'
            . '</tr></table></td></tr><tr><td style="height:4px;background:' . $tone['accent'] . '"></td></tr>'
            . '<tr><td class="mail-pad" style="padding:34px 32px"><h2 style="margin:0 0 16px;font-size:23px">' . $this->escape($model['greeting']) . '</h2><p style="margin:0 0 24px;color:#465B74;line-height:1.55">' . $this->escape($model['summary']) . '</p>'
            . '<table role="presentation" width="100%" style="margin:0 0 18px"><tr><td style="font-size:12px;font-weight:bold;letter-spacing:1px;color:#465B74">' . $this->escape($model['section']) . '</td><td style="border-bottom:1px solid #CBD7E6"></td></tr></table>'
            . $metadata . $items . $attachments . $note . $truncated
            . '<table role="presentation" width="100%" style="margin-top:26px"><tr><td align="center"><a class="cta" aria-label="Ver ticket" href="' . $this->escape($model['cta_url']) . '" style="display:inline-block;padding:15px 38px;background:#14579B;color:#FFFFFF;text-decoration:none;font-weight:bold;border-radius:7px">' . $this->escape($model['cta_label']) . '</a></td></tr></table>'
            . '</td></tr><tr><td style="padding:24px 28px;border-top:1px solid #CBD7E6;background:#F4F7FB;text-align:center;color:#465B74;font-size:13px"><strong style="color:#152238">' . $this->escape($model['system_name']) . ' · Correo automático</strong><br><span>' . $this->escape($model['footer']) . '</span></td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    /** @param list<array{label:string,value:string}> $metadata */
    private function htmlMetadata(array $metadata): string
    {
        if ($metadata === []) {
            return '';
        }

        $cells = '';
        foreach ($metadata as $entry) {
            $cells .= '<td class="data-cell" width="50%" style="padding:13px 15px;border-right:1px solid #CBD7E6;border-bottom:1px solid #CBD7E6;vertical-align:top"><span style="display:block;margin-bottom:5px;color:#64748B;font-size:11px;font-weight:bold;letter-spacing:.7px;text-transform:uppercase">' . $this->escape($entry['label']) . '</span><strong>' . $this->escape($entry['value']) . '</strong></td>';
        }

        return '<table role="presentation" width="100%" style="margin-bottom:18px;border:1px solid #CBD7E6;background:#FFFFFF"><tr>' . $cells . '</tr></table>';
    }

    /** @param list<array<string, mixed>> $items */
    private function htmlItems(array $items): string
    {
        $html = '';
        foreach ($items as $item) {
            $tone = match ($item['item_tone']) {
                'approved' => ['accent' => '#0A7A58', 'bg' => '#EAF8F3'],
                'rejected' => ['accent' => '#B42318', 'bg' => '#FFF1F0'],
                default => ['accent' => '#0D9EC4', 'bg' => '#EEF8FB'],
            };
            $reference = $this->optionalText($item['reference'] ?? null, 'items.reference')
                ?? 'Partida ' . (int) $item['partida_numero'];
            $details = '';
            foreach ([
                'Resultado' => (string) $item['item_status_label'],
                'Unidad SAT' => $item['unidad_sat_label'] ?? null,
                'Clave SAT' => $item['clave_sat_label'] ?? null,
            ] as $label => $value) {
                if (is_string($value) && trim($value) !== '') {
                    $details .= '<tr><td style="padding:12px 14px;border-bottom:1px solid #CBD7E6"><span style="display:block;margin-bottom:4px;color:#64748B;font-size:11px;font-weight:bold;letter-spacing:.7px;text-transform:uppercase">' . $this->escape($label) . '</span><strong>' . $this->escape($value) . '</strong></td></tr>';
                }
            }
            $response = $this->optionalText($item['response'] ?? null, 'items.response');
            $reason = $this->optionalText($item['reason'] ?? null, 'items.reason');
            $extra = '';
            if ($reason !== null) {
                $extra .= '<p style="margin:16px 0 0;line-height:1.5"><strong>Motivo:</strong> ' . $this->escape($reason) . '</p>';
            }
            if ($response !== null) {
                $extra .= '<p style="margin:16px 0 0;line-height:1.5"><strong>Respuesta:</strong> ' . $this->escape($response) . '</p>';
            }

            $html .= '<table role="presentation" width="100%" style="margin:0 0 18px;border-left:5px solid ' . $tone['accent'] . ';background:#F4F7FB"><tr><td style="padding:20px 17px"><h3 style="margin:0 0 7px;font-size:20px">' . $this->escape($reference) . '</h3><p style="margin:0 0 13px;color:#465B74;line-height:1.5">' . $this->escape((string) $item['description']) . '</p><span style="display:inline-block;margin-bottom:16px;padding:6px 10px;border:1px solid ' . $tone['accent'] . ';border-radius:15px;background:' . $tone['bg'] . ';color:' . $tone['accent'] . ';font-size:12px;font-weight:bold">' . $this->escape((string) $item['item_status_label']) . '</span><table role="presentation" width="100%" style="background:#FFFFFF;border:1px solid #CBD7E6">' . $details . '</table>' . $extra . '</td></tr></table>';
        }

        return $html;
    }

    /** @param array<string, mixed> $model */
    private function text(array $model): string
    {
        $lines = [
            $this->sanitizePlainTextValue((string) $model['system_name']),
            (string) $model['title'],
            'Folio: ' . $this->sanitizePlainTextValue((string) $model['folio']),
            'Estado: ' . $this->sanitizePlainTextValue((string) $model['status_label']),
            '',
            $this->sanitizePlainTextValue((string) $model['greeting']),
            '',
            $this->sanitizePlainTextValue((string) $model['summary']),
            '',
        ];

        foreach ($model['metadata'] as $entry) {
            $lines[] = $entry['label'] . ': '
                . $this->sanitizePlainTextValue((string) $entry['value']);
        }

        if ($model['items'] !== []) {
            $lines[] = '';
            $lines[] = (string) $model['section'];
            foreach ($model['items'] as $item) {
                $lines[] = '';
                $lines[] = 'Partida '
                    . $this->sanitizePlainTextValue((string) $item['partida_numero'])
                    . ': '
                    . $this->sanitizePlainTextValue((string) $item['description']);
                $lines[] = 'Resultado: '
                    . $this->sanitizePlainTextValue((string) $item['item_status_label']);
                foreach ([
                    'Referencia' => $item['reference'] ?? null,
                    'Unidad SAT' => $item['unidad_sat_label'] ?? null,
                    'Clave SAT' => $item['clave_sat_label'] ?? null,
                    'Motivo' => $item['reason'] ?? null,
                    'Respuesta' => $item['response'] ?? null,
                ] as $label => $value) {
                    if (is_string($value) && trim($value) !== '') {
                        $lines[] = $label . ': ' . $this->sanitizePlainTextValue($value);
                    }
                }
            }
        }

        if ($model['attachments_count'] > 0) {
            $lines[] = '';
            $lines[] = 'Adjuntos: este ticket tiene ' . $model['attachments_count'] . ' archivo(s) de soporte.';
        }
        if (is_string($model['note']) && trim($model['note']) !== '') {
            $lines[] = '';
            $lines[] = 'Nota: ' . $this->sanitizePlainTextValue($model['note']);
        }
        if ($model['truncated']) {
            $lines[] = '';
            $lines[] = 'Este ticket contiene más partidas. Consulta el ticket completo en R-ERP.';
        }

        $lines[] = '';
        $lines[] = 'Ver ticket:';
        $lines[] = (string) $model['cta_url'];
        $lines[] = '';
        $lines[] = $this->sanitizePlainTextValue((string) $model['system_name'])
            . ' · Correo automático';
        $lines[] = $this->sanitizePlainTextValue((string) $model['footer']);

        return implode("\r\n", $lines);
    }

    private function validateUrl(string $url): void
    {
        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            throw new ProductTicketEmailTemplateValidationException('cta.url is invalid.');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new ProductTicketEmailTemplateValidationException('cta.url must be absolute.');
        }
        if (!in_array(strtolower((string) $parts['scheme']), ['https', 'http'], true)) {
            throw new ProductTicketEmailTemplateValidationException('cta.url scheme is not allowed.');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new ProductTicketEmailTemplateValidationException('cta.url contains disallowed components.');
        }
    }

    /** @param array<mixed> $value */
    private function assertNoForbiddenKeys(array $value, string $path): void
    {
        foreach ($value as $key => $nested) {
            if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_KEYS, true)) {
                throw new ProductTicketEmailTemplateValidationException($path . ' contains a prohibited field.');
            }
            if (is_array($nested)) {
                $this->assertNoForbiddenKeys($nested, $path);
            }
        }
    }

    private function requiredText(mixed $value, string $field): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            throw new ProductTicketEmailTemplateValidationException($field . ' is required.');
        }
        $this->assertText($value, $field);
        return $value;
    }

    private function optionalText(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $this->assertText($value, $field);
        return $value;
    }

    private function assertText(string $value, string $field): void
    {
        if (preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new ProductTicketEmailTemplateValidationException($field . ' contains invalid text.');
        }
    }

    private function assertHeaderValue(string $value, string $field): void
    {
        if (preg_match('/[\r\n\x00-\x1F\x7F]/', $value) === 1) {
            throw new ProductTicketEmailTemplateValidationException($field . ' contains invalid header characters.');
        }
    }

    private function dateValue(mixed $value): ?DateTimeImmutable
    {
        return $value instanceof DateTimeImmutable ? $value : null;
    }

    private function formatDate(mixed $value, string $timezone): string
    {
        if (!$value instanceof DateTimeImmutable) {
            throw new ProductTicketEmailTemplateValidationException('date value is required.');
        }
        return $value->setTimezone(new DateTimeZone($timezone))->format('d/m/Y H:i');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function sanitizePlainTextValue(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        $value = str_replace("\n", ' ', $value);

        return str_replace(['<', '>'], ['&lt;', '&gt;'], $value);
    }
}
