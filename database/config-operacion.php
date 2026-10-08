<?php

declare(strict_types=1);

use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Database\Migration;
use App\Infrastructure\Database\MigrationRunner;
use App\Infrastructure\Database\Seed;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));

try {
    $command = $argv[1] ?? '';
    $options = [];

    foreach (array_slice($argv, 2) as $argument) {
        if (!str_starts_with($argument, '--')) {
            throw new RuntimeException('Unexpected CLI argument.');
        }

        [$name, $value] = array_pad(
            explode('=', substr($argument, 2), 2),
            2,
            ''
        );
        $options[$name] = $value;
    }

    $allowed = ['migrate', 'rollback', 'db:test', 'seed', 'status'];

    if (!in_array($command, $allowed, true)) {
        throw new RuntimeException('Unknown CONFIG-OPERACION CLI command.');
    }

    $expectedDatabase = trim($options['database'] ?? '');
    $confirmedDatabase = trim($options['confirm-database'] ?? '');

    if (
        $expectedDatabase === ''
        || $expectedDatabase !== $confirmedDatabase
        || $expectedDatabase !== 'r_erp_db_core_0_test'
    ) {
        throw new RuntimeException(
            'The CONFIG-OPERACION test database name must be confirmed exactly.'
        );
    }

    $config = require BASE_PATH . '/bootstrap/database.php';

    if (
        strtolower((string) $config->get('app.env', 'production'))
        === 'production'
    ) {
        throw new RuntimeException(
            'CONFIG-OPERACION CLI commands are disabled in production.'
        );
    }

    $databaseConfig = $config->get('database', []);

    if (
        !is_array($databaseConfig)
        || ($databaseConfig['name'] ?? '') !== $expectedDatabase
    ) {
        throw new RuntimeException(
            'CONFIG-OPERACION configuration does not match the confirmed database.'
        );
    }

    $pdo = (new ConnectionProvider($databaseConfig))->pdo();
    $migrations = [
        require BASE_PATH
            . '/database/migrations/config_operacion_1_001_extend_companies_warehouses.php',
        require BASE_PATH
            . '/database/migrations/config_operacion_codigos_min_2_001_update_company_warehouse_code_checks.php',
        require BASE_PATH
            . '/database/migrations/config_operacion_codigos_upper_1_001_uppercase_company_warehouse_codes.php',
    ];
    $seed = require BASE_PATH
        . '/database/seeds/config_operacion_empresas_almacenes_1_seed_permissions.php';
    $test = require BASE_PATH
        . '/database/tests/config_operacion_empresas_almacenes_1_test.php';

    foreach ($migrations as $migration) {
        if (!$migration instanceof Migration) {
            throw new RuntimeException(
                'A CONFIG-OPERACION migration has an invalid contract.'
            );
        }
    }

    if (!$seed instanceof Seed || !$test instanceof DatabaseTest) {
        throw new RuntimeException(
            'A CONFIG-OPERACION artifact has an invalid contract.'
        );
    }

    $runner = new MigrationRunner($pdo);
    $result = match ($command) {
        'migrate' => [
            'migrations' => migrateAll($runner, $migrations),
        ],
        'rollback' => [
            'migrations' => rollbackAll($runner, $migrations),
        ],
        'seed' => (static function () use ($seed, $pdo): array {
            $seed->run($pdo);
            return ['seed' => $seed->id(), 'result' => 'applied'];
        })(),
        'db:test' => [
            'migration' => migrateAll($runner, $migrations),
            'migration_second_run' => migrateAll($runner, $migrations),
            'seed' => (static function () use ($seed, $pdo): string {
                $seed->run($pdo);
                $seed->run($pdo);
                return 'idempotent';
            })(),
            'test' => $test->run($pdo, $expectedDatabase),
        ],
        'status' => ['migrations' => $runner->status()],
    };

    echo json_encode(
        ['command' => $command, 'result' => $result],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
} catch (PDOException $exception) {
    fwrite(
        STDERR,
        'CONFIG-OPERACION database operation failed with SQLSTATE['
        . $exception->getCode()
        . '].'
        . PHP_EOL
    );
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

/**
 * @param list<Migration> $migrations
 * @return array<string, string>
 */
function migrateAll(MigrationRunner $runner, array $migrations): array
{
    $results = [];

    foreach ($migrations as $migration) {
        $results[$migration->id()] = $runner->migrate($migration);
    }

    return $results;
}

/**
 * @param list<Migration> $migrations
 * @return array<string, string>
 */
function rollbackAll(MigrationRunner $runner, array $migrations): array
{
    $results = [];

    foreach (array_reverse($migrations) as $migration) {
        $results[$migration->id()] = $runner->rollback($migration);
    }

    return $results;
}
