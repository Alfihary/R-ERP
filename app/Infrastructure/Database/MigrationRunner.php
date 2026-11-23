<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use InvalidArgumentException;
use PDO;
use RuntimeException;

final class MigrationRunner
{
    public const MODE_SCHEMA_SETUP = 'schema_setup';
    public const MODE_TEST_EXECUTION = 'test_execution';

    private const TEST_DATABASE = 'r_erp_tickets_qa_2f';
    private const TEST_ACCOUNT = 'r_erp_tickets_qa_runner@localhost';
    private const TEST_MIGRATION = 'tp_partidas_estados_db_1_001_create_ticket_product_tables';

    /** @var list<string> */
    private const TEST_DEPENDENCY_MIGRATIONS = [
        'db_core_0_001_create_core_identity_tables',
        'db_scope_1_001_create_scope_tables',
        'db_catalogos_1_001_create_base_catalog_tables',
        'db_sat_1_001_create_sat_catalogs',
        'folios_1_001_create_document_numbering_tables',
    ];

    /** @var list<string> */
    private const TEST_TABLES = [
        'tickets_productos',
        'tickets_productos_partidas',
        'tickets_productos_adjuntos',
        'tickets_productos_comentarios',
        'tickets_productos_eventos',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $mode = self::MODE_SCHEMA_SETUP
    ) {
        if (!in_array($this->mode, [
            self::MODE_SCHEMA_SETUP,
            self::MODE_TEST_EXECUTION,
        ], true)) {
            throw new InvalidArgumentException('Invalid migration runner mode.');
        }
    }

    public function migrate(Migration $migration): string
    {
        if ($this->mode === self::MODE_TEST_EXECUTION) {
            $this->assertTestExecutionReady($migration->id());

            return 'already_applied';
        }

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
        if ($this->mode === self::MODE_TEST_EXECUTION) {
            $this->assertTestExecutionReady($migration->id());

            throw new RuntimeException(
                'Migration rollback is unavailable in test execution mode.'
            );
        }

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
        if ($this->mode === self::MODE_TEST_EXECUTION) {
            $this->assertTestExecutionReady();
        } else {
            $this->ensureRepository();
        }

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

    private function assertTestExecutionReady(?string $migrationId = null): void
    {
        if ($migrationId !== null && $migrationId !== self::TEST_MIGRATION) {
            throw new RuntimeException('Unexpected migration for test execution mode.');
        }

        $database = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($database !== self::TEST_DATABASE) {
            throw new RuntimeException('Test execution requires the isolated QA database.');
        }

        $identity = $this->pdo->query('SELECT USER(), CURRENT_USER()')->fetch(PDO::FETCH_NUM);
        if (!is_array($identity)
            || (string) ($identity[0] ?? '') !== self::TEST_ACCOUNT
            || (string) ($identity[1] ?? '') !== self::TEST_ACCOUNT
        ) {
            throw new RuntimeException('Test execution requires the authorized QA account.');
        }

        if (!$this->tableExistsReadOnly('schema_migrations')) {
            throw new RuntimeException('QA schema_migrations table is not prepared.');
        }

        if (!$this->isApplied(self::TEST_MIGRATION)) {
            throw new RuntimeException('Required Tickets migration is not applied.');
        }

        $this->assertDependenciesApplied();

        foreach (self::TEST_TABLES as $table) {
            if (!$this->tableExistsReadOnly($table)) {
                throw new RuntimeException('Required Tickets table is missing.');
            }
        }
    }

    private function assertDependenciesApplied(): void
    {
        $placeholders = implode(
            ', ',
            array_fill(0, count(self::TEST_DEPENDENCY_MIGRATIONS), '?')
        );
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM schema_migrations
             WHERE migration IN (' . $placeholders . ')'
        );
        $statement->execute(self::TEST_DEPENDENCY_MIGRATIONS);

        if ((int) $statement->fetchColumn() !== count(self::TEST_DEPENDENCY_MIGRATIONS)) {
            throw new RuntimeException('Required QA migration dependencies are missing.');
        }
    }

    private function tableExistsReadOnly(string $table): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table_name'
        );
        $statement->execute(['table_name' => $table]);

        return (int) $statement->fetchColumn() === 1;
    }
}
