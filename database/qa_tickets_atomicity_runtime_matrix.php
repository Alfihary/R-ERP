<?php
declare(strict_types=1);

// Register only the project namespace; do not load the web bootstrap or .env.
spl_autoload_register(static function(string $class): void {
    if (str_starts_with($class,'App\\')) {
        $path=dirname(__DIR__).'/app/'.str_replace('\\','/',substr($class,4)).'.php';
        if (is_file($path)) { require_once $path; }
    }
});

/** Mutable, connection-local profile; never persists configuration. */
final class QaAtomicityRuleProfile
{
    public bool $enabled=false;
    public int $applications=0;

    public function apply(string $query,array $rows): array
    {
        $normalized=preg_replace('/\s+/',' ',trim($query));
        if (!$this->enabled || !str_starts_with($normalized,'SELECT r.*, a.codigo AS mail_account_codigo, a.nombre AS mail_account_nombre FROM tickets_productos_correo_reglas r INNER JOIN mail_accounts a ON a.id = r.mail_account_id ')) { return $rows; }
        foreach ($rows as &$row) {
            $row=array_replace($row,['enviar_solicitante'=>0,'enviar_responsables'=>0,'to_json'=>null,'cc_json'=>null,'bcc_json'=>null]);
        }
        unset($row); ++$this->applications;
        return $rows;
    }
}

/** Alters only the fetched configuration envelope for QA-G on the real PDO. */
final class QaAtomicityProfileStatement extends PDOStatement
{
    protected function __construct(private readonly QaAtomicityRuleProfile $profile) {}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array
    {
        return $this->profile->apply($this->queryString,parent::fetchAll($mode,...$args));
    }
}

final class QaTicketsAtomicityRuntimeMatrix
{
    public const CONFIRMATIONS=[
        'TICKETS_QA_ATOMICITY_RUNTIME_CONFIRM'=>'RUN_QA_A_THROUGH_H_WITH_EXACT_ID_CLEANUP',
        'TICKETS_QA_ATOMICITY_DATABASE_CONFIRM'=>'r_erp_tickets_qa_2f',
    ];
    public const TABLES=['tickets_productos','tickets_productos_partidas','tickets_productos_eventos','tickets_productos_correos'];

    public static function demand(bool $ok,string $code): void
    {
        if (!$ok) { throw new RuntimeException($code); }
    }

    public static function environment(array $env): void
    {
        require_once __DIR__.'/qa_tickets_atomicity_rbac_repair.php';
        QaTicketsAtomicityRbacRepair::assertEnvironment($env,false);
        foreach (self::CONFIRMATIONS as $key=>$value) {
            self::demand(($env[$key]??null)===$value,'ATOMICITY_DOUBLE_CONFIRMATION_FAILED');
        }
    }

