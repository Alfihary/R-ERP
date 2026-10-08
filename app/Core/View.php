<?php

declare(strict_types=1);

namespace App\Core;

final class View
{
    private static ?object $sidebarNavigation = null;

    public static function setSidebarNavigation(object $navigation): void
    {
        self::$sidebarNavigation = $navigation;
    }

    /** @return array<string, mixed>|null */
    public static function sidebarNavigationForUser(int $userId): ?array
    {
        if (self::$sidebarNavigation === null || !method_exists(self::$sidebarNavigation, 'forUser')) {
            return null;
        }

        $navigation = self::$sidebarNavigation->forUser($userId);
        return is_array($navigation) ? $navigation : null;
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
