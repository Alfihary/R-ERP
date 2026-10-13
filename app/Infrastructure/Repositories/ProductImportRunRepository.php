<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;
use PDO;

/**
 * Persistence guard for a future product-import batch.
 * The owning service supplies the transaction; this repository never begins
 * or commits one itself.
 */
final class ProductImportRunRepository
{
    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    /** @param array{confirmation_token:string,preview_id:string,usuario_id:int,source_sha256:string,total_filas:int} $data */
    public function createProcessing(array $data): int
    {
        $statement = $this->pdo()->prepare(
            <<<'SQL'
            INSERT INTO producto_importaciones (
                confirmation_token,
                preview_id,
                usuario_id,
                source_sha256,
                estado,
                total_filas,
                creados,
                resultado_resumen_json,
                completado_en
            ) VALUES (
                :confirmation_token,
                :preview_id,
                :usuario_id,
                :source_sha256,
                'PROCESSING',
                :total_filas,
                0,
                NULL,
                NULL
            )
            SQL
        );
        $statement->execute([
            'confirmation_token' => $this->token($data['confirmation_token']),
            'preview_id' => $this->token($data['preview_id']),
            'usuario_id' => $data['usuario_id'],
            'source_sha256' => $this->digest($data['source_sha256']),
            'total_filas' => $data['total_filas'],
        ]);

        return (int) $this->pdo()->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findByConfirmationToken(string $token): ?array
    {
        return $this->find($token, null);
    }

    /** @return array<string, mixed>|null */
    public function findSucceededByTokenForUser(string $token, int $userId): ?array
    {
        return $this->find($token, $userId, 'SUCCEEDED');
    }

    /** @param array<string, scalar|null> $resultSummary */
    public function markSucceeded(int $id, int $createdCount, array $resultSummary): void
    {
        if ($id < 1 || $createdCount < 0) {
            throw new \InvalidArgumentException('Invalid import run result.');
        }

        $encoded = json_encode(
            $resultSummary,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if (!is_string($encoded) || $encoded === '') {
            throw new \RuntimeException('Import result JSON is empty.');
        }

        $statement = $this->pdo()->prepare(
            <<<'SQL'
            UPDATE producto_importaciones
            SET estado = 'SUCCEEDED',
                creados = :creados,
                resultado_resumen_json = :resultado,
                completado_en = CURRENT_TIMESTAMP
            WHERE id = :id
              AND estado = 'PROCESSING'
            SQL
        );
        $statement->execute([
            'id' => $id,
            'creados' => $createdCount,
            'resultado' => $encoded,
        ]);

        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Import run is not in PROCESSING state.');
        }
    }

    /** @return array<string, mixed>|null */
    private function find(string $token, ?int $userId, ?string $state = null): ?array
    {
        $sql = 'SELECT id, confirmation_token, preview_id, usuario_id,
                       source_sha256, estado, total_filas, creados,
                       resultado_resumen_json, creado_en, completado_en
                FROM producto_importaciones
                WHERE confirmation_token = :confirmation_token';
        $params = ['confirmation_token' => $this->token($token)];

        if ($userId !== null) {
            $sql .= ' AND usuario_id = :usuario_id';
            $params['usuario_id'] = $userId;
        }
        if ($state !== null) {
            $sql .= ' AND estado = :estado';
            $params['estado'] = $state;
        }
        $sql .= ' LIMIT 1';

        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function pdo(): PDO
    {
        return $this->connection->pdo();
    }

    private function token(string $value): string
    {
        if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new \InvalidArgumentException('Import token must be 64 lowercase hex characters.');
        }
        return $value;
    }

    private function digest(string $value): string
    {
        if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new \InvalidArgumentException('Source digest must be 64 lowercase hex characters.');
        }
        return $value;
    }
}
