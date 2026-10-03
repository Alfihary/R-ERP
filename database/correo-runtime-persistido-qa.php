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

    if ($command !== 'db:test') {
        throw new RuntimeException('Unknown CORREO-RUNTIME-PERSISTIDO-QA-1 command.');
    }

    $expectedDatabase = trim($options['database'] ?? '');
    $confirmedDatabase = trim($options['confirm-database'] ?? '');
    if (
        $expectedDatabase !== 'r_erp_db_core_0_test'
        || $confirmedDatabase !== $expectedDatabase
    ) {
        throw new RuntimeException('The persisted runtime email QA database must be confirmed exactly.');
    }

    $config = require BASE_PATH . '/bootstrap/database.php';
    if (strtolower((string) $config->get('app.env', 'production')) === 'production') {
        throw new RuntimeException('Persisted runtime email QA is disabled in production.');
    }

    $databaseConfig = $config->get('database', []);
    if (!is_array($databaseConfig) || ($databaseConfig['name'] ?? '') !== $expectedDatabase) {
        throw new RuntimeException('Persisted runtime email QA configuration does not match the confirmed database.');
    }

    $pdo = (new ConnectionProvider($databaseConfig))->pdo();
    $test = require BASE_PATH . '/database/tests/correo_runtime_persistido_qa_1_test.php';
    if (!$test instanceof DatabaseTest) {
        throw new RuntimeException('Persisted runtime email QA test has an invalid contract.');
    }

    echo json_encode(
        ['command' => $command, 'result' => $test->run($pdo, $expectedDatabase)],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
} catch (PDOException $exception) {
    fwrite(
        STDERR,
        'CORREO-RUNTIME-PERSISTIDO-QA-1 failed with SQLSTATE[' . $exception->getCode() . '].' . PHP_EOL
    );
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
