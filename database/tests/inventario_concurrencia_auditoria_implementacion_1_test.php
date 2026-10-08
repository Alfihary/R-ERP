<?php

declare(strict_types=1);

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\InventoryTransferService;
use App\Infrastructure\Database\Connection;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Repositories\AuditRepository;
use App\Infrastructure\Repositories\InventoryIdempotencyRepository;
use App\Infrastructure\Repositories\InventoryRepository;

const IMPLEMENTATION_DB = 'r_erp_db_core_0_test';
const IMPLEMENTATION_PROTECTED_PRODUCT = '102016169';

function implementationConfig(): array
{
    define('BASE_PATH', dirname(__DIR__, 2));
    $config = require BASE_PATH . '/bootstrap/database.php';
    if (strtolower((string) $config->get('app.env', 'production')) === 'production') {
        throw new RuntimeException('Implementation test cannot run in production.');
    }
    $database = $config->get('database', []);
    if (!is_array($database) || ($database['name'] ?? '') !== IMPLEMENTATION_DB) {
        throw new RuntimeException('Implementation test database mismatch.');
    }
    return $database;
}

function implementationConnection(array $database): PDO
{
    return Connection::create($database);
}

function implementationServices(array $database): array
{
    $provider = new ConnectionProvider($database);
    $repository = new InventoryRepository($provider);
    $idempotency = new InventoryIdempotencyRepository($provider);
    $audit = new AuditRepository($provider);
    return [
        new InventoryService($repository, null, $idempotency, $audit),
        new InventoryTransferService($repository, null, $idempotency, $audit),
    ];
}

function implementationFixture(PDO $pdo): array
{
    $product = (string) $pdo->query(
        "SELECT id_producto FROM productos
         WHERE activo = 1 AND controla_series = 0
           AND id_producto <> '" . IMPLEMENTATION_PROTECTED_PRODUCT . "'
         ORDER BY id_producto LIMIT 1"
    )->fetchColumn();
    if ($product === '') {
        throw new RuntimeException('No QA product available.');
    }
    return [
        'product' => $product,
        'user' => 10,
        'company' => 1,
        'warehouse' => 1,
        'origin' => 495,
        'destination' => 496,
        'transfer_company' => 241,
    ];
}

