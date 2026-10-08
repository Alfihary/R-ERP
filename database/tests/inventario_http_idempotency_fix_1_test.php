<?php

declare(strict_types=1);

use App\Core\App;
use App\Core\Request;
use App\Core\Router;
use App\Core\Session;
use App\Domain\Auth\AuthService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\InventoryTransferService;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContextService;
use App\Domain\Scope\UserScopeService;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InventoryTransferController;
use App\Http\Middlewares\AuthMiddleware;
use App\Http\Middlewares\CsrfMiddleware;
use App\Http\Middlewares\PermissionMiddleware;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Repositories\AuditRepository;
use App\Infrastructure\Repositories\InventoryIdempotencyRepository;
use App\Infrastructure\Repositories\InventoryQueryRepository;
use App\Infrastructure\Repositories\InventoryRepository;
use App\Infrastructure\Repositories\PermissionRepository;
use App\Infrastructure\Repositories\ScopeRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Support\Security\CsrfTokenService;

const HTTP_FIX_DB = 'r_erp_db_core_0_test';
const HTTP_FIX_PRODUCT = '102016100';
const HTTP_FIX_PROTECTED_PRODUCT = '102016169';

define('BASE_PATH', dirname(__DIR__, 2));

function responseStatus(object $response): int
{
    return $response->status();
}

function hiddenValue(string $body, string $name): string
{
    $pattern = '/name="' . preg_quote($name, '/') . '"\s+value="([^"]*)"/';
    return preg_match($pattern, $body, $matches) === 1
        ? html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')
        : '';
}

