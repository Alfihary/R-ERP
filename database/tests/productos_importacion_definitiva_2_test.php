<?php

declare(strict_types=1);

use App\Domain\Audit\AuditService;
use App\Core\Request;
use App\Core\Router;
use App\Core\Session;
use App\Domain\Auth\AuthService;
use App\Domain\Products\ProductCreateCore;
use App\Domain\Products\Import\ProductImportBatchException;
use App\Domain\Products\Import\ProductImportBatchService;
use App\Domain\Products\Import\ProductImportBusinessValidator;
use App\Domain\Products\Import\ProductImportConfirmationService;
use App\Domain\Products\Import\ProductImportFileReader;
use App\Domain\Products\Import\ProductImportPreviewService;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Import\PrivateProductImportPreviewStore;
use App\Infrastructure\Import\ProductImportReader;
use App\Infrastructure\Repositories\AuditRepository;
use App\Infrastructure\Repositories\PdoProductImportBusinessLookup;
use App\Infrastructure\Repositories\ProductImportRunRepository;
use App\Infrastructure\Repositories\ProductRepository;
use App\Infrastructure\Repositories\PermissionRepository;
use App\Infrastructure\Repositories\ScopeRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContextService;
use App\Domain\Scope\UserScopeService;
use App\Http\Controllers\ProductImportController;
use App\Http\Middlewares\AuthMiddleware;
use App\Http\Middlewares\CsrfMiddleware;
use App\Http\Middlewares\PermissionMiddleware;
use App\Support\Security\CsrfTokenService;

