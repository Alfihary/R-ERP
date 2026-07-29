<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;
use PDO;

final class UserPhotoRepository
{
    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activePhoto(int $userId, bool $lock = false): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT
                id,
                usuario_id,
                disco,
                ruta_relativa,
                nombre_original,
                nombre_archivo,
                mime,
                extension,
                tamano_bytes,
                sha256,
                ancho,
                alto,
                activa,
                creado_por,
                creado_en,
                reemplazada_en,
                eliminada_en
            FROM usuarios_fotos
            WHERE usuario_id = :usuario_id
              AND activa = 1
              AND reemplazada_en IS NULL
              AND eliminada_en IS NULL
            ORDER BY id DESC
            LIMIT 1
            SQL
            . ($lock ? ' FOR UPDATE' : '')
        );
        $statement->execute(['usuario_id' => $userId]);
        $photo = $statement->fetch(PDO::FETCH_ASSOC);

        return $photo === false ? null : $photo;
    }

    public function deactivateActivePhotos(int $userId, ?int $actorId): void
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            UPDATE usuarios_fotos
            SET activa = 0,
                reemplazada_en = CURRENT_TIMESTAMP
            WHERE usuario_id = :usuario_id
              AND activa = 1
              AND reemplazada_en IS NULL
              AND eliminada_en IS NULL
            SQL
        );
        $statement->execute([
            'usuario_id' => $userId,
        ]);
    }

    /**
     * @param array{
     *     ruta_relativa: string,
     *     nombre_archivo: string,
     *     nombre_original: string|null,
     *     mime: string,
     *     extension: string,
     *     tamano_bytes: int,
     *     sha256: string,
     *     ancho: int|null,
     *     alto: int|null
     * } $metadata
     */
    public function insertPhoto(
        int $userId,
        array $metadata,
        ?int $actorId
    ): int {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            INSERT INTO usuarios_fotos (
                usuario_id,
                disco,
                ruta_relativa,
                nombre_original,
                nombre_archivo,
                mime,
                extension,
                tamano_bytes,
                sha256,
                ancho,
                alto,
                activa,
                creado_por
            )
            VALUES (
                :usuario_id,
                'local',
                :ruta_relativa,
                :nombre_original,
                :nombre_archivo,
                :mime,
                :extension,
                :tamano_bytes,
                :sha256,
                :ancho,
                :alto,
                1,
                :creado_por
            )
            SQL
        );
        $statement->execute([
            'usuario_id' => $userId,
            'ruta_relativa' => $metadata['ruta_relativa'],
            'nombre_original' => $metadata['nombre_original'],
            'nombre_archivo' => $metadata['nombre_archivo'],
            'mime' => $metadata['mime'],
            'extension' => $metadata['extension'],
            'tamano_bytes' => $metadata['tamano_bytes'],
            'sha256' => $metadata['sha256'],
            'ancho' => $metadata['ancho'],
            'alto' => $metadata['alto'],
            'creado_por' => $actorId,
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    public function markActivePhotoDeleted(int $userId, ?int $actorId): void
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            UPDATE usuarios_fotos
            SET activa = 0,
                eliminada_en = CURRENT_TIMESTAMP
            WHERE usuario_id = :usuario_id
              AND activa = 1
              AND reemplazada_en IS NULL
              AND eliminada_en IS NULL
            SQL
        );
        $statement->execute([
            'usuario_id' => $userId,
        ]);
    }
}
