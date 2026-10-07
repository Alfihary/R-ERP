<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;
use PDO;
use RuntimeException;
use Throwable;

final class SmtpQaFixtureIntentRepository
{
    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    public function currentDatabaseName(): string
    {
        return (string) $this->pdo()->query('SELECT DATABASE()')->fetchColumn();
    }

    /** @return array{user_id:int,company_id:int,warehouse_id:int} */
    public function activeScope(): array
    {
        $row = $this->pdo()->query(
            "SELECT u.id AS user_id, a.empresa_id AS company_id, a.id AS warehouse_id
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

        if (!is_array($row)) {
            throw new RuntimeException('SMTP QA fixture requires one active scoped user.');
        }

        return [
            'user_id' => (int) $row['user_id'],
            'company_id' => (int) $row['company_id'],
            'warehouse_id' => (int) $row['warehouse_id'],
        ];
    }

    public function nextFolio(): string
    {
        $rows = $this->pdo()->query(
            "SELECT folio
               FROM tickets_productos
              WHERE folio LIKE 'QASMTP-%'
              ORDER BY folio"
        )->fetchAll(PDO::FETCH_COLUMN);
        $used = array_fill_keys(array_map('strval', $rows), true);

        for ($number = 2; $number <= 999999; $number++) {
            $folio = sprintf('QASMTP-%06d', $number);
            if (!isset($used[$folio])) {
                return $folio;
            }
        }

        throw new RuntimeException('SMTP QA folio namespace is exhausted.');
    }

    /** @return array<string, mixed>|null */
    public function phaseArtifact(string $phase): ?array
    {
        $statement = $this->pdo()->prepare(
            "SELECT tp.id AS ticket_id, tp.folio, tp.estado, tp.observaciones_generales,
                    tp.total_partidas, tp.cancelado_at, tc.id AS outbox_id,
                    tc.status, tc.intentos, tc.ultimo_intento_at, tc.enviado_at,
                    tc.evento, tc.plantilla, tc.destinatario_email, tc.cc_json,
                    tc.subject, tc.html, tc.text, tc.dedupe_key
               FROM tickets_productos_eventos ev
               INNER JOIN tickets_productos tp ON tp.id = ev.ticket_producto_id
               LEFT JOIN tickets_productos_correos tc
                      ON tc.ticket_id = tp.id AND tc.evento = 'TICKET_CREADO'
              WHERE JSON_UNQUOTE(JSON_EXTRACT(ev.metadata_json, '$.qa_phase')) = :phase
              ORDER BY ev.id DESC, tc.id DESC
              LIMIT 1"
        );
        $statement->execute(['phase' => $phase]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array{lines:int,attachments:int,comments:int,events:int,outbox:int} */
    public function relationCounts(int $ticketId): array
    {
        $statement = $this->pdo()->prepare(
            'SELECT
                (SELECT COUNT(*) FROM tickets_productos_partidas WHERE ticket_producto_id = :ticket_lines) AS line_count,
                (SELECT COUNT(*) FROM tickets_productos_adjuntos WHERE ticket_producto_id = :ticket_attachments) AS attachment_count,
                (SELECT COUNT(*) FROM tickets_productos_comentarios WHERE ticket_producto_id = :ticket_comments) AS comment_count,
                (SELECT COUNT(*) FROM tickets_productos_eventos WHERE ticket_producto_id = :ticket_events) AS event_count,
                (SELECT COUNT(*) FROM tickets_productos_correos WHERE ticket_id = :ticket_outbox) AS outbox_count'
        );
        $statement->execute([
            'ticket_lines' => $ticketId,
            'ticket_attachments' => $ticketId,
            'ticket_comments' => $ticketId,
            'ticket_events' => $ticketId,
            'ticket_outbox' => $ticketId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return [
            'lines' => (int) ($row['line_count'] ?? -1),
            'attachments' => (int) ($row['attachment_count'] ?? -1),
            'comments' => (int) ($row['comment_count'] ?? -1),
            'events' => (int) ($row['event_count'] ?? -1),
            'outbox' => (int) ($row['outbox_count'] ?? -1),
        ];
    }

    public function eligibleCount(): int
    {
        return (int) $this->pdo()->query(
            "SELECT COUNT(*)
               FROM tickets_productos_correos
              WHERE status = 'PENDIENTE'
                 OR (status = 'ERROR' AND intentos < max_intentos)"
        )->fetchColumn();
    }

    public function phaseArtifactCount(string $phase): int
    {
        $statement = $this->pdo()->prepare(
            "SELECT COUNT(DISTINCT ticket_producto_id)
               FROM tickets_productos_eventos
              WHERE JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.qa_phase')) = :phase"
        );
        $statement->execute(['phase' => $phase]);

        return (int) $statement->fetchColumn();
    }

    /** @return array{tickets_count:int,outbox_count:int,eligible_count:int,qa_fixture_count:int} */
    public function counts(string $phase): array
    {
        return [
            'tickets_count' => (int) $this->pdo()->query('SELECT COUNT(*) FROM tickets_productos')->fetchColumn(),
            'outbox_count' => (int) $this->pdo()->query('SELECT COUNT(*) FROM tickets_productos_correos')->fetchColumn(),
            'eligible_count' => $this->eligibleCount(),
            'qa_fixture_count' => $this->phaseArtifactCount($phase),
        ];
    }

    /** @return array<string, mixed> */
    public function protectedEvidence(): array
    {
        $outbox = $this->pdo()->query(
            'SELECT id, status, intentos, enviado_at
               FROM tickets_productos_correos
              WHERE id IN (1, 36, 698, 699, 700)
              ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $ticket = $this->pdo()->query(
            'SELECT id, folio, estado, total_partidas
               FROM tickets_productos
              WHERE id = 34'
        )->fetch(PDO::FETCH_ASSOC);

        return ['outbox' => $outbox, 'ticket_34' => is_array($ticket) ? $ticket : null];
    }

    /** @template T @param callable():T $operation @return T */
    public function transactional(callable $operation): mixed
    {
        $pdo = $this->pdo();
        $nested = $pdo->inTransaction();
        $savepoint = 'smtp_qa_fixture_intention';

        if ($nested) {
            $pdo->exec('SAVEPOINT ' . $savepoint);
        } else {
            $pdo->beginTransaction();
        }

        try {
            $result = $operation();
            if ($nested) {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            } else {
                $pdo->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if ($nested && $pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            } elseif ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @template T @param callable():T $operation @return T */
    public function withCreationLock(callable $operation): mixed
    {
        $statement = $this->pdo()->prepare("SELECT GET_LOCK('r_erp_smtp_qa_fixture_intention', 5)");
        $statement->execute();
        if ((int) $statement->fetchColumn() !== 1) {
            throw new RuntimeException('SMTP QA fixture creation lock is unavailable.');
        }

        try {
            return $operation();
        } finally {
            $this->pdo()->query("SELECT RELEASE_LOCK('r_erp_smtp_qa_fixture_intention')");
        }
    }

    private function pdo(): PDO
    {
        return $this->connection->pdo();
    }
}
