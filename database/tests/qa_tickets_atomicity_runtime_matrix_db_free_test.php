<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/tickets_productos_correo_atomicity_qa_runner.php';

/** In-memory outbox double: exercises real enqueue, renderer, dedupe and skip. */
final class QaAtomicityMemoryOutbox implements \App\Domain\Tickets\ProductTicketEmailOutboxRepositoryContract
{
    public array $rows=[];
    public int $inserts=0;
    public function findTicketContext(int $ticketId): ?array
    {
        return ['id'=>$ticketId,'folio'=>'QA01-000001','estado'=>'EN_REVISION','total_partidas'=>1,
            'partidas_aprobadas'=>0,'partidas_rechazadas'=>0,'created_at'=>'2026-11-20 12:00:00',
            'cancelado_at'=>null,'empresa_nombre'=>'QA','almacen_nombre'=>'QA01','solicitante_username'=>'qa.atomicity.tickets',
            'solicitante_email'=>'qa.atomicity.tickets@example.invalid'];
    }
    public function currentDatabaseName(): string { return 'r_erp_tickets_qa_2f'; }
    public function qaFixtureRelationCounts(int $ticketId): array { return ['lines'=>1,'attachments'=>0]; }
    public function findLineContext(int $ticketId,int $partidaId): ?array { return ['id'=>$partidaId,'numero_partida'=>1,'estado'=>'EN_REVISION','descripcion'=>'QA item']; }
    public function findLinesContext(int $ticketId): array { return [$this->findLineContext($ticketId,1)]; }
    public function countAttachments(int $ticketId): int { return 0; }
    public function findByDedupeKey(string $dedupeKey): ?array { return $this->rows[$dedupeKey]??null; }
    public function insertPending(array $data): array { ++$this->inserts; return $this->rows[$data['dedupe_key']]=['id'=>$this->inserts]+$data; }
    public function markSent(int $id,?int $expectedAttempts=null,?string $expectedLastAttemptAt=null): array { return ['id'=>$id]; }
    public function markError(int $id,string $safeMessage,?int $expectedAttempts=null,?string $expectedLastAttemptAt=null): array { return ['id'=>$id]; }
}

