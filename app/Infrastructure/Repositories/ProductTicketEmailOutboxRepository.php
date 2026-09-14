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
     * @return array<string, mixed>
     */
    public function markSent(int $id): array
    {
        $statement = $this->pdo()->prepare(
            "UPDATE tickets_productos_correos
             SET status = 'ENVIADO',
                 intentos = intentos + 1,
                 ultimo_intento_at = CURRENT_TIMESTAMP,
                 enviado_at = CURRENT_TIMESTAMP,
                 error_mensaje_seguro = NULL
             WHERE id = :id"
        );
        $statement->execute(['id' => $id]);

        return $this->findById($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function markError(int $id, string $safeMessage): array
    {
        $statement = $this->pdo()->prepare(
            "UPDATE tickets_productos_correos
             SET status = 'ERROR',
                 intentos = intentos + 1,
                 ultimo_intento_at = CURRENT_TIMESTAMP,
                 error_mensaje_seguro = :error_mensaje_seguro
             WHERE id = :id"
        );
        $statement->execute([
            'id' => $id,
            'error_mensaje_seguro' => $safeMessage,
        ]);

        return $this->findById($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function findById(int $id): array
    {
        $statement = $this->pdo()->prepare(
            'SELECT *
             FROM tickets_productos_correos
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            throw new \RuntimeException('Ticket product email outbox row was not found.');
        }

        return $row;
    }

    private function pdo(): PDO
    {
        return $this->connection instanceof ConnectionProvider
            ? $this->connection->pdo()
            : $this->connection;
    }
}
