<?php

declare(strict_types=1);

/**
 * Dedicated local QA schema route. --plan and --contract-test never open PDO.
 * --inspect uses SELECT only. --execute is a separate, doubly confirmed action.
 * No global transaction/rollback: MySQL DDL implicitly commits. A failed step
 * may leave unregistered DDL; a subsequent invocation must fail closed on drift.
 * Never run the generic runner/status(): even status() can create its repository.
 */
final class QaTicketsAtomicityPendingMigrations
{
    public const DATABASE = 'r_erp_tickets_qa_2f';
    public const ACCOUNT = 'r_erp_tickets_qa_setup@localhost';
    public const HOSTNAME = 'GER005OTU';
    public const PORT = 3306;
    public const IDS = [
        'config_operacion_codigos_min_2_001_update_company_warehouse_code_checks',
        'config_operacion_codigos_upper_1_001_uppercase_company_warehouse_codes',
        'tp_partidas_estados_correo_outbox_db_1_001_create_ticket_product_email_outbox',
        'correo_outbox_url_check_correccion_1_001_allow_safe_absolute_urls',
    ];
    public const BASELINE = [
        'db_core_0_001_create_core_identity_tables',
        'db_scope_1_001_create_scope_tables',
        'db_catalogos_1_001_create_base_catalog_tables',
        'db_sat_1_001_create_sat_catalogs',
        'config_operacion_1_001_extend_companies_warehouses',
        'folios_1_001_create_document_numbering_tables',
        'folios_1_002_reserve_global_documentary_prefixes',
        'tp_partidas_estados_db_1_001_create_ticket_product_tables',
        'tp_partidas_estados_aprobacion_captura_1_001_add_authorized_line_fields',
        'tp_partidas_estados_correo_config_1_001_create_mail_configuration',
    ];
    public const OUTBOX = 'tickets_productos_correos';
    public const CONFIRMATIONS = [
        'TICKETS_QA_SCHEMA_CONFIRM' => 'APPLY_EXACT_PENDING_MIGRATIONS',
        'TICKETS_QA_DATABASE_CONFIRM' => self::DATABASE,
    ];

    public static function dependencies(): array
    {
        return [
            self::IDS[0] => ['db_scope_1_001_create_scope_tables'],
            self::IDS[1] => [self::IDS[0]],
            self::IDS[2] => ['tp_partidas_estados_db_1_001_create_ticket_product_tables', 'tp_partidas_estados_correo_config_1_001_create_mail_configuration'],
            self::IDS[3] => [self::IDS[2]],
        ];
    }

    private static function demand(bool $condition, string $code): void
    {
        if (!$condition) {
            throw new RuntimeException($code);
        }
    }

    public static function environment(array $env, bool $execute): void
    {
        self::demand(in_array($env['APP_ENV'] ?? null, ['qa', 'test'], true), 'APP_ENV_GUARD_FAILED');
        self::demand(($env['APP_DB_NAME'] ?? null) === self::DATABASE, 'DATABASE_ENV_GUARD_FAILED');
        $expectedUser = $execute ? 'r_erp_tickets_qa_setup' : 'r_erp_tickets_qa_runner';
        self::demand(($env['APP_DB_USER'] ?? null) === $expectedUser, 'ACCOUNT_ENV_GUARD_FAILED');
        self::demand(in_array(strtolower((string) ($env['APP_DB_HOST'] ?? '')), ['localhost', '127.0.0.1', 'ger005otu'], true), 'HOST_ENV_GUARD_FAILED');
        self::demand(($env['APP_DB_PORT'] ?? null) === '3306', 'PORT_ENV_GUARD_FAILED');
        self::demand(is_string($env['APP_DB_PASSWORD'] ?? null) && $env['APP_DB_PASSWORD'] !== '', 'CREDENTIAL_MISSING');
        if ($execute) {
            foreach (self::CONFIRMATIONS as $name => $expected) {
                self::demand(($env[$name] ?? null) === $expected, 'DOUBLE_CONFIRMATION_FAILED');
            }
        }
    }

