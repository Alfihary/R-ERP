<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

return new class implements Migration {
    private const CODE_PATTERN = "^[a-z0-9]+([._-][a-z0-9]+)*$";

    public function id(): string
    {
        return 'config_operacion_codigos_min_2_001_update_company_warehouse_code_checks';
    }

    public function up(PDO $pdo): void
    {
        $this->assertScopeMigrationApplied($pdo);
        $this->replaceCodeCheck($pdo, 'empresas', 'chk_empresas_codigo', 2);
        $this->replaceCodeCheck($pdo, 'almacenes', 'chk_almacenes_codigo', 2);
    }

    public function down(PDO $pdo): void
    {
        $this->replaceCodeCheck($pdo, 'almacenes', 'chk_almacenes_codigo', 3);
        $this->replaceCodeCheck($pdo, 'empresas', 'chk_empresas_codigo', 3);
    }

    private function assertScopeMigrationApplied(PDO $pdo): void
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM schema_migrations
             WHERE migration = :migration'
        );
        $statement->execute([
            'migration' => 'db_scope_1_001_create_scope_tables',
        ]);

        if ((int) $statement->fetchColumn() !== 1) {
            throw new RuntimeException(
                'CONFIG-OPERACION-CODIGOS-MIN-2 requires DB-SCOPE-1.'
            );
        }
    }

    private function replaceCodeCheck(
        PDO $pdo,
        string $table,
        string $constraint,
        int $minimumLength
    ): void {
        $expected = $this->expectedClause($minimumLength);

        if ($this->constraintClauseMatches($pdo, $constraint, $minimumLength)) {
            return;
        }

        if ($this->constraintExists($pdo, $constraint)) {
            $this->dropCheck($pdo, $table, $constraint);
        }

        $pdo->exec(
            sprintf(
                "ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s)",
                $table,
                $constraint,
                $expected
            )
        );
    }

    private function dropCheck(PDO $pdo, string $table, string $constraint): void
    {
        try {
            $pdo->exec(sprintf('ALTER TABLE %s DROP CHECK %s', $table, $constraint));
        } catch (PDOException) {
            $pdo->exec(sprintf('ALTER TABLE %s DROP CONSTRAINT %s', $table, $constraint));
        }
    }

    private function constraintExists(PDO $pdo, string $constraint): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.CHECK_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND CONSTRAINT_NAME = :constraint_name'
        );
        $statement->execute(['constraint_name' => $constraint]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function constraintClauseMatches(
        PDO $pdo,
        string $constraint,
        int $minimumLength
    ): bool {
        $statement = $pdo->prepare(
            'SELECT CHECK_CLAUSE
             FROM information_schema.CHECK_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND CONSTRAINT_NAME = :constraint_name
             LIMIT 1'
        );
        $statement->execute(['constraint_name' => $constraint]);
        $clause = (string) $statement->fetchColumn();

        return str_contains($clause, 'char_length(`codigo`) between ' . $minimumLength . ' and 64')
            || str_contains($clause, 'CHAR_LENGTH(`codigo`) BETWEEN ' . $minimumLength . ' AND 64')
            || str_contains($clause, 'CHAR_LENGTH(codigo) BETWEEN ' . $minimumLength . ' AND 64');
    }

    private function expectedClause(int $minimumLength): string
    {
        return sprintf(
            "CHAR_LENGTH(codigo) BETWEEN %d AND 64 "
            . "AND codigo = LOWER(codigo) "
            . "AND codigo REGEXP '%s'",
            $minimumLength,
            self::CODE_PATTERN
        );
    }
};