function qaAtomicityMatrixContractTest(): array
{
    $checks=0;
    $assert=static function(bool $ok,string $label) use (&$checks):void {
        if (!$ok) { throw new RuntimeException('CONTRACT_FAILED_'.$label); } ++$checks;
    };
    $reject=static function(callable $fn,string $label) use ($assert):void {
        $caught=false; try { $fn(); } catch (RuntimeException|InvalidArgumentException $e) { $caught=true; }
        $assert($caught,$label);
    };
    $c=QaTicketsAtomicityRuntimeMatrix::class;
    $assert(tpAtomicityQaCaseIds()===['QA-A','QA-B','QA-C','QA-D','QA-E','QA-F','QA-G','QA-H'],'CASES');
    $env=['APP_ENV'=>'qa','APP_DB_NAME'=>'r_erp_tickets_qa_2f','APP_DB_USER'=>'r_erp_tickets_qa_runner','APP_DB_PASSWORD'=>'DB_FREE_PLACEHOLDER','APP_DB_HOST'=>'localhost','APP_DB_PORT'=>'3306']+$c::CONFIRMATIONS;
    $c::environment($env);
    foreach (['APP_ENV'=>'production','APP_DB_NAME'=>'r_erp_db_core_0_test','APP_DB_USER'=>'root','APP_DB_PASSWORD'=>'','APP_DB_HOST'=>'remote.invalid','APP_DB_PORT'=>'3307'] as $key=>$bad) {
        $case=$env; $case[$key]=$bad; $reject(static fn()=>$c::environment($case),'ENV_'.$key);
    }
    foreach ($c::CONFIRMATIONS as $key=>$value) {
        $case=$env; unset($case[$key]); $reject(static fn()=>$c::environment($case),'CONFIRM');
    }
    $identity=['database'=>TP_ATOMICITY_QA_DATABASE,'effective_user'=>TP_ATOMICITY_QA_ACCOUNT,'presented_user'=>TP_ATOMICITY_QA_ACCOUNT,'hostname'=>TP_ATOMICITY_QA_HOST,'port'=>3306];
    foreach (['database'=>'other','effective_user'=>'root@localhost','presented_user'=>'other@localhost','hostname'=>'other','port'=>3307] as $key=>$bad) {
        $case=$identity; $case[$key]=$bad; $reject(static fn()=>tpAtomicityQaAssertDatabaseIdentity($case),'IDENTITY_'.$key);
    }
    $repo=new QaAtomicityMemoryOutbox();
    foreach (tpAtomicityQaCaseIds() as $case) {
        $selected=tpAtomicityQaRepositoryForCase($repo,$case);
        $failing=in_array($case,['QA-B','QA-D','QA-F'],true);
        $assert(($selected instanceof FailingProductTicketEmailOutboxRepository)===$failing,'FAILURE_MAPPING');
        if ($failing) {
            try { $selected->insertPending([]); $assert(false,'FAILURE_MISSING'); }
            catch (RuntimeException $e) { $assert(get_class($e)===RuntimeException::class && $e->getMessage()==='QA_INJECTED_OUTBOX_FAILURE','FAILURE_EXACT'); }
            $assert($selected->findTicketContext(41)===$repo->findTicketContext(41),'DELEGATE_TICKET');
            $assert($selected->findLineContext(41,2)===$repo->findLineContext(41,2),'DELEGATE_LINE');
            $assert($selected->findLinesContext(41)===$repo->findLinesContext(41),'DELEGATE_LINES');
            $assert($selected->currentDatabaseName()===$repo->currentDatabaseName(),'DELEGATE_DB');
            $assert($selected->qaFixtureRelationCounts(41)===$repo->qaFixtureRelationCounts(41),'DELEGATE_COUNTS');
            $assert($selected->countAttachments(41)===0 && $selected->findByDedupeKey('absent')===null,'DELEGATE_LOOKUPS');
            $assert($selected->markSent(1)===['id'=>1] && $selected->markError(1,'QA')===['id'=>1],'DELEGATE_MARKS');
        }
    }
    // Construct production services without obtaining PDO; all hold one provider.
    $connection=new \App\Infrastructure\Database\ConnectionProvider([]);
    [$tickets,$notifications]=$c::buildServices($connection,'QA-A');
    $property=static fn(object $o,string $p):mixed=>(new ReflectionProperty($o,$p))->getValue($o);
    $assert($property($property($tickets,'tickets'),'connection')===$connection,'TICKET_CONNECTION');
    $assert($property($tickets,'notifications')===$notifications,'NOTIFICATION_WIRING');
    $configuration=$property($notifications,'configuration');
    $assert($property($property($configuration,'repository'),'connection')===$connection,'CONFIG_CONNECTION');
    $assert($property($property($property($notifications,'outbox'),'outbox'),'connection')===$connection,'OUTBOX_CONNECTION');
    $assert($property($connection,'pdo')===null,'NO_CONNECTION');
    $profile=new QaAtomicityRuleProfile();
    $sql="SELECT r.*, a.codigo AS mail_account_codigo, a.nombre AS mail_account_nombre FROM tickets_productos_correo_reglas r INNER JOIN mail_accounts a ON a.id = r.mail_account_id ORDER BY r.id";
    $rule=['enviar_solicitante'=>1,'enviar_responsables'=>1,'to_json'=>'["qa-recipient@example.invalid"]','cc_json'=>null,'bcc_json'=>null];
    $assert($profile->apply($sql,[$rule])===[$rule],'PROFILE_OFF');
    $profile->enabled=true;
    $memory=$profile->apply($sql,[$rule])[0];
    $assert($memory===['enviar_solicitante'=>0,'enviar_responsables'=>0,'to_json'=>null,'cc_json'=>null,'bcc_json'=>null],'PROFILE_FIELDS');
    $assert($profile->apply('SELECT * FROM tickets_productos',[$rule])===[$rule],'PROFILE_ISOLATION');
    $assert($rule['enviar_solicitante']===1,'PERSISTENT_RULE_COPY_UNCHANGED');
    $outbox=new \App\Domain\Tickets\ProductTicketEmailOutboxService($repo,new \App\Domain\Mail\ProductTicketEmailTemplateRenderer(),new \App\Domain\Mail\ProductTicketEmailTemplatePayloadBuilder('http://localhost','UTC',true));
    $input=['ticket_id'=>41,'evento'=>'TICKET_CREADO','creado_por_usuario_id'=>16];
    $skip=$outbox->enqueueConfigured($input,$memory);
    $assert($skip['notification_reason']==='no_valid_recipients' && !$skip['notification_enqueued'] && $skip['outbox']===null && $repo->inserts===0,'QA_G_REAL_SKIP');
    $first=$outbox->enqueueConfigured($input,$rule);
    $assert($first['notification_enqueued'] && $repo->inserts===1,'QA_H_FIRST');
    $second=$outbox->enqueueConfigured($input,$rule);
    $assert(!$second['notification_enqueued'] && $second['notification_reason']==='duplicate_dedupe_key' && $repo->inserts===1 && count($repo->rows)===1,'QA_H_SECOND');
    $assert(isset($repo->rows[$c::dedupe(41,null,'TICKET_CREADO')]),'REAL_DEDUPE');
    $assert($c::dedupe(41,2,'PARTIDA_RECHAZADA')==='ticket:41:partida:2:evento:PARTIDA_RECHAZADA','LINE_DEDUPE');
    foreach (['tickets_productos_correos','tickets_productos_eventos','tickets_productos_partidas','tickets_productos','documentos_folios'] as $table) {
        $q=tpAtomicityQaBuildExactDelete($table,'id',[7,11]);
        $assert($q['sql']==='DELETE FROM '.$table.' WHERE id IN (:id_0, :id_1)' && $q['params']===[':id_0'=>7,':id_1'=>11],'EXACT_DELETE');
    }
    foreach (['usuarios','empresas','almacenes','series_documentales','mail_accounts','tickets_productos_correo_reglas','permisos','roles'] as $table) {
        $reject(static fn()=>tpAtomicityQaBuildExactDelete($table,'id',[16]),'MASTER_PROTECTED');
    }
    $reject(static fn()=>tpAtomicityQaBuildExactDelete('tickets_productos','id',[]),'EMPTY_DELETE');
    $before=array_fill_keys($c::TABLES,0); $after=array_combine($c::TABLES,[1,1,2,1]);
    $c::assertDelta($before,$after,[1,1,2,1]);
    $reject(static fn()=>$c::assertDelta($before,$after,[0,0,0,0]),'NEGATIVE_DELTAS');
    $source=file_get_contents(dirname(__DIR__).'/qa_tickets_atomicity_runtime_matrix.php');
    $assert(!preg_match('/(?:->|::)(?:send|process|flush|worker)\s*\(/i',$source),'NO_SEND');
    $assert(!preg_match('/\b(?:INSERT\s+INTO|UPDATE)\s+(?:mail_accounts|tickets_productos_correo_reglas)\b/i',$source),'NO_CONFIG_WRITES');
    $assert(str_contains($source,'self::assertDelta($before,self::counts($pdo),[0,0,0,0])') && str_contains($source,'TICKET_ROLLBACK_FAILED') && str_contains($source,'PARTIDA_ROLLBACK_FAILED'),'ROLLBACK_ASSERTIONS');
    $assert(tpAtomicityQaRuntimePlan()['confirmations']===$c::CONFIRMATIONS,'PLAN_CONFIRMATIONS');
    return ['status'=>'PASS','checks'=>$checks,'mysql_connections'=>0,'write_sql_executed'=>false,'smtp'=>false,'outbox_processor'=>false];
}

if (PHP_SAPI==='cli' && realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    echo json_encode(qaAtomicityMatrixContractTest(),JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT).PHP_EOL;
}
