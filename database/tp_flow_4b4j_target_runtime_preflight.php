<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || (($argv[1] ?? null) !== '--preflight')) {
    fwrite(STDERR, "PREflight requires --preflight.\n");
    exit(2);
}

$base = dirname(__DIR__);
require_once $base . '/app/Core/Env.php';
require_once $base . '/app/Infrastructure/Database/Connection.php';
require_once $base . '/app/Infrastructure/Database/ConnectionProvider.php';
require_once $base . '/app/Infrastructure/Database/ReadConnectionProvider.php';
require_once $base . '/app/Infrastructure/Database/WriteConnectionProvider.php';
require_once $base . '/app/Domain/Tickets/ResponsibleRecipientRepositoryInterface.php';
require_once $base . '/app/Domain/Tickets/ResponsibleRecipientResolverInterface.php';
require_once $base . '/app/Domain/Tickets/ResponsibleRecipientResolver.php';
require_once $base . '/app/Infrastructure/Repositories/ResponsibleRecipientRepository.php';

use App\Core\Env;
use App\Domain\Tickets\ResponsibleRecipientResolver;
use App\Infrastructure\Database\ReadConnectionProvider;
use App\Infrastructure\Database\WriteConnectionProvider;
use App\Infrastructure\Repositories\ResponsibleRecipientRepository;

$targetDatabase = 'r_erp_db_core_0_test';
$expectedReadUser = 'consulta@localhost';
$expectedWriteUser = 'r_erp_tickets_write@localhost';

$required = ['APP_DB_HOST', 'APP_DB_PORT', 'APP_DB_NAME', 'APP_DB_READ_USER', 'APP_DB_READ_PASSWORD', 'APP_DB_WRITE_USER', 'APP_DB_WRITE_PASSWORD'];
foreach ($required as $name) {
    if (trim((string) Env::get($name, '')) === '') {
        throw new RuntimeException('TARGET_PREFLIGHT_CONFIG_MISSING:' . $name);
    }
}
if (Env::get('APP_DB_NAME') !== $targetDatabase) {
    throw new RuntimeException('TARGET_PREFLIGHT_DATABASE_MISMATCH');
}

$config = require $base . '/config/database.php';
$read = new ReadConnectionProvider($config);
$write = new WriteConnectionProvider($config);
$readPdo = $read->pdo();
$writePdo = $write->pdo();

$identity = static function (PDO $pdo): array {
    return $pdo->query('SELECT DATABASE() AS database_name, CURRENT_USER() AS current_user_name')->fetch(PDO::FETCH_ASSOC) ?: [];
};
$readIdentity = $identity($readPdo);
$writeIdentity = $identity($writePdo);
if (($readIdentity['database_name'] ?? null) !== $targetDatabase
    || ($writeIdentity['database_name'] ?? null) !== $targetDatabase
    || ($readIdentity['current_user_name'] ?? null) !== $expectedReadUser
    || ($writeIdentity['current_user_name'] ?? null) !== $expectedWriteUser
) {
    throw new RuntimeException('TARGET_PREFLIGHT_IDENTITY_GUARD_FAILED');
}

$readPdo->query('SHOW GRANTS');
$writePdo->query('SHOW GRANTS');
$readTables = ['usuarios', 'usuario_empresas', 'usuario_almacenes', 'usuario_roles', 'roles', 'rol_permisos', 'permisos'];
foreach ($readTables as $table) {
    $readPdo->query('SELECT 1 FROM `' . $table . '` LIMIT 1');
}

$row = static function (PDO $pdo, string $sql, array $params = []): array {
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
};

$requester = $row($readPdo, 'SELECT id, activo, eliminado_en FROM usuarios WHERE id = 4612');
$responsible = $row($readPdo, 'SELECT id, activo, eliminado_en FROM usuarios WHERE id = 4613');
$role = $row($readPdo, 'SELECT id, activo, eliminado_en FROM roles WHERE id = 1001');
$permission = $row($readPdo, 'SELECT id, codigo, activo, eliminado_en FROM permisos WHERE id = 698');
$rolePermission = $row($readPdo, 'SELECT rol_id, permiso_id, activo, eliminado_en FROM rol_permisos WHERE rol_id = 1001 AND permiso_id = 698');
$userRole = $row($readPdo, 'SELECT usuario_id, rol_id, activo, eliminado_en FROM usuario_roles WHERE usuario_id = 4613 AND rol_id = 1001');

$qaResponsibleEmail = (string) ($row($readPdo, 'SELECT email FROM usuarios WHERE id = 4613')['email'] ?? '');
$qaRequesterEmail = (string) ($row($readPdo, 'SELECT email FROM usuarios WHERE id = 4612')['email'] ?? '');
$resolver = new ResponsibleRecipientResolver(new ResponsibleRecipientRepository($read));
$responsibleEmails = $resolver->emailsForTicketContext(1, 1, 4612);
$normalizedResponsible = strtolower(trim($qaResponsibleEmail));
$normalizedRequester = strtolower(trim($qaRequesterEmail));

$result = [
    'status' => 'PASS',
    'database' => $targetDatabase,
    'read_current_user' => $readIdentity['current_user_name'],
    'write_current_user' => $writeIdentity['current_user_name'],
    'read_provider_select_access' => true,
    'requester_ready' => (int) ($requester['activo'] ?? 0) === 1 && ($requester['eliminado_en'] ?? null) === null,
    'responsible_ready' => (int) ($responsible['activo'] ?? 0) === 1 && ($responsible['eliminado_en'] ?? null) === null,
    'role_ready' => (int) ($role['activo'] ?? 0) === 1 && ($role['eliminado_en'] ?? null) === null,
    'permission_ready' => (int) ($permission['id'] ?? 0) === 698
        && ($permission['codigo'] ?? null) === 'tickets_productos.resolver'
        && (int) ($permission['activo'] ?? 0) === 1
        && ($permission['eliminado_en'] ?? null) === null,
    'role_permission_ready' => (int) ($rolePermission['activo'] ?? 0) === 1
        && ($rolePermission['eliminado_en'] ?? null) === null,
    'qa_responsible_role_ready' => (int) ($userRole['usuario_id'] ?? 0) === 4613
        && (int) ($userRole['rol_id'] ?? 0) === 1001
        && (int) ($userRole['activo'] ?? 0) === 1
        && ($userRole['eliminado_en'] ?? null) === null,
    'canonical_responsible_count' => count($responsibleEmails),
    'qa_4613_included' => in_array($normalizedResponsible, $responsibleEmails, true),
    'qa_4612_excluded' => !in_array($normalizedRequester, $responsibleEmails, true),
    'smtp_connections' => 0,
    'email_sent' => false,
    'worker_runtime_executed' => false,
    'application_dml_executed' => false,
];

if (!$result['requester_ready'] || !$result['responsible_ready'] || !$result['role_ready']
    || !$result['permission_ready'] || !$result['role_permission_ready']
    || !$result['qa_responsible_role_ready'] || !$result['qa_4613_included']
    || !$result['qa_4612_excluded']) {
    throw new RuntimeException('TARGET_PREFLIGHT_QA_CONTRACT_FAILED');
}

echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
