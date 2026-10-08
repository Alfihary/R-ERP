<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;
use PDO;

final class ProfileRepository
{
    public function __construct(
        private readonly ConnectionProvider $connection
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveUser(int $userId): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT
                id,
                username,
                email,
                password_hash,
                activo,
                deleted_at
            FROM usuarios
            WHERE id = :id
              AND activo = 1
              AND deleted_at IS NULL
            LIMIT 1
            SQL
        );

        $statement->execute([
            'id' => $userId,
        ]);

        $user = $statement->fetch(PDO::FETCH_ASSOC);

        return $user === false ? null : $user;
    }

    /**
     * El perfil base vive en usuarios.
     *
     * Los campos sociales se obtienen de usuario_vcards.
     *
     * @return array<string, mixed>|null
     */
    public function findProfileByUser(int $userId): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT
                u.id AS id,
                u.id AS usuario_id,

                u.nombre AS primer_nombre,
                u.nombre_2 AS segundo_nombre,
                u.apellido_paterno,
                u.apellido_materno,
                u.puesto,

                u.telefono AS telefono_fijo,
                u.telefono_movil,

                v.website AS sitio_web,
                v.linkedin AS linkedin_url,
                v.facebook AS facebook_url,
                v.instagram AS instagram_url,
                v.whatsapp,
                v.google_maps_url,
                v.direccion AS ubicacion_publica,

                u.created_at AS creado_en,
                u.updated_at AS actualizado_en

            FROM usuarios u

            LEFT JOIN usuario_vcards v
                ON v.usuario_id = u.id
               AND v.deleted_at IS NULL

            WHERE u.id = :usuario_id
              AND u.activo = 1
              AND u.deleted_at IS NULL

            LIMIT 1
            SQL
        );

        $statement->execute([
            'usuario_id' => $userId,
        ]);

        $profile = $statement->fetch(PDO::FETCH_ASSOC);

        return $profile === false ? null : $profile;
    }

    /**
     * No es necesaria una tabla perfiles_usuario.
     *
     * Si el usuario existe, ya existe su perfil base.
     */
    public function createBaseProfile(int $userId): int
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT id
            FROM usuarios
            WHERE id = :usuario_id
              AND activo = 1
              AND deleted_at IS NULL
            LIMIT 1
            SQL
        );

        $statement->execute([
            'usuario_id' => $userId,
        ]);

        $id = $statement->fetchColumn();

        if ($id === false) {
            throw new \RuntimeException(
                'No se pudo crear el perfil porque el usuario no existe.'
            );
        }

        return (int) $id;
    }

    /**
     * @param array<string, string|null> $data
     */
    public function updateProfile(int $userId, array $data): void
    {
        $pdo = $this->connection->pdo();

        /*
         * Datos principales.
         */
        $statement = $pdo->prepare(
            <<<'SQL'
            UPDATE usuarios
            SET nombre = :primer_nombre,
                nombre_2 = :segundo_nombre,
                apellido_paterno = :apellido_paterno,
                apellido_materno = :apellido_materno,
                puesto = :puesto,
                telefono = :telefono_fijo,
                telefono_movil = :telefono_movil,
                updated_at = CURRENT_TIMESTAMP,
                updated_by = :updated_by
            WHERE id = :usuario_id
              AND activo = 1
              AND deleted_at IS NULL
            SQL
        );

        $statement->execute([
            'primer_nombre' => $data['primer_nombre'],
            'segundo_nombre' => $data['segundo_nombre'],
            'apellido_paterno' => $data['apellido_paterno'],
            'apellido_materno' => $data['apellido_materno'],
            'puesto' => $data['puesto'],
            'telefono_fijo' => $data['telefono_fijo'],
            'telefono_movil' => $data['telefono_movil'],
            'updated_by' => $userId,
            'usuario_id' => $userId,
        ]);

        /*
         * Comprobamos si ya existe la vCard del usuario.
         */
        $vcardStatement = $pdo->prepare(
            <<<'SQL'
            SELECT id
            FROM usuario_vcards
            WHERE usuario_id = :usuario_id
              AND deleted_at IS NULL
            LIMIT 1
            SQL
        );

        $vcardStatement->execute([
            'usuario_id' => $userId,
        ]);

        $vcardId = $vcardStatement->fetchColumn();

        /*
         * Si no existe, creamos una configuración base.
         */
        if ($vcardId === false) {
            $insert = $pdo->prepare(
                <<<'SQL'
                INSERT INTO usuario_vcards (
                    usuario_id,
                    slug_publico,
                    titulo_publico,
                    website,
                    linkedin,
                    facebook,
                    instagram,
                    whatsapp,
                    direccion,
                    google_maps_url,
                    activa,
                    created_at,
                    created_by
                )
                SELECT
                    u.id,
                    CONCAT('usuario-', u.id),
                    TRIM(
                        CONCAT_WS(
                            ' ',
                            u.nombre,
                            u.nombre_2,
                            u.apellido_paterno,
                            u.apellido_materno
                        )
                    ),
                    :website,
                    :linkedin,
                    :facebook,
                    :instagram,
                    :whatsapp,
                    :direccion,
                    :google_maps_url,
                    0,
                    CURRENT_TIMESTAMP,
                    :created_by
                FROM usuarios u
                WHERE u.id = :usuario_id
                  AND u.activo = 1
                  AND u.deleted_at IS NULL
                SQL
            );

            $insert->execute([
                'website' => $data['sitio_web'],
                'linkedin' => $data['linkedin_url'],
                'facebook' => $data['facebook_url'],
                'instagram' => $data['instagram_url'],
                'whatsapp' => $data['whatsapp'],
                'direccion' => $data['ubicacion_publica'],
                'google_maps_url' => $data['google_maps_url'],
                'created_by' => $userId,
                'usuario_id' => $userId,
            ]);

            return;
        }

        /*
         * Si ya existe, conservamos slug, publicación,
         * privacidad, productos, QR, visitas, etc.
         */
        $updateVcard = $pdo->prepare(
            <<<'SQL'
            UPDATE usuario_vcards
            SET website = :website,
                linkedin = :linkedin,
                facebook = :facebook,
                instagram = :instagram,
                whatsapp = :whatsapp,
                direccion = :direccion,
                google_maps_url = :google_maps_url,
                updated_at = CURRENT_TIMESTAMP,
                updated_by = :updated_by
            WHERE id = :id
              AND usuario_id = :usuario_id
              AND deleted_at IS NULL
            SQL
        );

        $updateVcard->execute([
            'website' => $data['sitio_web'],
            'linkedin' => $data['linkedin_url'],
            'facebook' => $data['facebook_url'],
            'instagram' => $data['instagram_url'],
            'whatsapp' => $data['whatsapp'],
            'direccion' => $data['ubicacion_publica'],
            'google_maps_url' => $data['google_maps_url'],
            'updated_by' => $userId,
            'id' => (int) $vcardId,
            'usuario_id' => $userId,
        ]);
    }

    public function updatePasswordHash(
        int $userId,
        string $passwordHash
    ): void {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            UPDATE usuarios
            SET password_hash = :password_hash,
                updated_at = CURRENT_TIMESTAMP,
                updated_by = :updated_by
            WHERE id = :id
              AND activo = 1
              AND deleted_at IS NULL
            SQL
        );

        $statement->execute([
            'id' => $userId,
            'password_hash' => $passwordHash,
            'updated_by' => $userId,
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
            if (
                $ownsTransaction
                && $pdo->inTransaction()
            ) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }
}