<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
define('BASE_PATH', $root);
require_once $root . '/bootstrap/autoload.php';

use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\ReadConnectionProvider;
use App\Infrastructure\Database\WriteConnectionProvider;

$keys = [
    'APP_DB_HOST',
    'APP_DB_PORT',
    'APP_DB_NAME',
    'APP_DB_CHARSET',
    'APP_DB_COLLATION',
    'APP_DB_USER',
    'APP_DB_PASSWORD',
    'APP_DB_READ_USER',
    'APP_DB_READ_PASSWORD',
    'APP_DB_WRITE_USER',
    'APP_DB_WRITE_PASSWORD',
];

$snapshotEnv = static function (array $keys): array {
    $snapshot = [];

    foreach ($keys as $key) {
        $environmentValue = getenv($key);
        $snapshot[$key] = [
            'environment' => [
                'exists' => $environmentValue !== false,
                'value' => $environmentValue === false ? null : (string) $environmentValue,
            ],
            'env' => [
                'exists' => array_key_exists($key, $_ENV),
                'value' => array_key_exists($key, $_ENV) ? (string) $_ENV[$key] : null,
            ],
            'server' => [
                'exists' => array_key_exists($key, $_SERVER),
                'value' => array_key_exists($key, $_SERVER) ? (string) $_SERVER[$key] : null,
            ],
        ];
    }

    return $snapshot;
};

$restoreEnv = static function (array $snapshot): void {
    foreach ($snapshot as $key => $sources) {
        if ($sources['environment']['exists']) {
            putenv($key . '=' . $sources['environment']['value']);
        } else {
            putenv($key);
        }

        if ($sources['env']['exists']) {
            $_ENV[$key] = $sources['env']['value'];
        } else {
            unset($_ENV[$key]);
        }

        if ($sources['server']['exists']) {
            $_SERVER[$key] = $sources['server']['value'];
        } else {
            unset($_SERVER[$key]);
        }
    }
};

$assertEnvMatchesSnapshot = static function (array $snapshot): void {
    foreach ($snapshot as $key => $sources) {
        $environmentValue = getenv($key);
        if (($environmentValue !== false) !== $sources['environment']['exists']) {
            throw new RuntimeException('Environment restoration failed for ' . $key . '.');
        }
        if ($sources['environment']['exists'] && (string) $environmentValue !== $sources['environment']['value']) {
            throw new RuntimeException('Environment value restoration failed for ' . $key . '.');
        }

        $envExists = array_key_exists($key, $_ENV);
        if ($envExists !== $sources['env']['exists']) {
            throw new RuntimeException('$_ENV restoration failed for ' . $key . '.');
        }
        if ($envExists && (string) $_ENV[$key] !== $sources['env']['value']) {
            throw new RuntimeException('$_ENV value restoration failed for ' . $key . '.');
        }

        $serverExists = array_key_exists($key, $_SERVER);
        if ($serverExists !== $sources['server']['exists']) {
            throw new RuntimeException('$_SERVER restoration failed for ' . $key . '.');
        }
        if ($serverExists && (string) $_SERVER[$key] !== $sources['server']['value']) {
            throw new RuntimeException('$_SERVER value restoration failed for ' . $key . '.');
        }
    }
};

$set = static function (string $key, ?string $value): void {
    if ($value === null) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
        return;
    }

    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
};

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$rw2Commit = 'f2417d11bfff785ac1cf8a9425d71317c0950055';
$gitShow = static function (string $commit, string $relativePath) use ($root): string {
    $command = 'git -C ' . escapeshellarg($root)
        . ' show ' . escapeshellarg($commit . ':' . $relativePath);
    $source = shell_exec($command);
    if (!is_string($source) || $source === '') {
        throw new RuntimeException('Unable to load RW-2 historical snapshot: ' . $relativePath . '.');
    }

    return $source;
};

$snapshot = $snapshotEnv($keys);

foreach ($keys as $key) {
    $set($key, null);
}

