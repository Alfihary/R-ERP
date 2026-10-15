<?php

declare(strict_types=1);

namespace App\Domain\Products;

use App\Domain\Audit\AuditService;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Repositories\ProductFamilyRepository;
use PDOException;

final class ProductFamilyService
{
    public function __construct(private readonly ConnectionProvider $connection, private readonly ProductFamilyRepository $families, private readonly AuditService $audit) { }
    public function all(): array { return $this->families->all(); }
    public function find(int $id): ?array { return $this->families->find($id); }
    public function create(array $input,int $actor): int { $code=$this->code($input['codigo']??''); $name=trim((string)($input['nombre']??'')); if($code===''||$name===''){throw new ProductVariantException(['family'=>'Código y nombre de familia son obligatorios.']);} try{$id=$this->families->insert(['codigo'=>$code,'nombre'=>$name,'descripcion'=>$this->nullable($input['descripcion']??null),'actor'=>$actor]); $this->audit->recordRequired('productos.familias.crear',$actor,['entidad'=>'producto_familia','entidad_id'=>(string)$id]); return $id;}catch(PDOException $e){throw new ProductVariantException(['family'=>'El código de familia ya existe.'],$e->getMessage());} }
    public function update(int $id,array $input,int $actor): void { if($this->find($id)===null){throw new ProductVariantException(['family'=>'La familia no existe.']);} $code=$this->code($input['codigo']??'');$name=trim((string)($input['nombre']??''));if($code===''||$name===''){throw new ProductVariantException(['family'=>'Código y nombre de familia son obligatorios.']);} $this->families->update($id,['codigo'=>$code,'nombre'=>$name,'descripcion'=>$this->nullable($input['descripcion']??null),'actor'=>$actor]);$this->audit->recordRequired('productos.familias.actualizar',$actor,['entidad'=>'producto_familia','entidad_id'=>(string)$id]); }
    public function remove(int $id,int $actor): void { if($this->families->activeMemberCount($id)>0){throw new ProductVariantException(['family'=>'No puedes eliminar una familia con variantes activas.']);} $this->families->softDelete($id,$actor);$this->audit->recordRequired('productos.familias.eliminar',$actor,['entidad'=>'producto_familia','entidad_id'=>(string)$id]); }
    public function assignAttribute(int $family,int $attribute,bool $required,int $actor): void { if($this->find($family)===null){throw new ProductVariantException(['family'=>'La familia no existe.']);} $this->families->setAttribute($family,$attribute,$required,$actor); }
    public function attributes(int $family): array { return $this->families->allowedAttributes($family); }
    private function code(mixed $v): string { $v=strtoupper(trim((string)$v)); return preg_match('/^[A-Z0-9._\/-]{1,40}$/',$v)===1?$v:''; }
    private function nullable(mixed $v): ?string { $v=trim((string)$v);return $v===''?null:mb_substr($v,0,255); }
}
