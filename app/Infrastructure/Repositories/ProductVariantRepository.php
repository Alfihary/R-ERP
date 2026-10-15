<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;

final class ProductVariantRepository
{
    public function __construct(private readonly ConnectionProvider $connection) { }
    public function pdo(): \PDO { return $this->connection->pdo(); }
    public function activeMembership(string $productId): ?array { $s=$this->pdo()->prepare('SELECT * FROM producto_familia_miembros WHERE id_producto=:id AND activo=1 AND eliminado_en IS NULL'); $s->execute(['id'=>$productId]); $r=$s->fetch(\PDO::FETCH_ASSOC); return $r===false?null:$r; }
    /** @return list<array<string,mixed>> */
    public function members(int $familyId): array { $s=$this->pdo()->prepare('SELECT m.*,p.descripcion,p.sku FROM producto_familia_miembros m INNER JOIN productos p ON p.id_producto=m.id_producto WHERE m.familia_id=:id AND m.activo=1 AND m.eliminado_en IS NULL ORDER BY m.es_predeterminada DESC,p.descripcion,p.id_producto'); $s->execute(['id'=>$familyId]); return $s->fetchAll(\PDO::FETCH_ASSOC); }
    public function member(int $familyId,string $productId): ?array { $s=$this->pdo()->prepare('SELECT * FROM producto_familia_miembros WHERE familia_id=:f AND id_producto=:p ORDER BY membresia_id DESC LIMIT 1'); $s->execute(['f'=>$familyId,'p'=>$productId]); $r=$s->fetch(\PDO::FETCH_ASSOC); return $r===false?null:$r; }
    public function insertMember(array $d): int { $s=$this->pdo()->prepare('INSERT INTO producto_familia_miembros (familia_id,id_producto,es_predeterminada,combination_hash,creado_por,actualizado_por) VALUES (:family,:product,:default,:hash,:created_by,:updated_by)'); $s->bindValue('family',$d['family'],\PDO::PARAM_INT); $s->bindValue('product',$d['product']); $s->bindValue('default',$d['default'],\PDO::PARAM_INT); $s->bindValue('hash',$d['hash'],\PDO::PARAM_LOB); $s->bindValue('created_by',$d['actor'],\PDO::PARAM_INT); $s->bindValue('updated_by',$d['actor'],\PDO::PARAM_INT); $s->execute(); return (int)$this->pdo()->lastInsertId(); }
    public function reactivate(int $membershipId,int $actor,bool $default,string $hash): void { $s=$this->pdo()->prepare('UPDATE producto_familia_miembros SET activo=1,eliminado_en=NULL,eliminado_por=NULL,es_predeterminada=:default,combination_hash=:hash,actualizado_por=:actor WHERE membresia_id=:id'); $s->bindValue('default',$default?1:0,\PDO::PARAM_INT); $s->bindValue('hash',$hash,\PDO::PARAM_LOB); $s->bindValue('actor',$actor,\PDO::PARAM_INT); $s->bindValue('id',$membershipId,\PDO::PARAM_INT); $s->execute(); }
    public function softDelete(int $membershipId,int $actor): void { $this->pdo()->prepare('UPDATE producto_familia_miembros SET activo=0,es_predeterminada=0,eliminado_en=CURRENT_TIMESTAMP,eliminado_por=:deleted_by,actualizado_por=:updated_by WHERE membresia_id=:id')->execute(['id'=>$membershipId,'deleted_by'=>$actor,'updated_by'=>$actor]); }
    public function replaceValues(string $productId,array $values,int $actor): void { $this->pdo()->prepare('DELETE FROM producto_variante_valores WHERE id_producto=:id')->execute(['id'=>$productId]); $s=$this->pdo()->prepare('INSERT INTO producto_variante_valores (id_producto,atributo_id,valor_atributo_id,creado_por,actualizado_por) VALUES (:product,:attribute,:value,:created_by,:updated_by)'); foreach($values as $value){$s->execute(['product'=>$productId,'attribute'=>$value['attribute'],'value'=>$value['value'],'created_by'=>$actor,'updated_by'=>$actor]);} }
    /** @return list<array<string,mixed>> */
    public function values(string $productId): array { $s=$this->pdo()->prepare('SELECT v.atributo_id,v.valor_atributo_id,a.codigo atributo_codigo,a.nombre atributo_nombre,x.codigo valor_codigo,x.nombre valor_nombre FROM producto_variante_valores v INNER JOIN atributos_producto a ON a.atributo_id=v.atributo_id INNER JOIN valores_atributo_producto x ON x.valor_id=v.valor_atributo_id WHERE v.id_producto=:id ORDER BY v.atributo_id'); $s->execute(['id'=>$productId]); return $s->fetchAll(\PDO::FETCH_ASSOC); }
    public function clearDefault(int $familyId,int $actor): void { $this->pdo()->prepare('UPDATE producto_familia_miembros SET es_predeterminada=0,actualizado_por=:actor WHERE familia_id=:family AND activo=1 AND eliminado_en IS NULL')->execute(['family'=>$familyId,'actor'=>$actor]); }
    public function setDefault(int $membershipId,int $actor): void { $this->pdo()->prepare('UPDATE producto_familia_miembros SET es_predeterminada=1,actualizado_por=:actor WHERE membresia_id=:id AND activo=1 AND eliminado_en IS NULL')->execute(['id'=>$membershipId,'actor'=>$actor]); }
}
