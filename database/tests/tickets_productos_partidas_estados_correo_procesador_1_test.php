<?php

declare(strict_types=1);

use App\Domain\Mail\MailOutboxProcessor;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Mail\FakeMailTransport;
use App\Infrastructure\Repositories\MailConfigurationRepository;
use App\Infrastructure\Repositories\ProductTicketEmailOutboxRepository;

return new class implements DatabaseTest {
    private PDO $pdo;
    private int $ticketId;
    /** @var list<int> */
    private array $outboxIds = [];
    private int $sequence = 0;

    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $this->pdo = $pdo;
        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
            throw new RuntimeException('Unexpected database for mail processor test.');
        }
        foreach (['tickets_productos', 'tickets_productos_correos', 'mail_accounts'] as $table) {
            if (!$this->tableExists($table)) {
                throw new RuntimeException('Required mail processor table is missing: ' . $table);
            }
        }
        if ($this->eligibleCount() !== 0) {
            throw new RuntimeException('Mail processor test requires an empty eligible outbox.');
        }

        $countsBefore = $this->operationalCounts();
        $this->ticketId = $this->createTicketFixture();

        try {
            $repository = new ProductTicketEmailOutboxRepository($pdo);
            $configuration = new MailConfigurationRepository($GLOBALS['tp_mail_processor_connection']);
            $secretResolutions = 0;
            $fake = new FakeMailTransport('success');
            $processor = new MailOutboxProcessor(
                $repository,
                $configuration,
                $fake,
                static function () use (&$secretResolutions): ?string {
                    $secretResolutions++;
                    throw new RuntimeException('Fake transport must not resolve secrets.');
                }
            );

            $pendingId = $this->insertOutbox('PENDIENTE', 0, 3, [
                'to' => ['SECOND@EXAMPLE.TEST', 'qa.primary@example.test'],
                'cc' => ['second@example.test', 'CC@EXAMPLE.TEST', 'invalid address'],
                'bcc' => ['cc@example.test', 'BCC@EXAMPLE.TEST'],
            ]);
            $dryBefore = $processor->dryRun(10);
            $beforeDryRow = $repository->findOutboxById($pendingId);
            $success = $processor->process(1);
            $sentRow = $repository->findOutboxById($pendingId);
            $delivery = $fake->deliveries()[0] ?? [];
            $message = is_array($delivery['message'] ?? null) ? $delivery['message'] : [];
            $account = is_array($delivery['account'] ?? null) ? $delivery['account'] : [];
            $expectedAccount = $configuration->primaryAccount() ?? [];

            $fake->scenario('auth_error');
            $retryId = $this->insertOutbox('ERROR', 0, 3);
            $authFailure = $processor->process(2);
            $authRow = $repository->findOutboxById($retryId);
            $fake->scenario('success');
            $retrySuccess = $processor->process(1);
            $retryRow = $repository->findOutboxById($retryId);

            $errorCases = [];
            foreach ([
                'connection_error' => 'No fue posible conectar con el servidor de correo.',
                'timeout' => 'El servidor de correo no respondió a tiempo.',
                'recipient_rejection' => 'El servidor rechazó uno o más destinatarios.',
                'unexpected_error' => 'No fue posible enviar el correo.',
            ] as $scenario => $expectedMessage) {
                $id = $this->insertOutbox('PENDIENTE', 0, 1);
                $fake->scenario($scenario);
                $processor->process(1);
                $row = $repository->findOutboxById($id);
                $errorCases[$scenario] = $row['status'] === 'ERROR'
                    && $row['enviado_at'] === null
                    && $row['error_mensaje_seguro'] === $expectedMessage
                    && !$this->containsSensitive((string) $row['error_mensaje_seguro']);
            }

            $sentExcludedId = $this->insertOutbox('ENVIADO', 1, 3);
            $cancelledExcludedId = $this->insertOutbox('CANCELADO', 0, 3);
            $exhaustedId = $this->insertOutbox('ERROR', 3, 3);
            $previewExcluded = $processor->dryRun(10);

            $claimId = $this->insertOutbox('PENDIENTE', 0, 1);
            $firstClaim = $repository->claimNextEligible();
            $secondClaim = $repository->claimNextEligible();
            $claimedRow = $repository->findOutboxById($claimId);
            $repository->markClaimError($claimId, 'No fue posible enviar el correo.');

            $staleId = $this->insertOutbox('ENVIANDO', 1, 1, null, '-20 minutes');
            $recovered = $processor->recoverStaleProcessing();
            $staleRow = $repository->findOutboxById($staleId);

            $allPreviewIds = array_column($previewExcluded, 'id');
            $sourceChecks = $this->sourceChecks();
            $cases = [
                'selection_and_claim' => [
                    'selects_pending' => in_array($pendingId, array_column($dryBefore, 'id'), true),
                    'selects_eligible_error' => ($authFailure['processed'][0]['id'] ?? null) === $retryId,
                    'does_not_select_sent' => !in_array($sentExcludedId, $allPreviewIds, true),
                    'does_not_select_cancelled' => !in_array($cancelledExcludedId, $allPreviewIds, true),
                    'does_not_select_exhausted_error' => !in_array($exhaustedId, $allPreviewIds, true),
                    'claim_sets_sending' => $claimedRow['status'] === 'ENVIANDO',
                    'claim_increments_attempts' => (int) $claimedRow['intentos'] === 1,
                    'claim_sets_last_attempt' => $claimedRow['ultimo_intento_at'] !== null,
                    'second_claim_cannot_claim_same_row' => (int) ($firstClaim['id'] ?? 0) === $claimId && $secondClaim === null,
                ],
                'success_and_retry' => [
                    'fake_success_marks_sent' => $sentRow['status'] === 'ENVIADO',
                    'success_sets_sent_at' => $sentRow['enviado_at'] !== null,
                    'success_clears_error' => $sentRow['error_mensaje_seguro'] === null,
                    'error_is_retryable' => $authRow['status'] === 'ERROR' && (int) $authRow['intentos'] === 1,
                    'no_immediate_retry_in_same_batch' => count($authFailure['processed']) === 1,
                    'retry_can_succeed' => $retryRow['status'] === 'ENVIADO' && (int) $retryRow['intentos'] === 2,
                    'max_attempts_respected' => (int) $repository->findOutboxById($exhaustedId)['intentos'] === 3,
                    'auth_error_is_safe' => $authRow['error_mensaje_seguro'] === 'El servidor de correo rechazó la autenticación.',
                    'all_fake_error_modes_safe' => !in_array(false, $errorCases, true),
                ],
                'stale' => [
                    'stale_recovered' => $recovered === 1 && $staleRow['status'] === 'ERROR',
                    'stale_message_safe' => $staleRow['error_mensaje_seguro'] === 'Procesamiento anterior interrumpido.',
                ],
                'envelope' => [
                    'primary_to_kept' => ($message['to'][0] ?? null) === 'qa.primary@example.test',
                    'cc_json_to_used' => in_array('second@example.test', $message['to'] ?? [], true),
                    'cc_kept' => ($message['cc'] ?? []) === ['cc@example.test'],
                    'bcc_kept_as_bcc' => ($message['bcc'] ?? []) === ['bcc@example.test'],
                    'invalid_email_removed' => !in_array('invalid address', array_merge($message['to'] ?? [], $message['cc'] ?? [], $message['bcc'] ?? []), true),
                    'dedupe_precedence' => !in_array('second@example.test', $message['cc'] ?? [], true)
                        && !in_array('cc@example.test', $message['bcc'] ?? [], true),
                ],
                'account_and_payload' => [
                    'from_email_used' => ($account['from_email'] ?? null) === strtolower((string) ($expectedAccount['from_email'] ?? '')),
                    'from_name_used' => ($account['from_name'] ?? null) === ($expectedAccount['from_name'] ?? null),
                    'reply_to_used' => ($account['reply_to_email'] ?? null) === ($expectedAccount['reply_to_email'] ?? null),
                    'host_used' => ($account['smtp_host'] ?? null) === ($expectedAccount['smtp_host'] ?? null),
                    'port_used' => (int) ($account['smtp_port'] ?? 0) === (int) ($expectedAccount['smtp_port'] ?? 0),
                    'encryption_used' => ($account['smtp_encryption'] ?? null) === ($expectedAccount['smtp_encryption'] ?? null),
                    'username_used' => ($account['smtp_username'] ?? null) === ($expectedAccount['smtp_username'] ?? null),
                    'stored_subject_used' => ($message['subject'] ?? null) === 'QA mail processor',
                    'stored_bodies_used' => ($message['html'] ?? null) === '<p>QA processor</p>'
                        && ($message['text'] ?? null) === 'QA processor',
                    'no_secret_given_to_fake' => !array_key_exists('smtp_password', $account),
                ],
                'dry_run_and_security' => [
                    'dry_run_does_not_change_row' => $beforeDryRow['status'] === 'PENDIENTE'
                        && (int) $beforeDryRow['intentos'] === 0,
                    'fake_never_resolves_secret' => $secretResolutions === 0,
                    'dry_run_has_safe_metadata_only' => $this->dryRunIsSafe($dryBefore),
                    'no_raw_exception_persisted' => !in_array(false, $errorCases, true),
                    'no_operational_writes' => $countsBefore === $this->operationalCounts(),
                ] + $sourceChecks,
            ];

            $this->assertAll($cases);

            return [
                'database' => $expectedDatabase,
                'cases' => $cases,
                'fake_scenarios' => array_keys($errorCases),
                'secret_resolutions' => $secretResolutions,
                'network_connections' => 0,
                'real_emails_sent' => 0,
                'semantics' => 'at-least-once',
                'cleanup' => 'fixture_rows_removed',
            ];
        } finally {
            $this->cleanup();
        }
    }

    private function createTicketFixture(): int
    {
        $parent = $this->pdo->query(
            'SELECT a.empresa_id, a.id AS almacen_id, u.id AS usuario_id
             FROM almacenes a CROSS JOIN usuarios u
             WHERE a.activo = 1 AND u.activo = 1
             ORDER BY a.id, u.id LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        if (!is_array($parent)) {
            throw new RuntimeException('Mail processor test parents are unavailable.');
        }
        $folio = 'QM-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        $statement = $this->pdo->prepare(
            "INSERT INTO tickets_productos (
                folio, empresa_id, almacen_id, solicitante_usuario_id, estado, observaciones_generales
             ) VALUES (:folio, :empresa, :almacen, :usuario, 'EN_REVISION', 'QA mail processor')"
        );
        $statement->execute([
            'folio' => $folio,
            'empresa' => $parent['empresa_id'],
            'almacen' => $parent['almacen_id'],
            'usuario' => $parent['usuario_id'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,list<string>>|null $envelope */
    private function insertOutbox(string $status, int $attempts, int $max, ?array $envelope = null, ?string $attemptOffset = null): int
    {
        $this->sequence++;
        $envelope ??= ['to' => ['qa.primary@example.test'], 'cc' => [], 'bcc' => []];
        $sentAt = $status === 'ENVIADO' ? date('Y-m-d H:i:s') : null;
        $cancelledAt = $status === 'CANCELADO' ? date('Y-m-d H:i:s') : null;
        $lastAttempt = $attemptOffset === null ? null : date('Y-m-d H:i:s', strtotime($attemptOffset));
        $statement = $this->pdo->prepare(
            'INSERT INTO tickets_productos_correos (
                ticket_id, evento, plantilla, destinatario_email, cc_json, subject, html, text,
                status, intentos, max_intentos, ultimo_intento_at, enviado_at, cancelado_at,
                dedupe_key, created_at
             ) VALUES (
                :ticket_id, \'TICKET_CREADO\', \'ticket_created\', \'qa.primary@example.test\',
                :cc_json, \'QA mail processor\', \'<p>QA processor</p>\', \'QA processor\',
                :status, :intentos, :max_intentos, :ultimo_intento_at, :enviado_at, :cancelado_at,
                :dedupe_key, CURRENT_TIMESTAMP
             )'
        );
        $statement->execute([
            'ticket_id' => $this->ticketId,
            'cc_json' => json_encode($envelope, JSON_THROW_ON_ERROR),
            'status' => $status,
            'intentos' => $attempts,
            'max_intentos' => $max,
            'ultimo_intento_at' => $lastAttempt,
            'enviado_at' => $sentAt,
            'cancelado_at' => $cancelledAt,
            'dedupe_key' => 'qa-mail-processor-' . $this->ticketId . '-' . $this->sequence,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->outboxIds[] = $id;

        if ($attemptOffset !== null) {
            $stale = $this->pdo->prepare(
                'UPDATE tickets_productos_correos
                 SET ultimo_intento_at = DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 20 MINUTE)
                 WHERE id = :id'
            );
            $stale->execute(['id' => $id]);
        }

        return $id;
    }

    /** @return array<string,bool> */
    private function sourceChecks(): array
    {
        $phpMailer = $this->read('app/Infrastructure/Mail/PHPMailerMailTransport.php');
        $tickets = $this->read('app/Domain/Tickets/ProductRequestTicketService.php')
            . $this->read('app/Domain/Tickets/ProductTicketEmailNotificationService.php')
            . $this->read('app/Http/Controllers/ProductRequestTicketController.php');
        $runner = $this->read('database/tickets-productos-partidas-estados-correo-procesador.php');

        return [
            'phpmailer_only_in_mail_infrastructure' => str_contains($phpMailer, 'PHPMailer\\PHPMailer')
                && !str_contains($tickets, 'PHPMailer'),
            'no_smtp_in_ticket_runtime' => preg_match('/isSMTP|smtpConnect|SMTPDebug/i', $tickets) !== 1,
            'no_mail_function' => preg_match('/\bmail\s*\(/i', $phpMailer . $tickets) !== 1,
            'no_insecure_tls_options' => preg_match('/verify_peer\s*[^\n]*false|allow_self_signed\s*[^\n]*true/i', $phpMailer) !== 1,
            'no_worker_or_cron' => preg_match('/\bworker\b|\bcron\b/i', $runner) !== 1,
            'no_external_http' => preg_match('/curl_|file_get_contents\s*\(\s*[\'\"]https?:/i', $runner . $phpMailer) !== 1,
            'real_process_requires_confirmation' => str_contains($runner, 'confirm-real-email')
                && str_contains($runner, "!== 'YES'"),
        ];
    }

    /** @param list<array<string,mixed>> $rows */
    private function dryRunIsSafe(array $rows): bool
    {
        $allowed = ['id', 'evento', 'status', 'intentos', 'max_intentos', 'plantilla', 'recipient_domain'];
        foreach ($rows as $row) {
            if (array_diff(array_keys($row), $allowed) !== []) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,mixed> $groups */
    private function assertAll(array $groups): void
    {
        $failed = [];
        foreach ($groups as $group => $checks) {
            foreach ($checks as $name => $passed) {
                if ($passed !== true) {
                    $failed[] = $group . '.' . $name;
                }
            }
        }
        if ($failed !== []) {
            throw new RuntimeException('Mail processor assertions failed: ' . implode(', ', $failed));
        }
    }

    private function cleanup(): void
    {
        if (isset($this->ticketId)) {
            $statement = $this->pdo->prepare('DELETE FROM tickets_productos WHERE id = :id');
            $statement->execute(['id' => $this->ticketId]);
        }
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table'
        );
        $statement->execute(['table' => $table]);
        return (int) $statement->fetchColumn() === 1;
    }

    private function eligibleCount(): int
    {
        return (int) $this->pdo->query(
            "SELECT COUNT(*) FROM tickets_productos_correos
             WHERE status = 'PENDIENTE' OR (status = 'ERROR' AND intentos < max_intentos)"
        )->fetchColumn();
    }

    /** @return array<string,int> */
    private function operationalCounts(): array
    {
        $counts = [];
        foreach (['productos', 'producto_precios', 'existencias_producto', 'movimientos_inventario', 'compras', 'proveedores'] as $table) {
            $counts[$table] = $this->tableExists($table)
                ? (int) $this->pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn()
                : 0;
        }
        return $counts;
    }

    private function containsSensitive(string $value): bool
    {
        return preg_match('~password|secret|dsn|storage[\\\\/]private|[a-z]:[\\\\/]~i', $value) === 1;
    }

    private function read(string $path): string
    {
        $content = file_get_contents(BASE_PATH . '/' . $path);
        return is_string($content) ? $content : '';
    }
};
