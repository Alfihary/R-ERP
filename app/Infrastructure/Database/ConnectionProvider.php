<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use PDO;

final class ConnectionProvider
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
            $this->pdo = Connection::create($this->config);
        }

        return $this->pdo;
    }
}
