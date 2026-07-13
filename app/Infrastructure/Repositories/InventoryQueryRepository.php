<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;
use PDO;

final class InventoryQueryRepository
{
    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    /**
     * @param array{
     *     company_id: int,
     *     warehouse_id: int,
     *     search: string,
     *     concept: string,
     *     status: string,
     *     date_from: string,
     *     date_to: string,
     *     page: int,
     *     per_page: int
     * } $filters
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     pagination: array{page: int, per_page: int, total: int, total_pages: int}
     * }
     */
    public function movements(array $filters): array
    {
        $conditions = [
            'm.empresa_id = :empresa_id',
            'm.almacen_id = :almacen_id',
        ];
        $parameters = [
            'empresa_id' => $filters['company_id'],
            'almacen_id' => $filters['warehouse_id'],
        ];

        if ($filters['search'] !== '') {
            $conditions[] = '(m.referencia LIKE :search OR CAST(m.id AS CHAR) LIKE :search_id)';
            $parameters['search'] = '%' . $filters['search'] . '%';
            $parameters['search_id'] = '%' . $filters['search'] . '%';
        }
        if ($filters['concept'] !== '') {
            $conditions[] = 'c.codigo = :concepto';
            $parameters['concepto'] = $filters['concept'];
        }
        if ($filters['status'] !== '') {
            $conditions[] = 'm.estado = :estado';
            $parameters['estado'] = $filters['status'];
        }
        if ($filters['date_from'] !== '') {
            $conditions[] = 'm.fecha_movimiento >= :fecha_desde';
            $parameters['fecha_desde'] = $filters['date_from'] . ' 00:00:00';
        }
        if ($filters['date_to'] !== '') {
            $conditions[] = 'm.fecha_movimiento <= :fecha_hasta';
            $parameters['fecha_hasta'] = $filters['date_to'] . ' 23:59:59';
        }

        $where = implode(' AND ', $conditions);
        $count = $this->connection->pdo()->prepare(
            'SELECT COUNT(*)
             FROM movimientos_inventario m
             INNER JOIN conceptos_movimiento_inventario c
                ON c.id = m.concepto_movimiento_id
             WHERE ' . $where
        );
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $page = max(1, $filters['page']);
        $perPage = max(1, min(50, $filters['per_page']));
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $statement = $this->connection->pdo()->prepare(
            'SELECT
                m.id,
                m.fecha_movimiento,
                m.estado,
                m.referencia,
                c.codigo AS concepto_codigo,
                c.nombre AS concepto_nombre,
                c.naturaleza,
                a.nombre AS almacen_nombre,
                COUNT(d.id) AS partidas
             FROM movimientos_inventario m
             INNER JOIN conceptos_movimiento_inventario c
                ON c.id = m.concepto_movimiento_id
             INNER JOIN almacenes a ON a.id = m.almacen_id
             LEFT JOIN movimientos_inventario_detalle d
                ON d.movimiento_id = m.id
             WHERE ' . $where . '
             GROUP BY
                m.id,
                m.fecha_movimiento,
                m.estado,
                m.referencia,
                c.codigo,
                c.nombre,
                c.naturaleza,
                a.nombre
             ORDER BY m.fecha_movimiento DESC, m.id DESC
             LIMIT :limit OFFSET :offset'
        );
        foreach ($parameters as $key => $value) {
            $statement->bindValue(':' . $key, $value);
        }
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return [
            'rows' => $statement->fetchAll(),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function movement(int $movementId, int $companyId, int $warehouseId): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT
                m.id,
                m.empresa_id,
                m.almacen_id,
                m.fecha_movimiento,
                m.estado,
                m.referencia,
                m.observaciones,
                m.creado_en,
                m.aplicado_en,
                c.codigo AS concepto_codigo,
                c.nombre AS concepto_nombre,
                c.naturaleza,
                e.nombre AS empresa_nombre,
                a.nombre AS almacen_nombre,
                uc.username AS creado_por_username,
                uc.email AS creado_por_email,
                ua.username AS aplicado_por_username,
                ua.email AS aplicado_por_email
             FROM movimientos_inventario m
             INNER JOIN conceptos_movimiento_inventario c
                ON c.id = m.concepto_movimiento_id
             INNER JOIN empresas e ON e.id = m.empresa_id
             INNER JOIN almacenes a ON a.id = m.almacen_id
             LEFT JOIN usuarios uc ON uc.id = m.creado_por
             LEFT JOIN usuarios ua ON ua.id = m.aplicado_por
             WHERE m.id = :id
               AND m.empresa_id = :empresa_id
               AND m.almacen_id = :almacen_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $movementId,
            'empresa_id' => $companyId,
            'almacen_id' => $warehouseId,
        ]);
        $movement = $statement->fetch();

        if ($movement === false) {
            return null;
        }

        $movement['partidas'] = $this->movementDetails($movementId);

        return $movement;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchProducts(string $query): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT
                p.id_producto,
                p.descripcion,
                tp.codigo AS tipo_codigo
             FROM productos p
             INNER JOIN tipos_producto tp ON tp.id = p.tipo_producto_id
             WHERE p.activo = 1
               AND p.eliminado_en IS NULL
               AND tp.activo = 1
               AND tp.eliminado_en IS NULL
               AND tp.codigo IN (\'PRODUCTO\', \'KIT\')
               AND (
                    p.id_producto LIKE :q_id
                    OR p.descripcion LIKE :q_desc
               )
             ORDER BY p.id_producto
             LIMIT 20'
        );
        $term = '%' . $query . '%';
        $statement->execute([
            'q_id' => $term,
            'q_desc' => $term,
        ]);

        return $statement->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function movementDetails(int $movementId): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT
                d.id_producto,
                p.descripcion,
                d.cantidad,
                d.observaciones
             FROM movimientos_inventario_detalle d
             INNER JOIN productos p ON p.id_producto = d.id_producto
             WHERE d.movimiento_id = :movimiento_id
             ORDER BY d.id_producto'
        );
        $statement->execute(['movimiento_id' => $movementId]);

        return $statement->fetchAll();
    }
}
