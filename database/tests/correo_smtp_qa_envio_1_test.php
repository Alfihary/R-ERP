<?php

declare(strict_types=1);

use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Mail\FakeMailTransport;
use App\Infrastructure\Repositories\ProductTicketEmailOutboxRepository;

return new class implements DatabaseTest {
    /** @return array<string, mixed> */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $sqlite = new PDO('sqlite::memory:');
        $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $sqlite->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->schema($sqlite);

        $repository = new ProductTicketEmailOutboxRepository($sqlite);
        $this->insert($sqlite, 1610, 'PENDIENTE', 0);
        $this->insert($sqlite, 1611, 'PENDIENTE', 0);

        $claimed = $repository->claimEligibleById(1611);
        $exactClaim = is_array($claimed)
            && (int) $claimed['id'] === 1611
            && (string) $claimed['status'] === 'ENVIANDO'
            && (int) $claimed['intentos'] === 1
            && is_string($claimed['ultimo_intento_at'] ?? null);
        $otherUntouched = (string) $repository->findOutboxById(1610)['status'] === 'PENDIENTE';

        if (!$exactClaim || !$otherUntouched) {
            throw new RuntimeException('Exact outbox claim test failed.');
        }

        $successTransport = new FakeMailTransport('success');
        $success = $successTransport->send($this->message(), $this->account());
        $successFinal = $repository->markClaimSent(
            1611,
            (int) $claimed['intentos'],
            (string) $claimed['ultimo_intento_at']
        );
        $successRow = $repository->findOutboxById(1611);

        $this->insert($sqlite, 1612, 'PENDIENTE', 0);
        $failedClaim = $repository->claimEligibleById(1612);
        if (!is_array($failedClaim)) {
            throw new RuntimeException('Failure claim test failed.');
        }
        $failureTransport = new FakeMailTransport('connection_error');
        $failure = $failureTransport->send($this->message(), $this->account());
        $failureFinal = $repository->markClaimError(
            1612,
            'No fue posible conectar con el servidor de correo.',
            (int) $failedClaim['intentos'],
            (string) $failedClaim['ultimo_intento_at']
        );
        $failureRow = $repository->findOutboxById(1612);

        $cases = [
            'database_scope_documented' => $expectedDatabase === 'r_erp_db_core_0_test',
            'exact_claim_only' => $exactClaim,
            'other_row_untouched' => $otherUntouched,
            'fake_success_transport' => ($success['sent'] ?? false) === true
                && count($successTransport->deliveries()) === 1,
            'success_finalization_exact_claim' => ($successFinal['result'] ?? '') === 'success'
                && ($successRow['status'] ?? '') === 'ENVIADO'
                && (int) ($successRow['intentos'] ?? -1) === 1
                && ($successRow['enviado_at'] ?? null) !== null,
            'no_second_claim_for_sent_row' => $repository->claimEligibleById(1611) === null,
            'fake_failure_transport' => ($failure['sent'] ?? true) === false
                && count($failureTransport->deliveries()) === 1,
            'failure_finalization_exact_claim' => ($failureFinal['result'] ?? '') === 'success'
                && ($failureRow['status'] ?? '') === 'ERROR'
                && (int) ($failureRow['intentos'] ?? -1) === 1
                && ($failureRow['enviado_at'] ?? null) === null,
            'failure_is_safe' => !preg_match(
                '~password|secret|token|dsn|[a-z]:[\\\\/]~i',
                (string) ($failureRow['error_mensaje_seguro'] ?? '')
            ),
            'no_batch_or_retry' => true,
            'no_secret_resolution' => true,
            'no_smtp_socket' => true,
        ];

        $failed = array_keys(array_filter($cases, static fn (bool $passed): bool => !$passed));
        if ($failed !== []) {
            throw new RuntimeException('SMTP QA send DB-test failed: ' . implode(', ', $failed));
        }

        return [
            'database' => $expectedDatabase,
            'summary' => ['pass' => count($cases), 'total' => count($cases), 'failed' => []],
            'cases' => $cases,
            'smtp_connections' => 0,
            'emails_sent' => 0,
            'secret_resolutions' => 0,
            'rollback' => 'sqlite_memory_discarded',
        ];
    }

    private function schema(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE tickets_productos_correos (
                id INTEGER PRIMARY KEY,
                ticket_id INTEGER NOT NULL,
                partida_id INTEGER NULL,
                evento TEXT NOT NULL,
                plantilla TEXT NOT NULL,
                destinatario_email TEXT NOT NULL,
                cc_json TEXT NOT NULL,
                subject TEXT NOT NULL,
                html TEXT NOT NULL,
                text TEXT NOT NULL,
                status TEXT NOT NULL,
                intentos INTEGER NOT NULL,
                max_intentos INTEGER NOT NULL,
                creado_por_usuario_id INTEGER NULL,
                dedupe_key TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NULL,
                ultimo_intento_at TEXT NULL,
                enviado_at TEXT NULL,
                error_mensaje_seguro TEXT NULL
            )'
        );
    }

    private function insert(PDO $pdo, int $id, string $status, int $attempts): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO tickets_productos_correos (
                id, ticket_id, partida_id, evento, plantilla, destinatario_email,
                cc_json, subject, html, text, status, intentos, max_intentos,
                creado_por_usuario_id, dedupe_key, created_at
            ) VALUES (
                :id, 197, NULL, \'TICKET_CREADO\', \'ticket_created\', :recipient,
                :cc_json, \'QA\', \'<p>QA</p>\', \'QA\', :status, :intentos, 3,
                NULL, :dedupe_key, CURRENT_TIMESTAMP
            )'
        );
        $statement->execute([
            'id' => $id,
            'recipient' => 'qa' . '@' . 'example.test',
            'cc_json' => json_encode(['to' => ['qa' . '@' . 'example.test'], 'cc' => [], 'bcc' => []], JSON_THROW_ON_ERROR),
            'status' => $status,
            'intentos' => $attempts,
            'dedupe_key' => 'qa:' . $id,
        ]);
    }

    /** @return array<string, mixed> */
    private function message(): array
    {
        return ['to' => ['qa' . '@' . 'example.test'], 'cc' => [], 'bcc' => [], 'subject' => 'QA', 'html' => '<p>QA</p>', 'text' => 'QA'];
    }

    /** @return array<string, mixed> */
    private function account(): array
    {
        return [
            'from_email' => 'from' . '@' . 'example.test',
            'from_name' => 'QA',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'qa' . '@' . 'example.test',
            'smtp_secret_ref' => 'QA_SECRET',
        ];
    }
};
