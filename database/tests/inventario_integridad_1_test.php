<?php

declare(strict_types=1);

use App\Infrastructure\Database\DatabaseTest;

return new class implements DatabaseTest {
    /** @return array<string, mixed> */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($database !== $expectedDatabase || $expectedDatabase !== 'r_erp_db_core_0_test') {
            throw new RuntimeException('Inventory integrity test database mismatch.');
        }

        $tables = [
            'productos', 'producto_precios', 'empresas', 'almacenes',
            'movimientos_inventario', 'movimientos_inventario_detalle',
            'existencias_producto', 'producto_series', 'existencias_serie',
            'movimiento_detalle_series', 'series_documentales', 'documentos_folios',
            'auditoria_eventos', 'usuario_empresas', 'usuario_almacenes',
        ];
        $tableEvidence = [];
        foreach ($tables as $table) {
            $statement = $pdo->prepare(
                'SELECT ENGINE, TABLE_COLLATION
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = :table'
            );
            $statement->execute(['table' => $table]);
            $metadata = $statement->fetch(PDO::FETCH_ASSOC);
            $tableEvidence[$table] = $metadata === false
                ? ['exists' => false, 'rows' => 0]
                : [
                    'exists' => true,
                    'rows' => (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn(),
                    'engine' => (string) $metadata['ENGINE'],
                    'collation' => (string) $metadata['TABLE_COLLATION'],
                ];
        }

        $protected = $pdo->prepare(
            'SELECT p.id_producto, p.descripcion, p.activo,
                    COUNT(DISTINCT pp.id) AS prices,
                    COALESCE(SUM(e.cantidad_actual), 0) AS stock
             FROM productos p
             LEFT JOIN producto_precios pp ON pp.id_producto = p.id_producto
             LEFT JOIN existencias_producto e ON e.id_producto = p.id_producto
             WHERE p.id_producto = :id
             GROUP BY p.id_producto, p.descripcion, p.activo'
        );
        $protected->execute(['id' => '102016169']);
        $protectedProduct = $protected->fetch(PDO::FETCH_ASSOC);

        $balanceMismatches = (int) $pdo->query(
            "SELECT COUNT(*) FROM (
                SELECT e.almacen_id, e.id_producto,
                       CAST(e.cantidad_actual AS DECIMAL(18,6)) AS materialized,
                       CAST(COALESCE(SUM(CASE WHEN c.naturaleza = 'ENTRADA' THEN d.cantidad ELSE -d.cantidad END), 0) AS DECIMAL(18,6)) AS derived
                FROM existencias_producto e
                LEFT JOIN movimientos_inventario_detalle d
                    ON d.id_producto = e.id_producto
                LEFT JOIN movimientos_inventario m
                    ON m.id = d.movimiento_id AND m.almacen_id = e.almacen_id AND m.estado = 'APLICADO'
                LEFT JOIN conceptos_movimiento_inventario c ON c.id = m.concepto_movimiento_id
                GROUP BY e.almacen_id, e.id_producto, e.cantidad_actual
                HAVING materialized <> derived
            ) mismatches"
        )->fetchColumn();

        $serialMultiWarehouse = (int) $pdo->query(
            "SELECT COUNT(*) FROM (
                SELECT serie_id
                FROM existencias_serie
                WHERE estado = 'EN_EXISTENCIA' AND almacen_id IS NOT NULL
                GROUP BY serie_id
                HAVING COUNT(DISTINCT almacen_id) > 1
            ) duplicate_locations"
        )->fetchColumn();
        $serialStockMismatches = (int) $pdo->query(
            "SELECT COUNT(*) FROM (
                SELECT ps.id_producto, es.almacen_id,
                       COALESCE(ep.cantidad_actual, 0) AS stock,
                       COUNT(es.serie_id) AS serials
                FROM producto_series ps
                INNER JOIN productos p ON p.id_producto = ps.id_producto AND p.controla_series = 1
                LEFT JOIN existencias_serie es ON es.serie_id = ps.id AND es.estado = 'EN_EXISTENCIA'
                LEFT JOIN existencias_producto ep ON ep.id_producto = ps.id_producto AND ep.almacen_id = es.almacen_id
                LEFT JOIN almacenes e ON e.id = es.almacen_id
GROUP BY ps.id_producto, es.almacen_id, ep.cantidad_actual
                HAVING stock <> serials
            ) serial_mismatches"
        )->fetchColumn();

        return [
            'database' => $database,
            'tables' => $tableEvidence,
            'protected_product' => $protectedProduct === false ? null : [
                'id_producto' => (string) $protectedProduct['id_producto'],
                'descripcion' => (string) $protectedProduct['descripcion'],
                'activo' => (int) $protectedProduct['activo'],
                'prices' => (int) $protectedProduct['prices'],
                'stock' => (string) $protectedProduct['stock'],
            ],
            'stock_model' => 'MATERIALIZED_BALANCE',
            'stock_balance_mismatch_count' => $balanceMismatches,
            'serial_multi_warehouse_count' => $serialMultiWarehouse,
            'serial_stock_mismatch_count' => $serialStockMismatches,
            'orphan_findings' => [
                'movement_details_without_header' => (int) $pdo->query(
                    'SELECT COUNT(*) FROM movimientos_inventario_detalle d LEFT JOIN movimientos_inventario m ON m.id = d.movimiento_id WHERE m.id IS NULL'
                )->fetchColumn(),
                'series_without_product' => (int) $pdo->query(
                    'SELECT COUNT(*) FROM producto_series s LEFT JOIN productos p ON p.id_producto = s.id_producto WHERE p.id_producto IS NULL'
                )->fetchColumn(),
                'folios_without_warehouse' => (int) $pdo->query(
                    'SELECT COUNT(*) FROM documentos_folios f LEFT JOIN almacenes a ON a.id = f.almacen_id WHERE a.id IS NULL'
                )->fetchColumn(),
            ],
            'duplicate_findings' => [
                'folios_by_scope' => (int) $pdo->query(
                    'SELECT COUNT(*) FROM (SELECT empresa_id, almacen_id, tipo_documento, codigo_serie, numero, anio FROM documentos_folios GROUP BY empresa_id, almacen_id, tipo_documento, codigo_serie, numero, anio HAVING COUNT(*) > 1) d'
                )->fetchColumn(),
                'series_by_product_number' => (int) $pdo->query(
                    'SELECT COUNT(*) FROM (SELECT id_producto, numero_serie FROM producto_series GROUP BY id_producto, numero_serie HAVING COUNT(*) > 1) d'
                )->fetchColumn(),
            ],
            'audit_event_coverage' => $pdo->query(
                "SELECT entidad, COUNT(*) AS events FROM auditoria_eventos WHERE entidad IN ('movimientos_inventario','transferencias','producto_series','documentos_folios') GROUP BY entidad ORDER BY entidad"
            )->fetchAll(PDO::FETCH_KEY_PAIR),
            'persistent_writes' => 0,
            'concurrency_test' => 'NOT_EXECUTED',
            'cleanup' => 'SELECT_ONLY',
        ];
    }
};
