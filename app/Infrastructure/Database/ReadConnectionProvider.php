<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use PDO;

final class ReadConnectionProvider
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
            $this->pdo = Connection::create($this->connectionConfig());
        }

        return $this->pdo;
    }

    /** @return array<string, mixed> */
    private function connectionConfig(): array
    {
        $common = is_array($this->config['common'] ?? null)
            ? $this->config['common']
            : $this->config;
        $read = is_array($this->config['read'] ?? null)
            ? $this->config['read']
            : $this->config;

        return [
            'host' => $common['host'] ?? '',
            'port' => $common['port'] ?? 0,
            'name' => $common['database'] ?? ($common['name'] ?? ''),
            'username' => $read['user'] ?? ($read['username'] ?? ''),
            'password' => $read['password'] ?? '',
            'charset' => $common['charset'] ?? '',
        ];
    }
}
