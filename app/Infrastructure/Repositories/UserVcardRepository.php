<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;
use PDO;

final class UserVcardRepository
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
            'SELECT id, username, email, activo, eliminado_en
             FROM usuarios
             WHERE id = :id
               AND activo = 1
               AND eliminado_en IS NULL
             LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($user) ? $user : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUser(int $userId, bool $lock = false): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT *
             FROM vcards_usuario
             WHERE usuario_id = :usuario_id
             LIMIT 1'
            . ($lock ? ' FOR UPDATE' : '')
        );
        $statement->execute(['usuario_id' => $userId]);
        $vcard = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($vcard) ? $vcard : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug, bool $publishedOnly = false): ?array
    {
        $sql = 'SELECT *
                FROM vcards_usuario
                WHERE slug = :slug';

        if ($publishedOnly) {
            $sql .= ' AND publicada = 1 AND despublicado_en IS NULL';
        }

        $sql .= ' LIMIT 1';
        $statement = $this->connection->pdo()->prepare($sql);
        $statement->execute(['slug' => $slug]);
        $vcard = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($vcard) ? $vcard : null;
    }

    public function createBase(int $userId, string $slug): int
    {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO vcards_usuario (usuario_id, slug, publicada)
             VALUES (:usuario_id, :slug, 0)'
        );
        $statement->execute([
            'usuario_id' => $userId,
            'slug' => $slug,
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    /**
     * @param array<string, string|null> $data
     */
    public function updateConfiguration(int $vcardId, array $data): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE vcards_usuario
             SET slug = :slug,
                 titulo_publico = :titulo_publico,
                 descripcion_publica = :descripcion_publica,
                 canal_contacto_preferido = :canal_contacto_preferido
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $vcardId,
            'slug' => $data['slug'],
            'titulo_publico' => $data['titulo_publico'],
            'descripcion_publica' => $data['descripcion_publica'],
            'canal_contacto_preferido' => $data['canal_contacto_preferido'],
        ]);
    }

    public function publish(int $vcardId): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE vcards_usuario
             SET publicada = 1,
                 publicado_en = CURRENT_TIMESTAMP,
                 despublicado_en = NULL
             WHERE id = :id'
        );
        $statement->execute(['id' => $vcardId]);
    }

    public function unpublish(int $vcardId): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE vcards_usuario
             SET publicada = 0,
                 despublicado_en = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $statement->execute(['id' => $vcardId]);
    }

    public function slugExists(string $slug, ?int $exceptVcardId = null): bool
    {
        $sql = 'SELECT COUNT(*)
                FROM vcards_usuario
                WHERE slug = :slug';
        $params = ['slug' => $slug];

        if ($exceptVcardId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptVcardId;
        }

        $statement = $this->connection->pdo()->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function publicDataForSlug(string $slug): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT
                v.id AS vcard_id,
                v.usuario_id,
                v.slug,
                v.titulo_publico,
                v.descripcion_publica,
                v.canal_contacto_preferido,
                u.username,
                u.email,
                p.primer_nombre,
                p.segundo_nombre,
                p.apellido_paterno,
                p.apellido_materno,
                p.puesto,
                p.telefono_fijo,
                p.telefono_movil,
                p.sitio_web,
                p.linkedin_url,
                p.facebook_url,
                p.instagram_url,
                p.whatsapp,
                p.google_maps_url,
                p.ubicacion_publica,
                uf.id AS foto_id,
                e.nombre AS empresa_nombre,
                a.nombre AS almacen_nombre
            FROM vcards_usuario v
            INNER JOIN usuarios u
                ON u.id = v.usuario_id
               AND u.activo = 1
               AND u.eliminado_en IS NULL
            LEFT JOIN perfiles_usuario p
                ON p.usuario_id = u.id
            LEFT JOIN usuarios_fotos uf
                ON uf.usuario_id = u.id
               AND uf.activa = 1
               AND uf.reemplazada_en IS NULL
               AND uf.eliminada_en IS NULL
            LEFT JOIN usuario_empresas ue
                ON ue.usuario_id = u.id
               AND ue.activo = 1
               AND ue.eliminado_en IS NULL
            LEFT JOIN empresas e
                ON e.id = ue.empresa_id
               AND e.activo = 1
               AND e.eliminado_en IS NULL
            LEFT JOIN usuario_almacenes ua
                ON ua.usuario_id = u.id
               AND ua.activo = 1
               AND ua.eliminado_en IS NULL
            LEFT JOIN almacenes a
                ON a.id = ua.almacen_id
               AND a.empresa_id = ue.empresa_id
               AND a.activo = 1
               AND a.eliminado_en IS NULL
            WHERE v.slug = :slug
              AND v.publicada = 1
              AND v.despublicado_en IS NULL
            ORDER BY e.id ASC, a.id ASC
            LIMIT 1
            SQL
        );
        $statement->execute(['slug' => $slug]);
        $data = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($data) ? $data : null;
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
