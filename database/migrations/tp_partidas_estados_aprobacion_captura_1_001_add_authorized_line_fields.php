<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

return new class implements Migration {
    public function id(): string
    {
        return 'tp_partidas_estados_aprobacion_captura_1_001_add_authorized_line_fields';
    }

    public function up(PDO $pdo): void
    {
        $this->assertTicketPartidasTableExists($pdo);

        if (!$this->columnExists($pdo, 'tickets_productos_partidas', 'clave_autorizada')) {
            $pdo->exec(
                <<<'SQL'
                ALTER TABLE tickets_productos_partidas
                    ADD COLUMN clave_autorizada VARCHAR(16) NULL AFTER motivo_rechazo,
                    ADD COLUMN descripcion_autorizada VARCHAR(255) NULL AFTER clave_autorizada,
                    ADD COLUMN unidad_sat_id_autorizada BIGINT UNSIGNED NULL AFTER descripcion_autorizada,
                    ADD COLUMN clave_sat_id_autorizada BIGINT UNSIGNED NULL AFTER unidad_sat_id_autorizada,
                    ADD KEY idx_tickets_productos_partidas_unidad_sat_autorizada (unidad_sat_id_autorizada),
                    ADD KEY idx_tickets_productos_partidas_clave_sat_autorizada (clave_sat_id_autorizada),
                    ADD CONSTRAINT chk_tickets_productos_partidas_clave_autorizada CHECK (
                        clave_autorizada IS NULL OR clave_autorizada REGEXP '^[A-Za-z0-9._-]{1,16}$'
                    ),
                    ADD CONSTRAINT chk_tickets_productos_partidas_descripcion_autorizada CHECK (
                        descripcion_autorizada IS NULL OR CHAR_LENGTH(TRIM(descripcion_autorizada)) > 0
                    ),
                    ADD CONSTRAINT fk_tickets_productos_partidas_unidad_sat_autorizada
                        FOREIGN KEY (unidad_sat_id_autorizada) REFERENCES unidades_sat (id)
                        ON UPDATE RESTRICT ON DELETE SET NULL,
                    ADD CONSTRAINT fk_tickets_productos_partidas_clave_sat_autorizada
                        FOREIGN KEY (clave_sat_id_autorizada) REFERENCES claves_sat (id)
                        ON UPDATE RESTRICT ON DELETE SET NULL
                SQL
            );
        }
    }

    public function down(PDO $pdo): void
    {
        $this->dropForeignKeyIfExists($pdo, 'tickets_productos_partidas', 'fk_tickets_productos_partidas_clave_sat_autorizada');
        $this->dropForeignKeyIfExists($pdo, 'tickets_productos_partidas', 'fk_tickets_productos_partidas_unidad_sat_autorizada');
        $this->dropIndexIfExists($pdo, 'tickets_productos_partidas', 'idx_tickets_productos_partidas_clave_sat_autorizada');
        $this->dropIndexIfExists($pdo, 'tickets_productos_partidas', 'idx_tickets_productos_partidas_unidad_sat_autorizada');
        $this->dropCheckIfExists($pdo, 'tickets_productos_partidas', 'chk_tickets_productos_partidas_descripcion_autorizada');
        $this->dropCheckIfExists($pdo, 'tickets_productos_partidas', 'chk_tickets_productos_partidas_clave_autorizada');

        foreach ([
            'clave_sat_id_autorizada',
            'unidad_sat_id_autorizada',
            'descripcion_autorizada',
            'clave_autorizada',
        ] as $column) {
            if ($this->columnExists($pdo, 'tickets_productos_partidas', $column)) {
                $pdo->exec('ALTER TABLE tickets_productos_partidas DROP COLUMN ' . $column);
            }
        }
    }

    private function assertTicketPartidasTableExists(PDO $pdo): void
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table'
        );
        $statement->execute(['table' => 'tickets_productos_partidas']);

        if ((int) $statement->fetchColumn() !== 1) {
            throw new RuntimeException('tickets_productos_partidas must exist before adding authorized approval fields.');
        }
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table
               AND column_name = :column'
        );
        $statement->execute(['table' => $table, 'column' => $column]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function dropForeignKeyIfExists(PDO $pdo, string $table, string $constraint): void
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.referential_constraints
             WHERE constraint_schema = DATABASE()
               AND table_name = :table
               AND constraint_name = :constraint'
        );
        $statement->execute(['table' => $table, 'constraint' => $constraint]);

        if ((int) $statement->fetchColumn() === 1) {
            $pdo->exec('ALTER TABLE ' . $table . ' DROP FOREIGN KEY ' . $constraint);
        }
    }

    private function dropIndexIfExists(PDO $pdo, string $table, string $index): void
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = :table
               AND index_name = :index'
        );
        $statement->execute(['table' => $table, 'index' => $index]);

        if ((int) $statement->fetchColumn() > 0) {
            $pdo->exec('ALTER TABLE ' . $table . ' DROP INDEX ' . $index);
        }
    }

    private function dropCheckIfExists(PDO $pdo, string $table, string $constraint): void
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.check_constraints
             WHERE constraint_schema = DATABASE()
               AND constraint_name = :constraint'
        );
        $statement->execute(['constraint' => $constraint]);

        if ((int) $statement->fetchColumn() === 1) {
            $pdo->exec('ALTER TABLE ' . $table . ' DROP CHECK ' . $constraint);
        }
    }
};
