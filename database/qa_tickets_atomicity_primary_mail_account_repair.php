<?php
declare(strict_types=1);

require_once __DIR__ . '/qa_tickets_atomicity_rbac_repair.php';

/** QA-only code alignment. No production bootstrap, SMTP or general provisioning. */
final class QaTicketsAtomicityPrimaryMailAccountRepair
{
    public const SOURCE = 'TICKETS_ATOMICITY_QA';
    public const TARGET = 'TICKETS_PRODUCTOS';
    public const EVENTS = ['TICKET_CREADO','PARTIDA_APROBADA','PARTIDA_RECHAZADA','TICKET_CANCELADO','TICKET_RESUELTO_TOTAL','TICKET_RESUELTO_PARCIAL'];
    public const CONFIRMATIONS = [
        'TICKETS_QA_PRIMARY_MAIL_REPAIR_CONFIRM'=>'ALIGN_QA_MAIL_ACCOUNT_1_PRIMARY_CODE',
        'TICKETS_QA_PRIMARY_MAIL_DATABASE_CONFIRM'=>'r_erp_tickets_qa_2f',
    ];
    // Preserve the automatic timestamp too: codigo must be the only changed field.
    public const UPDATE_SQL = 'UPDATE mail_accounts SET codigo = :target, updated_at = updated_at WHERE id = :id AND codigo = :source AND activo = 1';

    private static function demand(bool $ok, string $code): void
    {
        if (!$ok) { throw new RuntimeException($code); }
    }

    public static function environment(array $env, bool $execute): void
    {
        QaTicketsAtomicityRbacRepair::assertEnvironment($env,false);
        if ($execute) {
            foreach (self::CONFIRMATIONS as $key=>$value) {
                self::demand(($env[$key]??null)===$value,'PRIMARY_MAIL_DOUBLE_CONFIRMATION_FAILED');
            }
        }
    }

    public static function fingerprint(array $data): string
    {
        return hash('sha256',serialize($data));
    }

    public static function classify(array $accounts, array $rules): array
    {
        self::demand(count($accounts)===1,'BLOCKED_CONFLICT');
        $a=$accounts[0];
        self::demand((int)($a['id']??0)===1 && in_array($a['codigo']??null,[self::SOURCE,self::TARGET],true),'BLOCKED_CONFLICT');
        $expected=['nombre'=>'QA Atomicity Mail','from_email'=>'qa.atomicity.tickets@example.invalid',
            'from_name'=>'QA Atomicity','reply_to_email'=>'qa.atomicity.tickets@example.invalid',
            'smtp_host'=>'invalid','smtp_encryption'=>'none',
            'smtp_username'=>'qa.atomicity.tickets@example.invalid','smtp_secret_ref'=>'QA_ATOMICITY_REF'];
        foreach ($expected as $key=>$value) { self::demand(($a[$key]??null)===$value,'QA_ACCOUNT_FIELD_CONFLICT'); }
        self::demand((int)($a['activo']??0)===1 && (int)($a['smtp_port']??0)===2525,'QA_ACCOUNT_FIELD_CONFLICT');
        $events=[];
        foreach ($rules as $r) {
            self::demand((int)$r['mail_account_id']===1 && (int)$r['activo']===1
                && (int)$r['enviar_solicitante']===0 && (int)$r['enviar_responsables']===0
                && json_decode((string)$r['to_json'],true)===['qa-recipient@example.invalid']
                && in_array($r['cc_json'],[null,'[]'],true) && in_array($r['bcc_json'],[null,'[]'],true),'QA_RULE_CONFLICT');
            $events[]=$r['evento'];
        }
        $expectedEvents=self::EVENTS; sort($events); sort($expectedEvents);
        self::demand($events===$expectedEvents,'QA_RULE_SET_CONFLICT');
        $nonCode=$a; unset($nonCode['codigo']); ksort($nonCode);
        foreach ($rules as &$r) { ksort($r); } unset($r);
        usort($rules,static fn(array $x,array $y):int=>(int)$x['id']<=>(int)$y['id']);
        return ['state'=>$a['codigo']===self::SOURCE?'READY_FOR_REPAIR':'ALREADY_REPAIRED',
            'SOURCE_ACCOUNT_FOUND'=>$a['codigo']===self::SOURCE,'SOURCE_ACCOUNT_ID'=>1,
            'SOURCE_ACCOUNT_CODE'=>$a['codigo'],'TARGET_CODE_ALREADY_EXISTS'=>$a['codigo']===self::TARGET,
            'TARGET_CODE_CONFLICT'=>false,'QA_MAIL_ACCOUNT_ID'=>1,'QA_MAIL_ACCOUNT_CODE'=>$a['codigo'],
            'QA_MAIL_ACCOUNT_ACTIVE'=>true,'QA_MAIL_ACCOUNT_NON_DELIVERABLE'=>true,
            'SOURCE_ACCOUNT_RULE_COUNT'=>6,'SOURCE_ACCOUNT_RULE_EVENTS'=>self::EVENTS,'SUCCESS_RULE_COUNT'=>6,
            'account_fingerprint'=>self::fingerprint($nonCode),'rules_fingerprint'=>self::fingerprint($rules)];
    }