    public static function identity(array $row): void
    {
        self::demand(($row['db'] ?? null) === self::DATABASE, 'DATABASE_IDENTITY_FAILED');
        self::demand(($row['login'] ?? null) === self::ACCOUNT && ($row['effective'] ?? null) === self::ACCOUNT, 'ACCOUNT_IDENTITY_FAILED');
        self::demand(($row['hostname'] ?? null) === self::HOSTNAME && (int) ($row['port'] ?? 0) === self::PORT, 'SERVER_IDENTITY_FAILED');
        self::demand(str_starts_with((string) ($row['version'] ?? ''), '8.0.'), 'MYSQL_VERSION_UNREVIEWED');
        self::demand(!str_contains((string) ($row['sql_mode'] ?? ''), 'NO_BACKSLASH_ESCAPES'), 'SQL_MODE_UNREVIEWED');
        self::demand((int) ($row['fk_checks'] ?? 0) === 1, 'FOREIGN_KEY_CHECKS_DISABLED');
    }

    /** Identity queries are deliberately the first SQL after PDO construction. */
    private static function readIdentity(PDO $pdo): void
    {
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $login = (string) $pdo->query('SELECT USER()')->fetchColumn();
        $effective = (string) $pdo->query('SELECT CURRENT_USER()')->fetchColumn();
        $hostname = (string) $pdo->query('SELECT @@hostname')->fetchColumn();
        $port = (int) $pdo->query('SELECT @@port')->fetchColumn();
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $sqlMode = (string) $pdo->query('SELECT @@sql_mode')->fetchColumn();
        $foreignKeyChecks = (int) $pdo->query('SELECT @@foreign_key_checks')->fetchColumn();

        self::identity([
            'db' => $database,
            'login' => $login,
            'effective' => $effective,
            'hostname' => $hostname,
            'port' => $port,
            'version' => $version,
            'sql_mode' => $sqlMode,
            'fk_checks' => $foreignKeyChecks,
        ]);
    }

    /**
     * SHOW GRANTS is read-only, but its scope is still fail-closed. The
     * setup account may have DDL/DML on the QA schema; it must not have
     * privileges on any other schema or global write/admin privileges.
     */
    public static function assertGrantScope(array $grants): void
    {
        self::demand($grants !== [], 'GRANTS_EMPTY');
        $qaGrantFound = false;
        foreach ($grants as $grant) {
            $line = strtoupper(trim((string) $grant));
            self::demand($line !== '', 'GRANT_LINE_EMPTY');
            if (str_contains($line, ' ON *.* ')) {
                self::demand(str_contains($line, 'USAGE'), 'GLOBAL_GRANT_FORBIDDEN');
                continue;
            }
            if (preg_match('/ ON `?R_ERP_TICKETS_QA_2F`?\.\* /', $line) === 1) {
                $qaGrantFound = true;
                self::demand(!str_contains($line, 'GRANT OPTION'), 'QA_GRANT_OPTION_FORBIDDEN');
                continue;
            }
            self::demand(false, 'NON_QA_GRANT_FORBIDDEN');
        }
        self::demand($qaGrantFound, 'QA_GRANT_MISSING');
    }

