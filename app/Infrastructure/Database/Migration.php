<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use PDO;

interface Migration
{
    public function id(): string;

    public function up(PDO $pdo): void;

    public function down(PDO $pdo): void;
}
