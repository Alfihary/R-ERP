<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use PDO;

final class WriteConnectionProvider
{
    private ?PDO $pdo = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private readonly array $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $connectionConfig = $this->connectionConfig();
            $this->pdo = Connection::create($connectionConfig);
        }

        return $this->pdo;
    }

    /** @return array<string, mixed> */
    private function connectionConfig(): array
    {
        $common = is_array($this->config['common'] ?? null)
            ? $this->config['common']
            : $this->config;
        $write = is_array($this->config['write'] ?? null)
            ? $this->config['write']
            : [];
        $user = trim((string) ($write['user'] ?? ''));
        $password = (string) ($write['password'] ?? '');

        if ($user === '' || $password === '') {
            throw new \RuntimeException('Write database credentials are not configured.');
        }

        return [
            'host' => $common['host'] ?? '',
            'port' => $common['port'] ?? 0,
            'name' => $common['database'] ?? ($common['name'] ?? ''),
            'username' => $user,
            'password' => $password,
            'charset' => $common['charset'] ?? '',
        ];
    }
}
