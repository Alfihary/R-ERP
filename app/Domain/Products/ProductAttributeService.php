<?php

declare(strict_types=1);

namespace App\Domain\Products;

use App\Domain\Audit\AuditService;
use App\Infrastructure\Repositories\ProductAttributeRepository;
use PDOException;

final class ProductAttributeService
{
    public function __construct(private readonly ProductAttributeRepository $attributes, private readonly AuditService $audit) { }
    public function all(): array { return $this->attributes->all(); }
    public function values(int $id): array { return $this->attributes->values($id); }
    public function create(array $input,int $actor): int { $code=$this->code($input['codigo']??'');$name=trim((string)($input['nombre']??''));if($code===''||$name===''){throw new ProductVariantException(['attribute'=>'Código y nombre son obligatorios.']);}try{$id=$this->attributes->insertAttribute(['codigo'=>$code,'nombre'=>$name,'tipo'=>'SELECT','actor'=>$actor]);$this->audit->recordRequired('productos.atributos.crear',$actor,['entidad'=>'atributo_producto','entidad_id'=>(string)$id]);return $id;}catch(PDOException $e){throw new ProductVariantException(['attribute'=>'El código de atributo ya existe.'],$e->getMessage());}}
    public function createValue(int $attribute,array $input,int $actor): int { if($this->attributes->attribute($attribute)===null){throw new ProductVariantException(['value'=>'El atributo no existe.']);}$code=$this->code($input['codigo']??'');$name=trim((string)($input['nombre']??''));if($code===''||$name===''){throw new ProductVariantException(['value'=>'Código y nombre son obligatorios.']);}try{$id=$this->attributes->insertValue(['attribute'=>$attribute,'codigo'=>$code,'nombre'=>$name,'actor'=>$actor]);$this->audit->recordRequired('productos.atributos.valor.crear',$actor,['entidad'=>'valor_atributo_producto','entidad_id'=>(string)$id]);return $id;}catch(PDOException $e){throw new ProductVariantException(['value'=>'El código de valor ya existe para ese atributo.'],$e->getMessage());}}
    private function code(mixed $v): string { $v=strtoupper(trim((string)$v));return preg_match('/^[A-Z0-9._\/-]{1,40}$/',$v)===1?$v:''; }
}
