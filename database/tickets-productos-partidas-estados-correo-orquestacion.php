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

    if ($command !== 'functional:test') {
        throw new RuntimeException('Unknown TP-PARTIDAS-ESTADOS-CORREO-ORQUESTACION-1 CLI command.');
    }

    $expectedDatabase = trim($options['database'] ?? '');
    $confirmedDatabase = trim($options['confirm-database'] ?? '');
    if ($expectedDatabase !== 'r_erp_db_core_0_test' || $confirmedDatabase !== $expectedDatabase) {
        throw new RuntimeException('The orchestration test database must be confirmed exactly.');
    }

    $config = require BASE_PATH . '/bootstrap/database.php';
    if (strtolower((string) $config->get('app.env', 'production')) === 'production') {
        throw new RuntimeException('Mail orchestration tests are disabled in production.');
    }
    $databaseConfig = $config->get('database', []);
    if (!is_array($databaseConfig) || ($databaseConfig['name'] ?? '') !== $expectedDatabase) {
        throw new RuntimeException('Mail orchestration does not match the confirmed database.');
    }

    $connection = new ConnectionProvider($databaseConfig);
    $GLOBALS['tp_mail_orchestration_connection'] = $connection;
    $test = require BASE_PATH . '/database/tests/tickets_productos_partidas_estados_correo_orquestacion_1_test.php';
    if (!$test instanceof DatabaseTest) {
        throw new RuntimeException('Mail orchestration test has an invalid contract.');
    }

    echo json_encode([
        'command' => $command,
        'result' => $test->run($connection->pdo(), $expectedDatabase),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (PDOException $exception) {
    fwrite(STDERR, 'Mail orchestration DB operation failed with SQLSTATE[' . $exception->getCode() . '].' . PHP_EOL);
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
