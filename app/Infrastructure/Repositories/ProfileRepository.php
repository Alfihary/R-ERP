<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;
use PDO;

final class ProfileRepository
{
    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveUser(int $userId): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT id, username, email, password_hash, activo, eliminado_en
            FROM usuarios
            WHERE id = :id
              AND activo = 1
              AND eliminado_en IS NULL
            LIMIT 1
            SQL
        );
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);

        return $user === false ? null : $user;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findProfileByUser(int $userId): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT
                id,
                usuario_id,
                primer_nombre,
                segundo_nombre,
                apellido_paterno,
                apellido_materno,
                puesto,
                telefono_fijo,
                telefono_movil,
                sitio_web,
                linkedin_url,
                facebook_url,
                instagram_url,
                whatsapp,
                google_maps_url,
                ubicacion_publica,
                creado_en,
                actualizado_en
            FROM perfiles_usuario
            WHERE usuario_id = :usuario_id
            LIMIT 1
            SQL
        );
        $statement->execute(['usuario_id' => $userId]);
        $profile = $statement->fetch(PDO::FETCH_ASSOC);

        return $profile === false ? null : $profile;
    }

    public function createBaseProfile(int $userId): int
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            INSERT INTO perfiles_usuario (usuario_id)
            VALUES (:usuario_id)
            SQL
        );
        $statement->execute(['usuario_id' => $userId]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    /**
     * @param array<string, string|null> $data
     */
    public function updateProfile(int $userId, array $data): void
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            UPDATE perfiles_usuario
            SET primer_nombre = :primer_nombre,
                segundo_nombre = :segundo_nombre,
                apellido_paterno = :apellido_paterno,
                apellido_materno = :apellido_materno,
                puesto = :puesto,
                telefono_fijo = :telefono_fijo,
                telefono_movil = :telefono_movil,
                sitio_web = :sitio_web,
                linkedin_url = :linkedin_url,
                facebook_url = :facebook_url,
                instagram_url = :instagram_url,
                whatsapp = :whatsapp,
                google_maps_url = :google_maps_url,
                ubicacion_publica = :ubicacion_publica
            WHERE usuario_id = :usuario_id
            SQL
        );
        $statement->execute([
            ...$data,
            'usuario_id' => $userId,
        ]);
    }

    public function updatePasswordHash(int $userId, string $passwordHash): void
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            UPDATE usuarios
            SET password_hash = :password_hash,
                actualizado_en = CURRENT_TIMESTAMP,
                actualizado_por = :actualizado_por
            WHERE id = :id
              AND activo = 1
              AND eliminado_en IS NULL
            SQL
        );
        $statement->execute([
            'id' => $userId,
            'password_hash' => $passwordHash,
            'actualizado_por' => $userId,
        ]);
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function transactional(callable $operation): mixed
    {
        $pdo = $this->connection->pdo();
        $ownsTransaction = !$pdo->inTransaction();

        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $result = $operation();

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }
}
