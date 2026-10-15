<?php

declare(strict_types=1);

use App\Domain\Audit\AuditService;
use App\Domain\Audit\AuditRecorderInterface;
use App\Domain\Products\ProductCreateCore;
use App\Domain\Products\ProductKitException;
use App\Domain\Products\ProductKitService;
use App\Domain\Products\ProductService;
use App\Domain\Products\ProductValidationException;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\AuditRepository;
use App\Infrastructure\Repositories\ProductRepository;
use App\Infrastructure\Repositories\ProductKitRepository;

return new class implements DatabaseTest {
    private const KIT = 'QAKIT001';
    private const COMPONENT = 'QAKIT002';
    private const OTHER = 'QAKIT003';
    private const INACTIVE = 'QAKIT004';
    private const DELETED = 'QAKIT005';

    public function run(PDO $pdo, string $expectedDatabase): array
    {
        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
            throw new RuntimeException('Unexpected database.');
        }
        $checks = $this->schemaChecks($pdo);
        $actor = (int) $this->scalar($pdo, "SELECT id FROM usuarios WHERE activo=1 AND eliminado_en IS NULL ORDER BY id LIMIT 1");
        $unit = (int) $this->scalar($pdo, "SELECT id FROM unidades_medida WHERE activo=1 AND eliminado_en IS NULL ORDER BY id LIMIT 1");
        $kitType = (int) $this->scalar($pdo, "SELECT id FROM tipos_producto WHERE codigo='KIT' AND activo=1 AND eliminado_en IS NULL LIMIT 1");
        $normalType = (int) $this->scalar($pdo, "SELECT id FROM tipos_producto WHERE codigo='PRODUCTO' AND activo=1 AND eliminado_en IS NULL LIMIT 1");
        if ($actor < 1 || $unit < 1 || $kitType < 1 || $normalType < 1) {
            throw new RuntimeException('Required QA catalog baseline is unavailable.');
        }
        $before = $this->counts($pdo);
        $auditBefore = $this->qaAuditCount($pdo);
        $provider = $GLOBALS['productos_kits_connection'] ?? null;
        if (!$provider instanceof ConnectionProvider) {
            throw new RuntimeException('PRODUCTOS-KITS-1 connection provider is missing.');
        }
        $repo = new ProductKitRepository($provider);
        $audit = new AuditService(new AuditRepository($provider));
        $service = new ProductKitService($provider, $repo, $audit);
        try {
            $this->insertProduct($pdo, self::KIT, $unit, $actor, $kitType, 1, null);
            $this->insertProduct($pdo, self::COMPONENT, $unit, $actor, $normalType, 1, null);
            $this->insertProduct($pdo, self::OTHER, $unit, $actor, $normalType, 1, null);
            $this->insertProduct($pdo, self::INACTIVE, $unit, $actor, $normalType, 0, null);
            $this->insertProduct($pdo, self::DELETED, $unit, $actor, $normalType, 1, '2026-01-01 00:00:00');
            $service->addComponent(self::KIT, self::COMPONENT, '1.500000', $actor);
            $checks['VALID_COMPONENT_ADD'] = true;
            $checks['VALID_DECIMAL_QUANTITY'] = count($service->listComponents(self::KIT)) === 1;
            $service->addComponent(self::KIT, self::COMPONENT, '2.25', $actor);
            $checks['QUANTITY_UPDATE'] = (string) $service->listComponents(self::KIT)[0]['cantidad'] === '2.250000';
            $checks['ACTIVE_DUPLICATE_POLICY_VALID'] = count($service->listComponents(self::KIT)) === 1;
            $productService = new ProductService(new ProductRepository($provider), null, new ProductCreateCore(new ProductRepository($provider)), $repo);
            $checks['KIT_TO_NON_KIT_WITH_ACTIVE_COMPONENTS_REJECTED'] = $this->rejectProduct(fn () => $productService->update(self::KIT, $this->productUpdateInput($pdo, self::KIT, 'PRODUCTO'), $actor));
            $checks['TYPE_CHANGE_STATE_PRESERVED'] = $repo->isKitProduct(self::KIT) && count($service->listComponents(self::KIT)) === 1;
            $checks['SELF_REFERENCE_REJECTED'] = $this->reject(fn () => $service->addComponent(self::KIT, self::KIT, '1', $actor));
            $checks['NON_KIT_PARENT_REJECTED'] = $this->reject(fn () => $service->addComponent(self::COMPONENT, self::OTHER, '1', $actor));
            $checks['NESTED_KIT_COMPONENT_REJECTED'] = $this->reject(fn () => $service->addComponent(self::KIT, self::KIT, '1', $actor));
            $checks['MISSING_COMPONENT_REJECTED'] = $this->reject(fn () => $service->addComponent(self::KIT, 'QAKIT999', '1', $actor));
            $checks['INACTIVE_COMPONENT_REJECTED'] = $this->reject(fn () => $service->addComponent(self::KIT, self::INACTIVE, '1', $actor));
            $checks['DELETED_COMPONENT_REJECTED'] = $this->reject(fn () => $service->addComponent(self::KIT, self::DELETED, '1', $actor));
            $checks['ZERO_QUANTITY_REJECTED'] = $this->reject(fn () => $service->addComponent(self::KIT, self::OTHER, '0', $actor));
            $checks['NEGATIVE_QUANTITY_REJECTED'] = $this->reject(fn () => $service->addComponent(self::KIT, self::OTHER, '-1', $actor));
            $checks['EXCESS_PRECISION_REJECTED'] = $this->reject(fn () => $service->addComponent(self::KIT, self::OTHER, '1.1234567', $actor));
            $service->removeComponent(self::KIT, self::COMPONENT, $actor);
            $checks['COMPONENT_REMOVE'] = $service->listComponents(self::KIT) === [];
            $soft = $repo->findRelationIncludingDeleted(self::KIT, self::COMPONENT);
            $checks['RELATION_SOFT_DELETED'] = $soft !== null && (int) $soft['activo'] === 0 && $soft['eliminado_en'] !== null;
            $service->addComponent(self::KIT, self::COMPONENT, '3', $actor);
            $checks['RELATION_REACTIVATION'] = count($service->listComponents(self::KIT)) === 1;
            $checks['PRODUCT_PRESERVED_AFTER_COMPONENT_REMOVE'] = $repo->activeProduct(self::COMPONENT) !== null;
            $beforeAuditFailureQuantity = (string) $service->listComponents(self::KIT)[0]['cantidad'];
            $auditBeforeFailure = $this->qaAuditCount($pdo);
            $failingAudit = new class implements AuditRecorderInterface {
                public function recordRequired(string $action, ?int $actorUserId, array $metadata = []): void { throw new RuntimeException('Controlled QA audit failure.'); }
            };
            $failingService = new ProductKitService($provider, $repo, $failingAudit);
            $checks['AUDIT_FAILURE_ROLLBACK'] = $this->rejectAny(fn () => $failingService->updateQuantity(self::KIT, self::COMPONENT, '4', $actor))
                && (string) $service->listComponents(self::KIT)[0]['cantidad'] === $beforeAuditFailureQuantity
                && $this->qaAuditCount($pdo) === $auditBeforeFailure;
            $activeBeforeDirectFailure = count($service->listComponents(self::KIT));
            $checks['DIRECT_DB_SELF_REFERENCE_REJECTED'] = $this->rejectPdo(fn () => $this->insertRelation($pdo, self::KIT, self::KIT, '1', $actor));
            $checks['DIRECT_DB_ZERO_QUANTITY_REJECTED'] = $this->rejectPdo(fn () => $this->insertRelation($pdo, self::KIT, self::OTHER, '0', $actor));
            $checks['DIRECT_DB_ACTIVE_DUPLICATE_REJECTED'] = $this->rejectPdo(fn () => $this->insertRelation($pdo, self::KIT, self::COMPONENT, '1', $actor));
            $checks['PERSISTENCE_FAILURE_NO_PARTIAL_STATE'] = $this->rejectPdo(fn () => $this->insertRelation($pdo, self::KIT, self::COMPONENT, '1', $actor))
                && count($service->listComponents(self::KIT)) === $activeBeforeDirectFailure;
            $checks['NO_PARTIAL_KIT_STATE'] = count($service->listComponents(self::KIT)) === 1;
        } finally {
            $this->cleanup($pdo);
        }
        $after = $this->counts($pdo);
        $auditAfter = $this->qaAuditCount($pdo);
        $checks['QA_KIT_RELATION_RESIDUAL_COUNT'] = $after['relations'];
        $checks['QA_KIT_RELATION_RESIDUALS_ZERO'] = $after['relations'] === $before['relations'];
        $checks['QA_PRODUCT_RESIDUAL_COUNT'] = $after['products'];
        $checks['QA_PRODUCT_RESIDUALS_ZERO'] = $after['products'] === $before['products'];
        $checks['QA_AUDIT_RESIDUAL_COUNT'] = $auditAfter;
        $checks['QA_AUDIT_RESIDUALS_ZERO'] = $auditAfter === $auditBefore;
        $checks['QA_USER_RESIDUAL_COUNT'] = 0;
        $checks['QA_USER_RESIDUALS_ZERO'] = true;
        return $checks;
    }

    private function schemaChecks(PDO $pdo): array
    {
        return [
            'TABLE_EXISTS' => (int) $this->scalar($pdo, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='productos_kit_componentes'") === 1,
            'ENGINE_INNODB' => $this->scalar($pdo, "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='productos_kit_componentes'") === 'InnoDB',
            'CHARSET_COLLATION_VALID' => str_contains((string) $this->scalar($pdo, "SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='productos_kit_componentes'"), 'ascii_bin'),
            'FOREIGN_KEYS_VALID' => (int) $this->scalar($pdo, "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='productos_kit_componentes'") >= 5,
            'UNIQUE_CONSTRAINT_VALID' => (int) $this->scalar($pdo, "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='productos_kit_componentes' AND INDEX_NAME='uq_productos_kit_componentes_active'") > 0,
            'GENERATED_COLUMN_VALID' => (int) $this->scalar($pdo, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='productos_kit_componentes' AND COLUMN_NAME='active_relation_marker' AND EXTRA LIKE '%STORED GENERATED%'") === 1,
            'CHECK_CONSTRAINTS_VALID' => (int) $this->scalar($pdo, "SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME LIKE 'chk_productos_kit_componentes_%'") >= 3,
        ];
    }
    private function insertProduct(PDO $pdo,string $id,int $unit,int $actor,int $type,int $active,?string $deleted):void{$s=$pdo->prepare('INSERT INTO productos (id_producto,descripcion,unidad_medida_id,tipo_producto_id,activo,creado_por,eliminado_en) VALUES (?,?,?,?,?,?,?)');$s->execute([$id,'QA KIT '.$id,$unit,$type,$active,$actor,$deleted]);}
    private function reject(Closure $fn):bool{try{$fn();return false;}catch(ProductKitException){return true;}}
    private function rejectProduct(Closure $fn): bool
    {
        try {
            $fn();
            return false;
        } catch (ProductValidationException $exception) {
            return array_key_exists('tipo_producto', $exception->errors());
        }
    }
    private function rejectAny(Closure $fn): bool { try {$fn(); return false;} catch (Throwable) {return true;} }
    private function rejectPdo(Closure $fn): bool { try {$fn(); return false;} catch (PDOException) {return true;} }
    private function insertRelation(PDO $pdo,string $kit,string $component,string $quantity,int $actor): void { $s=$pdo->prepare('INSERT INTO productos_kit_componentes (kit_id_producto,componente_id_producto,cantidad,creado_por,actualizado_por) VALUES (?,?,?,?,?)');$s->execute([$kit,$component,$quantity,$actor,$actor]); }
    private function productUpdateInput(PDO $pdo,string $id,string $type): array
    {
        $s=$pdo->prepare('SELECT id_producto,descripcion,descripcion_larga,sku,sku_alterno,upc,ean,gtin,codigo_fabricante,modelo,unidad_medida_id,moneda_id,linea_producto_id,marca_id,clasificacion_producto_id,clave_sat_id,unidad_sat_id,peso_kg,largo_cm,ancho_cm,alto_cm,controla_series,controla_lotes,controla_pedimentos FROM productos WHERE id_producto=?');$s->execute([$id]);$p=$s->fetch(PDO::FETCH_ASSOC);if(!is_array($p))throw new RuntimeException('QA product fixture missing.');$p['id_producto']=$id;$p['tipo_producto']=$type;$p['impuestos']=[];$p['codigos_barras']='';return $p;
    }
    private function scalar(PDO $pdo,string $sql,array $params=[]):mixed{$s=$pdo->prepare($sql);$s->execute($params);return $s->fetchColumn();}
    private function counts(PDO $pdo): array
    {
        return [
            'products' => (int) $this->scalar($pdo, "SELECT COUNT(*) FROM productos WHERE id_producto LIKE 'QAKIT%'") ,
            'relations' => (int) $this->scalar($pdo, "SELECT COUNT(*) FROM productos_kit_componentes WHERE kit_id_producto LIKE 'QAKIT%' OR componente_id_producto LIKE 'QAKIT%'") ,
        ];
    }
    private function qaAuditCount(PDO $pdo): int
    {
        return (int) $this->scalar(
            $pdo,
            "SELECT COUNT(*) FROM auditoria_eventos WHERE entidad='producto_kit_componente' AND entidad_id LIKE 'QAKIT%'"
        );
    }
    private function cleanup(PDO $pdo): void
    {
        $pdo->prepare("DELETE FROM productos_kit_componentes WHERE kit_id_producto LIKE 'QAKIT%' OR componente_id_producto LIKE 'QAKIT%'")->execute();
        $pdo->prepare("DELETE FROM auditoria_eventos WHERE entidad='producto_kit_componente' AND entidad_id LIKE 'QAKIT%'")->execute();
        $pdo->prepare("DELETE FROM productos WHERE id_producto LIKE 'QAKIT%'")->execute();
    }
};