    public static function plan(): array
    {
        return ['status'=>'PASS','mode'=>'DB_FREE_PLAN','database'=>'r_erp_tickets_qa_2f',
            'source'=>self::SOURCE,'target'=>self::TARGET,'id'=>1,'strategy'=>'EXACT_UPDATE_CODE_ONLY',
            'expected_update_rows'=>1,'confirmations'=>self::CONFIRMATIONS,
            'mysql_connections'=>0,'write_sql_executed'=>false];
    }

    private static function rows(PDO $pdo,string $sql,array $params=[]): array
    {
        $s=$pdo->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function inspect(PDO $pdo,bool $lock=false): array
    {
        $columns=self::rows($pdo,"SELECT COLUMN_NAME, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mail_accounts'");
        $names=array_column($columns,'COLUMN_NAME');
        foreach (['id','codigo','activo','updated_at','smtp_secret_ref','smtp_host'] as $name) {
            self::demand(in_array($name,$names,true),'ACCOUNT_SCHEMA_CONFLICT');
        }
        foreach ($columns as $c) {
            self::demand(!str_contains(strtolower($c['EXTRA']),'on update') || $c['COLUMN_NAME']==='updated_at','AUTOMATIC_FIELD_CONFLICT');
            self::demand(!preg_match('/password|credential|token|secret(?!_ref)/i',$c['COLUMN_NAME']),'SECRET_COLUMN_CONFLICT');
        }
        $engine=self::rows($pdo,"SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mail_accounts'");
        self::demand(($engine[0]['ENGINE']??null)==='InnoDB','ACCOUNT_ENGINE_CONFLICT');
        $accounts=self::rows($pdo,'SELECT * FROM mail_accounts WHERE id=1 OR codigo IN (?,?) ORDER BY id'.($lock?' FOR UPDATE':''),[self::SOURCE,self::TARGET]);
        $rules=self::rows($pdo,'SELECT * FROM tickets_productos_correo_reglas ORDER BY id'.($lock?' FOR UPDATE':''));
        $result=self::classify($accounts,$rules);
        // Same lookup as MailConfigurationRepository::primaryAccount().
        $primary=self::rows($pdo,'SELECT * FROM mail_accounts WHERE codigo = :codigo LIMIT 1',['codigo'=>self::TARGET]);
        $result['RUNTIME_PRIMARY_ACCOUNT_FOUND']=count($primary)===1;
        $result['RUNTIME_PRIMARY_ACCOUNT_ID']=$primary===[]?null:(int)$primary[0]['id'];
        return $result;
    }

    public static function assertPostcheck(array $before,array $after): void
    {
        self::demand($after['state']==='ALREADY_REPAIRED' && $after['RUNTIME_PRIMARY_ACCOUNT_FOUND']
            && $after['RUNTIME_PRIMARY_ACCOUNT_ID']===1,'PRIMARY_MAIL_POSTCHECK_FAILED');
        self::demand($before['account_fingerprint']===$after['account_fingerprint'],'ACCOUNT_NON_CODE_FIELDS_CHANGED');
        self::demand($before['rules_fingerprint']===$after['rules_fingerprint'],'RULES_CHANGED');
    }

    public static function assertAffectedRows(int $rows): void
    {
        self::demand($rows===1,'UPDATE_ROW_COUNT_FAILED');
    }

    public static function run(array $argv,?callable $connector=null): array
    {
        self::demand(count($argv)===2 && in_array($argv[1],['--plan','--contract-test','--inspect','--execute'],true),'CLI_MODE_REQUIRED');
        $mode=$argv[1];
        if ($mode==='--plan') { return self::plan(); }
        if ($mode==='--contract-test') {
            require_once __DIR__.'/tests/qa_tickets_atomicity_primary_mail_account_repair_db_free_test.php';
            return qaTicketsPrimaryMailContractTest();
        }
        $env=[];
        foreach (array_merge(['APP_ENV','APP_DB_NAME','APP_DB_HOST','APP_DB_PORT','APP_DB_USER','APP_DB_PASSWORD'],array_keys(self::CONFIRMATIONS)) as $key) { $env[$key]=getenv($key); }
        self::environment($env,$mode==='--execute');
        $pdo=$connector!==null?$connector($env):new PDO('mysql:host='.$env['APP_DB_HOST'].';port=3306;dbname=r_erp_tickets_qa_2f;charset=utf8mb4',$env['APP_DB_USER'],$env['APP_DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
        unset($env);
        self::demand($pdo instanceof PDO && !$pdo->inTransaction(),'PDO_STATE_INVALID');
        $identity=self::rows($pdo,'SELECT DATABASE() AS `database`,USER() AS login,CURRENT_USER() AS effective,@@hostname AS hostname,@@port AS port')[0];
        $identity['port']=(int)$identity['port'];
        QaTicketsAtomicityRbacRepair::assertIdentity($identity);
        if ($mode==='--inspect') { return ['status'=>'PASS','mode'=>'SELECT_ONLY','write_sql_executed'=>false]+self::inspect($pdo); }
        $pdo->beginTransaction();
        try {
            $before=self::inspect($pdo,true);
            $updated=false;
            if ($before['state']==='READY_FOR_REPAIR') {
                $s=$pdo->prepare(self::UPDATE_SQL);
                $s->execute(['target'=>self::TARGET,'id'=>1,'source'=>self::SOURCE]);
                self::assertAffectedRows($s->rowCount()); $updated=true;
            }
            $after=self::inspect($pdo,true);
            self::assertPostcheck($before,$after);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw new RuntimeException('PRIMARY_MAIL_REPAIR_STOPPED',0,$e);
        }
        return ['status'=>'PASS','mode'=>'EXECUTE','write_sql_executed'=>$updated,'updated_rows'=>$updated?1:0,
            'ACCOUNT_NON_CODE_FIELDS_UNCHANGED'=>true,'RULES_UNCHANGED'=>true]+$after;
    }
}

if (PHP_SAPI==='cli' && realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    try { echo json_encode(QaTicketsAtomicityPrimaryMailAccountRepair::run($argv),JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL; }
    catch (Throwable $e) {
        $code=$e instanceof PDOException?'PDO_OPERATION_FAILED':$e->getMessage();
        echo json_encode(['status'=>'BLOCKED','code'=>preg_match('/^[A-Z0-9_]+$/D',$code)?$code:'QA_OPERATION_FAILED']).PHP_EOL; exit(1);
    }
}
