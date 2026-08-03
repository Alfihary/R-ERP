<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;
use PDO;

final class VcardProductRepository
{
    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    public function pdo(): PDO
    {
        return $this->connection->pdo();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activeUser(int $userId): ?array
    {
        $statement = $this->pdo()->prepare(
            'SELECT id, username, email
             FROM usuarios
             WHERE id = :id
               AND activo = 1
               AND eliminado_en IS NULL
             LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function vcardByUser(int $userId): ?array
    {
        $statement = $this->pdo()->prepare(
            'SELECT id, usuario_id, slug, publicada
             FROM vcards_usuario
             WHERE usuario_id = :usuario_id
             LIMIT 1'
        );
        $statement->execute(['usuario_id' => $userId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function productExists(string $productId): bool
    {
        $statement = $this->pdo()->prepare(
            'SELECT COUNT(*)
             FROM productos
             WHERE id_producto = :id_producto
               AND eliminado_en IS NULL'
        );
        $statement->execute(['id_producto' => $productId]);

        return (int) $statement->fetchColumn() === 1;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function privateLinkedProducts(int $vcardId): array
    {
        $statement = $this->pdo()->prepare(
            <<<'SQL'
            SELECT
                vp.id_producto,
                vp.activo,
                vp.destacado,
                vp.orden,
                vp.texto_publico,
                p.descripcion,
                p.activo AS producto_activo,
                u.codigo AS unidad_codigo,
                u.nombre AS unidad_nombre,
                m.nombre AS marca_nombre,
                l.nombre AS linea_nombre,
                c.nombre AS clasificacion_nombre
            FROM vcard_productos vp
            INNER JOIN productos p
                ON p.id_producto = vp.id_producto
               AND p.eliminado_en IS NULL
            INNER JOIN unidades_medida u
                ON u.id = p.unidad_medida_id
            LEFT JOIN marcas m
                ON m.id = p.marca_id
            LEFT JOIN lineas_producto l
                ON l.id = p.linea_producto_id
            LEFT JOIN clasificaciones_producto c
                ON c.id = p.clasificacion_producto_id
            WHERE vp.vcard_id = :vcard_id
              AND vp.eliminado_en IS NULL
            ORDER BY vp.orden ASC, p.descripcion ASC, p.id_producto ASC
            SQL
        );
        $statement->execute(['vcard_id' => $vcardId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param list<array{
     *     id_producto: string,
     *     activo: int,
     *     destacado: int,
     *     orden: int,
     *     texto_publico: string|null
     * }> $products
     */
    public function syncProducts(int $vcardId, int $userId, array $products): void
    {
        $this->pdo()->prepare(
            'UPDATE vcard_productos
             SET activo = 0,
                 eliminado_en = CURRENT_TIMESTAMP
             WHERE vcard_id = :vcard_id'
        )->execute(['vcard_id' => $vcardId]);

        $statement = $this->pdo()->prepare(
            <<<'SQL'
            INSERT INTO vcard_productos (
                vcard_id,
                id_producto,
                activo,
                destacado,
                orden,
                texto_publico,
                creado_por,
                eliminado_en
            ) VALUES (
                :vcard_id,
                :id_producto,
                :activo,
                :destacado,
                :orden,
                :texto_publico,
                :creado_por,
                NULL
            )
            ON DUPLICATE KEY UPDATE
                activo = VALUES(activo),
                destacado = VALUES(destacado),
                orden = VALUES(orden),
                texto_publico = VALUES(texto_publico),
                eliminado_en = NULL
            SQL
        );

        foreach ($products as $product) {
            $statement->execute([
                'vcard_id' => $vcardId,
                'id_producto' => $product['id_producto'],
                'activo' => $product['activo'],
                'destacado' => $product['destacado'],
                'orden' => $product['orden'],
                'texto_publico' => $product['texto_publico'],
                'creado_por' => $userId,
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function publicProductsBySlug(string $slug): array
    {
        $statement = $this->pdo()->prepare(
            <<<'SQL'
            SELECT
                p.id_producto,
                p.descripcion,
                vp.texto_publico,
                vp.destacado,
                vp.orden,
                u.codigo AS unidad_codigo,
                u.nombre AS unidad_nombre,
                m.nombre AS marca_nombre,
                l.nombre AS linea_nombre,
                c.nombre AS clasificacion_nombre
            FROM vcards_usuario v
            INNER JOIN usuarios usr
                ON usr.id = v.usuario_id
               AND usr.activo = 1
               AND usr.eliminado_en IS NULL
            INNER JOIN vcard_privacidad priv
                ON priv.vcard_id = v.id
               AND priv.campo = 'productos'
               AND priv.visible = 1
            INNER JOIN vcard_productos vp
                ON vp.vcard_id = v.id
               AND vp.activo = 1
               AND vp.eliminado_en IS NULL
            INNER JOIN productos p
                ON p.id_producto = vp.id_producto
               AND p.activo = 1
               AND p.eliminado_en IS NULL
            INNER JOIN unidades_medida u
                ON u.id = p.unidad_medida_id
            LEFT JOIN marcas m
                ON m.id = p.marca_id
            LEFT JOIN lineas_producto l
                ON l.id = p.linea_producto_id
            LEFT JOIN clasificaciones_producto c
                ON c.id = p.clasificacion_producto_id
            WHERE v.slug = :slug
              AND v.publicada = 1
              AND v.despublicado_en IS NULL
            ORDER BY vp.orden ASC, p.descripcion ASC, p.id_producto ASC
            SQL
        );
        $statement->execute(['slug' => $slug]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
