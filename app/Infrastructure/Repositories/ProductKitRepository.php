<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;
use PDO;

final class ProductKitRepository
{
    public function __construct(private readonly ConnectionProvider $connection) {}
    private function pdo(): PDO { return $this->connection->pdo(); }

    public function activeProduct(string $id): ?array
    {
        $s = $this->pdo()->prepare('SELECT p.id_producto,p.descripcion,p.sku,t.codigo AS tipo_codigo,p.activo,p.eliminado_en FROM productos p INNER JOIN tipos_producto t ON t.id=p.tipo_producto_id WHERE p.id_producto=? AND p.activo=1 AND p.eliminado_en IS NULL LIMIT 1');
        $s->execute([$id]); $row = $s->fetch(); return $row === false ? null : $row;
    }
    public function findProductTypeCode(string $productId): ?string
    { $row=$this->activeProduct($productId); return $row === null ? null : (string)$row['tipo_codigo']; }
    public function isKitProduct(string $productId): bool { return $this->findProductTypeCode($productId) === 'KIT'; }
    public function findActiveComponentsByKit(string $kitId): array
    {
        $s=$this->pdo()->prepare('SELECT r.id,r.kit_id_producto,r.componente_id_producto,r.cantidad,r.activo,p.descripcion,p.sku FROM productos_kit_componentes r INNER JOIN productos p ON p.id_producto=r.componente_id_producto WHERE r.kit_id_producto=? AND r.activo=1 AND r.eliminado_en IS NULL ORDER BY p.descripcion,p.id_producto');
        $s->execute([$kitId]); return $s->fetchAll();
    }
    public function findActiveRelation(string $kitId,string $componentId): ?array { return $this->findRelation($kitId,$componentId,true); }
    public function findRelationIncludingDeleted(string $kitId,string $componentId): ?array { return $this->findRelation($kitId,$componentId,false); }
    private function findRelation(string $kitId,string $componentId,bool $active): ?array
    {
        $sql='SELECT * FROM productos_kit_componentes WHERE kit_id_producto=? AND componente_id_producto=?';
        if($active){$sql.=' AND activo=1 AND eliminado_en IS NULL';} $sql.=' ORDER BY id DESC LIMIT 1';
        $s=$this->pdo()->prepare($sql); $s->execute([$kitId,$componentId]); $row=$s->fetch(); return $row===false?null:$row;
    }
    public function insert(string $kitId,string $componentId,string $quantity,int $actorId): int { $s=$this->pdo()->prepare('INSERT INTO productos_kit_componentes (kit_id_producto,componente_id_producto,cantidad,creado_por,actualizado_por) VALUES (?,?,?,?,?)'); $s->execute([$kitId,$componentId,$quantity,$actorId,$actorId]); return (int)$this->pdo()->lastInsertId(); }
    public function updateQuantity(int $id,string $quantity,int $actorId): void { $s=$this->pdo()->prepare('UPDATE productos_kit_componentes SET cantidad=?,actualizado_en=CURRENT_TIMESTAMP,actualizado_por=? WHERE id=? AND activo=1 AND eliminado_en IS NULL'); $s->execute([$quantity,$actorId,$id]); }
    public function softDelete(int $id,int $actorId): void { $s=$this->pdo()->prepare('UPDATE productos_kit_componentes SET activo=0,eliminado_en=CURRENT_TIMESTAMP,eliminado_por=?,actualizado_en=CURRENT_TIMESTAMP,actualizado_por=? WHERE id=? AND activo=1 AND eliminado_en IS NULL'); $s->execute([$actorId,$actorId,$id]); }
    public function reactivate(int $id,string $quantity,int $actorId): void { $s=$this->pdo()->prepare('UPDATE productos_kit_componentes SET cantidad=?,activo=1,eliminado_en=NULL,eliminado_por=NULL,actualizado_en=CURRENT_TIMESTAMP,actualizado_por=? WHERE id=?'); $s->execute([$quantity,$actorId,$id]); }
    public function countActiveComponents(string $kitId): int { $s=$this->pdo()->prepare('SELECT COUNT(*) FROM productos_kit_componentes WHERE kit_id_producto=? AND activo=1 AND eliminado_en IS NULL'); $s->execute([$kitId]); return (int)$s->fetchColumn(); }
    public function hasActiveComponents(string $kitId): bool { return $this->countActiveComponents($kitId)>0; }
    public function searchEligibleComponents(string $kitId,string $term,int $limit): array { $s=$this->pdo()->prepare('SELECT DISTINCT p.id_producto,p.descripcion,p.sku FROM productos p INNER JOIN tipos_producto t ON t.id=p.tipo_producto_id LEFT JOIN producto_codigos_barras b ON b.id_producto=p.id_producto AND b.activo=1 AND b.eliminado_en IS NULL WHERE p.activo=1 AND p.eliminado_en IS NULL AND t.codigo<>? AND p.id_producto<>? AND (p.id_producto LIKE ? OR p.descripcion LIKE ? OR p.sku LIKE ? OR b.codigo_barras LIKE ?) ORDER BY p.descripcion,p.id_producto LIMIT ?'); $like='%'.$term.'%'; $s->bindValue(1,'KIT');$s->bindValue(2,$kitId);foreach([3,4,5,6] as $i){$s->bindValue($i,$like);} $s->bindValue(7,max(1,min(20,$limit)),PDO::PARAM_INT);$s->execute();return $s->fetchAll(); }
}
