<?php

declare(strict_types=1);

use App\Core\Config;
use Composer\InstalledVersions;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

return static function (): array {
    $composerJsonPath = BASE_PATH . '/composer.json';
    $composerLockPath = BASE_PATH . '/composer.lock';
    $vendorAutoloadPath = BASE_PATH . '/vendor/autoload.php';
    $gitignorePath = BASE_PATH . '/.gitignore';

    $composerJson = is_file($composerJsonPath)
        ? json_decode((string) file_get_contents($composerJsonPath), true, 512, JSON_THROW_ON_ERROR)
        : [];
    $composerLock = is_file($composerLockPath)
        ? json_decode((string) file_get_contents($composerLockPath), true, 512, JSON_THROW_ON_ERROR)
        : [];
    $gitignore = is_file($gitignorePath) ? (string) file_get_contents($gitignorePath) : '';

    $packageNames = array_map(
        static fn (array $package): string => (string) ($package['name'] ?? ''),
        is_array($composerLock['packages'] ?? null) ? $composerLock['packages'] : []
    );

    $checks = [
        'composer_json_exists' => is_file($composerJsonPath),
        'composer_lock_exists' => is_file($composerLockPath),
        'vendor_autoload_exists' => is_file($vendorAutoloadPath),
        'vendor_is_ignored' => preg_match('/^\/vendor\/$/m', $gitignore) === 1,
        'composer_autoload_available' => defined('COMPOSER_AUTOLOAD_AVAILABLE') && COMPOSER_AUTOLOAD_AVAILABLE === true,
        'app_autoload_available' => class_exists(Config::class),
        'phpmailer_available' => class_exists(PHPMailer::class),
        'phpmailer_smtp_available' => class_exists(SMTP::class),
        'php_constraint_is_php_82' => ($composerJson['require']['php'] ?? null) === '>=8.2',
        'phpmailer_constraint_is_stable_6x' => ($composerJson['require']['phpmailer/phpmailer'] ?? null) === '^6.12',
        'only_phpmailer_package_locked' => $packageNames === ['phpmailer/phpmailer'],
        'phpmailer_version_detectable' => InstalledVersions::isInstalled('phpmailer/phpmailer')
            && InstalledVersions::getPrettyVersion('phpmailer/phpmailer') === 'v6.12.0',
    ];

    foreach ($checks as $name => $passed) {
        if (!$passed) {
            throw new RuntimeException('PHPMailer dependency audit failed: ' . $name . '.');
        }
    }

    return [
        'phase' => 'MAIL-DEPENDENCY-PHPMAILER-1',
        'status' => 'PASS',
        'phpmailer_version' => InstalledVersions::getPrettyVersion('phpmailer/phpmailer'),
        'checks' => $checks,
        'smtp_used' => false,
        'database_used' => false,
        'environment_loaded' => false,
        'secrets_read' => false,
    ];
};
