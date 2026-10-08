<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use PDO;
use PDOException;

final class Connection
{
    /**
     * @param array<string, mixed> $config
     */
    public static function create(array $config): PDO
    {
        $host = trim((string) ($config['host'] ?? ''));
        $port = (int) ($config['port'] ?? 0);
        $database = trim((string) ($config['name'] ?? ''));
        $username = trim((string) ($config['username'] ?? ''));
        $password = (string) ($config['password'] ?? '');
        $charset = trim((string) ($config['charset'] ?? ''));

        if ($host === '' || $port < 1 || $port > 65535 || $username === '') {
            throw new \RuntimeException('Database connection configuration is incomplete.');
        }

        if (preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1) {
            throw new \RuntimeException('The configured database name is missing or invalid.');
        }

        if (preg_match('/^[A-Za-z0-9_]+$/', $charset) !== 1) {
            throw new \RuntimeException('The configured database charset is invalid.');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $host,
            $port,
            $database,
            $charset
        );

        try {
            return new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
        } catch (PDOException $exception) {
            throw new \RuntimeException(
                'Unable to establish the database connection.',
                0,
                $exception
            );
        }
    }
}
