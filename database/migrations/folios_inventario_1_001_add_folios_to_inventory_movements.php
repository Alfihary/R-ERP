<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

return new class implements Migration {
    public function id(): string
    {
        return 'folios_inventario_1_001_add_folios_to_inventory_movements';
    }

    public function up(PDO $pdo): void
    {
        foreach ([
            'inventario_1_001_create_inventory_core',
            'folios_1_001_create_document_numbering_tables',
        ] as $migration) {
            $statement = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM schema_migrations
                 WHERE migration = :migration'
            );
            $statement->execute(['migration' => $migration]);

            if ((int) $statement->fetchColumn() !== 1) {
                throw new RuntimeException(
                    'FOLIOS-INVENTARIO-1 requires ' . $migration . '.'
                );
            }
        }

        if ($this->columnExists($pdo, 'movimientos_inventario', 'folio_id')) {
            throw new RuntimeException(
                'FOLIOS-INVENTARIO-1 requires folio_id to be absent.'
            );
        }

        $pdo->exec(
            <<<'SQL'
            ALTER TABLE movimientos_inventario
                ADD COLUMN folio_id BIGINT UNSIGNED NULL
                    AFTER estado,
                ADD COLUMN folio VARCHAR(100)
                    CHARACTER SET ascii
                    COLLATE ascii_bin
                    NULL
                    AFTER folio_id,
                ADD KEY idx_movimientos_inventario_folio_id (folio_id),
                ADD KEY idx_movimientos_inventario_folio (folio),
                ADD CONSTRAINT chk_movimientos_inventario_folio CHECK (
                    folio IS NULL OR CHAR_LENGTH(folio) BETWEEN 1 AND 100
                ),
                ADD CONSTRAINT fk_movimientos_inventario_folio
                    FOREIGN KEY (folio_id)
                    REFERENCES documentos_folios (id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT
            SQL
        );
    }

    public function down(PDO $pdo): void
    {
        if (!$this->columnExists($pdo, 'movimientos_inventario', 'folio_id')) {
            return;
        }

        $pdo->exec(
            <<<'SQL'
            ALTER TABLE movimientos_inventario
                DROP FOREIGN KEY fk_movimientos_inventario_folio,
                DROP CHECK chk_movimientos_inventario_folio,
                DROP INDEX idx_movimientos_inventario_folio_id,
                DROP INDEX idx_movimientos_inventario_folio,
                DROP COLUMN folio_id,
                DROP COLUMN folio
            SQL
        );
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name'
        );
        $statement->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);

        return (int) $statement->fetchColumn() === 1;
    }
};
