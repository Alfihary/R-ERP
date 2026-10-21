<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
define('BASE_PATH', dirname(__DIR__));

$args = array_slice($argv, 1);
$command = $args[0] ?? '';
$database = null;
$confirm = null;
foreach ($args as $arg) { if (str_starts_with($arg, '--database=')) $database=substr($arg,11); if (str_starts_with($arg, '--confirm-database=')) $confirm=substr($arg,19); }
if (!in_array($command, ['db:test', 'preflight'], true) || !$database || $database !== $confirm) { fwrite(STDERR, "Usage: db:test|preflight --database=... --confirm-database=...\n"); exit(2); }
$config = require BASE_PATH . '/bootstrap/database.php';
if ((string)$config->get('app.env') === 'production') { fwrite(STDERR, "Production database rejected.\n"); exit(3); }
$dbConfig = $config->get('database');
if (!is_array($dbConfig) || ($dbConfig['name'] ?? null) !== $database) { fwrite(STDERR, "Database target mismatch.\n"); exit(4); }
$connection = new App\Infrastructure\Database\ConnectionProvider($dbConfig);
$pdo = $connection->pdo();
$GLOBALS['productos_historial_audit_connection'] = $connection;
$test = require BASE_PATH . '/database/tests/productos_historial_audit_contract_1_test.php';
if ($command === 'preflight') {
    try {
        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) {
            throw new RuntimeException('Unexpected database.');
        }
        echo json_encode(['command'=>'preflight','result'=>$test->preflight($pdo)],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, json_encode(['command'=>'preflight','error'=>get_class($e),'message'=>'Read-only preflight failed.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL);
        exit(1);
    }
}
try {
    $result=$test->run($pdo,$database);
    if (is_object($test) && method_exists($test, 'preCreateTrace')) {
        $result['pre_create_trace'] = $test->preCreateTrace();
    }
    echo json_encode(['command'=>'db:test','result'=>$result],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit(empty($result['_failed_checks'])?0:1);
}
catch (Throwable $e) {
    $sanitizeValue = static function (mixed $value, int $depth = 0) use (&$sanitizeValue): mixed {
        if ($depth > 4) {
            return '[TRUNCATED]';
        }
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value)) {
            return strlen($value) > 512 ? substr($value, 0, 512) . '[TRUNCATED]' : $value;
        }
        if (is_resource($value)) {
            return '[RESOURCE]';
        }
        if (is_object($value)) {
            return '[OBJECT:' . get_class($value) . ']';
        }
        if (is_array($value)) {
            $result = [];
            $count = 0;
            foreach ($value as $key => $child) {
                if ($count++ >= 32) {
                    $result['__truncated__'] = true;
                    break;
                }
                $safeKey = strlen((string) $key) > 80
                    ? substr((string) $key, 0, 80) . '[TRUNCATED]'
                    : (string) $key;
                if (preg_match('/password|passwd|secret|token|cookie|session|csrf|authorization|dsn|credential|smtp|api_key|apikey/i', $safeKey)) {
                    $result[$safeKey] = '[REDACTED]';
                    continue;
                }
                $result[$safeKey] = $sanitizeValue($child, $depth + 1);
            }
            return $result;
        }
        return '[UNSUPPORTED]';
    };
    $sanitize = static function (Throwable $exception): string {
        $message = $exception->getMessage();
        foreach (['password', 'token', 'cookie', 'session', 'csrf', 'authorization', 'dsn', 'credential', 'smtp'] as $secret) {
            $message = preg_replace('/' . preg_quote($secret, '/') . '/i', '[REDACTED]', $message) ?? '[REDACTED]';
        }
        return $message;
    };
    $payload = ['command'=>'db:test','error'=>get_class($e),'message'=>$sanitize($e)];
    if ($e instanceof \App\Domain\Products\ProductValidationException) {
        $payload['validation_errors'] = $sanitizeValue($e->errors());
    }
    if (is_object($test) && method_exists($test, 'cleanupFailure')) {
        $cleanupFailure = $test->cleanupFailure();
        if ($cleanupFailure instanceof Throwable) {
            $payload['cleanup_error'] = [
                'class' => get_class($cleanupFailure),
                'message' => $sanitize($cleanupFailure),
            ];
        }
        if (method_exists($test, 'postFailureResiduals')) {
            $payload['post_failure_residuals'] = $test->postFailureResiduals();
        }
        if (method_exists($test, 'postFailureResidualError')) {
            $residualError = $test->postFailureResidualError();
            if ($residualError instanceof Throwable) {
                $payload['post_failure_residual_error'] = [
                    'class' => get_class($residualError),
                    'message' => $sanitize($residualError),
                ];
            }
        }
        if (method_exists($test, 'preCreateTrace')) {
            $trace = $test->preCreateTrace();
            if ($trace !== null) {
                $payload['pre_create_trace'] = $trace;
            }
        }
        if (method_exists($test, 'imageFinalDiagnostics')) {
            $payload['image_final_diagnostics'] = $test->imageFinalDiagnostics();
        }
        if (method_exists($test, 'tempCleanupDiagnostics')) {
            $payload['temp_cleanup_diagnostics'] = $test->tempCleanupDiagnostics();
        }
    }
    fwrite(STDERR, json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL);
    exit(1);
}
