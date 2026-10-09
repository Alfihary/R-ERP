<?php

declare(strict_types=1);

use App\Core\Session;
use App\Domain\Audit\AuditRecorderInterface;
use App\Domain\Audit\AuditService;
use App\Domain\Auth\AuthService;
use App\Domain\Users\UserAdminConflictException;
use App\Domain\Users\UserAdminService;
use App\Domain\Users\UserAdminValidationException;
use App\Infrastructure\Database\Connection;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Repositories\AuditRepository;
use App\Infrastructure\Repositories\RoleRepository;
use App\Infrastructure\Repositories\ScopeRepository;
use App\Infrastructure\Repositories\UserRepository;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

define('BASE_PATH', dirname(__DIR__, 2));
require BASE_PATH . '/bootstrap/autoload.php';

function option(string $name): string
{
    foreach (array_slice($GLOBALS['argv'], 1) as $argument) {
        if (str_starts_with($argument, '--' . $name . '=')) {
            return substr($argument, strlen($name) + 3);
        }
    }
    return '';
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function throws(callable $operation, string $type): void
{
    try {
        $operation();
    } catch (Throwable $exception) {
        check($exception instanceof $type, 'Unexpected exception type: ' . $exception::class);
        return;
    }
    throw new RuntimeException('Expected exception was not raised: ' . $type);
}

function guardBaselineTarget(int $targetId, int $baselineId, string $expectedOutcome): void
{
    if ($targetId === $baselineId && $expectedOutcome !== 'REJECT') {
        throw new RuntimeException('Destructive baseline target is forbidden in this harness.');
    }
}

$expectedDatabase = option('database');
$confirmedDatabase = option('confirm-database');
if ($expectedDatabase !== 'r_erp_db_core_0_test' || $confirmedDatabase !== $expectedDatabase) {
    throw new RuntimeException('The DB-TEST requires the exact confirmed test database.');
}

$config = require BASE_PATH . '/bootstrap/database.php';
$environment = strtolower((string) $config->get('app.env', 'production'));
if ($environment === 'production') {
    throw new RuntimeException('The users backend DB-TEST is disabled in production.');
}
$databaseConfig = $config->get('database', []);
if (!is_array($databaseConfig) || (string) ($databaseConfig['name'] ?? '') !== $expectedDatabase) {
    throw new RuntimeException('Configured database does not match the confirmed test database.');
}

$pdo = Connection::create($databaseConfig);
check((string) $pdo->query('SELECT DATABASE()')->fetchColumn() === $expectedDatabase, 'Wrong active database.');
$connection = new ConnectionProvider($databaseConfig);
$users = new UserRepository($connection);
$roles = new RoleRepository($connection);
$scope = new ScopeRepository($connection);
$auditRepository = new AuditRepository($connection);
$audit = new AuditService($auditRepository);
$service = new UserAdminService($users, $roles, $scope, $audit);

$permissionBefore = (int) $pdo->query('SELECT COUNT(*) FROM permisos WHERE activo = 1 AND eliminado_en IS NULL')->fetchColumn();
$assignmentBefore = (int) $pdo->query('SELECT COUNT(*) FROM rol_permisos WHERE activo = 1 AND eliminado_en IS NULL')->fetchColumn();
$permissionAfter = null;
$assignmentAfter = null;
$seed = require BASE_PATH . '/database/seeds/rbac_0_seed_base_permissions.php';
$seed->run($pdo);
$permissionAfter = (int) $pdo->query('SELECT COUNT(*) FROM permisos WHERE activo = 1 AND eliminado_en IS NULL')->fetchColumn();
$assignmentAfter = (int) $pdo->query('SELECT COUNT(*) FROM rol_permisos WHERE activo = 1 AND eliminado_en IS NULL')->fetchColumn();

$baseline = $pdo->query(
    "SELECT u.id FROM usuarios u
     INNER JOIN usuario_roles ur ON ur.usuario_id = u.id AND ur.activo = 1 AND ur.eliminado_en IS NULL
     INNER JOIN roles r ON r.id = ur.rol_id AND r.codigo = 'ADMIN' AND r.activo = 1 AND r.eliminado_en IS NULL
     WHERE u.activo = 1 AND u.eliminado_en IS NULL ORDER BY u.id LIMIT 1"
)->fetchColumn();
check($baseline !== false, 'A usable ADMIN baseline is required.');
$baseline = (int) $baseline;
$baselineSnapshot = $pdo->prepare(
    "SELECT u.activo, u.eliminado_en, ur.activo AS role_active
     FROM usuarios u INNER JOIN usuario_roles ur ON ur.usuario_id = u.id
     INNER JOIN roles r ON r.id = ur.rol_id AND r.codigo = 'ADMIN'
     WHERE u.id = :id LIMIT 1"
);
$baselineSnapshot->execute(['id' => $baseline]);
$baselineBefore = $baselineSnapshot->fetch();

$company = $pdo->query('SELECT id FROM empresas WHERE activo = 1 AND eliminado_en IS NULL ORDER BY id LIMIT 1')->fetchColumn();
$warehouse = $pdo->query('SELECT id, empresa_id FROM almacenes WHERE activo = 1 AND eliminado_en IS NULL ORDER BY id LIMIT 1')->fetch();
check($company !== false && is_array($warehouse) && (int) $warehouse['empresa_id'] === (int) $company, 'An active company/warehouse fixture is required.');
$companyId = (int) $company;
$warehouseId = (int) $warehouse['id'];
$crossCompany = $pdo->prepare(
    'SELECT e.id AS company_id, a.id AS warehouse_id
     FROM empresas e
     INNER JOIN almacenes a ON a.empresa_id = e.id
        AND a.activo = 1 AND a.eliminado_en IS NULL
     WHERE e.activo = 1 AND e.eliminado_en IS NULL AND e.id <> :company_id
     ORDER BY e.id, a.id LIMIT 1'
);
$crossCompany->execute(['company_id' => $companyId]);
$crossCompany = $crossCompany->fetch();
check(is_array($crossCompany), 'A second active company with its own warehouse is required for cross-scope tests.');
$crossCompanyId = (int) $crossCompany['company_id'];
$crossWarehouseId = (int) $crossCompany['warehouse_id'];

$suffix = bin2hex(random_bytes(4));
$adminA = 'qa.admin-a-' . $suffix;
$adminB = 'qa.admin-b-' . $suffix;
$currentQaUsername = 'qa.user-' . $suffix;
$currentQaEmail = $currentQaUsername . '@example.test';
$password = 'QA-Admin-Temporary-2026!';
$roleCode = 'QA_USER_' . strtoupper($suffix);
$pdo->prepare(
    'INSERT INTO roles (codigo, nombre, descripcion, es_sistema, activo, creado_por, actualizado_por)
     VALUES (:codigo, :nombre, :description, 0, 1, :actor_created, :actor_updated)'
)->execute([
    'codigo' => $roleCode, 'nombre' => 'QA User Role', 'description' => 'Temporary DB-TEST role.',
    'actor_created' => $baseline, 'actor_updated' => $baseline,
]);
$qaRoleId = (int) $pdo->lastInsertId();
$created = [];

$rollbackUsername = 'qa.rollback-' . $suffix;
$rollbackAudit = new class implements AuditRecorderInterface {
    public function recordRequired(string $action, ?int $actorUserId, array $metadata = []): void
    {
        throw new RuntimeException('Intentional post-mutation audit failure for rollback harness.');
    }
};
$rollbackService = new UserAdminService($users, $roles, $scope, $rollbackAudit);
$rollbackAuditBefore = (int) $pdo->query("SELECT COUNT(*) FROM auditoria_eventos WHERE entidad = 'usuarios'")->fetchColumn();
throws(fn () => $rollbackService->create([
    'username' => $rollbackUsername,
    'email' => $rollbackUsername . '@example.test',
    'password' => $password,
    'company_id' => $companyId,
    'warehouse_id' => $warehouseId,
    'roles' => [$qaRoleId],
], $baseline), RuntimeException::class);
$rollbackUser = $users->findByUsername($rollbackUsername);
check($rollbackUser === null, 'Rollback left the QA user persisted.');
$rollbackRoles = $pdo->prepare('SELECT COUNT(*) FROM usuario_roles ur INNER JOIN usuarios u ON u.id = ur.usuario_id WHERE u.username = :username');
$rollbackRoles->execute(['username' => $rollbackUsername]);
check((int) $rollbackRoles->fetchColumn() === 0, 'Rollback left QA user roles persisted.');
$rollbackAuditAfter = (int) $pdo->query("SELECT COUNT(*) FROM auditoria_eventos WHERE entidad = 'usuarios'")->fetchColumn();
check($rollbackAuditAfter === $rollbackAuditBefore, 'Rollback left a success audit event persisted.');

$failure = null;
try {
    $created[] = $service->create([
        'username' => 'Qa.Admin-Test', 'email' => '  QA.Admin.' . $suffix . '@Example.COM ',
        'password' => $password, 'company_id' => $companyId, 'warehouse_id' => $warehouseId,
        'roles' => [$roles->findStructural('ADMIN')['id']],
    ], $baseline);
    $first = $users->findByUsername('qa.admin-test');
    check(is_array($first) && $first['email'] === strtolower('QA.Admin.' . $suffix . '@Example.COM'), 'Canonical create failed.');

    $created[] = $service->create([
        'username' => $adminA, 'email' => $adminA . '@example.test', 'password' => $password,
        'company_id' => $companyId, 'warehouse_id' => $warehouseId,
        'roles' => [$roles->findStructural('ADMIN')['id']],
    ], $baseline);
    $created[] = $service->create([
        'username' => $adminB, 'email' => $adminB . '@example.test', 'password' => $password,
        'company_id' => $companyId, 'warehouse_id' => $warehouseId,
        'roles' => [$roles->findStructural('ADMIN')['id']],
    ], $baseline);
    $created[] = $service->create([
        'username' => $currentQaUsername, 'email' => $currentQaEmail, 'password' => $password,
        'company_id' => $companyId, 'warehouse_id' => $warehouseId, 'roles' => [$qaRoleId],
    ], $baseline);

    throws(fn () => $service->create([
        'username' => $adminA, 'email' => 'unique.' . $suffix . '@example.test', 'password' => $password,
        'company_id' => $companyId, 'warehouse_id' => $warehouseId, 'roles' => [$qaRoleId],
    ], $baseline), UserAdminConflictException::class);
    throws(fn () => $service->create([
        'username' => 'unique.' . $suffix, 'email' => $adminA . '@example.test', 'password' => $password,
        'company_id' => $companyId, 'warehouse_id' => $warehouseId, 'roles' => [$qaRoleId],
    ], $baseline), UserAdminConflictException::class);
    throws(fn () => $service->create([
        'username' => 'invalid.' . $suffix, 'email' => 'invalid.' . $suffix . '@example.test', 'password' => $password,
        'company_id' => 999999999, 'warehouse_id' => $warehouseId, 'roles' => [$qaRoleId],
    ], $baseline), UserAdminValidationException::class);
    throws(fn () => $service->create([
        'username' => 'invalid2.' . $suffix, 'email' => 'invalid2.' . $suffix . '@example.test', 'password' => $password,
        'company_id' => $companyId, 'warehouse_id' => 999999999, 'roles' => [$qaRoleId],
    ], $baseline), UserAdminValidationException::class);
    $crossScopeUsername = 'qa.cross-scope-' . $suffix;
    throws(fn () => $service->create([
        'username' => $crossScopeUsername, 'email' => $crossScopeUsername . '@example.test', 'password' => $password,
        'company_id' => $crossCompanyId, 'warehouse_id' => $warehouseId, 'roles' => [$qaRoleId],
    ], $baseline), UserAdminValidationException::class);
    check($users->findByUsername($crossScopeUsername) === null, 'Cross-company create left a user persisted.');
    throws(fn () => $service->create([
        'username' => 'invalid3.' . $suffix, 'email' => 'invalid3.' . $suffix . '@example.test', 'password' => $password,
        'company_id' => $companyId, 'warehouse_id' => $warehouseId, 'roles' => [999999999],
    ], $baseline), UserAdminValidationException::class);
    throws(fn () => $service->create([
        'username' => 'invalid4.' . $suffix, 'email' => 'invalid4.' . $suffix . '@example.test', 'password' => $password,
        'company_id' => $companyId, 'warehouse_id' => $warehouseId, 'roles' => [],
    ], $baseline), UserAdminValidationException::class);

    $qaUserId = $created[3];
    $service->update($qaUserId, [
        'username' => $currentQaUsername . '-edit', 'email' => '  ' . $currentQaUsername . '.edit@EXAMPLE.TEST ',
        'company_id' => $companyId, 'warehouse_id' => $warehouseId, 'roles' => [$qaRoleId],
    ], $baseline);
    $currentQaUsername .= '-edit';
    $currentQaEmail = $currentQaUsername . '@example.test';
    throws(fn () => $service->update($qaUserId, [
        'username' => $adminA, 'email' => 'new.' . $suffix . '@example.test',
        'company_id' => $companyId, 'warehouse_id' => $warehouseId, 'roles' => [$qaRoleId],
    ], $baseline), UserAdminConflictException::class);
    throws(fn () => $service->update($qaUserId, [
        'username' => $currentQaUsername . '-password', 'email' => 'password.' . $suffix . '@example.test',
        'password' => $password, 'company_id' => $companyId, 'warehouse_id' => $warehouseId, 'roles' => [$qaRoleId],
    ], $baseline), UserAdminValidationException::class);
    $scopeBefore = $pdo->prepare(
        'SELECT empresa_id FROM usuario_empresas WHERE usuario_id = :user_id AND activo = 1 AND eliminado_en IS NULL'
    );
    $scopeBefore->execute(['user_id' => $qaUserId]);
    $companyBefore = (int) $scopeBefore->fetchColumn();
    $warehouseBefore = $pdo->prepare(
        'SELECT almacen_id FROM usuario_almacenes WHERE usuario_id = :user_id AND activo = 1 AND eliminado_en IS NULL'
    );
    $warehouseBefore->execute(['user_id' => $qaUserId]);
    $warehouseBeforeId = (int) $warehouseBefore->fetchColumn();
    throws(fn () => $service->update($qaUserId, [
        'username' => $currentQaUsername . '-cross', 'email' => $currentQaUsername . '.cross@example.test',
        'company_id' => $crossCompanyId, 'warehouse_id' => $warehouseId, 'roles' => [$qaRoleId],
    ], $baseline), UserAdminValidationException::class);
    $scopeBefore->execute(['user_id' => $qaUserId]);
    $companyAfter = (int) $scopeBefore->fetchColumn();
    $warehouseBefore->execute(['user_id' => $qaUserId]);
    $warehouseAfter = (int) $warehouseBefore->fetchColumn();
    check($companyBefore === $companyAfter && $warehouseBeforeId === $warehouseAfter, 'Cross-company update mutated scope.');

    $service->changeStatus($qaUserId, false, $baseline);
    $inactiveSession = new Session(require BASE_PATH . '/config/session.php');
    $inactiveSession->start();
    $inactiveAuth = new AuthService($users, $inactiveSession);
    check($inactiveAuth->attempt($currentQaUsername, $password) === false, 'Inactive user authenticated.');
    $inactiveAuth->logout();
    $service->changeStatus($qaUserId, true, $baseline);
    $service->syncRoles($qaUserId, [$qaRoleId, $qaRoleId], $baseline);
    throws(fn () => $service->syncRoles($qaUserId, [999999999], $baseline), UserAdminValidationException::class);
    throws(fn () => $service->syncRoles($qaUserId, [], $baseline), UserAdminValidationException::class);
    $service->resetPassword($qaUserId, $password . 'x', $password . 'x', $baseline);

    $sessionConfig = require BASE_PATH . '/config/session.php';
    $session = new Session($sessionConfig);
    $session->start();
    $auth = new AuthService($users, $session);
    check($auth->attempt($currentQaUsername, $password) === false, 'Old password authenticated after reset.');
    check($auth->attempt($currentQaUsername, $password . 'x') === true, 'New password failed after reset.');
    $auth->logout();
    $session->start();
    check($auth->attempt('qa.admin-test', $password) === true, 'Username authentication regression failed.');
    check($auth->attempt($first['email'], $password) === true, 'Email authentication regression failed.');
    $auth->logout();

    $service->softDelete($qaUserId, $baseline);
    $session->start();
    check($auth->attempt($currentQaUsername, $password . 'x') === false, 'Soft-deleted user authenticated.');
    $auth->logout();

    // End the multi-admin fixtures before testing the true last-ADMIN invariant.
    $service->softDelete($created[0], $baseline);
    $service->softDelete($created[1], $baseline);
    $service->softDelete($created[2], $baseline);
    $usableAdminCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM usuarios u
         INNER JOIN usuario_roles ur ON ur.usuario_id=u.id AND ur.activo=1 AND ur.eliminado_en IS NULL
         INNER JOIN roles r ON r.id=ur.rol_id AND r.codigo='ADMIN' AND r.activo=1 AND r.eliminado_en IS NULL
         WHERE u.activo=1 AND u.eliminado_en IS NULL"
    )->fetchColumn();
    $onlyAdminId = (int) $pdo->query(
        "SELECT u.id FROM usuarios u
         INNER JOIN usuario_roles ur ON ur.usuario_id=u.id AND ur.activo=1 AND ur.eliminado_en IS NULL
         INNER JOIN roles r ON r.id=ur.rol_id AND r.codigo='ADMIN' AND r.activo=1 AND r.eliminado_en IS NULL
         WHERE u.activo=1 AND u.eliminado_en IS NULL LIMIT 1"
    )->fetchColumn();
    check($usableAdminCount === 1 && $onlyAdminId === $baseline, 'Last ADMIN fixture setup is invalid.');
    guardBaselineTarget($baseline, $baseline, 'REJECT');
    throws(fn () => $service->changeStatus($baseline, false, $baseline), UserAdminConflictException::class);
    guardBaselineTarget($baseline, $baseline, 'REJECT');
    throws(fn () => $service->softDelete($baseline, $baseline), UserAdminConflictException::class);
    guardBaselineTarget($baseline, $baseline, 'REJECT');
    throws(fn () => $service->syncRoles($baseline, [$qaRoleId], $baseline), UserAdminConflictException::class);

    $auditCheck = $pdo->prepare(
        "SELECT COUNT(*) FROM auditoria_eventos
         WHERE entidad = 'usuarios' AND entidad_id IN (" . implode(',', array_fill(0, count($created), '?')) . ")
           AND (metadata_json LIKE '%password%' OR metadata_json LIKE '%hash%')"
    );
    $auditCheck->execute(array_map('strval', $created));
    check((int) $auditCheck->fetchColumn() === 0, 'Password data reached audit metadata.');

    $auditEvents = [
        'usuario.creado',
        'usuario.actualizado',
        'usuario.estado_actualizado',
        'usuario.eliminado',
        'usuario.roles_actualizados',
        'usuario.password_reiniciada',
    ];
    $auditIdNames = array_map(static fn (int $index): string => ':audit_id_' . $index, array_keys($created));
    $auditEventCheck = $pdo->prepare(
        'SELECT COUNT(*) FROM auditoria_eventos
         WHERE entidad = :entity AND entidad_id IN (' . implode(',', $auditIdNames) . ')
           AND accion = :action'
    );
    foreach ($auditEvents as $auditEvent) {
        $params = ['entity' => 'usuarios', 'action' => $auditEvent];
        foreach ($created as $index => $createdId) {
            $params['audit_id_' . $index] = (string) $createdId;
        }
        $auditEventCheck->execute($params);
        check((int) $auditEventCheck->fetchColumn() > 0, 'Missing QA audit event: ' . $auditEvent);
    }

    $counts = [
        'orphan_user_role' => (int) $pdo->query('SELECT COUNT(*) FROM usuario_roles ur LEFT JOIN usuarios u ON u.id=ur.usuario_id WHERE u.id IS NULL')->fetchColumn(),
        'duplicate_user_role' => (int) $pdo->query('SELECT COUNT(*) FROM (SELECT usuario_id,rol_id,COUNT(*) c FROM usuario_roles GROUP BY usuario_id,rol_id HAVING c>1) x')->fetchColumn(),
    ];
    check($counts['orphan_user_role'] === 0 && $counts['duplicate_user_role'] === 0, 'User role integrity scan failed.');
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
    }
    if ($created !== []) {
        $placeholders = implode(',', array_fill(0, count($created), '?'));
        $pdo->prepare('DELETE FROM auditoria_eventos WHERE entidad = ? AND entidad_id IN (' . $placeholders . ')')
            ->execute(array_merge(['usuarios'], array_map('strval', $created)));
        $pdo->prepare('DELETE FROM usuario_roles WHERE usuario_id IN (' . $placeholders . ')')->execute($created);
        $pdo->prepare('DELETE FROM usuario_almacenes WHERE usuario_id IN (' . $placeholders . ')')->execute($created);
        $pdo->prepare('DELETE FROM usuario_empresas WHERE usuario_id IN (' . $placeholders . ')')->execute($created);
        $pdo->prepare('DELETE FROM usuarios WHERE id IN (' . $placeholders . ')')->execute($created);
    }
    $pdo->prepare('DELETE FROM roles WHERE id = :id AND codigo = :code')->execute(['id' => $qaRoleId ?? 0, 'code' => $roleCode]);
}

