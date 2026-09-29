<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;
use PDO;

final class ProductTicketEmailOutboxRepository
{
    public function __construct(private readonly ConnectionProvider|PDO $connection)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findTicketContext(int $ticketId): ?array
    {
        $statement = $this->pdo()->prepare(
            'SELECT
                tp.id,
                tp.folio,
                tp.estado,
                e.nombre AS empresa_nombre,
                a.nombre AS almacen_nombre,
                a.codigo AS almacen_codigo,
                u.id AS solicitante_id,
                u.username AS solicitante_username,
                u.email AS solicitante_email
             FROM tickets_productos tp
             INNER JOIN empresas e ON e.id = tp.empresa_id
             INNER JOIN almacenes a ON a.id = tp.almacen_id
             INNER JOIN usuarios u ON u.id = tp.solicitante_usuario_id
             WHERE tp.id = :ticket_id
               AND tp.deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute(['ticket_id' => $ticketId]);
        $ticket = $statement->fetch();

        return is_array($ticket) ? $ticket : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findLineContext(int $ticketId, int $partidaId): ?array
    {
        $statement = $this->pdo()->prepare(
            'SELECT
                id,
                ticket_producto_id,
                numero_partida,
                estado,
                descripcion,
                motivo_rechazo,
                comentario_resolucion
             FROM tickets_productos_partidas
             WHERE id = :partida_id
               AND ticket_producto_id = :ticket_id
               AND deleted_at IS NULL
             LIMIT 1'
        );
        $statement->execute([
            'ticket_id' => $ticketId,
            'partida_id' => $partidaId,
        ]);
        $line = $statement->fetch();

        return is_array($line) ? $line : null;
    }

    public function findByDedupeKey(string $dedupeKey): ?array
    {
        $statement = $this->pdo()->prepare(
            'SELECT *
             FROM tickets_productos_correos
             WHERE dedupe_key = :dedupe_key
             LIMIT 1'
        );
        $statement->execute(['dedupe_key' => $dedupeKey]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function insertPending(array $data): array
    {
        $statement = $this->pdo()->prepare(
            'INSERT INTO tickets_productos_correos (
                ticket_id,
                partida_id,
                evento,
                plantilla,
                destinatario_email,
                cc_json,
                subject,
                html,
                text,
                status,
                intentos,
                max_intentos,
                creado_por_usuario_id,
                dedupe_key,
                created_at
             ) VALUES (
                :ticket_id,
                :partida_id,
                :evento,
                :plantilla,
                :destinatario_email,
                :cc_json,
                :subject,
                :html,
                :text,
                \'PENDIENTE\',
                0,
                :max_intentos,
                :creado_por_usuario_id,
                :dedupe_key,
                CURRENT_TIMESTAMP
             )'
        );
        $statement->execute([
            'ticket_id' => $data['ticket_id'],
            'partida_id' => $data['partida_id'],
            'evento' => $data['evento'],
            'plantilla' => $data['plantilla'],
            'destinatario_email' => $data['destinatario_email'],
            'cc_json' => $data['cc_json'],
            'subject' => $data['subject'],
            'html' => $data['html'],
            'text' => $data['text'],
            'max_intentos' => $data['max_intentos'],
            'creado_por_usuario_id' => $data['creado_por_usuario_id'],
            'dedupe_key' => $data['dedupe_key'],
        ]);

        return $this->findById((int) $this->pdo()->lastInsertId());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function eligiblePreview(int $limit): array
    {
        $statement = $this->pdo()->prepare(
            "SELECT id, evento, plantilla, status, intentos, max_intentos, destinatario_email
             FROM tickets_productos_correos
             WHERE (
                    status = 'PENDIENTE'
                    OR (status = 'ERROR' AND intentos < max_intentos)
             )
             ORDER BY created_at ASC, id ASC
             LIMIT :limit"
        );
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Atomically claims one eligible row. The transaction ends before this
     * method returns so callers never keep a database lock during SMTP.
     *
     * @return array<string, mixed>|null
     */
    public function claimNextEligible(array $excludeIds = []): ?array
    {
        $pdo = $this->pdo();

        if ($pdo->inTransaction()) {
            throw new \RuntimeException('Mail outbox claim requires its own short transaction.');
        }

        $pdo->beginTransaction();

        try {
            $excludeIds = array_values(array_filter(
                array_map('intval', $excludeIds),
                static fn (int $id): bool => $id > 0
            ));
            $excludeSql = '';
            $parameters = [];
            if ($excludeIds !== []) {
                $placeholders = [];
                foreach ($excludeIds as $index => $excludedId) {
                    $name = 'excluded_' . $index;
                    $placeholders[] = ':' . $name;
                    $parameters[$name] = $excludedId;
                }
                $excludeSql = ' AND id NOT IN (' . implode(', ', $placeholders) . ')';
            }

            $statement = $pdo->prepare(
                "SELECT id
                 FROM tickets_productos_correos
                 WHERE (
                        status = 'PENDIENTE'
                        OR (status = 'ERROR' AND intentos < max_intentos)
                 )
                 {$excludeSql}
                 ORDER BY created_at ASC, id ASC
                 LIMIT 1
                 FOR UPDATE"
            );
            $statement->execute($parameters);
            $id = $statement->fetchColumn();

            if ($id === false) {
                $pdo->commit();
                return null;
            }

            $update = $pdo->prepare(
                "UPDATE tickets_productos_correos
                 SET status = 'ENVIANDO',
                     intentos = intentos + 1,
                     ultimo_intento_at = CURRENT_TIMESTAMP,
                     enviado_at = NULL,
                     error_mensaje_seguro = NULL
                 WHERE id = :id
                   AND (
                        status = 'PENDIENTE'
                        OR (status = 'ERROR' AND intentos < max_intentos)
                   )"
            );
            $update->execute(['id' => (int) $id]);

            if ($update->rowCount() !== 1) {
                $pdo->rollBack();
                return null;
            }

            $row = $this->findById((int) $id);
            $pdo->commit();

            return $row;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @return array{result:string,row:array<string,mixed>|null}
     */
    public function markClaimSent(
        int $id,
        int $expectedAttempts,
        string $expectedLastAttemptAt
    ): array {
        return $this->markSent($id, $expectedAttempts, $expectedLastAttemptAt);
    }

    /**
     * @return array{result:string,row:array<string,mixed>|null}
     */
    public function markClaimError(
        int $id,
        string $safeMessage,
        int $expectedAttempts,
        string $expectedLastAttemptAt
    ): array {
        return $this->markError($id, $safeMessage, $expectedAttempts, $expectedLastAttemptAt);
    }

    public function recoverStale(int $minutes, string $safeMessage): int
    {
        if ($minutes < 1 || $minutes > 1440) {
            throw new \RuntimeException('Invalid stale mail threshold.');
        }

        $statement = $this->pdo()->prepare(
            "UPDATE tickets_productos_correos
             SET status = 'ERROR',
                 enviado_at = NULL,
                 error_mensaje_seguro = :error_mensaje_seguro
             WHERE status = 'ENVIANDO'
               AND ultimo_intento_at IS NOT NULL
               AND ultimo_intento_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL {$minutes} MINUTE)"
        );
        $statement->bindValue('error_mensaje_seguro', $safeMessage);
        $statement->execute();

        return $statement->rowCount();
    }

    /** @return array<string, mixed> */
    public function findOutboxById(int $id): array
    {
        return $this->findById($id);
    }

    /**
     * Finalizes only the exact claim identified by attempts and timestamp.
     * Calls without claim identity are retained as safe, non-mutating legacy calls.
     *
     * @return array{result:string,row:array<string,mixed>|null}
     */
    public function markSent(
        int $id,
        ?int $expectedAttempts = null,
        ?string $expectedLastAttemptAt = null
    ): array {
        if (!$this->validClaimIdentity($id, $expectedAttempts, $expectedLastAttemptAt)) {
            return $this->currentTransitionResult($id);
        }

        $statement = $this->pdo()->prepare(
            "UPDATE tickets_productos_correos
             SET status = 'ENVIADO',
                 enviado_at = CURRENT_TIMESTAMP,
                 error_mensaje_seguro = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND status = 'ENVIANDO'
               AND intentos = :expected_attempts
               AND ultimo_intento_at = :expected_last_attempt_at"
        );
        $statement->execute([
            'id' => $id,
            'expected_attempts' => $expectedAttempts,
            'expected_last_attempt_at' => $expectedLastAttemptAt,
        ]);

        return $this->transitionResult($id, $statement->rowCount());
    }

    /**
     * Fails only the exact claim identified by attempts and timestamp.
     * Calls without claim identity are retained as safe, non-mutating legacy calls.
     *
     * @return array{result:string,row:array<string,mixed>|null}
     */
    public function markError(
        int $id,
        string $safeMessage,
        ?int $expectedAttempts = null,
        ?string $expectedLastAttemptAt = null
    ): array {
        $safeMessage = $this->safeErrorMessage($safeMessage);
        if (!$this->validClaimIdentity($id, $expectedAttempts, $expectedLastAttemptAt)) {
            return $this->currentTransitionResult($id);
        }

        $statement = $this->pdo()->prepare(
            "UPDATE tickets_productos_correos
             SET status = 'ERROR',
                 enviado_at = NULL,
                 error_mensaje_seguro = :error_mensaje_seguro,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND status = 'ENVIANDO'
               AND intentos = :expected_attempts
               AND ultimo_intento_at = :expected_last_attempt_at"
        );
        $statement->execute([
            'id' => $id,
            'error_mensaje_seguro' => $safeMessage,
            'expected_attempts' => $expectedAttempts,
            'expected_last_attempt_at' => $expectedLastAttemptAt,
        ]);

        return $this->transitionResult($id, $statement->rowCount());
    }

    /** @return array{result:string,row:array<string,mixed>|null} */
    private function transitionResult(int $id, int $affectedRows): array
    {
        $row = $this->findByIdOrNull($id);

        return [
            'result' => $affectedRows === 1 ? 'success' : ($row === null ? 'not_found' : 'state_changed'),
            'row' => $row,
        ];
    }

    /** @return array{result:string,row:array<string,mixed>|null} */
    private function currentTransitionResult(int $id): array
    {
        $row = $this->findByIdOrNull($id);

        return ['result' => $row === null ? 'not_found' : 'state_changed', 'row' => $row];
    }

    private function validClaimIdentity(
        int $id,
        ?int $expectedAttempts,
        ?string $expectedLastAttemptAt
    ): bool {
        return $id > 0
            && $expectedAttempts !== null
            && $expectedAttempts > 0
            && is_string($expectedLastAttemptAt)
            && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $expectedLastAttemptAt) === 1;
    }

    private function safeErrorMessage(string $message): string
    {
        $message = trim(str_replace(["\r", "\n"], ' ', $message));
        if (
            $message === ''
            || mb_strlen($message, 'UTF-8') > 500
            || preg_match('~password|secret|token|dsn|storage[\\\\/]private|[a-z]:[\\\\/]~i', $message) === 1
        ) {
            throw new \RuntimeException('Safe mail error message is required.');
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    private function findById(int $id): array
    {
        $row = $this->findByIdOrNull($id);
        if (!is_array($row)) {
            throw new \RuntimeException('Ticket product email outbox row was not found.');
        }

        return $row;
    }

    /** @return array<string, mixed>|null */
    private function findByIdOrNull(int $id): ?array
    {
        $statement = $this->pdo()->prepare(
            'SELECT *
             FROM tickets_productos_correos
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    private function pdo(): PDO
    {
        return $this->connection instanceof ConnectionProvider
            ? $this->connection->pdo()
            : $this->connection;
    }
}
