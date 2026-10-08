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

    $allowedCommands = [
        'migrate',
        'rollback',
        'seed',
        'seed:rollback',
        'db:test',
        'status',
    ];

    if (!in_array($command, $allowedCommands, true)) {
        throw new RuntimeException('Unknown DB-CATALOGOS-1 CLI command.');
    }

    $expectedDatabase = trim($options['database'] ?? '');
    $confirmedDatabase = trim($options['confirm-database'] ?? '');

    if ($expectedDatabase === ''
        || $expectedDatabase !== $confirmedDatabase
    ) {
        throw new RuntimeException('The database name must be confirmed twice.');
    }

    $config = require BASE_PATH . '/bootstrap/database.php';

    if (strtolower((string) $config->get('app.env', 'production'))
        === 'production'
    ) {
        throw new RuntimeException(
            'DB-CATALOGOS-1 CLI commands are disabled in production.'
        );
    }

    $databaseConfig = $config->get('database', []);
    $adminConfig = $config->get('auth.initial_admin', []);

    if (!is_array($databaseConfig)
        || !is_array($adminConfig)
        || ($databaseConfig['name'] ?? '') !== $expectedDatabase
    ) {
        throw new RuntimeException(
            'DB-CATALOGOS-1 configuration does not match the confirmed database.'
        );
    }

    $initialAdminUsername = (string) ($adminConfig['username'] ?? '');
    $initialAdminEmail = (string) ($adminConfig['email'] ?? '');
    $connection = new ConnectionProvider($databaseConfig);
    $pdo = $connection->pdo();
    $migration = require BASE_PATH
        . '/database/migrations/'
        . 'db_catalogos_1_001_create_base_catalog_tables.php';
    $seed = require BASE_PATH
        . '/database/seeds/db_catalogos_1_seed_base_catalogs.php';
    $test = require BASE_PATH
        . '/database/tests/db_catalogos_1_test.php';

    if (!$migration instanceof Migration
        || !$seed instanceof Seed
        || !$test instanceof DatabaseTest
    ) {
        throw new RuntimeException(
            'A DB-CATALOGOS-1 database artifact has an invalid contract.'
        );
    }

    $runner = new MigrationRunner($pdo);
    $result = match ($command) {
        'migrate' => [
            'migration' => $migration->id(),
            'result' => $runner->migrate($migration),
        ],
        'rollback' => [
            'migration' => $migration->id(),
            'result' => $runner->rollback($migration),
        ],
        'seed' => (static function () use ($seed, $pdo): array {
            $seed->run($pdo);
            return ['seed' => $seed->id(), 'result' => 'applied'];
        })(),
        'seed:rollback' => (static function () use ($seed, $pdo): array {
            $seed->rollback($pdo);
            return ['seed' => $seed->id(), 'result' => 'rolled_back'];
        })(),
        'db:test' => $test->run($pdo, $expectedDatabase),
        'status' => ['migrations' => $runner->status()],
    };

    echo json_encode(
        ['command' => $command, 'result' => $result],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
} catch (PDOException $exception) {
    fwrite(
        STDERR,
        'DB-CATALOGOS-1 database operation failed with SQLSTATE['
        . $exception->getCode()
        . '].'
        . PHP_EOL
    );
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
