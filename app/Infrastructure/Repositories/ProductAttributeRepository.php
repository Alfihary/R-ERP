<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;

final class ProductAttributeRepository
{
    public function __construct(private readonly ConnectionProvider $connection) { }
    public function pdo(): \PDO { return $this->connection->pdo(); }
    /** @return list<array<string,mixed>> */
    public function all(): array { return $this->pdo()->query('SELECT atributo_id,codigo,nombre,tipo,activo,eliminado_en FROM atributos_producto ORDER BY nombre,atributo_id')->fetchAll(\PDO::FETCH_ASSOC); }
    /** @return list<array<string,mixed>> */
    public function values(int $attributeId): array { $s=$this->pdo()->prepare('SELECT valor_id,atributo_id,codigo,nombre,activo,eliminado_en FROM valores_atributo_producto WHERE atributo_id=:id ORDER BY nombre,valor_id'); $s->execute(['id'=>$attributeId]); return $s->fetchAll(\PDO::FETCH_ASSOC); }
    public function attribute(int $id): ?array { $s=$this->pdo()->prepare('SELECT * FROM atributos_producto WHERE atributo_id=:id'); $s->execute(['id'=>$id]); $r=$s->fetch(\PDO::FETCH_ASSOC); return $r===false?null:$r; }
    public function value(int $id): ?array { $s=$this->pdo()->prepare('SELECT * FROM valores_atributo_producto WHERE valor_id=:id'); $s->execute(['id'=>$id]); $r=$s->fetch(\PDO::FETCH_ASSOC); return $r===false?null:$r; }
    public function insertAttribute(array $d): int { $this->pdo()->prepare('INSERT INTO atributos_producto (codigo,nombre,tipo,creado_por) VALUES (:codigo,:nombre,:tipo,:actor)')->execute($d); return (int)$this->pdo()->lastInsertId(); }
    public function insertValue(array $d): int { $this->pdo()->prepare('INSERT INTO valores_atributo_producto (atributo_id,codigo,nombre,creado_por) VALUES (:attribute,:codigo,:nombre,:actor)')->execute($d); return (int)$this->pdo()->lastInsertId(); }
    public function usageCount(int $attributeId): int { $s=$this->pdo()->prepare('SELECT (SELECT COUNT(*) FROM producto_familia_atributos WHERE atributo_id=:id)+(SELECT COUNT(*) FROM producto_variante_valores WHERE atributo_id=:id)'); $s->execute(['id'=>$attributeId]); return (int)$s->fetchColumn(); }
    public function valueUsageCount(int $valueId): int { $s=$this->pdo()->prepare('SELECT COUNT(*) FROM producto_variante_valores WHERE valor_atributo_id=:id'); $s->execute(['id'=>$valueId]); return (int)$s->fetchColumn(); }
}
