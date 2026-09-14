<?php

declare(strict_types=1);

use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;

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

    if ($command !== 'audit') {
        throw new RuntimeException('Unknown TP-PARTIDAS-ESTADOS-CORREO-CONTRATO-1 CLI command.');
    }

    $expectedDatabase = trim($options['database'] ?? '');
    $confirmedDatabase = trim($options['confirm-database'] ?? '');

    if (
        $expectedDatabase === ''
        || $expectedDatabase !== $confirmedDatabase
        || $expectedDatabase !== 'r_erp_db_core_0_test'
    ) {
        throw new RuntimeException('The TP mail contract audit database name must be confirmed exactly.');
    }

    $config = require BASE_PATH . '/bootstrap/database.php';

    if (strtolower((string) $config->get('app.env', 'production')) === 'production') {
        throw new RuntimeException('TP-PARTIDAS-ESTADOS-CORREO-CONTRATO-1 CLI commands are disabled in production.');
    }

    $databaseConfig = $config->get('database', []);

    if (!is_array($databaseConfig) || ($databaseConfig['name'] ?? '') !== $expectedDatabase) {
        throw new RuntimeException('TP mail contract audit configuration does not match the confirmed database.');
    }

    $test = require BASE_PATH . '/database/tests/tickets_productos_partidas_estados_correo_contrato_1_test.php';

    if (!$test instanceof DatabaseTest) {
        throw new RuntimeException('TP-PARTIDAS-ESTADOS-CORREO-CONTRATO-1 test has an invalid contract.');
    }

    $connection = new ConnectionProvider($databaseConfig);
    $result = $test->run($connection->pdo(), $expectedDatabase);

    echo json_encode(
        ['command' => $command, 'result' => $result],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
} catch (PDOException $exception) {
    fwrite(
        STDERR,
        'TP-PARTIDAS-ESTADOS-CORREO-CONTRATO-1 database audit failed with SQLSTATE['
        . $exception->getCode()
        . '].'
        . PHP_EOL
    );
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
