<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
define('BASE_PATH', $root);
require_once $root . '/bootstrap/autoload.php';

use App\Domain\Audit\AuditService;
use App\Domain\Mail\MailOutboxActionService;
use App\Domain\Mail\MailOutboxProcessor;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\WriteConnectionProvider;
use App\Infrastructure\Mail\FakeMailTransport;
use App\Infrastructure\Repositories\AuditRepository;
use App\Infrastructure\Repositories\MailConfigurationRepository;
use App\Infrastructure\Repositories\MailOutboxActionRepository;
use App\Infrastructure\Repositories\MailOutboxQueryRepository;
use App\Infrastructure\Repositories\ProductTicketEmailOutboxRepository;

final class Rw4SpyPdo extends PDO
{
    /** @var list<string> */
    public array $events = [];

    public function beginTransaction(): bool { $this->events[] = 'BEGIN'; return parent::beginTransaction(); }
    public function commit(): bool { $this->events[] = 'COMMIT'; return parent::commit(); }
    public function rollBack(): bool { $this->events[] = 'ROLLBACK'; return parent::rollBack(); }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $normalized = preg_replace('/\s+FOR UPDATE\s*$/i', '', $query) ?? $query;
        if (stripos($normalized, 'SELECT') === 0 && str_contains($normalized, 'tickets_productos_correos')) {
            $this->events[] = 'OUTBOX_LOCK';
        } elseif (stripos($normalized, 'UPDATE tickets_productos_correos') === 0) {
            $this->events[] = 'OUTBOX_UPDATE';
            $normalized = str_contains($normalized, "SET m.status = 'PENDIENTE'")
                ? "UPDATE tickets_productos_correos SET status = 'PENDIENTE' WHERE id = :id AND status = 'ERROR' AND intentos < max_intentos AND enviado_at IS NULL AND cancelado_at IS NULL AND :scope_warehouse_0 IS NOT NULL"
                : "UPDATE tickets_productos_correos SET status = 'CANCELADO', cancelado_at = CURRENT_TIMESTAMP WHERE id = :id AND status IN ('PENDIENTE', 'ERROR') AND enviado_at IS NULL AND cancelado_at IS NULL AND :scope_warehouse_0 IS NOT NULL";
        } elseif (stripos($normalized, 'INSERT INTO auditoria_eventos') === 0) {
            $this->events[] = 'AUDIT_INSERT';
        }
        return parent::prepare($normalized, $options);
    }
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
};
$source = static function (string $path) use ($root): string {
    $contents = file_get_contents($root . '/' . $path);
    if (!is_string($contents)) { throw new RuntimeException('RW4 source file could not be read: ' . $path . '.'); }
    return $contents;
};
$newProvider = static function (array $write = ['user' => 'rw4_write_fixture', 'password' => 'rw4_write_password_fixture']): WriteConnectionProvider {
    return new WriteConnectionProvider([
        'common' => ['host' => 'invalid-rw4-host', 'port' => 3306, 'database' => 'rw4_no_connection'],
        'read' => ['user' => 'rw4_read_fixture', 'password' => 'rw4_read_password_fixture'],
        'write' => $write,
    ]);
};
$pdoFor = static function (): Rw4SpyPdo {
    $pdo = new Rw4SpyPdo('sqlite::memory:');
    $pdo->exec('CREATE TABLE tickets_productos (id INTEGER PRIMARY KEY, almacen_id INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE tickets_productos_correos (id INTEGER PRIMARY KEY, ticket_id INTEGER NOT NULL, status TEXT NOT NULL, intentos INTEGER NOT NULL, max_intentos INTEGER NOT NULL, ultimo_intento_at TEXT NULL, enviado_at TEXT NULL, cancelado_at TEXT NULL, error_mensaje_seguro TEXT NULL, dedupe_key TEXT NULL)');
    $pdo->exec('CREATE TABLE auditoria_eventos (actor_usuario_id INTEGER NULL, accion TEXT NOT NULL, entidad TEXT NOT NULL, entidad_id TEXT NULL, resultado TEXT NOT NULL, ip TEXT NULL, user_agent TEXT NULL, metadata_json TEXT NULL)');
    $pdo->exec('INSERT INTO tickets_productos (id, almacen_id) VALUES (7, 3)');
    return $pdo;
};
$setProviderPdo = static function (WriteConnectionProvider $provider, PDO $pdo): void {
    $property = new ReflectionProperty(WriteConnectionProvider::class, 'pdo');
    $property->setAccessible(true);
    $property->setValue($provider, $pdo);
};
$providerOf = static function (object $object): mixed {
    $property = new ReflectionProperty($object, 'connection');
    $property->setAccessible(true);
    return $property->getValue($object);
};
$propertyOf = static function (object $object, string $name): mixed {
    $property = new ReflectionProperty($object, $name);
    $property->setAccessible(true);
    return $property->getValue($object);
};

