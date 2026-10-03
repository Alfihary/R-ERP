<?php

declare(strict_types=1);

use App\Domain\Mail\ProductTicketEmailTemplatePayload;
use App\Domain\Mail\ProductTicketEmailTemplatePayloadBuilder;
use App\Domain\Mail\ProductTicketEmailTemplateRenderer;
use App\Domain\Mail\ProductTicketEmailTemplateValidationException;
use App\Domain\Mail\RenderedEmail;

return static function (): array {
    $renderer = new ProductTicketEmailTemplateRenderer();
    $builder = new ProductTicketEmailTemplatePayloadBuilder(
        'http://localhost:8000',
        'America/Mexico_City',
        true
    );

    $ticket = static fn (array $overrides = []): array => array_replace([
        'id' => 901,
        'folio' => 'QA-900001',
        'estado' => 'EN_REVISION',
        'empresa_nombre' => 'Empresa QA & Refrigeración',
        'almacen_nombre' => 'Almacén Norte',
        'almacen_codigo' => 'NTE',
        'solicitante_username' => 'qa.user',
        'observaciones_generales' => null,
        'total_partidas' => 2,
        'partidas_aprobadas' => 1,
        'partidas_rechazadas' => 1,
        'cancelado_at' => null,
        'motivo_cancelacion' => null,
        'cancelado_por_username' => null,
        'created_at' => '2026-09-30 10:00:00',
    ], $overrides);

    $line = static fn (int $number, string $state, array $overrides = []): array => array_replace([
        'numero_partida' => $number,
        'estado' => $state,
        'descripcion' => 'Partida QA ' . $number,
        'motivo_rechazo' => $state === 'RECHAZADA' ? 'La clave SAT requiere corrección.' : null,
        'comentario_resolucion' => $state === 'APROBADA' ? 'Datos autorizados.' : null,
        'resuelto_at' => '2026-09-30 11:00:00',
        'clave_autorizada' => $state === 'APROBADA' ? 'QA-PROD-1' : null,
        'unidad_sat_autorizada_codigo' => $state === 'APROBADA' ? 'H87' : null,
        'unidad_sat_autorizada_nombre' => $state === 'APROBADA' ? 'Pieza' : null,
        'clave_sat_autorizada_codigo' => $state === 'APROBADA' ? '40101704' : null,
        'clave_sat_autorizada_descripcion' => $state === 'APROBADA' ? 'Unidades de condensación' : null,
        'unidad_sat_codigo' => 'H87',
        'unidad_sat_nombre' => 'Pieza',
        'clave_sat_codigo' => '40101704',
        'clave_sat_descripcion' => 'Unidades de condensación',
    ], $overrides);

    $inputs = [
        'TICKET_CREADO' => [$ticket(['total_partidas' => 2]), []],
        'PARTIDA_APROBADA' => [$ticket(), [$line(1, 'APROBADA')]],
        'PARTIDA_RECHAZADA' => [$ticket(), [$line(2, 'RECHAZADA')]],
        'TICKET_RESUELTO_TOTAL' => [
            $ticket(['estado' => 'APROBADO', 'total_partidas' => 2, 'partidas_aprobadas' => 2, 'partidas_rechazadas' => 0]),
            [$line(1, 'APROBADA'), $line(2, 'APROBADA')],
        ],
        'TICKET_RESUELTO_PARCIAL' => [
            $ticket(['estado' => 'RESUELTO_PARCIAL']),
            [$line(1, 'APROBADA'), $line(2, 'RECHAZADA')],
        ],
        'TICKET_CANCELADO' => [
            $ticket([
                'estado' => 'CANCELADO',
                'cancelado_at' => '2026-09-30 12:00:00',
                'motivo_cancelacion' => 'Captura sustituida por una corregida.',
            ]),
            [],
        ],
    ];

    $renders = [];
    foreach ($inputs as $event => [$eventTicket, $eventLines]) {
        $renders[$event] = $renderer->render(
            $event,
            $builder->build($event, $eventTicket, $eventLines, $event === 'PARTIDA_RECHAZADA' ? 1 : 0)
        );
    }

    $expectedSubjects = [
        'TICKET_CREADO' => '[R-ERP] Ticket QA-900001 creado',
        'PARTIDA_APROBADA' => '[R-ERP] Ticket QA-900001: partida 1 aprobada',
        'PARTIDA_RECHAZADA' => '[R-ERP] Ticket QA-900001: partida 2 rechazada',
        'TICKET_RESUELTO_TOTAL' => '[R-ERP] Ticket QA-900001 resuelto',
        'TICKET_RESUELTO_PARCIAL' => '[R-ERP] Ticket QA-900001 resuelto parcialmente',
        'TICKET_CANCELADO' => '[R-ERP] Ticket QA-900001 cancelado',
    ];

    $fails = static function (callable $callback): bool {
        try {
            $callback();
            return false;
        } catch (ProductTicketEmailTemplateValidationException) {
            return true;
        }
    };

    $manual = static function (
        ProductTicketEmailTemplatePayload $source,
        ?string $event = null,
        ?array $ticketData = null,
        ?array $items = null,
        ?int $attachmentsCount = null,
        ?int $totalItems = null,
        ?string $note = null,
        ?string $ctaUrl = null
    ): ProductTicketEmailTemplatePayload {
        return new ProductTicketEmailTemplatePayload(
            $event ?? $source->event,
            $ticketData ?? $source->ticket,
            $source->displayName,
            $items ?? $source->items,
            $attachmentsCount ?? $source->attachmentsCount,
            $totalItems ?? $source->totalItems,
            $note,
            $ctaUrl ?? $source->ctaUrl,
            $source->timezone,
            $source->systemName,
            $source->brandLabel
        );
    };

    $approvedPayload = $builder->build('PARTIDA_APROBADA', $ticket(), [$line(1, 'APROBADA')], 0);
    $createdPayload = $builder->build('TICKET_CREADO', $ticket(), [], 0);

    $xssItem = $approvedPayload->items[0];
    $xssItem['description'] = 'A&B <script>alert("x")</script> " \' >';
    $xssItem['response'] = '<img src=x onerror=alert(1)>';
    $xssPayload = $manual($approvedPayload, items: [$xssItem], note: '<b>Nota</b>');
    $xssRender = $renderer->render('PARTIDA_APROBADA', $xssPayload);

    $rejectedPayload = $builder->build('PARTIDA_RECHAZADA', $ticket(), [$line(2, 'RECHAZADA')], 1);
    $reasonItem = $rejectedPayload->items[0];
    $reasonItem['reason'] = '<script>motivo</script>';
    $reasonRender = $renderer->render(
        'PARTIDA_RECHAZADA',
        $manual($rejectedPayload, items: [$reasonItem])
    );

    $tenLines = [];
    for ($number = 1; $number <= 10; $number++) {
        $tenLines[] = $line($number, 'APROBADA');
    }
    $elevenLines = $tenLines;
    $elevenLines[] = $line(11, 'APROBADA');
    $tenRender = $renderer->render(
        'TICKET_RESUELTO_TOTAL',
        $builder->build(
            'TICKET_RESUELTO_TOTAL',
            $ticket(['estado' => 'APROBADO', 'total_partidas' => 10, 'partidas_aprobadas' => 10, 'partidas_rechazadas' => 0]),
            $tenLines,
            0
        )
    );
    $elevenRender = $renderer->render(
        'TICKET_RESUELTO_TOTAL',
        $builder->build(
            'TICKET_RESUELTO_TOTAL',
            $ticket(['estado' => 'APROBADO', 'total_partidas' => 11, 'partidas_aprobadas' => 11, 'partidas_rechazadas' => 0]),
            $elevenLines,
            0
        )
    );

    $domValid = true;
    if (class_exists(DOMDocument::class)) {
        foreach ($renders as $render) {
            libxml_use_internal_errors(true);
            $document = new DOMDocument();
            $domValid = $document->loadHTML($render->htmlBody, LIBXML_NOWARNING | LIBXML_NOERROR)
                && libxml_get_errors() === [];
            libxml_clear_errors();
            if (!$domValid) {
                break;
            }
        }
    }

    $rendererSource = file_get_contents(BASE_PATH . '/app/Domain/Mail/ProductTicketEmailTemplateRenderer.php') ?: '';
    $allHtml = implode("\n", array_map(static fn (RenderedEmail $mail): string => $mail->htmlBody, $renders));
    $allText = implode("\n", array_map(static fn (RenderedEmail $mail): string => $mail->textBody, $renders));

    $cases = [
        'six_events_render' => count($renders) === 6,
        'subjects_exact' => array_reduce(array_keys($renders), static fn (bool $ok, string $event): bool => $ok && $renders[$event]->subject === $expectedSubjects[$event], true),
        'html_non_empty' => array_reduce($renders, static fn (bool $ok, RenderedEmail $mail): bool => $ok && trim($mail->htmlBody) !== '', true),
        'text_non_empty' => array_reduce($renders, static fn (bool $ok, RenderedEmail $mail): bool => $ok && trim($mail->textBody) !== '', true),
        'html_text_folio_parity' => array_reduce($renders, static fn (bool $ok, RenderedEmail $mail): bool => $ok && str_contains($mail->htmlBody, 'QA-900001') && str_contains($mail->textBody, 'QA-900001'), true),
        'utf8_output' => preg_match('//u', $allHtml . $allText) === 1,
        'description_xss_escaped_once' => str_contains($xssRender->htmlBody, 'A&amp;B &lt;script&gt;') && !str_contains($xssRender->htmlBody, '<script>alert'),
        'reason_xss_escaped' => str_contains($reasonRender->htmlBody, '&lt;script&gt;motivo&lt;/script&gt;'),
        'response_xss_escaped' => str_contains($xssRender->htmlBody, '&lt;img src=x onerror=alert(1)&gt;'),
        'note_xss_escaped' => str_contains($xssRender->htmlBody, '&lt;b&gt;Nota&lt;/b&gt;'),
        'crlf_folio_rejected' => $fails(function () use ($renderer, $manual, $createdPayload): void {
            $ticketData = $createdPayload->ticket;
            $ticketData['folio'] = "QA-900001\r\nBcc:x@example.test";
            $renderer->render('TICKET_CREADO', $manual($createdPayload, ticketData: $ticketData));
        }),
        'crlf_item_number_rejected' => $fails(function () use ($renderer, $manual, $approvedPayload): void {
            $items = $approvedPayload->items;
            $items[0]['partida_numero'] = "1\r\nBcc:x@example.test";
            $renderer->render('PARTIDA_APROBADA', $manual($approvedPayload, items: $items));
        }),
        'invalid_event_rejected' => $fails(fn () => $renderer->render('INVALIDO', $manual($createdPayload, event: 'INVALIDO'))),
        'missing_folio_rejected' => $fails(function () use ($renderer, $manual, $createdPayload): void {
            $ticketData = $createdPayload->ticket;
            $ticketData['folio'] = '';
            $renderer->render('TICKET_CREADO', $manual($createdPayload, ticketData: $ticketData));
        }),
        'missing_item_number_rejected' => $fails(function () use ($renderer, $manual, $approvedPayload): void {
            $items = $approvedPayload->items;
            $items[0]['partida_numero'] = '';
            $renderer->render('PARTIDA_APROBADA', $manual($approvedPayload, items: $items));
        }),
        'attachments_zero_hidden' => !str_contains($renders['PARTIDA_APROBADA']->htmlBody, '<strong>Adjuntos</strong>'),
        'attachments_positive_shown' => str_contains($renders['PARTIDA_RECHAZADA']->htmlBody, '<strong>Adjuntos</strong>') && str_contains($renders['PARTIDA_RECHAZADA']->textBody, 'Adjuntos:'),
        'note_empty_hidden' => !str_contains($renders['PARTIDA_APROBADA']->htmlBody, '<strong>Nota</strong>'),
        'response_empty_hidden' => !str_contains($renders['PARTIDA_RECHAZADA']->htmlBody, '<strong>Respuesta:</strong>'),
        'cancel_without_items' => !str_contains($renders['TICKET_CANCELADO']->htmlBody, 'Partida 1'),
        'multiple_items_keep_order' => strpos($renders['TICKET_RESUELTO_TOTAL']->textBody, 'Partida 1:') < strpos($renders['TICKET_RESUELTO_TOTAL']->textBody, 'Partida 2:'),
        'ten_items_all_rendered' => substr_count($tenRender->textBody, 'Resultado:') === 10 && !str_contains($tenRender->textBody, 'más partidas'),
        'eleven_items_truncated' => substr_count($elevenRender->textBody, 'Resultado:') === 10 && str_contains($elevenRender->textBody, 'más partidas'),
        'total_items_validation' => $fails(fn () => $renderer->render('PARTIDA_APROBADA', $manual($approvedPayload, totalItems: 0))),
        'cta_safe_url' => str_contains($renders['TICKET_CREADO']->htmlBody, 'href="http://localhost:8000/tickets/productos/901"'),
        'unsafe_url_rejected' => $fails(fn () => $renderer->render('TICKET_CREADO', $manual($createdPayload, ctaUrl: 'javascript:alert(1)'))),
        'raw_html_field_rejected' => $fails(function () use ($renderer, $manual, $approvedPayload): void {
            $items = $approvedPayload->items;
            $items[0]['raw_html'] = '<b>x</b>';
            $renderer->render('PARTIDA_APROBADA', $manual($approvedPayload, items: $items));
        }),
        'no_script_tags' => preg_match('/<script\b/i', $allHtml) === 0,
        'no_inline_events' => preg_match('/\son[a-z]+\s*=/i', $allHtml) === 0,
        'no_unexpected_external_refs' => preg_match('/<(?:img|link|script)[^>]+(?:src|href)=["\']https?:/i', $allHtml) === 0,
        'deterministic_output' => $renderer->render('TICKET_CREADO', $createdPayload) == $renderer->render('TICKET_CREADO', $createdPayload),
        'text_uses_crlf' => str_contains($renders['TICKET_CREADO']->textBody, "\r\n") && !preg_match('/(?<!\r)\n/', $renders['TICKET_CREADO']->textBody),
        'tones_controlled' => str_contains($renders['TICKET_RESUELTO_PARCIAL']->htmlBody, '#9A5B00') && str_contains($renders['PARTIDA_RECHAZADA']->htmlBody, '#B42318'),
        'branding_present' => str_contains($allHtml, 'ERP REFRIGERACIÓN') && str_contains($allText, 'R-ERP'),
        'no_recipient_logic' => !preg_match('/destinatario_email|resolveRecipients|FILTER_VALIDATE_EMAIL|\$payload->(?:to|cc|bcc)\b/i', $rendererSource),
        'renderer_has_no_db_dependency' => !preg_match('/PDO|ConnectionProvider|Repository/i', $rendererSource),
        'html_document_markers' => array_reduce($renders, static fn (bool $ok, RenderedEmail $mail): bool => $ok && str_starts_with($mail->htmlBody, '<!doctype html>') && str_contains($mail->htmlBody, '<html lang="es">') && str_contains($mail->htmlBody, '<meta charset="UTF-8">') && str_contains($mail->htmlBody, '<meta name="viewport"') && str_contains($mail->htmlBody, '<body'), true),
        'html_dom_valid' => $domValid,
        'preview_semantic_markers' => array_reduce($renders, static fn (bool $ok, RenderedEmail $mail): bool => $ok && str_contains($mail->htmlBody, 'ERP REFRIGERACIÓN') && str_contains($mail->htmlBody, 'Ver ticket') && str_contains($mail->htmlBody, 'Correo automático'), true),
        'no_network_or_secret_runtime' => !preg_match('/curl_|stream_socket|fsockopen|getenv|Env::|PHPMailer|MailTransport/i', $rendererSource),
    ];

    $failed = array_keys(array_filter($cases, static fn (bool $value): bool => !$value));
    if ($failed !== []) {
        throw new RuntimeException('CORREO-PLANTILLA-RUNTIME assertions failed: ' . implode(', ', $failed));
    }

    return [
        'cases' => $cases,
        'case_count' => count($cases),
        'events' => array_keys($renders),
        'html_sha256' => array_map(static fn (RenderedEmail $mail): string => hash('sha256', $mail->htmlBody), $renders),
        'text_sha256' => array_map(static fn (RenderedEmail $mail): string => hash('sha256', $mail->textBody), $renders),
        'network_connections' => 0,
        'real_emails_sent' => 0,
        'secret_resolutions' => 0,
        'database_used' => false,
    ];
};
