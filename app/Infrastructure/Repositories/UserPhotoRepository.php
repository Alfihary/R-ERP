<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;
use PDO;

final class UserPhotoRepository
{
    public function __construct(
        private readonly ConnectionProvider $connection
    ) {
    }

    /**
     * Compatibilidad con la BD actual.
     *
     * La foto activa se guarda directamente en:
     * usuarios.foto
     *
     * @return array<string, mixed>|null
     */
    public function activePhoto(int $userId, bool $lock = false): ?array
    {
        $sql = <<<'SQL'
        SELECT
            id,
            foto,
            created_at,
            updated_at
        FROM usuarios
        WHERE id = :usuario_id
          AND activo = 1
          AND deleted_at IS NULL
          AND foto IS NOT NULL
          AND TRIM(foto) <> ''
        LIMIT 1
        SQL;

        if ($lock) {
            $sql .= ' FOR UPDATE';
        }

        $statement = $this->connection->pdo()->prepare($sql);

        $statement->execute([
            'usuario_id' => $userId,
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $relativePath = str_replace('\\', '/', trim((string) $row['foto']));
        $fileName = basename($relativePath);
        $extension = strtolower((string) pathinfo(
            $fileName,
            PATHINFO_EXTENSION
        ));

        $mime = match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };

        return [
            'id' => (int) $row['id'],
            'usuario_id' => (int) $row['id'],
            'disco' => 'local',
            'ruta_relativa' => $relativePath,
            'nombre_original' => null,
            'nombre_archivo' => $fileName,
            'mime' => $mime,
            'extension' => $extension,
            'tamano_bytes' => 0,
            'sha256' => '',
            'ancho' => null,
            'alto' => null,
            'activa' => 1,
            'creado_por' => null,
            'creado_en' => $row['updated_at'] ?? $row['created_at'],
            'reemplazada_en' => null,
            'eliminada_en' => null,
        ];
    }

    /**
     * En la BD actual no existe historial usuarios_fotos.
     *
     * La fotografía nueva sustituye directamente usuarios.foto,
     * por lo que aquí no es necesario actualizar otra tabla.
     */
    public function deactivateActivePhotos(
        int $userId,
        ?int $actorId
    ): void {
        // Intencionalmente vacío.
        //
        // registrarFoto() ejecuta esta operación dentro de una transacción
        // y después insertPhoto() sustituye usuarios.foto.
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
            UPDATE usuarios
            SET foto = :foto,
                updated_at = CURRENT_TIMESTAMP,
                updated_by = :updated_by
            WHERE id = :usuario_id
              AND activo = 1
              AND deleted_at IS NULL
            SQL
        );

        $statement->execute([
            'foto' => $metadata['ruta_relativa'],
            'updated_by' => $actorId,
            'usuario_id' => $userId,
        ]);

        return $userId;
    }

    public function markActivePhotoDeleted(
        int $userId,
        ?int $actorId
    ): void {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            UPDATE usuarios
            SET foto = NULL,
                updated_at = CURRENT_TIMESTAMP,
                updated_by = :updated_by
            WHERE id = :usuario_id
              AND activo = 1
              AND deleted_at IS NULL
            SQL
        );

        $statement->execute([
            'updated_by' => $actorId,
            'usuario_id' => $userId,
        ]);
    }
}