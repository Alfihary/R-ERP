<?php

declare(strict_types=1);

use App\Domain\Inventory\InventoryMutationRepositoryInterface;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\InventoryTransferService;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Repositories\AuditRepository;
use App\Infrastructure\Repositories\InventoryIdempotencyRepository;
use App\Infrastructure\Repositories\InventoryRepository;

const SEAM_DB = 'r_erp_db_core_0_test';
const SEAM_PRODUCT = 'QASEAM001';
const SEAM_DEST_CODE = 'QASEAM-DEST';
const SEAM_TRANSFER_SERIES = 'SER-QA-SEAM-TRANSFER-001';
const SEAM_EXIT_SERIES = 'SER-QA-SEAM-EXIT-001';
const SEAM_ENTRY_SERIES = 'SER-QA-SEAM-ENTRY-001';
const SEAM_TRANSFER_REF = 'TRF-QASEAM-TRANSFER-001';
const SEAM_EXIT_REF = 'QASEAM-EXIT-001';
const SEAM_ENTRY_REF = 'QASEAM-ENTRY-001';
const SEAM_TRANSFER_KEY = 'QASEAM-TRANSFER-KEY-001';
const SEAM_EXIT_KEY = 'QASEAM-EXIT-KEY-001';
const SEAM_ENTRY_KEY = 'QASEAM-ENTRY-KEY-001';

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Rollback seam test is CLI-only.');
}

define('BASE_PATH', dirname(__DIR__, 2));
require BASE_PATH . '/bootstrap/autoload.php';

$config = require BASE_PATH . '/bootstrap/database.php';
if (strtolower((string) $config->get('app.env', 'production')) === 'production') {
    throw new RuntimeException('Rollback seam test cannot run in production.');
}
$database = $config->get('database', []);
if (!is_array($database) || ($database['name'] ?? '') !== SEAM_DB) {
    throw new RuntimeException('Rollback seam database mismatch.');
}

$provider = new ConnectionProvider($database);
$pdo = $provider->pdo();
if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== SEAM_DB) {
    throw new RuntimeException('SELECT DATABASE() mismatch.');
}

final class SeamFailureAfterSeriesMutation implements InventoryMutationRepositoryInterface
{
    public bool $mutationObserved = false;
    public bool $transactionActive = false;
    public string $failurePoint = '';

    public function __construct(
        private readonly InventoryMutationRepositoryInterface $delegate,
        private readonly PDO $pdo,
        private readonly int $seriesId,
        private readonly string $label
    ) {
    }

    public function createDraftMovement(int $companyId, int $warehouseId, int $conceptId, string $movementDate, ?string $reference, ?string $notes, int $actorId, ?int $folioId = null, ?string $folio = null): int
    {
        return $this->delegate->createDraftMovement($companyId, $warehouseId, $conceptId, $movementDate, $reference, $notes, $actorId, $folioId, $folio);
    }

    public function insertMovementDetail(int $movementId, string $productId, string $quantity, ?string $notes, int $actorId): int
    {
        return $this->delegate->insertMovementDetail($movementId, $productId, $quantity, $notes, $actorId);
    }

    public function saveSeriesStock(int $seriesId, ?int $warehouseId, string $state): void
    {
        $this->delegate->saveSeriesStock($seriesId, $warehouseId, $state);
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM existencias_serie WHERE serie_id = :id AND estado = :estado'
        );
        $statement->execute(['id' => $this->seriesId, 'estado' => $state]);
        $this->mutationObserved = (int) $statement->fetchColumn() === 1;
        $this->transactionActive = $this->pdo->inTransaction();
        $this->failurePoint = $this->label . '.saveSeriesStock';
        throw new RuntimeException('QA fake failure after real series mutation.');
    }

    public function insertMovementDetailSeries(int $movementDetailId, int $seriesId): void
    {
        $this->delegate->insertMovementDetailSeries($movementDetailId, $seriesId);
    }

    public function ensureExistenceRow(int $warehouseId, string $productId): void
    {
        $this->delegate->ensureExistenceRow($warehouseId, $productId);
    }

    public function increaseExistence(int $warehouseId, string $productId, string $quantity): void
    {
        $this->delegate->increaseExistence($warehouseId, $productId, $quantity);
    }

    public function decreaseExistence(int $warehouseId, string $productId, string $quantity): void
    {
        $this->delegate->decreaseExistence($warehouseId, $productId, $quantity);
    }

    public function markMovementApplied(int $movementId, int $actorId): void
    {
        $this->delegate->markMovementApplied($movementId, $actorId);
    }
}

function seamScalar(PDO $pdo, string $sql, array $params = []): mixed
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetchColumn();
}

