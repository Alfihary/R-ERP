<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/qa_tickets_atomicity_primary_mail_account_repair.php';

function qaTicketsPrimaryMailContractTest(): array
{
    $c=QaTicketsAtomicityPrimaryMailAccountRepair::class;
    $checks=0; $connections=0;
    $assert=static function(bool $ok) use (&$checks):void { if (!$ok) { throw new RuntimeException('CONTRACT_FAILED'); } ++$checks; };
    $reject=static function(callable $fn) use ($assert):void { try { $fn(); } catch (RuntimeException $e) { $assert(true); return; } $assert(false); };
    $connector=static function() use (&$connections):void { ++$connections; throw new RuntimeException('UNEXPECTED_CONNECTION'); };
    $assert($c::run(['qa','--plan'],$connector)['mysql_connections']===0);
    $env=['APP_ENV'=>'qa','APP_DB_NAME'=>'r_erp_tickets_qa_2f','APP_DB_USER'=>'r_erp_tickets_qa_runner','APP_DB_PASSWORD'=>'DB_FREE_PLACEHOLDER','APP_DB_HOST'=>'localhost','APP_DB_PORT'=>'3306'];
    $c::environment($env,false); $setupEnv=$env; $setupEnv['APP_DB_USER']='r_erp_tickets_qa_setup';
    $reject(static fn()=>$c::environment($setupEnv,false));
    $reject(static fn()=>$c::environment($setupEnv,true));
    $c::environment($env+$c::CONFIRMATIONS,true);
    foreach (['APP_ENV'=>'production','APP_DB_NAME'=>'other','APP_DB_USER'=>'','APP_DB_PASSWORD'=>'','APP_DB_HOST'=>'remote.invalid','APP_DB_PORT'=>'3307'] as $key=>$bad) {
        $case=$env; $case[$key]=$bad; $reject(static fn()=>$c::environment($case,false));
    }
    foreach ($c::CONFIRMATIONS as $key=>$value) {
        $case=$env+$c::CONFIRMATIONS; unset($case[$key]); $reject(static fn()=>$c::environment($case,true));
    }
    $a=['id'=>1,'codigo'=>$c::SOURCE,'nombre'=>'QA Atomicity Mail','from_email'=>'qa.atomicity.tickets@example.invalid','from_name'=>'QA Atomicity','reply_to_email'=>'qa.atomicity.tickets@example.invalid','smtp_host'=>'invalid','smtp_port'=>2525,'smtp_encryption'=>'none','smtp_username'=>'qa.atomicity.tickets@example.invalid','smtp_secret_ref'=>'QA_ATOMICITY_REF','activo'=>1,'updated_at'=>null];
    $rules=[]; foreach ($c::EVENTS as $i=>$event) { $rules[]=['id'=>$i+1,'evento'=>$event,'mail_account_id'=>1,'activo'=>1,'enviar_solicitante'=>0,'enviar_responsables'=>0,'to_json'=>'["qa-recipient@example.invalid"]','cc_json'=>null,'bcc_json'=>null]; }
    $before=$c::classify([$a],$rules); $assert($before['state']==='READY_FOR_REPAIR');
    $target=$a; $target['codigo']=$c::TARGET;
    $after=$c::classify([$target],$rules); $assert($after['state']==='ALREADY_REPAIRED');
    $assert($before['account_fingerprint']===$after['account_fingerprint']);
    $after+=['RUNTIME_PRIMARY_ACCOUNT_FOUND'=>true,'RUNTIME_PRIMARY_ACCOUNT_ID'=>1];
    $c::assertPostcheck($before,$after);
    $reject(static fn()=>$c::classify([$a,$target],$rules));
    $reject(static fn()=>$c::classify([],$rules));
    foreach (['id'=>2,'smtp_host'=>'smtp.example.com','smtp_secret_ref'=>'REAL_REF','activo'=>0,'smtp_port'=>587,'from_email'=>'other@example.invalid'] as $key=>$bad) {
        $case=$target; $case[$key]=$bad; $reject(static fn()=>$c::classify([$case],$rules));
    }
    $bad=$rules; $bad[0]['mail_account_id']=2; $reject(static fn()=>$c::classify([$a],$bad));
    $reject(static fn()=>$c::classify([$a],array_slice($rules,1)));
    $bad=$rules; $bad[]=$rules[0]; $reject(static fn()=>$c::classify([$a],$bad));
    foreach (['account_fingerprint','rules_fingerprint'] as $key) {
        $case=$after; $case[$key]='changed'; $reject(static fn()=>$c::assertPostcheck($before,$case));
    }
    $case=$after; $case['RUNTIME_PRIMARY_ACCOUNT_ID']=2; $reject(static fn()=>$c::assertPostcheck($before,$case));
    $c::assertAffectedRows(1);
    foreach ([0,2] as $rows) { $reject(static fn()=>$c::assertAffectedRows($rows)); }
    $reject(static fn()=>$c::run(['qa','--unknown'],$connector));
    $assert(str_contains($c::UPDATE_SQL,'WHERE id = :id AND codigo = :source AND activo = 1'));
    $assert(str_contains($c::UPDATE_SQL,'updated_at = updated_at'));
    $source=file_get_contents(dirname(__DIR__).'/qa_tickets_atomicity_primary_mail_account_repair.php');
    $assert(!preg_match('/\b(?:INSERT\s+INTO|DELETE\s+FROM|REPLACE\s+INTO)\b/i',$source));
    $assert(substr_count($source,'UPDATE mail_accounts SET')===1);
    $production=file_get_contents(dirname(__DIR__,2).'/app/Infrastructure/Repositories/MailConfigurationRepository.php');
    $assert(str_contains($production,'SELECT * FROM mail_accounts WHERE codigo = :codigo LIMIT 1') && str_contains($production,"['codigo' => 'TICKETS_PRODUCTOS']"));
    $assert($connections===0);
    return ['status'=>'PASS','checks'=>$checks,'mysql_connections'=>0,'write_sql_executed'=>false];
}
if (PHP_SAPI==='cli' && realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    echo json_encode(qaTicketsPrimaryMailContractTest(),JSON_THROW_ON_ERROR).PHP_EOL;
}