    public static function rows(PDO $pdo,string $sql,array $params=[]): array
    {
        $s=$pdo->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function dedupe(int $ticket,?int $part,string $event): string
    {
        return 'ticket:'.$ticket.':partida:'.($part===null?'null':$part).':evento:'.$event;
    }

    public static function counts(PDO $pdo): array
    {
        $counts=[];
        foreach (self::TABLES as $table) { $counts[$table]=(int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn(); }
        return $counts;
    }

    public static function assertDelta(array $before,array $after,array $expected): void
    {
        self::demand(array_keys($before)===self::TABLES && array_keys($after)===self::TABLES && count($expected)===4,'COUNT_SHAPE_INVALID');
        foreach (self::TABLES as $i=>$table) { self::demand($after[$table]-$before[$table]===$expected[$i],'COUNT_DELTA_FAILED'); }
    }

    private static function one(PDO $pdo,string $sql,array $params=[]): array
    {
        $rows=self::rows($pdo,$sql,$params); self::demand(count($rows)===1,'MASTER_NATURAL_KEY_CONFLICT'); return $rows[0];
    }

    private static function preflight(\App\Infrastructure\Database\ConnectionProvider $connection): array
    {
        $pdo=$connection->pdo();
        tpAtomicityQaAssertDatabaseIdentity(self::one($pdo,'SELECT DATABASE() AS `database`,USER() AS presented_user,CURRENT_USER() AS effective_user,@@hostname AS hostname,@@port AS port'));
        require_once __DIR__.'/qa_tickets_atomicity_pending_migrations.php';
        require_once __DIR__.'/qa_tickets_atomicity_actor_admin_assignment.php';
        require_once __DIR__.'/qa_tickets_atomicity_primary_mail_account_repair.php';
        $same=static fn(array $env):PDO=>$pdo;
        $schema=QaTicketsAtomicityPendingMigrations::run(['qa','--inspect'],$same);
        self::demand($schema['PENDING_EXPECTED']===[] && $schema['UNEXPECTED_PENDING']===[] && !$schema['schema_drift'],'SCHEMA_NOT_READY');
        foreach (array_merge(self::TABLES,['documentos_folios','series_documentales']) as $table) {
            $engine=self::one($pdo,'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$table]);
            self::demand($engine['ENGINE']==='InnoDB','NONTRANSACTIONAL_TABLE');
        }
        $rbac=QaTicketsAtomicityActorAdminAssignment::run(['qa','--inspect'],$same);
        self::demand($rbac['QA_ACTOR_ADMIN_ASSIGNED'] && $rbac['QA_ACTOR_ADMIN_RELATION_COUNT']===1,'RBAC_NOT_READY');
        $mail=QaTicketsAtomicityPrimaryMailAccountRepair::run(['qa','--inspect'],$same);
        self::demand($mail['state']==='ALREADY_REPAIRED' && $mail['RUNTIME_PRIMARY_ACCOUNT_ID']===1,'MAIL_NOT_READY');
        $actor=self::one($pdo,'SELECT * FROM usuarios WHERE email=? AND username=? AND activo=1 AND eliminado_en IS NULL',['qa.atomicity.tickets@example.invalid','qa.atomicity.tickets']);
        $company=self::one($pdo,'SELECT * FROM empresas WHERE codigo=? AND activo=1 AND eliminado_en IS NULL',['QA']);
        $warehouse=self::one($pdo,'SELECT * FROM almacenes WHERE empresa_id=? AND codigo=? AND activo=1 AND eliminado_en IS NULL',[$company['id'],'QA01']);
        $series=self::one($pdo,'SELECT * FROM series_documentales WHERE empresa_id=? AND almacen_id=? AND tipo_documento=? AND codigo_serie=? AND activo=1',[$company['id'],$warehouse['id'],'TICKET_PRODUCTO','TP']);
        self::demand((int)$actor['id']===16 && (int)$company['id']===35 && (int)$warehouse['id']===37 && (int)$series['id']===39,'MASTER_ID_INVARIANT_FAILED');
        self::demand($series['formato']==='{ALMACEN}-{NUMERO}' && (int)$series['siguiente_numero']>0 && (int)$series['siguiente_numero']<=999990,'SERIES_NOT_READY');
        self::one($pdo,'SELECT usuario_id FROM usuario_empresas WHERE usuario_id=? AND empresa_id=? AND activo=1 AND eliminado_en IS NULL',[$actor['id'],$company['id']]);
        self::one($pdo,'SELECT usuario_id FROM usuario_almacenes WHERE usuario_id=? AND empresa_id=? AND almacen_id=? AND activo=1 AND eliminado_en IS NULL',[$actor['id'],$company['id'],$warehouse['id']]);
        self::demand(array_sum(self::counts($pdo))===0,'NONEMPTY_OPERATIONAL_BASELINE');
        foreach (['tickets_productos_adjuntos','tickets_productos_comentarios'] as $table) {
            self::demand((int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===0,'NONEMPTY_ATTACHMENT_COMMENT_BASELINE');
        }
        return ['actor'=>(int)$actor['id'],'company'=>(int)$company['id'],'warehouse'=>(int)$warehouse['id'],'series'=>(int)$series['id'],
            'scope'=>\App\Domain\Scope\EffectiveScope::fromRecords(
                [['id'=>(int)$company['id'],'code'=>'QA','name'=>$company['nombre']]],
                [['id'=>(int)$warehouse['id'],'company_id'=>(int)$company['id'],'code'=>'QA01','name'=>$warehouse['nombre']]]),
            'mail_fingerprint'=>$mail['account_fingerprint'],'rules_fingerprint'=>$mail['rules_fingerprint'],
            'permissions_fingerprint'=>$rbac['permissions_fingerprint']];
    }

    /** Capture all persistent masters; allow only production folio advancement. */
    private static function masterFingerprint(PDO $pdo): string
    {
        $all=[];
        foreach (['usuarios','roles','permisos','usuario_roles','rol_permisos','empresas','almacenes','usuario_empresas','usuario_almacenes','mail_accounts','tickets_productos_correo_reglas','series_documentales'] as $table) {
            $rows=self::rows($pdo,'SELECT * FROM '.$table);
            if ($table==='series_documentales') {
                foreach ($rows as &$row) { unset($row['siguiente_numero'],$row['actualizado_en']); } unset($row);
            }
            usort($rows,static fn(array $a,array $b):int=>strcmp(serialize($a),serialize($b)));
            $all[$table]=$rows;
        }
        return hash('sha256',serialize($all));
    }

    /** IDs are selected only via this case's exact unpredictable marker. */
    private static function collect(PDO $pdo,string $run,array $masters): array
    {
        $owned=[];
        $tickets=self::rows($pdo,'SELECT id,folio FROM tickets_productos WHERE observaciones_generales=? AND solicitante_usuario_id=? AND empresa_id=? AND almacen_id=?',[$run,$masters['actor'],$masters['company'],$masters['warehouse']]);
        self::demand(count($tickets)<=1,'CASE_OWNERSHIP_CONFLICT');
        $owned['tickets_productos']=array_map('intval',array_column($tickets,'id'));
        foreach (['tickets_productos_correos'=>'ticket_id','tickets_productos_eventos'=>'ticket_producto_id','tickets_productos_partidas'=>'ticket_producto_id'] as $table=>$fk) {
            $owned[$table]=[];
            foreach ($tickets as $ticket) {
                $owned[$table]=array_merge($owned[$table],array_map('intval',array_column(self::rows($pdo,'SELECT id FROM '.$table.' WHERE '.$fk.'=?',[$ticket['id']]),'id')));
            }
        }
        $owned['documentos_folios']=[];
        foreach ($tickets as $ticket) {
            $rows=self::rows($pdo,'SELECT id FROM documentos_folios WHERE folio=? AND serie_documental_id=? AND creado_por_usuario_id=?',[$ticket['folio'],$masters['series'],$masters['actor']]);
            self::demand(count($rows)===1,'CASE_FOLIO_OWNERSHIP_FAILED');
            $owned['documentos_folios'][]=(int)$rows[0]['id'];
        }
        return $owned;
    }

    private static function cleanup(PDO $pdo,array $owned): void
    {
        self::demand(!$pdo->inTransaction(),'LEAKED_TRANSACTION');
        $pdo->beginTransaction();
        try {
            foreach (['tickets_productos_correos','tickets_productos_eventos','tickets_productos_partidas','tickets_productos','documentos_folios'] as $table) {
                $ids=$owned[$table]; if ($ids===[]) { continue; }
                $q=tpAtomicityQaBuildExactDelete($table,'id',$ids);
                $s=$pdo->prepare($q['sql']); $s->execute($q['params']);
                self::demand($s->rowCount()===count($ids),'CLEANUP_ROW_COUNT_FAILED');
                $remaining=self::rows($pdo,'SELECT id FROM '.$table.' WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids);
                self::demand($remaining===[],'CLEANUP_POSTCHECK_FAILED');
            }
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack(); } throw $e; }
    }

    public static function buildServices(\App\Infrastructure\Database\ConnectionProvider $connection,string $case): array
    {
        $real=new \App\Infrastructure\Repositories\ProductTicketEmailOutboxRepository($connection);
        $outbox=new \App\Domain\Tickets\ProductTicketEmailOutboxService(tpAtomicityQaRepositoryForCase($real,$case),new \App\Domain\Mail\ProductTicketEmailTemplateRenderer(),new \App\Domain\Mail\ProductTicketEmailTemplatePayloadBuilder('http://localhost','UTC',true));
        $notifications=new \App\Domain\Tickets\ProductTicketEmailNotificationService(new \App\Domain\Mail\MailConfigurationService(new \App\Infrastructure\Repositories\MailConfigurationRepository($connection)),$outbox);
        return [new \App\Domain\Tickets\ProductRequestTicketService(new \App\Infrastructure\Repositories\ProductRequestTicketRepository($connection),$notifications),$notifications];
    }

    private static function assertDedupe(PDO $pdo,int $ticket,?int $part,string $event): void
    {
        $row=self::one($pdo,'SELECT dedupe_key,status FROM tickets_productos_correos WHERE dedupe_key=?',[self::dedupe($ticket,$part,$event)]);
        self::demand($row['status']==='PENDIENTE','OUTBOX_STATUS_FAILED');
    }

    private static function executeCase(\App\Infrastructure\Database\ConnectionProvider $connection,array $m,string $case,string $run,QaAtomicityRuleProfile $profile): array
    {
        $pdo=$connection->pdo(); $initial=self::counts($pdo);
        [$service,$notifications]=self::buildServices($connection,$case);
        $input=['empresa_id'=>$m['company'],'almacen_id'=>$m['warehouse'],'observaciones_generales'=>$run,'partidas'=>[['descripcion'=>$run.' item']]];
        $ticket=null;
        if (in_array($case,['QA-C','QA-D','QA-E','QA-F'],true)) {
            [$base]=self::buildServices($connection,'QA-A');
            $ticket=$base->crearTicket($input,$m['actor'],null,$m['scope']);
            self::assertDelta($initial,self::counts($pdo),[1,1,2,1]);
        }
        $before=self::counts($pdo);
        $beforeState=$ticket===null?null:self::rows($pdo,'SELECT * FROM tickets_productos WHERE id=?',[$ticket['id']]);
        $beforeParts=$ticket===null?null:self::rows($pdo,'SELECT * FROM tickets_productos_partidas WHERE ticket_producto_id=? ORDER BY id',[$ticket['id']]);
        $failure=in_array($case,['QA-B','QA-D','QA-F'],true);
        $caught=false; $result=null; $rawReason=null;
        try {
            $profile->enabled=$case==='QA-G';
            if (in_array($case,['QA-A','QA-B','QA-G','QA-H'],true)) {
                $result=$service->crearTicket($input,$m['actor'],null,$m['scope']);
                $ticket=$result;
            } elseif (in_array($case,['QA-C','QA-D'],true)) {
                $result=$service->resolverPartida((int)$ticket['id'],(int)$ticket['partidas'][0]['id'],'RECHAZAR',['motivo_rechazo'=>'QA controlled rejection'],$m['actor'],null,$m['scope']);
            } else {
                $result=$service->cancelarTicket((int)$ticket['id'],'QA controlled cancellation',$m['actor'],null,$m['scope']);
            }
        } catch (RuntimeException $e) {
            self::demand($failure && get_class($e)===RuntimeException::class && $e->getMessage()==='QA_INJECTED_OUTBOX_FAILURE','UNEXPECTED_OPERATION_EXCEPTION');
            $caught=true;
        } finally { $profile->enabled=false; }
        self::demand($caught===$failure && !$pdo->inTransaction(),'FAILURE_OR_TRANSACTION_ASSERTION_FAILED');
        if ($failure) {
            self::assertDelta($before,self::counts($pdo),[0,0,0,0]);
            if ($ticket!==null) {
                self::demand($beforeState===self::rows($pdo,'SELECT * FROM tickets_productos WHERE id=?',[$ticket['id']]),'TICKET_ROLLBACK_FAILED');
                self::demand($beforeParts===self::rows($pdo,'SELECT * FROM tickets_productos_partidas WHERE ticket_producto_id=? ORDER BY id',[$ticket['id']]),'PARTIDA_ROLLBACK_FAILED');
            }
            return ['status'=>'PASS','expected_exception'=>'QA_INJECTED_OUTBOX_FAILURE','counts_before'=>$before,'counts_after'=>self::counts($pdo),'deltas'=>[0,0,0,0],'same_connection'=>true];
        }
        $id=(int)$ticket['id'];
        if ($case==='QA-G') {
            self::assertDelta($before,self::counts($pdo),[1,1,2,0]);
            $rawReason=$result['notification']['notification_reason'];
            self::demand($rawReason==='no_valid_recipients' && !$result['notification']['notification_enqueued'] && $result['notification']['outbox']===null && $profile->applications>0,'QA_G_SKIP_FAILED');
        } elseif ($case==='QA-C') {
            self::assertDelta($before,self::counts($pdo),[0,0,1,2]);
            $part=(int)$ticket['partidas'][0]['id'];
            self::demand(self::one($pdo,'SELECT estado FROM tickets_productos_partidas WHERE id=?',[$part])['estado']==='RECHAZADA','RESOLUTION_NOT_COMMITTED');
            self::one($pdo,'SELECT id FROM tickets_productos_eventos WHERE ticket_producto_id=? AND partida_id=? AND evento=?',[$id,$part,'PARTIDA_RECHAZADA']);
            self::assertDedupe($pdo,$id,$part,'PARTIDA_RECHAZADA');
            self::assertDedupe($pdo,$id,null,'TICKET_RESUELTO_TOTAL');
        } elseif ($case==='QA-E') {
            self::assertDelta($before,self::counts($pdo),[0,0,1,1]);
            self::demand(self::one($pdo,'SELECT estado FROM tickets_productos WHERE id=?',[$id])['estado']==='CANCELADO','CANCELLATION_NOT_COMMITTED');
            self::one($pdo,'SELECT id FROM tickets_productos_eventos WHERE ticket_producto_id=? AND evento=?',[$id,'TICKET_CANCELADO']);
            self::assertDedupe($pdo,$id,null,'TICKET_CANCELADO');
        } else {
            self::assertDelta($before,self::counts($pdo),[1,1,2,1]);
            self::assertDedupe($pdo,$id,null,'TICKET_CREADO');
            self::one($pdo,'SELECT id FROM tickets_productos_eventos WHERE ticket_producto_id=? AND evento=?',[$id,'TICKET_CREADO']);
            if ($case==='QA-H') {
                $first=self::counts($pdo);
                $duplicate=$notifications->handle('TICKET_CREADO',$id,null,$m['actor']);
                self::assertDelta($first,self::counts($pdo),[0,0,0,0]);
                $rawReason=$duplicate['notification_reason'];
                self::demand($rawReason==='duplicate_dedupe_key' && !$duplicate['notification_enqueued'],'QA_H_DEDUPE_FAILED');
                self::assertDedupe($pdo,$id,null,'TICKET_CREADO');
            }
        }
        if (in_array($case,['QA-A','QA-G','QA-H'],true)) {
            $events=self::rows($pdo,'SELECT evento FROM tickets_productos_eventos WHERE ticket_producto_id=? ORDER BY id',[$id]);
            self::demand(array_column($events,'evento')===['TICKET_CREADO','PARTIDA_AGREGADA'],'CREATION_EVENTS_FAILED');
        }
        self::demand(self::one($pdo,'SELECT estado FROM tickets_productos WHERE id=?',[$id])['estado']===($case==='QA-E'?'CANCELADO':($case==='QA-C'?'RECHAZADO':'EN_REVISION')),'BUSINESS_COMMIT_FAILED');
        return ['status'=>'PASS','notification_reason_raw'=>$rawReason,'skip_reason'=>$rawReason===null?null:strtoupper($rawReason),'business_committed'=>true,
            'counts_before'=>$before,'counts_after'=>self::counts($pdo),'same_connection'=>true,'ticket_id'=>$id];
    }

    public static function run(): array
    {
        $env=[];
        foreach (array_merge(['APP_ENV','APP_DB_NAME','APP_DB_HOST','APP_DB_PORT','APP_DB_USER','APP_DB_PASSWORD'],array_keys(self::CONFIRMATIONS)) as $key) { $env[$key]=getenv($key); }
        self::environment($env); // Before constructing or obtaining PDO.
        $connection=new \App\Infrastructure\Database\ConnectionProvider(['host'=>$env['APP_DB_HOST'],'port'=>(int)$env['APP_DB_PORT'],'name'=>$env['APP_DB_NAME'],'username'=>$env['APP_DB_USER'],'password'=>$env['APP_DB_PASSWORD'],'charset'=>'utf8mb4']);
        unset($env);
        $pdo=$connection->pdo();
        tpAtomicityQaAssertDatabaseIdentity(self::one($pdo,'SELECT DATABASE() AS `database`,USER() AS presented_user,CURRENT_USER() AS effective_user,@@hostname AS hostname,@@port AS port'));
        // Keep the entire QA process serialized, without wrapping business commits.
        self::demand((int)$pdo->query("SELECT GET_LOCK('r_erp_tickets_qa_2f_atomicity',0)")->fetchColumn()===1,'QA_RUN_LOCK_UNAVAILABLE');
        $profile=new QaAtomicityRuleProfile();
        $results=[];
        try {
            $masters=self::preflight($connection);
            $masterFingerprint=self::masterFingerprint($pdo);
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[QaAtomicityProfileStatement::class,[$profile]]);
            foreach (tpAtomicityQaCaseIds() as $case) {
                $run=tpAtomicityQaGenerateRunId().'-'.$case;
                $initial=self::counts($pdo); $owned=[]; $testError=null; $cleanupError=null;
                $folioBaseline=(int)$pdo->query('SELECT COUNT(*) FROM documentos_folios')->fetchColumn();
                try {
                    // Revalidate identity before every case, using the same PDO.
                    tpAtomicityQaAssertDatabaseIdentity(self::one($pdo,'SELECT DATABASE() AS `database`,USER() AS presented_user,CURRENT_USER() AS effective_user,@@hostname AS hostname,@@port AS port'));
                    $result=self::executeCase($connection,$masters,$case,$run,$profile);
                } catch (Throwable $e) { $testError='CASE_ASSERTION_OR_OPERATION_FAILED'; $result=['status'=>'FAIL']; }
                finally {
                    $profile->enabled=false;
                    try {
                        self::demand(!$pdo->inTransaction(),'LEAKED_TRANSACTION');
                        $owned=self::collect($pdo,$run,$masters);
                        self::cleanup($pdo,$owned);
                        self::assertDelta($initial,self::counts($pdo),[0,0,0,0]);
                        self::demand((int)$pdo->query('SELECT COUNT(*) FROM documentos_folios')->fetchColumn()===$folioBaseline,'FOLIO_CLEANUP_OR_ROLLBACK_FAILED');
                        self::demand(self::masterFingerprint($pdo)===$masterFingerprint,'MASTER_FIXTURES_CHANGED');
                    } catch (Throwable $e) { $cleanupError='EXACT_CLEANUP_OR_MASTER_POSTCHECK_FAILED'; }
                }
                $results[$case]=['QA_RUN_ID'=>$run,'test_error'=>$testError,'cleanup_error'=>$cleanupError,'owned_ids'=>$owned]+$result;
                if ($testError!==null || $cleanupError!==null) { return ['status'=>'FAIL','cases'=>$results,'smtp'=>false,'outbox_processor'=>false]; }
            }
            return ['status'=>'PASS','cases'=>$results,'same_connection'=>true,'smtp'=>false,'outbox_processor'=>false,'series_counter_restored'=>false];
        } finally {
            $profile->enabled=false;
            $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[PDOStatement::class]);
            $pdo->query("SELECT RELEASE_LOCK('r_erp_tickets_qa_2f_atomicity')");
        }
    }
}