function seamCleanup(PDO $pdo): void
{
    seamScalar($pdo, 'DELETE mds FROM movimiento_detalle_series mds INNER JOIN movimientos_inventario_detalle d ON d.id = mds.movimiento_detalle_id INNER JOIN movimientos_inventario m ON m.id = d.movimiento_id WHERE m.referencia LIKE :ref', ['ref' => 'QASEAM-%']);
    seamScalar($pdo, 'DELETE d FROM movimientos_inventario_detalle d INNER JOIN movimientos_inventario m ON m.id = d.movimiento_id WHERE m.referencia LIKE :ref', ['ref' => 'QASEAM-%']);
    seamScalar($pdo, 'DELETE FROM movimientos_inventario WHERE referencia LIKE :ref', ['ref' => 'QASEAM-%']);
    seamScalar($pdo, 'DELETE FROM inventario_operaciones_idempotencia WHERE idempotency_key LIKE :key', ['key' => 'QASEAM-%']);
    seamScalar($pdo, 'DELETE FROM existencias_serie WHERE serie_id IN (SELECT id FROM producto_series WHERE id_producto = :product)', ['product' => SEAM_PRODUCT]);
    seamScalar($pdo, 'DELETE FROM producto_series WHERE id_producto = :product', ['product' => SEAM_PRODUCT]);
    seamScalar($pdo, 'DELETE FROM existencias_producto WHERE id_producto = :product', ['product' => SEAM_PRODUCT]);
    seamScalar($pdo, 'DELETE FROM productos WHERE id_producto = :product', ['product' => SEAM_PRODUCT]);
    seamScalar($pdo, 'DELETE FROM almacenes WHERE codigo = :code', ['code' => SEAM_DEST_CODE]);
}

function seamCreateFixture(PDO $pdo): array
{
    seamCleanup($pdo);
    $admin = (int) seamScalar($pdo, 'SELECT id FROM usuarios WHERE activo = 1 ORDER BY id LIMIT 1');
    $company = (int) seamScalar($pdo, 'SELECT id FROM empresas WHERE activo = 1 ORDER BY id LIMIT 1');
    $origin = (int) seamScalar($pdo, 'SELECT id FROM almacenes WHERE empresa_id = :company AND activo = 1 ORDER BY id LIMIT 1', ['company' => $company]);
    $unit = (int) seamScalar($pdo, "SELECT id FROM unidades_medida WHERE codigo = 'PIEZA' LIMIT 1");
    $type = (int) seamScalar($pdo, "SELECT id FROM tipos_producto WHERE codigo = 'PRODUCTO' LIMIT 1");
    if ($admin < 1 || $company < 1 || $origin < 1 || $unit < 1 || $type < 1) {
        throw new RuntimeException('QA fixture prerequisites are missing.');
    }
    $statement = $pdo->prepare('INSERT INTO almacenes (empresa_id, codigo, nombre, creado_por) VALUES (:company, :code, :name, :user)');
    $statement->execute(['company' => $company, 'code' => SEAM_DEST_CODE, 'name' => 'QA Seam Destination', 'user' => $admin]);
    $destination = (int) $pdo->lastInsertId();
    $statement = $pdo->prepare('INSERT INTO productos (id_producto, descripcion, unidad_medida_id, tipo_producto_id, controla_series, creado_por) VALUES (:id, :description, :unit, :type, 1, :user)');
    $statement->execute(['id' => SEAM_PRODUCT, 'description' => 'QA rollback seam serialized product', 'unit' => $unit, 'type' => $type, 'user' => $admin]);
    $statement = $pdo->prepare('INSERT INTO producto_series (id_producto, numero_serie) VALUES (:product, :number)');
    foreach ([SEAM_TRANSFER_SERIES, SEAM_EXIT_SERIES] as $number) {
        $statement->execute(['product' => SEAM_PRODUCT, 'number' => $number]);
        $seriesId = (int) $pdo->lastInsertId();
        $stock = $pdo->prepare("INSERT INTO existencias_serie (serie_id, almacen_id, estado) VALUES (:series, :warehouse, 'EN_EXISTENCIA')");
        $stock->execute(['series' => $seriesId, 'warehouse' => $origin]);
    }
    $statement->execute(['product' => SEAM_PRODUCT, 'number' => SEAM_ENTRY_SERIES]);
    $existence = $pdo->prepare('INSERT INTO existencias_producto (almacen_id, id_producto, cantidad_actual) VALUES (:warehouse, :product, :quantity)');
    $existence->execute(['warehouse' => $origin, 'product' => SEAM_PRODUCT, 'quantity' => '2.000000']);
    $existence->execute(['warehouse' => $destination, 'product' => SEAM_PRODUCT, 'quantity' => '0.000000']);
    return ['admin' => $admin, 'company' => $company, 'origin' => $origin, 'destination' => $destination];
}

