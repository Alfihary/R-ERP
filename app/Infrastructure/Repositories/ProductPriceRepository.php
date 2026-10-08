<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;

final class ProductPriceRepository
{
    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    public function beginTransaction(): bool
    {
        $pdo = $this->connection->pdo();

        if ($pdo->inTransaction()) {
            return false;
        }

        $pdo->beginTransaction();

        return true;
    }

    public function commit(bool $ownsTransaction = true): void
    {
        if ($ownsTransaction) {
            $this->connection->pdo()->commit();
        }
    }

    public function rollBack(bool $ownsTransaction = true): void
    {
        if ($ownsTransaction && $this->connection->pdo()->inTransaction()) {
            $this->connection->pdo()->rollBack();
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT *
             FROM producto_precios
             WHERE id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findDetailById(int $id): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT pp.*, p.descripcion AS producto_descripcion,
                    p.sku AS producto_sku, p.upc AS producto_upc,
                    p.ean AS producto_ean, p.gtin AS producto_gtin,
                    lp.clave AS lista_clave, lp.nombre AS lista_nombre,
                    m.codigo AS moneda_codigo
             FROM producto_precios pp
             INNER JOIN productos p ON p.id_producto = pp.id_producto
             INNER JOIN listas_precios lp ON lp.id = pp.lista_precio_id
             INNER JOIN monedas m ON m.id = pp.moneda_id
             WHERE pp.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdForUpdate(int $id): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT *
             FROM producto_precios
             WHERE id = :id
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByProductAndList(
        string $idProducto,
        int $listaPrecioId
    ): ?array {
        $statement = $this->connection->pdo()->prepare(
            'SELECT *
             FROM producto_precios
             WHERE id_producto = :id_producto
               AND lista_precio_id = :lista_precio_id
             LIMIT 1'
        );
        $statement->execute([
            'id_producto' => $idProducto,
            'lista_precio_id' => $listaPrecioId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByProductAndListForUpdate(
        string $idProducto,
        int $listaPrecioId
    ): ?array {
        $statement = $this->connection->pdo()->prepare(
            'SELECT *
             FROM producto_precios
             WHERE id_producto = :id_producto
               AND lista_precio_id = :lista_precio_id
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute([
            'id_producto' => $idProducto,
            'lista_precio_id' => $listaPrecioId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAllByProduct(string $idProducto): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT *
             FROM producto_precios
             WHERE id_producto = :id_producto
             ORDER BY lista_precio_id, id'
        );
        $statement->execute(['id_producto' => $idProducto]);

        return $statement->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAllByProductForUpdate(string $idProducto): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT *
             FROM producto_precios
             WHERE id_producto = :id_producto
             ORDER BY id
             FOR UPDATE'
        );
        $statement->execute(['id_producto' => $idProducto]);

        return $statement->fetchAll();
    }

    /**
     * @param array<string, int|string|null> $data
     */
    public function insert(array $data): int
    {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO producto_precios (
                id_producto,
                lista_precio_id,
                moneda_id,
                precio_lista,
                precio_minimo,
                incluye_impuestos,
                requiere_revision,
                activo,
                creado_por,
                actualizado_por
             ) VALUES (
                :id_producto,
                :lista_precio_id,
                :moneda_id,
                :precio_lista,
                :precio_minimo,
                :incluye_impuestos,
                :requiere_revision,
                :activo,
                :creado_por,
                :actualizado_por
             )'
        );
        $statement->execute([
            'id_producto' => $data['id_producto'],
            'lista_precio_id' => $data['lista_precio_id'],
            'moneda_id' => $data['moneda_id'],
            'precio_lista' => $data['precio_lista'],
            'precio_minimo' => $data['precio_minimo'],
            'incluye_impuestos' => $data['incluye_impuestos'],
            'requiere_revision' => $data['requiere_revision'] ?? 0,
            'activo' => $data['activo'] ?? 1,
            'creado_por' => $data['creado_por'] ?? null,
            'actualizado_por' => $data['actualizado_por'] ?? null,
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    /**
     * @param array<string, int|string|null> $data
     */
    public function update(int $id, array $data): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE producto_precios
             SET moneda_id = :moneda_id,
                 precio_lista = :precio_lista,
                 precio_minimo = :precio_minimo,
                 incluye_impuestos = :incluye_impuestos,
                 requiere_revision = :requiere_revision,
                 activo = :activo,
                 actualizado_en = CURRENT_TIMESTAMP,
                 actualizado_por = :actualizado_por
             WHERE id = :id
               AND eliminado_en IS NULL'
        );
        $statement->execute([
            'id' => $id,
            'moneda_id' => $data['moneda_id'],
            'precio_lista' => $data['precio_lista'],
            'precio_minimo' => $data['precio_minimo'],
            'incluye_impuestos' => $data['incluye_impuestos'],
            'requiere_revision' => $data['requiere_revision'],
            'activo' => $data['activo'],
            'actualizado_por' => $data['actualizado_por'],
        ]);
    }

    public function softDelete(int $id, int $usuarioId): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE producto_precios
             SET activo = 0,
                 actualizado_en = CURRENT_TIMESTAMP,
                 actualizado_por = :actualizado_por,
                 eliminado_en = CURRENT_TIMESTAMP,
                 eliminado_por = :eliminado_por
             WHERE id = :id
               AND eliminado_en IS NULL'
        );
        $statement->execute([
            'id' => $id,
            'actualizado_por' => $usuarioId,
            'eliminado_por' => $usuarioId,
        ]);
    }

    public function reactivate(int $id, int $usuarioId): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE producto_precios
             SET activo = 1,
                 actualizado_en = CURRENT_TIMESTAMP,
                 actualizado_por = :actualizado_por
             WHERE id = :id
               AND eliminado_en IS NULL'
        );
        $statement->execute([
            'id' => $id,
            'actualizado_por' => $usuarioId,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activeProduct(string $idProducto): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT id_producto, descripcion, moneda_id, activo
             FROM productos
             WHERE id_producto = :id_producto
               AND activo = 1
               AND eliminado_en IS NULL
             LIMIT 1'
        );
        $statement->execute(['id_producto' => $idProducto]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function productForUpdate(string $idProducto): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT id_producto, descripcion, moneda_id, activo, eliminado_en
             FROM productos
             WHERE id_producto = :id_producto
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute(['id_producto' => $idProducto]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function activeCurrencyExists(int $currencyId): bool
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT COUNT(*)
             FROM monedas
             WHERE id = :id
               AND activo = 1
               AND eliminado_en IS NULL'
        );
        $statement->execute(['id' => $currencyId]);

        return (int) $statement->fetchColumn() === 1;
    }

    public function activeUserExists(int $userId): bool
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT COUNT(*)
             FROM usuarios
             WHERE id = :id
               AND activo = 1
               AND eliminado_en IS NULL'
        );
        $statement->execute(['id' => $userId]);

        return (int) $statement->fetchColumn() === 1;
    }

    /**
     * @param array{q: string, lista_precio_id: int|null, activo: string, requiere_revision: string, moneda_id: int|null} $filters
     * @return list<array<string, mixed>>
     */
    public function paginate(array $filters, int $page, int $perPage): array
    {
        $where = $this->priceWhere($filters);
        $statement = $this->connection->pdo()->prepare(
            'SELECT pp.*, p.descripcion AS producto_descripcion,
                    p.sku AS producto_sku, p.upc AS producto_upc,
                    p.ean AS producto_ean, p.gtin AS producto_gtin,
                    lp.clave AS lista_clave, lp.nombre AS lista_nombre,
                    m.codigo AS moneda_codigo
             FROM producto_precios pp
             INNER JOIN productos p ON p.id_producto = pp.id_producto
             INNER JOIN listas_precios lp ON lp.id = pp.lista_precio_id
             INNER JOIN monedas m ON m.id = pp.moneda_id
             ' . $where['sql'] . '
             ORDER BY pp.requiere_revision DESC, p.descripcion, lp.nombre, pp.id
             LIMIT :limit OFFSET :offset'
        );

        foreach ($where['params'] as $name => $value) {
            $statement->bindValue($name, $value);
        }

        $statement->bindValue('limit', $perPage, \PDO::PARAM_INT);
        $statement->bindValue('offset', ($page - 1) * $perPage, \PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * @param array{q: string, lista_precio_id: int|null, activo: string, requiere_revision: string, moneda_id: int|null} $filters
     */
    public function count(array $filters): int
    {
        $where = $this->priceWhere($filters);
        $statement = $this->connection->pdo()->prepare(
            'SELECT COUNT(*)
             FROM producto_precios pp
             INNER JOIN productos p ON p.id_producto = pp.id_producto
             INNER JOIN listas_precios lp ON lp.id = pp.lista_precio_id
             INNER JOIN monedas m ON m.id = pp.moneda_id
             ' . $where['sql']
        );
        $statement->execute($where['params']);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return list<array{id: int, codigo: string, nombre: string}>
     */
    public function activeCurrencies(): array
    {
        return $this->connection->pdo()->query(
            'SELECT id, codigo, nombre
             FROM monedas
             WHERE activo = 1
               AND eliminado_en IS NULL
             ORDER BY codigo'
        )->fetchAll();
    }

    public function updateProductCurrency(
        string $idProducto,
        int $currencyId,
        int $actorId
    ): void {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE productos
             SET moneda_id = :moneda_id,
                 actualizado_por = :actualizado_por
             WHERE id_producto = :id_producto
               AND eliminado_en IS NULL'
        );
        $statement->execute([
            'id_producto' => $idProducto,
            'moneda_id' => $currencyId,
            'actualizado_por' => $actorId,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function usablePrice(
        string $idProducto,
        int $listaPrecioId
    ): ?array {
        $statement = $this->connection->pdo()->prepare(
            'SELECT pp.*, lp.clave AS lista_clave, lp.nombre AS lista_nombre,
                    m.codigo AS moneda_codigo
             FROM producto_precios pp
             INNER JOIN listas_precios lp ON lp.id = pp.lista_precio_id
             INNER JOIN monedas m ON m.id = pp.moneda_id
             WHERE pp.id_producto = :id_producto
               AND pp.lista_precio_id = :lista_precio_id
               AND pp.activo = 1
               AND pp.eliminado_en IS NULL
               AND pp.requiere_revision = 0
               AND pp.precio_lista > 0
             LIMIT 1'
        );
        $statement->execute([
            'id_producto' => $idProducto,
            'lista_precio_id' => $listaPrecioId,
        ]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listProductPricesWithDetails(string $idProducto): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT pp.*, lp.clave AS lista_clave, lp.nombre AS lista_nombre,
                    m.codigo AS moneda_codigo
             FROM producto_precios pp
             INNER JOIN listas_precios lp ON lp.id = pp.lista_precio_id
             INNER JOIN monedas m ON m.id = pp.moneda_id
             WHERE pp.id_producto = :id_producto
             ORDER BY lp.nombre, pp.id'
        );
        $statement->execute(['id_producto' => $idProducto]);

        return $statement->fetchAll();
    }

    /**
     * @param array{q: string, lista_precio_id: int|null, activo: string, requiere_revision: string, moneda_id: int|null} $filters
     * @return array{sql: string, params: array<string, int|string>}
     */
    private function priceWhere(array $filters): array
    {
        $conditions = ['pp.eliminado_en IS NULL'];
        $params = [];

        if ($filters['q'] !== '') {
            $conditions[] = '(
                pp.id_producto LIKE :q_producto
                OR p.descripcion LIKE :q_descripcion
                OR p.sku LIKE :q_sku
                OR p.upc LIKE :q_upc
                OR p.ean LIKE :q_ean
                OR p.gtin LIKE :q_gtin
            )';
            $like = '%' . $filters['q'] . '%';
            $params['q_producto'] = $like;
            $params['q_descripcion'] = $like;
            $params['q_sku'] = $like;
            $params['q_upc'] = $like;
            $params['q_ean'] = $like;
            $params['q_gtin'] = $like;
        }

        if ($filters['lista_precio_id'] !== null) {
            $conditions[] = 'pp.lista_precio_id = :lista_precio_id';
            $params['lista_precio_id'] = $filters['lista_precio_id'];
        }

        if ($filters['moneda_id'] !== null) {
            $conditions[] = 'pp.moneda_id = :moneda_id';
            $params['moneda_id'] = $filters['moneda_id'];
        }

        if ($filters['activo'] === 'active') {
            $conditions[] = 'pp.activo = 1';
        } elseif ($filters['activo'] === 'inactive') {
            $conditions[] = 'pp.activo = 0';
        }

        if ($filters['requiere_revision'] === 'yes') {
            $conditions[] = 'pp.requiere_revision = 1';
        } elseif ($filters['requiere_revision'] === 'no') {
            $conditions[] = 'pp.requiere_revision = 0';
        }

        return [
            'sql' => 'WHERE ' . implode(' AND ', $conditions),
            'params' => $params,
        ];
    }
}