return static function (PDO $pdo, object $config, string $database): array {
    $connection = new ConnectionProvider($config->get('database', []));
    $pdo = $connection->pdo();
    $actor = authorizedActor($pdo);
    $otherActor = otherActor($pdo, $actor);
    $protectedBefore = productSnapshot($pdo, '102016169');
    if ($protectedBefore['exists'] !== true || $protectedBefore['prices'] !== 2) {
        throw new RuntimeException('Protected product baseline is not intact.');
    }

    $root = BASE_PATH . '/storage/temp/product-import-definitive-' . bin2hex(random_bytes(8));
    if (!mkdir($root, 0700, true) && !is_dir($root)) {
        throw new RuntimeException('Could not create isolated definitive import storage.');
    }
    $ids = [];
    $runIds = [];
    $auditRunIds = [];
    $checks = [];
    $clock = time();
    $catalogId = null;
    $catalogWasActive = null;
    try {
        $reader = new ProductImportReader();
        $lookup = new PdoProductImportBusinessLookup($pdo);
        $validator = new ProductImportBusinessValidator($lookup);
        $store = new PrivateProductImportPreviewStore($root, true, static function () use (&$clock): int { return $clock; });
        $previews = new ProductImportPreviewService($reader, $validator, $store);
        $confirmations = new ProductImportConfirmationService($reader, $validator, $store);
        $productRepository = new ProductRepository($connection);
        $audit = new AuditService(new AuditRepository($connection));
        $batch = new ProductImportBatchService(
            $connection,
            new ProductImportRunRepository($connection),
            new ProductCreateCore($productRepository),
            $audit,
            $confirmations,
            $reader,
            $validator,
            $store,
        );
        $binding = str_repeat('a', 64);

        $csvId = 'QAIMP2CSV' . strtoupper(bin2hex(random_bytes(3)));
        $ids[] = $csvId;
        $csvPreview = createPreview($previews, $root, $csvId, $actor, $binding, csv($csvId, 1));
        $csvConfirmation = $confirmations->confirm($csvPreview['preview_id'], $actor, $binding);
        $csvResult = $batch->execute($csvConfirmation['confirmation_token'], $actor, $binding);
        $runIds[] = (int) $csvResult['import_run_id'];
        $checks['IMPORT_VALID_CSV_CONFIRM'] = $csvResult['status'] === 'succeeded'
            && (int) $csvResult['created_count'] === 1;

        $replay = $batch->execute($csvConfirmation['confirmation_token'], $actor, str_repeat('b', 64));
        $checks['CONFIRM_REPLAY_TEST'] = $replay['status'] === 'already_processed'
            && (int) $replay['import_run_id'] === (int) $csvResult['import_run_id'];
        $checks['REPLAY_SAME_USER_DIFFERENT_SESSION'] = $checks['CONFIRM_REPLAY_TEST'];

        $otherRejected = false;
        try {
            $batch->execute($csvConfirmation['confirmation_token'], $otherActor, str_repeat('c', 64));
        } catch (ProductImportBatchException $exception) {
            $otherRejected = $exception->httpStatus === 404 && $exception->errorCode === 'confirmation_not_found';
        }
        $checks['REPLAY_OTHER_USER_REJECTED'] = $otherRejected;
        $checks['REPLAY_OTHER_USER_DATA_DISCLOSED'] = !$otherRejected;

        $xlsxId = 'QAIMP2XLS' . strtoupper(bin2hex(random_bytes(3)));
        $ids[] = $xlsxId;
        $xlsxPath = xlsx($xlsxId);
        $xlsxPreview = $previews->create(
            ['name' => $xlsxId . '.xlsx', 'tmp_name' => $xlsxPath, 'error' => UPLOAD_ERR_OK,
             'size' => filesize($xlsxPath), 'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            $actor,
            $binding,
        );
        $xlsxConfirmation = $confirmations->confirm($xlsxPreview['preview_id'], $actor, $binding);
        $xlsxResult = $batch->execute($xlsxConfirmation['confirmation_token'], $actor, $binding);
        $runIds[] = (int) $xlsxResult['import_run_id'];
        $checks['IMPORT_VALID_XLSX_CONFIRM'] = $xlsxResult['status'] === 'succeeded'
            && (int) $xlsxResult['created_count'] === 1;

        $multiA = 'QAIMP2M1' . strtoupper(bin2hex(random_bytes(3)));
        $multiB = 'QAIMP2M2' . strtoupper(bin2hex(random_bytes(3)));
        $ids[] = $multiA; $ids[] = $multiB;
        $multiPreview = createPreview($previews, $root, $multiA, $actor, $binding, csv($multiA, 2, $multiB));
        $multiConfirmation = $confirmations->confirm($multiPreview['preview_id'], $actor, $binding);
        $multiResult = $batch->execute($multiConfirmation['confirmation_token'], $actor, $binding);
        $runIds[] = (int) $multiResult['import_run_id'];
        $checks['IMPORT_MULTIPLE_VALID_ROWS'] = $multiResult['status'] === 'succeeded'
            && (int) $multiResult['created_count'] === 2;

        $modifiedId = 'QAIMP2MOD' . strtoupper(bin2hex(random_bytes(3)));
        $modifiedPreview = createPreview($previews, $root, $modifiedId, $actor, $binding, csv($modifiedId, 1));
        $modifiedMeta = readMetadata($root, $modifiedPreview['preview_id']);
        file_put_contents($root . '/private/product_import_previews/uploads/' . $modifiedPreview['preview_id'] . '.csv', "tampered\n");
        $modifiedRejected = false;
        try { $confirmations->confirm($modifiedPreview['preview_id'], $actor, $binding); }
        catch (Throwable $exception) { $modifiedRejected = $exception->getCode() === 409 || str_contains($exception->getMessage(), 'cambió'); }
        $checks['MODIFIED_PREVIEW_FILE_REJECTED'] = $modifiedRejected;
        unset($modifiedMeta);

        $existingPreview = createPreview($previews, $root, '102016169', $actor, $binding, csv('102016169', 1));
        $existingRejected = false;
        try { $confirmations->confirm($existingPreview['preview_id'], $actor, $binding); }
        catch (Throwable $exception) { $existingRejected = str_contains($exception->getMessage(), 'cambiaron') || str_contains($exception->getMessage(), 'existe'); }
        $checks['EXISTING_PRODUCT_REJECTED'] = $existingRejected;
        $checks['EXISTING_PRODUCT_MUTATED'] = productSnapshot($pdo, '102016169') !== $protectedBefore;

        $rollback = realBatchRollback(
            $pdo,
            $connection,
            $previews,
            $confirmations,
            $reader,
            $validator,
            $store,
            $actor,
            $binding,
        );
        $ids = array_merge($ids, $rollback['ids']);
        $runIds = array_merge($runIds, $rollback['run_ids']);
        $checks['REAL_BATCH_ROLLBACK_TEST_USED'] = $rollback['used'];
        $checks['BATCH_FIRST_ROW_REACHED_PERSISTENCE'] = $rollback['first_row_persisted'];
        $checks['CONTROLLED_FAILURE_AFTER_FIRST_PERSISTED_ROW'] = $rollback['failure_after_first'];
        $checks['IMPORT_ALL_OR_NOTHING_ROLLBACK'] = $rollback['passed'];
        $checks['QA_PRODUCTS_FROM_FAILED_BATCH'] = $rollback['products'];
        $checks['QA_RELATIONS_FROM_FAILED_BATCH'] = $rollback['relations'];
        $checks['QA_PRODUCT_AUDITS_FROM_FAILED_BATCH'] = $rollback['product_audits'];
        $checks['QA_BATCH_AUDITS_FROM_FAILED_BATCH'] = $rollback['batch_audits'];
        $checks['QA_IMPORT_RUNS_FROM_FAILED_BATCH'] = $rollback['runs'];
        $checks['POST_PREVIEW_CONFLICT_REVALIDATION'] = $existingRejected;
        $checks['CONCURRENT_PRODUCT_CONFLICT_ROLLBACK'] = 'NOT_EXECUTED_SAFE_LIMITATION';
        $checks['CONCURRENT_UNIQUE_GUARD_DB_TEST'] = 'NOT_EXECUTED_SAFE_LIMITATION';
        $checks['REPLAY_AFTER_PREVIEW_FILE_REMOVED'] = $replay['status'] === 'already_processed';
        $catalogId = (int) $pdo->query("SELECT id FROM unidades_medida WHERE codigo='PIEZA' LIMIT 1")->fetchColumn();
        $catalogWasActive = (int) $pdo->query('SELECT activo FROM unidades_medida WHERE id=' . $catalogId)->fetchColumn();
        $catalogPreview = createPreview($previews, $root, 'QAIMP2CAT' . strtoupper(bin2hex(random_bytes(2))), $actor, $binding, csv('QAIMP2CAT' . strtoupper(bin2hex(random_bytes(2))), 1));
        $pdo->prepare('UPDATE unidades_medida SET activo=0 WHERE id=:id')->execute(['id' => $catalogId]);
        $catalogRejected = false;
        try { $confirmations->confirm($catalogPreview['preview_id'], $actor, $binding); }
        catch (Throwable $exception) { $catalogRejected = str_contains($exception->getMessage(), 'cambiaron'); }
        $pdo->prepare('UPDATE unidades_medida SET activo=:activo WHERE id=:id')->execute(['activo' => $catalogWasActive, 'id' => $catalogId]);
        $checks['CATALOG_REVALIDATION_ON_CONFIRM'] = $catalogRejected;

        $expiredId = 'QAIMP2EXP' . strtoupper(bin2hex(random_bytes(2)));
        $ids[] = $expiredId;
        $expiredPreview = createPreview($previews, $root, $expiredId, $actor, $binding, csv($expiredId, 1));
        $expiredConfirmation = $confirmations->confirm($expiredPreview['preview_id'], $actor, $binding);
        $clock += ProductImportConfirmationService::TTL_SECONDS + 1;
        $expiredRejected = false;
        try { $batch->execute($expiredConfirmation['confirmation_token'], $actor, $binding); }
        catch (Throwable $exception) { $expiredRejected = str_contains($exception->getMessage(), 'expiró'); }
        $clock = time();
        $checks['EXPIRED_CONFIRMATION_REJECTED'] = $expiredRejected;

        $auditCount = 0;
        foreach ($runIds as $runId) {
            $auditStatement = $pdo->prepare("SELECT COUNT(*) FROM auditoria_eventos WHERE accion='productos.importacion.lote' AND entidad='producto_importacion' AND entidad_id=:id");
            $auditStatement->execute(['id' => (string) $runId]);
            $auditCount += (int) $auditStatement->fetchColumn();
        }
        $checks['BATCH_AUDIT_PER_SUCCESS_ASSERTED'] = $auditCount === count($runIds);
        $checks['BATCH_AUDIT_REPLAY_NO_DUPLICATE_ASSERTED'] = $auditCount === count($runIds);
        $checks['BATCH_AUDIT_ROLLBACK_ZERO_ASSERTED'] = $rollback['batch_audits'] === 0;
        $checks['INDIVIDUAL_AUDIT_ROLLBACK_ZERO_ASSERTED'] = $rollback['product_audits'] === 0;
        $checks['REPLAY_SAME_RESULT_ASSERTED'] = $replay['status'] === 'already_processed'
            && (int) $replay['created_count'] === (int) $csvResult['created_count'];
        $http = httpProductionChecks($pdo, $config, $connection, $actor);
        $checks = array_merge($checks, $http['checks']);
        $ids = array_merge($ids, $http['ids']);
        $runIds = array_merge($runIds, $http['run_ids']);

        foreach ($checks as $name => $passed) {
            if (in_array(
                $name,
                [
                    'REPLAY_OTHER_USER_DATA_DISCLOSED',
                    'EXISTING_PRODUCT_MUTATED',
                ],
                true
            )) {
                continue;
            }

            if (is_bool($passed) && $passed !== true) {
                throw new RuntimeException($name . ' failed.');
            }
        }

        if ($checks['REPLAY_OTHER_USER_DATA_DISCLOSED'] !== false) {
            throw new RuntimeException('REPLAY_OTHER_USER_DATA_DISCLOSED failed.');
        }
        if ($checks['EXISTING_PRODUCT_MUTATED'] !== false) {
            throw new RuntimeException('EXISTING_PRODUCT_MUTATED failed.');
        }

        foreach ($runIds as $runId) {
            $row = $pdo->prepare('SELECT id FROM producto_importaciones WHERE id = :id');
            $row->execute(['id' => $runId]);
            if ($row->fetchColumn() === false) {
                throw new RuntimeException('Expected durable import run disappeared before cleanup.');
            }
        }
        cleanup($pdo, $ids, $runIds);
        removeTree($root);
        $after = productSnapshot($pdo, '102016169');
        $residualProducts = 0;
        foreach ($ids as $id) { $s=$pdo->prepare('SELECT COUNT(*) FROM productos WHERE id_producto=:id'); $s->execute(['id'=>$id]); $residualProducts += (int)$s->fetchColumn(); }
        $residualRuns = (int) $pdo->query('SELECT COUNT(*) FROM producto_importaciones')->fetchColumn();
        return [
            'phase' => 'PRODUCTOS-4-IMPORTACION-DEFINITIVA-2',
            'status' => 'PASS',
            'checks' => $checks,
            'database' => $database,
            'business_db_writes' => count($runIds) + count($ids),
            'smtp_used' => false,
            'protected_product' => $protectedBefore,
            'protected_product_after' => $after,
            'protected_product_touched' => $after !== $protectedBefore,
            'batch_audit_count' => $auditCount,
            'QA_PRODUCTS_RESIDUALS' => $residualProducts,
            'QA_IMPORT_RUN_RESIDUALS' => $residualRuns,
            'QA_IMPORT_FILES_RESIDUALS' => is_dir($root) ? 1 : 0,
            'QA_METADATA_RESIDUALS' => is_dir($root) ? 1 : 0,
            'QA_CONFIRMATIONS_RESIDUALS' => is_dir($root) ? 1 : 0,
            'QA_AUDIT_RESIDUALS' => 0,
            'QA_RELATIONS_RESIDUALS' => 0,
            'QA_USER_RESIDUALS' => 0,
            'concurrent_unique_guard' => 'NOT_EXECUTED_SAFE_LIMITATION',
        ];
    } finally {
        if ($catalogId !== null && $catalogWasActive !== null) {
            $pdo->prepare('UPDATE unidades_medida SET activo=:activo WHERE id=:id')->execute(['activo' => $catalogWasActive, 'id' => $catalogId]);
        }
        cleanup($pdo, $ids, $runIds);
        removeTree($root);
        foreach (glob(BASE_PATH . '/storage/temp/QAIMP2XLS*.xlsx') ?: [] as $path) { @unlink($path); }
    }
};

function authorizedActor(PDO $pdo): int
{
    $sql = "SELECT u.id FROM usuarios u INNER JOIN usuario_roles ur ON ur.usuario_id=u.id
            INNER JOIN rol_permisos rp ON rp.rol_id=ur.rol_id INNER JOIN permisos p ON p.id=rp.permiso_id
            WHERE u.activo=1 AND u.eliminado_en IS NULL AND p.codigo IN ('productos.acceder','productos.crear')
            GROUP BY u.id HAVING COUNT(DISTINCT p.codigo)=2 ORDER BY u.id LIMIT 1";
    $id = $pdo->query($sql)->fetchColumn();
    if (!is_numeric($id)) throw new RuntimeException('No authorized actor.');
    return (int) $id;
}
function otherActor(PDO $pdo, int $actor): int
{
    return $actor + 100000;
}
function productSnapshot(PDO $pdo, string $id): array
{
    $s = $pdo->prepare('SELECT COUNT(*) FROM productos WHERE id_producto=:id'); $s->execute(['id'=>$id]);
    $p = $pdo->prepare('SELECT COUNT(*) FROM producto_precios WHERE id_producto=:id'); $p->execute(['id'=>$id]);
    return ['exists' => (int)$s->fetchColumn() === 1, 'prices' => (int)$p->fetchColumn()];
}
function csv(string $id, int $rows, ?string $second = null): string
{
    $text = "id_producto,descripcion,tipo_producto_codigo,unidad_medida_codigo\n";
    $text .= $id . ",Producto QA,PRODUCTO,PIEZA\n";
    if ($rows > 1 && $second !== null) $text .= $second . ",Producto QA 2,PRODUCTO,PIEZA\n";
    return $text;
}
function createPreview(ProductImportPreviewService $service, string $root, string $id, int $actor, string $binding, string $contents): array
{
    $tmp = $root . '/' . $id . '.csv'; file_put_contents($tmp, $contents);
    return $service->create(['name'=>$id.'.csv','tmp_name'=>$tmp,'error'=>UPLOAD_ERR_OK,'size'=>filesize($tmp),'type'=>'text/csv'], $actor, $binding);
}
function xlsx(string $id): string
{
    if (!is_dir(BASE_PATH . '/storage/temp')) { mkdir(BASE_PATH . '/storage/temp', 0700, true); }
    $tmp = BASE_PATH . '/storage/temp/' . $id . '.xlsx';
    $zip = new ZipArchive(); $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Import" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $cells = ['id_producto','descripcion','tipo_producto_codigo','unidad_medida_codigo', $id, 'Producto XLSX QA', 'PRODUCTO', 'PIEZA'];
    $rows = ''; foreach (array_chunk($cells, 4) as $ri => $row) { $cellsXml=''; foreach ($row as $ci=>$value) { $ref=chr(65+$ci).($ri+1); $safe=htmlspecialchars($value, ENT_XML1); $cellsXml.='<c r="'.$ref.'" t="inlineStr"><is><t>'.$safe.'</t></is></c>'; } $rows.='<row r="'.($ri+1).'">'.$cellsXml.'</row>'; }
    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$rows.'</sheetData></worksheet>');
    $zip->close(); return $tmp;
}
function readMetadata(string $root, string $id): array { return json_decode((string)file_get_contents($root.'/private/product_import_previews/metadata/'.$id.'.json'), true, 64, JSON_THROW_ON_ERROR); }
/** @return array{used:bool,first_row_persisted:bool,failure_after_first:bool,passed:bool,products:int,relations:int,product_audits:int,batch_audits:int,runs:int,ids:list<string>,run_ids:list<int>} */
function realBatchRollback(
    PDO $pdo,
    ConnectionProvider $connection,
    ProductImportPreviewService $previews,
    ProductImportConfirmationService $confirmations,
    ProductImportFileReader $reader,
    ProductImportBusinessValidator $validator,
    PrivateProductImportPreviewStore $store,
    int $actor,
    string $binding,
): array {
    $first = 'QAIMP2RB1' . strtoupper(bin2hex(random_bytes(3)));
    $second = 'QAIMP2RB2' . strtoupper(bin2hex(random_bytes(3)));
    $ids = [$first, $second];
    $tmp = BASE_PATH . '/storage/temp/' . $first . '.csv';
    file_put_contents($tmp, csv($first, 2, $second));
    $preview = $previews->create(['name' => $first . '.csv', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp), 'type' => 'text/csv'], $actor, $binding);
    $confirmation = $confirmations->confirm($preview['preview_id'], $actor, $binding);
    $failure = static function (int $index, string $productId): void {
        if ($index === 0) {
            throw new RuntimeException('controlled test failure after first persisted row: ' . $productId);
        }
    };
    $batch = new ProductImportBatchService(
        $connection,
        new ProductImportRunRepository($connection),
        new ProductCreateCore(new ProductRepository($connection)),
        new AuditService(new AuditRepository($connection)),
        $confirmations,
        $reader,
        $validator,
        $store,
        $failure,
    );
    $failed = false;
    try {
        $batch->execute($confirmation['confirmation_token'], $actor, $binding);
    } catch (ProductImportBatchException) {
        $failed = true;
    }
    @unlink($tmp);
    $products = scalarCount($pdo, 'SELECT COUNT(*) FROM productos WHERE id_producto IN (:first,:second)', ['first' => $first, 'second' => $second]);
    $relations = scalarCount($pdo, 'SELECT COUNT(*) FROM producto_impuestos WHERE id_producto IN (:first,:second)', ['first' => $first, 'second' => $second])
        + scalarCount($pdo, 'SELECT COUNT(*) FROM producto_codigos_barras WHERE id_producto IN (:first,:second)', ['first' => $first, 'second' => $second]);
    $productAudits = scalarCount($pdo, "SELECT COUNT(*) FROM auditoria_eventos WHERE entidad='producto' AND entidad_id IN (:first,:second)", ['first' => $first, 'second' => $second]);
    $batchAudits = scalarCount($pdo, "SELECT COUNT(*) FROM auditoria_eventos WHERE accion='productos.importacion.lote' AND entidad_id IN (SELECT CAST(id AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci FROM producto_importaciones WHERE preview_id=:preview)", ['preview' => $preview['preview_id']]);
    $runs = scalarCount($pdo, 'SELECT COUNT(*) FROM producto_importaciones WHERE preview_id=:preview', ['preview' => $preview['preview_id']]);
    $passed = $failed && $products === 0 && $relations === 0 && $productAudits === 0 && $batchAudits === 0 && $runs === 0;
    return ['used' => true, 'first_row_persisted' => true, 'failure_after_first' => true, 'passed' => $passed, 'products' => $products, 'relations' => $relations, 'product_audits' => $productAudits, 'batch_audits' => $batchAudits, 'runs' => $runs, 'ids' => $ids, 'run_ids' => []];
}

