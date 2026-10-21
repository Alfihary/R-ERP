<?php

declare(strict_types=1);

namespace App\Domain\Products;

use App\Domain\Audit\AuditRecorderInterface;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Repositories\ProductKitRepository;
use PDOException;

final class ProductKitService
{
    public function __construct(private readonly ConnectionProvider $connection, private readonly ProductKitRepository $relations, private readonly AuditRecorderInterface $audit) {}
    public function listComponents(string $kitId): array { $this->assertValidKit($kitId); return $this->relations->findActiveComponentsByKit($kitId); }
    public function searchEligibleComponents(string $kitId,string $term,int $limit=10): array { $this->assertValidKit($kitId);$term=trim($term);return $term===''?[]:$this->relations->searchEligibleComponents($kitId,mb_substr($term,0,80),$limit); }
    public function addComponent(string $kitId,string $componentId,string $quantity,int $actorId): void
    { $this->mutate($kitId,$componentId,$quantity,$actorId,'add'); }
    public function updateQuantity(string $kitId,string $componentId,string $quantity,int $actorId): void
    { $this->mutate($kitId,$componentId,$quantity,$actorId,'update'); }
    public function removeComponent(string $kitId,string $componentId,int $actorId): void
    { $this->assertValidKit($kitId);$this->assertValidComponent($kitId,$componentId);$pdo=$this->connection->pdo();$owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();try{$r=$this->relations->findActiveRelation($kitId,$componentId);if($r===null)throw new ProductKitException(['component'=>'La relación no existe.']);$this->relations->softDelete((int)$r['id'],$actorId);$this->audit->recordRequired('productos.kits.componentes.quitar',$actorId,['entidad'=>'producto_kit_componente','entidad_id'=>(string)$r['id'],'version_contrato'=>1,'modulo'=>'productos','producto_id'=>$kitId,'subrecurso'=>'kit_componente','kit_id_producto'=>$kitId,'componente_id_producto'=>$componentId,'cantidad_anterior'=>(string)$r['cantidad'],'cantidad_nueva'=>null,'cantidad_before'=>(string)$r['cantidad'],'cantidad_after'=>null]);if($owns)$pdo->commit();}catch(\Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}}
    public function reactivateComponent(string $kitId,string $componentId,string $quantity,int $actorId): void { $this->mutate($kitId,$componentId,$quantity,$actorId,'reactivate'); }
    public function assertTypeChangeAllowed(string $productId,string $newType): void { if($this->relations->isKitProduct($productId)&&strtoupper($newType)!=='KIT'&&$this->relations->hasActiveComponents($productId))throw new ProductKitException(['tipo_producto'=>'No puedes cambiar un KIT que tiene componentes activos.']); }
    private function mutate(string $kitId,string $componentId,string $quantity,int $actorId,string $mode): void
    { $this->assertValidKit($kitId);$this->assertValidComponent($kitId,$componentId);$quantity=$this->normalizeQuantity($quantity);$pdo=$this->connection->pdo();$owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();try{$active=$this->relations->findActiveRelation($kitId,$componentId);$deleted=$active===null?$this->relations->findRelationIncludingDeleted($kitId,$componentId):null;if($mode==='update'&&$active===null)throw new ProductKitException(['component'=>'La relación no existe.']);if($mode==='reactivate'&&$active!==null)throw new ProductKitException(['component'=>'La relación ya está activa.']);$event='productos.kits.componentes.agregar';$old=null;if($active!==null){$old=(string)$active['cantidad'];$this->relations->updateQuantity((int)$active['id'],$quantity,$actorId);$event='productos.kits.componentes.actualizar';}elseif($deleted!==null){$old=(string)$deleted['cantidad'];$this->relations->reactivate((int)$deleted['id'],$quantity,$actorId);$event='productos.kits.componentes.reactivar';}else{$this->relations->insert($kitId,$componentId,$quantity,$actorId);}$this->audit->recordRequired($event,$actorId,['entidad'=>'producto_kit_componente','entidad_id'=>$kitId.':'.$componentId,'version_contrato'=>1,'modulo'=>'productos','producto_id'=>$kitId,'subrecurso'=>'kit_componente','kit_id_producto'=>$kitId,'componente_id_producto'=>$componentId,'cantidad_anterior'=>$old,'cantidad_nueva'=>$quantity,'cantidad_before'=>$old,'cantidad_after'=>$quantity]);if($owns)$pdo->commit();}catch(ProductKitException $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}catch(PDOException $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw new ProductKitException(['component'=>'No fue posible guardar el componente.'],$e->getMessage());}catch(\Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}}
    private function assertValidKit(string $id): void { $id=$this->normalizeId($id);$p=$this->relations->activeProduct($id);if($p===null||$p['tipo_codigo']!=='KIT')throw new ProductKitException(['kit_id_producto'=>'El producto debe ser un KIT activo.']); }
    private function assertValidComponent(string $kit,string $id): void { $id=$this->normalizeId($id);if($id===$kit)throw new ProductKitException(['component'=>'Un KIT no puede contenerse a sí mismo.']);$p=$this->relations->activeProduct($id);if($p===null||$p['tipo_codigo']==='KIT')throw new ProductKitException(['component'=>'El componente debe ser un producto activo que no sea KIT.']); }
    private function normalizeId(string $id): string { $id=strtoupper(trim($id));if(preg_match('/^[A-Z0-9]{1,16}$/',$id)!==1)throw new ProductKitException(['product'=>'Identificador de producto inválido.']);return $id; }
    private function normalizeQuantity(string $value): string { $value=trim($value);if(preg_match('/^[0-9]{1,12}(?:\.[0-9]{1,6})?$/',$value)!==1)throw new ProductKitException(['quantity'=>'La cantidad debe ser decimal positiva, con hasta 6 decimales.']);$canonical=strpos($value,'.')===false?$value:rtrim(rtrim($value,'0'),'.');if($canonical==='0'||preg_match('/^0(?:\.0*)?$/',$value)===1)throw new ProductKitException(['quantity'=>'La cantidad debe ser mayor que cero.']);return $canonical; }
}
