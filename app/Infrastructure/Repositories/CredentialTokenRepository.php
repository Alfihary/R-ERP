<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;
use PDO;

final class CredentialTokenRepository
{
    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    /**
     * @template T
     * @param callable(PDO): T $operation
     * @return T
     */
    public function transaction(callable $operation): mixed
    {
        $pdo = $this->connection->pdo();
        $started = !$pdo->inTransaction();

        if ($started) {
            $pdo->beginTransaction();
        }

        try {
            $result = $operation($pdo);

            if ($started) {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function credentialIdByUser(int $userId): ?int
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT c.id
            FROM credenciales_usuario c
            INNER JOIN usuarios u
                ON u.id = c.usuario_id
            WHERE c.usuario_id = :usuario_id
              AND u.activo = 1
              AND u.eliminado_en IS NULL
            LIMIT 1
            SQL
        );
        $statement->execute(['usuario_id' => $userId]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function revokeActiveTokens(int $credentialId): int
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            UPDATE credencial_tokens
            SET activo = 0,
                revocado_en = CURRENT_TIMESTAMP
            WHERE credencial_id = :credencial_id
              AND activo = 1
              AND revocado_en IS NULL
            SQL
        );
        $statement->execute(['credencial_id' => $credentialId]);

        return $statement->rowCount();
    }

    public function createToken(int $credentialId, string $tokenHash, string $tokenPrefix): void
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            INSERT INTO credencial_tokens (
                credencial_id,
                token_hash,
                token_prefix,
                activo
            ) VALUES (
                :credencial_id,
                :token_hash,
                :token_prefix,
                1
            )
            SQL
        );
        $statement->execute([
            'credencial_id' => $credentialId,
            'token_hash' => $tokenHash,
            'token_prefix' => $tokenPrefix,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activeTokenByUser(int $userId): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT
                t.id,
                t.token_prefix,
                t.activo,
                t.creado_en,
                t.expira_en,
                t.revocado_en
            FROM credencial_tokens t
            INNER JOIN credenciales_usuario c
                ON c.id = t.credencial_id
            INNER JOIN usuarios u
                ON u.id = c.usuario_id
            WHERE c.usuario_id = :usuario_id
              AND u.activo = 1
              AND u.eliminado_en IS NULL
              AND t.activo = 1
              AND t.revocado_en IS NULL
              AND (t.expira_en IS NULL OR t.expira_en > CURRENT_TIMESTAMP)
            ORDER BY t.id DESC
            LIMIT 1
            SQL
        );
        $statement->execute(['usuario_id' => $userId]);
        $token = $statement->fetch(PDO::FETCH_ASSOC);

        return $token === false ? null : $token;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestTokenByUser(int $userId): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT
                t.id,
                t.token_prefix,
                t.activo,
                t.creado_en,
                t.expira_en,
                t.revocado_en
            FROM credencial_tokens t
            INNER JOIN credenciales_usuario c
                ON c.id = t.credencial_id
            INNER JOIN usuarios u
                ON u.id = c.usuario_id
            WHERE c.usuario_id = :usuario_id
              AND u.activo = 1
              AND u.eliminado_en IS NULL
            ORDER BY t.id DESC
            LIMIT 1
            SQL
        );
        $statement->execute(['usuario_id' => $userId]);
        $token = $statement->fetch(PDO::FETCH_ASSOC);

        return $token === false ? null : $token;
    }

    public function activeTokenCount(int $credentialId): int
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT COUNT(*)
            FROM credencial_tokens
            WHERE credencial_id = :credencial_id
              AND activo = 1
              AND revocado_en IS NULL
            SQL
        );
        $statement->execute(['credencial_id' => $credentialId]);

        return (int) $statement->fetchColumn();
    }
}
