<?php

declare(strict_types=1);

use App\Infrastructure\Database\DatabaseTest;

return new class implements DatabaseTest {
    private const MIGRATION = 'correo_outbox_url_check_correccion_1_001_allow_safe_absolute_urls';
    private const CONSTRAINT = 'chk_tickets_productos_correos_no_sensitive';

    public function run(PDO $pdo, string $expectedDatabase): array
    {
        if ($expectedDatabase !== 'r_erp_db_core_0_test') {
            throw new RuntimeException('The URL CHECK DB test is restricted to the disposable test database.');
        }
        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
            throw new RuntimeException('The connected database does not match the confirmed URL CHECK database.');
        }

        $before = $this->snapshot($pdo);
        $clause = $this->constraintClause($pdo);
        $definition = $this->definitionCases($clause);
        $matrix = $this->regexMatrix($pdo);
        $prevalidation = $this->violatingIds($pdo);

        $positive = $this->insertAccepted(
            $pdo,
            'https://example.test/tickets/productos/123'
        );

        $negativeValues = [
            'drive_backslash' => 'C:' . chr(92) . 'temp' . chr(92) . 'archivo.txt',
            'drive_slash' => 'C:/temp/archivo.txt',
            'text_drive_backslash' => 'texto C:' . chr(92) . 'temp' . chr(92) . 'archivo.txt',
            'text_drive_slash' => 'texto C:/temp/archivo.txt',
            'quoted_backslash' => '"C:' . chr(92) . 'temp' . chr(92) . 'archivo.txt"',
            'quoted_slash' => '"C:/temp/archivo.txt"',
            'file_url' => 'file:///C:/temp/archivo.txt',
            'user_path' => 'C:' . chr(92) . 'Users' . chr(92) . 'usuario' . chr(92) . 'archivo.pdf',
            'storage_private' => 'storage/private/file.pdf',
            'storage_uploads' => 'storage/uploads/file.pdf',
            'dsn' => 'dsn failure',
            'password' => 'password failure',
            'secret' => 'secret failure',
            'token' => 'token failure',
        ];
        $negative = [];
        foreach ($negativeValues as $label => $value) {
            $negative[$label] = $this->insertRejected($pdo, $value);
        }

        $after = $this->snapshot($pdo);
        $cases = [
            'migration_registered' => $this->migrationRows($pdo) === 1,
            'constraint_definition_corrected' => !in_array(false, $definition, true),
            'regex_matrix_matches_contract' => !in_array(false, $matrix, true),
            'existing_rows_prevalidated' => $prevalidation === [],
            'https_outbox_insert_accepted' => $positive,
            'negative_outbox_inserts_rejected' => !in_array(false, $negative, true),
            'tickets_count_unchanged' => $before['tickets_count'] === $after['tickets_count'],
            'outbox_count_unchanged' => $before['outbox_count'] === $after['outbox_count'],
            'tickets_hash_unchanged' => $before['tickets_hash'] === $after['tickets_hash'],
            'outbox_hash_unchanged' => $before['outbox_hash'] === $after['outbox_hash'],
            'protected_rows_unchanged' => $before['protected'] === $after['protected'],
            'eligible_count_unchanged' => $before['eligible_count'] === $after['eligible_count'],
        ];

        foreach ($cases as $label => $passed) {
            if ($passed !== true) {
                throw new RuntimeException('CORREO-OUTBOX-URL-CHECK-CORRECCION-1 assertion failed: ' . $label);
            }
        }

        return [
            'database' => $expectedDatabase,
            'constraint' => self::CONSTRAINT,
            'definition' => $definition,
            'regex_matrix' => $matrix,
            'prevalidation_violating_ids' => $prevalidation,
            'https_insert' => 'ACCEPTED_AND_ROLLED_BACK',
            'negative_inserts' => $negative,
            'cases' => $cases,
            'before' => $before,
            'after' => $after,
            'network_connections' => 0,
            'real_emails_sent' => 0,
            'secret_resolutions' => 0,
            'cleanup' => 'transaction_rolled_back_per_case',
        ];
    }

    /** @return array<string, bool> */
    private function definitionCases(string $clause): array
    {
        $lower = strtolower($clause);

        return [
            'contextual_windows_path_pattern' => str_contains(
                $lower,
                '(^|[^[:alnum:]_])[a-z]:'
            ),
            'storage_private_preserved' => str_contains($lower, 'storage/private'),
            'storage_uploads_preserved' => str_contains($lower, 'storage/uploads'),
            'dsn_preserved' => str_contains($lower, 'dsn'),
            'password_preserved' => str_contains($lower, 'password'),
            'secret_preserved' => str_contains($lower, 'secret'),
            'token_preserved' => str_contains($lower, 'token'),
        ];
    }

    /** @return array<string, bool> */
    private function regexMatrix(PDO $pdo): array
    {
        $slash = chr(92);
        $allowed = [
            'https_example' => 'https://example.test/tickets/productos/123',
            'https_erp' => 'https://erp.example.com/tickets/productos/34',
            'http_local' => 'http://127.0.0.1:8080/tickets/productos/123',
            'text_https' => 'texto con: https://example.test/a/b',
        ];
        $rejected = [
            'drive_backslash' => 'C:' . $slash . 'temp' . $slash . 'archivo.txt',
            'drive_slash' => 'C:/temp/archivo.txt',
            'text_drive_backslash' => 'texto C:' . $slash . 'temp' . $slash . 'archivo.txt',
            'text_drive_slash' => 'texto C:/temp/archivo.txt',
            'quoted_backslash' => '"C:' . $slash . 'temp' . $slash . 'archivo.txt"',
            'quoted_slash' => '"C:/temp/archivo.txt"',
            'file_url' => 'file:///C:/temp/archivo.txt',
            'user_path' => 'C:' . $slash . 'Users' . $slash . 'usuario' . $slash . 'archivo.pdf',
            'storage_private' => 'storage/private/file.pdf',
            'storage_uploads' => 'storage/uploads/file.pdf',
            'dsn' => 'dsn failure',
            'password' => 'password failure',
            'secret' => 'secret failure',
            'token' => 'token failure',
        ];
        $statement = $pdo->prepare('SELECT REGEXP_LIKE(LOWER(:value), :pattern)');
        $pattern = $this->correctedPattern();
        $result = [];

        foreach ($allowed as $label => $value) {
            $statement->execute(['value' => $value, 'pattern' => $pattern]);
            $result[$label . '_allowed'] = (int) $statement->fetchColumn() === 0;
        }
        foreach ($rejected as $label => $value) {
            $statement->execute(['value' => $value, 'pattern' => $pattern]);
            $result[$label . '_detected'] = (int) $statement->fetchColumn() === 1;
        }

        return $result;
    }

    private function insertAccepted(PDO $pdo, string $content): bool
    {
        try {
            $pdo->beginTransaction();
            $this->insertOutbox($pdo, $content);
            return true;
        } catch (PDOException) {
            return false;
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    private function insertRejected(PDO $pdo, string $content): bool
    {
        try {
            $pdo->beginTransaction();
            $this->insertOutbox($pdo, $content);
        } catch (PDOException) {
            return true;
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        return false;
    }

    private function insertOutbox(PDO $pdo, string $content): void
    {
        $statement = $pdo->prepare(
            "INSERT INTO tickets_productos_correos (
                ticket_id, partida_id, evento, plantilla, destinatario_email,
                cc_json, subject, html, text, status, intentos, max_intentos,
                creado_por_usuario_id, dedupe_key, created_at
             ) VALUES (
                34, NULL, 'TICKET_CREADO', 'ticket_created', 'qa@example.test',
                NULL, 'QA URL CHECK', :html, :text, 'PENDIENTE', 0, 3,
                NULL, :dedupe_key, CURRENT_TIMESTAMP
             )"
        );
        $statement->execute([
            'html' => '<a href="' . $content . '">Ver ticket</a>',
            'text' => 'Ver ticket: ' . $content,
            'dedupe_key' => 'qa:url-check:' . bin2hex(random_bytes(12)),
        ]);
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
            throw new RuntimeException('The corrected outbox URL CHECK was not found.');
        }

        return preg_replace('/\s+/', ' ', $clause) ?? $clause;
    }

    /** @return list<int> */
    private function violatingIds(PDO $pdo): array
    {
        $statement = $pdo->prepare(
            "SELECT id
             FROM tickets_productos_correos
             WHERE REGEXP_LIKE(
                 LOWER(CONCAT_WS(' ', subject, error_mensaje_seguro, html, text)),
                 :pattern
             )
             ORDER BY id"
        );
        $statement->execute(['pattern' => $this->correctedPattern()]);

        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function migrationRows(PDO $pdo): int
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM schema_migrations WHERE migration = :migration'
        );
        $statement->execute(['migration' => self::MIGRATION]);

        return (int) $statement->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function snapshot(PDO $pdo): array
    {
        $tickets = $pdo->query(
            'SELECT id, folio, estado, total_partidas FROM tickets_productos ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $outbox = $pdo->query(
            'SELECT id, ticket_id, partida_id, evento, plantilla, status, intentos,
                    max_intentos, enviado_at, cancelado_at, dedupe_key
             FROM tickets_productos_correos ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $protected = $pdo->query(
            'SELECT id, status, intentos, max_intentos, enviado_at, cancelado_at
             FROM tickets_productos_correos
             WHERE id IN (1, 36, 698, 699, 700)
             ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);

        return [
            'tickets_count' => count($tickets),
            'outbox_count' => count($outbox),
            'tickets_hash' => hash('sha256', json_encode($tickets, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'outbox_hash' => hash('sha256', json_encode($outbox, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'protected' => $protected,
            'eligible_count' => (int) $pdo->query(
                "SELECT COUNT(*)
                 FROM tickets_productos_correos
                 WHERE status IN ('PENDIENTE', 'ERROR')
                   AND intentos < max_intentos"
            )->fetchColumn(),
        ];
    }

    private function correctedPattern(): string
    {
        return '(storage/private|storage/uploads|dsn|password|secret|token|(^|[^[:alnum:]_])[a-z]:[\\\\/])';
    }
};