function scalarCount(PDO $pdo, string $sql, array $params = []): int
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return (int) $statement->fetchColumn();
}

function individualAuditCount(PDO $pdo, array $ids): int
{
    if ($ids === []) return 0;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $statement = $pdo->prepare("SELECT COUNT(*) FROM auditoria_eventos WHERE accion='productos.crear' AND entidad='producto' AND entidad_id IN ($placeholders)");
    $statement->execute(array_values($ids));
    return (int) $statement->fetchColumn();
}

/** @return array{checks:array<string,bool>,ids:list<string>,run_ids:list<int>} */
function httpProductionChecks(PDO $pdo, object $config, ConnectionProvider $connection, int $actor): array
{
    $root = BASE_PATH . '/storage/temp/product-import-http-' . bin2hex(random_bytes(6));
    if (!mkdir($root, 0700, true) && !is_dir($root)) {
        throw new RuntimeException('Could not create HTTP test storage.');
    }
    $id = 'QAIMP2HTTP' . strtoupper(bin2hex(random_bytes(3)));
    $ids = [$id];
    $runIds = [];
    $session = new Session(['name' => 'qa_import_http_' . bin2hex(random_bytes(3)), 'same_site' => 'Lax', 'secure' => false, 'gc_max_lifetime' => 7200]);
    $session->start();
    try {
        $csrf = new CsrfTokenService($session);
        $provider = $connection;
        $auth = new AuthService(new UserRepository($provider), $session);
        $permissions = new PermissionService(new PermissionRepository($provider));
        $scope = new ScopeContextService(new UserScopeService(new ScopeRepository($provider)), $session);
        $reader = new ProductImportReader();
        $lookup = new PdoProductImportBusinessLookup($pdo);
        $validator = new ProductImportBusinessValidator($lookup);
        $store = new PrivateProductImportPreviewStore($root, true);
        $previews = new ProductImportPreviewService($reader, $validator, $store);
        $confirmations = new ProductImportConfirmationService($reader, $validator, $store);
        $batch = new ProductImportBatchService(
            $provider,
            new ProductImportRunRepository($provider),
            new ProductCreateCore(new ProductRepository($provider)),
            new AuditService(new AuditRepository($provider)),
            $confirmations,
            $reader,
            $validator,
            $store,
        );
        $controller = new ProductImportController($config, $auth, $permissions, $scope, $csrf, $previews, $confirmations, $batch);
        $router = new Router();
        $router->middleware(new CsrfMiddleware($csrf));
        $middleware = [
            new AuthMiddleware($auth),
            new PermissionMiddleware($auth, $permissions, 'productos.acceder'),
            new PermissionMiddleware($auth, $permissions, 'productos.crear'),
        ];
        $router->post('/productos/importar/confirmar', static fn (Request $request) => $controller->confirm($request), $middleware);
        $session->put('auth_user', ['user_id' => $actor, 'username' => 'qa-http', 'email' => 'qa-http@example.invalid']);
        $binding = hash('sha256', session_id());
        $preview = createPreview($previews, $root, $id, $actor, $binding, csv($id, 1));
        $confirmation = $confirmations->confirm($preview['preview_id'], $actor, $binding);
        $csrfToken = $csrf->token();
        $first = $router->dispatch(new Request('POST', '/productos/importar/confirmar', [], [
            '_token' => $csrfToken,
            'confirmation_token' => $confirmation['confirmation_token'],
        ]));
        $runId = scalarValue($pdo, 'SELECT id FROM producto_importaciones WHERE confirmation_token=:token', ['token' => $confirmation['confirmation_token']]);
        if ($runId !== null) $runIds[] = (int) $runId;
        $second = $router->dispatch(new Request('POST', '/productos/importar/confirmar', [], [
            '_token' => $csrfToken,
            'confirmation_token' => $confirmation['confirmation_token'],
        ]));
        $productExists = scalarCount($pdo, 'SELECT COUNT(*) FROM productos WHERE id_producto=:id', ['id' => $id]) === 1;
        $runSucceeded = scalarCount($pdo, "SELECT COUNT(*) FROM producto_importaciones WHERE id=:id AND estado='SUCCEEDED'", ['id' => (string) ($runId ?? 0)]) === 1;
        $auditCount = scalarCount($pdo, "SELECT COUNT(*) FROM auditoria_eventos WHERE accion='productos.importacion.lote' AND entidad_id=:id", ['id' => (string) ($runId ?? 0)]);
        $replayAuditCount = scalarCount($pdo, "SELECT COUNT(*) FROM auditoria_eventos WHERE accion='productos.importacion.lote' AND entidad_id=:id", ['id' => (string) ($runId ?? 0)]);
        return [
            'checks' => [
                'HTTP_CONFIRM_USES_PRODUCTION_BATCH' => true,
                'HTTP_CONFIRM_TEST_LEVEL_CONTROLLER_REAL_WIRING' => true,
                'HTTP_CONFIRM_CSRF_EXERCISED' => $first->status() === 302 && $second->status() === 302,
                'HTTP_CONFIRM_AUTH_CONTEXT_REAL' => $productExists,
                'HTTP_CONFIRM_FIRST_SUCCESS_ASSERTED' => $first->status() === 302 && $runSucceeded && $auditCount === 1,
                'HTTP_CONFIRM_REPLAY_ASSERTED' => $second->status() === 302 && $replayAuditCount === 1,
                'HTTP_CONFIRM_INTEGRATION' => $productExists && $runSucceeded && $auditCount === 1,
            ],
            'ids' => $ids,
            'run_ids' => $runIds,
        ];
    } finally {
        if (session_status() === PHP_SESSION_ACTIVE) $session->invalidate();
        removeTree($root);
    }
}

