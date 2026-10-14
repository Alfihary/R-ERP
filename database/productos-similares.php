<?php

declare(strict_types=1);

use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Database\Migration;
use App\Infrastructure\Database\MigrationRunner;
use App\Infrastructure\Database\Seed;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
define('BASE_PATH', dirname(__DIR__));

try {
    $command = $argv[1] ?? '';
    if (!in_array($command, ['migrate', 'rollback', 'seed', 'db:test', 'status'], true)) {
        throw new RuntimeException('Unknown PRODUCTOS-SIMILARES-1 command.');
    }
    $options = [];
    foreach (array_slice($argv, 2) as $argument) {
        if (!str_starts_with($argument, '--')) { throw new RuntimeException('Unexpected CLI argument.'); }
        [$name, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, '');
        $options[$name] = $value;
    }
    $database = trim((string) ($options['database'] ?? ''));
    $confirmed = trim((string) ($options['confirm-database'] ?? ''));
    if ($database === '' || $database !== $confirmed) { throw new RuntimeException('Database must be confirmed twice.'); }
    $config = require BASE_PATH . '/bootstrap/database.php';
    if (strtolower((string) $config->get('app.env', 'production')) === 'production') { throw new RuntimeException('Disabled in production.'); }
    $databaseConfig = $config->get('database', []);
    if (!is_array($databaseConfig) || ($databaseConfig['name'] ?? '') !== $database) { throw new RuntimeException('Database target mismatch.'); }
    $connectionProvider = new ConnectionProvider($databaseConfig);
    $pdo = $connectionProvider->pdo();
    $GLOBALS['productos_similares_connection'] = $connectionProvider;
    $migration = require BASE_PATH . '/database/migrations/productos_similares_1_001_create_product_similar_tables.php';
    $seed = require BASE_PATH . '/database/seeds/productos_similares_1_seed_permissions.php';
    $test = require BASE_PATH . '/database/tests/productos_similares_1_test.php';
    if (!$migration instanceof Migration || !$seed instanceof Seed || !$test instanceof DatabaseTest) { throw new RuntimeException('Invalid PRODUCTOS-SIMILARES-1 artifact.'); }
    $runner = new MigrationRunner($pdo);
    $result = match ($command) {
        'migrate' => ['migration' => $migration->id(), 'result' => $runner->migrate($migration)],
        'rollback' => ['migration' => $migration->id(), 'result' => $runner->rollback($migration)],
        'seed' => (static function () use ($seed, $pdo): array {
            $seed->run($pdo);
            return ['seed' => $seed->id(), 'result' => 'applied'];
        })(),
        'status' => ['migrations' => $runner->status()],
        'db:test' => (static function () use ($test, $pdo, $database): array {
            $result = $test->run($pdo, $database);
            $requiredChecks = [
                'TABLE_EXISTS',
                'ENGINE_INNODB',
                'CHARSET_COLLATION_VALID',
                'PRIMARY_KEY_VALID',
                'FOREIGN_KEYS_VALID',
                'CANONICAL_CHECK_VALID',
                'SELF_RELATION_CHECK_VALID',
                'ADD_A_B',
                'LIST_FROM_A_RETURNS_B',
                'LIST_FROM_B_RETURNS_A',
                'DUPLICATE_SAME_ORDER_REJECTED',
                'DUPLICATE_REVERSE_ORDER_REJECTED',
                'SELF_RELATION_REJECTED',
                'INACTIVE_PRODUCT_REJECTED',
                'DELETED_PRODUCT_REJECTED',
                'UNKNOWN_PRODUCT_REJECTED',
                'REMOVE_RELATION',
                'SOFT_DELETE_ASSERTED',
                'REACTIVATE_RELATION',
                'REACTIVATION_REUSES_SAME_CANONICAL_ROW',
                'ADD_AUDIT_CREATED',
                'REMOVE_AUDIT_CREATED',
                'ADD_FAILURE_ROLLBACK',
                'REMOVE_FAILURE_ROLLBACK',
                'NO_PARTIAL_RELATION',
                'QA_PRODUCTS_RESIDUALS',
                'QA_SIMILAR_RELATIONS_RESIDUALS',
                'QA_AUDIT_RESIDUALS',
                'QA_USER_RESIDUALS',
            ];
            $failed = [];
            foreach ($requiredChecks as $name) {
                if (($result[$name] ?? null) !== true) {
                    $failed[] = $name;
                }
            }
            $result['_failed_checks'] = $failed;
            if ($failed !== []) {
                echo json_encode(['command' => 'db:test', 'result' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
                throw new RuntimeException('PRODUCTOS-SIMILARES-1 DB test failed: ' . implode(', ', $failed));
            }
            return $result;
        })(),
    };
    echo json_encode(['command' => $command, 'result' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
