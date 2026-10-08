<?php

declare(strict_types=1);

use App\Domain\Scope\UserScopeService;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\ScopeRepository;

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
        throw new RuntimeException('Unknown SCOPE-SERVICE-1 CLI command.');
    }

    $expectedDatabase = trim($options['database'] ?? '');
    $confirmedDatabase = trim($options['confirm-database'] ?? '');

    if ($expectedDatabase === '' || $expectedDatabase !== $confirmedDatabase) {
        throw new RuntimeException('The database name must be confirmed twice.');
    }

    $config = require BASE_PATH . '/bootstrap/database.php';

    if (strtolower((string) $config->get('app.env', 'production')) === 'production') {
        throw new RuntimeException('SCOPE-SERVICE-1 CLI commands are disabled in production.');
    }

    $databaseConfig = $config->get('database', []);
    $adminConfig = $config->get('auth.initial_admin', []);

    if (!is_array($databaseConfig)
        || !is_array($adminConfig)
        || ($databaseConfig['name'] ?? '') !== $expectedDatabase
    ) {
        throw new RuntimeException(
            'SCOPE-SERVICE-1 configuration does not match the confirmed database.'
        );
    }

    $initialAdminUsername = (string) ($adminConfig['username'] ?? '');
    $initialAdminEmail = (string) ($adminConfig['email'] ?? '');
    $connection = new ConnectionProvider($databaseConfig);
    $pdo = $connection->pdo();
    $userScope = new UserScopeService(new ScopeRepository($connection));
    $test = require BASE_PATH . '/database/tests/scope_service_1_test.php';

    if (!$test instanceof DatabaseTest) {
        throw new RuntimeException('The SCOPE-SERVICE-1 test has an invalid contract.');
    }

    $result = $test->run($pdo, $expectedDatabase);

    echo json_encode(
        ['command' => $command, 'result' => $result],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
} catch (PDOException $exception) {
    fwrite(
        STDERR,
        'SCOPE-SERVICE-1 database operation failed with SQLSTATE['
        . $exception->getCode()
        . '].'
        . PHP_EOL
    );
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
