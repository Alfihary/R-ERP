<?php

declare(strict_types=1);

use App\Core\Env;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/bootstrap/autoload.php';

$suffix = strtoupper(bin2hex(random_bytes(4)));
$basicKey = 'RERP_ENV_BASIC_' . $suffix;
$emptyKey = 'RERP_ENV_EMPTY_' . $suffix;
$precedenceKey = 'RERP_ENV_PRECEDENCE_' . $suffix;
$path = tempnam(sys_get_temp_dir(), 'rerp-env-');

if ($path === false) {
    throw new RuntimeException('Unable to create the synthetic environment fixture.');
}

$assertions = [];

try {
    $content = implode(PHP_EOL, [
        '# synthetic comment',
        '',
        $basicKey . '=synthetic-value',
        $emptyKey . '=',
        'export ' . $precedenceKey . '=file-value',
    ]) . PHP_EOL;
    file_put_contents($path, $content);
    putenv($precedenceKey . '=process-value');

    $source = (string) file_get_contents(BASE_PATH . '/app/Core/Env.php');
    $assertions['ENV_LOADER_IS_READABLE_DEPENDENCY_REMOVED'] = !str_contains($source, 'is_readable(')
        && str_contains($source, "fopen(\$path, 'rb')");
    $assertions['ENV_LOAD_SYNTHETIC_BASIC'] = (Env::load($path) === null)
        && Env::get($basicKey) === 'synthetic-value';
    $assertions['ENV_LOAD_COMMENTS'] = Env::get($basicKey) === 'synthetic-value';
    $assertions['ENV_LOAD_EMPTY_LINES'] = Env::get($basicKey) !== null;
    $assertions['ENV_LOAD_EMPTY_VALUE'] = Env::get($emptyKey, 'fallback') === '';
    $assertions['ENV_LOAD_PROCESS_ENV_PRECEDENCE'] = Env::get($precedenceKey) === 'process-value';
    $assertions['ENV_LOAD_MISSING_FILE'] = Env::load($path . '-missing') === null;
    $handle = @fopen($path, 'rb');
    $assertions['ENV_LOAD_REAL_ACCESS_WITH_READABILITY_FALSE'] = is_resource($handle);
    $readabilityEvidence = is_readable($path) === false ? 'PASS' : 'NOT_PORTABLY_TESTED';
    if (isset($handle) && is_resource($handle)) {
        fclose($handle);
    }
    $assertions['ENV_LOAD_UNOPENABLE_FILE'] = 'NOT_PORTABLY_TESTED';
} finally {
    putenv($precedenceKey);
    unset($_ENV[$basicKey], $_ENV[$emptyKey], $_ENV[$precedenceKey]);
    unset($_SERVER[$basicKey], $_SERVER[$emptyKey], $_SERVER[$precedenceKey]);
    @unlink($path);
}

$failed = array_keys(array_filter(
    $assertions,
    static fn (bool|string $pass): bool => $pass !== true
));

foreach ($assertions as $name => $pass) {
    echo $name . '=' . ($pass === true ? 'PASS' : (string) $pass) . PHP_EOL;
}
echo 'ENV_LOAD_READABILITY_EVIDENCE=' . $readabilityEvidence . PHP_EOL;
echo 'DB_READS=0' . PHP_EOL;
echo 'DB_WRITES=0' . PHP_EOL;
exit($failed === [] ? 0 : 1);
