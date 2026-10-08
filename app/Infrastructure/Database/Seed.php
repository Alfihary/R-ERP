<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use PDO;

interface Seed
{
    public function id(): string;

    public function run(PDO $pdo): void;

    public function rollback(PDO $pdo): void;
}
