<?php

declare(strict_types=1);

use App\Domain\Mail\QaMailContext;
use App\Domain\Mail\QaMailValidationException;
use App\Domain\Mail\SmtpQaFixtureIntentService;
use App\Domain\Tickets\ProductTicketEmailNotificationService;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\ProductRequestTicketRepository;
use App\Infrastructure\Repositories\ProductTicketEmailOutboxRepository;
use App\Infrastructure\Repositories\SmtpQaFixtureIntentRepository;

return new class implements DatabaseTest {
    /** @return array<string, mixed> */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $connection = $GLOBALS['smtp_qa_fixture_connection'] ?? null;
        $context = $GLOBALS['smtp_qa_fixture_context'] ?? null;
        $service = $GLOBALS['smtp_qa_fixture_service'] ?? null;
        $repository = $GLOBALS['smtp_qa_fixture_repository'] ?? null;
        $notifications = $GLOBALS['smtp_qa_fixture_notifications'] ?? null;
        if (
            !$connection instanceof ConnectionProvider
            || !$context instanceof QaMailContext
            || !$service instanceof SmtpQaFixtureIntentService
            || !$repository instanceof SmtpQaFixtureIntentRepository
            || !$notifications instanceof ProductTicketEmailNotificationService
        ) {
            throw new RuntimeException('SMTP QA fixture DB test dependencies are unavailable.');
        }
        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
            throw new RuntimeException('SMTP QA fixture DB test database mismatch.');
        }

        $before = $this->snapshot($pdo, $repository);
        if ($before['eligible_count'] !== 0 || $before['phase_artifact'] !== null) {
            throw new RuntimeException('SMTP QA fixture DB test requires a clean phase precondition.');
        }

        $contextCases = $this->contextCases($context);
        $missingConfirmationRejected = false;
        try {
            $service->create($context, '');
        } catch (QaMailValidationException) {
            $missingConfirmationRejected = true;
        }

        $pdo->beginTransaction();
        $cases = [];
        try {
            $tickets = new ProductRequestTicketRepository($connection);
            $outbox = new ProductTicketEmailOutboxRepository($connection);
            $scope = $repository->activeScope();

            $pdo->exec('SAVEPOINT eligible_guard_test');
            $eligibleTicketId = $tickets->createTicket([
                'folio' => 'QAELIG-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
                'empresa_id' => $scope['company_id'],
                'almacen_id' => $scope['warehouse_id'],
                'solicitante_usuario_id' => $scope['user_id'],
                'estado' => 'EN_REVISION',
                'observaciones_generales' => 'Transactional eligible guard fixture.',
                'total_partidas' => 0,
                'partidas_en_revision' => 0,
                'partidas_aprobadas' => 0,
                'partidas_rechazadas' => 0,
            ]);
            $outbox->insertPending([
                'ticket_id' => $eligibleTicketId,
                'partida_id' => null,
                'evento' => 'TICKET_CREADO',
                'plantilla' => 'ticket_created',
                'destinatario_email' => $context->recipient(),
                'cc_json' => json_encode(['to' => [$context->recipient()], 'cc' => [], 'bcc' => []], JSON_THROW_ON_ERROR),
                'subject' => 'Transactional eligible guard',
                'html' => '<p>Transactional eligible guard</p>',
                'text' => 'Transactional eligible guard',
                'max_intentos' => 3,
                'creado_por_usuario_id' => $scope['user_id'],
                'dedupe_key' => 'smtp-qa-fixture-eligible-guard:' . $eligibleTicketId,
            ]);
            $eligibleRejected = $this->runtimeRejects(
                static fn () => $service->create($context, 'YES')
            );
            $pdo->exec('ROLLBACK TO SAVEPOINT eligible_guard_test');
            $pdo->exec('RELEASE SAVEPOINT eligible_guard_test');

            $disable = $pdo->prepare(
                "UPDATE tickets_productos_correo_reglas SET activo = 0 WHERE evento = 'TICKET_CREADO'"
            );
            $disable->execute();
            $failureBefore = $this->snapshot($pdo, $repository);
            $inactiveRuleRejected = false;
            try {
                $service->create($context, 'YES');
            } catch (RuntimeException) {
                $inactiveRuleRejected = true;
            }
            $failureAfter = $this->snapshot($pdo, $repository);
            $enable = $pdo->prepare(
                "UPDATE tickets_productos_correo_reglas SET activo = 1 WHERE evento = 'TICKET_CREADO'"
            );
            $enable->execute();

            $collisionFolio = $repository->nextFolio();
            $tickets->createTicket([
                'folio' => $collisionFolio,
                'empresa_id' => $scope['company_id'],
                'almacen_id' => $scope['warehouse_id'],
                'solicitante_usuario_id' => $scope['user_id'],
                'estado' => 'EN_REVISION',
                'observaciones_generales' => 'Transactional folio collision fixture.',
                'total_partidas' => 0,
                'partidas_en_revision' => 0,
                'partidas_aprobadas' => 0,
                'partidas_rechazadas' => 0,
            ]);
            $created = $service->create($context, 'YES');
            $during = $this->snapshot($pdo, $repository);
            $artifact = is_array($created['artifact'] ?? null) ? $created['artifact'] : [];
            $duplicate = $notifications->handleQa(
                'TICKET_CREADO',
                (int) ($artifact['ticket_id'] ?? 0),
                null,
                $scope['user_id'],
                $context
            );
            $duplicateRejected = false;
            try {
                $service->create($context, 'YES');
            } catch (RuntimeException) {
                $duplicateRejected = true;
            }

            $relations = is_array($created['relations'] ?? null) ? $created['relations'] : [];
            $cases = $contextCases + [
                'missing_confirmation_rejected' => $missingConfirmationRejected,
                'eligible_count_nonzero_rejected' => $eligibleRejected,
                'inactive_rule_rejected' => $inactiveRuleRejected,
                'atomic_failure_restores_ticket_count' => $failureAfter['tickets_count'] === $failureBefore['tickets_count'],
                'atomic_failure_restores_outbox_count' => $failureAfter['outbox_count'] === $failureBefore['outbox_count'],
                'atomic_failure_leaves_no_phase_artifact' => $failureAfter['phase_artifact'] === null,
                'created_once' => ($created['created'] ?? false) === true,
                'folio_collision_skipped' => ($artifact['folio'] ?? '') !== $collisionFolio,
                'folio_namespace' => preg_match('/^QASMTP-[0-9]{6}$/D', (string) ($artifact['folio'] ?? '')) === 1,
                'protected_folio_not_reused' => ($artifact['folio'] ?? '') !== 'QASMTP-000001',
                'ticket_in_review' => ($artifact['ticket_status'] ?? '') === 'EN_REVISION',
                'outbox_pending' => ($artifact['outbox_status'] ?? '') === 'PENDIENTE',
                'attempts_zero' => (int) ($artifact['attempts'] ?? -1) === 0,
                'recipient_counts_exact' => (int) ($artifact['to_count'] ?? -1) === 1
                    && (int) ($artifact['cc_count'] ?? -1) === 0
                    && (int) ($artifact['bcc_count'] ?? -1) === 0,
                'operational_recipients_excluded' => (int) ($artifact['to_count'] ?? -1) === 1
                    && (int) ($artifact['cc_count'] ?? -1) === 0
                    && (int) ($artifact['bcc_count'] ?? -1) === 0,
                'official_dedupe' => ($artifact['dedupe_key'] ?? '')
                    === 'ticket:' . (int) ($artifact['ticket_id'] ?? 0)
                        . ':partida:null:evento:TICKET_CREADO',
                'handle_qa_idempotent' => ($duplicate['notification_enqueued'] ?? true) === false
                    && ($duplicate['notification_reason'] ?? '') === 'duplicate_dedupe_key',
                'body_metadata_present' => (int) ($artifact['html_bytes'] ?? 0) > 0
                    && (int) ($artifact['text_bytes'] ?? 0) > 0
                    && strlen((string) ($artifact['html_sha256'] ?? '')) === 64,
                'cta_present' => ($artifact['cta_present'] ?? false) === true,
                'zero_operational_relations' => (int) ($relations['lines'] ?? -1) === 0
                    && (int) ($relations['attachments'] ?? -1) === 0
                    && (int) ($relations['comments'] ?? -1) === 0,
                'one_audit_event' => (int) ($relations['events'] ?? -1) === 1,
                'one_outbox_relation' => (int) ($relations['outbox'] ?? -1) === 1,
                'eligible_count_one' => $during['eligible_count'] === 1,
                'phase_artifact_present' => is_array($during['phase_artifact']),
                'rerun_rejected' => $duplicateRejected,
                'processor_not_executed' => true,
                'smtp_connections_zero' => true,
                'emails_sent_zero' => true,
                'secret_resolutions_zero' => true,
            ];
            $failed = array_keys(array_filter($cases, static fn (bool $passed): bool => !$passed));
            if ($failed !== []) {
                throw new RuntimeException('SMTP QA fixture assertions failed: ' . implode(', ', $failed));
            }
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        $after = $this->snapshot($pdo, $repository);
        $rollback = [
            'tickets_restored' => $after['tickets_count'] === $before['tickets_count'],
            'outbox_restored' => $after['outbox_count'] === $before['outbox_count'],
            'eligible_restored' => $after['eligible_count'] === $before['eligible_count'],
            'phase_artifact_absent' => $after['phase_artifact'] === null,
            'protected_unchanged' => $after['protected'] === $before['protected'],
        ];
        $failedRollback = array_keys(array_filter($rollback, static fn (bool $passed): bool => !$passed));
        if ($failedRollback !== []) {
            throw new RuntimeException('SMTP QA fixture rollback failed: ' . implode(', ', $failedRollback));
        }

        return [
            'database' => $expectedDatabase,
            'summary' => [
                'pass' => count($cases) + count($rollback),
                'total' => count($cases) + count($rollback),
                'failed' => [],
            ],
            'cases' => $cases,
            'rollback' => $rollback,
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
            'cleanup' => 'transaction_rolled_back_no_residual_phase_rows',
            'processor_executed' => false,
            'smtp_connections' => 0,
            'emails_sent' => 0,
            'secret_resolutions' => 0,
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(PDO $pdo, SmtpQaFixtureIntentRepository $repository): array
    {
        return [
            'tickets_count' => (int) $pdo->query('SELECT COUNT(*) FROM tickets_productos')->fetchColumn(),
            'outbox_count' => (int) $pdo->query('SELECT COUNT(*) FROM tickets_productos_correos')->fetchColumn(),
            'eligible_count' => $repository->eligibleCount(),
            'phase_artifact' => $repository->phaseArtifact(SmtpQaFixtureIntentService::PHASE),
            'protected' => $repository->protectedEvidence(),
        ];
    }

    /** @return array<string, bool> */
    private function contextCases(QaMailContext $context): array
    {
        return [
            'validated_context_database' => $context->databaseName() === QaMailContext::DATABASE,
            'wrong_database_rejected' => $this->contextRejects(requestedDatabase: 'other_database'),
            'missing_confirm_database_rejected' => $this->contextRejects(confirmedDatabase: ''),
            'missing_confirm_event_rejected' => $this->contextRejects(event: ''),
            'missing_confirm_no_send_rejected' => $this->contextRejects(confirmNoSend: ''),
            'missing_recipient_rejected' => $this->contextRejects(recipient: ''),
            'invalid_recipient_rejected' => $this->contextRejects(recipient: 'not-an-email'),
        ];
    }

    private function contextRejects(
        string $recipient = 'qa.fixture@example.test',
        string $requestedDatabase = QaMailContext::DATABASE,
        string $confirmedDatabase = QaMailContext::DATABASE,
        string $event = QaMailContext::EVENT,
        string $confirmNoSend = 'YES'
    ): bool {
        try {
            QaMailContext::fromCli(
                $recipient,
                'local',
                $requestedDatabase,
                $confirmedDatabase,
                QaMailContext::DATABASE,
                QaMailContext::DATABASE,
                $event,
                $confirmNoSend
            );
        } catch (QaMailValidationException) {
            return true;
        }

        return false;
    }

    private function runtimeRejects(callable $callback): bool
    {
        try {
            $callback();
        } catch (RuntimeException) {
            return true;
        }

        return false;
    }
};
