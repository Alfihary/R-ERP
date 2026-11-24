<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/qa_tickets_atomicity_pending_migrations.php';

/** Synthetic metadata only. No PDO, SQL execution or application services. */
function qaTicketsAtomicityPendingContractTest(): array
{
    $class = QaTicketsAtomicityPendingMigrations::class;
    $assertions = 0;
    $assert = static function (bool $ok, string $name) use (&$assertions): void {
        $assertions++;
        if (!$ok) { throw new RuntimeException('TEST_FAILED_' . $name); }
    };
    $reject = static function (callable $operation, string $code) use ($assert): void {
        try { $operation(); } catch (RuntimeException $error) {
            $assert($error->getMessage() === $code, 'EXPECTED_' . $code);
            return;
        }
        $assert(false, 'REJECTION_' . $code);
    };
    $ids = [
        'config_operacion_codigos_min_2_001_update_company_warehouse_code_checks',
        'config_operacion_codigos_upper_1_001_uppercase_company_warehouse_codes',
        'tp_partidas_estados_correo_outbox_db_1_001_create_ticket_product_email_outbox',
        'correo_outbox_url_check_correccion_1_001_allow_safe_absolute_urls',
    ];
    $assert($class::IDS === $ids, 'EXACT_ALLOWLIST_ORDER');
    $assert($class::dependencies() === [
        $ids[0]=>['db_scope_1_001_create_scope_tables'], $ids[1]=>[$ids[0]],
        $ids[2]=>['tp_partidas_estados_db_1_001_create_ticket_product_tables','tp_partidas_estados_correo_config_1_001_create_mail_configuration'], $ids[3]=>[$ids[2]],
    ], 'DEPENDENCY_MAP');
    $env = ['APP_ENV'=>'qa','APP_DB_NAME'=>'r_erp_tickets_qa_2f','APP_DB_USER'=>'r_erp_tickets_qa_setup','APP_DB_HOST'=>'localhost','APP_DB_PORT'=>'3306','APP_DB_PASSWORD'=>'synthetic-test-value'] + $class::CONFIRMATIONS;
    $class::environment($env, true);
    foreach (['production','development','','QA'] as $value) {
        $reject(static fn () => $class::environment(array_replace($env, ['APP_ENV'=>$value]), true), 'APP_ENV_GUARD_FAILED');
    }
    $class::environment(array_replace($env, ['APP_ENV'=>'test']), true);
    foreach (['APP_DB_NAME'=>['r_erp_db_core_0_test','DATABASE_ENV_GUARD_FAILED'], 'APP_DB_USER'=>['root','ACCOUNT_ENV_GUARD_FAILED'], 'APP_DB_HOST'=>['remote.example','HOST_ENV_GUARD_FAILED'], 'APP_DB_PORT'=>['3307','PORT_ENV_GUARD_FAILED'], 'APP_DB_PASSWORD'=>['','CREDENTIAL_MISSING']] as $key=>[$bad,$code]) {
        $reject(static fn () => $class::environment(array_replace($env, [$key=>$bad]), true), $code);
    }
    foreach ($class::CONFIRMATIONS as $key=>$value) {
        foreach ([null,'YES','wrong'] as $bad) {
            $reject(static fn () => $class::environment(array_replace($env, [$key=>$bad]), true), 'DOUBLE_CONFIRMATION_FAILED');
        }
    }
    $identity = ['db'=>$class::DATABASE,'login'=>$class::ACCOUNT,'effective'=>$class::ACCOUNT,'hostname'=>$class::HOSTNAME,'port'=>3306,'version'=>'8.0.38','sql_mode'=>'STRICT_TRANS_TABLES','fk_checks'=>1];
    $class::identity($identity);
    foreach (['db'=>['wrong','DATABASE_IDENTITY_FAILED'], 'login'=>['root@localhost','ACCOUNT_IDENTITY_FAILED'], 'effective'=>['root@localhost','ACCOUNT_IDENTITY_FAILED'], 'hostname'=>['OTHER','SERVER_IDENTITY_FAILED'], 'port'=>[3307,'SERVER_IDENTITY_FAILED'], 'version'=>['8.4.0','MYSQL_VERSION_UNREVIEWED'], 'sql_mode'=>['NO_BACKSLASH_ESCAPES','SQL_MODE_UNREVIEWED'], 'fk_checks'=>[0,'FOREIGN_KEY_CHECKS_DISABLED']] as $key=>[$bad,$code]) {
        $reject(static fn () => $class::identity(array_replace($identity, [$key=>$bad])), $code);
    }
    $class::assertGrantScope([
        'GRANT USAGE ON *.* TO `r_erp_tickets_qa_setup`@`localhost`',
        'GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP ON `r_erp_tickets_qa_2f`.* TO `r_erp_tickets_qa_setup`@`localhost`',
    ]);
    foreach ([
        [['GRANT SELECT ON `other_schema`.* TO `r_erp_tickets_qa_setup`@`localhost`'], 'NON_QA_GRANT_FORBIDDEN'],
        [['GRANT ALL PRIVILEGES ON *.* TO `r_erp_tickets_qa_setup`@`localhost`'], 'GLOBAL_GRANT_FORBIDDEN'],
        [['GRANT USAGE ON *.* TO `r_erp_tickets_qa_setup`@`localhost`'], 'QA_GRANT_MISSING'],
        [['GRANT SELECT ON `r_erp_tickets_qa_2f`.* TO `r_erp_tickets_qa_setup`@`localhost` WITH GRANT OPTION'], 'QA_GRANT_OPTION_FORBIDDEN'],
    ] as [$grants, $code]) {
        $reject(static fn () => $class::assertGrantScope($grants), $code);
    }
    $s = ['applied'=>$class::BASELINE,'tables'=>[],'columns'=>[],'checks'=>[],'indexes'=>[],'foreign_keys'=>[],'code_rows'=>['empresas'=>0,'almacenes'=>0]];
    foreach (['schema_migrations','usuarios','empresas','almacenes','tickets_productos','tickets_productos_partidas','mail_accounts','tickets_productos_correo_reglas'] as $t) { $s['tables'][$t] = ['ENGINE'=>'InnoDB']; }
    foreach (['usuarios','tickets_productos','tickets_productos_partidas','mail_accounts'] as $t) { $s['columns'][$t]['id'] = ['COLUMN_TYPE'=>'bigint unsigned']; }
    $s['columns']['schema_migrations'] = ['migration'=>[], 'applied_at'=>[]];
    $unique = static fn (string $column): array => [['COLUMN_NAME'=>$column,'NON_UNIQUE'=>0,'SEQ_IN_INDEX'=>1,'SUB_PART'=>null,'IS_VISIBLE'=>'YES']];
    foreach (['schema_migrations','usuarios','tickets_productos','tickets_productos_partidas','mail_accounts'] as $t) { $s['indexes'][$t]['PRIMARY'] = $unique($t === 'schema_migrations' ? 'migration' : 'id'); }
    foreach (['empresas','almacenes'] as $t) {
        $s['columns'][$t]['codigo'] = ['COLUMN_TYPE'=>'varchar(64)','COLLATION_NAME'=>'ascii_bin','IS_NULLABLE'=>'NO'];
        $s['checks'][$t]['chk_' . $t . '_codigo'] = ['ENFORCED'=>'YES','CHECK_CLAUSE'=>$class::codeClause(3, false)];
    }
    $initial = $class::preflight($s);
    $assert($initial['PENDING_EXPECTED'] === $ids && $initial['ALREADY_APPLIED_EXPECTED'] === [] && $initial['UNEXPECTED_PENDING'] === [], 'ALL_PENDING');
    foreach ($class::BASELINE as $dependency) {
        $bad = $s; $bad['applied'] = array_values(array_diff($bad['applied'], [$dependency]));
        $reject(static fn () => $class::preflight($bad), 'EXTERNAL_DEPENDENCY_MISSING');
    }
    $bad = $s; $bad['applied'][] = 'unreviewed_migration';
    $reject(static fn () => $class::preflight($bad), 'UNEXPECTED_REGISTERED_MIGRATION');
    $bad = $s; $bad['applied'][] = $ids[2];
    $reject(static fn () => $class::preflight($bad), 'MIGRATION_ORDER_DRIFT');
    $bad = $s; $bad['tables'][$class::OUTBOX] = ['ENGINE'=>'InnoDB'];
    $reject(static fn () => $class::preflight($bad), 'OUTBOX_REGISTRATION_DRIFT');
    $bad = $s; $bad['code_rows']['empresas'] = 1;
    $reject(static fn () => $class::preflight($bad), 'CODE_DATA_REQUIRES_SEPARATE_REVIEW');
    $bad = $s; $bad['indexes']['usuarios']['PRIMARY'][0]['SUB_PART'] = 4;
    $reject(static fn () => $class::preflight($bad), 'UNIQUE_INDEX_DRIFT');
    $bad = $s; $bad['columns']['usuarios']['id']['COLUMN_TYPE'] = 'bigint';
    $reject(static fn () => $class::preflight($bad), 'PHYSICAL_KEY_TYPE_DRIFT');
    foreach ([str_replace('BETWEEN 3', 'BETWEEN 2', $class::codeClause(3, false)), str_replace('LOWER','UPPER',$class::codeClause(3,false)), $class::codeClause(3,false) . ' OR 1=1', str_replace('[a-z0-9]', '[A-Z0-9]', $class::codeClause(3,false))] as $clause) {
        $reject(static fn () => $class::assertCodeCheck(['ENFORCED'=>'YES','CHECK_CLAUSE'=>$clause],3,false), 'CODE_CHECK_DRIFT');
    }
    $reject(static fn () => $class::assertCodeCheck(['ENFORCED'=>'NO','CHECK_CLAUSE'=>$class::codeClause(3,false)],3,false), 'CODE_CHECK_NOT_ENFORCED');
    // Recorded metadata shape: escaped delimiters with no backslashes inside
    // the code regex. No literal case folding is permitted.
    $physicalCode = "((char_length(`codigo`) between 3 and 64) and (`codigo` = lower(`codigo`)) and regexp_like(`codigo`,_ascii\\'^[a-z0-9]+([._-][a-z0-9]+)*$\\'))";
    $class::assertCodeCheck(['ENFORCED'=>'YES','CHECK_CLAUSE'=>$physicalCode],3,false);
    $regrouped = "CHAR_LENGTH(codigo BETWEEN 3 AND 64) AND codigo = LOWER(codigo) AND REGEXP_LIKE(codigo, '^[a-z0-9]+([._-][a-z0-9]+)*$')";
    $reject(static fn () => $class::assertCodeCheck(['ENFORCED'=>'YES','CHECK_CLAUSE'=>$regrouped],3,false), 'CODE_CHECK_DRIFT');
    $afterMin = $s; $afterMin['applied'][] = $ids[0];
    foreach (['empresas','almacenes'] as $t) { $afterMin['checks'][$t]['chk_' . $t . '_codigo']['CHECK_CLAUSE'] = $class::codeClause(2,false); }
    $assert($class::preflight($afterMin)['PENDING_EXPECTED'] === array_slice($ids,1), 'MIN2_APPLIED_SKIP');
    $afterUpper = $afterMin; $afterUpper['applied'][] = $ids[1];
    foreach (['empresas','almacenes'] as $t) { $afterUpper['checks'][$t]['chk_' . $t . '_codigo']['CHECK_CLAUSE'] = $class::codeClause(2,true); }
    $assert($class::preflight($afterUpper)['PENDING_EXPECTED'] === array_slice($ids,2), 'UPPER_APPLIED_SKIP');
    $bad = $afterUpper; $bad['applied'][] = $ids[2];
    $reject(static fn () => $class::preflight($bad), 'OUTBOX_REGISTRATION_DRIFT');
    // Build outbox metadata from the actual allowlisted SQL source, never up().
    $source = file_get_contents(dirname(__DIR__) . '/migrations/' . $ids[2] . '.php');
    preg_match_all('/^\s*(\w+) (BIGINT UNSIGNED|SMALLINT UNSIGNED|VARCHAR\(\d+\)|MEDIUMTEXT|JSON|DATETIME)([^\r\n]*)/m', $source, $matches, PREG_SET_ORDER);
    $outbox = $afterUpper; $outbox['applied'][] = $ids[2]; $outbox['tables'][$class::OUTBOX] = ['ENGINE'=>'InnoDB'];
    foreach ($matches as $m) { $outbox['columns'][$class::OUTBOX][$m[1]] = ['COLUMN_TYPE'=>strtolower($m[2]),'COLUMN_DEFAULT'=>$m[1] === 'status' ? 'PENDIENTE' : null,'IS_NULLABLE'=>str_contains($m[3], 'NOT NULL') ? 'NO' : 'YES','COLLATION_NAME'=>str_contains($m[3], 'ascii_bin') ? 'ascii_bin' : null]; }
    $outbox['indexes'][$class::OUTBOX] = ['PRIMARY'=>$unique('id'),'uq_tickets_productos_correos_dedupe'=>$unique('dedupe_key')];
    foreach (['ticket'=>['ticket_id','tickets_productos','CASCADE'],'partida'=>['partida_id','tickets_productos_partidas','SET NULL'],'creado_por'=>['creado_por_usuario_id','usuarios','SET NULL']] as $suffix=>[$column,$target,$delete]) { $outbox['foreign_keys'][$class::OUTBOX]['fk_tickets_productos_correos_' . $suffix] = [['COLUMN_NAME'=>$column,'REFERENCED_TABLE_NAME'=>$target,'REFERENCED_COLUMN_NAME'=>'id','DELETE_RULE'=>$delete,'UPDATE_RULE'=>'RESTRICT']]; }
    foreach ($class::outboxCheckDefinitions() as $name=>$definition) { $outbox['checks'][$class::OUTBOX][$name] = ['ENFORCED'=>'YES','CHECK_CLAUSE'=>$definition]; }
    $outbox['checks'][$class::OUTBOX]['chk_tickets_productos_correos_status']['CHECK_CLAUSE'] = "(`status` in (_ascii'PENDIENTE',_ascii'ENVIANDO',_ascii'ENVIADO',_ascii'ERROR',_ascii'CANCELADO'))";
    $outbox['checks'][$class::OUTBOX]['chk_tickets_productos_correos_no_sensitive']['CHECK_CLAUSE'] = $class::sensitiveClause(false);
    $assert($class::preflight($outbox)['PENDING_EXPECTED'] === [$ids[3]], 'OUTBOX_POSTCHECK');
    $final = $outbox; $final['applied'][] = $ids[3];
    $final['checks'][$class::OUTBOX]['chk_tickets_productos_correos_no_sensitive']['CHECK_CLAUSE'] = $class::sensitiveClause(true);
    $assert($class::preflight($final)['PENDING_EXPECTED'] === [], 'FINAL_POSTCHECK');
    $encoded = $final;
    $rawClause = $class::sensitiveClause(true);
    $encodedClause = str_replace(["\\", "'"], ["\\\\", "\\'"], $rawClause);
    $encoded['checks'][$class::OUTBOX]['chk_tickets_productos_correos_no_sensitive']['CHECK_CLAUSE'] = $encodedClause;
    $assert($class::preflight($encoded)['PENDING_EXPECTED'] === [], 'METADATA_ESCAPING_SINGLE_DOCUMENTED_LAYER');
    $bad = $final;
    $bad['checks'][$class::OUTBOX]['chk_tickets_productos_correos_intentos']['CHECK_CLAUSE'] = 'intentos >= max_intentos';
    $reject(static fn () => $class::preflight($bad), 'OUTBOX_CHECK_DEFINITION_DRIFT');
    $bad = $s; $bad['columns']['empresas']['codigo']['COLLATION_NAME'] = 'ascii_general_ci';
    $reject(static fn () => $class::preflight($bad), 'CODE_COLUMN_DRIFT');
    foreach (['default','unique','fk','url','status','check','column'] as $mutation) {
        $bad = $final;
        switch ($mutation) {
            case 'default': $bad['columns'][$class::OUTBOX]['status']['COLUMN_DEFAULT'] = 'ERROR'; break;
            case 'unique': $bad['indexes'][$class::OUTBOX]['uq_tickets_productos_correos_dedupe'][0]['NON_UNIQUE'] = 1; break;
            case 'fk': $bad['foreign_keys'][$class::OUTBOX]['fk_tickets_productos_correos_ticket'][0]['REFERENCED_TABLE_NAME'] = 'usuarios'; break;
            case 'url': $bad['checks'][$class::OUTBOX]['chk_tickets_productos_correos_no_sensitive']['CHECK_CLAUSE'] = $class::sensitiveClause(false); break;
            case 'status': $bad['checks'][$class::OUTBOX]['chk_tickets_productos_correos_status']['CHECK_CLAUSE'] = "status IN ('PENDIENTE','ERROR')"; break;
            case 'check': unset($bad['checks'][$class::OUTBOX]['chk_tickets_productos_correos_email']); break;
            case 'column': unset($bad['columns'][$class::OUTBOX]['dedupe_key']); break;
        }
        $codes = ['default'=>'OUTBOX_STATUS_DEFAULT_DRIFT','unique'=>'UNIQUE_INDEX_DRIFT','fk'=>'OUTBOX_FK_DRIFT','url'=>'OUTBOX_URL_CHECK_DRIFT','status'=>'OUTBOX_STATUS_CHECK_DRIFT','check'=>'OUTBOX_CHECK_MISSING','column'=>'OUTBOX_COLUMN_SET_DRIFT'];
        $reject(static fn () => $class::preflight($bad), $codes[$mutation]);
    }
    // A missing regex backslash must not be accepted as a cosmetic difference.
    $assert($class::tokens($class::sensitiveClause(true)) !== $class::tokens(str_replace(str_repeat('\\',4),str_repeat('\\',2),$class::sensitiveClause(true))), 'REGEX_BYTES_STRICT');
    $events = [];
    $done = $class::applyPending(array_slice($ids,2), static function ($id) use (&$events): void { $events[] = 'up:' . $id; }, static function ($id) use (&$events): void { $events[] = 'register:' . $id; }, static function ($id,$registered) use (&$events): void { $events[] = ($registered ? 'post:' : 'physical:') . $id; });
    $assert($done === array_slice($ids,2) && $events === ['up:'.$ids[2],'physical:'.$ids[2],'register:'.$ids[2],'post:'.$ids[2],'up:'.$ids[3],'physical:'.$ids[3],'register:'.$ids[3],'post:'.$ids[3]], 'EXACT_EXECUTION_SKIP_AND_REGISTRATION_ORDER');
    foreach (['apply','register','verify','post_register_verify'] as $stage) {
        $visited = [];
        $reject(static function () use ($class,$ids,$stage,&$visited): void {
            $class::applyPending($ids, static function ($id) use ($stage,&$visited): void { $visited[]=$id; if ($stage === 'apply') { throw new RuntimeException('INJECTED_FAILURE'); } }, static function () use ($stage): void { if ($stage === 'register') { throw new RuntimeException('INJECTED_FAILURE'); } }, static function ($id,$registered) use ($stage): void { if ($stage === 'verify' || ($stage === 'post_register_verify' && $registered)) { throw new RuntimeException('INJECTED_FAILURE'); } });
        }, 'INJECTED_FAILURE');
        $assert($visited === [$ids[0]], 'FAIL_STOP_' . $stage);
    }
    foreach ([['arbitrary'],array_reverse($ids),[$ids[0],$ids[0]]] as $bad) { $reject(static fn () => $class::applyPending($bad, static fn () => null, static fn () => null, static fn () => null), 'PENDING_ALLOWLIST_OR_ORDER_FAILED'); }
    foreach ($ids as $index=>$failedId) {
        $visited = [];
        $reject(static function () use ($class,$ids,$failedId,&$visited): void {
            $class::applyPending($ids, static function ($id) use ($failedId,&$visited): void { $visited[]=$id; if ($id === $failedId) { throw new RuntimeException('INJECTED_FAILURE'); } }, static fn () => null, static fn () => null);
        }, 'INJECTED_FAILURE');
        $assert($visited === array_slice($ids,0,$index+1), 'FAIL_STOP_AT_EVERY_MIGRATION');
    }
    $assert($class::applyPending([],static fn () => throw new RuntimeException('UNEXPECTED_APPLY'),static fn () => null,static fn () => null) === [], 'ALL_APPLIED_NO_UP');
    $assert(array_keys($class::loadMigrations()) === $ids, 'REAL_ARTIFACT_IDS');
    $connector = static function (): never { throw new RuntimeException('CONNECTOR_MUST_NOT_RUN'); };
    $assert($class::run(['runner','--plan'],$connector)['pdo_opened'] === false, 'PLAN_NEVER_CONNECTS');
    $reject(static fn () => $class::run(['runner','--execute','--all'],$connector), 'CLI_MODE_REQUIRED');
    // Prove CLI environment rejection happens before connector using only a
    // temporary process variable, restored immediately; no .env is involved.
    $old = getenv('APP_ENV');
    try {
        putenv('APP_ENV=production');
        $reject(static fn () => $class::run(['runner','--execute'],$connector), 'APP_ENV_GUARD_FAILED');
    } finally { putenv($old === false ? 'APP_ENV' : 'APP_ENV=' . $old); }
    $runnerSource = file_get_contents(dirname(__DIR__) . '/qa_tickets_atomicity_pending_migrations.php');
    foreach (['glob(', 'MigrationRunner(', '->down(', 'DROP DATABASE', 'TRUNCATE ', 'bootstrap/database.php', 'PHPMailer', '->seed(', 'CREATE TABLE'] as $forbidden) { $assert(!str_contains($runnerSource,$forbidden), 'NO_BROAD_OR_PRODUCTION_PATH'); }
    return ['status'=>'PASS','assertions'=>$assertions,'allowlist_order'=>'PASS','environment_identity_confirmations'=>'PASS','dependencies_drift'=>'PASS','partial_and_final_postchecks'=>'PASS','fail_stop_and_skip'=>'PASS','regex_bytes'=>'PASS','no_broad_execution'=>'PASS','mysql_connections'=>0,'sql_executed'=>false];
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    echo json_encode(qaTicketsAtomicityPendingContractTest(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
}
