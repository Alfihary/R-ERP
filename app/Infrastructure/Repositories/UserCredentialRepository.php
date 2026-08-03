<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;
use PDO;

final class UserCredentialRepository
{
    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activeUser(int $userId): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT id, username, email
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
    public function credentialByUser(int $userId): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT estatus, emitida_en, creado_en
            FROM credenciales_usuario
            WHERE usuario_id = :usuario_id
            LIMIT 1
            SQL
        );
        $statement->execute(['usuario_id' => $userId]);
        $credential = $statement->fetch(PDO::FETCH_ASSOC);

        return $credential === false ? null : $credential;
    }

    public function createBaseCredential(int $userId): void
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            INSERT INTO credenciales_usuario (
                usuario_id,
                estatus,
                emitida_en
            ) VALUES (
                :usuario_id,
                'VIGENTE',
                CURRENT_TIMESTAMP
            )
            SQL
        );
        $statement->execute(['usuario_id' => $userId]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function visualDataByUser(int $userId): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT
                u.username,
                u.email,
                p.primer_nombre,
                p.segundo_nombre,
                p.apellido_paterno,
                p.apellido_materno,
                p.puesto,
                p.telefono_fijo,
                p.telefono_movil,
                p.ubicacion_publica,
                f.id AS foto_id,
                f.nombre_archivo AS foto_nombre_archivo,
                f.mime AS foto_mime,
                f.tamano_bytes AS foto_tamano_bytes,
                f.creado_en AS foto_creado_en
            FROM usuarios u
            LEFT JOIN perfiles_usuario p
                ON p.usuario_id = u.id
            LEFT JOIN usuarios_fotos f
                ON f.usuario_id = u.id
               AND f.activa = 1
               AND f.reemplazada_en IS NULL
               AND f.eliminada_en IS NULL
            WHERE u.id = :usuario_id
              AND u.activo = 1
              AND u.eliminado_en IS NULL
            ORDER BY f.id DESC
            LIMIT 1
            SQL
        );
        $statement->execute(['usuario_id' => $userId]);
        $visual = $statement->fetch(PDO::FETCH_ASSOC);

        return $visual === false ? null : $visual;
    }
}
