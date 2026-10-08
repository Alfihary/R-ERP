<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;
use PDO;

final class UserVcardRepository
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

        return is_array($user) ? $user : null;
    }


    /** @return list<string> */
    public function activeNotificationRoleCodes(int $userId): array
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT r.codigo
            FROM usuarios u
            INNER JOIN usuario_rol ur ON ur.usuario_id = u.id
            INNER JOIN roles r ON r.id = ur.rol_id AND r.activo = 1 AND r.deleted_at IS NULL
            WHERE u.id = :usuario_id
              AND u.activo = 1
              AND u.deleted_at IS NULL
              AND r.codigo IN ('VENTAS', 'GERENCIA', 'ADMIN')
            ORDER BY FIELD(r.codigo, 'VENTAS', 'GERENCIA', 'ADMIN')
            SQL
        );
        $statement->execute(['usuario_id' => $userId]);
        return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    /**
     * Devuelve la estructura esperada por VcardService
     * usando la tabla real usuario_vcards.
     *
     * @return array<string, mixed>|null
     */
    public function findByUser(
        int $userId,
        bool $lock = false
    ): ?array {
        $sql = <<<'SQL'
        SELECT
            v.id,
            v.usuario_id,

            v.slug_publico AS slug,
            v.titulo_publico,
            v.descripcion_publica,

            NULL AS canal_contacto_preferido,

            v.activa AS publicada,

            CASE
                WHEN v.activa = 1
                THEN COALESCE(v.updated_at, v.created_at)
                ELSE NULL
            END AS publicado_en,

            CASE
                WHEN v.activa = 0
                THEN v.updated_at
                ELSE NULL
            END AS despublicado_en,

            v.website,
            v.linkedin,
            v.facebook,
            v.instagram,
            v.whatsapp,
            v.direccion,
            v.ciudad,
            v.estado,
            v.codigo_postal,
            v.pais,
            v.google_maps_url,

            v.mostrar_website,
            v.mostrar_linkedin,
            v.mostrar_facebook,
            v.mostrar_instagram,
            v.mostrar_whatsapp,
            v.mostrar_google_maps,
            v.mostrar_email,
            v.mostrar_telefono,
            v.mostrar_telefono_movil,
            v.mostrar_foto,
            v.mostrar_empresa,
            v.mostrar_almacen,
            v.mostrar_puesto,
            v.mostrar_ubicacion,
            v.mostrar_productos_publicos,

            v.qr_path,
            v.visitas,

            v.created_at AS creado_en,
            v.updated_at AS actualizado_en,

            v.created_at,
            v.updated_at,
            v.deleted_at,
            v.created_by,
            v.updated_by,
            v.deleted_by

        FROM usuario_vcards v

        WHERE v.usuario_id = :usuario_id
          AND v.deleted_at IS NULL

        LIMIT 1
        SQL;

        if ($lock) {
            $sql .= ' FOR UPDATE';
        }

        $statement = $this->connection->pdo()->prepare($sql);

        $statement->execute([
            'usuario_id' => $userId,
        ]);

        $vcard = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($vcard) ? $vcard : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySlug(
        string $slug,
        bool $publishedOnly = false
    ): ?array {
        $sql = <<<'SQL'
        SELECT
            v.id,
            v.usuario_id,

            v.slug_publico AS slug,
            v.titulo_publico,
            v.descripcion_publica,

            NULL AS canal_contacto_preferido,

            v.activa AS publicada,

            CASE
                WHEN v.activa = 1
                THEN COALESCE(v.updated_at, v.created_at)
                ELSE NULL
            END AS publicado_en,

            CASE
                WHEN v.activa = 0
                THEN v.updated_at
                ELSE NULL
            END AS despublicado_en,

            v.website,
            v.linkedin,
            v.facebook,
            v.instagram,
            v.whatsapp,
            v.direccion,
            v.ciudad,
            v.estado,
            v.codigo_postal,
            v.pais,
            v.google_maps_url,

            v.mostrar_website,
            v.mostrar_linkedin,
            v.mostrar_facebook,
            v.mostrar_instagram,
            v.mostrar_whatsapp,
            v.mostrar_google_maps,
            v.mostrar_email,
            v.mostrar_telefono,
            v.mostrar_telefono_movil,
            v.mostrar_foto,
            v.mostrar_empresa,
            v.mostrar_almacen,
            v.mostrar_puesto,
            v.mostrar_ubicacion,
            v.mostrar_productos_publicos,

            v.qr_path,
            v.visitas,

            v.created_at AS creado_en,
            v.updated_at AS actualizado_en,

            v.created_at,
            v.updated_at,
            v.deleted_at,
            v.created_by,
            v.updated_by,
            v.deleted_by

        FROM usuario_vcards v

        WHERE v.slug_publico = :slug
          AND v.deleted_at IS NULL
        SQL;

        if ($publishedOnly) {
            $sql .= ' AND v.activa = 1';
        }

        $sql .= ' LIMIT 1';

        $statement = $this->connection->pdo()->prepare($sql);

        $statement->execute([
            'slug' => $slug,
        ]);

        $vcard = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($vcard) ? $vcard : null;
    }

    public function createBase(
        int $userId,
        string $slug
    ): int {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            INSERT INTO usuario_vcards (
                usuario_id,
                slug_publico,
                activa,
                created_at,
                created_by
            )
            VALUES (
                :usuario_id,
                :slug,
                0,
                CURRENT_TIMESTAMP,
                :created_by
            )
            SQL
        );

        $statement->execute([
            'usuario_id' => $userId,
            'slug' => $slug,
            'created_by' => $userId,
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    /**
     * @param array<string, string|null> $data
     */
    public function updateConfiguration(
        int $vcardId,
        array $data
    ): void {
        /*
         * canal_contacto_preferido no existe en la BD actual.
         * Por ello no se persiste aquí.
         *
         * No creamos una columna nueva solo para satisfacer
         * el código más reciente.
         */
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            UPDATE usuario_vcards
            SET slug_publico = :slug,
                titulo_publico = :titulo_publico,
                descripcion_publica = :descripcion_publica,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
              AND deleted_at IS NULL
            SQL
        );

        $statement->execute([
            'id' => $vcardId,
            'slug' => $data['slug'],
            'titulo_publico' => $data['titulo_publico'],
            'descripcion_publica' => $data['descripcion_publica'],
        ]);
    }

    public function publish(int $vcardId): void
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            UPDATE usuario_vcards
            SET activa = 1,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
              AND deleted_at IS NULL
            SQL
        );

        $statement->execute([
            'id' => $vcardId,
        ]);
    }

    public function unpublish(int $vcardId): void
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            UPDATE usuario_vcards
            SET activa = 0,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
              AND deleted_at IS NULL
            SQL
        );

        $statement->execute([
            'id' => $vcardId,
        ]);
    }

    public function slugExists(
        string $slug,
        ?int $exceptVcardId = null
    ): bool {
        $sql = <<<'SQL'
        SELECT COUNT(*)
        FROM usuario_vcards
        WHERE slug_publico = :slug
          AND deleted_at IS NULL
        SQL;

        $params = [
            'slug' => $slug,
        ];

        if ($exceptVcardId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptVcardId;
        }

        $statement = $this->connection->pdo()->prepare($sql);

        $statement->execute($params);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * Datos públicos de una vCard.
     *
     * Usa las tablas reales:
     *
     * usuarios
     * usuario_vcards
     * empresas
     * almacenes
     *
     * @return array<string, mixed>|null
     */
    public function publicDataForSlug(string $slug): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT
                v.id AS vcard_id,
                v.usuario_id,

                v.slug_publico AS slug,
                v.titulo_publico,
                v.descripcion_publica,

                NULL AS canal_contacto_preferido,

                u.username,
                u.email,

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

                CASE
                    WHEN u.foto IS NOT NULL
                     AND TRIM(u.foto) <> ''
                    THEN u.id
                    ELSE NULL
                END AS foto_id,

                COALESCE(
                    NULLIF(e.nombre_comercial, ''),
                    e.razon_social
                ) AS empresa_nombre,

                a.nombre AS almacen_nombre,

                v.mostrar_website,
                v.mostrar_linkedin,
                v.mostrar_facebook,
                v.mostrar_instagram,
                v.mostrar_whatsapp,
                v.mostrar_google_maps,
                v.mostrar_email,
                v.mostrar_telefono,
                v.mostrar_telefono_movil,
                v.mostrar_foto,
                v.mostrar_empresa,
                v.mostrar_almacen,
                v.mostrar_puesto,
                v.mostrar_ubicacion,
                v.mostrar_productos_publicos,

                v.activa AS publicada

            FROM usuario_vcards v

            INNER JOIN usuarios u
                ON u.id = v.usuario_id
               AND u.activo = 1
               AND u.deleted_at IS NULL

            LEFT JOIN almacenes a
                ON a.id = u.almacen_id
               AND a.activo = 1
               AND a.deleted_at IS NULL

            LEFT JOIN empresas e
                ON e.id = COALESCE(
                    u.empresa_id,
                    a.empresa_id
                )
               AND e.activo = 1
               AND e.deleted_at IS NULL

            WHERE v.slug_publico = :slug
              AND v.activa = 1
              AND v.deleted_at IS NULL

            LIMIT 1
            SQL
        );

        $statement->execute([
            'slug' => $slug,
        ]);

        $data = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($data) ? $data : null;
    }

    /**
     * Foto pública asociada a la vCard.
     *
     * En la BD actual la ruta se guarda en usuarios.foto.
     *
     * @return array<string, mixed>|null
     */
    public function publicPhotoForSlug(string $slug): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT
                v.id AS vcard_id,
                v.usuario_id,

                v.slug_publico AS slug,

                u.id AS foto_id,

                u.foto AS ruta_relativa,

                SUBSTRING_INDEX(
                    REPLACE(u.foto, '\\', '/'),
                    '/',
                    -1
                ) AS nombre_archivo,

                CASE
                    LOWER(
                        SUBSTRING_INDEX(
                            u.foto,
                            '.',
                            -1
                        )
                    )
                    WHEN 'jpg' THEN 'image/jpeg'
                    WHEN 'jpeg' THEN 'image/jpeg'
                    WHEN 'png' THEN 'image/png'
                    WHEN 'webp' THEN 'image/webp'
                    ELSE 'application/octet-stream'
                END AS mime,

                LOWER(
                    SUBSTRING_INDEX(
                        u.foto,
                        '.',
                        -1
                    )
                ) AS extension,

                0 AS tamano_bytes,
                '' AS sha256,
                NULL AS ancho,
                NULL AS alto

            FROM usuario_vcards v

            INNER JOIN usuarios u
                ON u.id = v.usuario_id
               AND u.activo = 1
               AND u.deleted_at IS NULL
               AND u.foto IS NOT NULL
               AND TRIM(u.foto) <> ''

            WHERE v.slug_publico = :slug
              AND v.activa = 1
              AND v.deleted_at IS NULL

            LIMIT 1
            SQL
        );

        $statement->execute([
            'slug' => $slug,
        ]);

        $photo = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($photo) ? $photo : null;
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