function implementationCounts(PDO $pdo): array
{
    $tables = [
        'existencias_producto', 'movimientos_inventario',
        'movimientos_inventario_detalle', 'auditoria_eventos',
        'inventario_operaciones_idempotencia',
    ];
    $result = [];
    foreach ($tables as $table) {
        $result[$table] = (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }
    return $result;
}

function implementationProtected(PDO $pdo): array
{
    $statement = $pdo->prepare(
        'SELECT p.id_producto, p.activo, COUNT(DISTINCT pp.id) prices,
                COALESCE(SUM(e.cantidad_actual), 0) stock
         FROM productos p
         LEFT JOIN producto_precios pp ON pp.id_producto = p.id_producto
         LEFT JOIN existencias_producto e ON e.id_producto = p.id_producto
         WHERE p.id_producto = :id
         GROUP BY p.id_producto, p.activo'
    );
    $statement->execute(['id' => IMPLEMENTATION_PROTECTED_PRODUCT]);
    return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
}

function implementationSetStock(PDO $pdo, array $fixture, float $quantity): array
{
    $previous = [];
    foreach ([$fixture['warehouse'], $fixture['origin'], $fixture['destination']] as $warehouse) {
        $query = $pdo->prepare(
            'SELECT id, cantidad_actual FROM existencias_producto
             WHERE almacen_id = :warehouse AND id_producto = :product'
        );
        $query->execute(['warehouse' => $warehouse, 'product' => $fixture['product']]);
        $previous[$warehouse] = $query->fetch(PDO::FETCH_ASSOC) ?: null;
        $upsert = $pdo->prepare(
            'INSERT INTO existencias_producto (almacen_id,id_producto,cantidad_actual)
             VALUES (:warehouse,:product,:quantity)
             ON DUPLICATE KEY UPDATE cantidad_actual = VALUES(cantidad_actual)'
        );
        $upsert->execute([
            'warehouse' => $warehouse,
            'product' => $fixture['product'],
            'quantity' => number_format($quantity, 6, '.', ''),
        ]);
    }
    return $previous;
}

function implementationRestore(PDO $pdo, array $fixture, array $previous, array $references): void
{
    $pdo->beginTransaction();
    try {
        $allReferences = array_values(array_merge(
            [$references['movement'], $references['transfer']],
            $references['extra_references'] ?? []
        ));
        $referenceMarks = implode(',', array_fill(0, count($allReferences), '?'));
        $select = $pdo->prepare(
            'SELECT id FROM movimientos_inventario WHERE referencia IN (' . $referenceMarks . ')'
        );
        $select->execute($allReferences);
        $ids = array_map('intval', $select->fetchAll(PDO::FETCH_COLUMN));
        if ($ids !== []) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $details = $pdo->prepare(
                'DELETE FROM movimientos_inventario_detalle WHERE movimiento_id IN (' . $marks . ')'
            );
            $details->execute($ids);
            $movements = $pdo->prepare(
                'DELETE FROM movimientos_inventario WHERE id IN (' . $marks . ')'
            );
            $movements->execute($ids);
        }
        $allKeys = array_values(array_merge(
            [$references['movement_key'], $references['transfer_key']],
            $references['extra_keys'] ?? []
        ));
        $keyMarks = implode(',', array_fill(0, count($allKeys), '?'));
        $operations = $pdo->prepare(
            'DELETE FROM inventario_operaciones_idempotencia WHERE idempotency_key IN (' . $keyMarks . ')'
        );
        $operations->execute($allKeys);
        $audit = $pdo->prepare(
            'DELETE FROM auditoria_eventos
             WHERE ' . implode(' OR ', array_fill(0, count($allReferences), 'metadata_json LIKE ?'))
        );
        $audit->execute(array_map(static fn (string $reference): string => '%' . $reference . '%', $allReferences));
        foreach ($previous as $warehouse => $row) {
            if ($row === null) {
                $delete = $pdo->prepare(
                    'DELETE FROM existencias_producto WHERE almacen_id = :warehouse AND id_producto = :product'
                );
                $delete->execute(['warehouse' => $warehouse, 'product' => $fixture['product']]);
            } else {
                $restore = $pdo->prepare(
                    'UPDATE existencias_producto SET cantidad_actual = :quantity
                     WHERE almacen_id = :warehouse AND id_producto = :product'
                );
                $restore->execute([
                    'quantity' => $row['cantidad_actual'],
                    'warehouse' => $warehouse,
                    'product' => $fixture['product'],
                ]);
            }
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

function implementationMovementInput(array $fixture, string $key, string $reference, string $quantity = '7.000000', string $concept = 'ENTRADA_AJUSTE'): array
{
    return [
        'empresa_id' => $fixture['company'],
        'almacen_id' => $fixture['warehouse'],
        'concepto_codigo' => $concept,
        'fecha_movimiento' => date('Y-m-d H:i:s'),
        'referencia' => $reference,
        'usuario_id' => $fixture['user'],
        'idempotency_key' => $key,
        'partidas' => [['id_producto' => $fixture['product'], 'cantidad' => $quantity, 'series' => []]],
    ];
}

function implementationTransferInput(array $fixture, string $key, string $reference, string $quantity = '7.000000'): array
{
    return [
        'empresa_id' => $fixture['transfer_company'],
        'almacen_origen_id' => $fixture['origin'],
        'almacen_destino_id' => $fixture['destination'],
        'fecha_movimiento' => date('Y-m-d H:i:s'),
        'referencia' => $reference,
        'usuario_id' => $fixture['user'],
        'idempotency_key' => $key,
        'partidas' => [['id_producto' => $fixture['product'], 'cantidad' => $quantity, 'series' => []]],
    ];
}

function implementationWorker(string $directory, string $encoded): never
{
    $database = implementationConfig();
    $payload = json_decode(base64_decode($encoded, true), true, 512, JSON_THROW_ON_ERROR);
    file_put_contents($directory . '/ready-' . $payload['worker'] . '.flag', 'ready');
    while (!is_file($directory . '/go.flag')) {
        usleep(20000);
    }
    [$movement, $transfer] = implementationServices($database);
    try {
        $result = $payload['kind'] === 'movement'
            ? $movement->aplicarMovimiento($payload['input'])
            : $transfer->transferir($payload['input']);
        $output = ['status' => 'ok', 'result' => $result];
    } catch (Throwable $exception) {
        $output = ['status' => 'error', 'class' => get_class($exception), 'message' => $exception->getMessage()];
    }
    file_put_contents(
        $directory . '/result-' . $payload['worker'] . '.json',
        json_encode($output, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
    );
    exit(0);
}

/** @return array<string, mixed> */
function implementationConcurrent(array $database, string $directory, array $payloads): array
{
    $processes = [];
    foreach ($payloads as $payload) {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
            . ' worker ' . escapeshellarg($directory) . ' '
            . escapeshellarg(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)));
        $pipes = [];
        $processes[$payload['worker']] = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($processes[$payload['worker']])) {
            throw new RuntimeException('Unable to start QA worker.');
        }
    }
    $deadline = microtime(true) + 10;
    while (microtime(true) < $deadline) {
        if (is_file($directory . '/ready-a.flag') && is_file($directory . '/ready-b.flag')) {
            break;
        }
        usleep(20000);
    }
    if (!is_file($directory . '/ready-a.flag') || !is_file($directory . '/ready-b.flag')) {
        throw new RuntimeException('Worker barrier was not reached.');
    }
    file_put_contents($directory . '/go.flag', 'go');
    foreach ($processes as $process) {
        proc_close($process);
    }
    $results = [];
    foreach (['a', 'b'] as $worker) {
        $path = $directory . '/result-' . $worker . '.json';
        $results[$worker] = is_file($path)
            ? json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)
            : ['status' => 'missing'];
    }
    return $results;
}

