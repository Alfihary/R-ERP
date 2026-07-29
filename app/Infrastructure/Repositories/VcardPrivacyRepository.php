<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;
use PDO;

final class VcardPrivacyRepository
{
    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    /**
     * @return array<string, bool>
     */
    public function listByVcard(int $vcardId): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT campo, visible
             FROM vcard_privacidad
             WHERE vcard_id = :vcard_id'
        );
        $statement->execute(['vcard_id' => $vcardId]);
        $privacy = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $privacy[(string) $row['campo']] = (int) $row['visible'] === 1;
        }

        return $privacy;
    }

    /**
     * @param array<string, bool> $defaults
     */
    public function ensureDefaults(int $vcardId, array $defaults): void
    {
        $statement = $this->connection->pdo()->prepare(
            'INSERT IGNORE INTO vcard_privacidad (vcard_id, campo, visible)
             VALUES (:vcard_id, :campo, :visible)'
        );

        foreach ($defaults as $field => $visible) {
            $statement->execute([
                'vcard_id' => $vcardId,
                'campo' => $field,
                'visible' => $visible ? 1 : 0,
            ]);
        }
    }

    /**
     * @param array<string, bool> $visibility
     */
    public function upsertVisibility(int $vcardId, array $visibility): void
    {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO vcard_privacidad (vcard_id, campo, visible)
             VALUES (:vcard_id, :campo, :visible)
             ON DUPLICATE KEY UPDATE
                visible = VALUES(visible),
                actualizado_en = CURRENT_TIMESTAMP'
        );

        foreach ($visibility as $field => $visible) {
            $statement->execute([
                'vcard_id' => $vcardId,
                'campo' => $field,
                'visible' => $visible ? 1 : 0,
            ]);
        }
    }
}