$keys = ['APP_DB_HOST', 'APP_DB_PORT', 'APP_DB_NAME', 'APP_DB_CHARSET', 'APP_DB_COLLATION', 'APP_DB_USER', 'APP_DB_PASSWORD', 'APP_DB_READ_USER', 'APP_DB_READ_PASSWORD', 'APP_DB_WRITE_USER', 'APP_DB_WRITE_PASSWORD'];
$snapshot = [];
foreach ($keys as $key) {
    $value = getenv($key);
    $snapshot[$key] = ['exists' => $value !== false, 'value' => $value === false ? null : (string) $value, 'env_exists' => array_key_exists($key, $_ENV), 'env_value' => array_key_exists($key, $_ENV) ? (string) $_ENV[$key] : null, 'server_exists' => array_key_exists($key, $_SERVER), 'server_value' => array_key_exists($key, $_SERVER) ? (string) $_SERVER[$key] : null];
}
$restore = static function (array $snapshot): void {
    foreach ($snapshot as $key => $state) {
        if ($state['exists']) { putenv($key . '=' . $state['value']); } else { putenv($key); }
        if ($state['env_exists']) { $_ENV[$key] = $state['env_value']; } else { unset($_ENV[$key]); }
        if ($state['server_exists']) { $_SERVER[$key] = $state['server_value']; } else { unset($_SERVER[$key]); }
    }
};
$set = static function (string $key, ?string $value): void {
    if ($value === null) { putenv($key); unset($_ENV[$key], $_SERVER[$key]); return; }
    putenv($key . '=' . $value); $_ENV[$key] = $value; $_SERVER[$key] = $value;
};

