<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/qa_tickets_atomicity_actor_admin_assignment.php';

function qaTicketsActorAdminContractTest(): array
{
    $c=QaTicketsAtomicityActorAdminAssignment::class;
    $checks=0;
    $assert=static function(bool $ok) use (&$checks): void { if (!$ok) { throw new RuntimeException('CONTRACT_FAILED'); } ++$checks; };
    $reject=static function(callable $fn) use ($assert): void { try { $fn(); } catch (RuntimeException $e) { $assert(true); return; } $assert(false); };
    $connections=0;
    $connector=static function() use (&$connections): void { ++$connections; throw new RuntimeException('UNEXPECTED_CONNECTION'); };
    $plan=$c::run(['qa','--plan'],$connector);
    $assert($plan['mysql_connections']===0 && $plan['strategy']==='INSERT_IF_ABSENT');
    $env=['APP_ENV'=>'qa','APP_DB_NAME'=>'r_erp_tickets_qa_2f','APP_DB_USER'=>'r_erp_tickets_qa_runner','APP_DB_PASSWORD'=>'DB_FREE_PLACEHOLDER','APP_DB_HOST'=>'localhost','APP_DB_PORT'=>'3306'];
    $c::environment($env,false);
    $setupEnv=$env; $setupEnv['APP_DB_USER']='r_erp_tickets_qa_setup';
    $reject(static fn()=>$c::environment($setupEnv,false));
    $reject(static fn()=>$c::environment($setupEnv,true));
    foreach (['APP_ENV'=>'production','APP_DB_NAME'=>'r_erp_db_core_0_test','APP_DB_USER'=>'','APP_DB_PASSWORD'=>'','APP_DB_HOST'=>'remote.invalid','APP_DB_PORT'=>'3307'] as $key=>$bad) {
        $case=$env; $case[$key]=$bad; $reject(static fn()=>$c::environment($case,false));
    }
    $reject(static fn()=>$c::environment($env,true));
    foreach ($c::CONFIRMATIONS as $key=>$value) {
        $case=$env+$c::CONFIRMATIONS; unset($case[$key]); $reject(static fn()=>$c::environment($case,true));
    }
    $c::environment($env+$c::CONFIRMATIONS,true);
    $identity=['database'=>'r_erp_tickets_qa_2f','login'=>'r_erp_tickets_qa_runner@localhost','effective'=>'r_erp_tickets_qa_runner@localhost','hostname'=>'GER005OTU','port'=>3306];
    foreach (['database'=>'other','effective'=>'other@localhost'] as $key=>$bad) {
        $case=$identity; $case[$key]=$bad; $reject(static fn()=>QaTicketsAtomicityRbacRepair::assertIdentity($case));
    }
    $assert($c::validateRelation([])===false);
    $row=['usuario_id'=>16,'rol_id'=>1,'activo'=>1,'eliminado_en'=>null];
    $assert($c::validateRelation([$row])===true);
    $reject(static fn()=>$c::validateRelation([$row,$row]));
    foreach (['usuario_id'=>17,'rol_id'=>2,'activo'=>0,'eliminado_en'=>'2026-01-01'] as $key=>$bad) {
        $case=$row; $case[$key]=$bad; $reject(static fn()=>$c::validateRelation([$case]));
    }
    $reject(static fn()=>$c::run(['qa','--unknown'],$connector));
    $source=file_get_contents(dirname(__DIR__).'/qa_tickets_atomicity_actor_admin_assignment.php');
    $assert(!preg_match('/\b(?:UPDATE\s+\w+\s+SET|DELETE\s+FROM|REPLACE\s+INTO)\b/i',$source));
    $assert(substr_count($source,'INSERT INTO ')===1 && str_contains($source,'WHERE NOT EXISTS'));
    $assert($connections===0);
    return ['status'=>'PASS','checks'=>$checks,'mysql_connections'=>$connections,'write_sql_executed'=>false];
}
if (PHP_SAPI==='cli' && realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    echo json_encode(qaTicketsActorAdminContractTest(),JSON_THROW_ON_ERROR).PHP_EOL;
}
