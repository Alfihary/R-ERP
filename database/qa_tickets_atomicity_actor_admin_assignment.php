<?php
declare(strict_types=1);

require_once __DIR__ . '/qa_tickets_atomicity_rbac_repair.php';

final class QaTicketsAtomicityActorAdminAssignment
{
    public const CONFIRMATIONS = [
        'TICKETS_QA_ACTOR_ADMIN_CONFIRM' => 'ASSIGN_QA_ACTOR_16_ADMIN_1',
        'TICKETS_QA_ACTOR_ADMIN_DATABASE_CONFIRM' => 'r_erp_tickets_qa_2f',
    ];

    public static function demand(bool $ok, string $code): void
    {
        if (!$ok) { throw new RuntimeException($code); }
    }

    public static function environment(array $env, bool $execute): void
    {
        QaTicketsAtomicityRbacRepair::assertEnvironment($env, false);
        if ($execute) {
            foreach (self::CONFIRMATIONS as $key => $value) {
                self::demand(($env[$key] ?? null) === $value, 'ACTOR_ADMIN_DOUBLE_CONFIRMATION_FAILED');
            }
        }
    }

    public static function plan(): array
    {
        return ['status'=>'PASS', 'mode'=>'DB_FREE_PLAN', 'database'=>'r_erp_tickets_qa_2f',
            'QA_ACTOR_ID'=>16, 'ADMIN_ROLE_ID'=>1, 'USER_ROLE_TABLE'=>'usuario_roles',
            'USER_ROLE_USER_COLUMN'=>'usuario_id', 'USER_ROLE_ROLE_COLUMN'=>'rol_id',
            'USER_ROLE_REQUIRED_COLUMNS'=>['usuario_id','rol_id'],
            'USER_ROLE_UNIQUE_CONSTRAINT'=>'PRIMARY(usuario_id,rol_id)',
            'strategy'=>'INSERT_IF_ABSENT', 'confirmations'=>self::CONFIRMATIONS,
            'insert_only'=>true, 'delete_sql'=>false, 'update_sql'=>false,
            'mysql_connections'=>0, 'write_sql_executed'=>false];
    }

    public static function validateRelation(array $rows): bool
    {
        self::demand(count($rows) <= 1, 'DUPLICATE_RELATION');
        if ($rows === []) { return false; }
        $r = $rows[0];
        self::demand((int)$r['usuario_id'] === 16 && (int)$r['rol_id'] === 1
            && (int)$r['activo'] === 1 && $r['eliminado_en'] === null, 'RELATION_CONFLICT');
        return true;
    }

