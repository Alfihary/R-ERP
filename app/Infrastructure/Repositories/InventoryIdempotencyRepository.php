<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;

final class InventoryIdempotencyRepository
{
    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    /** @return array{id: int, estado: string, resultado_json: string|null} */
    public function reserve(string $scopeKey, string $idempotencyKey, string $payloadHash): array
    {
        $pdo = $this->connection->pdo();
        $insert = $pdo->prepare(
            'INSERT INTO inventario_operaciones_idempotencia
                (scope_key, idempotency_key, payload_hash, estado)
             VALUES (:scope_key, :idempotency_key, :payload_hash, \'PENDIENTE\')
             ON DUPLICATE KEY UPDATE id = id'
        );
        $insert->execute([
            'scope_key' => $scopeKey,
            'idempotency_key' => $idempotencyKey,
            'payload_hash' => $payloadHash,
        ]);

        $select = $pdo->prepare(
            'SELECT id, payload_hash, estado, resultado_json
             FROM inventario_operaciones_idempotencia
             WHERE scope_key = :scope_key AND idempotency_key = :idempotency_key
             FOR UPDATE'
        );
        $select->execute([
            'scope_key' => $scopeKey,
            'idempotency_key' => $idempotencyKey,
        ]);
        $row = $select->fetch();
        if (!is_array($row)) {
            throw new \RuntimeException('No se pudo reservar la operación idempotente.');
        }
        if (!hash_equals((string) $row['payload_hash'], $payloadHash)) {
            throw new \App\Domain\Inventory\InventoryIdempotencyConflictException();
        }

        return [
            'id' => (int) $row['id'],
            'estado' => (string) $row['estado'],
            'resultado_json' => $row['resultado_json'] === null
                ? null
                : (string) $row['resultado_json'],
        ];
    }

    /** @param array<string, mixed> $result */
    public function complete(int $id, array $result): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE inventario_operaciones_idempotencia
             SET estado = \'COMPLETADA\', resultado_json = :resultado_json,
                 completado_en = CURRENT_TIMESTAMP
             WHERE id = :id AND estado = \'PENDIENTE\''
        );
        $statement->execute([
            'id' => $id,
            'resultado_json' => json_encode(
                $result,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ),
        ]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('No se pudo completar la operación idempotente.');
        }
    }
}
