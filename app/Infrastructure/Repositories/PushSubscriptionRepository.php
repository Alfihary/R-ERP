<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;
use PDO;

final class PushSubscriptionRepository
{
    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    /** @param array{endpoint:string,p256dh:string,auth:string,content_encoding:string,user_agent:?string} $data */
    public function upsertForUser(int $userId, array $data): int
    {
        $pdo = $this->connection->pdo();
        $fingerprint = hash('sha256', $data['endpoint']);
        $existing = $pdo->prepare(
            'SELECT id, usuario_id FROM push_subscriptions WHERE endpoint_fingerprint = :fingerprint LIMIT 1'
        );
        $existing->execute(['fingerprint' => $fingerprint]);
        $existingRow = $existing->fetch(PDO::FETCH_ASSOC);
        if (is_array($existingRow) && (int) $existingRow['usuario_id'] !== $userId) {
            return 0;
        }
        $statement = $pdo->prepare(<<<'SQL'
INSERT INTO push_subscriptions (
    usuario_id, endpoint, endpoint_fingerprint, p256dh, auth, content_encoding,
    user_agent, activo, revoked_at, last_used_at, created_at, updated_at
) VALUES (
    :usuario_id, :endpoint, :fingerprint, :p256dh, :auth, :content_encoding,
    :user_agent, 1, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
)
ON DUPLICATE KEY UPDATE
    usuario_id = VALUES(usuario_id),
    endpoint = VALUES(endpoint),
    p256dh = VALUES(p256dh),
    auth = VALUES(auth),
    content_encoding = VALUES(content_encoding),
    user_agent = VALUES(user_agent),
    activo = 1,
    revoked_at = NULL,
    last_used_at = CURRENT_TIMESTAMP,
    updated_at = CURRENT_TIMESTAMP
SQL);
        $statement->execute([
            'usuario_id' => $userId,
            'endpoint' => $data['endpoint'],
            'fingerprint' => $fingerprint,
            'p256dh' => $data['p256dh'],
            'auth' => $data['auth'],
            'content_encoding' => $data['content_encoding'],
            'user_agent' => $data['user_agent'],
        ]);

        $lookup = $pdo->prepare(
            'SELECT id FROM push_subscriptions WHERE endpoint_fingerprint = :fingerprint LIMIT 1'
        );
        $lookup->execute(['fingerprint' => $fingerprint]);
        return (int) $lookup->fetchColumn();
    }

    public function revokeForUser(int $userId, string $endpoint): bool
    {
        $statement = $this->connection->pdo()->prepare(<<<'SQL'
UPDATE push_subscriptions
SET activo = 0, revoked_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
WHERE usuario_id = :usuario_id AND endpoint_fingerprint = :fingerprint
SQL);
        $statement->execute([
            'usuario_id' => $userId,
            'fingerprint' => hash('sha256', $endpoint),
        ]);
        return $statement->rowCount() > 0;
    }

    /** @return list<array<string,mixed>> */
    public function activeForUser(int $userId): array
    {
        $statement = $this->connection->pdo()->prepare(<<<'SQL'
SELECT id, endpoint, p256dh, auth, content_encoding
FROM push_subscriptions
WHERE usuario_id = :usuario_id AND activo = 1 AND revoked_at IS NULL
ORDER BY id
SQL);
        $statement->execute(['usuario_id' => $userId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function revokeById(int $id, int $userId): void
    {
        $statement = $this->connection->pdo()->prepare(<<<'SQL'
UPDATE push_subscriptions
SET activo = 0, revoked_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
WHERE id = :id AND usuario_id = :usuario_id
SQL);
        $statement->execute(['id' => $id, 'usuario_id' => $userId]);
    }
}
