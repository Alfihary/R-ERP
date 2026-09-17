<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));

try {
    $command = $argv[1] ?? '';

    if ($command !== 'audit' || count($argv) !== 2) {
        throw new RuntimeException('Unknown MAIL-DEPENDENCY-PHPMAILER-1 CLI command.');
    }

    require BASE_PATH . '/bootstrap/autoload.php';

    $audit = require BASE_PATH . '/database/tests/mail_dependency_phpmailer_1_test.php';
    if (!is_callable($audit)) {
        throw new RuntimeException('PHPMailer dependency audit has an invalid contract.');
    }

    echo json_encode([
        'command' => $command,
        'result' => $audit(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
