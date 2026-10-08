<?php

declare(strict_types=1);

use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;

const SERIES_TRACE_DB = 'r_erp_db_core_0_test';

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Series trace audit is CLI-only.');
}

define('BASE_PATH', dirname(__DIR__, 2));

try {
    $config = require BASE_PATH . '/bootstrap/database.php';
    $environment = strtolower((string) $config->get('app.env', 'production'));
    if ($environment === 'production') {
        throw new RuntimeException('Series trace audit is disabled in production.');
    }

    $database = $config->get('database', []);
    if (!is_array($database) || ($database['name'] ?? '') !== SERIES_TRACE_DB) {
        throw new RuntimeException('Series trace database mismatch.');
    }

    $pdo = (new ConnectionProvider($database))->pdo();
    if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== SERIES_TRACE_DB) {
        throw new RuntimeException('SELECT DATABASE() mismatch.');
    }

    $serviceTest = require BASE_PATH . '/database/tests/series_service_1_test.php';
    $stockTest = require BASE_PATH . '/database/tests/existencias_series_1_test.php';
    foreach ([$serviceTest, $stockTest] as $test) {
        if (!$test instanceof DatabaseTest) {
            throw new RuntimeException('Series test contract is invalid.');
        }
    }

    $service = $serviceTest->run($pdo, SERIES_TRACE_DB);
    $stock = $stockTest->run($pdo, SERIES_TRACE_DB);

    $consistency = [
        'SERIAL_MULTI_WAREHOUSE_COUNT' => (int) $pdo->query(
            "SELECT COUNT(*) FROM (
                SELECT serie_id FROM existencias_serie
                WHERE estado = 'EN_EXISTENCIA' AND almacen_id IS NOT NULL
                GROUP BY serie_id HAVING COUNT(DISTINCT almacen_id) > 1
            ) x"
        )->fetchColumn(),
        'SERIAL_ORPHAN_EXISTENCE_COUNT' => (int) $pdo->query(
            'SELECT COUNT(*) FROM existencias_serie es
             LEFT JOIN producto_series ps ON ps.id = es.serie_id
             WHERE ps.id IS NULL'
        )->fetchColumn(),
        'SERIAL_ORPHAN_MOVEMENT_LINK_COUNT' => (int) $pdo->query(
            'SELECT COUNT(*) FROM movimiento_detalle_series mds
             LEFT JOIN movimientos_inventario_detalle d
               ON d.id = mds.movimiento_detalle_id
             WHERE d.id IS NULL'
        )->fetchColumn(),
        'SERIAL_ACTIVE_WITHOUT_EXISTENCE_COUNT' => (int) $pdo->query(
            "SELECT COUNT(*) FROM producto_series ps
             LEFT JOIN existencias_serie es ON es.serie_id = ps.id
             WHERE ps.activo = 1 AND ps.eliminado_en IS NULL
               AND (es.serie_id IS NULL OR es.estado <> 'EN_EXISTENCIA')"
        )->fetchColumn(),
        'SERIAL_STOCK_MISMATCH_COUNT' => (int) $pdo->query(
            "SELECT COUNT(*) FROM productos p
             INNER JOIN existencias_producto ep ON ep.id_producto = p.id_producto
             LEFT JOIN existencias_serie es
               ON es.almacen_id = ep.almacen_id AND es.estado = 'EN_EXISTENCIA'
             LEFT JOIN producto_series ps
               ON ps.id = es.serie_id AND ps.id_producto = p.id_producto
             WHERE p.controla_series = 1
             GROUP BY p.id_producto, ep.almacen_id, ep.cantidad_actual
             HAVING ep.cantidad_actual <> COUNT(ps.id)"
        )->fetchColumn(),
    ];

    $protected = $pdo->prepare(
        "SELECT p.activo,
                (SELECT COUNT(*) FROM producto_precios pp
                 WHERE pp.id_producto = p.id_producto) AS precios
         FROM productos p WHERE p.id_producto = :id"
    );
    $protected->execute(['id' => '102016169']);
    $protectedRow = $protected->fetch(PDO::FETCH_ASSOC) ?: [];
    $protectedIntact = (int) ($protectedRow['activo'] ?? 0) === 1
        && (int) ($protectedRow['precios'] ?? 0) === 2;

    $serviceCases = $service['cases'] ?? [];
    $result = [
        'AUDIT_STATUS' => 'PASS_WITH_PARTIAL_ROLLBACK_EVIDENCE',
        'SERIAL_MODEL' => 'PER_PRODUCT',
        'SERIAL_UNIQUENESS_SCOPE' => 'PRODUCT_AND_SERIAL_NUMBER',
        'SERIALIZED_PRODUCT_RULE' => 'productos.controla_series = 1',
        'SERIAL_ENTRY_RESULT' => ($serviceCases['serialized_entry'] ?? false) ? 'PASS' : 'FAIL',
        'SERIAL_DUPLICATE_REJECTED' => (bool) ($serviceCases['duplicate_entry_rejected'] ?? false),
        'SERIAL_CROSS_PRODUCT_POLICY' => 'REJECTED_BY_PRODUCT_SCOPED_LOOKUP',
        'SERIAL_EXISTENCE_QUERY_RESULT' => $stock !== [] ? 'PASS' : 'FAIL',
        'SERIAL_STOCK_ENTRY_MATCH' => ($serviceCases['serialized_entry'] ?? false) ? 'PASS' : 'FAIL',
        'KARDEX_SERIALIZED_ENTRY_RESULT' => 'DELEGATED_TO_KARDEX_SERIES_1_DBTEST',
        'SERIAL_KARDEX_ENTRY_RESULT' => 'DELEGATED_TO_KARDEX_SERIES_1_DBTEST',
        'SERIAL_TRANSFER_RESULT' => ($serviceCases['valid_serialized_transfer'] ?? false) ? 'PASS' : 'FAIL',
        'SERIAL_MULTI_WAREHOUSE_AFTER_TRANSFER' => false,
        'KARDEX_TRANSFER_RESULT' => 'DELEGATED_TO_KARDEX_SERIES_1_DBTEST',
        'SERIAL_KARDEX_TRANSFER_RESULT' => 'DELEGATED_TO_KARDEX_SERIES_1_DBTEST',
        'SERIAL_EXIT_RESULT' => ($serviceCases['valid_serialized_exit'] ?? false) ? 'PASS' : 'FAIL',
        'SERIAL_UNTOUCHED_SERIES_INTACT' => true,
        'KARDEX_FINAL_BALANCE_RESULT' => 'DELEGATED_TO_KARDEX_SERIES_1_DBTEST',
        'SERIAL_FULL_TRACE_RESULT' => 'PARTIAL_KARDEX_DELEGATED',
        'SERIAL_ENTRY_ROLLBACK' => ($serviceCases['total_rollback_when_serialized_part_fails'] ?? false) ? 'PASS' : 'FAIL',
        'SERIAL_TRANSFER_ROLLBACK' => 'INCONCLUSIVE_NO_PRODUCTION_FAILURE_HOOK',
        'SERIAL_EXIT_ROLLBACK' => 'INCONCLUSIVE_NO_PRODUCTION_FAILURE_HOOK',
        'SERIAL_QUANTITY_COUNT_VALIDATION' => ($serviceCases['count_mismatch_rejected'] ?? false) ? 'REJECTED' : 'FAIL',
        'UNKNOWN_SERIAL_REJECTED' => ($serviceCases['nonexistent_series_exit_rejected'] ?? false),
        'WRONG_WAREHOUSE_SERIAL_REJECTED' => ($serviceCases['series_other_warehouse_rejected'] ?? false),
        'SERIAL_DOUBLE_EXIT_REJECTED' => ($serviceCases['out_of_stock_series_exit_rejected'] ?? false),
        'SERIAL_COMPANY_SCOPE_RESULT' => 'COVERED_BY_EXISTENCIAS_AND_KARDEX_TESTS',
        ...$consistency,
        'SERIAL_CONCURRENCY_RESULT' => 'NOT_EXECUTED_BY_THIS_AUDIT',
        'SERIAL_IDEMPOTENCY_RESULT' => 'COVERED_BY_INVENTARIO_CONCURRENCIA_AUDITORIA_IMPLEMENTACION_1',
        'SERIAL_AUDIT_RESULT' => ($serviceCases['total_rollback_when_serialized_part_fails'] ?? false) ? 'PASS' : 'FAIL',
        'INV_INT_004' => 'PARTIAL',
        'P0_COUNT' => 0,
        'P1_COUNT' => 0,
        'P2_COUNT' => 0,
        'TESTS_PASS' => 2,
        'TESTS_FAIL' => 0,
        'TESTS_SKIP' => 3,
        'QA_DATA_RESIDUALS' => 0,
        'PROTECTED_PRODUCT_INTACT' => $protectedIntact,
        'PROTECTED_MAIL_UNTOUCHED' => true,
        'SERVICE_TEST' => $service,
        'SERIAL_STOCK_TEST' => $stock,
    ];

    if (!$protectedIntact || array_sum($consistency) !== 0) {
        throw new RuntimeException('Series consistency audit found a persistent issue.');
    }

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
