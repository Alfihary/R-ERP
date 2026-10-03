<?php

declare(strict_types=1);

use App\Domain\Mail\ProductTicketEmailTemplatePayload;
use App\Domain\Mail\ProductTicketEmailTemplatePayloadBuilder;
use App\Domain\Mail\ProductTicketEmailTemplateRenderer;
use App\Domain\Tickets\ProductTicketEmailOutboxService;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\ProductTicketEmailOutboxRepository;

return new class implements DatabaseTest {
    private const CORE_FOLIO = 'QARP-900001';
    private const LONG_FOLIO = 'QARP-900002';
    private const CTA_BASE = 'https://erp.example.test';

    private PDO $pdo;

    /** @return array<string, mixed> */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $this->pdo = $pdo;
        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
            throw new RuntimeException('Unexpected active database for persisted runtime email QA.');
        }

        $before = $this->snapshot();
        $pdo->beginTransaction();

        try {
            $fixture = $this->fixture();
            $repository = new ProductTicketEmailOutboxRepository($pdo);
            $renderer = new ProductTicketEmailTemplateRenderer();
            $builder = new ProductTicketEmailTemplatePayloadBuilder(
                self::CTA_BASE,
                'America/Mexico_City'
            );
            $service = new ProductTicketEmailOutboxService($repository, $renderer, $builder);
            $rule = $this->recipientRule();

            $determinism = $this->determinism($fixture, $repository, $builder, $renderer);
            $invalidCases = $this->invalidPayloadCases($fixture, $repository, $builder, $renderer);
            $coreRows = $this->persistCoreEvents($fixture, $service, $rule);
            $longRow = $this->persistLongEvent($fixture, $service, $rule);

            $persistedCore = $this->persistedRows(array_column($coreRows, 'id'));
            $persistedLong = $this->persistedRows([(int) $longRow['id']])[0] ?? null;
            if (!is_array($persistedLong)) {
                throw new RuntimeException('Long persisted runtime email row was not found.');
            }

            $duplicateCountBefore = $this->outboxCountForTicket((int) $fixture['ticket_id']);
            $duplicateResult = $service->enqueueConfigured([
                'ticket_id' => $fixture['ticket_id'],
                'partida_id' => null,
                'evento' => 'TICKET_CREADO',
                'creado_por_usuario_id' => $fixture['user_id'],
                'responsable_email' => 'qa.responsible@example.test',
            ], $rule);
            $duplicateCountAfter = $this->outboxCountForTicket((int) $fixture['ticket_id']);

            $eventAudit = $this->eventAudit($persistedCore, $fixture);
            $domAudit = $this->domAudit($persistedCore);
            $sizes = $this->sizes($persistedCore);
            $envelope = $this->envelopeAudit($persistedCore);
            $security = $this->securityAudit($persistedCore, $persistedLong);
            $optional = $this->optionalBlocksAudit($persistedCore);
            $longAudit = $this->longAudit($persistedLong, $fixture);

            $cases = [
                'six_events_persisted' => count($persistedCore) === 6,
                'subjects_exact' => $eventAudit['subjects_exact'],
                'event_metadata_correct' => $eventAudit['events_exact'],
                'template_metadata_correct' => $eventAudit['templates_exact'],
                'all_rows_pending' => $eventAudit['all_pending'],
                'html_persisted' => $eventAudit['html_valid'],
                'text_persisted' => $eventAudit['text_valid'],
                'html_text_parity' => $eventAudit['parity'],
                'visual_markers' => $eventAudit['visual_markers'],
                'cta_https_persisted' => $eventAudit['cta_https'],
                'check_accepts_https' => str_contains((string) $persistedCore[0]['html'], self::CTA_BASE),
                'xss_persisted_escaped' => $security['xss_escaped'],
                'no_executable_injection' => $security['no_executable_injection'],
                'no_unexpected_external_refs' => $security['no_external_refs'],
                'crlf_rejected_before_insert' => $invalidCases['crlf_folio_rejected']
                    && $invalidCases['crlf_line_rejected']
                    && $invalidCases['row_inserted'] === false,
                'recipient_envelope_intact' => $envelope['valid'],
                'dedupe_duplicate_skipped' => $duplicateCountBefore === $duplicateCountAfter
                    && ($duplicateResult['notification_enqueued'] ?? true) === false
                    && ($duplicateResult['notification_reason'] ?? '') === 'duplicate_dedupe_key',
                'dedupe_semantics_unchanged' => $eventAudit['dedupe_exact'],
                'conditional_blocks' => $optional['valid'],
                'attachments_zero_and_positive' => $optional['attachments'],
                'cancel_without_items' => $optional['cancel_without_items'],
                'multi_item_two_rows' => $optional['multi_item'],
                'long_email_persisted' => $longAudit['persisted'],
                'long_email_first_ten' => $longAudit['ten_items'],
                'long_email_notice_and_cta' => $longAudit['notice_and_cta'],
                'total_items_invalid_rejected' => $invalidCases['total_items_rejected'],
                'deterministic_subject' => $determinism['subject'],
                'deterministic_html' => $determinism['html'],
                'deterministic_text' => $determinism['text'],
                'dom_valid_six_events' => $domAudit['valid'],
                'size_non_zero' => $this->sizesNonZero($sizes),
            ];

            $failed = array_keys(array_filter($cases, static fn (bool $passed): bool => !$passed));
            if ($failed !== []) {
                $details = $eventAudit['text_raw_markup_events'] !== []
                    ? '; raw_text_markup_events=' . implode(',', $eventAudit['text_raw_markup_events'])
                    : '';
                throw new RuntimeException(
                    'Persisted runtime email QA assertions failed: ' . implode(', ', $failed) . $details
                );
            }
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        $after = $this->snapshot();
        $residualRows = $this->residualRows();
        $integrity = [
            'tickets_count_unchanged' => $before['tickets_count'] === $after['tickets_count'],
            'outbox_count_unchanged' => $before['outbox_count'] === $after['outbox_count'],
            'tickets_hash_unchanged' => $before['tickets_hash'] === $after['tickets_hash'],
            'outbox_hash_unchanged' => $before['outbox_hash'] === $after['outbox_hash'],
            'protected_ticket_unchanged' => $before['protected_ticket'] === $after['protected_ticket'],
            'protected_outbox_unchanged' => $before['protected_outbox'] === $after['protected_outbox'],
            'eligible_count_unchanged' => $before['eligible_count'] === $after['eligible_count'],
            'residual_rows_zero' => $residualRows === 0,
        ];
        $failedIntegrity = array_keys(array_filter($integrity, static fn (bool $passed): bool => !$passed));
        if ($failedIntegrity !== []) {
            throw new RuntimeException(
                'Persisted runtime email QA cleanup failed: ' . implode(', ', $failedIntegrity)
            );
        }

        return [
            'database' => $expectedDatabase,
            'fixture_strategy' => 'two_isolated_transactional_tickets_core_2_items_and_boundary_11_items',
            'events' => array_values(array_map(
                static fn (array $row): string => (string) $row['evento'],
                $persistedCore
            )),
            'cases' => $cases,
            'event_audit' => $eventAudit,
            'recipient_envelope' => $envelope,
            'optional_blocks' => $optional,
            'long_email' => $longAudit,
            'determinism' => $determinism,
            'invalid_payloads' => $invalidCases,
            'dom' => $domAudit,
            'sizes' => $sizes,
            'before' => $before,
            'after' => $after,
            'integrity' => $integrity,
            'residual_rows' => $residualRows,
            'network_connections' => 0,
            'real_emails_sent' => 0,
            'secret_resolutions' => 0,
            'processor_executed' => false,
            'cleanup' => 'transaction_rolled_back',
        ];
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $userId = $this->createUser();
        $companyId = $this->createCompany($userId);
        $warehouseId = $this->createWarehouse($companyId, $userId);
        $sat = $this->satFixture();
        $ticketId = $this->createTicket($companyId, $warehouseId, $userId, self::CORE_FOLIO, 2);
        $approvedId = $this->createLine(
            $ticketId,
            1,
            'APROBADA',
            'Componente QA aprobado.',
            '<img src=x onerror=alert(1)>',
            null,
            $userId,
            $sat
        );
        $rejectedId = $this->createLine(
            $ticketId,
            2,
            'RECHAZADA',
            '<script>alert(1)</script>',
            null,
            '"><script>alert(1)</script>',
            $userId,
            $sat
        );

        $longTicketId = $this->createTicket($companyId, $warehouseId, $userId, self::LONG_FOLIO, 11);
        $longLineIds = [];
        for ($number = 1; $number <= 11; $number++) {
            $longLineIds[] = $this->createLine(
                $longTicketId,
                $number,
                'APROBADA',
                'Partida extensa QA ' . $number,
                'Respuesta extensa QA ' . $number,
                null,
                $userId,
                $sat
            );
        }
        $this->updateTicketState($longTicketId, 'APROBADO', 11, 0);

        return [
            'user_id' => $userId,
            'company_id' => $companyId,
            'warehouse_id' => $warehouseId,
            'ticket_id' => $ticketId,
            'approved_id' => $approvedId,
            'rejected_id' => $rejectedId,
            'long_ticket_id' => $longTicketId,
            'long_line_ids' => $longLineIds,
            'core_folio' => self::CORE_FOLIO,
            'long_folio' => self::LONG_FOLIO,
        ];
    }

    /** @return array<string, mixed> */
    private function recipientRule(): array
    {
        return [
            'enviar_solicitante' => 1,
            'enviar_responsables' => 1,
            'to_json' => json_encode(['qa.to@example.test'], JSON_THROW_ON_ERROR),
            'cc_json' => json_encode(['qa.cc@example.test'], JSON_THROW_ON_ERROR),
            'bcc_json' => json_encode(['qa.bcc@example.test'], JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * @param array<string, mixed> $fixture
     * @param array<string, mixed> $rule
     * @return array<string, array<string, mixed>>
     */
    private function persistCoreEvents(
        array $fixture,
        ProductTicketEmailOutboxService $service,
        array $rule
    ): array {
        $input = fn (string $event, ?int $lineId = null): array => [
            'ticket_id' => $fixture['ticket_id'],
            'partida_id' => $lineId,
            'evento' => $event,
            'creado_por_usuario_id' => $fixture['user_id'],
            'responsable_email' => 'qa.responsible@example.test',
        ];
        $enqueue = function (string $event, ?int $lineId = null) use ($service, $rule, $input): array {
            $result = $service->enqueueConfigured($input($event, $lineId), $rule);
            if (($result['notification_enqueued'] ?? false) !== true || !is_array($result['outbox'] ?? null)) {
                throw new RuntimeException('Expected persisted runtime email was not enqueued for ' . $event . '.');
            }
            return $result['outbox'];
        };

        $rows = [];
        $rows['TICKET_CREADO'] = $enqueue('TICKET_CREADO');
        $rows['PARTIDA_APROBADA'] = $enqueue('PARTIDA_APROBADA', (int) $fixture['approved_id']);
        $this->createAttachment((int) $fixture['ticket_id'], (int) $fixture['rejected_id'], (int) $fixture['user_id']);
        $rows['PARTIDA_RECHAZADA'] = $enqueue('PARTIDA_RECHAZADA', (int) $fixture['rejected_id']);

        $this->updateLineState((int) $fixture['rejected_id'], 'APROBADA', null, 'Aprobación total QA.');
        $this->updateTicketState((int) $fixture['ticket_id'], 'APROBADO', 2, 0);
        $rows['TICKET_RESUELTO_TOTAL'] = $enqueue('TICKET_RESUELTO_TOTAL');

        $this->updateLineState(
            (int) $fixture['rejected_id'],
            'RECHAZADA',
            '"><script>alert(1)</script>',
            null
        );
        $this->updateTicketState((int) $fixture['ticket_id'], 'RESUELTO_PARCIAL', 1, 1);
        $rows['TICKET_RESUELTO_PARCIAL'] = $enqueue('TICKET_RESUELTO_PARCIAL');

        $this->cancelTicket((int) $fixture['ticket_id'], (int) $fixture['user_id']);
        $rows['TICKET_CANCELADO'] = $enqueue('TICKET_CANCELADO');

        return $rows;
    }

    /**
     * @param array<string, mixed> $fixture
     * @param array<string, mixed> $rule
     * @return array<string, mixed>
     */
    private function persistLongEvent(
        array $fixture,
        ProductTicketEmailOutboxService $service,
        array $rule
    ): array {
        $result = $service->enqueueConfigured([
            'ticket_id' => $fixture['long_ticket_id'],
            'partida_id' => null,
            'evento' => 'TICKET_RESUELTO_TOTAL',
            'creado_por_usuario_id' => $fixture['user_id'],
            'responsable_email' => 'qa.responsible@example.test',
        ], $rule);
        if (($result['notification_enqueued'] ?? false) !== true || !is_array($result['outbox'] ?? null)) {
            throw new RuntimeException('Expected long persisted runtime email was not enqueued.');
        }

        return $result['outbox'];
    }

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    private function persistedRows(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare(
            'SELECT * FROM tickets_productos_correos WHERE id IN (' . $placeholders . ') ORDER BY id'
        );
        $statement->execute(array_map('intval', $ids));

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $fixture
     * @return array<string, mixed>
     */
    private function eventAudit(array $rows, array $fixture): array
    {
        $byEvent = [];
        foreach ($rows as $row) {
            $byEvent[(string) $row['evento']] = $row;
        }
        $expected = [
            'TICKET_CREADO' => ['ticket_created', '[R-ERP] Ticket ' . self::CORE_FOLIO . ' creado', 'Ticket creado', 'EN REVISIÓN'],
            'PARTIDA_APROBADA' => ['line_approved', '[R-ERP] Ticket ' . self::CORE_FOLIO . ': partida 1 aprobada', 'Partida aprobada', 'APROBADA'],
            'PARTIDA_RECHAZADA' => ['line_rejected', '[R-ERP] Ticket ' . self::CORE_FOLIO . ': partida 2 rechazada', 'Partida rechazada', 'RECHAZADA'],
            'TICKET_RESUELTO_TOTAL' => ['ticket_resolved', '[R-ERP] Ticket ' . self::CORE_FOLIO . ' resuelto', 'Ticket resuelto', 'RESUELTO'],
            'TICKET_RESUELTO_PARCIAL' => ['ticket_resolved', '[R-ERP] Ticket ' . self::CORE_FOLIO . ' resuelto parcialmente', 'Ticket resuelto parcialmente', 'RESUELTO PARCIALMENTE'],
            'TICKET_CANCELADO' => ['ticket_cancelled', '[R-ERP] Ticket ' . self::CORE_FOLIO . ' cancelado', 'Ticket cancelado', 'CANCELADO'],
        ];

        $subjects = $events = $templates = $pending = $html = $text = $parity = $visual = $cta = true;
        $textRawMarkupEvents = [];
        $dedupe = true;
        foreach ($expected as $event => [$template, $subject, $title, $status]) {
            $row = $byEvent[$event] ?? null;
            if (!is_array($row)) {
                return [
                    'subjects_exact' => false, 'events_exact' => false, 'templates_exact' => false,
                    'all_pending' => false, 'html_valid' => false, 'text_valid' => false,
                    'parity' => false, 'visual_markers' => false, 'cta_https' => false,
                    'dedupe_exact' => false, 'text_raw_markup_events' => [],
                ];
            }
            $subjects = $subjects && (string) $row['subject'] === $subject;
            $events = $events && (string) $row['evento'] === $event;
            $templates = $templates && (string) $row['plantilla'] === $template;
            $pending = $pending && (string) $row['status'] === 'PENDIENTE' && (int) $row['intentos'] === 0;
            $htmlBody = (string) $row['html'];
            $textBody = (string) $row['text'];
            $html = $html && trim($htmlBody) !== ''
                && str_starts_with($htmlBody, '<!doctype html>')
                && str_contains($htmlBody, '<html lang="es">')
                && str_contains($htmlBody, '<meta charset="UTF-8">')
                && str_contains($htmlBody, '<meta name="viewport"')
                && str_contains($htmlBody, '<body');
            $rawTextMarkup = preg_match('/<\/?[a-z][^>]*>/i', $textBody) === 1;
            if ($rawTextMarkup) {
                $textRawMarkupEvents[] = $event;
            }
            $text = $text && trim($textBody) !== ''
                && str_contains($textBody, "\r\n")
                && preg_match('/(?<!\r)\n/', $textBody) !== 1
                && !$rawTextMarkup;
            $parity = $parity
                && str_contains($htmlBody, self::CORE_FOLIO)
                && str_contains($textBody, self::CORE_FOLIO)
                && str_contains($htmlBody, $title)
                && str_contains($textBody, $title)
                && str_contains($htmlBody, $status)
                && str_contains($textBody, $status);
            $visual = $visual
                && str_contains($htmlBody, 'ERP REFRIGERACIÓN')
                && str_contains($htmlBody, 'Ver ticket')
                && str_contains($htmlBody, 'Correo automático');
            $expectedUrl = self::CTA_BASE . '/tickets/productos/' . $fixture['ticket_id'];
            $cta = $cta && str_contains($htmlBody, 'href="' . $expectedUrl . '"')
                && str_contains($textBody, $expectedUrl);
            $partId = in_array($event, ['PARTIDA_APROBADA', 'PARTIDA_RECHAZADA'], true)
                ? (int) ($event === 'PARTIDA_APROBADA' ? $fixture['approved_id'] : $fixture['rejected_id'])
                : null;
            $expectedDedupe = 'ticket:' . $fixture['ticket_id'] . ':partida:'
                . ($partId === null ? 'null' : $partId) . ':evento:' . $event;
            $dedupe = $dedupe && (string) $row['dedupe_key'] === $expectedDedupe;
        }

        return [
            'subjects_exact' => $subjects,
            'events_exact' => $events && count($byEvent) === 6,
            'templates_exact' => $templates,
            'all_pending' => $pending,
            'html_valid' => $html,
            'text_valid' => $text,
            'parity' => $parity,
            'visual_markers' => $visual,
            'cta_https' => $cta,
            'dedupe_exact' => $dedupe,
            'text_raw_markup_events' => $textRawMarkupEvents,
        ];
    }

    /** @param list<array<string, mixed>> $rows @return array<string, mixed> */
    private function envelopeAudit(array $rows): array
    {
        $valid = true;
        $sample = null;
        foreach ($rows as $row) {
            $envelope = json_decode((string) $row['cc_json'], true, 8, JSON_THROW_ON_ERROR);
            $valid = $valid
                && (string) $row['destinatario_email'] === 'qa.to@example.test'
                && ($envelope['to'] ?? null) === ['qa.to@example.test', 'qa.runtime.persisted@example.test']
                && ($envelope['cc'] ?? null) === ['qa.cc@example.test', 'qa.responsible@example.test']
                && ($envelope['bcc'] ?? null) === ['qa.bcc@example.test'];
            $sample ??= $envelope;
        }

        return ['valid' => $valid, 'sample' => $sample];
    }

    /** @param list<array<string, mixed>> $rows @return array<string, bool> */
    private function securityAudit(array $rows, array $longRow): array
    {
        $allHtml = implode("\n", array_map(static fn (array $row): string => (string) $row['html'], [...$rows, $longRow]));
        $xssEscaped = str_contains($allHtml, '&lt;script&gt;alert(1)&lt;/script&gt;')
            && str_contains($allHtml, '&lt;img src=x onerror=alert(1)&gt;')
            && str_contains($allHtml, '&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;');
        $dangerous = preg_match('/<script\b|<[^>]+\son[a-z]+\s*=|(?:href|src)=["\'](?:javascript|data|file):/i', $allHtml) === 1;
        $external = preg_match('/<(?:img|link|script)[^>]+(?:src|href)=["\']https?:/i', $allHtml) === 1;

        return [
            'xss_escaped' => $xssEscaped,
            'no_executable_injection' => !$dangerous,
            'no_external_refs' => !$external,
        ];
    }

    /** @param list<array<string, mixed>> $rows @return array<string, mixed> */
    private function optionalBlocksAudit(array $rows): array
    {
        $byEvent = [];
        foreach ($rows as $row) {
            $byEvent[(string) $row['evento']] = $row;
        }
        $created = (string) $byEvent['TICKET_CREADO']['html'];
        $approved = (string) $byEvent['PARTIDA_APROBADA']['html'];
        $rejected = (string) $byEvent['PARTIDA_RECHAZADA']['html'];
        $total = (string) $byEvent['TICKET_RESUELTO_TOTAL']['text'];
        $partial = (string) $byEvent['TICKET_RESUELTO_PARCIAL']['text'];
        $cancelled = (string) $byEvent['TICKET_CANCELADO']['html'];

        $attachments = !str_contains($created, '<strong>Adjuntos</strong>')
            && !str_contains($approved, '<strong>Adjuntos</strong>')
            && str_contains($rejected, '<strong>Adjuntos</strong>');
        $notes = str_contains($created, '<strong>Nota</strong>')
            && !str_contains($approved, '<strong>Nota</strong>')
            && str_contains($cancelled, '<strong>Nota</strong>');
        $responses = str_contains($approved, '<strong>Respuesta:</strong>')
            && !str_contains($rejected, '<strong>Respuesta:</strong>');
        $reasons = str_contains($rejected, '<strong>Motivo:</strong>')
            && !str_contains($approved, '<strong>Motivo:</strong>');
        $cancelWithoutItems = !str_contains($cancelled, '<h3')
            && !str_contains((string) $byEvent['TICKET_CANCELADO']['text'], 'Partida 1:');
        $multiItem = substr_count($total, 'Resultado:') === 2
            && substr_count($partial, 'Resultado:') === 2
            && strpos($partial, 'Partida 1:') < strpos($partial, 'Partida 2:');

        return [
            'valid' => $attachments && $notes && $responses && $reasons,
            'attachments' => $attachments,
            'notes' => $notes,
            'responses' => $responses,
            'reasons' => $reasons,
            'cancel_without_items' => $cancelWithoutItems,
            'multi_item' => $multiItem,
        ];
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $fixture @return array<string, mixed> */
    private function longAudit(array $row, array $fixture): array
    {
        $html = (string) $row['html'];
        $text = (string) $row['text'];
        $url = self::CTA_BASE . '/tickets/productos/' . $fixture['long_ticket_id'];

        return [
            'persisted' => (string) $row['evento'] === 'TICKET_RESUELTO_TOTAL'
                && (string) $row['plantilla'] === 'ticket_resolved'
                && (string) $row['subject'] === '[R-ERP] Ticket ' . self::LONG_FOLIO . ' resuelto',
            'items_total' => 11,
            'items_rendered' => substr_count($text, 'Resultado:'),
            'ten_items' => substr_count($text, 'Resultado:') === ProductTicketEmailTemplateRenderer::MAX_ITEMS_RENDERED
                && str_contains($text, 'Partida 10:')
                && !str_contains($text, 'Partida 11:'),
            'notice_and_cta' => str_contains($html, 'más partidas')
                && str_contains($text, 'más partidas')
                && str_contains($html, $url)
                && str_contains($text, $url),
            'subject_bytes' => strlen((string) $row['subject']),
            'html_bytes' => strlen($html),
            'text_bytes' => strlen($text),
        ];
    }

    /**
     * @param array<string, mixed> $fixture
     * @return array<string, bool>
     */
    private function determinism(
        array $fixture,
        ProductTicketEmailOutboxRepository $repository,
        ProductTicketEmailTemplatePayloadBuilder $builder,
        ProductTicketEmailTemplateRenderer $renderer
    ): array {
        $ticket = $repository->findTicketContext((int) $fixture['ticket_id']);
        if (!is_array($ticket)) {
            throw new RuntimeException('Determinism fixture ticket was not found.');
        }
        $payload = $builder->build('TICKET_CREADO', $ticket, [], 0);
        $first = $renderer->render('TICKET_CREADO', $payload);
        $second = $renderer->render('TICKET_CREADO', $payload);

        return [
            'subject' => hash('sha256', $first->subject) === hash('sha256', $second->subject),
            'html' => hash('sha256', $first->htmlBody) === hash('sha256', $second->htmlBody),
            'text' => hash('sha256', $first->textBody) === hash('sha256', $second->textBody),
        ];
    }

    /**
     * @param array<string, mixed> $fixture
     * @return array<string, mixed>
     */
    private function invalidPayloadCases(
        array $fixture,
        ProductTicketEmailOutboxRepository $repository,
        ProductTicketEmailTemplatePayloadBuilder $builder,
        ProductTicketEmailTemplateRenderer $renderer
    ): array {
        $ticket = $repository->findTicketContext((int) $fixture['ticket_id']);
        $line = $repository->findLineContext((int) $fixture['ticket_id'], (int) $fixture['approved_id']);
        if (!is_array($ticket) || !is_array($line)) {
            throw new RuntimeException('Invalid payload fixtures were not found.');
        }
        $created = $builder->build('TICKET_CREADO', $ticket, [], 0);
        $approved = $builder->build('PARTIDA_APROBADA', $ticket, [$line], 0);
        $outboxBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM tickets_productos_correos')->fetchColumn();

        $crlfFolio = $created->ticket;
        $crlfFolio['folio'] = self::CORE_FOLIO . "\r\nBcc:qa@example.test";
        $crlfFolioRejected = $this->fails(fn () => $renderer->render(
            'TICKET_CREADO',
            $this->copyPayload($created, ticket: $crlfFolio)
        ));

        $crlfItems = $approved->items;
        $crlfItems[0]['partida_numero'] = "1\r\nBcc:qa@example.test";
        $crlfLineRejected = $this->fails(fn () => $renderer->render(
            'PARTIDA_APROBADA',
            $this->copyPayload($approved, items: $crlfItems)
        ));
        $totalItemsRejected = $this->fails(fn () => $renderer->render(
            'PARTIDA_APROBADA',
            $this->copyPayload($approved, totalItems: 0)
        ));
        $outboxAfter = (int) $this->pdo->query('SELECT COUNT(*) FROM tickets_productos_correos')->fetchColumn();

        return [
            'crlf_folio_rejected' => $crlfFolioRejected,
            'crlf_line_rejected' => $crlfLineRejected,
            'total_items_rejected' => $totalItemsRejected,
            'row_inserted' => $outboxAfter !== $outboxBefore,
        ];
    }

    /**
     * @param array<string, mixed>|null $ticket
     * @param list<array<string, mixed>>|null $items
     */
    private function copyPayload(
        ProductTicketEmailTemplatePayload $payload,
        ?array $ticket = null,
        ?array $items = null,
        ?int $totalItems = null
    ): ProductTicketEmailTemplatePayload {
        return new ProductTicketEmailTemplatePayload(
            $payload->event,
            $ticket ?? $payload->ticket,
            $payload->displayName,
            $items ?? $payload->items,
            $payload->attachmentsCount,
            $totalItems ?? $payload->totalItems,
            $payload->note,
            $payload->ctaUrl,
            $payload->timezone,
            $payload->systemName,
            $payload->brandLabel
        );
    }

    private function fails(callable $callback): bool
    {
        try {
            $callback();
            return false;
        } catch (Throwable) {
            return true;
        }
    }

    /** @param list<array<string, mixed>> $rows @return array<string, mixed> */
    private function domAudit(array $rows): array
    {
        $events = [];
        $allValid = true;
        foreach ($rows as $row) {
            $valid = true;
            $errors = 0;
            if (class_exists(DOMDocument::class)) {
                libxml_use_internal_errors(true);
                $document = new DOMDocument();
                $valid = $document->loadHTML((string) $row['html'], LIBXML_NOWARNING | LIBXML_NOERROR);
                $errors = count(libxml_get_errors());
                libxml_clear_errors();
            }
            $events[(string) $row['evento']] = ['DOM_LOAD' => $valid, 'DOM_ERRORS' => $errors];
            $allValid = $allValid && $valid && $errors === 0;
        }

        return ['valid' => $allValid, 'events' => $events];
    }

    /** @param list<array<string, mixed>> $rows @return array<string, array<string, int>> */
    private function sizes(array $rows): array
    {
        $sizes = [];
        foreach ($rows as $row) {
            $sizes[(string) $row['evento']] = [
                'subject_bytes' => strlen((string) $row['subject']),
                'html_bytes' => strlen((string) $row['html']),
                'text_bytes' => strlen((string) $row['text']),
            ];
        }
        return $sizes;
    }

    /** @param array<string, array<string, int>> $sizes */
    private function sizesNonZero(array $sizes): bool
    {
        foreach ($sizes as $size) {
            if ($size['subject_bytes'] < 1 || $size['html_bytes'] < 1 || $size['text_bytes'] < 1) {
                return false;
            }
        }
        return true;
    }

    private function createUser(): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO usuarios (username, email, password_hash, activo, creado_en)
             VALUES (:username, :email, :password_hash, 1, CURRENT_TIMESTAMP)'
        );
        $statement->execute([
            'username' => 'qa.runtime.persisted',
            'email' => 'qa.runtime.persisted@example.test',
            'password_hash' => password_hash('qa-runtime-persisted', PASSWORD_DEFAULT),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function createCompany(int $userId): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO empresas (codigo, nombre, activo, creado_por)
             VALUES (:codigo, :nombre, 1, :creado_por)'
        );
        $statement->execute([
            'codigo' => 'QARPQ1',
            'nombre' => 'Empresa QA Runtime Persistido',
            'creado_por' => $userId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function createWarehouse(int $companyId, int $userId): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO almacenes (empresa_id, codigo, nombre, activo, creado_por)
             VALUES (:empresa_id, :codigo, :nombre, 1, :creado_por)'
        );
        $statement->execute([
            'empresa_id' => $companyId,
            'codigo' => 'RPQ1',
            'nombre' => 'Almacén QA Runtime Persistido',
            'creado_por' => $userId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return array{unit_id:int,key_id:int} */
    private function satFixture(): array
    {
        $unitId = (int) $this->pdo->query(
            'SELECT id FROM unidades_sat WHERE activo = 1 ORDER BY id LIMIT 1'
        )->fetchColumn();
        $keyId = (int) $this->pdo->query(
            'SELECT id FROM claves_sat WHERE activo = 1 ORDER BY id LIMIT 1'
        )->fetchColumn();
        if ($unitId < 1 || $keyId < 1) {
            throw new RuntimeException('Active SAT fixtures are required for persisted runtime email QA.');
        }
        return ['unit_id' => $unitId, 'key_id' => $keyId];
    }

    private function createTicket(
        int $companyId,
        int $warehouseId,
        int $userId,
        string $folio,
        int $total
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO tickets_productos (
                folio, empresa_id, almacen_id, solicitante_usuario_id, estado,
                observaciones_generales, total_partidas, partidas_en_revision,
                partidas_aprobadas, partidas_rechazadas, created_at
             ) VALUES (
                :folio, :empresa_id, :almacen_id, :solicitante_usuario_id, \'EN_REVISION\',
                :observaciones, :total, 0, :aprobadas, :rechazadas, CURRENT_TIMESTAMP
             )'
        );
        $statement->execute([
            'folio' => $folio,
            'empresa_id' => $companyId,
            'almacen_id' => $warehouseId,
            'solicitante_usuario_id' => $userId,
            'observaciones' => 'Nota QA runtime persistido.',
            'total' => $total,
            'aprobadas' => $total === 2 ? 1 : $total,
            'rechazadas' => $total === 2 ? 1 : 0,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array{unit_id:int,key_id:int} $sat */
    private function createLine(
        int $ticketId,
        int $number,
        string $state,
        string $description,
        ?string $response,
        ?string $reason,
        int $userId,
        array $sat
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO tickets_productos_partidas (
                ticket_producto_id, numero_partida, estado, descripcion,
                unidad_sat_id, clave_sat_id, motivo_rechazo, comentario_resolucion,
                clave_autorizada, descripcion_autorizada,
                unidad_sat_id_autorizada, clave_sat_id_autorizada,
                resuelto_por_usuario_id, resuelto_at, created_at
             ) VALUES (
                :ticket_id, :numero, :estado, :descripcion,
                :unidad_sat_id, :clave_sat_id, :motivo, :respuesta,
                :clave_autorizada, :descripcion_autorizada,
                :unidad_sat_autorizada, :clave_sat_autorizada,
                :usuario_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             )'
        );
        $statement->execute([
            'ticket_id' => $ticketId,
            'numero' => $number,
            'estado' => $state,
            'descripcion' => $description,
            'unidad_sat_id' => $sat['unit_id'],
            'clave_sat_id' => $sat['key_id'],
            'motivo' => $reason,
            'respuesta' => $response,
            'clave_autorizada' => 'QA-' . str_pad((string) $number, 2, '0', STR_PAD_LEFT),
            'descripcion_autorizada' => 'Descripción autorizada QA ' . $number,
            'unidad_sat_autorizada' => $sat['unit_id'],
            'clave_sat_autorizada' => $sat['key_id'],
            'usuario_id' => $userId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function createAttachment(int $ticketId, int $lineId, int $userId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO tickets_productos_adjuntos (
                ticket_producto_id, partida_id, subido_por_usuario_id,
                nombre_original, nombre_guardado, ruta_relativa, mime, extension,
                tamano_bytes, hash_sha256, created_at
             ) VALUES (
                :ticket_id, :partida_id, :user_id,
                :original, :stored, :path, :mime, :extension,
                :bytes, :hash, CURRENT_TIMESTAMP
             )'
        );
        $statement->execute([
            'ticket_id' => $ticketId,
            'partida_id' => $lineId,
            'user_id' => $userId,
            'original' => 'qa-runtime.pdf',
            'stored' => 'qa-runtime-persisted.pdf',
            'path' => 'tickets-productos/qa-runtime-persisted/fixture.pdf',
            'mime' => 'application/pdf',
            'extension' => 'pdf',
            'bytes' => 128,
            'hash' => hash('sha256', 'qa-runtime-persisted'),
        ]);
    }

    private function updateLineState(int $lineId, string $state, ?string $reason, ?string $response): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE tickets_productos_partidas
             SET estado = :estado, motivo_rechazo = :motivo,
                 comentario_resolucion = :respuesta, resuelto_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute([
            'estado' => $state,
            'motivo' => $reason,
            'respuesta' => $response,
            'id' => $lineId,
        ]);
    }

    private function updateTicketState(int $ticketId, string $state, int $approved, int $rejected): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE tickets_productos
             SET estado = :estado, partidas_en_revision = 0,
                 partidas_aprobadas = :aprobadas, partidas_rechazadas = :rechazadas
             WHERE id = :id'
        );
        $statement->execute([
            'estado' => $state,
            'aprobadas' => $approved,
            'rechazadas' => $rejected,
            'id' => $ticketId,
        ]);
    }

    private function cancelTicket(int $ticketId, int $userId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE tickets_productos
             SET estado = 'CANCELADO', cancelado_at = CURRENT_TIMESTAMP,
                 cancelado_por_usuario_id = :user_id,
                 motivo_cancelacion = :motivo
             WHERE id = :id"
        );
        $statement->execute([
            'user_id' => $userId,
            'motivo' => 'Cancelación QA runtime persistido.',
            'id' => $ticketId,
        ]);
    }

    private function outboxCountForTicket(int $ticketId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM tickets_productos_correos WHERE ticket_id = :ticket_id'
        );
        $statement->execute(['ticket_id' => $ticketId]);
        return (int) $statement->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $tickets = $this->pdo->query(
            'SELECT id, folio, estado, total_partidas FROM tickets_productos ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $outbox = $this->pdo->query(
            'SELECT id, ticket_id, partida_id, evento, plantilla, status, intentos,
                    max_intentos, enviado_at, cancelado_at, dedupe_key
             FROM tickets_productos_correos ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $protectedTicket = $this->pdo->query(
            'SELECT id, folio, estado, total_partidas FROM tickets_productos WHERE id = 34'
        )->fetch(PDO::FETCH_ASSOC);
        $protectedOutbox = $this->pdo->query(
            'SELECT id, status, intentos, max_intentos, ultimo_intento_at,
                    error_mensaje_seguro, enviado_at, cancelado_at, dedupe_key
             FROM tickets_productos_correos
             WHERE id IN (1, 36, 698, 699, 700)
             ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);

        return [
            'tickets_count' => count($tickets),
            'outbox_count' => count($outbox),
            'tickets_hash' => hash('sha256', json_encode($tickets, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'outbox_hash' => hash('sha256', json_encode($outbox, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'protected_ticket' => is_array($protectedTicket) ? $protectedTicket : null,
            'protected_outbox' => $protectedOutbox,
            'eligible_count' => (int) $this->pdo->query(
                "SELECT COUNT(*) FROM tickets_productos_correos
                 WHERE status IN ('PENDIENTE', 'ERROR') AND intentos < max_intentos"
            )->fetchColumn(),
        ];
    }

    private function residualRows(): int
    {
        $statement = $this->pdo->prepare(
            'SELECT
                (SELECT COUNT(*) FROM usuarios WHERE username = :username)
                + (SELECT COUNT(*) FROM tickets_productos WHERE folio IN (:core_folio, :long_folio))'
        );
        $statement->execute([
            'username' => 'qa.runtime.persisted',
            'core_folio' => self::CORE_FOLIO,
            'long_folio' => self::LONG_FOLIO,
        ]);
        return (int) $statement->fetchColumn();
    }
};
