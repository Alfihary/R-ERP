<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));

try {
    $command = $argv[1] ?? '';
    if ($command !== 'functional:test' || count($argv) !== 2) {
        throw new RuntimeException('Unknown CORREO-PLANTILLA-RUNTIME-IMPLEMENTACION-1 CLI command.');
    }

    require BASE_PATH . '/bootstrap/autoload.php';
    $test = require BASE_PATH . '/database/tests/correo_plantilla_runtime_1_test.php';
    if (!is_callable($test)) {
        throw new RuntimeException('Runtime email template test has an invalid contract.');
    }

    echo json_encode([
        'command' => $command,
        'result' => $test(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
