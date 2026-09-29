<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;
use PDO;

final class MailOutboxActionRepository
{
    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    /**
     * @param list<int> $warehouseIds
     * @return array<string, mixed>|null
     */
    public function findScopedForUpdate(int $id, array $warehouseIds): ?array
    {
        [$scopeSql, $params] = $this->scopeWhere($warehouseIds);
        if ($scopeSql === '1 = 0' || $id < 1) {
            return null;
        }

        $params['id'] = $id;
        $statement = $this->connection->pdo()->prepare(
            "SELECT
                m.id,
                m.ticket_id,
                m.status,
                m.intentos,
                m.max_intentos,
                m.ultimo_intento_at,
                m.enviado_at,
                m.cancelado_at,
                m.error_mensaje_seguro,
                m.dedupe_key,
                tp.almacen_id
             FROM tickets_productos_correos m
             INNER JOIN tickets_productos tp ON tp.id = m.ticket_id
             WHERE m.id = :id
               AND {$scopeSql}
             LIMIT 1
             FOR UPDATE"
        );
        $this->bind($statement, $params);
        $statement->execute();
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param list<int> $warehouseIds */
    public function retryError(int $id, array $warehouseIds): int
    {
        [$scopeSql, $params] = $this->scopeWhere($warehouseIds);
        if ($scopeSql === '1 = 0' || $id < 1) {
            return 0;
        }

        $params['id'] = $id;
        $statement = $this->connection->pdo()->prepare(
            "UPDATE tickets_productos_correos m
             INNER JOIN tickets_productos tp ON tp.id = m.ticket_id
             SET m.status = 'PENDIENTE'
             WHERE m.id = :id
               AND m.status = 'ERROR'
               AND m.intentos < m.max_intentos
               AND m.enviado_at IS NULL
               AND m.cancelado_at IS NULL
               AND {$scopeSql}"
        );
        $this->bind($statement, $params);
        $statement->execute();

        return $statement->rowCount();
    }

    /** @param list<int> $warehouseIds */
    public function cancelPendingOrError(int $id, array $warehouseIds): int
    {
        [$scopeSql, $params] = $this->scopeWhere($warehouseIds);
        if ($scopeSql === '1 = 0' || $id < 1) {
            return 0;
        }

        $params['id'] = $id;
        $statement = $this->connection->pdo()->prepare(
            "UPDATE tickets_productos_correos m
             INNER JOIN tickets_productos tp ON tp.id = m.ticket_id
             SET m.status = 'CANCELADO',
                 m.cancelado_at = CURRENT_TIMESTAMP
             WHERE m.id = :id
               AND m.status IN ('PENDIENTE', 'ERROR')
               AND m.enviado_at IS NULL
               AND m.cancelado_at IS NULL
               AND {$scopeSql}"
        );
        $this->bind($statement, $params);
        $statement->execute();

        return $statement->rowCount();
    }

    /**
     * @param list<int> $warehouseIds
     * @return array{0:string,1:array<string,int>}
     */
    private function scopeWhere(array $warehouseIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $warehouseIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return ['1 = 0', []];
        }

        $placeholders = [];
        $params = [];
        foreach ($ids as $index => $warehouseId) {
            $name = 'scope_warehouse_' . $index;
            $placeholders[] = ':' . $name;
            $params[$name] = $warehouseId;
        }

        return ['tp.almacen_id IN (' . implode(', ', $placeholders) . ')', $params];
    }

    /** @param array<string, int> $params */
    private function bind(\PDOStatement $statement, array $params): void
    {
        foreach ($params as $name => $value) {
            $statement->bindValue(':' . $name, $value, PDO::PARAM_INT);
        }
    }
}
