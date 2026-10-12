<?php

declare(strict_types=1);

use App\Domain\Products\ProductImageOrphanCleanupService;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Repositories\ProductDocumentRepository;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));

try {
    $command = $argv[1] ?? '';
    if (!in_array($command, ['dry-run', 'cleanup'], true)) {
        throw new RuntimeException('Use dry-run or cleanup.');
    }

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

    $expected = trim((string) ($options['database'] ?? ''));
    $confirmed = trim((string) ($options['confirm-database'] ?? ''));
    if ($expected === '' || $expected !== $confirmed) {
        throw new RuntimeException('The database name must be confirmed twice.');
    }

    $config = require BASE_PATH . '/bootstrap/database.php';
    if (strtolower((string) $config->get('app.env', 'production')) === 'production') {
        throw new RuntimeException('Image cleanup is disabled in production.');
    }

    $databaseConfig = $config->get('database', []);
    if (!is_array($databaseConfig) || ($databaseConfig['name'] ?? '') !== $expected) {
        throw new RuntimeException('Database target does not match confirmation.');
    }

    $connection = new ConnectionProvider($databaseConfig);
    $documents = new ProductDocumentRepository($connection);
    $storagePath = (string) $config->get('paths.STORAGE_PATH', STORAGE_PATH);
    $service = new ProductImageOrphanCleanupService($documents, $storagePath);
    $paths = $service->cleanup($command === 'dry-run');

    echo json_encode([
        'command' => $command,
        'dry_run' => $command === 'dry-run',
        'candidate_count' => count($paths),
        'candidates' => $paths,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (PDOException $exception) {
    fwrite(STDERR, 'Product image cleanup database operation failed with SQLSTATE['
        . $exception->getCode() . '].' . PHP_EOL);
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
