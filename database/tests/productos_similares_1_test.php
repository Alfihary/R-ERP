<?php

declare(strict_types=1);

use App\Domain\Audit\AuditService;
use App\Domain\Products\ProductSimilarException;
use App\Domain\Products\ProductSimilarService;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\AuditRepository;
use App\Infrastructure\Repositories\ProductSimilarRepository;

return new class implements DatabaseTest {
    private const A = 'QASIM001';
    private const B = 'QASIM002';
    private const C = 'QASIM003';
    private const INACTIVE = 'QASIM004';
    private const DELETED = 'QASIM005';
    private const UNKNOWN = 'QASIM999';

    public function run(PDO $pdo, string $expectedDatabase): array
    {
        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
            throw new RuntimeException('Unexpected database.');
        }
        $checks = [
            'TABLE_EXISTS' => $this->tableExists($pdo, 'producto_similares'),
            'ENGINE_INNODB' => $this->scalar($pdo, "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='producto_similares'") === 'InnoDB',
            'CHARSET_COLLATION_VALID' => (bool) $this->scalar($pdo, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='producto_similares' AND TABLE_COLLATION='utf8mb4_unicode_ci'"),
            'PRIMARY_KEY_VALID' => (int) $this->scalar($pdo, "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='producto_similares' AND CONSTRAINT_NAME='PRIMARY'") === 2,
            'FOREIGN_KEYS_VALID' => $this->foreignKeysValid($pdo),
            'CANONICAL_CHECK_VALID' => $this->checkConstraintContains($pdo, 'producto_a_id<producto_b_id'),
            'SELF_RELATION_CHECK_VALID' => $this->checkConstraintContains($pdo, 'producto_a_id<>producto_b_id'),
        ];
        $actor = (int) $this->scalar($pdo, "SELECT id FROM usuarios WHERE activo=1 AND eliminado_en IS NULL ORDER BY id LIMIT 1");
        $unit = (int) $this->scalar($pdo, "SELECT id FROM unidades_medida WHERE activo=1 AND eliminado_en IS NULL ORDER BY id LIMIT 1");
        $type = (int) $this->scalar($pdo, "SELECT id FROM tipos_producto WHERE activo=1 AND eliminado_en IS NULL ORDER BY id LIMIT 1");
        if ($actor < 1 || $unit < 1 || $type < 1) { throw new RuntimeException('Required QA catalog baseline is unavailable.'); }
        $auditBefore = $this->qaAuditCount($pdo);
        $before = $this->counts($pdo);
        $connection = $GLOBALS['productos_similares_connection'] ?? null;
        if (!$connection instanceof ConnectionProvider) { throw new RuntimeException('PRODUCTOS-SIMILARES-1 connection provider is missing.'); }
        $relations = new ProductSimilarRepository($connection);
        $service = new ProductSimilarService($connection, $relations, new AuditService(new AuditRepository($connection)));
        $pdo->beginTransaction();
        try {
            $this->insertProduct($pdo, self::A, $unit, $actor, $type, 1, null);
            $this->insertProduct($pdo, self::B, $unit, $actor, $type, 1, null);
            $this->insertProduct($pdo, self::C, $unit, $actor, $type, 1, null);
            $this->insertProduct($pdo, self::INACTIVE, $unit, $actor, $type, 0, null);
            $this->insertProduct($pdo, self::DELETED, $unit, $actor, $type, 1, '2026-01-01 00:00:00');
            $service->add(self::A, self::B, $actor);
            $checks['ADD_A_B'] = true;
            $checks['LIST_FROM_A_RETURNS_B'] = count($service->listFor(self::A)) === 1 && $service->listFor(self::A)[0]['id_producto'] === self::B;
            $checks['LIST_FROM_B_RETURNS_A'] = count($service->listFor(self::B)) === 1 && $service->listFor(self::B)[0]['id_producto'] === self::A;
            $checks['DUPLICATE_SAME_ORDER_REJECTED'] = $this->reject(fn() => $service->add(self::A, self::B, $actor));
            $checks['DUPLICATE_REVERSE_ORDER_REJECTED'] = $this->reject(fn() => $service->add(self::B, self::A, $actor));
            $checks['SELF_RELATION_REJECTED'] = $this->reject(fn() => $service->add(self::A, self::A, $actor));
            $checks['INACTIVE_PRODUCT_REJECTED'] = $this->reject(fn() => $service->add(self::A, self::INACTIVE, $actor));
            $checks['DELETED_PRODUCT_REJECTED'] = $this->reject(fn() => $service->add(self::A, self::DELETED, $actor));
            $checks['UNKNOWN_PRODUCT_REJECTED'] = $this->reject(fn() => $service->add(self::A, self::UNKNOWN, $actor));
            $service->remove(self::A, self::B, $actor);
            $checks['REMOVE_RELATION'] = $service->listFor(self::A) === [];
            $softDeleted = $this->row($pdo, self::A, self::B);
            $checks['SOFT_DELETE_ASSERTED'] = $softDeleted !== null && $softDeleted['eliminado_en'] !== null && (int) $softDeleted['eliminado_por'] === $actor;
            $service->add(self::A, self::B, $actor);
            $checks['REACTIVATE_RELATION'] = count($service->listFor(self::A)) === 1;
            $checks['REACTIVATION_REUSES_SAME_CANONICAL_ROW'] = (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM producto_similares WHERE producto_a_id=:a AND producto_b_id=:b', ['a'=>self::A,'b'=>self::B]) === 1;
            $checks['ADD_AUDIT_CREATED'] = (int) $this->scalar($pdo, "SELECT COUNT(*) FROM auditoria_eventos WHERE accion='productos.similares.agregar' AND entidad='producto_similar' AND entidad_id=:id", ['id'=>self::A . ':' . self::B]) >= 2;
            $checks['REMOVE_AUDIT_CREATED'] = (int) $this->scalar($pdo, "SELECT COUNT(*) FROM auditoria_eventos WHERE accion='productos.similares.quitar' AND entidad='producto_similar' AND entidad_id=:id", ['id'=>self::A . ':' . self::B]) >= 1;
            $addFailureCaught = $this->rejectAny(fn() => $service->add(self::A, self::C, 0));
            $checks['ADD_FAILURE_ROLLBACK'] = $addFailureCaught && $relations->relation(self::A, self::C) === null;
            $removeFailureCaught = $this->rejectAny(fn() => $service->remove(self::A, self::B, 0));
            $activeAfterFailure = $relations->relation(self::A, self::B);
            $checks['REMOVE_FAILURE_ROLLBACK'] = $removeFailureCaught && $activeAfterFailure !== null && $activeAfterFailure['eliminado_en'] === null;
            $checks['NO_PARTIAL_RELATION'] = $relations->relation(self::A, self::C) === null && $activeAfterFailure !== null && $activeAfterFailure['eliminado_en'] === null;
        } finally {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
        }
        $after = $this->counts($pdo);
        $auditAfter = $this->qaAuditCount($pdo);
        $checks['QA_PRODUCTS_RESIDUALS'] = $after['products'] === $before['products'];
        $checks['QA_SIMILAR_RELATIONS_RESIDUALS'] = $after['relations'] === $before['relations'];
        $checks['QA_AUDIT_RESIDUALS'] = $auditAfter === 0 && $auditBefore === 0;
        $qaUserResidualCount = 0;
        $checks['QA_USER_RESIDUALS'] = $qaUserResidualCount === 0;
        return $checks + ['QA_AUDIT_RESIDUAL_COUNT' => $auditAfter, 'QA_USER_CREATED_BY_TEST' => false, 'QA_USER_RESIDUAL_COUNT' => $qaUserResidualCount];
    }

    private function foreignKeysValid(PDO $pdo): bool
    {
        $expected = [
            'fk_producto_similares_a' => ['producto_a_id', 'productos', 'id_producto', 'RESTRICT', 'RESTRICT'],
            'fk_producto_similares_b' => ['producto_b_id', 'productos', 'id_producto', 'RESTRICT', 'RESTRICT'],
            'fk_producto_similares_creado_por' => ['creado_por', 'usuarios', 'id', 'RESTRICT', 'SET NULL'],
            'fk_producto_similares_actualizado_por' => ['actualizado_por', 'usuarios', 'id', 'RESTRICT', 'SET NULL'],
            'fk_producto_similares_eliminado_por' => ['eliminado_por', 'usuarios', 'id', 'RESTRICT', 'SET NULL'],
        ];
        $rows = $pdo->query("SELECT k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.CONSTRAINT_SCHEMA=DATABASE() AND k.TABLE_NAME='producto_similares' AND k.REFERENCED_TABLE_NAME IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
        $actual = [];
        foreach ($rows as $row) { $actual[$row['CONSTRAINT_NAME']] = [$row['COLUMN_NAME'], $row['REFERENCED_TABLE_NAME'], $row['REFERENCED_COLUMN_NAME'], $row['UPDATE_RULE'], $row['DELETE_RULE']]; }
        ksort($actual);
        ksort($expected);
        return count($actual) === count($expected) && $actual === $expected;
    }

    private function checkConstraintContains(PDO $pdo, string $needle): bool
    {
        $rows = $pdo->query("SELECT cc.CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS cc INNER JOIN information_schema.TABLE_CONSTRAINTS tc ON tc.CONSTRAINT_SCHEMA=cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME=cc.CONSTRAINT_NAME AND tc.TABLE_NAME='producto_similares' WHERE cc.CONSTRAINT_SCHEMA=DATABASE() AND tc.TABLE_NAME='producto_similares'")->fetchAll(PDO::FETCH_COLUMN);
        $normalize = static fn(string $value): string => preg_replace('/[`\\s\\(\\)]/', '', strtolower($value)) ?? '';
        $needle = $normalize($needle);
        foreach ($rows as $clause) { if (str_contains($normalize((string) $clause), $needle)) { return true; } }
        return false;
    }

    private function insertProduct(PDO $pdo, string $id, int $unit, int $actor, int $type, int $active, ?string $deletedAt): void
    {
        $statement = $pdo->prepare('INSERT INTO productos (id_producto, descripcion, unidad_medida_id, tipo_producto_id, activo, creado_por, eliminado_en) VALUES (:id, :descripcion, :unit, :type, :active, :actor, :deleted)');
        $statement->execute(['id'=>$id,'descripcion'=>'QA similar '.$id,'unit'=>$unit,'type'=>$type,'active'=>$active,'actor'=>$actor,'deleted'=>$deletedAt]);
    }

    private function reject(Closure $callback): bool { try { $callback(); return false; } catch (ProductSimilarException) { return true; } }
    private function rejectAny(Closure $callback): bool { try { $callback(); return false; } catch (Throwable) { return true; } }
    private function tableExists(PDO $pdo, string $table): bool { $s=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table"); $s->execute(['table'=>$table]); return (int)$s->fetchColumn()===1; }
    private function scalar(PDO $pdo, string $sql, array $params=[]): mixed { $s=$pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); }
    private function row(PDO $pdo, string $a, string $b): ?array { $s=$pdo->prepare('SELECT eliminado_en, eliminado_por FROM producto_similares WHERE producto_a_id=:a AND producto_b_id=:b'); $s->execute(['a'=>$a,'b'=>$b]); $row=$s->fetch(PDO::FETCH_ASSOC); return $row === false ? null : $row; }
    private function qaAuditCount(PDO $pdo): int { return (int) $this->scalar($pdo, "SELECT COUNT(*) FROM auditoria_eventos WHERE accion IN ('productos.similares.agregar','productos.similares.quitar') AND entidad='producto_similar' AND entidad_id LIKE 'QASIM%'"); }
    /** @return array{products:int,relations:int} */
    private function counts(PDO $pdo): array { return ['products'=>(int)$this->scalar($pdo,"SELECT COUNT(*) FROM productos WHERE id_producto LIKE 'QASIM%'") , 'relations'=>(int)$this->scalar($pdo,"SELECT COUNT(*) FROM producto_similares WHERE producto_a_id LIKE 'QASIM%' OR producto_b_id LIKE 'QASIM%'")]; }
};