try {
    $bootstrapSource = $gitShow($rw2Commit, 'bootstrap/app.php');
    $databaseBootstrapSource = $gitShow($rw2Commit, 'bootstrap/database.php');

    $assert(str_contains($bootstrapSource, 'use App\\Infrastructure\\Database\\ConnectionProvider;'), 'RW2-BOOT-03 legacy provider import missing.');
    $assert(str_contains($bootstrapSource, 'use App\\Infrastructure\\Database\\ReadConnectionProvider;'), 'RW2-BOOT-01 read provider import missing.');
    $assert(str_contains($bootstrapSource, 'use App\\Infrastructure\\Database\\WriteConnectionProvider;'), 'RW2-BOOT-02 write provider import missing.');
    $assert(str_contains($bootstrapSource, '$connection = new ConnectionProvider($databaseConfig);'), 'RW2-BOOT-03 legacy provider construction missing.');
    $assert(str_contains($bootstrapSource, '$readConnectionProvider = new ReadConnectionProvider($databaseConfig);'), 'RW2-BOOT-01 read provider construction missing.');
    $assert(str_contains($bootstrapSource, '$writeConnectionProvider = new WriteConnectionProvider($databaseConfig);'), 'RW2-BOOT-02 write provider construction missing.');
    $assert(!preg_match('/\$(?:read|write)ConnectionProvider\s*->\s*pdo\s*\(/', $bootstrapSource), 'RW2-BOOT-04/05 bootstrap eagerly opens a provider connection.');
    $assert(!str_contains($bootstrapSource, "Env::get('APP_DB_WRITE_"), 'RW2-BOOT-10 bootstrap reads WRITE environment directly.');
    $assert(str_contains($databaseBootstrapSource, "'database' => require CONFIG_PATH . '/database.php'"), 'bootstrap/database.php no longer exposes shared database config.');
    $assert(!str_contains($databaseBootstrapSource, 'ReadConnectionProvider'), 'bootstrap/database.php unexpectedly changed provider wiring.');
    $assert(!str_contains($databaseBootstrapSource, 'WriteConnectionProvider'), 'bootstrap/database.php unexpectedly changed provider wiring.');

    $configPath = $root . '/config/database.php';
    $set('APP_DB_HOST', 'localhost');
    $set('APP_DB_PORT', '3306');
    $set('APP_DB_NAME', 'rw_2_foundation_db');
    $set('APP_DB_CHARSET', 'utf8mb4');
    $set('APP_DB_COLLATION', 'utf8mb4_unicode_ci');
    $set('APP_DB_USER', 'legacy_read_fixture');
    $set('APP_DB_PASSWORD', 'legacy-read-password-fixture');
    $set('APP_DB_READ_USER', null);
    $set('APP_DB_READ_PASSWORD', null);
    $set('APP_DB_WRITE_USER', null);
    $set('APP_DB_WRITE_PASSWORD', null);

    $config = require $configPath;
    $assert(($config['read']['user'] ?? null) === 'legacy_read_fixture', 'RW2 legacy READ fixture was not resolved.');
    $assert(($config['write']['user'] ?? null) === '', 'RW2 legacy user populated WRITE config.');
    $assert(($config['write']['password'] ?? null) === '', 'RW2 legacy password populated WRITE config.');

    $legacyProvider = new ConnectionProvider($config);
    $readProvider = new ReadConnectionProvider($config);
    $writeProvider = new WriteConnectionProvider($config);
    $assert($legacyProvider instanceof ConnectionProvider, 'RW2 legacy provider construction failed.');
    $assert($readProvider instanceof ReadConnectionProvider, 'RW2 read provider construction failed.');
    $assert($writeProvider instanceof WriteConnectionProvider, 'RW2-BOOT-06/07 write provider construction failed without credentials.');

    try {
        $writeProvider->pdo();
        throw new RuntimeException('RW2-BOOT-12 write provider unexpectedly connected without credentials.');
    } catch (RuntimeException $exception) {
        $assert($exception->getMessage() === 'Write database credentials are not configured.', 'RW2-BOOT-12 write provider fail-closed contract changed.');
    }

    $set('APP_DB_NAME', 'r_erp_tickets_qa_2f');
    $set('APP_DB_USER', 'r_erp_tickets_qa_setup');
    $qaConfig = require $configPath;
    $assert(($qaConfig['write']['user'] ?? null) === '', 'RW2 QA legacy user populated WRITE provider.');
    $assert(($qaConfig['write']['password'] ?? null) === '', 'RW2 QA legacy password populated WRITE provider.');

    $consumerFiles = [
        'app/Http/Controllers/ProductRequestTicketController.php',
        'app/Domain/Tickets/ProductRequestTicketService.php',
        'app/Infrastructure/Repositories/ProductRequestTicketRepository.php',
        'app/Infrastructure/Repositories/ProductTicketEmailOutboxRepository.php',
        'app/Domain/Tickets/ProductTicketEmailOutboxService.php',
        'app/Domain/Mail/MailOutboxActionService.php',
        'app/Infrastructure/Repositories/MailOutboxActionRepository.php',
        'app/Infrastructure/Repositories/MailOutboxQueryRepository.php',
    ];

    foreach ($consumerFiles as $relativePath) {
        $source = $gitShow($rw2Commit, $relativePath);
        $assert(!str_contains($source, 'ReadConnectionProvider'), 'RW2-BOOT-08/09 consumer migration detected in ' . $relativePath . '.');
        $assert(!str_contains($source, 'WriteConnectionProvider'), 'RW2-BOOT-08/09 consumer migration detected in ' . $relativePath . '.');
    }

    echo "RW_2_DB_FREE_TESTS=PASS\n";
} finally {
    $restoreEnv($snapshot);
}

$assertEnvMatchesSnapshot($snapshot);
