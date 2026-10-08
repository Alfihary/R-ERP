<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use PDO;

interface DatabaseTest
{
    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array;
}