try {
    $bootstrap = $source('bootstrap/app.php');
    $worker = $source('database/tickets-productos-partidas-estados-correo-procesador.php');
    $actionServiceSource = $source('app/Domain/Mail/MailOutboxActionService.php');
    $actionRepositorySource = $source('app/Infrastructure/Repositories/MailOutboxActionRepository.php');
    $queryRepositorySource = $source('app/Infrastructure/Repositories/MailOutboxQueryRepository.php');
    $assert(str_contains($worker, 'new WriteConnectionProvider($databaseConfig)'), 'RW4 worker WRITE provider wiring is missing.');
    $assert(!str_contains($worker, 'new ConnectionProvider'), 'RW4 worker legacy provider fallback detected.');
    $assert(!str_contains($worker, 'ReadConnectionProvider'), 'RW4 worker READ provider fallback detected.');
    $assert(!preg_match('/\$writeConnectionProvider\s*->\s*pdo\s*\(/', $bootstrap), 'RW4 bootstrap eagerly opens WRITE PDO.');
    $assert(str_contains($bootstrap, 'new MailOutboxQueryRepository($connection)'), 'RW4 admin GET query graph changed unexpectedly.');
    $assert(str_contains($bootstrap, 'new MailOutboxActionRepository($writeConnectionProvider)'), 'RW4 admin action graph is not WRITE-bound.');
    $assert(str_contains($bootstrap, '$outboxAuditRepository = new AuditRepository($writeConnectionProvider);'), 'RW4 admin audit graph is not WRITE-bound.');
    $assert(str_contains($actionServiceSource, 'ConnectionProvider|WriteConnectionProvider'), 'RW4 action service provider contract missing.');
    $assert(str_contains($actionRepositorySource, 'ConnectionProvider|WriteConnectionProvider'), 'RW4 action repository provider contract missing.');
    $assert(str_contains($queryRepositorySource, 'ConnectionProvider'), 'RW4 query repository legacy contract missing.');

    $workerProvider = $newProvider();
    $workerPdo = $pdoFor();
    $setProviderPdo($workerProvider, $workerPdo);
    $workerOutbox = new ProductTicketEmailOutboxRepository($workerProvider);
    $workerConfig = new MailConfigurationRepository($workerProvider);
    $processor = new MailOutboxProcessor($workerOutbox, $workerConfig, new FakeMailTransport());
    $assert($propertyOf($processor, 'outbox') === $workerOutbox && $propertyOf($processor, 'configuration') === $workerConfig, 'RW4 processor graph was not instantiated with real dependencies.');
    $assert($providerOf($workerOutbox) === $workerProvider && $providerOf($workerConfig) === $workerProvider, 'RW4 worker provider identity failed.');
    $assert($workerProvider->pdo() === $workerPdo && $workerProvider->pdo() === $workerProvider->pdo(), 'RW4 worker PDO identity failed.');

    $adminProvider = $newProvider();
    $adminPdo = $pdoFor();
    $setProviderPdo($adminProvider, $adminPdo);
    $adminOutbox = new MailOutboxActionRepository($adminProvider);
    $adminAudit = new AuditRepository($adminProvider);
    $tableExists = new ReflectionProperty($adminAudit, 'tableExists');
    $tableExists->setAccessible(true); $tableExists->setValue($adminAudit, true);
    $adminService = new MailOutboxActionService($adminProvider, $adminOutbox, new AuditService($adminAudit), $adminAudit);
    $assert($providerOf($adminOutbox) === $adminProvider && $providerOf($adminAudit) === $adminProvider, 'RW4 admin provider identity failed.');
    $assert($adminProvider->pdo() === $adminPdo && $adminProvider->pdo() === $adminProvider->pdo(), 'RW4 admin PDO identity failed.');

    $retry = $adminService->retry(1, 42, [3], ['ip' => '127.0.0.1', 'user_agent' => 'rw4-test']);
    $assert($retry['result'] === 'not_found', 'RW4 retry real service path was not executed.');
    $adminPdo->exec("INSERT INTO tickets_productos_correos (id, ticket_id, status, intentos, max_intentos) VALUES (1, 7, 'ERROR', 0, 3)");
    $retry = $adminService->retry(1, 42, [3], ['ip' => '127.0.0.1', 'user_agent' => 'rw4-test']);
    $assert($retry['result'] === 'success' && $retry['status'] === 'PENDIENTE', 'RW4 retry service behavior failed.');
    $retrySequence = implode(',', $adminPdo->events);
    $assert(str_contains($retrySequence, 'BEGIN') && str_contains($retrySequence, 'OUTBOX_UPDATE') && str_contains($retrySequence, 'AUDIT_INSERT') && str_contains($retrySequence, 'COMMIT'), 'RW4 retry transaction sequence is incomplete.');
    $assert(strpos($retrySequence, 'AUDIT_INSERT') < strrpos($retrySequence, 'COMMIT'), 'RW4 retry audit occurred after commit.');

    $cancelProvider = $newProvider(); $cancelPdo = $pdoFor(); $setProviderPdo($cancelProvider, $cancelPdo);
    $cancelOutbox = new MailOutboxActionRepository($cancelProvider); $cancelAudit = new AuditRepository($cancelProvider);
    $cancelTableExists = new ReflectionProperty($cancelAudit, 'tableExists'); $cancelTableExists->setAccessible(true); $cancelTableExists->setValue($cancelAudit, true);
    $cancelService = new MailOutboxActionService($cancelProvider, $cancelOutbox, new AuditService($cancelAudit), $cancelAudit);
    $cancelPdo->exec("INSERT INTO tickets_productos_correos (id, ticket_id, status, intentos, max_intentos) VALUES (2, 7, 'PENDIENTE', 0, 3)");
    $cancel = $cancelService->cancel(2, 42, [3], 'rw4 test cancellation', ['ip' => '127.0.0.1', 'user_agent' => 'rw4-test']);
    $assert($cancel['result'] === 'success' && $cancel['status'] === 'CANCELADO', 'RW4 cancel service behavior failed.');
    $cancelSequence = implode(',', $cancelPdo->events);
    $assert(str_contains($cancelSequence, 'BEGIN') && str_contains($cancelSequence, 'OUTBOX_UPDATE') && str_contains($cancelSequence, 'AUDIT_INSERT') && str_contains($cancelSequence, 'COMMIT'), 'RW4 cancel transaction sequence is incomplete.');
    $assert(strpos($cancelSequence, 'AUDIT_INSERT') < strrpos($cancelSequence, 'COMMIT'), 'RW4 cancel audit occurred after commit.');

    foreach (['APP_DB_USER' => 'r_erp_tickets_qa_setup', 'APP_DB_PASSWORD' => 'rw4_legacy_dummy', 'APP_DB_WRITE_USER' => null, 'APP_DB_WRITE_PASSWORD' => null] as $key => $value) { $set($key, $value); }
    $workerFailure = false;
    try { $missing = $newProvider(['user' => '', 'password' => '']); $missingOutbox = new ProductTicketEmailOutboxRepository($missing); $missingConfig = new MailConfigurationRepository($missing); (new MailOutboxProcessor($missingOutbox, $missingConfig, new FakeMailTransport()))->recoverStaleProcessing(); } catch (RuntimeException $exception) { $workerFailure = $exception->getMessage() === 'Write database credentials are not configured.'; }
    $assert($workerFailure, 'RW4 worker fail-closed behavior was not tested independently.');
    $retryFailure = false;
    try { $missing = $newProvider(['user' => '', 'password' => '']); $audit = new AuditRepository($missing); (new MailOutboxActionService($missing, new MailOutboxActionRepository($missing), new AuditService($audit), $audit))->retry(1, 42, [3]); } catch (RuntimeException $exception) { $retryFailure = $exception->getMessage() === 'Write database credentials are not configured.'; }
    $assert($retryFailure, 'RW4 retry fail-closed behavior was not tested independently.');
    $cancelFailure = false;
    try { $missing = $newProvider(['user' => '', 'password' => '']); $audit = new AuditRepository($missing); (new MailOutboxActionService($missing, new MailOutboxActionRepository($missing), new AuditService($audit), $audit))->cancel(1, 42, [3], 'rw4 test cancellation'); } catch (RuntimeException $exception) { $cancelFailure = $exception->getMessage() === 'Write database credentials are not configured.'; }
    $assert($cancelFailure, 'RW4 cancel fail-closed behavior was not tested independently.');

    $legacyProvider = new ConnectionProvider(['host' => 'invalid-rw4-host', 'port' => 3306, 'name' => 'rw4_no_connection', 'username' => 'rw4_legacy_fixture', 'password' => 'rw4_legacy_password_fixture']);
    $assert(new MailOutboxQueryRepository($legacyProvider) instanceof MailOutboxQueryRepository, 'RW4 GET graph could not be constructed without WRITE credentials.');
    $writePdo = new ReflectionProperty(WriteConnectionProvider::class, 'pdo'); $writePdo->setAccessible(true);
    $assert($writePdo->getValue($workerProvider) === $workerPdo, 'RW4 GET construction altered the worker provider cache unexpectedly.');
    echo "RW_4_DB_FREE_TESTS=PASS\n";
} finally {
    $restore($snapshot);
}

foreach ($snapshot as $key => $state) {
    $value = getenv($key);
    if (($value !== false) !== $state['exists'] || ($state['exists'] && (string) $value !== $state['value'])) { throw new RuntimeException('RW4 environment restoration failed for ' . $key . '.'); }
    if (array_key_exists($key, $_ENV) !== $state['env_exists'] || ($state['env_exists'] && (string) $_ENV[$key] !== $state['env_value'])) { throw new RuntimeException('RW4 $_ENV restoration failed for ' . $key . '.'); }
    if (array_key_exists($key, $_SERVER) !== $state['server_exists'] || ($state['server_exists'] && (string) $_SERVER[$key] !== $state['server_value'])) { throw new RuntimeException('RW4 $_SERVER restoration failed for ' . $key . '.'); }
}
