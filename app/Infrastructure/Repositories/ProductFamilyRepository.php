<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;

final class ProductFamilyRepository
{
    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    public function pdo(): \PDO { return $this->connection->pdo(); }

    /** @return list<array<string,mixed>> */
    public function all(bool $includeInactive = false): array
    {
        $sql = 'SELECT familia_id, codigo, nombre, descripcion, activo, eliminado_en FROM producto_familias';
        if (!$includeInactive) { $sql .= ' WHERE activo=1 AND eliminado_en IS NULL'; }
        $sql .= ' ORDER BY nombre, familia_id';
        return $this->pdo()->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $s = $this->pdo()->prepare('SELECT familia_id, codigo, nombre, descripcion, activo, eliminado_en FROM producto_familias WHERE familia_id=:id');
        $s->execute(['id' => $id]);
        $row = $s->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function insert(array $data): int
    {
        $s = $this->pdo()->prepare('INSERT INTO producto_familias (codigo,nombre,descripcion,creado_por) VALUES (:codigo,:nombre,:descripcion,:actor)');
        $s->execute($data);
        return (int) $this->pdo()->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $data['id'] = $id;
        $this->pdo()->prepare('UPDATE producto_familias SET codigo=:codigo,nombre=:nombre,descripcion=:descripcion,actualizado_por=:actor WHERE familia_id=:id AND eliminado_en IS NULL')->execute($data);
    }

    public function activeMemberCount(int $id): int
    {
        $s = $this->pdo()->prepare('SELECT COUNT(*) FROM producto_familia_miembros WHERE familia_id=:id AND activo=1 AND eliminado_en IS NULL');
        $s->execute(['id' => $id]);
        return (int) $s->fetchColumn();
    }

    public function softDelete(int $id, int $actor): void
    {
        $this->pdo()->prepare('UPDATE producto_familias SET activo=0, eliminado_en=CURRENT_TIMESTAMP, eliminado_por=:actor WHERE familia_id=:id AND eliminado_en IS NULL')->execute(['id'=>$id,'actor'=>$actor]);
    }

    /** @return list<array<string,mixed>> */
    public function allowedAttributes(int $familyId): array
    {
        $s = $this->pdo()->prepare('SELECT a.atributo_id,a.codigo,a.nombre,a.tipo,fa.obligatorio FROM producto_familia_atributos fa INNER JOIN atributos_producto a ON a.atributo_id=fa.atributo_id WHERE fa.familia_id=:id AND a.activo=1 AND a.eliminado_en IS NULL ORDER BY a.nombre');
        $s->execute(['id'=>$familyId]);
        return $s->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function setAttribute(int $familyId, int $attributeId, bool $required, int $actor): void
    {
        $this->pdo()->prepare('INSERT INTO producto_familia_atributos (familia_id,atributo_id,obligatorio,creado_por,actualizado_por) VALUES (:f,:a,:r,:created_by,:updated_by) ON DUPLICATE KEY UPDATE obligatorio=VALUES(obligatorio),actualizado_por=VALUES(actualizado_por)')->execute(['f'=>$familyId,'a'=>$attributeId,'r'=>$required?1:0,'created_by'=>$actor,'updated_by'=>$actor]);
    }
}
