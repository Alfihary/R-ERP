<?php

declare(strict_types=1);

use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Database\Migration;
use App\Infrastructure\Database\MigrationRunner;

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

        [$name, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, '');
        $options[$name] = $value;
    }

    if (!in_array($command, ['migrate', 'rollback', 'db:test', 'status'], true)) {
        throw new RuntimeException('Unknown TP-PARTIDAS-ESTADOS-CORREO-OUTBOX-DB-1 CLI command.');
    }

    $expectedDatabase = trim($options['database'] ?? '');
    $confirmedDatabase = trim($options['confirm-database'] ?? '');

    if (
        $expectedDatabase === ''
        || $expectedDatabase !== $confirmedDatabase
        || $expectedDatabase !== 'r_erp_db_core_0_test'
    ) {
        throw new RuntimeException('The TP mail outbox database name must be confirmed exactly.');
    }

    $config = require BASE_PATH . '/bootstrap/database.php';

    if (strtolower((string) $config->get('app.env', 'production')) === 'production') {
        throw new RuntimeException('TP-PARTIDAS-ESTADOS-CORREO-OUTBOX-DB-1 CLI commands are disabled in production.');
    }

    $databaseConfig = $config->get('database', []);

    if (!is_array($databaseConfig) || ($databaseConfig['name'] ?? '') !== $expectedDatabase) {
        throw new RuntimeException('TP mail outbox configuration does not match the confirmed database.');
    }

    $pdo = (new ConnectionProvider($databaseConfig))->pdo();
    $migration = require BASE_PATH
        . '/database/migrations/tp_partidas_estados_correo_outbox_db_1_001_create_ticket_product_email_outbox.php';
    $test = require BASE_PATH . '/database/tests/tickets_productos_partidas_estados_correo_outbox_db_1_test.php';

    if (!$migration instanceof Migration) {
        throw new RuntimeException('TP-PARTIDAS-ESTADOS-CORREO-OUTBOX-DB-1 migration has an invalid contract.');
    }

    if (!$test instanceof DatabaseTest) {
        throw new RuntimeException('TP-PARTIDAS-ESTADOS-CORREO-OUTBOX-DB-1 database test has an invalid contract.');
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
        'status' => [
            'migration' => $migration->id(),
            'migrations' => $runner->status(),
        ],
        'db:test' => [
            'migration' => $migration->id(),
            'test' => $test->run($pdo, $expectedDatabase),
        ],
    };

    echo json_encode(
        ['command' => $command, 'result' => $result],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
} catch (PDOException $exception) {
    fwrite(
        STDERR,
        'TP-PARTIDAS-ESTADOS-CORREO-OUTBOX-DB-1 database operation failed with SQLSTATE['
        . $exception->getCode()
        . '].'
        . PHP_EOL
    );
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