    private static function readGrantScope(PDO $pdo): void
    {
        $rows = $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_NUM);
        self::assertGrantScope(array_map(static fn (array $row): string => (string) ($row[0] ?? ''), $rows));
    }

    /** Strict SQL literal decoder, not a global backslash normalizer. */
    private static function literal(string $value): string
    {
        $out = '';
        for ($i = 0, $n = strlen($value); $i < $n; $i++) {
            if ($value[$i] !== '\\') {
                $out .= $value[$i];
                continue;
            }
            self::demand(++$i < $n, 'CHECK_LITERAL_AMBIGUOUS');
            $out .= match ($value[$i]) {
                '\\' => '\\', "'" => "'", '.' => '.',
                default => throw new RuntimeException('CHECK_LITERAL_ESCAPE_UNREVIEWED'),
            };
        }
        return $out;
    }

    /**
     * Tokenize reviewed CHECK shapes; preserve literal case and regex bytes.
     * Metadata may serialize delimiters as backslash-quote: that representation
     * has one additional SQL escaping layer, decoded only inside that literal.
     */
    public static function tokens(string $sql): array
    {
        $tokens = [];
        for ($i = 0, $n = strlen($sql); $i < $n;) {
            $c = $sql[$i];
            if (ctype_space($c)) { $i++; continue; }
            if ($c === '`') {
                $end = strpos($sql, '`', $i + 1);
                self::demand($end !== false, 'CHECK_IDENTIFIER_AMBIGUOUS');
                $tokens[] = strtolower(substr($sql, $i + 1, $end - $i - 1));
                $i = $end + 1;
                continue;
            }
            $escaped = substr($sql, $i, 2) === "\\'";
            if ($c === "'" || $escaped) {
                $i += $escaped ? 2 : 1;
                $body = '';
                $closed = false;
                while ($i < $n) {
                    if ($escaped && substr($sql, $i, 2) === "\\'") { $i += 2; $closed = true; break; }
                    if (!$escaped && $sql[$i] === "'") { $i++; $closed = true; break; }
                    if ($sql[$i] === '\\') {
                        self::demand($i + 1 < $n, 'CHECK_LITERAL_AMBIGUOUS');
                        $body .= substr($sql, $i, 2); $i += 2;
                    } else { $body .= $sql[$i++]; }
                }
                self::demand($closed, 'CHECK_LITERAL_AMBIGUOUS');
                if ($escaped) { $body = self::literal($body); }
                $tokens[] = 'literal:' . bin2hex(self::literal($body));
                continue;
            }
            if (preg_match('/\G([A-Za-z_][A-Za-z_0-9]*|[0-9]+|<=|>=|<>|[(),=<>])/A', $sql, $match, 0, $i)) {
                $token = strtolower($match[1]);
                self::demand($token[0] !== '_' || in_array($token, ['_ascii', '_utf8mb4', '_utf8mb3', '_utf8'], true), 'CHECK_CHARSET_UNREVIEWED');
                if ($token[0] !== '_') { $tokens[] = $token; }
                $i += strlen($match[1]);
                continue;
            }
            throw new RuntimeException('CHECK_TOKEN_UNREVIEWED');
        }
        return $tokens;
    }

    /** Small fail-closed parser for the CHECK grammar used by these migrations.
     * Parentheses are parsed, never removed globally. Only associative AND/OR
     * grouping and the documented REGEXP -> REGEXP_LIKE spelling normalize.
     */
    public static function expression(string $sql): array
    {
        $tokens = self::tokens($sql);
        $i = 0;
        // Closures must observe the current cursor, not a captured cursor copy.
        $peek = static function () use (&$i, $tokens): ?string { return $tokens[$i] ?? null; };
        $take = static function (string $expected) use (&$i, $tokens): void {
            self::demand(($tokens[$i] ?? null) === $expected, 'CHECK_GRAMMAR_UNREVIEWED'); $i++;
        };
        $primary = $comparison = $conjunction = $disjunction = null;
        $primary = static function () use (&$primary, &$disjunction, &$i, $tokens, $peek, $take): array {
            $token = $peek();
            self::demand($token !== null, 'CHECK_GRAMMAR_UNREVIEWED');
            if ($token === '(') { $i++; $node = $disjunction(); $take(')'); return $node; }
            if ($token === 'not') { $i++; return ['not', $primary()]; }
            self::demand(!in_array($token, [')',',','=','and','or','between','in','regexp'], true), 'CHECK_GRAMMAR_UNREVIEWED');
            $i++;
            if ($peek() !== '(') { return ['value', $token]; }
            $i++; $args = [];
            if ($peek() !== ')') {
                do { $args[] = $disjunction(); if ($peek() !== ',') { break; } $i++; } while (true);
            }
            $take(')');
            return ['call', $token, ...$args];
        };
        $comparison = static function () use ($primary, &$i, $peek, $take): array {
            $left = $primary(); $operator = $peek();
            if ($operator === 'between') {
                $i++; $low = $primary(); $take('and'); return ['between', $left, $low, $primary()];
            }
            $negated = $operator === 'not';
            if ($negated) { $i++; $operator = $peek(); self::demand(in_array($operator, ['regexp','in'], true), 'CHECK_GRAMMAR_UNREVIEWED'); }
            if ($operator === 'is') {
                $i++; $isNot = $peek() === 'not'; if ($isNot) { $i++; }
                $take('null'); $node = ['is_null',$left]; return $isNot ? ['not',$node] : $node;
            }
            if ($operator === 'in') {
                $i++; $take('('); $values = [];
                do { $values[] = $primary(); if ($peek() !== ',') { break; } $i++; } while (true);
                $take(')'); $node = ['in', $left, ...$values]; return $negated ? ['not',$node] : $node;
            }
            if ($operator === 'regexp') {
                $i++; $node = ['call','regexp_like',$left,$primary()]; return $negated ? ['not',$node] : $node;
            }
            if (in_array($operator, ['=','<>','<','>','<=','>='], true)) { $i++; return [$operator,$left,$primary()]; }
            return $left;
        };
        $combine = static function (string $op, array $left, array $right): array {
            return [$op, ...($left[0] === $op ? array_slice($left, 1) : [$left]), ...($right[0] === $op ? array_slice($right, 1) : [$right])];
        };
        $conjunction = static function () use ($comparison, $peek, &$i, $combine): array {
            $node = $comparison(); while ($peek() === 'and') { $i++; $node = $combine('and',$node,$comparison()); } return $node;
        };
        $disjunction = static function () use ($conjunction, $peek, &$i, $combine): array {
            $node = $conjunction(); while ($peek() === 'or') { $i++; $node = $combine('or',$node,$conjunction()); } return $node;
        };
        $result = $disjunction();
        self::demand($i === count($tokens), 'CHECK_GRAMMAR_UNREVIEWED');
        return $result;
    }

    public static function codeClause(int $minimum, bool $upper): string
    {
        $case = $upper ? 'UPPER' : 'LOWER';
        $pattern = $upper ? '^[A-Z0-9]+([._-][A-Z0-9]+)*$' : '^[a-z0-9]+([._-][a-z0-9]+)*$';
        return "CHAR_LENGTH(codigo) BETWEEN $minimum AND 64 AND codigo = $case(codigo) AND REGEXP_LIKE(codigo, '$pattern')";
    }

    public static function assertCodeCheck(array $check, int $minimum, bool $upper): void
    {
        self::demand(($check['ENFORCED'] ?? null) === 'YES', 'CODE_CHECK_NOT_ENFORCED');
        self::demand(self::expression((string) ($check['CHECK_CLAUSE'] ?? '')) === self::expression(self::codeClause($minimum, $upper)), 'CODE_CHECK_DRIFT');
    }

    public static function sensitiveClause(bool $corrected): string
    {
        $boundary = $corrected ? '(^|[^[:alnum:]_])' : '';
        // Four SQL-source slashes -> two regex slashes under the guarded mode.
        $pattern = '(storage/private|storage/uploads|dsn|password|secret|token|' . $boundary . '[a-z]:[' . str_repeat('\\', 4) . '/])';
        return "NOT(REGEXP_LIKE(LOWER(CONCAT_WS(' ', subject, error_mensaje_seguro, html, text)), '$pattern'))";
    }

    /** Read fixed allowlisted source, extract SQL CHECK bodies without up(). */
    public static function outboxCheckDefinitions(): array
    {
        $source = file_get_contents(__DIR__ . '/migrations/' . self::IDS[2] . '.php');
        self::demand(is_string($source), 'OUTBOX_ARTIFACT_UNREADABLE');
        preg_match_all('/CONSTRAINT (chk_tickets_productos_correos_\w+) CHECK\s*\(/', $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        $checks = [];
        foreach ($matches as $match) {
            $start = $match[0][1] + strlen($match[0][0]);
            $depth = 1; $quoted = false;
            for ($i = $start, $n = strlen($source); $i < $n; $i++) {
                $c = $source[$i];
                if ($quoted && $c === '\\') { $i++; continue; }
                if ($c === "'") { $quoted = !$quoted; continue; }
                if ($quoted) { continue; }
                if ($c === '(') { $depth++; }
                if ($c === ')') { $depth--; if ($depth === 0) { break; } }
            }
            self::demand($depth === 0 && !$quoted, 'OUTBOX_CHECK_SOURCE_AMBIGUOUS');
            $checks[$match[1][0]] = substr($source, $start, $i - $start);
        }
        self::demand(count($checks) === 9, 'OUTBOX_CHECK_ARTIFACT_DRIFT');
        return $checks;
    }

    private static function assertOutbox(array $s, bool $corrected): void
    {
        $table = self::OUTBOX;
        $expected = [
            'id'=>'bigint unsigned', 'ticket_id'=>'bigint unsigned', 'partida_id'=>'bigint unsigned',
            'evento'=>'varchar(40)', 'plantilla'=>'varchar(80)', 'destinatario_email'=>'varchar(190)',
            'cc_json'=>'json', 'subject'=>'varchar(190)', 'html'=>'mediumtext', 'text'=>'mediumtext',
            'status'=>'varchar(20)', 'intentos'=>'smallint unsigned', 'max_intentos'=>'smallint unsigned',
            'error_mensaje_seguro'=>'varchar(500)', 'ultimo_intento_at'=>'datetime', 'enviado_at'=>'datetime',
            'cancelado_at'=>'datetime', 'creado_por_usuario_id'=>'bigint unsigned', 'dedupe_key'=>'varchar(190)',
            'created_at'=>'datetime', 'updated_at'=>'datetime',
        ];
        $columns = $s['columns'][$table] ?? [];
        self::demand(array_keys($columns) === array_keys($expected), 'OUTBOX_COLUMN_SET_DRIFT');
        foreach ($expected as $column => $type) {
            self::demand(($columns[$column]['COLUMN_TYPE'] ?? null) === $type, 'OUTBOX_COLUMN_TYPE_DRIFT');
            $nullable = in_array($column, ['partida_id','cc_json','html','text','error_mensaje_seguro','ultimo_intento_at','enviado_at','cancelado_at','creado_por_usuario_id','updated_at'], true) ? 'YES' : 'NO';
            self::demand(($columns[$column]['IS_NULLABLE'] ?? null) === $nullable, 'OUTBOX_NULLABILITY_DRIFT');
        }
        self::demand(($columns['status']['COLUMN_DEFAULT'] ?? null) === 'PENDIENTE' && ($columns['status']['IS_NULLABLE'] ?? null) === 'NO', 'OUTBOX_STATUS_DEFAULT_DRIFT');
        self::demand(($columns['dedupe_key']['IS_NULLABLE'] ?? null) === 'NO' && ($columns['dedupe_key']['COLLATION_NAME'] ?? null) === 'ascii_bin', 'OUTBOX_DEDUPE_COLUMN_DRIFT');
        self::assertUnique($s, $table, 'PRIMARY', ['id']);
        self::assertUnique($s, $table, 'uq_tickets_productos_correos_dedupe', ['dedupe_key']);
        foreach (['ticket'=>['ticket_id','tickets_productos','CASCADE'], 'partida'=>['partida_id','tickets_productos_partidas','SET NULL'], 'creado_por'=>['creado_por_usuario_id','usuarios','SET NULL']] as $suffix => [$column,$target,$delete]) {
            $fk = $s['foreign_keys'][$table]['fk_tickets_productos_correos_' . $suffix] ?? [];
            self::demand(count($fk) === 1 && $fk[0]['COLUMN_NAME'] === $column && $fk[0]['REFERENCED_TABLE_NAME'] === $target && $fk[0]['REFERENCED_COLUMN_NAME'] === 'id' && $fk[0]['DELETE_RULE'] === $delete && $fk[0]['UPDATE_RULE'] === 'RESTRICT', 'OUTBOX_FK_DRIFT');
        }
        foreach (['evento','plantilla','status','email','subject','intentos','fechas','dedupe','no_sensitive'] as $suffix) {
            self::demand(($s['checks'][$table]['chk_tickets_productos_correos_' . $suffix]['ENFORCED'] ?? null) === 'YES', 'OUTBOX_CHECK_MISSING');
        }
        $status = "status IN ('PENDIENTE','ENVIANDO','ENVIADO','ERROR','CANCELADO')";
        self::demand(self::expression($s['checks'][$table]['chk_tickets_productos_correos_status']['CHECK_CLAUSE']) === self::expression($status), 'OUTBOX_STATUS_CHECK_DRIFT');
        $actual = $s['checks'][$table]['chk_tickets_productos_correos_no_sensitive']['CHECK_CLAUSE'];
        // Parentheses here are significant: preserve grouping and literal bytes.
        self::demand(self::expression($actual) === self::expression(self::sensitiveClause($corrected)), 'OUTBOX_URL_CHECK_DRIFT');
        $definitions = self::outboxCheckDefinitions();
        self::demand(count($s['checks'][$table]) === count($definitions), 'OUTBOX_CHECK_SET_DRIFT');
        foreach ($definitions as $name => $definition) {
            if ($name === 'chk_tickets_productos_correos_no_sensitive') { continue; }
            self::demand(self::expression($s['checks'][$table][$name]['CHECK_CLAUSE'] ?? '') === self::expression($definition), 'OUTBOX_CHECK_DEFINITION_DRIFT');
        }
    }

    private static function assertUnique(array $s, string $table, string $name, array $columns): void
    {
        $rows = $s['indexes'][$table][$name] ?? [];
        self::demand(count($rows) === count($columns), 'UNIQUE_INDEX_DRIFT');
        foreach ($columns as $i => $column) {
            $r = $rows[$i];
            self::demand($r['COLUMN_NAME'] === $column && (int) $r['NON_UNIQUE'] === 0 && (int) $r['SEQ_IN_INDEX'] === $i + 1 && $r['SUB_PART'] === null && $r['IS_VISIBLE'] === 'YES', 'UNIQUE_INDEX_DRIFT');
        }
    }

    /** Pure preflight: callers supply read-only metadata, tests supply snapshots. */
    public static function preflight(array $s): array
    {
        $applied = $s['applied'] ?? [];
        self::demand(count($applied) === count(array_unique($applied)), 'MIGRATION_REGISTRY_DRIFT');
        $unexpected = array_values(array_diff($applied, self::BASELINE, self::IDS));
        self::demand($unexpected === [], 'UNEXPECTED_REGISTERED_MIGRATION');
        self::demand(array_diff(self::BASELINE, $applied) === [], 'EXTERNAL_DEPENDENCY_MISSING');
        $pending = array_values(array_diff(self::IDS, $applied));
        $done = array_values(array_intersect(self::IDS, $applied));
        self::demand($done === array_slice(self::IDS, 0, count($done)), 'MIGRATION_ORDER_DRIFT');
        $available = $applied;
        foreach (self::dependencies() as $id => $deps) {
            self::demand(array_diff($deps, $available) === [], 'DEPENDENCY_MISSING');
            $available[] = $id;
        }
        foreach (['schema_migrations','empresas','almacenes','usuarios','tickets_productos','tickets_productos_partidas','mail_accounts','tickets_productos_correo_reglas'] as $table) {
            self::demand(($s['tables'][$table]['ENGINE'] ?? null) === 'InnoDB', 'PHYSICAL_DEPENDENCY_MISSING_OR_ENGINE_DRIFT');
        }
        foreach (['usuarios','tickets_productos','tickets_productos_partidas','mail_accounts'] as $table) {
            self::demand(($s['columns'][$table]['id']['COLUMN_TYPE'] ?? null) === 'bigint unsigned', 'PHYSICAL_KEY_TYPE_DRIFT');
            self::assertUnique($s, $table, 'PRIMARY', ['id']);
        }
        self::assertUnique($s, 'schema_migrations', 'PRIMARY', ['migration']);
        foreach (['migration','applied_at'] as $column) {
            self::demand(isset($s['columns']['schema_migrations'][$column]), 'REGISTRY_COLUMN_MISSING');
        }
        foreach (['empresas','almacenes'] as $table) {
            $code = $s['columns'][$table]['codigo'] ?? [];
            self::demand(($code['COLUMN_TYPE'] ?? null) === 'varchar(64)' && ($code['COLLATION_NAME'] ?? null) === 'ascii_bin' && ($code['IS_NULLABLE'] ?? null) === 'NO', 'CODE_COLUMN_DRIFT');
            self::assertCodeCheck($s['checks'][$table]['chk_' . $table . '_codigo'] ?? [], count($done) > 0 ? 2 : 3, count($done) > 1);
        }
        $outboxApplied = in_array(self::IDS[2], $applied, true);
        self::demand(isset($s['tables'][self::OUTBOX]) === $outboxApplied, 'OUTBOX_REGISTRATION_DRIFT');
        if ($outboxApplied) {
            self::demand($s['tables'][self::OUTBOX]['ENGINE'] === 'InnoDB', 'OUTBOX_ENGINE_DRIFT');
            self::assertOutbox($s, in_array(self::IDS[3], $applied, true));
        }
        // This dedicated route deliberately refuses nonempty code tables while
        // UPPER is pending: no unreviewed case collisions or snapshot changes.
        if (!in_array(self::IDS[1], $applied, true)) {
            self::demand(($s['code_rows']['empresas'] ?? -1) === 0 && ($s['code_rows']['almacenes'] ?? -1) === 0, 'CODE_DATA_REQUIRES_SEPARATE_REVIEW');
        }
        return ['PENDING_EXPECTED'=>$pending, 'ALREADY_APPLIED_EXPECTED'=>$done, 'UNEXPECTED_PENDING'=>[], 'schema_drift'=>false];
    }

    private static function snapshot(PDO $pdo): array
    {
        $s = ['applied'=>[], 'tables'=>[], 'columns'=>[], 'checks'=>[], 'indexes'=>[], 'foreign_keys'=>[], 'code_rows'=>[]];
        $s['applied'] = $pdo->query('SELECT migration FROM schema_migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($pdo->query('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_ASSOC) as $r) { $s['tables'][$r['TABLE_NAME']] = $r; }
        foreach ($pdo->query('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, COLUMN_DEFAULT, IS_NULLABLE, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION')->fetchAll(PDO::FETCH_ASSOC) as $r) { $s['columns'][$r['TABLE_NAME']][$r['COLUMN_NAME']] = $r; }
        foreach ($pdo->query('SELECT t.TABLE_NAME, c.CONSTRAINT_NAME, c.CHECK_CLAUSE, t.ENFORCED FROM information_schema.CHECK_CONSTRAINTS c JOIN information_schema.TABLE_CONSTRAINTS t ON t.CONSTRAINT_SCHEMA = c.CONSTRAINT_SCHEMA AND t.CONSTRAINT_NAME = c.CONSTRAINT_NAME WHERE c.CONSTRAINT_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_ASSOC) as $r) { $s['checks'][$r['TABLE_NAME']][$r['CONSTRAINT_NAME']] = $r; }
        foreach ($pdo->query('SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SEQ_IN_INDEX, SUB_PART, IS_VISIBLE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX')->fetchAll(PDO::FETCH_ASSOC) as $r) { $s['indexes'][$r['TABLE_NAME']][$r['INDEX_NAME']][] = $r; }
        foreach ($pdo->query('SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE, r.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME WHERE k.TABLE_SCHEMA = DATABASE() ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION')->fetchAll(PDO::FETCH_ASSOC) as $r) { $s['foreign_keys'][$r['TABLE_NAME']][$r['CONSTRAINT_NAME']][] = $r; }
        foreach (['empresas','almacenes'] as $table) { $s['code_rows'][$table] = (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn(); }
        return $s;
    }

    public static function plan(): array
    {
        return ['mode'=>'DB_FREE_PLAN', 'database'=>self::DATABASE, 'account'=>self::ACCOUNT,
            'hostname'=>self::HOSTNAME, 'port'=>self::PORT, 'ordered_ids'=>self::IDS,
            'dependencies'=>self::dependencies(), 'required_baseline'=>self::BASELINE,
            'PENDING_EXPECTED'=>'UNKNOWN_WITHOUT_INSPECT', 'ALREADY_APPLIED_EXPECTED'=>'UNKNOWN_WITHOUT_INSPECT',
            'UNEXPECTED_PENDING'=>'UNKNOWN_WITHOUT_INSPECT', 'schema_drift'=>'NOT_INSPECTED',
            'confirmations'=>self::CONFIRMATIONS, 'pdo_opened'=>false, 'sql_executed'=>false,
            'atomicity'=>'MYSQL_DDL_IMPLICIT_COMMITS_FAIL_STOP_NO_GLOBAL_ROLLBACK',
            'atomicity_by_id'=>[
                self::IDS[0]=>'ALTER_CHECKS_IMPLICIT_COMMIT',
                self::IDS[1]=>'ALTER_CHECKS_AND_UPDATE_CODES_NO_GLOBAL_TRANSACTION',
                self::IDS[2]=>'CREATE_TABLE_IMPLICIT_COMMIT',
                self::IDS[3]=>'ALTER_CHECK_IMPLICIT_COMMIT',
            ],
            'postchecks'=>['four_registered_ids','uppercase_codes_2_to_64','outbox_columns_and_nullability',
                'dedupe_full_visible_unique','three_outbox_foreign_keys','status_default_PENDIENTE',
                'enforced_outbox_checks','corrected_url_check_exact_expression'],
        ];
    }

    /** Fixed filenames derive ONLY from the constant allowlist, never CLI input. */
    public static function loadMigrations(): array
    {
        require_once __DIR__ . '/../app/Infrastructure/Database/Migration.php';
        $migrations = [];
        foreach (self::IDS as $id) {
            $migration = require __DIR__ . '/migrations/' . $id . '.php';
            self::demand($migration instanceof App\Infrastructure\Database\Migration && $migration->id() === $id, 'MIGRATION_ARTIFACT_MISMATCH');
            $migrations[$id] = $migration;
        }
        return $migrations;
    }

    /** Injected operations let DB-free tests prove ordering/skip/fail-stop. */
    public static function applyPending(array $pending, callable $apply, callable $register, callable $verify): array
    {
        self::demand($pending === array_values(array_intersect(self::IDS, $pending)), 'PENDING_ALLOWLIST_OR_ORDER_FAILED');
        $completed = [];
        foreach ($pending as $id) {
            $apply($id);
            $verify($id, false); // Physical postcondition BEFORE registration.
            $register($id);
            $verify($id, true);  // Registered and physical state must agree.
            $completed[] = $id;
        }
        return $completed;
    }

    public static function run(array $argv, ?callable $connector = null): array
    {
        self::demand(count($argv) === 2 && in_array($argv[1], ['--plan','--inspect','--contract-test','--execute'], true), 'CLI_MODE_REQUIRED');
        $mode = $argv[1];
        if ($mode === '--plan') { return self::plan(); }
        if ($mode === '--contract-test') {
            require_once __DIR__ . '/tests/qa_tickets_atomicity_pending_migrations_db_free_test.php';
            return qaTicketsAtomicityPendingContractTest();
        }
        $env = [];
        foreach (array_merge(['APP_ENV','APP_DB_NAME','APP_DB_USER','APP_DB_HOST','APP_DB_PORT','APP_DB_PASSWORD'], array_keys(self::CONFIRMATIONS)) as $key) { $env[$key] = getenv($key); }
        self::environment($env, $mode === '--execute'); // Always BEFORE connector.
        $pdo = $connector !== null ? $connector($env) : new PDO(
            'mysql:host=' . $env['APP_DB_HOST'] . ';port=3306;dbname=' . self::DATABASE . ';charset=utf8mb4',
            $env['APP_DB_USER'], $env['APP_DB_PASSWORD'],
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]
        );
        unset($env);
        self::demand($pdo instanceof PDO && !$pdo->inTransaction(), 'PDO_STATE_INVALID');
        self::readIdentity($pdo);
        self::readGrantScope($pdo);
        $preflight = self::preflight(self::snapshot($pdo));
        if ($mode === '--inspect') { return ['mode'=>'READ_ONLY_INSPECT', 'database'=>self::DATABASE, 'account'=>self::ACCOUNT, 'ordered_ids'=>self::IDS, 'dependencies'=>self::dependencies(), 'sql_write_executed'=>false] + $preflight; }
        $migrations = self::loadMigrations(); // Load ALL artifacts before first DDL.
        $currentId = null;
        $ddlMayHaveStarted = false;
        try {
            $completed = self::applyPending($preflight['PENDING_EXPECTED'],
                static function (string $id) use ($pdo, $migrations, &$currentId, &$ddlMayHaveStarted): void {
                    $currentId = $id;
                    self::readIdentity($pdo);
                    $now = self::preflight(self::snapshot($pdo));
                    self::demand(($now['PENDING_EXPECTED'][0] ?? null) === $id, 'CONCURRENT_SCHEMA_CHANGE');
                    $ddlMayHaveStarted = true;
                    $migrations[$id]->up($pdo);
                },
                static function (string $id) use ($pdo): void {
                    // Standard schema_migrations registration; no repository DDL.
                    $statement = $pdo->prepare('INSERT INTO schema_migrations (migration, applied_at) VALUES (:migration, CURRENT_TIMESTAMP)');
                    $statement->execute(['migration'=>$id]);
                },
                static function (string $id, bool $registered) use ($pdo): void {
                    $s = self::snapshot($pdo);
                    if (!$registered) {
                        self::demand(!in_array($id, $s['applied'], true), 'CONCURRENT_REGISTRATION');
                        $s['applied'][] = $id; // Virtual registration for physical check.
                    }
                    self::preflight($s);
                }
            );
            $final = self::preflight(self::snapshot($pdo));
            self::demand($final['PENDING_EXPECTED'] === [], 'FINAL_POSTCHECK_FAILED');
            return ['status'=>'PASS', 'mode'=>'EXECUTED', 'completed'=>$completed, 'postchecks'=>$final, 'global_rollback_available'=>false];
        } catch (Throwable $error) {
            // Never expose exception text: driver errors can contain credentials.
            throw new RuntimeException('EXECUTION_STOPPED_REVIEW_REQUIRED:' . ($currentId ?? 'NONE') . ':DDL_MAY_HAVE_STARTED=' . ($ddlMayHaveStarted ? 'true' : 'false'), 0, $error);
        }
    }
}

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        echo json_encode(QaTicketsAtomicityPendingMigrations::run($argv), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } catch (Throwable $error) {
        $code = $error instanceof PDOException ? 'PDO_OPERATION_FAILED' : $error->getMessage();
        if (!preg_match('/^[A-Z0-9_:=a-z]+$/D', $code)) { $code = 'QA_OPERATION_FAILED'; }
        // Migration IDs are emitted only from the constant allowlist above.
        echo json_encode(['status'=>'BLOCKED', 'code'=>$code, 'global_rollback_available'=>false], JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(1);
    }
}
