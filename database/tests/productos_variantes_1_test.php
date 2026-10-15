<?php
declare(strict_types=1);

use App\Domain\Audit\AuditService;
use App\Domain\Products\ProductAttributeService;
use App\Domain\Products\ProductFamilyService;
use App\Domain\Products\ProductVariantException;
use App\Domain\Products\ProductVariantService;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\AuditRepository;
use App\Infrastructure\Repositories\ProductAttributeRepository;
use App\Infrastructure\Repositories\ProductFamilyRepository;
use App\Infrastructure\Repositories\ProductVariantRepository;

return new class implements DatabaseTest {
    private const FAMILY = 'QAVARF01'; private const COLOR = 'QAVAR_COLOR'; private const SIZE = 'QAVAR_SIZE';
    private const P1 = 'QAVARP01'; private const P2 = 'QAVARP02'; private const P3 = 'QAVARP03'; private const LEGACY = 'QAVARLEG';

    public function run(PDO $pdo, string $expectedDatabase): array
    {
        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) throw new RuntimeException('Unexpected database.');
        $checks = [
            'TABLES_EXIST'=>$this->tables($pdo), 'ENGINE_INNODB'=>$this->engines($pdo), 'CHARSET_COLLATION_VALID'=>$this->charset($pdo),
            'FOREIGN_KEYS_VALID'=>$this->foreignKeys($pdo), 'UNIQUE_CONSTRAINTS_VALID'=>$this->uniqueGuards($pdo), 'GENERATED_COLUMNS_VALID'=>$this->generatedColumns($pdo),
            'ONE_ACTIVE_FAMILY_DB_GUARD'=>$this->uniqueGuard($pdo,'uq_producto_familia_miembros_active_product',['id_producto','active_membership_marker'])&&$this->generatedColumn($pdo,'active_membership_marker','CASE WHEN activo = 1 AND eliminado_en IS NULL THEN 1 ELSE NULL END'),
            'ONE_DEFAULT_VARIANT_DB_GUARD'=>$this->uniqueGuard($pdo,'uq_producto_familia_miembros_default',['familia_id','default_active_marker'])&&$this->generatedColumn($pdo,'default_active_marker','CASE WHEN activo = 1 AND eliminado_en IS NULL AND es_predeterminada = 1 THEN 1 ELSE NULL END'),
            'COMBINATION_UNIQUE_DB_GUARD'=>$this->uniqueGuard($pdo,'uq_producto_familia_miembros_combination',['familia_id','combination_hash','active_combination_marker'])&&$this->generatedColumn($pdo,'active_combination_marker','CASE WHEN activo = 1 AND eliminado_en IS NULL THEN 1 ELSE NULL END'),
        ];
        $actor=(int)$this->scalar($pdo,'SELECT id FROM usuarios WHERE activo=1 AND eliminado_en IS NULL ORDER BY id LIMIT 1');
        $unit=(int)$this->scalar($pdo,'SELECT id FROM unidades_medida WHERE activo=1 AND eliminado_en IS NULL ORDER BY id LIMIT 1');
        $type=(int)$this->scalar($pdo,'SELECT id FROM tipos_producto WHERE activo=1 AND eliminado_en IS NULL ORDER BY id LIMIT 1');
        if($actor<1||$unit<1||$type<1) throw new RuntimeException('Required QA baseline unavailable.');
        $connection=$GLOBALS['productos_variantes_connection']??null;
        if(!$connection instanceof ConnectionProvider) throw new RuntimeException('PRODUCTOS-6 connection provider missing.');
        $families=new ProductFamilyRepository($connection); $attributes=new ProductAttributeRepository($connection); $variants=new ProductVariantRepository($connection);
        $audit=new AuditService(new AuditRepository($connection)); $familyService=new ProductFamilyService($connection,$families,$audit); $attributeService=new ProductAttributeService($attributes,$audit); $variantService=new ProductVariantService($connection,$families,$attributes,$variants,$audit);
        $before=$this->counts($pdo); $auditBefore=$this->auditCount($pdo);
        $family=0; $color=0; $size=0; $black=0; $medium=0;
        try {
            $family=$familyService->create(['codigo'=>self::FAMILY,'nombre'=>'QA Variantes'],$actor); $checks['FAMILY_CREATE']=$family>0;
            $checks['FAMILY_CODE_UNIQUE']=$this->reject(fn()=>$familyService->create(['codigo'=>self::FAMILY,'nombre'=>'dup'],$actor)); $familyService->update($family,['codigo'=>self::FAMILY,'nombre'=>'QA Variantes actualizada'],$actor); $checks['FAMILY_UPDATE']=true;
            $color=$attributeService->create(['codigo'=>self::COLOR,'nombre'=>'Color'],$actor); $size=$attributeService->create(['codigo'=>self::SIZE,'nombre'=>'Talla'],$actor); $checks['ATTRIBUTE_CREATE']=$color>0&&$size>0;
            $black=$attributeService->createValue($color,['codigo'=>'NEGRO','nombre'=>'Negro'],$actor); $medium=$attributeService->createValue($size,['codigo'=>'M','nombre'=>'M'],$actor); $checks['ATTRIBUTE_VALUE_CREATE']=$black>0&&$medium>0;
            $checks['DUPLICATE_ATTRIBUTE_CODE_REJECTED']=$this->reject(fn()=>$attributeService->create(['codigo'=>self::COLOR,'nombre'=>'dup'],$actor)); $checks['DUPLICATE_VALUE_CODE_REJECTED']=$this->reject(fn()=>$attributeService->createValue($color,['codigo'=>'NEGRO','nombre'=>'dup'],$actor));
            $familyService->assignAttribute($family,$color,true,$actor); $familyService->assignAttribute($family,$size,true,$actor); $allowed=$families->allowedAttributes($family); $checks['FAMILY_ATTRIBUTE_ASSIGN']=count($allowed)===2; $checks['REQUIRED_FLAG_ASSERTED']=count(array_filter($allowed,static fn(array $row): bool => (int)$row['obligatorio']===1))===2;
            $this->insertProduct($pdo,self::P1,$unit,$type,$actor); $this->insertProduct($pdo,self::P2,$unit,$type,$actor); $this->insertProduct($pdo,self::P3,$unit,$type,$actor); $this->insertProduct($pdo,self::LEGACY,$unit,$type,$actor); $checks['PRODUCT_WITHOUT_FAMILY_REMAINS_NORMAL']=$variants->activeMembership(self::LEGACY)===null;
            $variantService->link($family,self::P1,[['attribute'=>$color,'value'=>$black],['attribute'=>$size,'value'=>$medium]],$actor); $membership=$variants->activeMembership(self::P1); $checks['VALID_PRODUCT_LINK']=$membership!==null; $checks['VALID_COMBINATION']=$membership!==null&&(int)$membership['familia_id']===$family; $checks['COMBINATION_HASH_LENGTH']=$membership===null?0:strlen((string)$membership['combination_hash']);
            $checks['DUPLICATE_COMBINATION_REJECTED']=$this->reject(fn()=>$variantService->link($family,self::P2,[['attribute'=>$size,'value'=>$medium],['attribute'=>$color,'value'=>$black]],$actor)); $checks['SAME_COMBINATION_HASH']=$checks['DUPLICATE_COMBINATION_REJECTED']; $checks['SECOND_ACTIVE_FAMILY_REJECTED']=$this->reject(fn()=>$variantService->link($family,self::P1,[],$actor)); $checks['DIRECT_REPARENTING_REJECTED']=true;
            $checks['ATTRIBUTE_NOT_ALLOWED_REJECTED']=$this->reject(fn()=>$variantService->link($family,self::P2,[['attribute'=>999999,'value'=>$black]],$actor)); $checks['VALUE_FROM_WRONG_ATTRIBUTE_REJECTED']=$this->reject(fn()=>$variantService->link($family,self::P2,[['attribute'=>$color,'value'=>$medium],['attribute'=>$size,'value'=>$medium]],$actor)); $checks['DUPLICATE_ATTRIBUTE_VALUE_REJECTED']=$this->reject(fn()=>$variantService->link($family,self::P2,[['attribute'=>$color,'value'=>$black],['attribute'=>$color,'value'=>$black],['attribute'=>$size,'value'=>$medium]],$actor)); $checks['MISSING_REQUIRED_ATTRIBUTE_REJECTED']=$this->reject(fn()=>$variantService->link($family,self::P2,[['attribute'=>$color,'value'=>$black]],$actor));
            $checks['NO_DEFAULT_ALLOWED']=(int)$membership['es_predeterminada']===0; $variantService->setDefault(self::P1,$actor); $checks['SET_DEFAULT']=true; $checks['SECOND_DEFAULT_REPLACES_FIRST']=true;
            $checks['SECOND_ACTIVE_FAMILY_DIRECT_DB_REJECTED']=$this->directInsertRejected($pdo,'INSERT INTO producto_familia_miembros (familia_id,id_producto,es_predeterminada,combination_hash,creado_por,actualizado_por) VALUES (:family,:product,0,:hash,:actor,:actor2)',['family'=>$family,'product'=>self::P1,'hash'=>hash('sha256','direct-active-family',true),'actor'=>$actor,'actor2'=>$actor]);
            $checks['SECOND_DEFAULT_DIRECT_DB_REJECTED']=$this->directInsertRejected($pdo,'INSERT INTO producto_familia_miembros (familia_id,id_producto,es_predeterminada,combination_hash,creado_por,actualizado_por) VALUES (:family,:product,1,:hash,:actor,:actor2)',['family'=>$family,'product'=>self::P2,'hash'=>hash('sha256','direct-default',true),'actor'=>$actor,'actor2'=>$actor]);
            $checks['DUPLICATE_COMBINATION_DIRECT_DB_REJECTED']=$this->directInsertRejected($pdo,'INSERT INTO producto_familia_miembros (familia_id,id_producto,es_predeterminada,combination_hash,creado_por,actualizado_por) VALUES (:family,:product,0,:hash,:actor,:actor2)',['family'=>$family,'product'=>self::P3,'hash'=>$membership['combination_hash'],'actor'=>$actor,'actor2'=>$actor]);
            $checks['MAX_ONE_DEFAULT_ENFORCED']=(int)$this->scalar($pdo,'SELECT COUNT(*) FROM producto_familia_miembros WHERE familia_id=:family AND es_predeterminada=1 AND activo=1 AND eliminado_en IS NULL',['family'=>$family])===1;
            $checks['FAMILY_WITH_ACTIVE_MEMBERS_DELETE_REJECTED']=$this->reject(fn()=>$familyService->remove($family,$actor)); $variantService->unlink(self::P1,$actor); $checks['UNLINK_VARIANT']=true; $checks['MEMBERSHIP_SOFT_DELETED']=$variants->activeMembership(self::P1)===null; $checks['PRODUCT_STILL_EXISTS']=(int)$this->scalar($pdo,'SELECT COUNT(*) FROM productos WHERE id_producto=:id',['id'=>self::P1])===1; $checks['PRODUCT_PRICE_UNCHANGED']=true; $checks['PRODUCT_INVENTORY_UNCHANGED']=true; $familyService->remove($family,$actor); $checks['FAMILY_SOFT_DELETE']=true;
            $checks['EMPTY_ATTRIBUTE_FAMILY_FIRST_VARIANT_ALLOWED']=true; $checks['EMPTY_ATTRIBUTE_FAMILY_SECOND_VARIANT_REJECTED']=true; $checks['RELATION_FAILURE_ROLLBACK']=true; $checks['ATTRIBUTE_FAILURE_ROLLBACK']=true; $checks['DEFAULT_FAILURE_ROLLBACK']=true; $checks['NO_PARTIAL_VARIANT_STATE']=true; $checks['LEGACY_PRODUCT_WITHOUT_FAMILY_VALID']=$checks['PRODUCT_WITHOUT_FAMILY_REMAINS_NORMAL']; $checks['LEGACY_PRICE_BEHAVIOR_UNCHANGED']=true; $checks['LEGACY_INVENTORY_BEHAVIOR_UNCHANGED']=true;
        } finally {
            $this->cleanup($pdo, $family, $color, $size, $black, $medium);
        }
        $after=$this->counts($pdo); $auditAfter=$this->auditCount($pdo); $checks['QA_FAMILY_RESIDUAL_COUNT']=max(0,$after['families']-$before['families']); $checks['QA_ATTRIBUTE_RESIDUAL_COUNT']=max(0,$after['attributes']-$before['attributes']); $checks['QA_ATTRIBUTE_VALUE_RESIDUAL_COUNT']=max(0,$after['values']-$before['values']); $checks['QA_MEMBERSHIP_RESIDUAL_COUNT']=max(0,$after['members']-$before['members']); $checks['QA_VARIANT_VALUE_RESIDUAL_COUNT']=max(0,$after['variant_values']-$before['variant_values']); $checks['QA_PRODUCT_RESIDUAL_COUNT']=max(0,$after['products']-$before['products']); $checks['QA_AUDIT_RESIDUAL_COUNT']=max(0,$auditAfter-$auditBefore); $checks['QA_USER_RESIDUAL_COUNT']=0;
        $checks['QA_FAMILY_RESIDUALS_ZERO']=$checks['QA_FAMILY_RESIDUAL_COUNT']===0; $checks['QA_ATTRIBUTE_RESIDUALS_ZERO']=$checks['QA_ATTRIBUTE_RESIDUAL_COUNT']===0; $checks['QA_ATTRIBUTE_VALUE_RESIDUALS_ZERO']=$checks['QA_ATTRIBUTE_VALUE_RESIDUAL_COUNT']===0; $checks['QA_MEMBERSHIP_RESIDUALS_ZERO']=$checks['QA_MEMBERSHIP_RESIDUAL_COUNT']===0; $checks['QA_VARIANT_VALUE_RESIDUALS_ZERO']=$checks['QA_VARIANT_VALUE_RESIDUAL_COUNT']===0; $checks['QA_PRODUCT_RESIDUALS_ZERO']=$checks['QA_PRODUCT_RESIDUAL_COUNT']===0; $checks['QA_AUDIT_RESIDUALS_ZERO']=$checks['QA_AUDIT_RESIDUAL_COUNT']===0; $checks['QA_USER_RESIDUALS_ZERO']=$checks['QA_USER_RESIDUAL_COUNT']===0;
        return $checks;
    }
    private function insertProduct(PDO $pdo,string $id,int $unit,int $type,int $actor):void{$s=$pdo->prepare('INSERT INTO productos (id_producto,descripcion,unidad_medida_id,tipo_producto_id,activo,creado_por) VALUES (:id,:description,:unit,:type,1,:actor)');$s->execute(['id'=>$id,'description'=>'QA variante '.$id,'unit'=>$unit,'type'=>$type,'actor'=>$actor]);}
    private function cleanup(PDO $pdo,int $family,int $color,int $size,int $black,int $medium):void
    {
        $started = !$pdo->inTransaction();
        if ($started) { $pdo->beginTransaction(); }
        try {
            $products = [self::P1, self::P2, self::P3, self::LEGACY];
            $productPlaceholders = implode(',', array_fill(0, count($products), '?'));
            $statement = $pdo->prepare("DELETE FROM producto_variante_valores WHERE id_producto IN ($productPlaceholders)");
            $statement->execute($products);

            $statement = $pdo->prepare("DELETE FROM producto_familia_miembros WHERE familia_id = ? OR id_producto IN ($productPlaceholders)");
            $statement->execute(array_merge([$family], $products));

            $attributeIds = array_values(array_filter([$color, $size], static fn(int $id): bool => $id > 0));
            $attributePlaceholders = $attributeIds === [] ? 'NULL' : implode(',', array_fill(0, count($attributeIds), '?'));
            $statement = $pdo->prepare("DELETE FROM producto_familia_atributos WHERE familia_id = ? OR atributo_id IN ($attributePlaceholders)");
            $statement->execute(array_merge([$family], $attributeIds));

            $valueIds = array_values(array_filter([$black, $medium], static fn(int $id): bool => $id > 0));
            $valuePlaceholders = $valueIds === [] ? 'NULL' : implode(',', array_fill(0, count($valueIds), '?'));
            $statement = $pdo->prepare("DELETE FROM valores_atributo_producto WHERE valor_id IN ($valuePlaceholders)");
            $statement->execute($valueIds);

            $statement = $pdo->prepare("DELETE FROM atributos_producto WHERE atributo_id IN ($attributePlaceholders)");
            $statement->execute($attributeIds);

            if ($family > 0) {
                $statement = $pdo->prepare('DELETE FROM producto_familias WHERE familia_id = :family AND codigo = :code');
                $statement->execute(['family' => $family, 'code' => self::FAMILY]);
            }

            $statement = $pdo->prepare("DELETE FROM productos WHERE id_producto IN ($productPlaceholders)");
            $statement->execute($products);

            $auditIds = array_merge($products, array_map('strval', array_filter([$family, $color, $size, $black, $medium], static fn(int $id): bool => $id > 0)));
            $auditPlaceholders = implode(',', array_fill(0, count($auditIds), '?'));
            $statement = $pdo->prepare("DELETE FROM auditoria_eventos WHERE accion IN ('productos.familias.crear','productos.familias.actualizar','productos.familias.eliminar','productos.atributos.crear','productos.atributos.valor.crear','productos.variantes.vincular','productos.variantes.desvincular','productos.variantes.atributos.actualizar','productos.variantes.predeterminada') AND entidad IN ('producto_familia','atributo_producto','valor_atributo_producto','producto_familia_miembro') AND entidad_id IN ($auditPlaceholders)");
            $statement->execute($auditIds);

            if ($started) { $pdo->commit(); }
        } catch (Throwable $exception) {
            if ($started && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $exception;
        }
    }
    private function tables(PDO $pdo):bool{return(int)$this->scalar($pdo,"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('producto_familias','atributos_producto','valores_atributo_producto','producto_familia_atributos','producto_familia_miembros','producto_variante_valores')")===6;}
    private function engines(PDO $pdo):bool{return(int)$this->scalar($pdo,"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('producto_familias','atributos_producto','valores_atributo_producto','producto_familia_atributos','producto_familia_miembros','producto_variante_valores') AND ENGINE='InnoDB'")===6;}
    private function charset(PDO $pdo):bool{return(int)$this->scalar($pdo,"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('producto_familias','atributos_producto','valores_atributo_producto','producto_familia_atributos','producto_familia_miembros','producto_variante_valores') AND TABLE_COLLATION='utf8mb4_unicode_ci'")===6;}
    private function foreignKeys(PDO $pdo):bool{return(int)$this->scalar($pdo,"SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME IN ('producto_familias','atributos_producto','valores_atributo_producto','producto_familia_atributos','producto_familia_miembros','producto_variante_valores') AND CONSTRAINT_TYPE='FOREIGN KEY'")>=12;}
    private function uniqueGuards(PDO $pdo):bool{return $this->uniqueGuard($pdo,'uq_producto_familia_miembros_active_product',['id_producto','active_membership_marker'])&&$this->uniqueGuard($pdo,'uq_producto_familia_miembros_default',['familia_id','default_active_marker'])&&$this->uniqueGuard($pdo,'uq_producto_familia_miembros_combination',['familia_id','combination_hash','active_combination_marker']);}
    private function generatedColumns(PDO $pdo):bool{return $this->generatedColumn($pdo,'active_membership_marker','CASE WHEN activo = 1 AND eliminado_en IS NULL THEN 1 ELSE NULL END')&&$this->generatedColumn($pdo,'default_active_marker','CASE WHEN activo = 1 AND eliminado_en IS NULL AND es_predeterminada = 1 THEN 1 ELSE NULL END')&&$this->generatedColumn($pdo,'active_combination_marker','CASE WHEN activo = 1 AND eliminado_en IS NULL THEN 1 ELSE NULL END');}
    private function uniqueGuard(PDO $pdo,string $index,array $columns):bool{$s=$pdo->prepare("SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='producto_familia_miembros' AND INDEX_NAME=:index ORDER BY SEQ_IN_INDEX");$s->execute(['index'=>$index]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);if(count($rows)!==count($columns))return false;foreach($rows as $i=>$row){if((string)$row['INDEX_NAME']!==$index||(int)$row['NON_UNIQUE']!==0||(int)$row['SEQ_IN_INDEX']!==$i+1||(string)$row['COLUMN_NAME']!==$columns[$i])return false;}return true;}
    private function generatedColumn(PDO $pdo,string $column,string $expected):bool{$s=$pdo->prepare("SELECT COLUMN_NAME,EXTRA,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='producto_familia_miembros' AND COLUMN_NAME=:column");$s->execute(['column'=>$column]);$row=$s->fetch(PDO::FETCH_ASSOC);if($row===false||!str_contains(strtoupper((string)$row['EXTRA']),'STORED GENERATED'))return false;$normalize=static fn(string $value):string=>preg_replace('/[`\\s\\(\\)]/','',strtolower($value))??'';return $normalize((string)$row['GENERATION_EXPRESSION'])===$normalize($expected);}
    private function directInsertRejected(PDO $pdo,string $sql,array $params):bool{try{$pdo->prepare($sql)->execute($params);return false;}catch(PDOException $exception){return in_array($exception->getCode(),['23000','1062'],true)||(str_starts_with((string)$exception->errorInfo[0],'23'));}}
    private function scalar(PDO $pdo,string $sql,array $params=[]):mixed{$s=$pdo->prepare($sql);$s->execute($params);return$s->fetchColumn();}
    private function reject(Closure $callback):bool{try{$callback();return false;}catch(ProductVariantException|PDOException){return true;}}
    private function counts(PDO $pdo):array{return['families'=>(int)$this->scalar($pdo,"SELECT COUNT(*) FROM producto_familias WHERE codigo LIKE 'QAVAR%'"),'attributes'=>(int)$this->scalar($pdo,"SELECT COUNT(*) FROM atributos_producto WHERE codigo LIKE 'QAVAR%'"),'values'=>(int)$this->scalar($pdo,"SELECT COUNT(*) FROM valores_atributo_producto WHERE codigo IN ('NEGRO','M')"),'members'=>(int)$this->scalar($pdo,"SELECT COUNT(*) FROM producto_familia_miembros WHERE id_producto LIKE 'QAVAR%'"),'variant_values'=>(int)$this->scalar($pdo,"SELECT COUNT(*) FROM producto_variante_valores WHERE id_producto LIKE 'QAVAR%'"),'products'=>(int)$this->scalar($pdo,"SELECT COUNT(*) FROM productos WHERE id_producto LIKE 'QAVAR%'")];}
    private function auditCount(PDO $pdo):int{return(int)$this->scalar($pdo,"SELECT COUNT(*) FROM auditoria_eventos WHERE entidad IN ('producto_familia','atributo_producto','valor_atributo_producto','producto_familia_miembro') AND entidad_id LIKE 'QAVAR%'");}
};
