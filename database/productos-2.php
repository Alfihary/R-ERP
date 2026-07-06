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

    $allowed = [
        'migrate',
        'rollback',
        'seed',
        'seed:rollback',
        'db:test',
        'status',
    ];

    if (!in_array($command, $allowed, true)) {
        throw new RuntimeException(
            'Unknown DB-PRODUCTOS-2 CLI command.'
        );
    }

    $expectedDatabase = trim($options['database'] ?? '');
    $confirmedDatabase = trim($options['confirm-database'] ?? '');

    if (
        $expectedDatabase === ''
        || $expectedDatabase !== $confirmedDatabase
    ) {
        throw new RuntimeException(
            'The database name must be confirmed twice.'
        );
    }

    $config = require BASE_PATH . '/bootstrap/database.php';

    if (
        strtolower((string) $config->get('app.env', 'production'))
        === 'production'
    ) {
        throw new RuntimeException(
            'DB-PRODUCTOS-2 CLI commands are disabled in production.'
        );
    }

    $databaseConfig = $config->get('database', []);

    if (
        !is_array($databaseConfig)
        || ($databaseConfig['name'] ?? '') !== $expectedDatabase
    ) {
        throw new RuntimeException(
            'DB-PRODUCTOS-2 configuration does not match the confirmed database.'
        );
    }

    $pdo = (new ConnectionProvider($databaseConfig))->pdo();
    $migration = require BASE_PATH
        . '/database/migrations/'
        . 'db_productos_2_001_extend_product_master.php';
    $seed = require BASE_PATH
        . '/database/seeds/db_productos_2_seed_product_types.php';
    $test = require BASE_PATH
        . '/database/tests/db_productos_2_test.php';

    if (
        !$migration instanceof Migration
        || !$seed instanceof Seed
        || !$test instanceof DatabaseTest
    ) {
        throw new RuntimeException(
            'A DB-PRODUCTOS-2 database artifact has an invalid contract.'
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
        'DB-PRODUCTOS-2 database operation failed with SQLSTATE['
        . $exception->getCode()
        . '].'
        . PHP_EOL
    );
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