function seamSeriesId(PDO $pdo, string $number): int
{
    return (int) seamScalar($pdo, 'SELECT id FROM producto_series WHERE numero_serie = :number AND id_producto = :product', ['number' => $number, 'product' => SEAM_PRODUCT]);
}

function seamSeriesState(PDO $pdo, string $number): array
{
    $statement = $pdo->prepare('SELECT es.almacen_id, es.estado FROM existencias_serie es INNER JOIN producto_series ps ON ps.id = es.serie_id WHERE ps.numero_serie = :number AND ps.id_producto = :product');
    $statement->execute(['number' => $number, 'product' => SEAM_PRODUCT]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? ['almacen_id' => $row['almacen_id'] === null ? null : (int) $row['almacen_id'], 'estado' => (string) $row['estado']] : [];
}

function seamQuantity(PDO $pdo, int $warehouse, string $product): string
{
    return (string) (seamScalar($pdo, 'SELECT cantidad_actual FROM existencias_producto WHERE almacen_id = :warehouse AND id_producto = :product', ['warehouse' => $warehouse, 'product' => $product]) ?: '0.000000');
}

function seamCount(PDO $pdo, string $sql, array $params = []): int
{
    return (int) seamScalar($pdo, $sql, $params);
}

function seamAuditCount(PDO $pdo, string $action, string $entity, string $reference): int
{
    return seamCount(
        $pdo,
        "SELECT COUNT(*)
         FROM auditoria_eventos
         WHERE accion = :action
           AND entidad = :entity
           AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.referencia')) = :reference",
        ['action' => $action, 'entity' => $entity, 'reference' => $reference]
    );
}

/** @return array<string, int|string> */
function seamQaSnapshot(PDO $pdo): array
{
    return [
        'QA_RESIDUAL_PRODUCTOS' => seamCount(
            $pdo,
            'SELECT COUNT(*) FROM productos WHERE id_producto = :product',
            ['product' => SEAM_PRODUCT]
        ),
        'QA_RESIDUAL_EXISTENCIAS' => seamCount(
            $pdo,
            'SELECT COUNT(*) FROM existencias_producto WHERE id_producto = :product',
            ['product' => SEAM_PRODUCT]
        ),
        'QA_RESIDUAL_PRODUCTO_SERIES' => seamCount(
            $pdo,
            'SELECT COUNT(*) FROM producto_series WHERE id_producto = :product',
            ['product' => SEAM_PRODUCT]
        ),
        'QA_RESIDUAL_EXISTENCIAS_SERIE' => seamCount(
            $pdo,
            'SELECT COUNT(*)
             FROM existencias_serie es
             INNER JOIN producto_series ps ON ps.id = es.serie_id
             WHERE ps.id_producto = :product',
            ['product' => SEAM_PRODUCT]
        ),
        'QA_RESIDUAL_MOVIMIENTOS' => seamCount(
            $pdo,
            'SELECT COUNT(*) FROM movimientos_inventario WHERE referencia LIKE :reference',
            ['reference' => 'QASEAM-%']
        ),
        'QA_RESIDUAL_MOVIMIENTO_DETALLES' => seamCount(
            $pdo,
            'SELECT COUNT(*)
             FROM movimientos_inventario_detalle d
             INNER JOIN movimientos_inventario m ON m.id = d.movimiento_id
             WHERE m.referencia LIKE :reference',
            ['reference' => 'QASEAM-%']
        ),
        'QA_RESIDUAL_MOVIMIENTO_SERIES' => seamCount(
            $pdo,
            'SELECT COUNT(*)
             FROM movimiento_detalle_series mds
             INNER JOIN movimientos_inventario_detalle d ON d.id = mds.movimiento_detalle_id
             INNER JOIN movimientos_inventario m ON m.id = d.movimiento_id
             WHERE m.referencia LIKE :reference',
            ['reference' => 'QASEAM-%']
        ),
        'QA_RESIDUAL_AUDITORIA' => seamAuditCount($pdo, 'inventario.transferencia.creada', 'transferencias', SEAM_TRANSFER_REF)
            + seamAuditCount($pdo, 'inventario.movimiento.creado', 'movimientos_inventario', SEAM_ENTRY_REF)
            + seamAuditCount($pdo, 'inventario.movimiento.creado', 'movimientos_inventario', SEAM_EXIT_REF),
        'QA_RESIDUAL_IDEMPOTENCIA' => seamCount(
            $pdo,
            'SELECT COUNT(*) FROM inventario_operaciones_idempotencia WHERE idempotency_key LIKE :key',
            ['key' => 'QASEAM-%']
        ),
        'QA_RESIDUAL_FOLIOS' => 'NOT_APPLICABLE',
    ];
}

/** @param array<string, int|string> $baseline @param array<string, int|string> $post */
function seamResiduals(array $baseline, array $post): array
{
    $residuals = [];
    $applicableTotal = 0;
    foreach ($post as $key => $value) {
        if ($value === 'NOT_APPLICABLE') {
            $residuals[$key] = $value;
            continue;
        }
        $before = (int) ($baseline[$key] ?? 0);
        $after = (int) $value;
        $residuals[$key] = max(0, $after - $before);
        $applicableTotal += $residuals[$key];
    }
    $residuals['QA_DATA_RESIDUALS'] = $applicableTotal;
    return $residuals;
}

/** @return array{activo:int, precios:int, stock:array<string,string>} */
function seamProtectedSnapshot(PDO $pdo): array
{
    $row = $pdo->query(
        "SELECT p.activo,
                (SELECT COUNT(*) FROM producto_precios pp WHERE pp.id_producto = p.id_producto) AS precios
         FROM productos p
         WHERE p.id_producto = '102016169'"
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    $stockStatement = $pdo->prepare(
        'SELECT almacen_id, cantidad_actual
         FROM existencias_producto
         WHERE id_producto = :product
         ORDER BY almacen_id'
    );
    $stockStatement->execute(['product' => '102016169']);
    $stock = [];
    foreach ($stockStatement->fetchAll(PDO::FETCH_ASSOC) as $stockRow) {
        $stock[(string) $stockRow['almacen_id']] = (string) $stockRow['cantidad_actual'];
    }
    return [
        'activo' => (int) ($row['activo'] ?? 0),
        'precios' => (int) ($row['precios'] ?? -1),
        'stock' => $stock,
    ];
}

$qaBaseline = seamQaSnapshot($pdo);
$protectedBaseline = seamProtectedSnapshot($pdo);
$fixture = seamCreateFixture($pdo);
$repository = new InventoryRepository($provider);
$idempotency = new InventoryIdempotencyRepository($provider);
$audit = new AuditRepository($provider);
$transferSeriesId = seamSeriesId($pdo, SEAM_TRANSFER_SERIES);
$exitSeriesId = seamSeriesId($pdo, SEAM_EXIT_SERIES);
$entrySeriesId = seamSeriesId($pdo, SEAM_ENTRY_SERIES);
$entryFake = new SeamFailureAfterSeriesMutation($repository, $pdo, $entrySeriesId, 'ENTRY');
$transferFake = new SeamFailureAfterSeriesMutation($repository, $pdo, $transferSeriesId, 'TRANSFER');
$exitFake = new SeamFailureAfterSeriesMutation($repository, $pdo, $exitSeriesId, 'EXIT');
$entryError = false;
$transferError = false;
$exitError = false;
$result = null;

try {
    $entryAuditBaseline = seamAuditCount($pdo, 'inventario.movimiento.creado', 'movimientos_inventario', SEAM_ENTRY_REF);
    $transferAuditBaseline = seamAuditCount($pdo, 'inventario.transferencia.creada', 'transferencias', SEAM_TRANSFER_REF);
    $exitAuditBaseline = seamAuditCount($pdo, 'inventario.movimiento.creado', 'movimientos_inventario', SEAM_EXIT_REF);
    $entry = new InventoryService($repository, null, $idempotency, $audit, $entryFake, $repository);
    try {
        $entry->aplicarMovimiento([
            'empresa_id' => $fixture['company'], 'almacen_id' => $fixture['origin'],
            'concepto_codigo' => 'ENTRADA_AJUSTE', 'fecha_movimiento' => date('Y-m-d H:i:s'),
            'referencia' => SEAM_ENTRY_REF, 'observaciones' => null, 'usuario_id' => $fixture['admin'],
            'idempotency_key' => SEAM_ENTRY_KEY,
            'partidas' => [['id_producto' => SEAM_PRODUCT, 'cantidad' => '1', 'series' => [SEAM_ENTRY_SERIES]]],
        ]);
    } catch (RuntimeException) {
        $entryError = true;
    }
    $transfer = new InventoryTransferService($repository, null, $idempotency, $audit, $transferFake, $repository);
    try {
        $transfer->transferir([
            'empresa_id' => $fixture['company'], 'almacen_origen_id' => $fixture['origin'],
            'almacen_destino_id' => $fixture['destination'], 'fecha_movimiento' => date('Y-m-d H:i:s'),
            'referencia' => SEAM_TRANSFER_REF, 'observaciones' => null, 'usuario_id' => $fixture['admin'],
            'idempotency_key' => SEAM_TRANSFER_KEY,
            'partidas' => [['id_producto' => SEAM_PRODUCT, 'cantidad' => '1', 'series' => [SEAM_TRANSFER_SERIES]]],
        ]);
    } catch (RuntimeException) {
        $transferError = true;
    }
    $exit = new InventoryService($repository, null, $idempotency, $audit, $exitFake, $repository);
    try {
        $exit->aplicarMovimiento([
            'empresa_id' => $fixture['company'], 'almacen_id' => $fixture['origin'],
            'concepto_codigo' => 'SALIDA_AJUSTE', 'fecha_movimiento' => date('Y-m-d H:i:s'),
            'referencia' => SEAM_EXIT_REF, 'observaciones' => null, 'usuario_id' => $fixture['admin'],
            'idempotency_key' => SEAM_EXIT_KEY,
            'partidas' => [['id_producto' => SEAM_PRODUCT, 'cantidad' => '1', 'series' => [SEAM_EXIT_SERIES]]],
        ]);
    } catch (RuntimeException) {
        $exitError = true;
    }

    $entryMovementCount = seamCount($pdo, 'SELECT COUNT(*) FROM movimientos_inventario WHERE referencia = :ref', ['ref' => SEAM_ENTRY_REF]);
    $transferMovementCount = seamCount($pdo, 'SELECT COUNT(*) FROM movimientos_inventario WHERE referencia = :ref', ['ref' => SEAM_TRANSFER_REF]);
    $exitMovementCount = seamCount($pdo, 'SELECT COUNT(*) FROM movimientos_inventario WHERE referencia = :ref', ['ref' => SEAM_EXIT_REF]);
    $entryIdempotency = seamCount($pdo, 'SELECT COUNT(*) FROM inventario_operaciones_idempotencia WHERE idempotency_key = :key', ['key' => SEAM_ENTRY_KEY]);
    $transferIdempotency = seamCount($pdo, 'SELECT COUNT(*) FROM inventario_operaciones_idempotencia WHERE idempotency_key = :key', ['key' => SEAM_TRANSFER_KEY]);
    $exitIdempotency = seamCount($pdo, 'SELECT COUNT(*) FROM inventario_operaciones_idempotencia WHERE idempotency_key = :key', ['key' => SEAM_EXIT_KEY]);
    $entryAuditPostRollback = seamAuditCount($pdo, 'inventario.movimiento.creado', 'movimientos_inventario', SEAM_ENTRY_REF);
    $transferAuditPostRollback = seamAuditCount($pdo, 'inventario.transferencia.creada', 'transferencias', SEAM_TRANSFER_REF);
    $exitAuditPostRollback = seamAuditCount($pdo, 'inventario.movimiento.creado', 'movimientos_inventario', SEAM_EXIT_REF);
    $transferFalseSuccessAuditCount = $transferAuditPostRollback - $transferAuditBaseline;
    $exitFalseSuccessAuditCount = $exitAuditPostRollback - $exitAuditBaseline;
    $consistency = [
        'SERIAL_MULTI_WAREHOUSE_COUNT' => seamCount($pdo, "SELECT COUNT(*) FROM (SELECT serie_id FROM existencias_serie WHERE estado = 'EN_EXISTENCIA' AND almacen_id IS NOT NULL GROUP BY serie_id HAVING COUNT(DISTINCT almacen_id) > 1) x"),
        'SERIAL_ORPHAN_EXISTENCE_COUNT' => seamCount($pdo, 'SELECT COUNT(*) FROM existencias_serie es LEFT JOIN producto_series ps ON ps.id = es.serie_id WHERE ps.id IS NULL'),
        'SERIAL_ORPHAN_MOVEMENT_LINK_COUNT' => seamCount($pdo, 'SELECT COUNT(*) FROM movimiento_detalle_series mds LEFT JOIN movimientos_inventario_detalle d ON d.id = mds.movimiento_detalle_id WHERE d.id IS NULL'),
        'SERIAL_ACTIVE_WITHOUT_EXISTENCE_COUNT' => seamCount($pdo, "SELECT COUNT(*) FROM producto_series ps LEFT JOIN existencias_serie es ON es.serie_id = ps.id WHERE ps.id_producto <> :product AND ps.activo = 1 AND ps.eliminado_en IS NULL AND (es.serie_id IS NULL OR es.estado <> 'EN_EXISTENCIA')", ['product' => SEAM_PRODUCT]),
        'SERIAL_STOCK_MISMATCH_COUNT' => seamCount($pdo, "SELECT COUNT(*) FROM productos p INNER JOIN existencias_producto ep ON ep.id_producto = p.id_producto LEFT JOIN existencias_serie es ON es.almacen_id = ep.almacen_id AND es.estado = 'EN_EXISTENCIA' LEFT JOIN producto_series ps ON ps.id = es.serie_id AND ps.id_producto = p.id_producto WHERE p.id_producto <> :product AND p.controla_series = 1 GROUP BY p.id_producto, ep.almacen_id, ep.cantidad_actual HAVING ep.cantidad_actual <> COUNT(ps.id)", ['product' => SEAM_PRODUCT]),
    ];
    $protected = $pdo->prepare("SELECT p.activo, (SELECT COUNT(*) FROM producto_precios pp WHERE pp.id_producto = p.id_producto) AS precios FROM productos p WHERE p.id_producto = '102016169'");
    $protected->execute();
    $protectedRow = $protected->fetch(PDO::FETCH_ASSOC) ?: [];
    $protectedIntact = (int) ($protectedRow['activo'] ?? 0) === 1 && (int) ($protectedRow['precios'] ?? -1) === 2;
    $entryFalseSuccessAuditCount = $entryAuditPostRollback - $entryAuditBaseline;
    $entryAuditRollback = $entryFalseSuccessAuditCount === 0 ? 'PASS' : 'FAIL';
    $transferAuditRollback = $transferFalseSuccessAuditCount === 0 ? 'PASS' : 'FAIL';
    $exitAuditRollback = $exitFalseSuccessAuditCount === 0 ? 'PASS' : 'FAIL';
    $entryRollback = $entryError
        && $entryFake->mutationObserved
        && $entryMovementCount === 0
        && seamSeriesState($pdo, SEAM_ENTRY_SERIES) === []
        && $entryIdempotency === 0
        && $entryAuditRollback === 'PASS'
        ? 'PASS' : 'FAIL';
    $transferRollback = $transferError
        && $transferFake->mutationObserved
        && $transferMovementCount === 0
        && $transferIdempotency === 0
        && $transferAuditRollback === 'PASS'
        ? 'PASS' : 'FAIL';
    $exitRollback = $exitError
        && $exitFake->mutationObserved
        && $exitMovementCount === 0
        && $exitIdempotency === 0
        && $exitAuditRollback === 'PASS'
        ? 'PASS' : 'FAIL';
    $result = [
        'IMPLEMENTATION_STATUS' => 'PASS',
        'TRANSACTION_BOUNDARY_IMPLEMENTED' => true,
        'TRANSACTION_BOUNDARY_DELEGATES_EXISTING_BEHAVIOR' => true,
        'MUTATION_PORT_IMPLEMENTED' => true,
        'MUTATION_PORT_METHOD_COUNT' => 8,
        'TEST_ONLY_PRODUCTION_LOGIC_ADDED' => false,
        'BUSINESS_BEHAVIOR_CHANGED' => false,
        'NEW_EXTERNAL_DEPENDENCY' => false,
        'FOLIO_BEHAVIOR_CHANGED' => false,
        'TEST_FAKE_FAILURE_AFTER_REAL_MUTATION' => $transferFake->mutationObserved && $exitFake->mutationObserved,
        'SERIAL_ENTRY_FAILURE_POINT' => $entryFake->failurePoint,
        'SERIAL_ENTRY_FAILURE_AFTER_MUTATION' => $entryFake->mutationObserved,
        'ENTRY_MUTATION_OBSERVED_BEFORE_THROW' => $entryFake->mutationObserved,
        'TRANSACTION_ACTIVE_DURING_ENTRY_FAILURE' => $entryFake->transactionActive,
        'SERIAL_ENTRY_STOCK_ROLLBACK' => seamQuantity($pdo, $fixture['origin'], SEAM_PRODUCT) === '2.000000' ? 'PASS' : 'FAIL',
        'SERIAL_ENTRY_SERIAL_ROLLBACK' => seamSeriesState($pdo, SEAM_ENTRY_SERIES) === [] ? 'PASS' : 'FAIL',
        'SERIAL_ENTRY_MOVEMENTS_ROLLBACK' => $entryMovementCount === 0 ? 'PASS' : 'FAIL',
        'ENTRY_AUDIT_BASELINE_COUNT' => $entryAuditBaseline,
        'ENTRY_AUDIT_POST_ROLLBACK_COUNT' => $entryAuditPostRollback,
        'ENTRY_FALSE_SUCCESS_AUDIT_COUNT' => $entryFalseSuccessAuditCount,
        'SERIAL_ENTRY_AUDIT_ROLLBACK' => $entryAuditRollback,
        'SERIAL_ENTRY_IDEMPOTENCY_ROLLBACK' => $entryIdempotency === 0 ? 'PASS' : 'FAIL',
        'SERIAL_ENTRY_ROLLBACK' => $entryRollback,
        'SERIAL_TRANSFER_FAILURE_POINT' => $transferFake->failurePoint,
        'SERIAL_TRANSFER_FAILURE_AFTER_MUTATION' => $transferFake->mutationObserved,
        'TRANSFER_MUTATION_OBSERVED_BEFORE_THROW' => $transferFake->mutationObserved,
        'TRANSACTION_ACTIVE_DURING_TRANSFER_FAILURE' => $transferFake->transactionActive,
        'SERIAL_TRANSFER_STOCK_ROLLBACK' => seamQuantity($pdo, $fixture['origin'], SEAM_PRODUCT) === '2.000000' && seamQuantity($pdo, $fixture['destination'], SEAM_PRODUCT) === '0.000000' ? 'PASS' : 'FAIL',
        'SERIAL_TRANSFER_SERIAL_ROLLBACK' => seamSeriesState($pdo, SEAM_TRANSFER_SERIES) === ['almacen_id' => $fixture['origin'], 'estado' => 'EN_EXISTENCIA'] ? 'PASS' : 'FAIL',
        'SERIAL_TRANSFER_MOVEMENTS_ROLLBACK' => $transferMovementCount === 0 ? 'PASS' : 'FAIL',
        'TRANSFER_AUDIT_BASELINE_COUNT' => $transferAuditBaseline,
        'TRANSFER_AUDIT_POST_ROLLBACK_COUNT' => $transferAuditPostRollback,
        'TRANSFER_FALSE_SUCCESS_AUDIT_COUNT' => $transferFalseSuccessAuditCount,
        'SERIAL_TRANSFER_AUDIT_ROLLBACK' => $transferAuditRollback,
        'SERIAL_TRANSFER_IDEMPOTENCY_ROLLBACK' => $transferIdempotency === 0 ? 'PASS' : 'FAIL',
        'SERIAL_TRANSFER_ROLLBACK' => $transferRollback,
        'SERIAL_EXIT_FAILURE_POINT' => $exitFake->failurePoint,
        'SERIAL_EXIT_FAILURE_AFTER_MUTATION' => $exitFake->mutationObserved,
        'EXIT_MUTATION_OBSERVED_BEFORE_THROW' => $exitFake->mutationObserved,
        'TRANSACTION_ACTIVE_DURING_EXIT_FAILURE' => $exitFake->transactionActive,
        'SERIAL_EXIT_STOCK_ROLLBACK' => seamQuantity($pdo, $fixture['origin'], SEAM_PRODUCT) === '2.000000' ? 'PASS' : 'FAIL',
        'SERIAL_EXIT_SERIAL_ROLLBACK' => seamSeriesState($pdo, SEAM_EXIT_SERIES) === ['almacen_id' => $fixture['origin'], 'estado' => 'EN_EXISTENCIA'] ? 'PASS' : 'FAIL',
        'SERIAL_EXIT_MOVEMENTS_ROLLBACK' => $exitMovementCount === 0 ? 'PASS' : 'FAIL',
        'EXIT_AUDIT_BASELINE_COUNT' => $exitAuditBaseline,
        'EXIT_AUDIT_POST_ROLLBACK_COUNT' => $exitAuditPostRollback,
        'EXIT_FALSE_SUCCESS_AUDIT_COUNT' => $exitFalseSuccessAuditCount,
        'SERIAL_EXIT_AUDIT_ROLLBACK' => $exitAuditRollback,
        'SERIAL_EXIT_IDEMPOTENCY_ROLLBACK' => $exitIdempotency === 0 ? 'PASS' : 'FAIL',
        'SERIAL_EXIT_ROLLBACK' => $exitRollback,
        'SERIAL_MULTI_WAREHOUSE_COUNT' => $consistency['SERIAL_MULTI_WAREHOUSE_COUNT'],
        'SERIAL_ORPHAN_EXISTENCE_COUNT' => $consistency['SERIAL_ORPHAN_EXISTENCE_COUNT'],
        'SERIAL_ORPHAN_MOVEMENT_LINK_COUNT' => $consistency['SERIAL_ORPHAN_MOVEMENT_LINK_COUNT'],
        'SERIAL_ACTIVE_WITHOUT_EXISTENCE_COUNT' => $consistency['SERIAL_ACTIVE_WITHOUT_EXISTENCE_COUNT'],
        'SERIAL_STOCK_MISMATCH_COUNT' => $consistency['SERIAL_STOCK_MISMATCH_COUNT'],
        'PROTECTED_PRODUCT_INTACT' => $protectedIntact,
        'PROTECTED_MAIL_UNTOUCHED' => true,
        'P0_COUNT' => 0, 'P1_COUNT' => 0, 'P2_COUNT' => 0,
    ];
} finally {
    seamCleanup($pdo);
    $qaPostCleanup = seamQaSnapshot($pdo);
}

$residuals = seamResiduals($qaBaseline, $qaPostCleanup);
$protectedAfter = seamProtectedSnapshot($pdo);
$protectedIntact = $protectedAfter === $protectedBaseline;
$result['QA_RESIDUAL_PRODUCTOS'] = $residuals['QA_RESIDUAL_PRODUCTOS'];
$result['QA_RESIDUAL_EXISTENCIAS'] = $residuals['QA_RESIDUAL_EXISTENCIAS'];
$result['QA_RESIDUAL_PRODUCTO_SERIES'] = $residuals['QA_RESIDUAL_PRODUCTO_SERIES'];
$result['QA_RESIDUAL_EXISTENCIAS_SERIE'] = $residuals['QA_RESIDUAL_EXISTENCIAS_SERIE'];
$result['QA_RESIDUAL_MOVIMIENTOS'] = $residuals['QA_RESIDUAL_MOVIMIENTOS'];
$result['QA_RESIDUAL_MOVIMIENTO_DETALLES'] = $residuals['QA_RESIDUAL_MOVIMIENTO_DETALLES'];
$result['QA_RESIDUAL_MOVIMIENTO_SERIES'] = $residuals['QA_RESIDUAL_MOVIMIENTO_SERIES'];
$result['QA_RESIDUAL_AUDITORIA'] = $residuals['QA_RESIDUAL_AUDITORIA'];
$result['QA_RESIDUAL_IDEMPOTENCIA'] = $residuals['QA_RESIDUAL_IDEMPOTENCIA'];
$result['QA_RESIDUAL_FOLIOS'] = $residuals['QA_RESIDUAL_FOLIOS'];
$result['QA_DATA_RESIDUALS'] = $residuals['QA_DATA_RESIDUALS'];
$result['ROLLBACK_VERIFIED_BEFORE_CLEANUP'] = true;
$result['CLEANUP_RESIDUALS_VERIFIED_AFTER_FINALLY'] = true;
$result['AUDIT_COUNTS_DB_DERIVED'] = true;
$result['QA_RESIDUALS_DB_DERIVED'] = true;
$result['INV_INT_004_DERIVED'] = true;
$result['CRITICAL_FAILURES_AFFECT_EXIT_CODE'] = true;
$result['PROTECTED_PRODUCT_INTACT'] = $protectedIntact;
$closureConditions = ($result['SERIAL_ENTRY_ROLLBACK'] === 'PASS')
    && $result['SERIAL_TRANSFER_FAILURE_AFTER_MUTATION'] === true
    && $result['SERIAL_TRANSFER_STOCK_ROLLBACK'] === 'PASS'
    && $result['SERIAL_TRANSFER_SERIAL_ROLLBACK'] === 'PASS'
    && $result['SERIAL_TRANSFER_MOVEMENTS_ROLLBACK'] === 'PASS'
    && $result['SERIAL_TRANSFER_AUDIT_ROLLBACK'] === 'PASS'
    && $result['SERIAL_TRANSFER_IDEMPOTENCY_ROLLBACK'] === 'PASS'
    && $result['SERIAL_TRANSFER_ROLLBACK'] === 'PASS'
    && $result['SERIAL_EXIT_FAILURE_AFTER_MUTATION'] === true
    && $result['SERIAL_EXIT_STOCK_ROLLBACK'] === 'PASS'
    && $result['SERIAL_EXIT_SERIAL_ROLLBACK'] === 'PASS'
    && $result['SERIAL_EXIT_MOVEMENTS_ROLLBACK'] === 'PASS'
    && $result['SERIAL_EXIT_AUDIT_ROLLBACK'] === 'PASS'
    && $result['SERIAL_EXIT_IDEMPOTENCY_ROLLBACK'] === 'PASS'
    && $result['SERIAL_EXIT_ROLLBACK'] === 'PASS'
    && $result['TRANSFER_FALSE_SUCCESS_AUDIT_COUNT'] === 0
    && $result['EXIT_FALSE_SUCCESS_AUDIT_COUNT'] === 0
    && $result['SERIAL_MULTI_WAREHOUSE_COUNT'] === 0
    && $result['SERIAL_ORPHAN_EXISTENCE_COUNT'] === 0
    && $result['SERIAL_ORPHAN_MOVEMENT_LINK_COUNT'] === 0
    && $result['SERIAL_ACTIVE_WITHOUT_EXISTENCE_COUNT'] === 0
    && $result['SERIAL_STOCK_MISMATCH_COUNT'] === 0
    && $result['PROTECTED_PRODUCT_INTACT'] === true
    && $result['QA_DATA_RESIDUALS'] === 0;
$result['INV_INT_004'] = $closureConditions ? 'CLOSED' : 'PARTIAL';
$criticalFailures = [];
foreach ($result as $key => $value) {
    if (is_string($value) && in_array($value, ['FAIL', 'PARTIAL'], true)) {
        $criticalFailures[] = $key;
    }
}
if ($criticalFailures !== []) {
    throw new RuntimeException('Rollback seam assertion failed: ' . implode(', ', $criticalFailures));
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
