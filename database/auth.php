<?php

declare(strict_types=1);

use App\Domain\Auth\InitialAdminService;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Repositories\RoleRepository;
use App\Infrastructure\Repositories\UserRepository;

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

    if (!in_array($command, ['create-initial-admin', 'rotate-initial-admin-password'], true)) {
        throw new RuntimeException('Unknown AUTH-0 CLI command.');
    }

    $expectedDatabase = trim($options['database'] ?? '');
    $confirmedDatabase = trim($options['confirm-database'] ?? '');

    if ($expectedDatabase === '' || $expectedDatabase !== $confirmedDatabase) {
        throw new RuntimeException('The database name must be confirmed twice.');
    }

    $config = require BASE_PATH . '/bootstrap/database.php';

    if (strtolower((string) $config->get('app.env', 'production')) === 'production') {
        throw new RuntimeException('AUTH-0 CLI commands are disabled in production.');
    }

    $databaseConfig = $config->get('database', []);
    $adminConfig = $config->get('auth.initial_admin', []);

    if (!is_array($databaseConfig)
        || !is_array($adminConfig)
        || ($databaseConfig['name'] ?? '') !== $expectedDatabase
    ) {
        throw new RuntimeException('AUTH-0 configuration does not match the confirmed database.');
    }

    $connection = new ConnectionProvider($databaseConfig);
    $service = new InitialAdminService(
        $connection,
        new UserRepository($connection),
        new RoleRepository($connection)
    );
    $username = (string) ($adminConfig['username'] ?? '');
    $email = (string) ($adminConfig['email'] ?? '');
    $password = (string) ($adminConfig['password'] ?? '');

    $result = $command === 'create-initial-admin'
        ? $service->create($username, $email, $password)
        : [
            'result' => 'password_rotated',
            'user_id' => $service->rotatePassword($username, $email, $password),
        ];

    echo json_encode(
        ['command' => $command, 'result' => $result],
        JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
} catch (PDOException $exception) {
    fwrite(STDERR, 'AUTH-0 database operation failed with SQLSTATE['
        . $exception->getCode() . '].' . PHP_EOL);
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
