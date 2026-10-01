<?php

declare(strict_types=1);

use App\Infrastructure\Database\Migration;

return new class implements Migration {
    private const ID = 'correo_outbox_url_check_correccion_1_001_allow_safe_absolute_urls';
    private const DEPENDENCY = 'tp_partidas_estados_correo_outbox_db_1_001_create_ticket_product_email_outbox';
    private const TABLE = 'tickets_productos_correos';
    private const CONSTRAINT = 'chk_tickets_productos_correos_no_sensitive';

    public function id(): string
    {
        return self::ID;
    }

    public function up(PDO $pdo): void
    {
        $this->assertDependency($pdo);
        $this->assertTable($pdo);

        $clause = $this->constraintClause($pdo);
        if ($this->isCorrectedClause($clause)) {
            return;
        }
        $this->assertOriginalClause($clause);
        $this->assertRowsComply($pdo, $this->correctedPattern());
        $this->replaceConstraint($pdo, $this->correctedPattern());
    }

    public function down(PDO $pdo): void
    {
        $this->assertDependency($pdo);
        $this->assertTable($pdo);

        $clause = $this->constraintClause($pdo);
        if ($this->isOriginalClause($clause)) {
            return;
        }
        $this->assertCorrectedClause($clause);
        $this->assertRowsComply($pdo, $this->originalPattern());
        $this->replaceConstraint($pdo, $this->originalPattern());
    }

    private function assertDependency(PDO $pdo): void
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM schema_migrations WHERE migration = :migration'
        );
        $statement->execute(['migration' => self::DEPENDENCY]);

        if ((int) $statement->fetchColumn() !== 1) {
            throw new RuntimeException('CORREO-OUTBOX-URL-CHECK-CORRECCION-1 requires the mail outbox migration.');
        }
    }

    private function assertTable(PDO $pdo): void
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name'
        );
        $statement->execute(['table_name' => self::TABLE]);

        if ((int) $statement->fetchColumn() !== 1) {
            throw new RuntimeException('CORREO-OUTBOX-URL-CHECK-CORRECCION-1 requires tickets_productos_correos.');
        }
    }

    private function constraintClause(PDO $pdo): string
    {
        $statement = $pdo->prepare(
            'SELECT CHECK_CLAUSE
             FROM information_schema.CHECK_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND CONSTRAINT_NAME = :constraint_name
             LIMIT 1'
        );
        $statement->execute(['constraint_name' => self::CONSTRAINT]);
        $clause = $statement->fetchColumn();

        if (!is_string($clause) || $clause === '') {
            throw new RuntimeException('The expected outbox sensitive-content CHECK was not found.');
        }

        return strtolower(preg_replace('/\s+/', ' ', $clause) ?? $clause);
    }

    private function assertOriginalClause(string $clause): void
    {
        if (!$this->isOriginalClause($clause)) {
            throw new RuntimeException('The existing outbox sensitive-content CHECK is not the expected original definition.');
        }
    }

    private function assertCorrectedClause(string $clause): void
    {
        if (!$this->isCorrectedClause($clause)) {
            throw new RuntimeException('The existing outbox sensitive-content CHECK is not the expected corrected definition.');
        }
    }

    private function isOriginalClause(string $clause): bool
    {
        return $this->hasPreservedTerms($clause)
            && str_contains($clause, '[a-z]:')
            && !str_contains($clause, '[^[:alnum:]_]');
    }

    private function isCorrectedClause(string $clause): bool
    {
        return $this->hasPreservedTerms($clause)
            && str_contains($clause, '(^|[^[:alnum:]_])[a-z]:');
    }

    private function hasPreservedTerms(string $clause): bool
    {
        foreach (['storage/private', 'storage/uploads', 'dsn', 'password', 'secret', 'token'] as $term) {
            if (!str_contains($clause, $term)) {
                return false;
            }
        }

        return true;
    }

    private function assertRowsComply(PDO $pdo, string $pattern): void
    {
        $statement = $pdo->prepare(
            "SELECT COUNT(*)
             FROM tickets_productos_correos
             WHERE REGEXP_LIKE(
                 LOWER(CONCAT_WS(' ', subject, error_mensaje_seguro, html, text)),
                 :pattern
             )"
        );
        $statement->execute(['pattern' => $pattern]);

        if ((int) $statement->fetchColumn() !== 0) {
            throw new RuntimeException('Existing outbox rows do not satisfy the requested sensitive-content CHECK.');
        }
    }

    private function replaceConstraint(PDO $pdo, string $pattern): void
    {
        $quotedPattern = $pdo->quote($pattern);
        if (!is_string($quotedPattern)) {
            throw new RuntimeException('Unable to quote the outbox sensitive-content pattern.');
        }

        $pdo->exec(
            'ALTER TABLE ' . self::TABLE
            . ' DROP CHECK ' . self::CONSTRAINT
            . ', ADD CONSTRAINT ' . self::CONSTRAINT
            . " CHECK (LOWER(CONCAT_WS(' ', subject, error_mensaje_seguro, html, text))"
            . ' NOT REGEXP ' . $quotedPattern . ')'
        );
    }

    private function originalPattern(): string
    {
        return '(storage/private|storage/uploads|dsn|password|secret|token|[a-z]:[\\\\/])';
    }

    private function correctedPattern(): string
    {
        return '(storage/private|storage/uploads|dsn|password|secret|token|(^|[^[:alnum:]_])[a-z]:[\\\\/])';
    }
};
