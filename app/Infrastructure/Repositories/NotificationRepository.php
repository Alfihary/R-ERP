<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;
use PDO;
use PDOException;

final class NotificationRepository
{
    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    /** @param array<string,mixed> $notification @return array{status:string,id:?int} */
    public function createIdempotent(array $notification): array
    {
        $role = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT r.id
            FROM usuarios u
            INNER JOIN usuario_rol ur ON ur.usuario_id = u.id
            INNER JOIN roles r ON r.id = ur.rol_id AND r.activo = 1 AND r.deleted_at IS NULL
            WHERE u.id = :usuario_id
              AND u.activo = 1
              AND u.deleted_at IS NULL
              AND r.codigo = :rol_codigo
              AND r.codigo IN ('VENTAS', 'GERENCIA', 'ADMIN')
            LIMIT 1
            SQL
        );
        $role->execute([
            'usuario_id' => (int) $notification['usuario_id'],
            'rol_codigo' => (string) $notification['rol_codigo'],
        ]);
        $roleId = $role->fetchColumn();
        if ($roleId === false) {
            return ['status' => 'invalid_recipient', 'id' => null];
        }
        $notification['rol_id'] = (int) $roleId;
        unset($notification['rol_codigo']);

        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            INSERT INTO notificaciones (
                usuario_id, rol_id, tipo, titulo, mensaje, prioridad, entidad_tipo,
                entidad_id, accion_url, origen, idempotency_key,
                idempotency_fingerprint, created_at
            ) VALUES (
                :usuario_id, :rol_id, :tipo, :titulo, :mensaje, :prioridad, :entidad_tipo,
                :entidad_id, :accion_url, :origen, :idempotency_key,
                :idempotency_fingerprint, CURRENT_TIMESTAMP
            )
            SQL
        );
        try {
            $statement->execute($notification);
            return ['status' => 'created', 'id' => (int) $this->connection->pdo()->lastInsertId()];
        } catch (PDOException $exception) {
            if (($exception->errorInfo[0] ?? $exception->getCode()) !== '23000') {
                throw $exception;
            }
            $existing = $this->findByIdempotencyKey((string) $notification['idempotency_key']);
            if ($existing === null) {
                throw $exception;
            }
            $sameEvent = (int) $existing['usuario_id'] === (int) $notification['usuario_id']
                && (int) $existing['rol_id'] === (int) $notification['rol_id']
                && hash_equals(
                    (string) $existing['idempotency_fingerprint'],
                    (string) $notification['idempotency_fingerprint']
                );
            return [
                'status' => $sameEvent ? 'duplicate' : 'conflict',
                'id' => (int) $existing['id'],
            ];
        }
    }

    /** @return array<string,mixed>|null */
    private function findByIdempotencyKey(string $key): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT id, usuario_id, rol_id, idempotency_fingerprint FROM notificaciones WHERE idempotency_key = :key LIMIT 1'
        );
        $statement->execute(['key' => $key]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function listForUser(int $userId, string $filter, int $limit = 50): array
    {
        $where = 'n.usuario_id = :usuario_id';
        if ($filter === 'unread') {
            $where .= ' AND n.leida_at IS NULL';
        } elseif ($filter === 'today') {
            $where .= ' AND n.created_at >= CURRENT_DATE AND n.created_at < CURRENT_DATE + INTERVAL 1 DAY';
        }
        $sql = 'SELECT n.id, n.tipo, n.titulo, n.mensaje, n.prioridad, n.entidad_tipo, '
            . 'n.entidad_id, n.accion_url, n.leida_at, n.created_at, r.codigo AS rol_codigo '
            . 'FROM notificaciones n '
            . 'INNER JOIN usuario_rol ur ON ur.usuario_id = n.usuario_id AND ur.rol_id = n.rol_id '
            . 'INNER JOIN roles r ON r.id = ur.rol_id AND r.activo = 1 AND r.deleted_at IS NULL '
            . 'WHERE ' . $where . ' ORDER BY n.created_at DESC, n.id DESC LIMIT ' . max(1, min(50, $limit));
        $statement = $this->connection->pdo()->prepare($sql);
        $statement->execute(['usuario_id' => $userId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function unreadCountForUser(int $userId): int
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT COUNT(*)
            FROM notificaciones n
            INNER JOIN usuario_rol ur ON ur.usuario_id = n.usuario_id AND ur.rol_id = n.rol_id
            INNER JOIN roles r ON r.id = ur.rol_id AND r.activo = 1 AND r.deleted_at IS NULL
            WHERE n.usuario_id = :usuario_id AND n.leida_at IS NULL
            SQL
        );
        $statement->execute(['usuario_id' => $userId]);
        return (int) $statement->fetchColumn();
    }

    /** @return array{unread_count:int,nuevas_hoy:int,latest_id:int,notificaciones:list<array<string,mixed>>} */
    public function realtimeStateForUser(int $userId): array
    {
        $items = $this->listForUser($userId, 'all', 5);
        $today = $this->connection->pdo()->prepare(<<<'SQL'
SELECT COUNT(*)
FROM notificaciones n
INNER JOIN usuario_rol ur ON ur.usuario_id = n.usuario_id AND ur.rol_id = n.rol_id
INNER JOIN roles r ON r.id = ur.rol_id AND r.activo = 1 AND r.deleted_at IS NULL
WHERE n.usuario_id = :usuario_id
  AND n.created_at >= CURRENT_DATE
  AND n.created_at < CURRENT_DATE + INTERVAL 1 DAY
SQL);
        $today->execute(['usuario_id' => $userId]);
        return [
            'unread_count' => $this->unreadCountForUser($userId),
            'nuevas_hoy' => (int) $today->fetchColumn(),
            'latest_id' => (int) ($items[0]['id'] ?? 0),
            'notificaciones' => $items,
        ];
    }

    public function markReadForUser(int $notificationId, int $userId): ?string
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            UPDATE notificaciones n
            INNER JOIN usuario_rol ur ON ur.usuario_id = n.usuario_id AND ur.rol_id = n.rol_id
            INNER JOIN roles r ON r.id = ur.rol_id AND r.activo = 1 AND r.deleted_at IS NULL
            SET n.leida_at = COALESCE(n.leida_at, CURRENT_TIMESTAMP), n.updated_at = CURRENT_TIMESTAMP
            WHERE n.id = :id AND n.usuario_id = :usuario_id
            SQL
        );
        $statement->execute(['id' => $notificationId, 'usuario_id' => $userId]);
        $query = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT n.accion_url
            FROM notificaciones n
            INNER JOIN usuario_rol ur ON ur.usuario_id = n.usuario_id AND ur.rol_id = n.rol_id
            INNER JOIN roles r ON r.id = ur.rol_id AND r.activo = 1 AND r.deleted_at IS NULL
            WHERE n.id = :id AND n.usuario_id = :usuario_id
            LIMIT 1
            SQL
        );
        $query->execute(['id' => $notificationId, 'usuario_id' => $userId]);
        $value = $query->fetchColumn();
        return $value === false ? null : (is_string($value) ? $value : null);
    }

    public function markAllReadForUser(int $userId): int
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            UPDATE notificaciones n
            INNER JOIN usuario_rol ur ON ur.usuario_id = n.usuario_id AND ur.rol_id = n.rol_id
            INNER JOIN roles r ON r.id = ur.rol_id AND r.activo = 1 AND r.deleted_at IS NULL
            SET n.leida_at = CURRENT_TIMESTAMP, n.updated_at = CURRENT_TIMESTAMP
            WHERE n.usuario_id = :usuario_id AND n.leida_at IS NULL
            SQL
        );
        $statement->execute(['usuario_id' => $userId]);
        return $statement->rowCount();
    }
}
