<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

return new class implements Migration {
    public function id(): string
    {
        return 'folios_1_002_reserve_global_documentary_prefixes';
    }

    public function up(PDO $pdo): void
    {
        $this->assertDependency($pdo, 'folios_1_001_create_document_numbering_tables');
        $this->assertNotRegistered($pdo);
        $this->assertSeriesTablePresent($pdo);
        $this->assertTargetsAbsent($pdo);
        $this->assertLegacyDataCompatible($pdo);

        // MySQL DDL is not assumed to be rollbackable.  A partial ALTER is
        // deliberately left for explicit reconciliation instead of being
        // hidden by a destructive compensating action.
        $pdo->exec(
            <<<'SQL'
            ALTER TABLE series_documentales
                ADD COLUMN prefijo_reserva VARCHAR(20)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    GENERATED ALWAYS AS (UPPER(prefijo)) STORED
                    AFTER prefijo,
                ADD UNIQUE KEY uq_series_documentales_prefijo_global (prefijo_reserva)
            SQL
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec(
            <<<'SQL'
            ALTER TABLE series_documentales
                DROP INDEX uq_series_documentales_prefijo_global,
                DROP COLUMN prefijo_reserva
            SQL
        );
    }

    private function assertDependency(PDO $pdo, string $migration): void
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM schema_migrations WHERE migration = :migration'
        );
        $statement->execute(['migration' => $migration]);

        if ((int) $statement->fetchColumn() !== 1) {
            throw new RuntimeException(
                'FOLIOS-1-GLOBAL-PREFIX requires ' . $migration . '.'
            );
        }
    }

    private function assertNotRegistered(PDO $pdo): void
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM schema_migrations WHERE migration = :migration'
        );
        $statement->execute(['migration' => $this->id()]);

        if ((int) $statement->fetchColumn() !== 0) {
            throw new RuntimeException(
                'FOLIOS-1-GLOBAL-PREFIX migration is already registered.'
            );
        }
    }

    private function assertSeriesTablePresent(PDO $pdo): void
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table_name'
        );
        $statement->execute(['table_name' => 'series_documentales']);

        if ((int) $statement->fetchColumn() !== 1) {
            throw new RuntimeException(
                'FOLIOS-1-GLOBAL-PREFIX requires series_documentales.'
            );
        }
    }

    private function assertTargetsAbsent(PDO $pdo): void
    {
        $column = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name'
        );
        $column->execute([
            'table_name' => 'series_documentales',
            'column_name' => 'prefijo_reserva',
        ]);
        if ((int) $column->fetchColumn() !== 0) {
            throw new RuntimeException(
                'FOLIOS-1-GLOBAL-PREFIX found a partial generated column.'
            );
        }

        $index = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND index_name = :index_name'
        );
        $index->execute([
            'table_name' => 'series_documentales',
            'index_name' => 'uq_series_documentales_prefijo_global',
        ]);
        if ((int) $index->fetchColumn() !== 0) {
            throw new RuntimeException(
                'FOLIOS-1-GLOBAL-PREFIX found a partial unique index.'
            );
        }
    }

    private function assertLegacyDataCompatible(PDO $pdo): void
    {
        $invalid = (int) $pdo->query(
            'SELECT COUNT(*)
             FROM series_documentales
             WHERE prefijo IS NULL
                OR CHAR_LENGTH(TRIM(prefijo)) = 0
                OR CHAR_LENGTH(prefijo) NOT BETWEEN 1 AND 20
                OR REGEXP_LIKE(
                    CONVERT(prefijo USING utf8mb4) COLLATE utf8mb4_0900_as_cs,
                    \'[^A-Z0-9]\',
                    \'c\'
               )'
        )->fetchColumn();
        if ($invalid !== 0) {
            throw new RuntimeException(
                'FOLIOS-1-GLOBAL-PREFIX found prefixes outside the current administrative contract.'
            );
        }

        $duplicates = (int) $pdo->query(
            'SELECT COUNT(*)
             FROM (
                 SELECT UPPER(prefijo) AS normalized_prefix
                 FROM series_documentales
                 GROUP BY UPPER(prefijo)
                 HAVING COUNT(*) > 1
             ) AS conflicts'
        )->fetchColumn();
        if ($duplicates !== 0) {
            throw new RuntimeException(
                'FOLIOS-1-GLOBAL-PREFIX found duplicate normalized prefixes.'
            );
        }
    }
};
