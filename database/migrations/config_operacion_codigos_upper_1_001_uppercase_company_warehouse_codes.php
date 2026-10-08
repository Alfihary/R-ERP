<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

return new class implements Migration {
    private const UPPERCASE_PATTERN = "^[A-Z0-9]+([._-][A-Z0-9]+)*$";
    private const LOWERCASE_PATTERN = "^[a-z0-9]+([._-][a-z0-9]+)*$";

    public function id(): string
    {
        return 'config_operacion_codigos_upper_1_001_uppercase_company_warehouse_codes';
    }

    public function up(PDO $pdo): void
    {
        $this->assertMinTwoMigrationApplied($pdo);

        $this->dropCheckIfExists($pdo, 'empresas', 'chk_empresas_codigo');
        $this->dropCheckIfExists($pdo, 'almacenes', 'chk_almacenes_codigo');

        $pdo->exec('UPDATE empresas SET codigo = UPPER(codigo)');
        $pdo->exec('UPDATE almacenes SET codigo = UPPER(codigo)');

        $this->addCodeCheck($pdo, 'empresas', 'chk_empresas_codigo', true);
        $this->addCodeCheck($pdo, 'almacenes', 'chk_almacenes_codigo', true);
    }

    public function down(PDO $pdo): void
    {
        $this->dropCheckIfExists($pdo, 'almacenes', 'chk_almacenes_codigo');
        $this->dropCheckIfExists($pdo, 'empresas', 'chk_empresas_codigo');

        $pdo->exec('UPDATE almacenes SET codigo = LOWER(codigo)');
        $pdo->exec('UPDATE empresas SET codigo = LOWER(codigo)');

        $this->addCodeCheck($pdo, 'almacenes', 'chk_almacenes_codigo', false);
        $this->addCodeCheck($pdo, 'empresas', 'chk_empresas_codigo', false);
    }

    private function assertMinTwoMigrationApplied(PDO $pdo): void
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM schema_migrations
             WHERE migration = :migration'
        );
        $statement->execute([
            'migration' => 'config_operacion_codigos_min_2_001_update_company_warehouse_code_checks',
        ]);

        if ((int) $statement->fetchColumn() !== 1) {
            throw new RuntimeException(
                'CONFIG-OPERACION-CODIGOS-UPPER-1 requires CONFIG-OPERACION-CODIGOS-MIN-2.'
            );
        }
    }

    private function dropCheckIfExists(PDO $pdo, string $table, string $constraint): void
    {
        if (!$this->constraintExists($pdo, $constraint)) {
            return;
        }

        try {
            $pdo->exec(sprintf('ALTER TABLE %s DROP CHECK %s', $table, $constraint));
        } catch (PDOException) {
            $pdo->exec(sprintf('ALTER TABLE %s DROP CONSTRAINT %s', $table, $constraint));
        }
    }

    private function addCodeCheck(
        PDO $pdo,
        string $table,
        string $constraint,
        bool $uppercase
    ): void {
        $function = $uppercase ? 'UPPER' : 'LOWER';
        $pattern = $uppercase ? self::UPPERCASE_PATTERN : self::LOWERCASE_PATTERN;

        $pdo->exec(
            sprintf(
                "ALTER TABLE %s ADD CONSTRAINT %s CHECK ("
                . "CHAR_LENGTH(codigo) BETWEEN 2 AND 64 "
                . "AND codigo = %s(codigo) "
                . "AND codigo REGEXP '%s'"
                . ")",
                $table,
                $constraint,
                $function,
                $pattern
            )
        );
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
};
