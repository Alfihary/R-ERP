<?php

declare(strict_types=1);

namespace App\Domain\Mail;

use App\Domain\Audit\AuditService;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Repositories\AuditRepository;
use App\Infrastructure\Repositories\MailOutboxActionRepository;
use Throwable;

final class MailOutboxActionService
{
    private const SAVEPOINT = 'mail_outbox_action';

    public function __construct(
        private readonly ConnectionProvider $connection,
        private readonly MailOutboxActionRepository $outbox,
        private readonly AuditService $audit,
        private readonly AuditRepository $auditRepository
    ) {
    }

    /**
     * @param list<int> $warehouseIds
     * @param array{ip?:mixed,user_agent?:mixed} $requestContext
     * @return array{result:string,reason:string,status:string|null}
     */
    public function retry(
        int $outboxId,
        int $actorUserId,
        array $warehouseIds,
        array $requestContext = []
    ): array {
        return $this->transactional(function () use (
            $outboxId,
            $actorUserId,
            $warehouseIds,
            $requestContext
        ): array {
            $row = $this->outbox->findScopedForUpdate($outboxId, $warehouseIds);
            if ($row === null) {
                return $this->result('not_found', 'not_found', null);
            }

            $status = (string) $row['status'];
            $attempts = (int) $row['intentos'];
            $maxAttempts = (int) $row['max_intentos'];
            if ($status !== 'ERROR') {
                return $this->result('invalid_transition', 'invalid_transition', $status);
            }
            if ($attempts >= $maxAttempts) {
                return $this->result('invalid_transition', 'max_attempts', $status);
            }

            if ($this->outbox->retryError($outboxId, $warehouseIds) !== 1) {
                return $this->result('already_changed', 'state_changed', $status);
            }

            $this->recordRequired(
                'MAIL_OUTBOX_RETRY_REQUESTED',
                $actorUserId,
                $row,
                'ERROR',
                'PENDIENTE',
                null,
                $requestContext
            );

            return $this->result('success', 'retry_requested', 'PENDIENTE');
        });
    }

    /**
     * @param list<int> $warehouseIds
     * @param array{ip?:mixed,user_agent?:mixed} $requestContext
     * @return array{result:string,reason:string,status:string|null}
     */
    public function cancel(
        int $outboxId,
        int $actorUserId,
        array $warehouseIds,
        string $reason,
        array $requestContext = []
    ): array {
        return $this->transactional(function () use (
            $outboxId,
            $actorUserId,
            $warehouseIds,
            $reason,
            $requestContext
        ): array {
            $row = $this->outbox->findScopedForUpdate($outboxId, $warehouseIds);
            if ($row === null) {
                return $this->result('not_found', 'not_found', null);
            }

            $reason = trim($reason);
            if ($reason === '' || mb_strlen($reason) > 300) {
                return $this->result('invalid_transition', 'invalid_reason', (string) $row['status']);
            }

            $status = (string) $row['status'];
            if ($status === 'CANCELADO') {
                return $this->result('already_changed', 'already_cancelled', $status);
            }
            if (!in_array($status, ['PENDIENTE', 'ERROR'], true)) {
                return $this->result('invalid_transition', 'invalid_transition', $status);
            }

            if ($this->outbox->cancelPendingOrError($outboxId, $warehouseIds) !== 1) {
                return $this->result('already_changed', 'state_changed', $status);
            }

            $this->recordRequired(
                'MAIL_OUTBOX_CANCELLED',
                $actorUserId,
                $row,
                $status,
                'CANCELADO',
                $reason,
                $requestContext
            );

            return $this->result('success', 'cancelled', 'CANCELADO');
        });
    }

    /** @return array{result:string,reason:string,status:string|null} */
    private function result(string $result, string $reason, ?string $status): array
    {
        return ['result' => $result, 'reason' => $reason, 'status' => $status];
    }

    /**
     * @param array<string, mixed> $row
     * @param array{ip?:mixed,user_agent?:mixed} $requestContext
     */
    private function recordRequired(
        string $action,
        int $actorUserId,
        array $row,
        string $previousStatus,
        string $newStatus,
        ?string $reason,
        array $requestContext
    ): void {
        $metadata = [
            'outbox_id' => (int) $row['id'],
            'ticket_id' => (int) $row['ticket_id'],
            'estado_anterior' => $previousStatus,
            'estado_nuevo' => $newStatus,
            'intentos' => (int) $row['intentos'],
            'max_intentos' => (int) $row['max_intentos'],
        ];
        if ($reason !== null) {
            $metadata['motivo'] = $reason;
        }

        $cleanMetadata = $this->audit->sanitizeMetadata($metadata);
        $this->auditRepository->insertRequired(
            $actorUserId,
            $action,
            'tickets_productos_correos',
            (string) $row['id'],
            'success',
            $this->safeIp($requestContext['ip'] ?? null),
            $this->safeUserAgent($requestContext['user_agent'] ?? null),
            $cleanMetadata
        );
    }

    private function safeIp(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim(explode(',', $value)[0] ?? '');

        return $value !== ''
            && strlen($value) <= 45
            && preg_match('/^[A-Fa-f0-9:.]{3,45}$/', $value) === 1
                ? $value
                : null;
    }

    private function safeUserAgent(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return substr(str_replace(["\r", "\n"], ' ', $value), 0, 500);
    }

    /** @template T @param callable():T $operation @return T */
    private function transactional(callable $operation): mixed
    {
        $pdo = $this->connection->pdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        }

        try {
            $result = $operation();
            if ($ownsTransaction) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }

            return $result;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            } elseif ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
                $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            }
            throw $exception;
        }
    }
}
