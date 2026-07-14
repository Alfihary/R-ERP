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
     * @param array{
     *     company_id: int,
     *     warehouse_id: int,
     *     search: string,
     *     warehouse_filter: int|null,
     *     type: string,
     *     balance_state: string,
     *     page: int,
     *     per_page: int
     * } $filters
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     pagination: array{page: int, per_page: int, total: int, total_pages: int}
     * }
     */
    public function stock(array $filters): array
    {
        $warehouseId = $filters['warehouse_filter'] ?? $filters['warehouse_id'];
        $conditions = [
            'a.empresa_id = :empresa_id',
            'e.almacen_id = :almacen_id',
            'a.activo = 1',
            'a.eliminado_en IS NULL',
            'em.activo = 1',
            'em.eliminado_en IS NULL',
            'p.activo = 1',
            'p.eliminado_en IS NULL',
            'tp.activo = 1',
            'tp.eliminado_en IS NULL',
        ];
        $parameters = [
            'empresa_id' => $filters['company_id'],
            'almacen_id' => $warehouseId,
        ];

        if ($filters['search'] !== '') {
            $conditions[] = '(e.id_producto LIKE :search_id OR p.descripcion LIKE :search_description)';
            $parameters['search_id'] = '%' . $filters['search'] . '%';
            $parameters['search_description'] = '%' . $filters['search'] . '%';
        }

        if ($filters['type'] !== '') {
            $conditions[] = 'tp.codigo = :tipo_producto';
            $parameters['tipo_producto'] = $filters['type'];
        }

        if ($filters['balance_state'] === 'positive') {
            $conditions[] = 'e.cantidad_actual > 0';
        } elseif ($filters['balance_state'] === 'zero') {
            $conditions[] = 'e.cantidad_actual = 0';
        } elseif ($filters['balance_state'] === 'negative') {
            $conditions[] = 'e.cantidad_actual < 0';
        }

        $where = implode(' AND ', $conditions);
        $count = $this->connection->pdo()->prepare(
            'SELECT COUNT(*)
             FROM existencias_producto e
             INNER JOIN almacenes a ON a.id = e.almacen_id
             INNER JOIN empresas em ON em.id = a.empresa_id
             INNER JOIN productos p ON p.id_producto = e.id_producto
             INNER JOIN tipos_producto tp ON tp.id = p.tipo_producto_id
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
                e.id,
                e.almacen_id,
                e.id_producto,
                e.cantidad_actual,
                e.actualizado_en,
                a.nombre AS almacen_nombre,
                em.nombre AS empresa_nombre,
                p.descripcion,
                tp.codigo AS tipo_codigo,
                tp.nombre AS tipo_nombre
             FROM existencias_producto e
             INNER JOIN almacenes a ON a.id = e.almacen_id
             INNER JOIN empresas em ON em.id = a.empresa_id
             INNER JOIN productos p ON p.id_producto = e.id_producto
             INNER JOIN tipos_producto tp ON tp.id = p.tipo_producto_id
             WHERE ' . $where . '
             ORDER BY a.nombre ASC, e.id_producto ASC
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
     * @return list<array{id: int, nombre: string}>
     */
    public function warehousesForCompany(int $companyId): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT id, nombre
             FROM almacenes
             WHERE empresa_id = :empresa_id
               AND activo = 1
               AND eliminado_en IS NULL
             ORDER BY nombre ASC'
        );
        $statement->execute(['empresa_id' => $companyId]);

        return $statement->fetchAll();
    }

    /**
     * @return list<array{codigo: string, nombre: string}>
     */
    public function productTypes(): array
    {
        $statement = $this->connection->pdo()->query(
            'SELECT codigo, nombre
             FROM tipos_producto
             WHERE activo = 1
               AND eliminado_en IS NULL
             ORDER BY nombre ASC'
        );

        return $statement->fetchAll();
    }

    /**
     * @return array{positive: int, zero: int, negative: int, total: int}
     */
    public function stockSummary(int $companyId, int $warehouseId): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT
                SUM(CASE WHEN e.cantidad_actual > 0 THEN 1 ELSE 0 END) AS positive,
                SUM(CASE WHEN e.cantidad_actual = 0 THEN 1 ELSE 0 END) AS zero,
                SUM(CASE WHEN e.cantidad_actual < 0 THEN 1 ELSE 0 END) AS negative,
                COUNT(*) AS total
             FROM existencias_producto e
             INNER JOIN almacenes a ON a.id = e.almacen_id
             INNER JOIN empresas em ON em.id = a.empresa_id
             INNER JOIN productos p ON p.id_producto = e.id_producto
             WHERE a.empresa_id = :empresa_id
               AND e.almacen_id = :almacen_id
               AND a.activo = 1
               AND a.eliminado_en IS NULL
               AND em.activo = 1
               AND em.eliminado_en IS NULL
               AND p.activo = 1
               AND p.eliminado_en IS NULL'
        );
        $statement->execute([
            'empresa_id' => $companyId,
            'almacen_id' => $warehouseId,
        ]);
        $row = $statement->fetch() ?: [];

        return [
            'positive' => (int) ($row['positive'] ?? 0),
            'zero' => (int) ($row['zero'] ?? 0),
            'negative' => (int) ($row['negative'] ?? 0),
            'total' => (int) ($row['total'] ?? 0),
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