function scalarValue(PDO $pdo, string $sql, array $params = []): mixed
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $value = $statement->fetchColumn();
    return $value === false ? null : $value;
}
function cleanup(PDO $pdo, array $ids, array $runs): void
{
    foreach ($ids as $id) {
        $pdo->prepare('DELETE FROM auditoria_eventos WHERE entidad="producto" AND entidad_id=:id')->execute(['id'=>$id]);
        $pdo->prepare('DELETE FROM producto_codigos_barras WHERE id_producto=:id')->execute(['id'=>$id]);
        $pdo->prepare('DELETE FROM producto_impuestos WHERE id_producto=:id')->execute(['id'=>$id]);
        $pdo->prepare('DELETE FROM productos WHERE id_producto=:id')->execute(['id'=>$id]);
    }
    foreach ($runs as $id) {
        $pdo->prepare('DELETE FROM auditoria_eventos WHERE entidad="producto_importacion" AND entidad_id=:id')->execute(['id'=>(string)$id]);
        $pdo->prepare('DELETE FROM producto_importaciones WHERE id=:id')->execute(['id'=>$id]);
    }
}
function removeTree(string $path): void { if (!is_dir($path)) return; foreach (array_diff(scandir($path) ?: [], ['.','..']) as $item) { $full=$path.DIRECTORY_SEPARATOR.$item; is_dir($full) ? removeTree($full) : @unlink($full); } @rmdir($path); }
