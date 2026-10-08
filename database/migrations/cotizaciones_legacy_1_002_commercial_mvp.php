<?php
declare(strict_types=1);
use App\Infrastructure\Database\Migration;
return new class implements Migration {
 public function id():string{return 'cotizaciones_legacy_1_002_commercial_mvp';}
 public function up(PDO $pdo):void{
  $db=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
  $has=function(string $t,string $c)use($pdo,$db):bool{$q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=:d AND table_name=:t AND column_name=:c');$q->execute(['d'=>$db,'t'=>$t,'c'=>$c]);return (int)$q->fetchColumn()>0;};
  $cols=['folio_comercial'=>"VARCHAR(64) NULL UNIQUE",'empresa_id'=>"BIGINT UNSIGNED NULL",'empresa_razon_social_snapshot'=>"VARCHAR(180) NULL",'empresa_rfc_snapshot'=>"VARCHAR(13) NULL",'empresa_direccion_snapshot'=>"TEXT NULL",'cliente_nombre_snapshot'=>"VARCHAR(160) NULL",'cliente_telefono_snapshot'=>"VARCHAR(64) NULL",'cliente_correo_snapshot'=>"VARCHAR(190) NULL",'moneda_id_snapshot'=>"BIGINT UNSIGNED NULL",'moneda_codigo_snapshot'=>"CHAR(3) NULL",'impuesto_id'=>"BIGINT UNSIGNED NULL",'impuesto_codigo_snapshot'=>"VARCHAR(20) NULL",'impuesto_tasa_snapshot'=>"DECIMAL(9,6) NULL",'vigencia_dias'=>"INT UNSIGNED NULL",'condiciones_pago'=>"VARCHAR(255) NULL",'observaciones'=>"TEXT NULL",'subtotal'=>"DECIMAL(18,6) NULL",'impuesto_importe'=>"DECIMAL(18,6) NULL",'total'=>"DECIMAL(18,6) NULL",'emitida_at'=>"DATETIME NULL"];foreach($cols as $c=>$def)if(!$has('cotizaciones',$c))$pdo->exec("ALTER TABLE cotizaciones ADD COLUMN `$c` $def");
  foreach(['codigo_snapshot'=>"VARCHAR(100) NULL",'unidad_snapshot'=>"VARCHAR(80) NULL",'precio_unitario_snapshot'=>"DECIMAL(18,6) NULL",'importe_snapshot'=>"DECIMAL(18,6) NULL"] as $c=>$def)if(!$has('cotizacion_partidas',$c))$pdo->exec("ALTER TABLE cotizacion_partidas ADD COLUMN `$c` $def");
  try{$pdo->exec('ALTER TABLE cotizaciones DROP CONSTRAINT chk_cotizaciones_estado');}catch(Throwable $e){} try{$pdo->exec("ALTER TABLE cotizaciones ADD CONSTRAINT chk_cotizaciones_estado CHECK (estado IN ('BORRADOR','PENDIENTE_AUTORIZACION','LISTA','EMITIDA','ENVIADA','ACEPTADA','RECHAZADA','VENCIDA','CANCELADA'))");}catch(Throwable $e){}
 }
 public function down(PDO $pdo):void{}
};
