<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use PDO;

final class MigrationRunner
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function migrate(Migration $migration): string
    {
        $this->ensureRepository();

        if ($this->isApplied($migration->id())) {
            return 'already_applied';
        }

        $migration->up($this->pdo);

        $statement = $this->pdo->prepare(
            'INSERT INTO schema_migrations (migration, applied_at) VALUES (:migration, CURRENT_TIMESTAMP)'
        );
        $statement->execute(['migration' => $migration->id()]);

        return 'applied';
    }

    public function rollback(Migration $migration): string
    {
        $this->ensureRepository();

        if (!$this->isApplied($migration->id())) {
            return 'not_applied';
        }

        $migration->down($this->pdo);

        $statement = $this->pdo->prepare(
            'DELETE FROM schema_migrations WHERE migration = :migration'
        );
        $statement->execute(['migration' => $migration->id()]);

        return 'rolled_back';
    }

    /**
     * @return list<array{migration: string, applied_at: string}>
     */
    public function status(): array
    {
        $this->ensureRepository();

        $statement = $this->pdo->query(
            'SELECT migration, applied_at FROM schema_migrations ORDER BY applied_at, migration'
        );

        return $statement->fetchAll();
    }

    private function ensureRepository(): void
    {
        $this->pdo->exec(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS schema_migrations (
                migration VARCHAR(190) NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL
        );
    }

    private function isApplied(string $migration): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM schema_migrations WHERE migration = :migration'
        );
        $statement->execute(['migration' => $migration]);

        return (int) $statement->fetchColumn() === 1;
    }
}