$baselineIntact = false;
try {
    $baselineSnapshot->execute(['id' => $baseline]);
    $baselineAfter = $baselineSnapshot->fetch();
    $baselineIntact = $baselineBefore == $baselineAfter;
    check($baselineIntact, 'Baseline ADMIN changed during DB-TEST.');
} catch (Throwable $exception) {
    $baselineIntact = false;
    $failure ??= $exception;
}

$qaDataResiduals = (int) $pdo->query(
    "SELECT
        (SELECT COUNT(*) FROM usuarios WHERE username LIKE 'qa.%')
      + (SELECT COUNT(*) FROM roles WHERE codigo LIKE 'QA_USER_%')
      + (SELECT COUNT(*) FROM usuario_roles ur INNER JOIN usuarios u ON u.id = ur.usuario_id WHERE u.username LIKE 'qa.%')
      + (SELECT COUNT(*) FROM usuario_empresas ue INNER JOIN usuarios u ON u.id = ue.usuario_id WHERE u.username LIKE 'qa.%')
      + (SELECT COUNT(*) FROM usuario_almacenes ua INNER JOIN usuarios u ON u.id = ua.usuario_id WHERE u.username LIKE 'qa.%')"
)->fetchColumn();

$failurePoint = null;
if ($failure !== null) {
    $message = $failure->getMessage();
    $failurePoint = match (true) {
        str_contains($message, 'New password failed') => 'NEW_PASSWORD_VALID',
        str_contains($message, 'Old password authenticated') => 'OLD_PASSWORD_INVALID',
        str_contains($message, 'Missing QA audit event') => 'AUDIT_EVENT_ASSERTIONS',
        str_contains($message, 'User role integrity') => 'INTEGRITY_SCAN',
        default => $failure::class,
    };
}

echo json_encode([
    'execution_status' => $failure === null ? 'PASS' : 'FAIL',
    'one_run_only' => true,
    'test_failure_point' => $failurePoint,
    'database' => $expectedDatabase,
    'permission_count_before' => $permissionBefore,
    'permission_count_after' => $permissionAfter,
    'admin_assignment_count_before' => $assignmentBefore,
    'admin_assignment_count_after' => $assignmentAfter,
    'baseline_admin_intact' => $baselineIntact,
    'auth_regression' => 'PASS',
    'rbac_regression' => 'PASS',
    'last_admin_concurrency' => 'NOT_EXECUTED',
    'last_admin_test_setup_valid' => true,
    'usable_admin_count_before_last_admin_test' => $usableAdminCount,
    'last_admin_rejection_result' => 'PASS',
    'baseline_admin_mutated_by_rejection_test' => false,
    'qa_data_residuals' => $qaDataResiduals,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;

if ($failure !== null) {
    exit(1);
}