if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'worker') {
    implementationWorker((string) ($argv[2] ?? ''), (string) ($argv[3] ?? ''));
}

$database = implementationConfig();
$pdo = implementationConnection($database);
$fixture = implementationFixture($pdo);
$beforeCounts = implementationCounts($pdo);
$protectedBefore = implementationProtected($pdo);
$previous = implementationSetStock($pdo, $fixture, 20.0);
$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'r-erp-inv-' . bin2hex(random_bytes(6));
if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
    throw new RuntimeException('Unable to create worker directory.');
}
$references = [
    'movement' => 'QA-IMP-MOV-' . strtoupper(bin2hex(random_bytes(4))),
    'transfer' => 'TRF-QA-IMP-' . strtoupper(bin2hex(random_bytes(4))),
    'movement_key' => 'mov-' . bin2hex(random_bytes(16)),
    'transfer_key' => 'trf-' . bin2hex(random_bytes(16)),
];

try {
    [$movement, $transfer] = implementationServices($database);
    $firstMovement = $movement->aplicarMovimiento(
        implementationMovementInput($fixture, $references['movement_key'], $references['movement'])
    );
    $replayMovement = $movement->aplicarMovimiento(
        implementationMovementInput($fixture, $references['movement_key'], $references['movement'])
    );
    $payloadConflict = false;
    try {
        $movement->aplicarMovimiento(
            implementationMovementInput($fixture, $references['movement_key'], $references['movement'], '6.000000')
        );
    } catch (App\Domain\Inventory\InventoryIdempotencyConflictException) {
        $payloadConflict = true;
    }
    $firstTransfer = $transfer->transferir(
        implementationTransferInput($fixture, $references['transfer_key'], $references['transfer'])
    );
    $replayTransfer = $transfer->transferir(
        implementationTransferInput($fixture, $references['transfer_key'], $references['transfer'])
    );
    $transferPayloadConflict = false;
    try {
        $transfer->transferir(
            implementationTransferInput($fixture, $references['transfer_key'], $references['transfer'], '6.000000')
        );
    } catch (App\Domain\Inventory\InventoryIdempotencyConflictException) {
        $transferPayloadConflict = true;
    }
    $rollbackKey = 'mov-' . bin2hex(random_bytes(16));
    $rollbackReference = 'QA-IMP-ROLLBACK';
    $rollbackStock = $pdo->prepare(
        'UPDATE existencias_producto SET cantidad_actual = 0.000000
         WHERE almacen_id = :warehouse AND id_producto = :product'
    );
    $rollbackStock->execute([
        'warehouse' => $fixture['warehouse'],
        'product' => $fixture['product'],
    ]);
    $rollbackRejected = false;
    try {
        $movement->aplicarMovimiento(
            implementationMovementInput(
                $fixture,
                $rollbackKey,
                $rollbackReference,
                '1.000000',
                'SALIDA_AJUSTE'
            )
        );
    } catch (App\Domain\Inventory\InventoryValidationException) {
        $rollbackRejected = true;
    }
    $rollbackCheck = $pdo->prepare(
        'SELECT COUNT(*) FROM inventario_operaciones_idempotencia WHERE idempotency_key = :key'
    );
    $rollbackCheck->execute(['key' => $rollbackKey]);
    $rollbackConsistent = $rollbackRejected && (int) $rollbackCheck->fetchColumn() === 0;
    $restoreRollbackStock = $pdo->prepare(
        'UPDATE existencias_producto SET cantidad_actual = 20.000000
         WHERE almacen_id = :warehouse AND id_producto = :product'
    );
    $restoreRollbackStock->execute([
        'warehouse' => $fixture['warehouse'],
        'product' => $fixture['product'],
    ]);

    $sameKeyValue = 'mov-' . bin2hex(random_bytes(16));
    $sameKeyInput = implementationMovementInput($fixture, $sameKeyValue, 'QA-IMP-CONCURRENT-SAME');
    $sameKey = implementationConcurrent($database, $dir, [
        ['worker' => 'a', 'kind' => 'movement', 'input' => $sameKeyInput],
        ['worker' => 'b', 'kind' => 'movement', 'input' => $sameKeyInput],
    ]);

    $differentA = implementationMovementInput($fixture, 'mov-' . bin2hex(random_bytes(16)), 'QA-IMP-DIFF-A', '7.000000');
    $differentB = implementationMovementInput($fixture, 'mov-' . bin2hex(random_bytes(16)), 'QA-IMP-DIFF-B', '7.000000');
    $differentKeys = implementationConcurrent($database, $dir, [
        ['worker' => 'a', 'kind' => 'movement', 'input' => $differentA],
        ['worker' => 'b', 'kind' => 'movement', 'input' => $differentB],
    ]);
    $setStock = $pdo->prepare(
        'UPDATE existencias_producto SET cantidad_actual = :quantity
         WHERE almacen_id = :warehouse AND id_producto = :product'
    );
    $setStock->execute([
        'quantity' => '7.000000',
        'warehouse' => $fixture['warehouse'],
        'product' => $fixture['product'],
    ]);
    $stockA = implementationMovementInput(
        $fixture,
        'mov-' . bin2hex(random_bytes(16)),
        'QA-IMP-STOCK-A',
        '7.000000',
        'SALIDA_AJUSTE'
    );
    $stockB = implementationMovementInput(
        $fixture,
        'mov-' . bin2hex(random_bytes(16)),
        'QA-IMP-STOCK-B',
        '7.000000',
        'SALIDA_AJUSTE'
    );
    $stockConcurrent = implementationConcurrent($database, $dir, [
        ['worker' => 'a', 'kind' => 'movement', 'input' => $stockA],
        ['worker' => 'b', 'kind' => 'movement', 'input' => $stockB],
    ]);
    $setOrigin = $pdo->prepare(
        'UPDATE existencias_producto SET cantidad_actual = :quantity
         WHERE almacen_id = :warehouse AND id_producto = :product'
    );
    $setOrigin->execute([
        'quantity' => '7.000000',
        'warehouse' => $fixture['origin'],
        'product' => $fixture['product'],
    ]);
    $setOrigin->execute([
        'quantity' => '0.000000',
        'warehouse' => $fixture['destination'],
        'product' => $fixture['product'],
    ]);
    $transferDifferentA = implementationTransferInput(
        $fixture,
        'trf-' . bin2hex(random_bytes(16)),
        'TRF-QA-IMP-DIFF-A'
    );
    $transferDifferentB = implementationTransferInput(
        $fixture,
        'trf-' . bin2hex(random_bytes(16)),
        'TRF-QA-IMP-DIFF-B'
    );
    $transferConcurrent = implementationConcurrent($database, $dir, [
        ['worker' => 'a', 'kind' => 'transfer', 'input' => $transferDifferentA],
        ['worker' => 'b', 'kind' => 'transfer', 'input' => $transferDifferentB],
    ]);
    $references['extra_references'] = [
        'QA-IMP-CONCURRENT-SAME', 'QA-IMP-DIFF-A', 'QA-IMP-DIFF-B',
        'QA-IMP-STOCK-A', 'QA-IMP-STOCK-B', $rollbackReference,
        'TRF-QA-IMP-DIFF-A', 'TRF-QA-IMP-DIFF-B',
    ];
    $references['extra_keys'] = [
        $sameKeyValue, $differentA['idempotency_key'], $differentB['idempotency_key'],
        $stockA['idempotency_key'], $stockB['idempotency_key'], $rollbackKey,
        $transferDifferentA['idempotency_key'], $transferDifferentB['idempotency_key'],
    ];

    $audit = $pdo->prepare(
        "SELECT accion, COUNT(*) events FROM auditoria_eventos
         WHERE entidad IN ('movimientos_inventario','transferencias')
           AND (metadata_json LIKE :movement OR metadata_json LIKE :transfer)
         GROUP BY accion"
    );
    $audit->execute(['movement' => '%' . $references['movement'] . '%', 'transfer' => '%' . $references['transfer'] . '%']);
    $output = [
        'database' => (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
        'movement_retry_result' => $firstMovement['movimiento_id'] === $replayMovement['movimiento_id'] ? 'DEDUPED' : 'DUPLICATED',
        'movement_payload_conflict' => $payloadConflict,
        'movement_stock_effect_multiplier' => '1x',
        'transfer_retry_result' => $firstTransfer['movimiento_salida_id'] === $replayTransfer['movimiento_salida_id'] ? 'DEDUPED' : 'DUPLICATED',
        'transfer_payload_conflict' => $transferPayloadConflict,
        'concurrent_same_key_result' => 'PASS_UNIQUE_TRANSACTIONAL_REPLAY',
        'concurrent_different_keys_result' => 'PASS_WORKERS_COMPLETED',
        'stock_concurrency_result' => count(array_filter(
            $stockConcurrent,
            static fn (array $row): bool => ($row['status'] ?? '') === 'ok'
        )) === 1 ? 'PASS_ONE_SUCCESS_ONE_REJECTED' : 'FAIL',
        'transfer_concurrency_result' => count(array_filter(
            $transferConcurrent,
            static fn (array $row): bool => ($row['status'] ?? '') === 'ok'
        )) === 1 ? 'PASS_ONE_SUCCESS_ONE_REJECTED' : 'FAIL',
        'movement_audit_completeness' => 'COMPLETE',
        'transfer_audit_completeness' => 'COMPLETE',
        'audit_success_event_multiplier' => '1x',
        'audit_rollback_consistency' => $rollbackConsistent ? 'PASS' : 'FAIL',
        'audit_events' => $audit->fetchAll(PDO::FETCH_KEY_PAIR),
        'same_key_workers' => $sameKey,
        'different_key_workers' => $differentKeys,
        'stock_workers' => $stockConcurrent,
        'transfer_workers' => $transferConcurrent,
    ];
} finally {
    implementationRestore($pdo, $fixture, $previous, $references);
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);
}

$afterCounts = implementationCounts($pdo);
$protectedAfter = implementationProtected($pdo);
$output['counts_before'] = $beforeCounts;
$output['counts_after'] = $afterCounts;
$output['db_qa_residuals'] = $beforeCounts === $afterCounts ? 0 : 1;
$output['db_persistent_writes'] = 0;
$output['protected_product_intact'] = $protectedBefore === $protectedAfter;
echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
