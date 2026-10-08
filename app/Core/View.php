<?php

declare(strict_types=1);

namespace App\Core;

final class View
{
    /** @var array<string, mixed> */
    private static array $sharedLayoutData = [];

    /** @param array<string, mixed> $data */
    public static function shareWithLayout(array $data): void
    {
        self::$sharedLayoutData = $data + self::$sharedLayoutData;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function render(string $view, array $data = []): string
    {
        if (preg_match('/^[A-Za-z0-9_\/-]+$/', $view) !== 1) {
            throw new \InvalidArgumentException('Invalid view name.');
        }

        $viewsPath = APP_PATH . '/Views';
        $file = realpath($viewsPath . '/' . $view . '.php');
        $normalizedRoot = rtrim(str_replace('\\', '/', (string) realpath($viewsPath)), '/') . '/';
        $normalizedFile = str_replace('\\', '/', (string) $file);

        if ($file === false || !str_starts_with($normalizedFile, $normalizedRoot)) {
            throw new \RuntimeException('View not found.');
        }

        if ($view === 'layouts/app') {
            $data += self::$sharedLayoutData;
        }

        extract($data, EXTR_SKIP);

        ob_start();

        try {
            require $file;
            return (string) ob_get_clean();
        } catch (\Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }
    }
}
