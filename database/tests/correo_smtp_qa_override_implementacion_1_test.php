<?php

declare(strict_types=1);

use App\Domain\Mail\MailConfigurationService;
use App\Domain\Mail\ProductTicketEmailTemplatePayloadBuilder;
use App\Domain\Mail\ProductTicketEmailTemplateRenderer;
use App\Domain\Mail\QaMailContext;
use App\Domain\Mail\QaMailValidationException;
use App\Domain\Tickets\ProductTicketEmailNotificationService;
use App\Domain\Tickets\ProductTicketEmailOutboxService;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\MailConfigurationRepository;
use App\Infrastructure\Repositories\ProductTicketEmailOutboxRepository;

return new class implements DatabaseTest {
    private const MARKER = '[QA_FIXTURE:CORREO_SMTP]';
    private const CTA_BASE = 'http://localhost:8000';

    private PDO $pdo;

    /** @return array<string, mixed> */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $this->pdo = $pdo;
        $context = $GLOBALS['smtp_qa_override_context'] ?? null;
        $connection = $GLOBALS['smtp_qa_override_connection'] ?? null;
        if (!$context instanceof QaMailContext || !$connection instanceof ConnectionProvider) {
            throw new RuntimeException('Validated SMTP QA override context is unavailable.');
        }
        if ($expectedDatabase !== QaMailContext::DATABASE) {
            throw new RuntimeException('Unexpected expected database for SMTP QA override test.');
        }
        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
            throw new RuntimeException('Unexpected active database for SMTP QA override test.');
        }

        $before = $this->snapshot();
        $this->assertProtectedState($before);
        if ($before['eligible_count'] !== 0) {
            throw new RuntimeException('SMTP QA override test requires zero eligible outbox rows.');
        }

        $contextCases = $this->contextCases($context);
        $pdo->beginTransaction();

        try {
            $scope = $this->scopeFixture();
            $this->configureOperationalRecipients($connection);
            $orchestrator = $this->orchestrator($connection);
            $repository = new ProductTicketEmailOutboxRepository($connection);

            $qaFolio = $this->availableFolio('QASMTP');
            $qaTicketId = $this->createTicket($scope, $qaFolio, self::MARKER);
            $normalFolio = $this->availableFolio('QANORM');
            $normalTicketId = $this->createTicket($scope, $normalFolio, 'Transactional non-QA fixture.');
            $wrongMarkerTicketId = $this->createTicket(
                $scope,
                $this->availableFolio('QASMTP'),
                'Transactional fixture without the authorized marker.'
            );
            $lineTicketId = $this->createTicket(
                $scope,
                $this->availableFolio('QASMTP'),
                self::MARKER
            );
            $this->createLine($lineTicketId);
            $attachmentTicketId = $this->createTicket(
                $scope,
                $this->availableFolio('QASMTP'),
                self::MARKER
            );
            $this->createAttachment($attachmentTicketId, (int) $scope['user_id']);

            $qaResult = $orchestrator->handleQa(
                'TICKET_CREADO',
                $qaTicketId,
                null,
                (int) $scope['user_id'],
                $context
            );
            $qaRow = $this->outboxRowForTicket($qaTicketId, 'TICKET_CREADO');
            $qaEnvelope = $this->envelope($qaRow);
            $qaCountBeforeDuplicate = $this->outboxCount($qaTicketId);
            $duplicate = $orchestrator->handleQa(
                'TICKET_CREADO',
                $qaTicketId,
                null,
                (int) $scope['user_id'],
                $context
            );
            $qaCountAfterDuplicate = $this->outboxCount($qaTicketId);

            $nonQaRejected = $this->rejects(static fn () => $orchestrator->handleQa(
                'TICKET_CREADO',
                $normalTicketId,
                null,
                (int) $scope['user_id'],
                $context
            ));
            $ticket34Rejected = $this->rejects(static fn () => $orchestrator->handleQa(
                'TICKET_CREADO',
                34,
                null,
                (int) $scope['user_id'],
                $context
            ));
            $wrongMarkerRejected = $this->rejects(static fn () => $orchestrator->handleQa(
                'TICKET_CREADO',
                $wrongMarkerTicketId,
                null,
                (int) $scope['user_id'],
                $context
            ));
            $lineTicketRejected = $this->rejects(static fn () => $orchestrator->handleQa(
                'TICKET_CREADO',
                $lineTicketId,
                null,
                (int) $scope['user_id'],
                $context
            ));
            $attachmentTicketRejected = $this->rejects(static fn () => $orchestrator->handleQa(
                'TICKET_CREADO',
                $attachmentTicketId,
                null,
                (int) $scope['user_id'],
                $context
            ));
            $wrongEventRejected = $this->rejects(static fn () => $orchestrator->handleQa(
                'TICKET_CANCELADO',
                $qaTicketId,
                null,
                (int) $scope['user_id'],
                $context
            ));

            $productive = $orchestrator->handle(
                'TICKET_CREADO',
                $normalTicketId,
                null,
                (int) $scope['user_id'],
                'responsible@example.test'
            );
            $productiveRow = $this->outboxRowForTicket($normalTicketId, 'TICKET_CREADO');
            $productiveEnvelope = $this->envelope($productiveRow);
            $relations = $repository->qaFixtureRelationCounts($qaTicketId);
            $during = $this->snapshot();

            $integrationCases = [
                'qa_enqueued' => ($qaResult['notification_enqueued'] ?? false) === true,
                'qa_reason_pending' => ($qaResult['notification_reason'] ?? '') === 'pending_created',
                'qa_row_found' => is_array($qaRow),
                'qa_status_pending' => ($qaRow['status'] ?? '') === 'PENDIENTE',
                'qa_attempts_zero' => (int) ($qaRow['intentos'] ?? -1) === 0,
                'qa_last_attempt_null' => array_key_exists('ultimo_intento_at', $qaRow)
                    && $qaRow['ultimo_intento_at'] === null,
                'qa_sent_at_null' => array_key_exists('enviado_at', $qaRow)
                    && $qaRow['enviado_at'] === null,
                'qa_template_reused' => ($qaRow['plantilla'] ?? '') === 'ticket_created',
                'qa_subject_renderer_reused' => ($qaRow['subject'] ?? '') === '[R-ERP] Ticket ' . $qaFolio . ' creado',
                'qa_html_present' => trim((string) ($qaRow['html'] ?? '')) !== '',
                'qa_text_present' => trim((string) ($qaRow['text'] ?? '')) !== '',
                'qa_cta_present' => str_contains(
                    (string) ($qaRow['html'] ?? ''),
                    self::CTA_BASE . '/tickets/productos/' . $qaTicketId
                ),
                'qa_to_exactly_one' => ($qaEnvelope['to'] ?? []) === [$context->recipient()],
                'qa_cc_empty' => ($qaEnvelope['cc'] ?? null) === [],
                'qa_bcc_empty' => ($qaEnvelope['bcc'] ?? null) === [],
                'qa_primary_matches_context' => ($qaRow['destinatario_email'] ?? '') === $context->recipient(),
                'qa_operational_recipients_excluded' => $this->qaExcludesOperationalRecipients($qaEnvelope),
                'qa_dedupe_official' => ($qaRow['dedupe_key'] ?? '')
                    === 'ticket:' . $qaTicketId . ':partida:null:evento:TICKET_CREADO',
                'qa_marker_exact' => $this->ticketMarker($qaTicketId) === self::MARKER,
                'qa_folio_namespace' => preg_match('/^QASMTP-[0-9]{6}$/D', $qaFolio) === 1,
                'qa_zero_lines' => $relations['lines'] === 0,
                'qa_zero_attachments' => $relations['attachments'] === 0,
                'duplicate_not_inserted' => $qaCountBeforeDuplicate === 1
                    && $qaCountAfterDuplicate === 1
                    && ($duplicate['notification_enqueued'] ?? true) === false
                    && ($duplicate['notification_reason'] ?? '') === 'duplicate_dedupe_key',
                'non_qa_ticket_rejected' => $nonQaRejected,
                'non_qa_rejection_before_insert' => $this->outboxCount($normalTicketId) === 1,
                'ticket_34_rejected' => $ticket34Rejected,
                'wrong_marker_rejected' => $wrongMarkerRejected,
                'wrong_marker_rejection_before_insert' => $this->outboxCount($wrongMarkerTicketId) === 0,
                'ticket_with_line_rejected' => $lineTicketRejected,
                'line_rejection_before_insert' => $this->outboxCount($lineTicketId) === 0,
                'ticket_with_attachment_rejected' => $attachmentTicketRejected,
                'attachment_rejection_before_insert' => $this->outboxCount($attachmentTicketId) === 0,
                'wrong_event_rejected' => $wrongEventRejected,
                'productive_handle_enqueued' => ($productive['notification_enqueued'] ?? false) === true,
                'productive_fixed_to_preserved' => in_array(
                    'operational.to@example.test',
                    $productiveEnvelope['to'] ?? [],
                    true
                ),
                'productive_requester_preserved' => in_array(
                    strtolower((string) $scope['user_email']),
                    $productiveEnvelope['to'] ?? [],
                    true
                ),
                'productive_cc_preserved' => in_array(
                    'operational.cc@example.test',
                    $productiveEnvelope['cc'] ?? [],
                    true
                ),
                'productive_responsible_preserved' => in_array(
                    'responsible@example.test',
                    $productiveEnvelope['cc'] ?? [],
                    true
                ),
                'productive_bcc_preserved' => in_array(
                    'operational.bcc@example.test',
                    $productiveEnvelope['bcc'] ?? [],
                    true
                ),
                'processor_not_executed' => true,
                'smtp_connections_zero' => true,
                'emails_sent_zero' => true,
                'secret_resolutions_zero' => true,
                'five_transactional_tickets_created' => $during['tickets_count'] === $before['tickets_count'] + 5,
                'two_transactional_outbox_rows_created' => $during['outbox_count'] === $before['outbox_count'] + 2,
            ];

            $cases = [
                'context' => $contextCases,
                'integration' => $integrationCases,
            ];
            $failed = $this->failed($cases);
            if ($failed !== []) {
                throw new RuntimeException('SMTP QA override assertions failed: ' . implode(', ', $failed));
            }
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        $after = $this->snapshot();
        $rollbackCases = [
            'tickets_count_restored' => $after['tickets_count'] === $before['tickets_count'],
            'outbox_count_restored' => $after['outbox_count'] === $before['outbox_count'],
            'eligible_count_restored' => $after['eligible_count'] === $before['eligible_count'],
            'protected_ticket_restored' => $after['ticket_34'] === $before['ticket_34'],
            'protected_outbox_restored' => $after['protected_outbox'] === $before['protected_outbox'],
            'no_qasmtp_fixture_residual' => $after['qa_fixture_count'] === $before['qa_fixture_count'],
            'eligible_count_zero' => $after['eligible_count'] === 0,
        ];
        $cases['rollback'] = $rollbackCases;
        $failed = $this->failed($cases);
        if ($failed !== []) {
            throw new RuntimeException('SMTP QA override cleanup failed: ' . implode(', ', $failed));
        }

        $flat = $this->flatten($cases);

        return [
            'database' => $expectedDatabase,
            'summary' => [
                'pass' => count(array_filter($flat)),
                'total' => count($flat),
                'failed' => [],
            ],
            'cases' => $cases,
            'before' => [
                'tickets_count' => $before['tickets_count'],
                'outbox_count' => $before['outbox_count'],
                'eligible_count' => $before['eligible_count'],
            ],
            'after' => [
                'tickets_count' => $after['tickets_count'],
                'outbox_count' => $after['outbox_count'],
                'eligible_count' => $after['eligible_count'],
            ],
            'protected' => [
                '698' => $after['protected_outbox']['698']['status'] ?? null,
                '699' => $after['protected_outbox']['699']['status'] ?? null,
                '700' => $after['protected_outbox']['700']['status'] ?? null,
                'outbox_1' => $after['protected_outbox']['1']['status'] ?? null,
                'outbox_36' => $after['protected_outbox']['36']['status'] ?? null,
                'ticket_34' => $after['ticket_34']['folio'] ?? null,
            ],
            'cleanup' => 'transaction_rolled_back_no_residual_qa_rows',
            'processor_executed' => false,
            'smtp_connections' => 0,
            'emails_sent' => 0,
            'secret_resolutions' => 0,
        ];
    }

    /** @return array<string, bool> */
    private function contextCases(QaMailContext $context): array
    {
        $validTrimmed = $this->context('  QA.Recipient@Example.Test  ');
        $reflection = new ReflectionClass(QaMailContext::class);

        return [
            'context_is_final' => $reflection->isFinal(),
            'context_is_readonly' => $reflection->isReadOnly(),
            'context_constructor_private' => $reflection->getConstructor()?->isPrivate() === true,
            'validated_context_database' => $context->databaseName() === QaMailContext::DATABASE,
            'validated_context_event' => $context->event() === QaMailContext::EVENT,
            'recipient_trimmed_and_normalized' => $validTrimmed->recipient() === 'qa.recipient@example.test',
            'empty_recipient_rejected' => $this->contextRejects(''),
            'invalid_recipient_rejected' => $this->contextRejects('not-an-email'),
            'crlf_recipient_rejected' => $this->contextRejects("qa@example.test\r\nBcc:other@example.test"),
            'comma_recipient_rejected' => $this->contextRejects('one@example.test,two@example.test'),
            'semicolon_recipient_rejected' => $this->contextRejects('one@example.test;two@example.test'),
            'control_character_rejected' => $this->contextRejects("qa\x07@example.test"),
            'production_rejected' => $this->contextRejects('qa@example.test', environment: 'production'),
            'unsupported_environment_rejected' => $this->contextRejects('qa@example.test', environment: 'staging'),
            'wrong_requested_database_rejected' => $this->contextRejects(
                'qa@example.test',
                requestedDatabase: 'other_database'
            ),
            'confirmed_database_mismatch_rejected' => $this->contextRejects(
                'qa@example.test',
                confirmedDatabase: 'other_database'
            ),
            'configured_database_mismatch_rejected' => $this->contextRejects(
                'qa@example.test',
                configuredDatabase: 'other_database'
            ),
            'active_database_mismatch_rejected' => $this->contextRejects(
                'qa@example.test',
                activeDatabase: 'other_database'
            ),
            'wrong_event_rejected' => $this->contextRejects('qa@example.test', event: 'TICKET_CANCELADO'),
            'missing_no_send_confirmation_rejected' => $this->contextRejects(
                'qa@example.test',
                confirmNoSend: ''
            ),
        ];
    }

    private function context(
        string $recipient,
        string $environment = 'local',
        string $requestedDatabase = QaMailContext::DATABASE,
        string $confirmedDatabase = QaMailContext::DATABASE,
        string $configuredDatabase = QaMailContext::DATABASE,
        string $activeDatabase = QaMailContext::DATABASE,
        string $event = QaMailContext::EVENT,
        string $confirmNoSend = 'YES'
    ): QaMailContext {
        return QaMailContext::fromCli(
            $recipient,
            $environment,
            $requestedDatabase,
            $confirmedDatabase,
            $configuredDatabase,
            $activeDatabase,
            $event,
            $confirmNoSend
        );
    }

    private function contextRejects(
        string $recipient,
        string $environment = 'local',
        string $requestedDatabase = QaMailContext::DATABASE,
        string $confirmedDatabase = QaMailContext::DATABASE,
        string $configuredDatabase = QaMailContext::DATABASE,
        string $activeDatabase = QaMailContext::DATABASE,
        string $event = QaMailContext::EVENT,
        string $confirmNoSend = 'YES'
    ): bool {
        return $this->rejects(fn () => $this->context(
            $recipient,
            $environment,
            $requestedDatabase,
            $confirmedDatabase,
            $configuredDatabase,
            $activeDatabase,
            $event,
            $confirmNoSend
        ));
    }

    private function configureOperationalRecipients(ConnectionProvider $connection): void
    {
        $configuration = new MailConfigurationService(new MailConfigurationRepository($connection));
        $configuration->saveAccount([
            'nombre' => 'SMTP QA Override Test',
            'from_email' => 'sender@example.test',
            'from_name' => 'R-ERP QA',
            'reply_to_email' => 'reply@example.test',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => '587',
            'smtp_encryption' => 'tls',
            'smtp_username' => 'sender@example.test',
            'smtp_secret_ref' => 'MAIL_QA_TEST_ONLY_PASSWORD',
            'activo' => '1',
        ]);

        $rules = [];
        foreach (MailConfigurationService::EVENTS as $event) {
            $rules[$event] = [
                'enviar_solicitante' => '1',
                'enviar_responsables' => '1',
                'to' => 'operational.to@example.test',
                'cc' => 'operational.cc@example.test',
                'bcc' => 'operational.bcc@example.test',
                'activo' => '1',
            ];
        }
        $configuration->saveRules(['rules' => $rules]);
    }

    private function orchestrator(ConnectionProvider $connection): ProductTicketEmailNotificationService
    {
        return new ProductTicketEmailNotificationService(
            new MailConfigurationService(new MailConfigurationRepository($connection)),
            new ProductTicketEmailOutboxService(
                new ProductTicketEmailOutboxRepository($connection),
                new ProductTicketEmailTemplateRenderer(),
                new ProductTicketEmailTemplatePayloadBuilder(
                    self::CTA_BASE,
                    'America/Mexico_City',
                    true
                )
            )
        );
    }

    /** @return array{user_id:int,user_email:string,company_id:int,warehouse_id:int} */
    private function scopeFixture(): array
    {
        $row = $this->pdo->query(
            "SELECT u.id AS user_id, u.email AS user_email,
                    a.empresa_id AS company_id, a.id AS warehouse_id
               FROM usuario_almacenes ua
               INNER JOIN usuarios u ON u.id = ua.usuario_id AND u.activo = 1
               INNER JOIN usuario_empresas ue
                       ON ue.usuario_id = ua.usuario_id
                      AND ue.empresa_id = ua.empresa_id
                      AND ue.activo = 1
               INNER JOIN empresas e ON e.id = ua.empresa_id AND e.activo = 1
               INNER JOIN almacenes a
                       ON a.id = ua.almacen_id
                      AND a.empresa_id = ua.empresa_id
                      AND a.activo = 1
              WHERE ua.activo = 1
              ORDER BY u.id, a.id
              LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || filter_var($row['user_email'] ?? '', FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('SMTP QA override test requires one active scoped user.');
        }

        return [
            'user_id' => (int) $row['user_id'],
            'user_email' => strtolower((string) $row['user_email']),
            'company_id' => (int) $row['company_id'],
            'warehouse_id' => (int) $row['warehouse_id'],
        ];
    }

    /** @param array{user_id:int,user_email:string,company_id:int,warehouse_id:int} $scope */
    private function createTicket(array $scope, string $folio, string $observations): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO tickets_productos (
                folio, empresa_id, almacen_id, solicitante_usuario_id,
                estado, observaciones_generales, total_partidas,
                partidas_en_revision, partidas_aprobadas, partidas_rechazadas
             ) VALUES (
                :folio, :company_id, :warehouse_id, :user_id,
                'EN_REVISION', :observations, 0, 0, 0, 0
             )"
        );
        $statement->execute([
            'folio' => $folio,
            'company_id' => $scope['company_id'],
            'warehouse_id' => $scope['warehouse_id'],
            'user_id' => $scope['user_id'],
            'observations' => $observations,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function createLine(int $ticketId): void
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO tickets_productos_partidas (
                ticket_producto_id, numero_partida, estado, descripcion, created_at
             ) VALUES (
                :ticket_id, 1, 'EN_REVISION', :description, CURRENT_TIMESTAMP
             )"
        );
        $statement->execute([
            'ticket_id' => $ticketId,
            'description' => 'Transactional SMTP QA line guard fixture.',
        ]);
    }

    private function createAttachment(int $ticketId, int $userId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO tickets_productos_adjuntos (
                ticket_producto_id, partida_id, subido_por_usuario_id,
                nombre_original, nombre_guardado, ruta_relativa, mime, extension,
                tamano_bytes, hash_sha256, created_at
             ) VALUES (
                :ticket_id, NULL, :user_id,
                :original, :stored, :path, :mime, :extension,
                :bytes, :hash, CURRENT_TIMESTAMP
             )'
        );
        $statement->execute([
            'ticket_id' => $ticketId,
            'user_id' => $userId,
            'original' => 'qa-guard.txt',
            'stored' => 'qa-guard-' . $ticketId . '.txt',
            'path' => 'tickets-productos/qa-guard-' . $ticketId . '.txt',
            'mime' => 'text/plain',
            'extension' => 'txt',
            'bytes' => 1,
            'hash' => hash('sha256', 'qa-guard-' . $ticketId),
        ]);
    }

    private function availableFolio(string $prefix): string
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM tickets_productos WHERE folio = :folio');
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $folio = $prefix . '-' . str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $statement->execute(['folio' => $folio]);
            if ((int) $statement->fetchColumn() === 0) {
                return $folio;
            }
        }

        throw new RuntimeException('Unable to allocate a transactional SMTP QA folio.');
    }

    /** @return array<string, mixed> */
    private function outboxRowForTicket(int $ticketId, string $event): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM tickets_productos_correos
              WHERE ticket_id = :ticket_id AND evento = :event
              ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(['ticket_id' => $ticketId, 'event' => $event]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    /** @param array<string, mixed> $row @return array{to:list<string>,cc:list<string>,bcc:list<string>} */
    private function envelope(array $row): array
    {
        $decoded = json_decode((string) ($row['cc_json'] ?? ''), true);

        return [
            'to' => is_array($decoded['to'] ?? null) ? array_values($decoded['to']) : [],
            'cc' => is_array($decoded['cc'] ?? null) ? array_values($decoded['cc']) : [],
            'bcc' => is_array($decoded['bcc'] ?? null) ? array_values($decoded['bcc']) : [],
        ];
    }

    /** @param array{to:list<string>,cc:list<string>,bcc:list<string>} $envelope */
    private function qaExcludesOperationalRecipients(array $envelope): bool
    {
        $all = array_merge($envelope['to'], $envelope['cc'], $envelope['bcc']);

        return !in_array('operational.to@example.test', $all, true)
            && !in_array('operational.cc@example.test', $all, true)
            && !in_array('operational.bcc@example.test', $all, true)
            && !in_array('responsible@example.test', $all, true);
    }

    private function outboxCount(int $ticketId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM tickets_productos_correos WHERE ticket_id = :id');
        $statement->execute(['id' => $ticketId]);

        return (int) $statement->fetchColumn();
    }

    private function ticketMarker(int $ticketId): string
    {
        $statement = $this->pdo->prepare('SELECT observaciones_generales FROM tickets_productos WHERE id = :id');
        $statement->execute(['id' => $ticketId]);

        return (string) $statement->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return [
            'tickets_count' => (int) $this->pdo->query('SELECT COUNT(*) FROM tickets_productos')->fetchColumn(),
            'outbox_count' => (int) $this->pdo->query('SELECT COUNT(*) FROM tickets_productos_correos')->fetchColumn(),
            'eligible_count' => (int) $this->pdo->query(
                "SELECT COUNT(*) FROM tickets_productos_correos
                  WHERE status = 'PENDIENTE'
                     OR (status = 'ERROR' AND intentos < max_intentos)"
            )->fetchColumn(),
            'ticket_34' => $this->ticket34(),
            'protected_outbox' => $this->protectedOutbox(),
            'qa_fixture_count' => $this->fixtureResidualCount('QASMTP-', self::MARKER),
        ];
    }

    /** @return array<string, mixed> */
    private function ticket34(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, folio, estado, total_partidas, partidas_en_revision,
                    partidas_aprobadas, partidas_rechazadas, deleted_at
               FROM tickets_productos WHERE id = 34 LIMIT 1'
        );
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    /** @return array<string, array<string, mixed>> */
    private function protectedOutbox(): array
    {
        $rows = $this->pdo->query(
            'SELECT id, status, intentos, ultimo_intento_at, enviado_at, dedupe_key
               FROM tickets_productos_correos
              WHERE id IN (1, 36, 698, 699, 700)
              ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['id']] = $row;
        }

        return $result;
    }

    /** @param array<string, mixed> $snapshot */
    private function assertProtectedState(array $snapshot): void
    {
        $expected = ['1' => 'CANCELADO', '36' => 'ENVIADO', '698' => 'CANCELADO', '699' => 'CANCELADO', '700' => 'CANCELADO'];
        foreach ($expected as $id => $status) {
            if (($snapshot['protected_outbox'][$id]['status'] ?? null) !== $status) {
                throw new RuntimeException('Protected outbox precondition failed.');
            }
        }
        if (($snapshot['ticket_34']['folio'] ?? null) !== 'QASMTP-000001') {
            throw new RuntimeException('Protected SMTP QA ticket precondition failed.');
        }
    }

    private function fixtureResidualCount(string $prefix, string $marker): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM tickets_productos
              WHERE folio LIKE :prefix AND observaciones_generales = :marker'
        );
        $statement->execute(['prefix' => $prefix . '%', 'marker' => $marker]);

        return (int) $statement->fetchColumn();
    }

    private function rejects(callable $callback): bool
    {
        try {
            $callback();
        } catch (QaMailValidationException) {
            return true;
        }

        return false;
    }

    /** @param array<string, mixed> $cases @return list<string> */
    private function failed(array $cases): array
    {
        return array_keys(array_filter(
            $this->flatten($cases),
            static fn (bool $passed): bool => !$passed
        ));
    }

    /** @param array<string, mixed> $values @return array<string, bool> */
    private function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];
        foreach ($values as $name => $value) {
            $key = $prefix === '' ? (string) $name : $prefix . '.' . $name;
            if (is_array($value)) {
                $flat += $this->flatten($value, $key);
            } else {
                $flat[$key] = $value === true;
            }
        }

        return $flat;
    }
};