    private static function rows(PDO $pdo, string $sql, array $params = []): array
    {
        $s=$pdo->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function inspect(PDO $pdo): array
    {
        $columns=self::rows($pdo, "SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='usuario_roles' ORDER BY ORDINAL_POSITION");
        $required=[];
        foreach ($columns as $c) {
            if ($c['IS_NULLABLE']==='NO' && $c['COLUMN_DEFAULT']===null && !str_contains($c['EXTRA'],'auto_increment')) { $required[]=$c['COLUMN_NAME']; }
        }
        self::demand($required===['usuario_id','rol_id'], 'RELATION_SCHEMA_DRIFT');
        $pk=self::rows($pdo, "SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='usuario_roles' AND INDEX_NAME='PRIMARY' AND NON_UNIQUE=0 ORDER BY SEQ_IN_INDEX");
        self::demand(array_column($pk,'COLUMN_NAME')===['usuario_id','rol_id'], 'RELATION_KEY_DRIFT');
        $engine=self::rows($pdo, "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='usuario_roles'");
        self::demand(($engine[0]['ENGINE']??null)==='InnoDB', 'RELATION_ENGINE_DRIFT');
        $actors=self::rows($pdo, 'SELECT id,username,email,activo,eliminado_en FROM usuarios WHERE email=? OR username=? OR id=?', ['qa.atomicity.tickets@example.invalid','qa.atomicity.tickets',16]);
        self::demand(count($actors)===1, 'ACTOR_IDENTITY_CONFLICT'); $a=$actors[0];
        self::demand((int)$a['id']===16 && $a['email']==='qa.atomicity.tickets@example.invalid' && $a['username']==='qa.atomicity.tickets' && (int)$a['activo']===1 && $a['eliminado_en']===null, 'ACTOR_INVALID');
        $roles=self::rows($pdo, 'SELECT id,codigo,es_sistema,activo,eliminado_en FROM roles WHERE codigo=? OR id=?', ['ADMIN',1]);
        self::demand(count($roles)===1 && (int)$roles[0]['id']===1, 'ADMIN_IDENTITY_CONFLICT');
        QaTicketsAtomicityRbacRepair::assertAdminRole($roles[0]);
        $assigned=self::validateRelation(self::rows($pdo, 'SELECT usuario_id,rol_id,activo,eliminado_en FROM usuario_roles WHERE usuario_id=? AND rol_id=?', [16,1]));
        $allPermissions=self::rows($pdo, 'SELECT * FROM permisos ORDER BY id');
        $p=QaTicketsAtomicityRbacRepair::classifyPermissions($allPermissions);
        self::demand(count($p['present'])===9 && $p['missing']===[], 'PERMISSIONS_INVALID');
        $q=QaTicketsAtomicityRbacRepair::adminAssignmentQuery(1);
        self::demand(QaTicketsAtomicityRbacRepair::missingAdminAssignments(array_column(self::rows($pdo,$q['sql'],$q['params']),'codigo'))===[], 'ADMIN_PERMISSIONS_MISSING');
        return ['QA_ACTOR_ID'=>16,'ADMIN_ROLE_ID'=>1,'QA_ACTOR_ADMIN_ASSIGNED'=>$assigned,
            'QA_ACTOR_ADMIN_RELATION_COUNT'=>$assigned?1:0,'EXPECTED_PERMISSIONS_COUNT'=>9,
            'PRESENT_PERMISSIONS_COUNT'=>count($p['present']),
            'permissions_fingerprint'=>hash('sha256',serialize($allPermissions))];
    }

    public static function run(array $argv, ?callable $connector=null): array
    {
        self::demand(count($argv)===2 && in_array($argv[1],['--plan','--contract-test','--inspect','--execute'],true), 'CLI_MODE_REQUIRED');
        $mode=$argv[1];
        if ($mode==='--plan') { return self::plan(); }
        if ($mode==='--contract-test') {
            require_once __DIR__.'/tests/qa_tickets_atomicity_actor_admin_assignment_db_free_test.php';
            return qaTicketsActorAdminContractTest();
        }
        $env=[];
        foreach (array_merge(['APP_ENV','APP_DB_HOST','APP_DB_PORT','APP_DB_NAME','APP_DB_USER','APP_DB_PASSWORD'],array_keys(self::CONFIRMATIONS)) as $k) { $env[$k]=getenv($k); }
        self::environment($env,$mode==='--execute');
        $pdo=$connector!==null ? $connector($env) : new PDO('mysql:host='.$env['APP_DB_HOST'].';port=3306;dbname=r_erp_tickets_qa_2f;charset=utf8mb4',$env['APP_DB_USER'],$env['APP_DB_PASSWORD'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
        unset($env);
        self::demand($pdo instanceof PDO && !$pdo->inTransaction(), 'PDO_STATE_INVALID');
        QaTicketsAtomicityRbacRepair::assertIdentity(self::rows($pdo,'SELECT DATABASE() AS `database`,USER() AS login,CURRENT_USER() AS effective,@@hostname AS hostname,@@port AS port')[0]);
        $before=self::inspect($pdo);
        if ($mode==='--inspect') { return ['status'=>'PASS','mode'=>'SELECT_ONLY','write_sql_executed'=>false]+$before; }
        $inserted=false;
        $pdo->beginTransaction();
        try {
            self::rows($pdo,'SELECT id FROM usuarios WHERE id=? FOR UPDATE',[16]);
            self::rows($pdo,'SELECT id FROM roles WHERE id=? FOR UPDATE',[1]);
            $locked=self::inspect($pdo);
            self::demand($locked['permissions_fingerprint']===$before['permissions_fingerprint'],'PERMISSIONS_CHANGED');
            if (!$locked['QA_ACTOR_ADMIN_ASSIGNED']) {
                $s=$pdo->prepare('INSERT INTO usuario_roles (usuario_id,rol_id,activo,creado_por,actualizado_por) SELECT :actor,:role,1,:creator,:updater WHERE NOT EXISTS (SELECT 1 FROM usuario_roles WHERE usuario_id=:existing_actor AND rol_id=:existing_role)');
                try {
                    $s->execute(['actor'=>16,'role'=>1,'creator'=>16,'updater'=>16,'existing_actor'=>16,'existing_role'=>1]);
                    $inserted=$s->rowCount()===1;
                } catch (PDOException $e) {
                    if ((int)($e->errorInfo[1]??0)!==1062) { throw $e; }
                }
            }
            $after=self::inspect($pdo);
            self::demand($after['QA_ACTOR_ADMIN_ASSIGNED'] && $after['QA_ACTOR_ADMIN_RELATION_COUNT']===1,'ASSIGNMENT_POSTCHECK_FAILED');
            self::demand($before['permissions_fingerprint']===$after['permissions_fingerprint'],'PERMISSIONS_CHANGED');
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw new RuntimeException('ACTOR_ADMIN_REPAIR_STOPPED',0,$e);
        }
        return ['status'=>'PASS','mode'=>'EXECUTE','inserted'=>$inserted,'write_sql_executed'=>$inserted,'PERMISSIONS_UNCHANGED'=>true]+$after;
    }
}

if (PHP_SAPI==='cli' && realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    try { echo json_encode(QaTicketsAtomicityActorAdminAssignment::run($argv),JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL; }
    catch (Throwable $e) { echo json_encode(['status'=>'BLOCKED','code'=>$e instanceof PDOException?'PDO_OPERATION_FAILED':(preg_match('/^[A-Z0-9_]+$/D',$e->getMessage())?$e->getMessage():'QA_OPERATION_FAILED')]).PHP_EOL; exit(1); }
}