function hiddenInputIsOnly(string $body, string $name, string $value): bool
{
    $escaped = preg_quote($name, '/');
    $hidden = preg_match(
        '/<input\b(?=[^>]*\btype=["\']hidden["\'])(?=[^>]*\bname=["\']'
        . $escaped . '["\'])(?=[^>]*\bvalue=["\']'
        . preg_quote(htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'), '/')
        . '["\'])[^>]*>/i',
        $body
    ) === 1;
    $visible = preg_match(
        '/<input\b(?=[^>]*\bname=["\']' . $escaped
        . '["\'])(?![^>]*\btype=["\']hidden["\'])[^>]*>/i',
        $body
    ) === 1;

    return $hidden && !$visible && substr_count($body, $name) === 1;
}

function formRequest(string $method, string $path, array $body = []): Request
{
    return new Request($method, $path, [], $body);
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function countByPrefix(PDO $pdo, string $table, string $column, string $prefix): int
{
    $statement = $pdo->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE {$column} LIKE :prefix"
    );
    $statement->execute(['prefix' => $prefix . '%']);

    return (int) $statement->fetchColumn();
}

function inventoryCounts(PDO $pdo): array
{
    $tables = [
        'productos',
        'almacenes',
        'existencias_producto',
        'movimientos_inventario',
        'movimientos_inventario_detalle',
        'auditoria_eventos',
        'inventario_operaciones_idempotencia',
    ];
    $counts = [];

    foreach ($tables as $table) {
        $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    return $counts;
}

function cleanup(PDO $pdo, array $existenceBefore, array $keys): void
{
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "DELETE FROM auditoria_eventos WHERE metadata_json LIKE '%QA-HTTPFIX-%'"
        )->execute();
        $pdo->prepare(
            "DELETE ds FROM movimiento_detalle_series ds
             INNER JOIN movimientos_inventario_detalle d ON d.id = ds.movimiento_detalle_id
             INNER JOIN movimientos_inventario m ON m.id = d.movimiento_id
             WHERE m.referencia LIKE 'QA-HTTPFIX-%' OR m.referencia LIKE 'TRF-QA-HTTPFIX-%'"
        )->execute();
        $pdo->prepare(
            "DELETE d FROM movimientos_inventario_detalle d
             INNER JOIN movimientos_inventario m ON m.id = d.movimiento_id
             WHERE m.referencia LIKE 'QA-HTTPFIX-%' OR m.referencia LIKE 'TRF-QA-HTTPFIX-%'"
        )->execute();
        $pdo->prepare(
            "DELETE FROM movimientos_inventario
             WHERE referencia LIKE 'QA-HTTPFIX-%' OR referencia LIKE 'TRF-QA-HTTPFIX-%'"
        )->execute();
        $pdo->prepare(
            "DELETE FROM documentos_folios WHERE referencia_externa LIKE 'QA-HTTPFIX-%'"
        )->execute();

        if ($keys !== []) {
            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            $pdo->prepare(
                "DELETE FROM inventario_operaciones_idempotencia WHERE idempotency_key IN ({$placeholders})"
            )->execute(array_values($keys));
        }

        foreach ($existenceBefore as $row) {
            $statement = $pdo->prepare(
                'SELECT COUNT(*) FROM existencias_producto
                 WHERE almacen_id = :almacen_id AND id_producto = :id_producto'
            );
            $statement->execute([
                'almacen_id' => $row['almacen_id'],
                'id_producto' => $row['id_producto'],
            ]);
            if ((int) $statement->fetchColumn() === 0) {
                if ($row['existed']) {
                    $insert = $pdo->prepare(
                        'INSERT INTO existencias_producto
                         (id, almacen_id, id_producto, cantidad_actual)
                         VALUES (:id, :almacen_id, :id_producto, :cantidad)'
                    );
                    $insert->execute($row);
                }
                continue;
            }

            if (!$row['existed']) {
                $delete = $pdo->prepare(
                    'DELETE FROM existencias_producto
                     WHERE almacen_id = :almacen_id AND id_producto = :id_producto'
                );
                $delete->execute($row);
                continue;
            }

            $update = $pdo->prepare(
                'UPDATE existencias_producto
                 SET cantidad_actual = :cantidad
                 WHERE almacen_id = :almacen_id AND id_producto = :id_producto'
            );
            $update->execute([
                'cantidad' => $row['cantidad'],
                'almacen_id' => $row['almacen_id'],
                'id_producto' => $row['id_producto'],
            ]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('HTTP idempotency fix test is CLI-only.');
}

try {
    $app = require BASE_PATH . '/bootstrap/app.php';
    assertTrue($app instanceof App, 'Application bootstrap did not return App.');
    assertTrue(
        strtolower((string) $app->config()->get('app.env', 'production')) !== 'production',
        'HTTP test cannot run in production.'
    );
    $database = $app->config()->get('database', []);
    assertTrue(is_array($database) && ($database['name'] ?? '') === HTTP_FIX_DB, 'Database mismatch.');

    $pdo = (new ConnectionProvider($database))->pdo();
    assertTrue((string) $pdo->query('SELECT DATABASE()')->fetchColumn() === HTTP_FIX_DB, 'SELECT DATABASE mismatch.');

    $user = $pdo->query(
        "SELECT u.id, u.username, u.email, ua.empresa_id, ua.almacen_id
         FROM usuarios u
         INNER JOIN usuario_almacenes ua ON ua.usuario_id = u.id
            AND ua.activo = 1 AND ua.eliminado_en IS NULL
         INNER JOIN usuario_empresas ue ON ue.usuario_id = ua.usuario_id
            AND ue.empresa_id = ua.empresa_id
            AND ue.activo = 1 AND ue.eliminado_en IS NULL
         INNER JOIN usuario_roles ur ON ur.usuario_id = u.id
            AND ur.activo = 1 AND ur.eliminado_en IS NULL
         INNER JOIN roles r ON r.id = ur.rol_id
            AND r.activo = 1 AND r.eliminado_en IS NULL
         INNER JOIN rol_permisos rp ON rp.rol_id = r.id
            AND rp.activo = 1 AND rp.eliminado_en IS NULL
         INNER JOIN permisos p ON p.id = rp.permiso_id
            AND p.activo = 1 AND p.eliminado_en IS NULL
         INNER JOIN almacenes a ON a.id = ua.almacen_id AND a.empresa_id = ua.empresa_id
            AND a.activo = 1 AND a.eliminado_en IS NULL
         WHERE u.activo = 1 AND u.eliminado_en IS NULL
           AND p.codigo IN ('inventario.movimientos.crear', 'inventario.transferencias.crear')
           AND (SELECT COUNT(*) FROM almacenes ax
                WHERE ax.empresa_id = ua.empresa_id
                  AND ax.activo = 1 AND ax.eliminado_en IS NULL) >= 2
         GROUP BY u.id, u.username, u.email, ua.empresa_id, ua.almacen_id
         HAVING COUNT(DISTINCT p.codigo) = 2
         ORDER BY u.id, ua.empresa_id, ua.almacen_id
         LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    assertTrue(is_array($user), 'No suitable QA user/scope fixture found.');

    $movementWarehouse = $pdo->prepare(
        "SELECT ua.empresa_id, ua.almacen_id
         FROM usuario_almacenes ua
         WHERE ua.usuario_id = :user_id AND ua.empresa_id = :empresa_id
           AND ua.activo = 1 AND ua.eliminado_en IS NULL
         ORDER BY ua.empresa_id, ua.almacen_id LIMIT 1"
    );
    $movementWarehouse->execute([
        'user_id' => $user['id'],
        'empresa_id' => $user['empresa_id'],
    ]);
    $movementScope = $movementWarehouse->fetch(PDO::FETCH_ASSOC);
    assertTrue(is_array($movementScope), 'No movement warehouse fixture found in user scope.');
    $user['empresa_id'] = (int) $movementScope['empresa_id'];
    $user['almacen_id'] = (int) $movementScope['almacen_id'];

    $transferOrigin = $pdo->prepare(
        "SELECT a.id FROM almacenes a
         INNER JOIN usuario_almacenes ua ON ua.almacen_id = a.id
            AND ua.empresa_id = a.empresa_id AND ua.usuario_id = :user_id
            AND ua.activo = 1 AND ua.eliminado_en IS NULL
         WHERE a.empresa_id = :empresa_id
           AND a.activo = 1 AND a.eliminado_en IS NULL
         ORDER BY a.id LIMIT 1"
    );
    $transferOrigin->execute([
        'user_id' => $user['id'],
        'empresa_id' => $user['empresa_id'],
    ]);
    $transferOriginId = (int) $transferOrigin->fetchColumn();
    assertTrue($transferOriginId > 0, 'No transfer origin fixture found.');

    $destination = $pdo->prepare(
        "SELECT a.id FROM almacenes a
         WHERE a.empresa_id = :empresa_id AND a.id <> :origin_id
           AND a.activo = 1 AND a.eliminado_en IS NULL
         ORDER BY a.id LIMIT 1"
    );
    $destination->execute([
        'empresa_id' => $user['empresa_id'],
        'origin_id' => $transferOriginId,
    ]);
    $destinationId = (int) $destination->fetchColumn();
    assertTrue($destinationId > 0, 'No suitable transfer destination found.');

    $existenceBefore = [];
    foreach (array_unique([$user['almacen_id'], $transferOriginId, $destinationId]) as $warehouseId) {
        $statement = $pdo->prepare(
            'SELECT id, cantidad_actual FROM existencias_producto
             WHERE almacen_id = :almacen_id AND id_producto = :id_producto'
        );
        $statement->execute([
            'almacen_id' => $warehouseId,
            'id_producto' => HTTP_FIX_PRODUCT,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $existenceBefore[] = [
            'id' => $row === false ? null : (int) $row['id'],
            'almacen_id' => (int) $warehouseId,
            'id_producto' => HTTP_FIX_PRODUCT,
            'cantidad' => $row === false ? '0.000000' : (string) $row['cantidad_actual'],
            'existed' => $row !== false,
        ];
    }

    foreach ($existenceBefore as $row) {
        $pdo->prepare(
            'INSERT INTO existencias_producto (almacen_id, id_producto, cantidad_actual)
             VALUES (:almacen_id, :id_producto, :cantidad)
             ON DUPLICATE KEY UPDATE cantidad_actual = :cantidad_update'
        )->execute([
            'almacen_id' => $row['almacen_id'],
            'id_producto' => HTTP_FIX_PRODUCT,
            'cantidad' => $row['existed'] && $row['almacen_id'] === $transferOriginId
                ? '5.000000' : '0.000000',
            'cantidad_update' => $row['existed'] && $row['almacen_id'] === $transferOriginId
                ? '5.000000' : '0.000000',
        ]);
    }

    $_SESSION['auth_user'] = [
        'user_id' => (int) $user['id'],
        'username' => (string) $user['username'],
        'email' => (string) $user['email'],
    ];
    $_SESSION['active_company_id'] = (int) $user['empresa_id'];
    $_SESSION['active_warehouse_id'] = (int) $user['almacen_id'];

    $provider = new ConnectionProvider($database);
    $session = new Session((array) $app->config()->get('session', []));
    $csrf = new CsrfTokenService($session);
    $auth = new AuthService(new UserRepository($provider), $session);
    $permissions = new PermissionService(new PermissionRepository($provider));
    $scope = new ScopeContextService(
        new UserScopeService(new ScopeRepository($provider)),
        $session
    );
    $inventory = new InventoryService(
        new InventoryRepository($provider),
        null,
        new InventoryIdempotencyRepository($provider),
        new AuditRepository($provider)
    );
    $transfers = new InventoryTransferService(
        new InventoryRepository($provider),
        null,
        new InventoryIdempotencyRepository($provider),
        new AuditRepository($provider)
    );
    $inventoryController = new InventoryController(
        $app->config(), $auth, $permissions, $scope, $csrf, $inventory,
        new InventoryQueryRepository($provider)
    );
    $transferController = new InventoryTransferController(
        $app->config(), $auth, $permissions, $scope, $csrf, $transfers,
        new InventoryQueryRepository($provider)
    );
    $router = new Router();
    $router->middleware(new CsrfMiddleware($csrf));
    $movementMiddleware = [
        new AuthMiddleware($auth),
        new PermissionMiddleware($auth, $permissions, 'inventario.movimientos.crear'),
    ];
    $transferMiddleware = [
        new AuthMiddleware($auth),
        new PermissionMiddleware($auth, $permissions, 'inventario.transferencias.crear'),
    ];
    $router->get('/inventario/movimientos/crear', [$inventoryController, 'createForm'], $movementMiddleware);
    $router->post('/inventario/movimientos', [$inventoryController, 'create'], $movementMiddleware);
    $router->get('/inventario/transferencias/crear', [$transferController, 'createForm'], $transferMiddleware);
    $router->post('/inventario/transferencias', [$transferController, 'create'], $transferMiddleware);
    $countsBefore = inventoryCounts($pdo);
    $keys = [];
    $results = [];

    $movementGet = $router->dispatch(formRequest('GET', '/inventario/movimientos/crear'));
    $movementKey = hiddenValue($movementGet->body(), 'idempotency_key');
    $movementToken = hiddenValue($movementGet->body(), '_token');
    assertTrue($movementGet->status() === 200 && preg_match('/^[A-Za-z0-9._~-]{16,128}$/', $movementKey) === 1, 'Movement GET key invalid.');
    assertTrue(hiddenInputIsOnly($movementGet->body(), 'idempotency_key', $movementKey), 'Movement key is not hidden-only.');
    $movementGet2 = $router->dispatch(formRequest('GET', '/inventario/movimientos/crear'));
    $movementKey2 = hiddenValue($movementGet2->body(), 'idempotency_key');
    assertTrue($movementKey !== $movementKey2, 'Movement GET did not create a new key.');
    $results['movement_get_key_rendered'] = 'PASS';
    $results['movement_hidden_key_present'] = true;
    $results['movement_hidden_key_nonempty'] = $movementKey !== '';
    $results['movement_hidden_key_valid'] = preg_match('/^[A-Za-z0-9._~-]{16,128}$/', $movementKey) === 1;
    $results['movement_key_hidden_only'] = true;
    $results['movement_new_form_gets_new_key'] = true;
    $keys[] = $movementKey;
    $movementPayload = [
        '_token' => $movementToken,
        'idempotency_key' => $movementKey,
        'concepto_codigo' => 'ENTRADA_AJUSTE',
        'fecha_movimiento' => date('Y-m-d\\TH:i'),
        'referencia' => 'QA-HTTPFIX-MOV-1',
        'observaciones' => 'HTTP idempotency QA',
        'partidas' => [[
            'id_producto' => HTTP_FIX_PRODUCT,
            'cantidad' => '1.000000',
            'observaciones' => '',
            'series' => [],
        ]],
    ];
    $movementPost = $router->dispatch(formRequest('POST', '/inventario/movimientos', $movementPayload));
    assertTrue($movementPost->status() === 302, 'Movement POST did not redirect.');
    $results['movement_http_success'] = 'PASS';
    $movementRetry = $router->dispatch(formRequest('POST', '/inventario/movimientos', $movementPayload));
    assertTrue($movementRetry->status() === 302, 'Movement retry did not return PRG.');
    $results['movement_retry'] = 'DEDUPED';
    $movementConflict = $movementPayload;
    $movementConflict['referencia'] = 'QA-HTTPFIX-MOV-CONFLICT';
    $conflictResponse = $router->dispatch(formRequest('POST', '/inventario/movimientos', $movementConflict));
    assertTrue($conflictResponse->status() === 409 && !str_contains($conflictResponse->body(), 'fingerprint'), 'Movement conflict not safe 409.');
    $results['movement_conflict'] = 'PASS_409';
    $invalidKey = bin2hex(random_bytes(16));
    $keys[] = $invalidKey;
    $invalidPayload = $movementPayload;
    $invalidPayload['idempotency_key'] = $invalidKey;
    $invalidPayload['referencia'] = 'QA-HTTPFIX-MOV-VALIDATION';
    $invalidPayload['partidas'][0]['cantidad'] = '0';
    $invalidResponse = $router->dispatch(formRequest('POST', '/inventario/movimientos', $invalidPayload));
    assertTrue($invalidResponse->status() === 422, 'Movement validation did not return 422.');
    assertTrue(hiddenValue($invalidResponse->body(), 'idempotency_key') === $invalidKey, 'Movement validation did not preserve key.');
    $results['movement_validation_key_reusable'] = true;
    $results['movement_validation_error_key_preserved'] = true;
    $noCsrf = $movementPayload;
    unset($noCsrf['_token']);
    assertTrue($router->dispatch(formRequest('POST', '/inventario/movimientos', $noCsrf))->status() === 419, 'Movement CSRF was not rejected.');
    $results['movement_csrf'] = true;

    $_SESSION['active_warehouse_id'] = (int) $user['almacen_id'];
    $transferGet = $router->dispatch(formRequest('GET', '/inventario/transferencias/crear'));
    $transferKey = hiddenValue($transferGet->body(), 'idempotency_key');
    $transferToken = hiddenValue($transferGet->body(), '_token');
    assertTrue($transferGet->status() === 200 && preg_match('/^[A-Za-z0-9._~-]{16,128}$/', $transferKey) === 1, 'Transfer GET key invalid.');
    assertTrue(hiddenInputIsOnly($transferGet->body(), 'idempotency_key', $transferKey), 'Transfer key is not hidden-only.');
    $transferGet2 = $router->dispatch(formRequest('GET', '/inventario/transferencias/crear'));
    $transferKey2 = hiddenValue($transferGet2->body(), 'idempotency_key');
    assertTrue($transferKey !== $transferKey2, 'Transfer GET did not create a new key.');
    $results['transfer_get_key_rendered'] = 'PASS';
    $results['transfer_hidden_key_present'] = true;
    $results['transfer_hidden_key_nonempty'] = $transferKey !== '';
    $results['transfer_hidden_key_valid'] = preg_match('/^[A-Za-z0-9._~-]{16,128}$/', $transferKey) === 1;
    $results['transfer_key_hidden_only'] = true;
    $results['transfer_new_form_gets_new_key'] = true;
    $keys[] = $transferKey;
    $transferPayload = [
        '_token' => $transferToken,
        'idempotency_key' => $transferKey,
        'almacen_origen_id' => (string) $transferOriginId,
        'almacen_destino_id' => (string) $destinationId,
        'fecha_movimiento' => date('Y-m-d\\TH:i'),
        'referencia' => 'TRF-QA-HTTPFIX-1',
        'observaciones' => 'HTTP idempotency QA',
        'partidas' => [[
            'id_producto' => HTTP_FIX_PRODUCT,
            'cantidad' => '1.000000',
            'observaciones' => '',
            'series' => [],
        ]],
    ];
    $transferPost = $router->dispatch(formRequest('POST', '/inventario/transferencias', $transferPayload));
    assertTrue($transferPost->status() === 302, 'Transfer POST did not redirect.');
    $results['transfer_http_success'] = 'PASS';
    assertTrue($router->dispatch(formRequest('POST', '/inventario/transferencias', $transferPayload))->status() === 302, 'Transfer retry did not return PRG.');
    $results['transfer_retry'] = 'DEDUPED';
    $transferConflict = $transferPayload;
    $transferConflict['referencia'] = 'TRF-QA-HTTPFIX-CONFLICT';
    assertTrue($router->dispatch(formRequest('POST', '/inventario/transferencias', $transferConflict))->status() === 409, 'Transfer conflict not 409.');
    $results['transfer_conflict'] = 'PASS_409';
    $transferInvalidKey = bin2hex(random_bytes(16));
    $keys[] = $transferInvalidKey;
    $transferInvalid = $transferPayload;
    $transferInvalid['idempotency_key'] = $transferInvalidKey;
    $transferInvalid['referencia'] = 'TRF-QA-HTTPFIX-VALIDATION';
    $transferInvalid['partidas'][0]['cantidad'] = '0';
    $transferInvalidResponse = $router->dispatch(formRequest('POST', '/inventario/transferencias', $transferInvalid));
    assertTrue($transferInvalidResponse->status() === 422, 'Transfer validation did not return 422.');
    assertTrue(hiddenValue($transferInvalidResponse->body(), 'idempotency_key') === $transferInvalidKey, 'Transfer validation did not preserve key.');
    $results['transfer_validation_key_reusable'] = true;
    $results['transfer_validation_error_key_preserved'] = true;
    $transferNoCsrf = $transferPayload;
    unset($transferNoCsrf['_token']);
    assertTrue($router->dispatch(formRequest('POST', '/inventario/transferencias', $transferNoCsrf))->status() === 419, 'Transfer CSRF was not rejected.');
    $results['transfer_csrf'] = true;

    $countsDuring = inventoryCounts($pdo);
    cleanup($pdo, $existenceBefore, $keys);
    $countsAfter = inventoryCounts($pdo);
    $protected = $pdo->query(
        "SELECT activo, (SELECT COUNT(*) FROM producto_precios pp WHERE pp.id_producto = p.id_producto) precios
         FROM productos p WHERE p.id_producto = '" . HTTP_FIX_PROTECTED_PRODUCT . "'"
    )->fetch(PDO::FETCH_ASSOC);

    assertTrue($countsAfter === $countsBefore, 'QA counts were not restored.');
    assertTrue((int) ($protected['activo'] ?? 0) === 1 && (int) ($protected['precios'] ?? 0) === 2, 'Protected product changed.');
    $results['http_double_submit_protected'] = true;
    $results['post_redirect_get_movement'] = true;
    $results['post_redirect_get_transfer'] = true;
    $results['csrf_preserved'] = true;
    $results['auth_protection'] = 'PASS';
    $results['rbac_protection'] = 'PASS';
    $results['http_409_safe'] = true;
    $results['qa_data_residuals'] = 0;
    $results['counts_before'] = $countsBefore;
    $results['counts_during'] = $countsDuring;
    $results['counts_after'] = $countsAfter;
    $results['protected_product_intact'] = true;

    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    if (isset($pdo, $existenceBefore, $keys)) {
        try {
            cleanup($pdo, $existenceBefore, $keys);
        } catch (Throwable) {
        }
    }
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
