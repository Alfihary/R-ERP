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
     * @param array{
     *     company_id: int,
     *     warehouse_id: int,
     *     search: string,
     *     product_id: string,
     *     status: string,
     *     warehouse_filter: int|null,
     *     page: int,
     *     per_page: int
     * } $filters
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     pagination: array{page: int, per_page: int, total: int, total_pages: int}
     * }
     */
    public function serialStock(array $filters): array
    {
        $conditions = $this->serialStockConditions($filters);
        $where = implode(' AND ', $conditions['where']);
        $parameters = $conditions['parameters'];

        $count = $this->connection->pdo()->prepare(
            'SELECT COUNT(*)
             FROM producto_series ps
             INNER JOIN productos p ON p.id_producto = ps.id_producto
             INNER JOIN existencias_serie es ON es.serie_id = ps.id
             LEFT JOIN almacenes a ON a.id = es.almacen_id
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
                ps.id,
                ps.id_producto,
                ps.numero_serie,
                ps.activo,
                ps.actualizado_en,
                p.descripcion,
                es.estado,
                es.almacen_id,
                es.actualizado_en AS existencia_actualizada_en,
                a.nombre AS almacen_nombre
             FROM producto_series ps
             INNER JOIN productos p ON p.id_producto = ps.id_producto
             INNER JOIN existencias_serie es ON es.serie_id = ps.id
             LEFT JOIN almacenes a ON a.id = es.almacen_id
             WHERE ' . $where . '
             ORDER BY p.id_producto ASC, ps.numero_serie ASC
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
     *     product_id: string,
     *     concept: string,
     *     nature: string,
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
    public function kardex(array $filters): array
    {
        $conditions = $this->kardexConditions($filters);
        $where = implode(' AND ', $conditions['where']);
        $parameters = $conditions['parameters'];

        $count = $this->connection->pdo()->prepare(
            'SELECT COUNT(*)
             FROM movimientos_inventario m
             INNER JOIN movimientos_inventario_detalle d
                ON d.movimiento_id = m.id
             INNER JOIN conceptos_movimiento_inventario c
                ON c.id = m.concepto_movimiento_id
             INNER JOIN productos p
                ON p.id_producto = d.id_producto
             INNER JOIN tipos_producto tp
                ON tp.id = p.tipo_producto_id
             INNER JOIN almacenes a
                ON a.id = m.almacen_id
             INNER JOIN empresas e
                ON e.id = m.empresa_id
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
                movimiento_id,
                fecha_movimiento,
                estado,
                referencia,
                concepto_codigo,
                concepto_nombre,
                naturaleza,
                id_producto,
                producto_descripcion,
                tipo_codigo,
                almacen_nombre,
                empresa_nombre,
                entrada,
                salida,
                saldo_resultante
             FROM (
                SELECT
                    m.id AS movimiento_id,
                    m.fecha_movimiento,
                    m.estado,
                    m.referencia,
                    c.codigo AS concepto_codigo,
                    c.nombre AS concepto_nombre,
                    c.naturaleza,
                    d.id_producto,
                    p.descripcion AS producto_descripcion,
                    tp.codigo AS tipo_codigo,
                    a.nombre AS almacen_nombre,
                    e.nombre AS empresa_nombre,
                    CASE WHEN c.naturaleza = \'ENTRADA\'
                        THEN CAST(d.cantidad AS CHAR)
                        ELSE NULL
                    END AS entrada,
                    CASE WHEN c.naturaleza = \'SALIDA\'
                        THEN CAST(d.cantidad AS CHAR)
                        ELSE NULL
                    END AS salida,
                    CAST(
                        SUM(
                            CASE WHEN c.naturaleza = \'ENTRADA\'
                                THEN d.cantidad
                                ELSE -d.cantidad
                            END
                        ) OVER (
                            ORDER BY m.fecha_movimiento ASC, m.id ASC
                            ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                        )
                        AS CHAR
                    ) AS saldo_resultante
                 FROM movimientos_inventario m
                 INNER JOIN movimientos_inventario_detalle d
                    ON d.movimiento_id = m.id
                 INNER JOIN conceptos_movimiento_inventario c
                    ON c.id = m.concepto_movimiento_id
                 INNER JOIN productos p
                    ON p.id_producto = d.id_producto
                 INNER JOIN tipos_producto tp
                    ON tp.id = p.tipo_producto_id
                 INNER JOIN almacenes a
                    ON a.id = m.almacen_id
                 INNER JOIN empresas e
                    ON e.id = m.empresa_id
                 WHERE ' . $where . '
             ) ordered_kardex
             ORDER BY fecha_movimiento ASC, movimiento_id ASC
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
    public function kardexProduct(string $productId): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT
                p.id_producto,
                p.descripcion,
                tp.codigo AS tipo_codigo,
                tp.nombre AS tipo_nombre
             FROM productos p
             INNER JOIN tipos_producto tp
                ON tp.id = p.tipo_producto_id
             WHERE p.id_producto = :id_producto
               AND p.activo = 1
               AND p.eliminado_en IS NULL
               AND tp.activo = 1
               AND tp.eliminado_en IS NULL
             LIMIT 1'
        );
        $statement->execute(['id_producto' => $productId]);
        $product = $statement->fetch();

        return $product === false ? null : $product;
    }

    public function kardexCurrentStock(int $warehouseId, string $productId): string
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT CAST(cantidad_actual AS CHAR)
             FROM existencias_producto
             WHERE almacen_id = :almacen_id
               AND id_producto = :id_producto
             LIMIT 1'
        );
        $statement->execute([
            'almacen_id' => $warehouseId,
            'id_producto' => $productId,
        ]);
        $stock = $statement->fetchColumn();

        return is_string($stock) ? $stock : '0.000000';
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
     * @param array{
     *     company_id: int,
     *     search: string,
     *     warehouse_id: int|null,
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
    public function transfers(array $filters): array
    {
        $conditions = $this->transferConditions($filters);
        $where = implode(' AND ', $conditions['where']);
        $parameters = $conditions['parameters'];
        $count = $this->connection->pdo()->prepare(
            'SELECT COUNT(*)
             FROM (
                SELECT s.referencia
                FROM movimientos_inventario s
                INNER JOIN conceptos_movimiento_inventario cs
                   ON cs.id = s.concepto_movimiento_id
                  AND cs.codigo = \'TRANSFERENCIA_SALIDA\'
                INNER JOIN movimientos_inventario e
                   ON e.referencia = s.referencia
                  AND e.empresa_id = s.empresa_id
                  AND e.estado = \'APLICADO\'
                INNER JOIN conceptos_movimiento_inventario ce
                   ON ce.id = e.concepto_movimiento_id
                  AND ce.codigo = \'TRANSFERENCIA_ENTRADA\'
                INNER JOIN almacenes ao ON ao.id = s.almacen_id
                INNER JOIN almacenes ad ON ad.id = e.almacen_id
                WHERE ' . $where . '
                GROUP BY s.referencia
             ) transferencias'
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
                s.referencia,
                s.fecha_movimiento,
                s.id AS movimiento_salida_id,
                e.id AS movimiento_entrada_id,
                ao.nombre AS almacen_origen_nombre,
                ad.nombre AS almacen_destino_nombre,
                COUNT(d.id) AS partidas,
                \'APLICADA\' AS estado
             FROM movimientos_inventario s
             INNER JOIN conceptos_movimiento_inventario cs
                ON cs.id = s.concepto_movimiento_id
               AND cs.codigo = \'TRANSFERENCIA_SALIDA\'
             INNER JOIN movimientos_inventario e
                ON e.referencia = s.referencia
               AND e.empresa_id = s.empresa_id
               AND e.estado = \'APLICADO\'
             INNER JOIN conceptos_movimiento_inventario ce
                ON ce.id = e.concepto_movimiento_id
               AND ce.codigo = \'TRANSFERENCIA_ENTRADA\'
             INNER JOIN almacenes ao ON ao.id = s.almacen_id
             INNER JOIN almacenes ad ON ad.id = e.almacen_id
             LEFT JOIN movimientos_inventario_detalle d
                ON d.movimiento_id = s.id
             WHERE ' . $where . '
             GROUP BY
                s.referencia,
                s.fecha_movimiento,
                s.id,
                e.id,
                ao.nombre,
                ad.nombre
             ORDER BY s.fecha_movimiento DESC, s.id DESC
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
    public function transfer(string $reference, int $companyId): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT
                s.referencia,
                s.fecha_movimiento,
                s.estado AS estado_salida,
                e.estado AS estado_entrada,
                s.id AS movimiento_salida_id,
                e.id AS movimiento_entrada_id,
                s.observaciones,
                s.creado_en,
                s.aplicado_en,
                empresa.nombre AS empresa_nombre,
                ao.nombre AS almacen_origen_nombre,
                ad.nombre AS almacen_destino_nombre,
                uc.username AS creado_por_username,
                uc.email AS creado_por_email,
                ua.username AS aplicado_por_username,
                ua.email AS aplicado_por_email
             FROM movimientos_inventario s
             INNER JOIN conceptos_movimiento_inventario cs
                ON cs.id = s.concepto_movimiento_id
               AND cs.codigo = \'TRANSFERENCIA_SALIDA\'
             INNER JOIN movimientos_inventario e
                ON e.referencia = s.referencia
               AND e.empresa_id = s.empresa_id
               AND e.estado = \'APLICADO\'
             INNER JOIN conceptos_movimiento_inventario ce
                ON ce.id = e.concepto_movimiento_id
               AND ce.codigo = \'TRANSFERENCIA_ENTRADA\'
             INNER JOIN empresas empresa ON empresa.id = s.empresa_id
             INNER JOIN almacenes ao ON ao.id = s.almacen_id
             INNER JOIN almacenes ad ON ad.id = e.almacen_id
             LEFT JOIN usuarios uc ON uc.id = s.creado_por
             LEFT JOIN usuarios ua ON ua.id = s.aplicado_por
             WHERE s.empresa_id = :empresa_id
               AND s.referencia = :referencia
               AND s.estado = \'APLICADO\'
               AND s.referencia LIKE \'TRF-%\'
             LIMIT 1'
        );
        $statement->execute([
            'empresa_id' => $companyId,
            'referencia' => $reference,
        ]);
        $transfer = $statement->fetch();

        if ($transfer === false) {
            return null;
        }

        $transfer['estado'] = 'APLICADA';
        $transfer['partidas'] = $this->transferDetails(
            (int) $transfer['movimiento_salida_id'],
            (int) $transfer['movimiento_entrada_id']
        );

        return $transfer;
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
                p.controla_series,
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
    public function searchKardexProducts(string $query): array
    {
        return $this->searchProducts($query);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{
     *     where: list<string>,
     *     parameters: array<string, mixed>
     * }
     */
    private function kardexConditions(array $filters): array
    {
        $where = [
            'm.empresa_id = :empresa_id',
            'm.almacen_id = :almacen_id',
            'm.estado = \'APLICADO\'',
            'd.id_producto = :id_producto',
            'a.activo = 1',
            'a.eliminado_en IS NULL',
            'e.activo = 1',
            'e.eliminado_en IS NULL',
            'p.activo = 1',
            'p.eliminado_en IS NULL',
            'tp.activo = 1',
            'tp.eliminado_en IS NULL',
            'tp.codigo IN (\'PRODUCTO\', \'KIT\')',
        ];
        $parameters = [
            'empresa_id' => $filters['company_id'],
            'almacen_id' => $filters['warehouse_id'],
            'id_producto' => $filters['product_id'],
        ];

        if ($filters['concept'] !== '') {
            $where[] = 'c.codigo = :concepto';
            $parameters['concepto'] = $filters['concept'];
        }

        if ($filters['nature'] !== '') {
            $where[] = 'c.naturaleza = :naturaleza';
            $parameters['naturaleza'] = $filters['nature'];
        }

        if ($filters['date_from'] !== '') {
            $where[] = 'm.fecha_movimiento >= :fecha_desde';
            $parameters['fecha_desde'] = $filters['date_from'] . ' 00:00:00';
        }

        if ($filters['date_to'] !== '') {
            $where[] = 'm.fecha_movimiento <= :fecha_hasta';
            $parameters['fecha_hasta'] = $filters['date_to'] . ' 23:59:59';
        }

        return ['where' => $where, 'parameters' => $parameters];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{
     *     where: list<string>,
     *     parameters: array<string, mixed>
     * }
     */
    private function serialStockConditions(array $filters): array
    {
        $where = [
            'p.activo = 1',
            'p.eliminado_en IS NULL',
            'ps.eliminado_en IS NULL',
            'EXISTS (
                SELECT 1
                FROM movimiento_detalle_series mds
                INNER JOIN movimientos_inventario_detalle mid
                    ON mid.id = mds.movimiento_detalle_id
                INNER JOIN movimientos_inventario mi
                    ON mi.id = mid.movimiento_id
                WHERE mds.serie_id = ps.id
                  AND mi.empresa_id = :empresa_id
                  AND mi.estado = \'APLICADO\'
            )',
            '(
                (es.almacen_id IS NOT NULL
                    AND a.empresa_id = :almacen_empresa_id
                    AND a.activo = 1
                    AND a.eliminado_en IS NULL)
                OR es.almacen_id IS NULL
            )',
            '(
                es.estado = \'FUERA_EXISTENCIA\'
                OR es.almacen_id = :active_warehouse_id
            )',
        ];
        $parameters = [
            'empresa_id' => $filters['company_id'],
            'almacen_empresa_id' => $filters['company_id'],
            'active_warehouse_id' => $filters['warehouse_id'],
        ];

        if ($filters['search'] !== '') {
            $where[] = '(
                ps.numero_serie LIKE :q_serie
                OR ps.id_producto LIKE :q_producto
                OR p.descripcion LIKE :q_descripcion
            )';
            $parameters['q_serie'] = '%' . $filters['search'] . '%';
            $parameters['q_producto'] = '%' . $filters['search'] . '%';
            $parameters['q_descripcion'] = '%' . $filters['search'] . '%';
        }

        if ($filters['product_id'] !== '') {
            $where[] = 'ps.id_producto = :id_producto';
            $parameters['id_producto'] = $filters['product_id'];
        }

        if ($filters['status'] !== '') {
            $where[] = 'es.estado = :estado';
            $parameters['estado'] = $filters['status'];
        }

        if ($filters['warehouse_filter'] !== null) {
            $where[] = 'es.almacen_id = :warehouse_filter';
            $parameters['warehouse_filter'] = $filters['warehouse_filter'];
        }

        return ['where' => $where, 'parameters' => $parameters];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function movementDetails(int $movementId): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT
                d.id AS detalle_id,
                d.id_producto,
                p.descripcion,
                p.controla_series,
                d.cantidad,
                d.observaciones
             FROM movimientos_inventario_detalle d
             INNER JOIN productos p ON p.id_producto = d.id_producto
             WHERE d.movimiento_id = :movimiento_id
             ORDER BY d.id_producto'
        );
        $statement->execute(['movimiento_id' => $movementId]);

        $rows = $statement->fetchAll();
        $seriesByDetail = $this->seriesByDetailIds(array_map(
            static fn (array $row): int => (int) $row['detalle_id'],
            $rows
        ));

        foreach ($rows as &$row) {
            $row['series'] = $seriesByDetail[(int) $row['detalle_id']] ?? [];
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{
     *     where: list<string>,
     *     parameters: array<string, mixed>
     * }
     */
    private function transferConditions(array $filters): array
    {
        $where = [
            's.empresa_id = :empresa_id',
            's.estado = \'APLICADO\'',
            's.referencia LIKE \'TRF-%\'',
            'ao.activo = 1',
            'ao.eliminado_en IS NULL',
            'ad.activo = 1',
            'ad.eliminado_en IS NULL',
        ];
        $parameters = ['empresa_id' => $filters['company_id']];

        if ($filters['search'] !== '') {
            $where[] = 's.referencia LIKE :search';
            $parameters['search'] = '%' . $filters['search'] . '%';
        }
        if ($filters['warehouse_id'] !== null) {
            $where[] = '(s.almacen_id = :almacen_origen_id OR e.almacen_id = :almacen_destino_id)';
            $parameters['almacen_origen_id'] = $filters['warehouse_id'];
            $parameters['almacen_destino_id'] = $filters['warehouse_id'];
        }
        if ($filters['date_from'] !== '') {
            $where[] = 's.fecha_movimiento >= :fecha_desde';
            $parameters['fecha_desde'] = $filters['date_from'] . ' 00:00:00';
        }
        if ($filters['date_to'] !== '') {
            $where[] = 's.fecha_movimiento <= :fecha_hasta';
            $parameters['fecha_hasta'] = $filters['date_to'] . ' 23:59:59';
        }

        return ['where' => $where, 'parameters' => $parameters];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function transferDetails(int $exitMovementId, int $entryMovementId): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT
                d.id AS detalle_salida_id,
                d.id_producto,
                p.descripcion,
                p.controla_series,
                tp.codigo AS tipo_codigo,
                d.cantidad,
                d.observaciones
             FROM movimientos_inventario_detalle d
             INNER JOIN productos p ON p.id_producto = d.id_producto
             INNER JOIN tipos_producto tp ON tp.id = p.tipo_producto_id
             WHERE d.movimiento_id = :movimiento_id
             ORDER BY d.id_producto'
        );
        $statement->execute(['movimiento_id' => $exitMovementId]);

        $rows = $statement->fetchAll();
        $entryDetails = $this->entryTransferDetailsByProduct($entryMovementId);
        $seriesByDetail = $this->seriesByDetailIds(array_merge(
            array_map(
                static fn (array $row): int => (int) $row['detalle_salida_id'],
                $rows
            ),
            array_values($entryDetails)
        ));

        foreach ($rows as &$row) {
            $exitDetailId = (int) $row['detalle_salida_id'];
            $entryDetailId = $entryDetails[(string) $row['id_producto']] ?? 0;
            $row['detalle_entrada_id'] = $entryDetailId;
            $row['series_salida'] = $seriesByDetail[$exitDetailId] ?? [];
            $row['series_entrada'] = $entryDetailId > 0
                ? ($seriesByDetail[$entryDetailId] ?? [])
                : [];
        }
        unset($row);

        return $rows;
    }

    /**
     * @param list<int> $detailIds
     * @return array<int, list<string>>
     */
    private function seriesByDetailIds(array $detailIds): array
    {
        $detailIds = array_values(array_unique(array_filter(
            array_map('intval', $detailIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($detailIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($detailIds), '?'));
        $statement = $this->connection->pdo()->prepare(
            'SELECT
                mds.movimiento_detalle_id,
                ps.numero_serie
             FROM movimiento_detalle_series mds
             INNER JOIN producto_series ps ON ps.id = mds.serie_id
             WHERE mds.movimiento_detalle_id IN (' . $placeholders . ')
             ORDER BY mds.movimiento_detalle_id, ps.numero_serie'
        );
        $statement->execute($detailIds);
        $series = [];

        foreach ($statement->fetchAll() as $row) {
            $series[(int) $row['movimiento_detalle_id']][] =
                (string) $row['numero_serie'];
        }

        return $series;
    }

    /**
     * @return array<string, int>
     */
    private function entryTransferDetailsByProduct(int $entryMovementId): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT id, id_producto
             FROM movimientos_inventario_detalle
             WHERE movimiento_id = :movimiento_id'
        );
        $statement->execute(['movimiento_id' => $entryMovementId]);
        $details = [];

        foreach ($statement->fetchAll() as $row) {
            $details[(string) $row['id_producto']] = (int) $row['id'];
        }

        return $details;
    }
}
