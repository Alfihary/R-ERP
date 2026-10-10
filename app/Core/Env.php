<?php

declare(strict_types=1);

namespace App\Core;

final class Env
{
    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return;
        }

        try {
            $lineNumber = 0;
            while (($line = fgets($handle)) !== false) {
                $lineNumber++;
                $line = trim($line);

                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                if (str_starts_with($line, 'export ')) {
                    $line = trim(substr($line, 7));
                }

                if (!str_contains($line, '=')) {
                    throw new \RuntimeException(
                        sprintf('Invalid environment entry on line %d.', $lineNumber)
                    );
                }

                [$key, $value] = array_map('trim', explode('=', $line, 2));

                if (preg_match('/^[A-Z_][A-Z0-9_]*$/', $key) !== 1) {
                    throw new \RuntimeException(
                        sprintf('Invalid environment key on line %d.', $lineNumber)
                    );
                }

                $value = self::unquote($value);

                if (self::exists($key)) {
                    continue;
                }

                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
                putenv($key . '=' . $value);
            }
        } finally {
            fclose($handle);
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);

        if ($value !== false) {
            return $value;
        }

        if (array_key_exists($key, $_ENV)) {
            return (string) $_ENV[$key];
        }

        if (array_key_exists($key, $_SERVER)) {
            return (string) $_SERVER[$key];
        }

        return $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        if ($value === null) {
            return $default;
        }

        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => $default,
        };
    }

    private static function exists(string $key): bool
    {
        return getenv($key) !== false
            || array_key_exists($key, $_ENV)
            || array_key_exists($key, $_SERVER);
    }

    private static function unquote(string $value): string
    {
        $length = strlen($value);

        if ($length < 2) {
            return $value;
        }

        $first = $value[0];
        $last = $value[$length - 1];

        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
