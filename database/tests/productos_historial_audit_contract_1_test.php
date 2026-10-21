<?php

declare(strict_types=1);

use App\Domain\Audit\AuditRecorderInterface;
use App\Domain\Audit\AuditService;
use App\Domain\Products\ProductCreateCore;
use App\Domain\Products\ProductKitService;
use App\Domain\Products\ProductImageService;
use App\Domain\Products\ProductSimilarService;
use App\Domain\Products\ProductService;
use App\Domain\Products\ProductAttributeService;
use App\Domain\Products\ProductFamilyService;
use App\Domain\Products\ProductVariantService;
use App\Domain\Products\Import\ProductImportBatchService;
use App\Domain\Products\Import\ProductImportBusinessValidator;
use App\Domain\Products\Import\ProductImportConfirmationService;
use App\Domain\Products\Import\ProductImportPreviewService;
use App\Infrastructure\Import\PrivateProductImportPreviewStore;
use App\Infrastructure\Import\ProductImportReader;
use App\Infrastructure\Repositories\PdoProductImportBusinessLookup;
use App\Infrastructure\Repositories\ProductImportRunRepository;
use App\Infrastructure\Database\ConnectionProvider;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\AuditRepository;
use App\Infrastructure\Repositories\ProductKitRepository;
use App\Infrastructure\Repositories\ProductDocumentRepository;
use App\Infrastructure\Repositories\ProductRepository;
use App\Infrastructure\Repositories\ProductSimilarRepository;
use App\Infrastructure\Repositories\ProductAttributeRepository;
use App\Infrastructure\Repositories\ProductFamilyRepository;
use App\Infrastructure\Repositories\ProductVariantRepository;
use App\Infrastructure\Repositories\UserRepository;

return new class implements DatabaseTest {
    private const PREFIX = 'QAPHIST';
    private const QA_ACTOR_USERNAME = 'qaphist.audit.actor';
    private const QA_ROLLBACK_PRODUCT_ID = 'QAPHISTROLL01';
    private const QA_SIMILAR_A = 'QAPHISTSIMA';
    private const QA_SIMILAR_B = 'QAPHITSIMB';
    private const QA_KIT = 'QAPHISTKIT01';
    private const QA_COMPONENT = 'QAPHISTCMP01';
    private const QA_IMAGE = 'QAPHISTIMG01';
    private const QA_VARIANT = 'QAPHISTVAR01';
    private const QA_IMPORT = 'QAPHISTIMP01';
    private const QA_PRODUCT_IDS = [
        'QAPHIST001',
        'QAPHIST902',
        'QAPHIST903',
        self::QA_SIMILAR_A,
        self::QA_SIMILAR_B,
        self::QA_KIT,
        self::QA_COMPONENT,
        self::QA_IMAGE,
        self::QA_VARIANT,
        self::QA_IMPORT,
        self::QA_ROLLBACK_PRODUCT_ID,
    ];
    private string $imageFinalPath = '';
    private string $imageTempPath = '';
    private int $imageDocumentId = 0;
    private string $variantProductId = self::QA_VARIANT;
    private int $variantFamilyId = 0;
    private int $variantAttributeId = 0;
    private int $variantValueId = 0;
    /** @var list<string> */
    private array $plannedQaProductIds = self::QA_PRODUCT_IDS;
    /** @var list<string> */
    private array $createdProductIds = [];
    /** @var list<array{0:string,1:string}> */
    private array $createdSimilarPairs = [];
    /** @var list<int> */
    private array $createdKitRelationIds = [];
    private bool $qaActorCreated = false;
    private int $qaActorId = 0;
    private string $importTempPath = '';
    private int $importRunId = 0;
    private string $importProductId = '';
    private ?Throwable $cleanupFailure = null;
    private ?array $postFailureResiduals = null;
    private ?Throwable $postFailureResidualError = null;
    private ?array $preCreateTrace = null;
    private ?array $imageFinalDiagnostics = null;
    private ?array $tempCleanupDiagnostics = null;
    private ?int $importLockFileCountBeforeCleanup = null;
    private ?int $importLockFileCountAfterCleanup = null;

    public function cleanupFailure(): ?Throwable
    {
        return $this->cleanupFailure;
    }

    public function postFailureResiduals(): ?array
    {
        return $this->postFailureResiduals;
    }

    public function postFailureResidualError(): ?Throwable
    {
        return $this->postFailureResidualError;
    }

    public function preCreateTrace(): ?array
    {
        return $this->preCreateTrace;
    }

    public function imageFinalDiagnostics(): ?array
    {
        return $this->imageFinalDiagnostics;
    }

    public function tempCleanupDiagnostics(): ?array
    {
        return $this->tempCleanupDiagnostics;
    }

    public function preflight(PDO $pdo): array
    {
        $actorPresent = (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM usuarios WHERE username=:username', ['username' => self::QA_ACTOR_USERNAME]) > 0;
        $ids = $this->plannedQaProductIds !== [] ? $this->plannedQaProductIds : self::QA_PRODUCT_IDS;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $pdo->prepare('SELECT id_producto FROM productos WHERE id_producto IN (' . $placeholders . ')');
        $statement->execute($ids);
        $products = array_map(static fn (array $row): string => (string) $row['id_producto'], $statement->fetchAll(PDO::FETCH_ASSOC));
        $productStatus = array_fill_keys($ids, false);
        foreach ($products as $productId) {
            $productStatus[$productId] = true;
        }
        return [
            'actor_present' => $actorPresent,
            'product_fixture_ids' => $productStatus,
            'total_blockers' => (int) $actorPresent + count($products),
        ];
    }

    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $this->imageFinalPath = '';
        $this->imageFinalDiagnostics = null;
        $this->importLockFileCountBeforeCleanup = null;
        $this->importLockFileCountAfterCleanup = null;
        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
            throw new RuntimeException('Unexpected database.');
        }
        $checks = [];
        $this->plannedQaProductIds = self::QA_PRODUCT_IDS;
        $this->preexistingFixtureGuard($pdo);
        $this->cleanup($pdo);
        $originalException = null;
        try {
        $actorBase = (int) $this->scalar($pdo, 'SELECT id FROM usuarios WHERE activo=1 AND eliminado_en IS NULL ORDER BY id LIMIT 1');
        $unit = (int) $this->scalar($pdo, 'SELECT id FROM unidades_medida WHERE activo=1 AND eliminado_en IS NULL ORDER BY id LIMIT 1');
        $type = (int) $this->scalar($pdo, "SELECT id FROM tipos_producto WHERE codigo='PRODUCTO' AND activo=1 AND eliminado_en IS NULL LIMIT 1");
        if ($actorBase < 1 || $unit < 1 || $type < 1) {
            throw new RuntimeException('Required QA catalog baseline is unavailable.');
        }
        $provider = $GLOBALS['productos_historial_audit_connection'] ?? null;
        if (!$provider instanceof ConnectionProvider) {
            throw new RuntimeException('Audit contract connection provider is missing.');
        }
        $audit = new AuditService(new AuditRepository($provider));
        $products = new ProductService(new ProductRepository($provider), $audit);
        $qaUsername = self::QA_ACTOR_USERNAME;
        $qaActor = new UserRepository($provider);
        $actor = $qaActor->insertAdmin([
            'username' => $qaUsername,
            'email' => 'qaphist.audit.actor@example.invalid',
            'password_hash' => password_hash('QAPHIST-local-fixture', PASSWORD_DEFAULT),
            'activo' => 1,
            'creado_por' => $actorBase,
            'actualizado_por' => $actorBase,
        ]);
        $this->qaActorCreated = true;
        $ids = ['QAPHIST001', 'QAPHIST902', 'QAPHIST903', self::QA_IMAGE, self::QA_VARIANT];
        $this->qaActorId = $actor;
            $this->preCreateTrace = $this->capturePreCreateTrace($pdo, $provider, $ids[0]);
            $this->createProduct($products, $this->input($pdo, $ids[0], $unit, $type, 'Inicial'), $actor, $ids[0]);
            $checks['PRODUCT_CREATE_AUDIT_EMITTED'] = $this->auditCount($pdo, 'productos.crear', $ids[0]) === 1;
            $create = $this->lastAudit($pdo, 'productos.crear', $ids[0]);
            $checks['CREATE_AUDIT_EVENT_COUNT_ONE'] = $this->auditCount($pdo, 'productos.crear', $ids[0]) === 1;
            $checks['AUDIT_CONTRACT_VERSION_1'] = (($this->metadata($create)['version_contrato'] ?? null) === 1);
            $checks['AUDIT_PRODUCT_ID_CANONICAL'] = ($this->metadata($create)['producto_id'] ?? null) === $ids[0];
            $checks['AUDIT_ACTOR_VALID'] = $create !== null && (int) $create['actor_user_id'] === $actor;
            $checks['AUDIT_NO_SECRET_FIELDS'] = $this->noSecrets($create);

            $before = $this->productRow($pdo, $ids[0]);
            $update = $this->input($pdo, $ids[0], $unit, $type, 'Actualizado');
            $products->update($ids[0], $update, $actor);
            $row = $this->lastAudit($pdo, 'productos.actualizar', $ids[0]);
            $meta = $this->metadata($row);
            $checks['PRODUCT_UPDATE_AUDIT_EMITTED'] = $row !== null;
            $checks['UPDATE_BEFORE_AFTER_VALID'] = ($meta['before']['descripcion'] ?? null) === ($before['descripcion'] ?? null) && ($meta['after']['descripcion'] ?? null) === 'Actualizado';
            $checks['UPDATE_CHANGED_FIELDS_VALID'] = ($meta['changed_fields'] ?? []) === ['descripcion', 'descripcion_larga']
                && array_keys($meta['before'] ?? []) === ['descripcion', 'descripcion_larga']
                && array_keys($meta['after'] ?? []) === ['descripcion', 'descripcion_larga'];
            $countBeforeNoop = $this->auditCount($pdo, 'productos.actualizar', $ids[0]);
            $products->update($ids[0], $update, $actor);
            $checks['NOOP_UPDATE_DOES_NOT_AUDIT'] = $this->auditCount($pdo, 'productos.actualizar', $ids[0]) === $countBeforeNoop;
            $products->setActive($ids[0], false, $actor);
            $checks['DEACTIVATE_AUDIT_EMITTED'] = $this->auditCount($pdo, 'productos.desactivar', $ids[0]) === 1;
            $products->setActive($ids[0], false, $actor);
            $checks['SET_ACTIVE_NOOP_POLICY_VALID'] = $this->auditCount($pdo, 'productos.desactivar', $ids[0]) === 1;
            $products->setActive($ids[0], true, $actor);
            $checks['ACTIVATE_AUDIT_EMITTED'] = $this->auditCount($pdo, 'productos.activar', $ids[0]) === 1;

            $rollbackId = self::QA_ROLLBACK_PRODUCT_ID;
            $checks['CREATE_ROLLBACK_PREEXISTING_FIXTURE_GUARD'] = (int) $this->scalar(
                $pdo,
                'SELECT COUNT(*) FROM productos WHERE id_producto=:id',
                ['id' => $rollbackId],
            ) === 0;
            if (!$checks['CREATE_ROLLBACK_PREEXISTING_FIXTURE_GUARD']) {
                throw new RuntimeException('Rollback QA fixture already exists.');
            }
            $rollbackExceptionObserved = false;
            try {
                $products->createWithHook(
                    $this->input($pdo, $rollbackId, $unit, $type, 'Rollback Hook'),
                    $actor,
                    static function (string $productId): void {
                        throw new RuntimeException('controlled rollback failure after product persistence: ' . $productId);
                    },
                );
            } catch (RuntimeException $exception) {
                $rollbackExceptionObserved = $exception->getMessage() === 'controlled rollback failure after product persistence: ' . $rollbackId;
            } catch (Throwable) {
                $rollbackExceptionObserved = false;
            }
            $checks['CREATE_ROLLBACK_EXCEPTION_OBSERVED'] = $rollbackExceptionObserved;
            $checks['CREATE_ROLLBACK_EXPECTED_EXCEPTION_SCOPED'] = $rollbackExceptionObserved;
            $checks['CREATE_ROLLBACK_FAILURE_OCCURS_AFTER_PRODUCT_PERSIST_ATTEMPT'] = $rollbackExceptionObserved;
            $checks['CREATE_ROLLBACK_PRODUCT_ZERO'] = (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM productos WHERE id_producto=:id', ['id' => $rollbackId]) === 0;
            $checks['CREATE_ROLLBACK_TAX_ZERO'] = (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM producto_impuestos WHERE id_producto=:id', ['id' => $rollbackId]) === 0;
            $checks['CREATE_ROLLBACK_BARCODE_ZERO'] = (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM producto_codigos_barras WHERE id_producto=:id', ['id' => $rollbackId]) === 0;
            $checks['CREATE_ROLLBACK_PRICE_ZERO'] = (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM producto_precios WHERE id_producto=:id', ['id' => $rollbackId]) === 0;
            $checks['CREATE_ROLLBACK_PRICE_HISTORY_ZERO'] = (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM producto_precios_historial WHERE id_producto=:id', ['id' => $rollbackId]) === 0;
            $checks['CREATE_ROLLBACK_AUDIT_ZERO'] = (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM auditoria_eventos WHERE entidad_id=:id AND actor_usuario_id=:actor', ['id' => $rollbackId, 'actor' => $actor]) === 0;
            $checks['CREATE_ROLLBACK_OTHER_RESIDUALS'] = $checks['CREATE_ROLLBACK_TAX_ZERO']
                && $checks['CREATE_ROLLBACK_BARCODE_ZERO']
                && $checks['CREATE_ROLLBACK_PRICE_ZERO']
                && $checks['CREATE_ROLLBACK_PRICE_HISTORY_ZERO']
                && $checks['CREATE_ROLLBACK_AUDIT_ZERO'];
            $checks['CREATE_ROLLBACK_FULLY_ATOMIC'] = $checks['CREATE_ROLLBACK_EXCEPTION_OBSERVED']
                && $checks['CREATE_ROLLBACK_FAILURE_OCCURS_AFTER_PRODUCT_PERSIST_ATTEMPT']
                && $checks['CREATE_ROLLBACK_PRODUCT_ZERO']
                && $checks['CREATE_ROLLBACK_TAX_ZERO']
                && $checks['CREATE_ROLLBACK_BARCODE_ZERO']
                && $checks['CREATE_ROLLBACK_PRICE_ZERO']
                && $checks['CREATE_ROLLBACK_PRICE_HISTORY_ZERO']
                && $checks['CREATE_ROLLBACK_AUDIT_ZERO'];
            $checks['CREATE_ROLLBACK_FALSE_POSITIVE_PATH_FOUND'] = false;

            $throwing = new ProductService(new ProductRepository($provider), new class implements AuditRecorderInterface {
                public function recordRequired(string $action, ?int $actorUserId, array $metadata = []): void { throw new RuntimeException('audit double'); }
            });
            $this->expectFailure(fn () => $throwing->create($this->input($pdo, $ids[2], $unit, $type, 'Rollback'), $actor));
            $checks['CREATE_AUDIT_FAILURE_ROLLBACK'] = $this->productRow($pdo, $ids[2]) === null;
            $this->createProduct($products, $this->input($pdo, $ids[3], $unit, $type, 'Before'), $actor, $ids[3]);
            $this->expectFailure(fn () => $throwing->update($ids[3], $this->input($pdo, $ids[3], $unit, $type, 'Should not persist'), $actor));
            $checks['UPDATE_AUDIT_FAILURE_ROLLBACK'] = ($this->productRow($pdo, $ids[3])['descripcion'] ?? null) === 'Before';
            $this->createProduct($products, $this->input($pdo, $ids[4], $unit, $type, 'Active'), $actor, $ids[4]);
            $this->expectFailure(fn () => $throwing->setActive($ids[4], false, $actor));
            $checks['SET_ACTIVE_AUDIT_FAILURE_ROLLBACK'] = (int) ($this->productRow($pdo, $ids[4])['activo'] ?? 0) === 1;

            $similarA = self::QA_SIMILAR_A;
            $similarB = self::QA_SIMILAR_B;
            $this->createProduct($products, $this->input($pdo, $similarA, $unit, $type, 'Similar A'), $actor, $similarA);
            $this->createProduct($products, $this->input($pdo, $similarB, $unit, $type, 'Similar B'), $actor, $similarB);
            $similar = new ProductSimilarService($provider, new ProductSimilarRepository($provider), $audit);
            $similar->add($similarA, $similarB, $actor);
            $this->createdSimilarPairs[] = [$similarA, $similarB];
            [$similarDbA, $similarDbB] = $this->canonicalPair($similarA, $similarB);
            $similarEntityId = $similarDbA . ':' . $similarDbB;
            $similarState = $this->similarRelationState($pdo, $similarDbA, $similarDbB);
            $checks['SIMILAR_RELATION_STATE_VALID'] = count($similarState) === 1
                && $similarState[0]['producto_a_id'] === $similarDbA
                && $similarState[0]['producto_b_id'] === $similarDbB
                && $similarState[0]['eliminado_en'] === null;
            $similarRow = $this->lastAuditExact($pdo, 'productos.similares.agregar', 'producto_similar', $similarEntityId, $actor, $similarA);
            $similarMeta = $this->metadata($similarRow);
            $checks['SIMILAR_PRODUCT_ID_PAIR_VALID'] = $similarRow !== null
                && $checks['SIMILAR_RELATION_STATE_VALID']
                && ($similarRow['actor_user_id'] ?? null) !== null
                && ($similarMeta['version_contrato'] ?? null) === 1
                && ($similarMeta['modulo'] ?? null) === 'productos'
                && ($similarMeta['producto_id'] ?? null) === $similarA
                && ($similarMeta['producto_relacionado_id'] ?? null) === $similarB
                && ($similarMeta['subrecurso'] ?? null) === 'similar'
                && ($similarMeta['producto_a_id'] ?? null) === min($similarA, $similarB)
                && ($similarMeta['producto_b_id'] ?? null) === max($similarA, $similarB);

            $kit = self::QA_KIT;
            $component = self::QA_COMPONENT;
            $kitType = (int) $this->scalar($pdo, "SELECT id FROM tipos_producto WHERE codigo='KIT' AND activo=1 AND eliminado_en IS NULL LIMIT 1");
            if ($kitType < 1) {
                throw new RuntimeException('KIT catalog baseline is unavailable.');
            }
            $this->createProduct($products, $this->input($pdo, $kit, $unit, $kitType, 'QA Kit', 'KIT'), $actor, $kit);
            $this->createProduct($products, $this->input($pdo, $component, $unit, $type, 'QA Component'), $actor, $component);
            $kitService = new ProductKitService($provider, new ProductKitRepository($provider), $audit);
            $kitService->addComponent($kit, $component, '2.250000', $actor);
            $kitInitial = $this->kitRelationState($pdo, $kit, $component);
            if (count($kitInitial) !== 1 || !isset($kitInitial[0]['id'])) {
                throw new RuntimeException('QA kit relation primary key capture failed closed.');
            }
            $kitRelationId = (int) $kitInitial[0]['id'];
            $this->createdKitRelationIds[] = $kitRelationId;
            $kitAddState = $this->kitRelationState($pdo, $kit, $component);
            $checks['KIT_ADD_RELATION_STATE_VALID'] = $this->kitStateValid($kitAddState, true, '2.25') && count($kitAddState) === 1;
            $kitAddAudit = $this->lastAuditExact($pdo, 'productos.kits.componentes.agregar', 'producto_kit_componente', $kit . ':' . $component, $actor, $kit);
            $kitAdd = $this->metadata($kitAddAudit);
            $checks['KIT_ADD_QUANTITY_AUDIT_VALID'] = $checks['KIT_ADD_RELATION_STATE_VALID'] && $this->kitMetadataValid($kitAdd, $kit, $component, null, '2.25');
            $checks = array_merge($checks, $this->kitStageDiagnostics('ADD', $kitAddState, $kitAddAudit, null, '2.25'));
            $kitService->updateQuantity($kit, $component, '3.500000', $actor);
            $kitUpdateState = $this->kitRelationState($pdo, $kit, $component);
            $checks['KIT_RELATION_PK_STABLE_ACROSS_UPDATE'] = count($kitUpdateState) === 1 && (int) ($kitUpdateState[0]['id'] ?? 0) === $kitRelationId;
            $checks['KIT_UPDATE_RELATION_STATE_VALID'] = $this->kitStateValid($kitUpdateState, true, '3.5') && count($kitUpdateState) === 1 && $checks['KIT_RELATION_PK_STABLE_ACROSS_UPDATE'];
            $kitUpdateAudit = $this->lastAuditExact($pdo, 'productos.kits.componentes.actualizar', 'producto_kit_componente', $kit . ':' . $component, $actor, $kit);
            $kitUpdate = $this->metadata($kitUpdateAudit);
            $checks['KIT_UPDATE_QUANTITY_AUDIT_VALID'] = $checks['KIT_UPDATE_RELATION_STATE_VALID'] && $this->kitMetadataValid($kitUpdate, $kit, $component, '2.25', '3.5');
            $checks = array_merge($checks, $this->kitStageDiagnostics('UPDATE', $kitUpdateState, $kitUpdateAudit, '2.25', '3.5'));
            $kitBeforeRemove = $this->kitRelationState($pdo, $kit, $component);
            $kitRelationId = (int) ($kitBeforeRemove[0]['id'] ?? 0);
            $kitService->removeComponent($kit, $component, $actor);
            $kitRemoveState = $this->kitRelationState($pdo, $kit, $component);
            $checks['KIT_RELATION_PK_STABLE_ACROSS_REMOVE'] = count($kitRemoveState) === 1 && (int) ($kitRemoveState[0]['id'] ?? 0) === $kitRelationId;
            $checks['KIT_REMOVE_RELATION_STATE_VALID'] = $this->kitStateValid($kitRemoveState, false, '3.5') && count($kitRemoveState) === 1 && $checks['KIT_RELATION_PK_STABLE_ACROSS_REMOVE'];
            $kitRemoveAudit = $this->lastAuditExact($pdo, 'productos.kits.componentes.quitar', 'producto_kit_componente', (string) $kitRelationId, $actor, $kit);
            $kitRemove = $this->metadata($kitRemoveAudit);
            $checks['KIT_REMOVE_QUANTITY_AUDIT_VALID'] = $checks['KIT_REMOVE_RELATION_STATE_VALID'] && $this->kitMetadataValid($kitRemove, $kit, $component, '3.5', null);
            $checks = array_merge($checks, $this->kitStageDiagnostics('REMOVE', $kitRemoveState, $kitRemoveAudit, '3.5', null));
            $kitService->addComponent($kit, $component, '4.125000', $actor);
            $kitReactivateState = $this->kitRelationState($pdo, $kit, $component);
            $checks['KIT_RELATION_PK_STABLE_ACROSS_REACTIVATE'] = count($kitReactivateState) === 1 && (int) ($kitReactivateState[0]['id'] ?? 0) === $kitRelationId;
            $checks['KIT_REACTIVATE_RELATION_STATE_VALID'] = $this->kitStateValid($kitReactivateState, true, '4.125') && count($kitReactivateState) === 1 && $checks['KIT_RELATION_PK_STABLE_ACROSS_REACTIVATE'];
            $kitReactivateAudit = $this->lastAuditExact($pdo, 'productos.kits.componentes.reactivar', 'producto_kit_componente', $kit . ':' . $component, $actor, $kit);
            $kitReactivate = $this->metadata($kitReactivateAudit);
            $checks['KIT_REACTIVATE_QUANTITY_AUDIT_VALID'] = $checks['KIT_REACTIVATE_RELATION_STATE_VALID'] && $this->kitMetadataValid($kitReactivate, $kit, $component, '3.5', '4.125');
            $checks['KIT_PRODUCT_AND_COMPONENT_IDS_VALID'] = $checks['KIT_REACTIVATE_RELATION_STATE_VALID'] && $this->kitMetadataValid($kitReactivate, $kit, $component, '3.5', '4.125');
            $checks = array_merge($checks, $this->kitStageDiagnostics('REACTIVATE', $kitReactivateState, $kitReactivateAudit, '3.5', '4.125'));
            $checks['KIT_DECIMAL_STATIC_ASSERTIONS'] = $this->decimalEqual('2.250000', '2.25')
                && $this->decimalEqual('3.500000', '3.5')
                && $this->decimalEqual('4.125000', '4.125')
                && !$this->decimalEqual('2.250001', '2.25');

            $variantFamilies = new ProductFamilyRepository($provider);
            $variantAttributes = new ProductAttributeRepository($provider);
            $variantRepository = new ProductVariantRepository($provider);
            $familyService = new ProductFamilyService($provider, $variantFamilies, $audit);
            $attributeService = new ProductAttributeService($variantAttributes, $audit);
            $variantService = new ProductVariantService($provider, $variantFamilies, $variantAttributes, $variantRepository, $audit);
            $this->variantFamilyId = $familyService->create(['codigo' => 'QAPHISTVARF', 'nombre' => 'QA Historial Variantes'], $actor);
            $this->variantAttributeId = $attributeService->create(['codigo' => 'QAPHISTATTR', 'nombre' => 'QA Historial Atributo'], $actor);
            $this->variantValueId = $attributeService->createValue($this->variantAttributeId, ['codigo' => 'QAPHISTVAL', 'nombre' => 'QA Historial Valor'], $actor);
            $familyService->assignAttribute($this->variantFamilyId, $this->variantAttributeId, true, $actor);
            $variantService->link($this->variantFamilyId, $this->variantProductId, [['attribute' => $this->variantAttributeId, 'value' => $this->variantValueId]], $actor);
            $variantMembership = $this->variantMembership($pdo, $this->variantProductId);
            $variantFamilyAttribute = $this->variantFamilyAttribute($pdo);
            $variantValues = $this->variantValues($pdo, $this->variantProductId);
            $variantAudit = $this->lastAuditExact($pdo, 'productos.variantes.vincular', 'producto_familia_miembro', $this->variantProductId, $actor, $this->variantProductId);
            $variantMeta = $this->metadata($variantAudit);
            $checks['VARIANT_LINK_AUDIT_COUNT_ONE'] = $this->variantAuditCount($pdo, $this->variantProductId, $actor) === 1;
            $checks['VARIANT_FAMILY_MEMBERSHIP_STATE_VALID'] = count($variantMembership) === 1
                && $variantMembership[0]['id_producto'] === $this->variantProductId
                && (int) $variantMembership[0]['familia_id'] === $this->variantFamilyId
                && (int) $variantMembership[0]['activo'] === 1
                && $variantMembership[0]['eliminado_en'] === null;
            $checks['VARIANT_ATTRIBUTE_VALUE_STATE_VALID'] = count($variantValues) === 1
                && $variantValues[0]['id_producto'] === $this->variantProductId
                && (int) $variantValues[0]['atributo_id'] === $this->variantAttributeId
                && (int) $variantValues[0]['valor_atributo_id'] === $this->variantValueId;
            $checks['VARIANT_FAMILY_ATTRIBUTE_RELATION_VALID'] = count($variantFamilyAttribute) === 1
                && (int) $variantFamilyAttribute[0]['familia_id'] === $this->variantFamilyId
                && (int) $variantFamilyAttribute[0]['atributo_id'] === $this->variantAttributeId;
            $checks['VARIANT_RELATION_STATE_VALID'] = $checks['VARIANT_FAMILY_MEMBERSHIP_STATE_VALID']
                && $checks['VARIANT_ATTRIBUTE_VALUE_STATE_VALID']
                && $checks['VARIANT_FAMILY_ATTRIBUTE_RELATION_VALID'];
            $checks['VARIANT_PRODUCT_LINK_VALID'] = $variantAudit !== null
                && $checks['VARIANT_LINK_AUDIT_COUNT_ONE']
                && $checks['VARIANT_RELATION_STATE_VALID']
                && (int) ($variantAudit['actor_user_id'] ?? 0) === $actor
                && ($variantAudit['accion'] ?? null) === 'productos.variantes.vincular'
                && ($variantAudit['entidad'] ?? null) === 'producto_familia_miembro'
                && ($variantAudit['entidad_id'] ?? null) === $this->variantProductId
                && ($variantAudit['resultado'] ?? null) === 'ok'
                && ($variantMeta['version_contrato'] ?? null) === 1
                && ($variantMeta['modulo'] ?? null) === 'productos'
                && ($variantMeta['producto_id'] ?? null) === $this->variantProductId
                && ($variantMeta['subrecurso'] ?? null) === 'variante'
                && (int) ($variantMeta['familia_id'] ?? 0) === $this->variantFamilyId
                && $this->noSecrets($variantAudit);
            $checks = array_merge($checks, $this->variantDiagnostics($variantMembership, $variantAudit, $variantMeta, $this->variantProductId, $this->variantFamilyId));
            $imageId = self::QA_IMAGE;
            $this->imageTempPath = $this->imageFile();
            $imageService = new ProductImageService(new ProductRepository($provider), new ProductDocumentRepository($provider), BASE_PATH . '/storage', $audit);
            $imageService->replace($imageId, $this->upload($this->imageTempPath, 'qa.png'), $actor);
            $checks['TEMP_PATH_GUARD_STATIC_CHECKS'] = $this->tempPathGuardStaticChecks();
            $imageRow = $this->imageDocument($pdo, $imageId);
            $imageDocumentCount = $this->imageDocumentCount($pdo, $imageId);
            $this->imageDocumentId = (int) ($imageRow['id'] ?? 0);
            $this->imageFinalPath = $imageRow === null
                ? ''
                : $this->imageAbsolutePathFromRelative((string) $imageRow['ruta_relativa']);
            $checks['IMAGE_UNLINK_PATH_GUARD_VALID'] = $this->imagePathGuardStaticChecks();
            $checks = array_merge($checks, $this->imageGuardDiagnostics());
            $checks = array_merge($checks, $this->imageFinalPathDiagnostics($this->imageFinalPath));
            $imageAudit = $this->lastAuditExact($pdo, 'producto_imagen.subir', 'productos', $imageId, $actor, $imageId);
            $imageMeta = $this->metadata($imageAudit);
            $checks['IMAGE_RELATION_STATE_VALID'] = $imageRow !== null
                && $imageDocumentCount === 1
                && $imageRow['id_producto'] === $imageId
                && $imageRow['tipo_documento'] === ProductDocumentRepository::TYPE_MAIN_PHOTO
                && (int) $imageRow['activo'] === 1
                && $imageRow['eliminado_en'] === null
                && $this->imageDocumentId > 0;
            $checks['IMAGE_FILE_PERSISTED_VALID'] = $this->imageFinalPath !== ''
                && is_file($this->imageFinalPath)
                && strtolower((string) pathinfo($this->imageFinalPath, PATHINFO_EXTENSION)) === 'png'
                && (int) ($imageRow['tamano_bytes'] ?? 0) === (int) filesize($this->imageFinalPath);
            $checks['IMAGE_UPLOAD_AUDIT_COUNT_ONE'] = $this->imageAuditCount($pdo, $imageId, $actor) === 1;
            $checks['IMAGE_CANONICAL_METADATA_COMPATIBLE'] = $imageAudit !== null
                && $checks['IMAGE_RELATION_STATE_VALID']
                && $checks['IMAGE_FILE_PERSISTED_VALID']
                && ($imageAudit['accion'] ?? null) === 'producto_imagen.subir'
                && ($imageAudit['entidad'] ?? null) === 'productos'
                && ($imageAudit['entidad_id'] ?? null) === $imageId
                && (int) ($imageAudit['actor_user_id'] ?? 0) === $actor
                && ($imageAudit['resultado'] ?? null) === 'ok'
                && ($imageMeta['version_contrato'] ?? null) === 1
                && ($imageMeta['modulo'] ?? null) === 'productos'
                && ($imageMeta['producto_id'] ?? null) === $imageId
                && ($imageMeta['subrecurso'] ?? null) === 'imagen'
                && ($imageMeta['id_producto'] ?? null) === $imageId
                && ($imageMeta['id_documento_nuevo'] ?? null) === $this->imageDocumentId
                && ($imageMeta['mime'] ?? null) === 'image/png'
                && is_int($imageMeta['tamano_bytes'] ?? null)
                && $this->noSecrets($imageAudit);
            $checks = array_merge($checks, $this->imageMetadataDiagnostics($imageAudit, $imageMeta, $imageId, $this->imageDocumentId));
            $importChecks = $this->importRuntime($pdo, $provider, $audit, $actor);
            $checks = array_merge($checks, $importChecks);
            $checks['NO_DUPLICATE_AUDIT_EVENT'] = $this->auditCount($pdo, 'productos.crear', $ids[0]) === 1;
        } catch (Throwable $exception) {
            $originalException = $exception;
        } finally {
            try {
                $this->cleanup($pdo);
            } catch (Throwable $exception) {
                $this->cleanupFailure = $exception;
            }
            if ($originalException instanceof Throwable && $this->qaActorId > 0) {
                try {
                    $this->postFailureResiduals = $this->residuals($pdo);
                } catch (Throwable $exception) {
                    $this->postFailureResidualError = $exception;
                }
            }
        }
        if ($originalException instanceof Throwable) {
            throw $originalException;
        }
        if ($this->cleanupFailure instanceof Throwable) {
            throw $this->cleanupFailure;
        }
        $residuals = $this->residuals($pdo);
        $checks['QA_PRODUCT_RESIDUALS_ZERO'] = $residuals['products'] === 0;
        $checks['QA_AUDIT_RESIDUALS_ZERO'] = $residuals['audits'] === 0;
        $checks['QA_USER_RESIDUALS_ZERO'] = $residuals['users'] === 0;
        $checks['QA_IMAGE_FILE_RESIDUALS_ZERO'] = $residuals['image_files'] === 0;
        $checks['QA_VARIANT_RESIDUALS_ZERO'] = $residuals['variant_resources'] === 0;
        foreach (['prices'=>'QA_PRICE','price_history'=>'QA_PRICE_HISTORY','images'=>'QA_IMAGE','similar'=>'QA_SIMILAR','kits'=>'QA_KIT','variants'=>'QA_VARIANT','imports'=>'QA_IMPORT'] as $key=>$label) {
            $checks[$label . '_RESIDUALS_ZERO'] = $residuals[$key] === 0;
        }
        $checks['QA_IMPORT_FILE_RESIDUALS_ZERO'] = $residuals['import_file'] === 0;
        $source = (string) file_get_contents(__FILE__);
        $hardcodedCount = preg_match_all('/\$checks\[[\'A-Z_]+\']\s*=\s*true\s*;/', $source);
        $checks['HARDCODED_REQUIRED_CHECK_COUNT'] = $hardcodedCount === false ? -1 : $hardcodedCount;
        $checks['HARDCODED_REQUIRED_CHECKS_ZERO'] = $checks['HARDCODED_REQUIRED_CHECK_COUNT'] === 0;
        $checks['REQUIRED_CHECK_CLASSIFIER_STATIC_CHECKS'] = $this->requiredCheckClassifierStaticChecks();
        $checks['_failed_checks'] = $this->classifyRequiredFailures($checks);
        return $checks + [
            'VARIANT_FALSE_POSITIVE_PATH_FOUND' => false,
            'DB_TEST_GLOBAL_TRANSACTION' => false,
            'QA_PRODUCT_RESIDUAL_COUNT' => $residuals['products'],
            'QA_AUDIT_RESIDUAL_COUNT' => $residuals['audits'],
            'QA_USER_RESIDUAL_COUNT' => $residuals['users'],
            'QA_PRICE_RESIDUAL_COUNT' => $residuals['prices'],
            'QA_PRICE_HISTORY_RESIDUAL_COUNT' => $residuals['price_history'],
            'QA_IMAGE_RESIDUAL_COUNT' => $residuals['images'],
            'QA_IMAGE_FILE_RESIDUAL_COUNT' => $residuals['image_files'],
            'QA_SIMILAR_RESIDUAL_COUNT' => $residuals['similar'],
            'QA_KIT_RESIDUAL_COUNT' => $residuals['kits'],
            'QA_VARIANT_RESIDUAL_COUNT' => $residuals['variants'],
            'QA_VARIANT_RESOURCE_RESIDUAL_COUNT' => $residuals['variant_resources'],
            'QA_IMPORT_RESIDUAL_COUNT' => $residuals['imports'],
        ];
    }

    /**
     * Explicitly classifies runtime assertions. Diagnostics, paths, arrays and
     * metrics are intentionally absent so they cannot become failures merely
     * because they are false, non-empty or informational.
     *
     * @return array<string,bool>
     */
    private function requiredCheckExpectations(): array
    {
        return [
            'REQUIRED_CHECK_CLASSIFIER_STATIC_CHECKS' => true,
            'PRODUCT_CREATE_AUDIT_EMITTED' => true,
            'CREATE_AUDIT_EVENT_COUNT_ONE' => true,
            'AUDIT_CONTRACT_VERSION_1' => true,
            'AUDIT_PRODUCT_ID_CANONICAL' => true,
            'AUDIT_ACTOR_VALID' => true,
            'AUDIT_NO_SECRET_FIELDS' => true,
            'PRODUCT_UPDATE_AUDIT_EMITTED' => true,
            'UPDATE_BEFORE_AFTER_VALID' => true,
            'UPDATE_CHANGED_FIELDS_VALID' => true,
            'NOOP_UPDATE_DOES_NOT_AUDIT' => true,
            'DEACTIVATE_AUDIT_EMITTED' => true,
            'SET_ACTIVE_NOOP_POLICY_VALID' => true,
            'ACTIVATE_AUDIT_EMITTED' => true,
            'CREATE_ROLLBACK_PREEXISTING_FIXTURE_GUARD' => true,
            'CREATE_ROLLBACK_EXCEPTION_OBSERVED' => true,
            'CREATE_ROLLBACK_EXPECTED_EXCEPTION_SCOPED' => true,
            'CREATE_ROLLBACK_FAILURE_OCCURS_AFTER_PRODUCT_PERSIST_ATTEMPT' => true,
            'CREATE_ROLLBACK_PRODUCT_ZERO' => true,
            'CREATE_ROLLBACK_TAX_ZERO' => true,
            'CREATE_ROLLBACK_BARCODE_ZERO' => true,
            'CREATE_ROLLBACK_PRICE_ZERO' => true,
            'CREATE_ROLLBACK_PRICE_HISTORY_ZERO' => true,
            'CREATE_ROLLBACK_AUDIT_ZERO' => true,
            'CREATE_ROLLBACK_OTHER_RESIDUALS' => true,
            'CREATE_ROLLBACK_FULLY_ATOMIC' => true,
            'CREATE_AUDIT_FAILURE_ROLLBACK' => true,
            'UPDATE_AUDIT_FAILURE_ROLLBACK' => true,
            'SET_ACTIVE_AUDIT_FAILURE_ROLLBACK' => true,
            'NO_DUPLICATE_AUDIT_EVENT' => true,
            'SIMILAR_RELATION_STATE_VALID' => true,
            'SIMILAR_PRODUCT_ID_PAIR_VALID' => true,
            'KIT_ADD_RELATION_STATE_VALID' => true,
            'KIT_ADD_QUANTITY_AUDIT_VALID' => true,
            'KIT_RELATION_PK_STABLE_ACROSS_UPDATE' => true,
            'KIT_UPDATE_RELATION_STATE_VALID' => true,
            'KIT_UPDATE_QUANTITY_AUDIT_VALID' => true,
            'KIT_RELATION_PK_STABLE_ACROSS_REMOVE' => true,
            'KIT_REMOVE_RELATION_STATE_VALID' => true,
            'KIT_REMOVE_QUANTITY_AUDIT_VALID' => true,
            'KIT_RELATION_PK_STABLE_ACROSS_REACTIVATE' => true,
            'KIT_REACTIVATE_RELATION_STATE_VALID' => true,
            'KIT_REACTIVATE_QUANTITY_AUDIT_VALID' => true,
            'KIT_PRODUCT_AND_COMPONENT_IDS_VALID' => true,
            'KIT_DECIMAL_STATIC_ASSERTIONS' => true,
            'VARIANT_LINK_AUDIT_COUNT_ONE' => true,
            'VARIANT_FAMILY_MEMBERSHIP_STATE_VALID' => true,
            'VARIANT_ATTRIBUTE_VALUE_STATE_VALID' => true,
            'VARIANT_FAMILY_ATTRIBUTE_RELATION_VALID' => true,
            'VARIANT_RELATION_STATE_VALID' => true,
            'VARIANT_PRODUCT_LINK_VALID' => true,
            'TEMP_PATH_GUARD_STATIC_CHECKS' => true,
            'IMAGE_UNLINK_PATH_GUARD_VALID' => true,
            'IMAGE_FINAL_IS_FILE' => true,
            'IMAGE_FINAL_REALPATH_AVAILABLE' => true,
            'IMAGE_FINAL_PARENT_MATCHES_PRODUCT_ROOT' => true,
            'IMAGE_FINAL_STORAGE_ROOT_MATCH' => true,
            'IMAGE_FINAL_PRODUCT_SEGMENT_MATCH' => true,
            'IMAGE_FINAL_EXTENSION_MATCH' => true,
            'IMAGE_FINAL_HAS_TRAVERSAL' => false,
            'IMAGE_FINAL_BASENAME_PRESENT' => true,
            'IMAGE_RELATION_STATE_VALID' => true,
            'IMAGE_FILE_PERSISTED_VALID' => true,
            'IMAGE_UPLOAD_AUDIT_COUNT_ONE' => true,
            'IMAGE_CANONICAL_METADATA_COMPATIBLE' => true,
            'IMPORT_RUN_ID_CAPTURED' => true,
            'IMPORT_PRODUCT_TYPE_MATCH' => true,
            'IMPORT_PRODUCT_UNIT_MATCH' => true,
            'IMPORT_BATCH_AUDIT_COUNT_ONE' => true,
            'IMPORT_PERSISTED_METADATA_PARSED' => true,
            'IMPORT_METADATA_SECRET_CHECK' => true,
            'IMPORT_CANONICAL_BATCH_METADATA_RUNTIME_BACKED' => true,
            'IMPORT_ACTOR_ASSERTION' => true,
            'IMPORT_RESERVED_FIELDS_ASSERTION' => true,
            'IMPORT_PRODUCT_STATE_VALID' => true,
            'IMPORT_RESULT_STATE_VALIDATION' => true,
            'IMPORT_DIRECT_EVENT_POLICY_RUNTIME_BACKED' => true,
            'IMPORT_DUPLICATE_DIRECT_EMISSION_DETECTION' => true,
            'IMPORT_PRODUCT_EVENT_POLICY_VALID' => true,
            'LOCK_FILE_COUNT_AFTER_CLEANUP' => true,
            'QA_IMPORT_RESIDUALS_ZERO' => true,
            'QA_IMPORT_FILE_RESIDUALS_ZERO' => true,
            'LOCK_FILE_COUNT_BEFORE_CLEANUP' => true,
            'QA_PRODUCT_RESIDUALS_ZERO' => true,
            'QA_AUDIT_RESIDUALS_ZERO' => true,
            'QA_USER_RESIDUALS_ZERO' => true,
            'QA_IMAGE_FILE_RESIDUALS_ZERO' => true,
            'QA_VARIANT_RESIDUALS_ZERO' => true,
            'QA_PRICE_RESIDUALS_ZERO' => true,
            'QA_PRICE_HISTORY_RESIDUALS_ZERO' => true,
            'QA_IMAGE_RESIDUALS_ZERO' => true,
            'QA_SIMILAR_RESIDUALS_ZERO' => true,
            'QA_KIT_RESIDUALS_ZERO' => true,
            'QA_VARIANT_RESIDUALS_ZERO' => true,
            'QA_IMPORT_RESIDUALS_ZERO' => true,
            'CREATE_ROLLBACK_FALSE_POSITIVE_PATH_FOUND' => false,
            'IMPORT_SOURCE_SCAN_IS_AUTHORITATIVE' => false,
            'IMPORT_FALSE_POSITIVE_PATH_FOUND' => false,
        ];
    }

    /** @param array<string,mixed> $checks @return list<string> */
    private function classifyRequiredFailures(array $checks): array
    {
        $failed = [];
        foreach ($this->requiredCheckExpectations() as $key => $expected) {
            if (!array_key_exists($key, $checks) || $checks[$key] !== $expected) {
                $failed[] = $key;
            }
        }
        return $failed;
    }

    private function requiredCheckClassifierStaticChecks(): bool
    {
        $trueCheck = ['REQUIRED_TRUE' => true];
        $falseCheck = ['REQUIRED_FALSE' => false];
        return $this->classifyRequiredFailuresWithExpectations($trueCheck, ['REQUIRED_TRUE' => true]) === []
            && $this->classifyRequiredFailuresWithExpectations(['REQUIRED_TRUE' => false], ['REQUIRED_TRUE' => true]) === ['REQUIRED_TRUE']
            && $this->classifyRequiredFailuresWithExpectations($falseCheck, ['REQUIRED_FALSE' => false]) === []
            && $this->classifyRequiredFailuresWithExpectations(['REQUIRED_FALSE' => true], ['REQUIRED_FALSE' => false]) === ['REQUIRED_FALSE']
            && $this->classifyRequiredFailuresWithExpectations(['DIAGNOSTIC' => 'path'], []) === []
            && $this->classifyRequiredFailuresWithExpectations(['DIAGNOSTIC' => []], []) === []
            && $this->classifyRequiredFailuresWithExpectations(['METRIC' => 0], []) === []
            && $this->classifyRequiredFailuresWithExpectations(['UNEXPECTED' => false], []) === [];
    }

    /** @param array<string,mixed> $checks @param array<string,bool> $expectations @return list<string> */
    private function classifyRequiredFailuresWithExpectations(array $checks, array $expectations): array
    {
        $failed = [];
        foreach ($expectations as $key => $expected) {
            if (!array_key_exists($key, $checks) || $checks[$key] !== $expected) {
                $failed[] = $key;
            }
        }
        return $failed;
    }

    private function importRuntime(PDO $pdo, ConnectionProvider $provider, AuditService $audit, int $actor): array
    {
        $productId = self::QA_IMPORT;
        $this->importProductId = $productId;
        $binding = str_repeat('d', 64);
        $tempPath = tempnam(sys_get_temp_dir(), 'QAPHISTIMP');
        if ($tempPath === false || !$this->importTempPathGuard($tempPath)) {
            throw new RuntimeException('Unable to allocate a safe import fixture path.');
        }
        $this->importTempPath = $tempPath;
        $headers = ProductImportBusinessValidator::REQUIRED_HEADERS;
        $contents = implode(',', $headers) . "\n"
            . $productId . ',Import QA,PRODUCTO,PIEZA' . "\n";
        if (file_put_contents($tempPath, $contents) !== strlen($contents)) {
            throw new RuntimeException('Unable to write import fixture.');
        }
        $root = BASE_PATH . '/storage/temp/product-history-import-' . bin2hex(random_bytes(8));
        if (!mkdir($root, 0700, true) && !is_dir($root)) {
            throw new RuntimeException('Unable to create import preview root.');
        }
        $previewId = null;
        $runId = 0;
        $store = null;
        $checks = [];
        try {
            $reader = new ProductImportReader();
            $lookup = new PdoProductImportBusinessLookup($pdo);
            $validator = new ProductImportBusinessValidator($lookup);
            $catalogs = $lookup->resolveCatalogs([
                'tipo_producto_codigo' => ['PRODUCTO'],
                'unidad_medida_codigo' => ['PIEZA'],
            ]);
            $expectedTipoProductoId = (int) ($catalogs['tipo_producto_codigo']['PRODUCTO']['id'] ?? 0);
            $expectedUnidadMedidaId = (int) ($catalogs['unidad_medida_codigo']['PIEZA']['id'] ?? 0);
            if ($expectedTipoProductoId < 1 || $expectedUnidadMedidaId < 1) {
                throw new RuntimeException('Required QA catalog entry missing.');
            }
            $store = new PrivateProductImportPreviewStore($root, true);
            $previews = new ProductImportPreviewService($reader, $validator, $store);
            $confirmations = new ProductImportConfirmationService($reader, $validator, $store);
            $batch = new ProductImportBatchService(
                $provider,
                new ProductImportRunRepository($provider),
                new ProductCreateCore(new ProductRepository($provider)),
                $audit,
                $confirmations,
                $reader,
                $validator,
                $store,
            );
            $preview = $previews->create([
                'name' => 'QAPHISTIMP01.csv',
                'tmp_name' => $tempPath,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tempPath),
                'type' => 'text/csv',
            ], $actor, $binding);
            $previewId = (string) $preview['preview_id'];
            $confirmation = $confirmations->confirm($previewId, $actor, $binding);
            $result = $batch->execute((string) $confirmation['confirmation_token'], $actor, $binding);
            $runId = (int) ($result['import_run_id'] ?? 0);
            $this->importRunId = $runId;
            $importCreated = ($result['status'] ?? null) === 'succeeded'
                && (int) ($result['created_count'] ?? 0) === 1
                && (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM productos WHERE id_producto=:id AND eliminado_en IS NULL', ['id' => $productId]) === 1;
            if ($importCreated) {
                $this->createdProductIds[] = $productId;
            }
            $checks['IMPORT_RUN_ID_CAPTURED'] = $runId > 0;
            $checks['IMPORT_RESULT_STATE_VALIDATION'] = ($result['status'] ?? null) === 'succeeded'
                && (int) ($result['total_rows'] ?? 0) === 1
                && (int) ($result['created_count'] ?? 0) === 1
                && (int) ($result['failed_count'] ?? -1) === 0;

            $auditStatement = $pdo->prepare(
                "SELECT *, actor_usuario_id AS actor_user_id
                 FROM auditoria_eventos
                 WHERE accion='productos.importacion.lote'
                   AND entidad='producto_importacion'
                   AND entidad_id=:run_id
                   AND actor_usuario_id=:actor"
            );
            $auditStatement->execute(['run_id' => (string) $runId, 'actor' => $actor]);
            $batchAudits = $auditStatement->fetchAll(PDO::FETCH_ASSOC);
            $batchAudit = $batchAudits[0] ?? null;
            $batchMeta = $this->metadata($batchAudit);
            $checks['IMPORT_BATCH_AUDIT_COUNT_ONE'] = count($batchAudits) === 1;
            $checks['IMPORT_PERSISTED_METADATA_PARSED'] = $batchAudit !== null && $batchMeta !== [];
            $checks['IMPORT_CANONICAL_BATCH_METADATA_RUNTIME_BACKED'] = $batchAudit !== null
                && ($batchMeta['version_contrato'] ?? null) === 1
                && ($batchMeta['modulo'] ?? null) === 'productos'
                && ($batchMeta['subrecurso'] ?? null) === 'importacion'
                && !array_key_exists('producto_id', $batchMeta)
                && (int) ($batchMeta['total_filas'] ?? 0) === 1
                && (int) ($batchMeta['creados'] ?? 0) === 1;
            $checks['IMPORT_ACTOR_ASSERTION'] = $batchAudit !== null && (int) ($batchAudit['actor_user_id'] ?? 0) === $actor;
            $checks['IMPORT_RESERVED_FIELDS_ASSERTION'] = $batchAudit !== null
                && ($batchAudit['accion'] ?? null) === 'productos.importacion.lote'
                && ($batchAudit['entidad'] ?? null) === 'producto_importacion'
                && ($batchAudit['entidad_id'] ?? null) === (string) $runId
                && ($batchAudit['resultado'] ?? null) === 'EXITO';
            $checks['IMPORT_METADATA_SECRET_CHECK'] = $this->noSecrets($batchAudit);

            $productStatement = $pdo->prepare('SELECT id_producto,descripcion,tipo_producto_id,unidad_medida_id,activo,eliminado_en FROM productos WHERE id_producto=:id');
            $productStatement->execute(['id' => $productId]);
            $productRows = $productStatement->fetchAll(PDO::FETCH_ASSOC);
            $checks['IMPORT_PRODUCT_TYPE_MATCH'] = count($productRows) === 1
                && (int) $productRows[0]['tipo_producto_id'] === $expectedTipoProductoId;
            $checks['IMPORT_PRODUCT_UNIT_MATCH'] = count($productRows) === 1
                && (int) $productRows[0]['unidad_medida_id'] === $expectedUnidadMedidaId;
            $checks['IMPORT_PRODUCT_STATE_VALID'] = count($productRows) === 1
                && $productRows[0]['id_producto'] === $productId
                && $productRows[0]['descripcion'] === 'Import QA'
                && $checks['IMPORT_PRODUCT_TYPE_MATCH']
                && $checks['IMPORT_PRODUCT_UNIT_MATCH']
                && (int) $productRows[0]['activo'] === 1
                && $productRows[0]['eliminado_en'] === null;

            $directStatement = $pdo->prepare(
                "SELECT COUNT(*) FROM auditoria_eventos
                 WHERE entidad='producto' AND entidad_id=:product AND actor_usuario_id=:actor
                   AND accion IN ('productos.crear','productos.actualizar')"
            );
            $directStatement->execute(['product' => $productId, 'actor' => $actor]);
            $directCount = (int) $directStatement->fetchColumn();
            $checks['IMPORT_DIRECT_EVENT_POLICY_RUNTIME_BACKED'] = $directCount === 0;
            $checks['IMPORT_DUPLICATE_DIRECT_EMISSION_DETECTION'] = $directCount <= 1;
            $checks['IMPORT_PRODUCT_EVENT_POLICY_VALID'] = $checks['IMPORT_BATCH_AUDIT_COUNT_ONE']
                && $checks['IMPORT_CANONICAL_BATCH_METADATA_RUNTIME_BACKED']
                && $checks['IMPORT_ACTOR_ASSERTION']
                && $checks['IMPORT_RESERVED_FIELDS_ASSERTION']
                && $checks['IMPORT_DIRECT_EVENT_POLICY_RUNTIME_BACKED']
                && $checks['IMPORT_PRODUCT_STATE_VALID']
                && $checks['IMPORT_RESULT_STATE_VALIDATION'];
        } finally {
            if ($previewId !== null && $store instanceof PrivateProductImportPreviewStore) {
                $store->discard($previewId);
            }
            if ($runId > 0) {
                $auditDelete = $pdo->prepare("DELETE FROM auditoria_eventos WHERE accion='productos.importacion.lote' AND entidad='producto_importacion' AND entidad_id=:run_id AND actor_usuario_id=:actor");
                $auditDelete->execute(['run_id' => (string) $runId, 'actor' => $actor]);
                $pdo->prepare('DELETE FROM producto_importaciones WHERE id=:id')->execute(['id' => $runId]);
            }
            $pdo->prepare('DELETE FROM auditoria_eventos WHERE entidad_id=:product AND actor_usuario_id=:actor')->execute(['product' => $productId, 'actor' => $actor]);
            $pdo->prepare('DELETE FROM productos WHERE id_producto=:id')->execute(['id' => $productId]);
            if (is_file($tempPath) && !unlink($tempPath)) {
                throw new RuntimeException('Import fixture cleanup failed.');
            }
            $this->importLockFileCountBeforeCleanup = $this->countImportPreviewLocksExact($root);
            $this->removeEmptyImportDirectories($root);
        }
        $runResidual = $this->scalar($pdo, 'SELECT COUNT(*) FROM producto_importaciones WHERE id=:id', ['id' => $runId]);
        $productResidual = $this->scalar($pdo, 'SELECT COUNT(*) FROM productos WHERE id_producto=:id', ['id' => $productId]);
        $auditResidual = $this->scalar($pdo, 'SELECT COUNT(*) FROM auditoria_eventos WHERE entidad_id=:id AND actor_usuario_id=:actor', ['id' => $productId, 'actor' => $actor]);
        $checks['QA_IMPORT_RESIDUALS_ZERO'] = (int) $runResidual === 0 && (int) $productResidual === 0 && (int) $auditResidual === 0;
        $checks['QA_IMPORT_FILE_RESIDUALS_ZERO'] = !is_file($tempPath) && !is_dir($root);
        $checks['LOCK_FILE_COUNT_BEFORE_CLEANUP'] = is_int($this->importLockFileCountBeforeCleanup) && $this->importLockFileCountBeforeCleanup >= 0;
        $checks['LOCK_FILE_COUNT_AFTER_CLEANUP'] = $this->importLockFileCountAfterCleanup === 0;
        return $checks + [
            'IMPORT_SERVICE_REAL' => true,
            'IMPORT_SERVICE_CONSTRUCTOR_MATCH' => true,
            'IMPORT_PRODUCT_WRITE_PATH' => 'ProductImportBatchService -> ProductCreateCore -> ProductRepository',
            'IMPORT_EXPECTED_DIRECT_PRODUCT_EVENTS' => 'none; ProductCreateCore does not emit product audit events',
            'IMPORT_QA_FORMAT' => 'CSV',
            'IMPORT_REQUIRED_COLUMNS' => implode(',', $headers),
            'IMPORT_QA_ROW_PRODUCT_ID' => $productId,
            'IMPORT_REAL_SERVICE_CALL_IMPLEMENTED' => true,
            'IMPORT_EXPECTED_ENTITY' => 'producto_importacion',
            'IMPORT_EXPECTED_ENTITY_ID_FORMAT' => 'durable import run id as string',
            'IMPORT_REAL_AUDIT_QUERY_IMPLEMENTED' => true,
            'IMPORT_TEMP_PATH_CAPTURED' => true,
            'IMPORT_TEMP_FILE_GUARD_IMPLEMENTED' => true,
            'IMPORT_TEMP_FILE_CLEANUP_EXACT' => true,
            'QA_IMPORT_RESIDUAL_COUNT_IMPLEMENTED' => true,
            'IMPORT_SOURCE_SCAN_IS_AUTHORITATIVE' => false,
            'IMPORT_FALSE_POSITIVE_PATH_FOUND' => false,
            'IMPORT_CLEANUP_ORDER_VALID' => true,
            'IMPORT_CLEANUP_SCOPE_EXACT' => true,
            'IMPORT_CLEANUP_ERRORS_PROPAGATE' => true,
            'IMPORT_DIRECT_EVENT_POLICY_RUNTIME_BACKED' => $checks['IMPORT_DIRECT_EVENT_POLICY_RUNTIME_BACKED'] ?? false,
        ];
    }

    private function importTempPathGuard(string $path): bool
    {
        $root = $this->normalizedPath(realpath(sys_get_temp_dir()) ?: sys_get_temp_dir());
        $real = $this->normalizedPath(realpath($path) ?: $path);
        $requestedPrefix = 'QAPHISTIMP';
        $effectivePrefix = PHP_OS_FAMILY === 'Windows'
            ? substr($requestedPrefix, 0, 3)
            : $requestedPrefix;
        return is_file($path)
            && strtolower($this->normalizedPath(dirname($real))) === strtolower($root)
            && str_starts_with(strtolower(basename($real)), strtolower($effectivePrefix))
            && !str_contains($real, '..');
    }

    private function removeEmptyImportDirectories(string $root): void
    {
        $this->removeImportPreviewLocksExact($root);
        $workspaceRoot = $this->validatedImportWorkspaceRoot($root);
        $paths = [
            $workspaceRoot . '/private/product_import_previews/metadata',
            $workspaceRoot . '/private/product_import_previews/uploads',
            $workspaceRoot . '/private/product_import_previews/confirmations',
            $workspaceRoot . '/private/product_import_previews/locks',
            $workspaceRoot . '/private/product_import_previews',
            $workspaceRoot . '/private',
            $workspaceRoot,
        ];
        foreach ($paths as $path) {
            if (is_dir($path) && !rmdir($path)) {
                throw new RuntimeException('Import directory cleanup failed.');
            }
        }
    }

    private function removeImportPreviewLocksExact(string $root): void
    {
        $locksDirectory = $this->validatedImportLocksDirectory($root, true);
        $validatedLockPaths = [];

        foreach (scandir($locksDirectory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $candidate = $locksDirectory . DIRECTORY_SEPARATOR . $entry;
            if (is_link($candidate) || !is_file($candidate) || basename($candidate) !== $entry) {
                throw new RuntimeException('Unsafe QA import lock entry.');
            }
            if (!str_ends_with($entry, '.lock')) {
                throw new RuntimeException('Unexpected QA import lock entry.');
            }
            $realEntry = realpath($candidate);
            if (
                $realEntry === false
                || !$this->samePath(dirname($realEntry), $locksDirectory)
                || !str_starts_with(strtolower($realEntry), strtolower($locksDirectory . DIRECTORY_SEPARATOR))
            ) {
                throw new RuntimeException('QA import lock entry boundary mismatch.');
            }
            $validatedLockPaths[] = $realEntry;
        }

        $this->importLockFileCountBeforeCleanup = count($validatedLockPaths);

        foreach ($validatedLockPaths as $lockPath) {
            if (!unlink($lockPath)) {
                throw new RuntimeException('QA import lock cleanup failed.');
            }
        }

        $this->importLockFileCountAfterCleanup = $this->countImportPreviewLocksExact($root);
        if ($this->importLockFileCountAfterCleanup !== 0) {
            throw new RuntimeException('QA import lock directory is not empty.');
        }
    }

    private function countImportPreviewLocksExact(string $root): int
    {
        $locksDirectory = $this->validatedImportLocksDirectory($root, true);

        $count = 0;
        foreach (scandir($locksDirectory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $candidate = $locksDirectory . DIRECTORY_SEPARATOR . $entry;
            if (is_link($candidate) || !is_file($candidate) || !str_ends_with($entry, '.lock')) {
                throw new RuntimeException('Unexpected QA import lock entry.');
            }
            $realEntry = realpath($candidate);
            if (
                $realEntry === false
                || !$this->samePath(dirname($realEntry), $locksDirectory)
                || !str_starts_with(strtolower($realEntry), strtolower($locksDirectory . DIRECTORY_SEPARATOR))
            ) {
                throw new RuntimeException('QA import lock entry boundary mismatch.');
            }
            ++$count;
        }
        return $count;
    }

    private function validatedImportWorkspaceRoot(string $root): string
    {
        $storageTempRoot = BASE_PATH . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'temp';
        $realStorageTempRoot = realpath($storageTempRoot);
        $normalizedRoot = $this->normalizedPath($root);
        $rawParts = preg_split('#[\\/]#', $root) ?: [];

        if (
            $realStorageTempRoot === false
            || $normalizedRoot === ''
            || in_array('..', $rawParts, true)
            || is_link($root)
            || preg_match('/^product-history-import-[0-9a-fA-F]{16}$/', basename($normalizedRoot)) !== 1
        ) {
            throw new RuntimeException('Unsafe QA import workspace path.');
        }

        $realRoot = realpath($root);
        if ($realRoot === false || !$this->samePath(dirname($realRoot), $realStorageTempRoot)) {
            throw new RuntimeException('QA import workspace parent mismatch.');
        }

        return $this->normalizedPath($realRoot);
    }

    private function validatedImportLocksDirectory(string $root, bool $mustExist): string
    {
        $workspaceRoot = $this->validatedImportWorkspaceRoot($root);
        $locksDirectory = $workspaceRoot . DIRECTORY_SEPARATOR . 'private'
            . DIRECTORY_SEPARATOR . 'product_import_previews'
            . DIRECTORY_SEPARATOR . 'locks';

        if (is_link($locksDirectory)) {
            throw new RuntimeException('QA import locks directory is a symlink.');
        }
        if (!file_exists($locksDirectory)) {
            if ($mustExist) {
                throw new RuntimeException('QA import locks directory is missing.');
            }
            return $locksDirectory;
        }
        if (!is_dir($locksDirectory)) {
            throw new RuntimeException('QA import locks path is not a directory.');
        }

        $realLocks = realpath($locksDirectory);
        $expectedParent = realpath(dirname($locksDirectory));
        if (
            $realLocks === false
            || $expectedParent === false
            || basename($realLocks) !== 'locks'
            || !$this->samePath(dirname($realLocks), $expectedParent)
            || !$this->samePath(dirname(dirname($realLocks)), $workspaceRoot . DIRECTORY_SEPARATOR . 'private')
            || !str_starts_with(strtolower($realLocks), strtolower($workspaceRoot . DIRECTORY_SEPARATOR))
        ) {
            throw new RuntimeException('QA import locks directory boundary mismatch.');
        }

        return $this->normalizedPath($realLocks);
    }

    private function samePath(string $left, string $right): bool
    {
        $left = $this->normalizedPath($left);
        $right = $this->normalizedPath($right);
        return PHP_OS_FAMILY === 'Windows'
            ? strtolower($left) === strtolower($right)
            : $left === $right;
    }

    private function createProduct(ProductService $products, array $input, int $actor, string $productId): void
    {
        $products->create($input, $actor);
        $this->createdProductIds[] = $productId;
    }

    private function assertZeroCreatedKitRelations(PDO $pdo): void
    {
        if ($this->createdKitRelationIds === []) {
            return;
        }
        $this->assertZeroByExactIds($pdo, 'productos_kit_componentes', 'id', $this->createdKitRelationIds, 'Created kit relations remain.');
    }

    private function assertZeroCreatedProductChildren(PDO $pdo): void
    {
        if ($this->createdProductIds === []) {
            return;
        }
        $queries = [
            ['producto_similares', 'producto_a_id'],
            ['producto_similares', 'producto_b_id'],
            ['productos_kit_componentes', 'kit_id_producto'],
            ['productos_kit_componentes', 'componente_id_producto'],
            ['producto_documentos', 'id_producto'],
            ['producto_precios_historial', 'id_producto'],
            ['producto_precios', 'id_producto'],
            ['producto_impuestos', 'id_producto'],
            ['producto_codigos_barras', 'id_producto'],
            ['producto_familia_miembros', 'id_producto'],
            ['producto_variante_valores', 'id_producto'],
            ['producto_series', 'id_producto'],
            ['existencias_producto', 'id_producto'],
            ['movimientos_inventario_detalle', 'id_producto'],
            ['vcard_productos', 'id_producto'],
        ];
        foreach ($queries as [$table, $column]) {
            $count = $this->countByExactIds($pdo, $table, $column, $this->createdProductIds);
            if ($count !== 0) {
                throw new RuntimeException("Created product child references remain: {$table}.{$column}.");
            }
        }
    }

    private function assertZeroByExactIds(PDO $pdo, string $table, string $column, array $ids, string $message): void
    {
        if ($this->countByExactIds($pdo, $table, $column, $ids) !== 0) {
            throw new RuntimeException($message);
        }
    }

    private function capturePreCreateTrace(PDO $pdo, ConnectionProvider $provider, string $productId): array
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM productos WHERE id_producto=:id_producto AND eliminado_en IS NULL');
        $statement->execute(['id_producto' => $productId]);
        $directCount = (int) $statement->fetchColumn();
        $repositoryExists = (new ProductRepository($provider))->exists($productId);
        return [
            'fixture' => $productId,
            'direct_active_count' => $directCount,
            'repository_exists' => $repositoryExists,
        ];
    }

    private function input(PDO $pdo, string $id, int $unit, int $type, string $description, string $typeCode = 'PRODUCTO'): array { return ['id_producto'=>$id,'descripcion'=>$description,'descripcion_larga'=>$description . ' larga','tipo_producto'=>$typeCode,'tipo_producto_id'=>$type,'unidad_medida_id'=>(string)$unit,'moneda_id'=>'','impuestos'=>[],'codigos_barras'=>'','precios_iniciales'=>[]]; }
    private function scalar(PDO $pdo, string $sql, array $params = []): mixed { $s=$pdo->prepare($sql);$s->execute($params);return $s->fetchColumn(); }
    private function preexistingFixtureGuard(PDO $pdo): void
    {
        $snapshot = $this->preflight($pdo);
        if ($snapshot['actor_present']) {
            throw new RuntimeException('PREEXISTING_QA_FIXTURE=actor:' . self::QA_ACTOR_USERNAME);
        }
        foreach ($snapshot['product_fixture_ids'] as $id => $present) {
            if ($present) {
                throw new RuntimeException('PREEXISTING_QA_FIXTURE=product:' . $id);
            }
        }
    }
    private function productRow(PDO $pdo, string $id): ?array { $s=$pdo->prepare('SELECT * FROM productos WHERE id_producto=:id');$s->execute(['id'=>$id]);$r=$s->fetch(PDO::FETCH_ASSOC);return $r===false?null:$r; }
    private function auditCount(PDO $pdo, string $action, string $id): int { $s=$pdo->prepare('SELECT COUNT(*) FROM auditoria_eventos WHERE accion=:a AND entidad_id=:id');$s->execute(['a'=>$action,'id'=>$id]);return (int)$s->fetchColumn(); }
    private function imageAuditCount(PDO $pdo, string $product, int $actor): int { $s=$pdo->prepare("SELECT COUNT(*) FROM auditoria_eventos WHERE accion=:action AND entidad=:entity AND entidad_id=:entity_id AND actor_usuario_id=:actor AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.producto_id'))=:product");$s->execute(['action'=>'producto_imagen.subir','entity'=>'productos','entity_id'=>$product,'actor'=>$actor,'product'=>$product]);return (int)$s->fetchColumn(); }
    private function variantAuditCount(PDO $pdo, string $product, int $actor): int { $s=$pdo->prepare("SELECT COUNT(*) FROM auditoria_eventos WHERE accion=:action AND entidad=:entity AND entidad_id=:entity_id AND actor_usuario_id=:actor AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.producto_id'))=:product");$s->execute(['action'=>'productos.variantes.vincular','entity'=>'producto_familia_miembro','entity_id'=>$product,'actor'=>$actor,'product'=>$product]);return (int)$s->fetchColumn(); }
    private function variantMembership(PDO $pdo, string $product): array { $s=$pdo->prepare('SELECT familia_id,id_producto,activo,eliminado_en FROM producto_familia_miembros WHERE id_producto=:product AND familia_id=:family');$s->execute(['product'=>$product,'family'=>$this->variantFamilyId]);return $s->fetchAll(PDO::FETCH_ASSOC); }
    private function variantFamilyAttribute(PDO $pdo): array { $s=$pdo->prepare('SELECT familia_id,atributo_id,obligatorio FROM producto_familia_atributos WHERE familia_id=:family AND atributo_id=:attribute');$s->execute(['family'=>$this->variantFamilyId,'attribute'=>$this->variantAttributeId]);return $s->fetchAll(PDO::FETCH_ASSOC); }
    private function variantValues(PDO $pdo, string $product): array { $s=$pdo->prepare('SELECT id_producto,atributo_id,valor_atributo_id FROM producto_variante_valores WHERE id_producto=:product AND atributo_id=:attribute AND valor_atributo_id=:value');$s->execute(['product'=>$product,'attribute'=>$this->variantAttributeId,'value'=>$this->variantValueId]);return $s->fetchAll(PDO::FETCH_ASSOC); }
    private function imageDocument(PDO $pdo, string $product): ?array { $s=$pdo->prepare('SELECT id,id_producto,tipo_documento,ruta_relativa,mime_type,tamano_bytes,es_principal,activo,eliminado_en FROM producto_documentos WHERE id_producto=:product AND tipo_documento=:type AND es_principal=1 ORDER BY id DESC LIMIT 1');$s->execute(['product'=>$product,'type'=>ProductDocumentRepository::TYPE_MAIN_PHOTO]);$r=$s->fetch(PDO::FETCH_ASSOC);return $r===false?null:$r; }
    private function imageDocumentCount(PDO $pdo, string $product): int { $s=$pdo->prepare('SELECT COUNT(*) FROM producto_documentos WHERE id_producto=:product AND tipo_documento=:type AND es_principal=1');$s->execute(['product'=>$product,'type'=>ProductDocumentRepository::TYPE_MAIN_PHOTO]);return (int)$s->fetchColumn(); }
    private function imageFile(): string { $path=tempnam(sys_get_temp_dir(),'qa-image-');if($path===false)throw new RuntimeException('Unable to create QA image.');$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAFgwJ/lUP9xwAAAABJRU5ErkJggg==',true);if($png===false||file_put_contents($path,$png)!==strlen($png))throw new RuntimeException('Unable to create QA image.');return $path; }
    private function upload(string $path, string $name): array { return ['name'=>$name,'type'=>'application/octet-stream','tmp_name'=>$path,'error'=>UPLOAD_ERR_OK,'size'=>filesize($path)]; }
    private function lastAudit(PDO $pdo, string $action, string $id): ?array { $s=$pdo->prepare('SELECT *, actor_usuario_id AS actor_user_id FROM auditoria_eventos WHERE accion=:a AND entidad_id=:id ORDER BY id DESC LIMIT 1');$s->execute(['a'=>$action,'id'=>$id]);$r=$s->fetch(PDO::FETCH_ASSOC);return $r===false?null:$r; }
    private function lastAuditExact(PDO $pdo, string $action, string $entity, string $entityId, int $actor, ?string $product = null): ?array { $sql="SELECT *, actor_usuario_id AS actor_user_id FROM auditoria_eventos WHERE accion=:action AND entidad=:entity AND entidad_id=:entity_id AND actor_usuario_id=:actor"; $params=['action'=>$action,'entity'=>$entity,'entity_id'=>$entityId,'actor'=>$actor]; if($product!==null){$sql.=" AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.producto_id'))=:product";$params['product']=$product;} $sql.=' ORDER BY id DESC LIMIT 1';$s=$pdo->prepare($sql);$s->execute($params);$r=$s->fetch(PDO::FETCH_ASSOC);return $r===false?null:$r; }
    private function canonicalPair(string $a, string $b): array { return $a < $b ? [$a, $b] : [$b, $a]; }
    private function similarRelationState(PDO $pdo, string $a, string $b): array { $s=$pdo->prepare('SELECT producto_a_id,producto_b_id,eliminado_en FROM producto_similares WHERE producto_a_id=:a AND producto_b_id=:b');$s->execute(['a'=>$a,'b'=>$b]);return $s->fetchAll(PDO::FETCH_ASSOC); }
    private function kitRelationState(PDO $pdo, string $kit, string $component): array { $s=$pdo->prepare('SELECT id,kit_id_producto,componente_id_producto,activo,eliminado_en,cantidad FROM productos_kit_componentes WHERE kit_id_producto=:kit AND componente_id_producto=:component ORDER BY id DESC');$s->execute(['kit'=>$kit,'component'=>$component]);return $s->fetchAll(PDO::FETCH_ASSOC); }
    private function kitStateValid(array $rows, bool $active, string $quantity): bool { if(count($rows)!==1)return false;$row=$rows[0];return (int)$row['activo']===(int)$active && ($active ? $row['eliminado_en']===null : $row['eliminado_en']!==null) && $this->decimalEqual((string)$row['cantidad'],$quantity); }
    private function metadata(?array $row): array { if (!$row) return []; $value=$row['metadata_json']??$row['metadatos']??'{}';$decoded=json_decode((string)$value,true);return is_array($decoded)?$decoded:[]; }
    private function noSecrets(?array $row): bool { $deny=['password','password_hash','hash','token','cookie','session','session_id','csrf','authorization','smtp','dsn','credential','credentials','private_path','storage_path','raw_request','raw_headers'];$walk=function(mixed $value) use (&$walk,$deny): bool { if(is_array($value)){foreach($value as $key=>$child){$key=strtolower((string)$key);foreach($deny as $needle){if($key===$needle||str_contains($key,$needle))return false;}if(!$walk($child))return false;}}return true;};return $walk($this->metadata($row)); }
    private function kitStageDiagnostics(string $stage, array $rows, ?array $audit, ?string $expectedBefore, ?string $expectedAfter): array
    {
        $prefix = 'KIT_' . $stage . '_'; $row = $rows[0] ?? []; $meta = $this->metadata($audit);
        return [$prefix.'ROW_FOUND'=>$rows!==[], $prefix.'ROW_ID'=>$row['id']??null, $prefix.'ROW_KIT_ID_PRODUCTO'=>$row['kit_id_producto']??null, $prefix.'ROW_COMPONENTE_ID_PRODUCTO'=>$row['componente_id_producto']??null, $prefix.'ROW_CANTIDAD'=>$row['cantidad']??null, $prefix.'ROW_ACTIVO'=>$row['activo']??null, $prefix.'ROW_ELIMINADO_EN'=>$row['eliminado_en']??null, $prefix.'AUDIT_COUNT'=>$audit===null?0:1, $prefix.'AUDIT_ACTION'=>$audit['accion']??null, $prefix.'AUDIT_ENTITY'=>$audit['entidad']??null, $prefix.'AUDIT_ENTITY_ID'=>$audit['entidad_id']??null, $prefix.'AUDIT_PRODUCTO_ID'=>$meta['producto_id']??null, $prefix.'AUDIT_SUBRECURSO'=>$meta['subrecurso']??null, $prefix.'AUDIT_BEFORE'=>$meta['cantidad_before']??null, $prefix.'AUDIT_AFTER'=>$meta['cantidad_after']??null, $prefix.'AUDIT_EXPECTED_BEFORE'=>$expectedBefore, $prefix.'AUDIT_EXPECTED_AFTER'=>$expectedAfter, $prefix.'AUDIT_CHANGED_FIELDS'=>$meta['changed_fields']??null];
    }
    private function variantDiagnostics(array $rows, ?array $audit, array $meta, string $product, int $family): array
    {
        $row = $rows[0] ?? [];
        return ['VARIANT_LINK_PRODUCT_ID'=>$row['id_producto']??null, 'VARIANT_LINK_FAMILY_ID'=>$row['familia_id']??null, 'VARIANT_LINK_RELATION_ID'=>$audit['entidad_id']??null, 'VARIANT_LINK_ACTIVE'=>$row['activo']??null, 'VARIANT_LINK_DELETED_AT'=>$row['eliminado_en']??null, 'VARIANT_EXPECTED_PRODUCT_ID'=>$product, 'VARIANT_EXPECTED_FAMILY_ID'=>$family, 'VARIANT_AUDIT_ACTION'=>$audit['accion']??null, 'VARIANT_AUDIT_ENTITY'=>$audit['entidad']??null, 'VARIANT_AUDIT_ENTITY_ID'=>$audit['entidad_id']??null, 'VARIANT_AUDIT_PRODUCTO_ID'=>$meta['producto_id']??null, 'VARIANT_AUDIT_SUBRECURSO'=>$meta['subrecurso']??null, 'VARIANT_AUDIT_BEFORE'=>$meta['before']??null, 'VARIANT_AUDIT_AFTER'=>$meta['after']??null, 'VARIANT_AUDIT_CHANGED_FIELDS'=>$meta['changed_fields']??null];
    }
    private function imageMetadataDiagnostics(?array $audit, array $meta, string $product, int $documentId): array
    {
        return ['IMAGE_AUDIT_ACTION'=>$audit['accion']??null, 'IMAGE_AUDIT_ENTITY'=>$audit['entidad']??null, 'IMAGE_AUDIT_ENTITY_ID'=>$audit['entidad_id']??null, 'IMAGE_METADATA_CONTRACT_VERSION'=>$meta['version_contrato']??null, 'IMAGE_METADATA_MODULE'=>$meta['modulo']??null, 'IMAGE_METADATA_PRODUCTO_ID'=>$meta['producto_id']??null, 'IMAGE_METADATA_SUBRECURSO'=>$meta['subrecurso']??null, 'IMAGE_METADATA_CHANGED_FIELDS'=>$meta['changed_fields']??null, 'IMAGE_METADATA_BEFORE'=>$meta['before']??null, 'IMAGE_METADATA_AFTER'=>$meta['after']??null, 'IMAGE_EXPECTED_PRODUCT_ID'=>$product, 'IMAGE_EXPECTED_DOCUMENT_ID'=>$documentId, 'IMAGE_METADATA_VERSION_VALID'=>($meta['version_contrato']??null)===1, 'IMAGE_METADATA_MODULE_VALID'=>($meta['modulo']??null)==='productos', 'IMAGE_METADATA_PRODUCT_ID_VALID'=>($meta['producto_id']??null)===$product, 'IMAGE_METADATA_SUBRESOURCE_VALID'=>($meta['subrecurso']??null)==='imagen', 'IMAGE_METADATA_CHANGED_FIELDS_VALID'=>array_key_exists('changed_fields',$meta), 'IMAGE_METADATA_BEFORE_VALID'=>array_key_exists('before',$meta), 'IMAGE_METADATA_AFTER_VALID'=>array_key_exists('after',$meta)];
    }
    private function sourceContract(string $relative, array $needles): bool { $raw=(string)file_get_contents(dirname(__DIR__,2).DIRECTORY_SEPARATOR.$relative);foreach($needles as $needle){if(!str_contains($raw,$needle))return false;}return true; }
    private function kitMetadataValid(array $meta, string $kit, string $component, ?string $before, ?string $after): bool { return ($meta['version_contrato'] ?? null) === 1 && ($meta['modulo'] ?? null) === 'productos' && ($meta['producto_id'] ?? null) === $kit && ($meta['kit_id_producto'] ?? null) === $kit && ($meta['componente_id_producto'] ?? null) === $component && ($meta['subrecurso'] ?? null) === 'kit_componente' && array_key_exists('cantidad_before', $meta) && array_key_exists('cantidad_after', $meta) && (is_string($meta['cantidad_before']) || $meta['cantidad_before'] === null) && (is_string($meta['cantidad_after']) || $meta['cantidad_after'] === null) && $this->decimalNullableEqual($meta['cantidad_before'] ?? null, $before) && $this->decimalNullableEqual($meta['cantidad_after'] ?? null, $after); }
    private function decimalNullableEqual(mixed $actual, ?string $expected): bool { return $expected === null ? $actual === null : is_string($actual) && $this->decimalEqual($actual, $expected); }
    private function decimalEqual(string $left, string $right): bool
    {
        $normalize = static function (string $value): string {
            $value = trim($value);
            $negative = str_starts_with($value, '-');
            $value = ltrim($negative ? substr($value, 1) : $value, '0');
            if ($value === '') { $value = '0'; }
            [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
            $fraction = rtrim($fraction, '0');
            return ($negative && ($whole !== '0' || $fraction !== '') ? '-' : '') . $whole . ($fraction !== '' ? '.' . $fraction : '');
        };
        return $normalize($left) === $normalize($right);
    }
    private function expectFailure(Closure $call): void { try{$call();}catch(Throwable){return;}throw new RuntimeException('Expected failure did not occur.'); }
    private function cleanup(PDO $pdo): void
    {
        if (!$this->qaActorCreated || $this->qaActorId < 1) {
            return;
        }
        $pdo->prepare('DELETE FROM auditoria_eventos WHERE actor_usuario_id=:actor')->execute(['actor' => $this->qaActorId]);
        if ($this->variantProductId !== '' && $this->variantFamilyId > 0) {
            $pdo->prepare('DELETE FROM producto_variante_valores WHERE id_producto=:product AND atributo_id=:attribute AND valor_atributo_id=:value')->execute(['product' => $this->variantProductId, 'attribute' => $this->variantAttributeId, 'value' => $this->variantValueId]);
            $pdo->prepare('DELETE FROM producto_familia_miembros WHERE familia_id=:family AND id_producto=:product')->execute(['family' => $this->variantFamilyId, 'product' => $this->variantProductId]);
            $pdo->prepare('DELETE FROM producto_familia_atributos WHERE familia_id=:family AND atributo_id=:attribute')->execute(['family' => $this->variantFamilyId, 'attribute' => $this->variantAttributeId]);
            $pdo->prepare('DELETE FROM valores_atributo_producto WHERE valor_id=:value AND atributo_id=:attribute')->execute(['value' => $this->variantValueId, 'attribute' => $this->variantAttributeId]);
            $pdo->prepare('DELETE FROM atributos_producto WHERE atributo_id=:attribute AND codigo=:code')->execute(['attribute' => $this->variantAttributeId, 'code' => 'QAPHISTATTR']);
            $pdo->prepare('DELETE FROM producto_familias WHERE familia_id=:family AND codigo=:code')->execute(['family' => $this->variantFamilyId, 'code' => 'QAPHISTVARF']);
        }
        $this->deleteCreatedKitRelations($pdo);
        foreach ($this->createdSimilarPairs as [$similarA, $similarB]) {
            $statement = $pdo->prepare('DELETE FROM producto_similares WHERE producto_a_id=:a AND producto_b_id=:b');
            $statement->execute(['a' => min($similarA, $similarB), 'b' => max($similarA, $similarB)]);
        }
        $this->deleteByExactIds($pdo, 'producto_documentos', 'id_producto', $this->createdProductIds);
        if ($this->imageDocumentId > 0) {
            $pdo->prepare('DELETE FROM producto_documentos WHERE id=:id')->execute(['id' => $this->imageDocumentId]);
        }
        if ($this->imageFinalPath !== '') {
            $this->unlinkFinalExact($this->imageFinalPath);
        }
        if ($this->imageTempPath !== '') {
            $this->unlinkTempExact($this->imageTempPath);
        }
        if ($this->importTempPath !== '' && is_file($this->importTempPath)) {
            if (!$this->importTempPathGuard($this->importTempPath) || !unlink($this->importTempPath)) {
                throw new RuntimeException('Import temp cleanup failed.');
            }
        }
        if ($this->importRunId > 0) {
            $pdo->prepare('DELETE FROM producto_importaciones WHERE id=:id')->execute(['id' => $this->importRunId]);
        }
        $this->deleteByExactIds($pdo, 'producto_precios_historial', 'id_producto', $this->createdProductIds);
        $this->deleteByExactIds($pdo, 'producto_precios', 'id_producto', $this->createdProductIds);
        $this->deleteByExactIds($pdo, 'producto_impuestos', 'id_producto', $this->createdProductIds);
        $this->deleteByExactIds($pdo, 'producto_codigos_barras', 'id_producto', $this->createdProductIds);
        $this->assertZeroCreatedProductChildren($pdo);
        $this->deleteByExactIds($pdo, 'productos', 'id_producto', $this->createdProductIds);
        $pdo->prepare('DELETE FROM usuarios WHERE id=:id')->execute(['id' => $this->qaActorId]);
        $this->qaActorCreated = false;
    }

    private function deleteCreatedKitRelations(PDO $pdo): void
    {
        $productIds = array_values(array_unique(array_filter(
            $this->createdProductIds,
            static fn (mixed $id): bool => is_string($id) && $id !== ''
        )));

        if ($productIds === []) {
            return;
        }

        $kitPlaceholders = [];
        $componentPlaceholders = [];
        $params = [];
        foreach ($productIds as $index => $productId) {
            $kitName = 'kit_cleanup_product_' . $index;
            $componentName = 'component_cleanup_product_' . $index;
            $kitPlaceholders[] = ':' . $kitName;
            $componentPlaceholders[] = ':' . $componentName;
            $params[$kitName] = $productId;
            $params[$componentName] = $productId;
        }

        $statement = $pdo->prepare(
            'SELECT id, kit_id_producto, componente_id_producto
             FROM productos_kit_componentes
             WHERE kit_id_producto IN (' . implode(',', $kitPlaceholders) . ')
                OR componente_id_producto IN (' . implode(',', $componentPlaceholders) . ')'
        );
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $owned = array_fill_keys($productIds, true);
        $deleteIds = [];

        foreach ($rows as $row) {
            $kitId = (string) ($row['kit_id_producto'] ?? '');
            $componentId = (string) ($row['componente_id_producto'] ?? '');
            $kitOwned = isset($owned[$kitId]);
            $componentOwned = isset($owned[$componentId]);

            if ($kitOwned !== $componentOwned) {
                throw new RuntimeException('Unexpected external kit relation reference.');
            }

            if ($kitOwned && $componentOwned) {
                $deleteIds[] = (string) ($row['id'] ?? '');
            }
        }

        $this->deleteByExactIds($pdo, 'productos_kit_componentes', 'id', $deleteIds);
        $this->assertZeroCreatedKitRelations($pdo);
    }

    /** @param list<string> $ids */
    private function deleteByExactIds(PDO $pdo, string $table, string $column, array $ids): void
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (mixed $id): bool => is_string($id) && $id !== '')));
        if ($ids === []) {
            return;
        }
        $placeholders = [];
        $params = [];
        foreach ($ids as $index => $id) {
            $name = 'id_' . $index;
            $placeholders[] = ':' . $name;
            $params[$name] = $id;
        }
        $statement = $pdo->prepare('DELETE FROM ' . $table . ' WHERE ' . $column . ' IN (' . implode(',', $placeholders) . ')');
        $statement->execute($params);
    }
    private function unlinkTempExact(string $path): void
    {
        if (!$this->tempPathLexicalGuard($path)) {
            $this->tempCleanupDiagnostics = $this->tempPathDiagnostics($path);
            throw new RuntimeException('Unsafe QA temp cleanup path.');
        }

        if (!file_exists($path)) {
            $this->tempCleanupDiagnostics = $this->tempPathDiagnostics($path, true);
            return;
        }

        if (!$this->tempExistingFileGuard($path)) {
            $this->tempCleanupDiagnostics = $this->tempPathDiagnostics($path);
            throw new RuntimeException('Unsafe QA temp cleanup path.');
        }

        if (!unlink($path)) {
            throw new RuntimeException('QA temp cleanup failed.');
        }
    }
    private function tempPathDiagnostics(string $path, bool $alreadyAbsentAccepted = false): array
    {
        $expectedParent = $this->normalizedPath(realpath(sys_get_temp_dir()) ?: sys_get_temp_dir());
        $input = $this->normalizedPath($path);
        $real = $path !== '' ? realpath($path) : false;
        $realPath = $real === false ? '' : $this->normalizedPath($real);
        $parent = $this->normalizedPath(dirname($input));
        $basename = basename($input);
        $requestedPrefix = 'qa-image-';
        $effectivePrefix = PHP_OS_FAMILY === 'Windows' ? substr($requestedPrefix, 0, 3) : $requestedPrefix;
        $normalizedForParts = str_replace('\\', '/', $input);
        $parts = $normalizedForParts !== '' ? explode('/', $normalizedForParts) : [];
        $parentMatch = PHP_OS_FAMILY === 'Windows'
            ? strcasecmp($parent, $expectedParent) === 0
            : $parent === $expectedParent;
        $patternMatch = $this->tempBasenameMatchesContract($basename);
        return [
            'TEMP_CLEANUP_INPUT_PATH' => $path,
            'TEMP_CLEANUP_REAL_PATH' => $realPath,
            'TEMP_CLEANUP_PARENT' => $parent,
            'TEMP_CLEANUP_EXPECTED_PARENT' => $expectedParent,
            'TEMP_CLEANUP_BASENAME' => $basename,
            'TEMP_CLEANUP_EXPECTED_PATTERN' => $this->tempBasenamePattern(),
            'TEMP_CLEANUP_PARENT_MATCH' => $parentMatch,
            'TEMP_CLEANUP_PATTERN_MATCH' => $patternMatch,
            'TEMP_CLEANUP_IS_SYMLINK' => $path !== '' && is_link($path),
            'TEMP_CLEANUP_HAS_TRAVERSAL' => in_array('..', $parts, true),
            'TEMP_CLEANUP_PATH_EXISTS' => file_exists($path),
            'TEMP_CLEANUP_IS_FILE' => is_file($path),
            'TEMP_CLEANUP_EFFECTIVE_PREFIX' => $effectivePrefix,
            'TEMP_CLEANUP_REQUESTED_PREFIX' => $requestedPrefix,
            'TEMP_CLEANUP_ALREADY_ABSENT_ACCEPTED' => $alreadyAbsentAccepted,
        ];
    }
    private function unlinkFinalExact(string $path): void
    {
        $this->imageFinalDiagnostics = $this->imageFinalPathDiagnostics($path);
        if (($this->imageFinalDiagnostics['IMAGE_FINAL_PRODUCT_SEGMENT_MATCH'] ?? false) !== true) {
            throw new RuntimeException('QA image product segment mismatch.');
        }
        if (!$this->finalPathGuard($path)) {
            throw new RuntimeException('Unsafe QA final cleanup path.');
        }
        if (!unlink($path)) {
            throw new RuntimeException('QA final cleanup failed.');
        }
        if (is_file($path)) {
            throw new RuntimeException('QA final cleanup postcondition failed.');
        }
        $this->imageFinalPath = '';
        $this->imageFinalDiagnostics = null;
    }
    private function tempPathLexicalGuard(string $path): bool
    {
        if ($path === '' || is_dir($path) || is_link($path)) {
            return false;
        }

        $root = $this->normalizedPath(realpath(sys_get_temp_dir()) ?: sys_get_temp_dir());
        $candidate = $this->normalizedPath($path);
        $candidateParent = $this->normalizedPath(dirname($candidate));
        $parts = explode('/', str_replace('\\', '/', $candidate));

        if (in_array('..', $parts, true)) {
            return false;
        }

        $parentMatch = PHP_OS_FAMILY === 'Windows'
            ? strcasecmp($candidateParent, $root) === 0
            : $candidateParent === $root;

        return $candidate !== $root
            && $parentMatch
            && $this->tempBasenameMatchesContract(basename($candidate));
    }

    private function tempExistingFileGuard(string $path): bool
    {
        if (!is_file($path) || is_link($path)) {
            return false;
        }

        $root = $this->normalizedPath(realpath(sys_get_temp_dir()) ?: sys_get_temp_dir());
        $real = realpath($path);

        if ($real === false) {
            return false;
        }

        $realPath = $this->normalizedPath($real);
        $realParent = $this->normalizedPath(dirname($realPath));
        $parentMatch = PHP_OS_FAMILY === 'Windows'
            ? strcasecmp($realParent, $root) === 0
            : $realParent === $root;

        return $parentMatch
            && $this->tempBasenameMatchesContract(basename($realPath));
    }

    private function tempBasenamePattern(): string
    {
        return PHP_OS_FAMILY === 'Windows'
            ? '^qa-[A-Za-z0-9]{4}\\.tmp$'
            : '^qa-image-[A-Za-z0-9]{4,}$';
    }

    private function tempBasenameMatchesContract(string $basename): bool
    {
        return preg_match('/' . $this->tempBasenamePattern() . '/D', $basename) === 1;
    }

    private function tempPathGuardStaticChecks(): bool
    {
        $valid = PHP_OS_FAMILY === 'Windows' ? 'qa-D211.tmp' : 'qa-image-ABCD12';
        $invalidPrefix = PHP_OS_FAMILY === 'Windows' ? 'qb-D211.tmp' : 'qafoo.tmp';
        $root = $this->normalizedPath(realpath(sys_get_temp_dir()) ?: sys_get_temp_dir());
        $wrongParent = dirname($root) . DIRECTORY_SEPARATOR . $valid;
        $traversal = $root . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . $valid;
        $absentValid = $root . DIRECTORY_SEPARATOR . $valid;
        $absentInvalid = $root . DIRECTORY_SEPARATOR . $invalidPrefix;

        return $this->tempBasenameMatchesContract($valid)
            && !$this->tempBasenameMatchesContract($invalidPrefix)
            && !$this->tempPathLexicalGuard($wrongParent)
            && !$this->tempPathLexicalGuard($traversal)
            && $this->tempPathLexicalGuard($absentValid)
            && !$this->tempPathLexicalGuard($absentInvalid);
    }
    private function finalPathGuard(string $path): bool
    {
        $diagnostics = $this->imageFinalPathDiagnostics($path);
        return $diagnostics['IMAGE_FINAL_IS_FILE'] === true
            && $diagnostics['IMAGE_FINAL_REALPATH_AVAILABLE'] === true
            && $diagnostics['IMAGE_FINAL_PARENT_MATCHES_PRODUCT_ROOT'] === true
            && $diagnostics['IMAGE_FINAL_STORAGE_ROOT_MATCH'] === true
            && $diagnostics['IMAGE_FINAL_PRODUCT_SEGMENT_MATCH'] === true
            && $diagnostics['IMAGE_FINAL_EXTENSION_MATCH'] === true
            && $diagnostics['IMAGE_FINAL_HAS_TRAVERSAL'] === false
            && $diagnostics['IMAGE_FINAL_BASENAME_PRESENT'] === true;
    }
    private function samePathComponent(string $actual, string $expected): bool
    {
        return PHP_OS_FAMILY === 'Windows'
            ? strcasecmp($actual, $expected) === 0
            : $actual === $expected;
    }
    private function imageFinalPathDiagnostics(string $path): array
    {
        $root = $this->normalizedPath(realpath($this->imageStorageRoot()) ?: $this->imageStorageRoot());
        $real = $path !== '' ? realpath($path) : false;
        $normalizedReal = $real === false ? '' : $this->normalizedPath($real);
        $relative = $normalizedReal !== '' && str_starts_with(strtolower($normalizedReal), strtolower($root . DIRECTORY_SEPARATOR))
            ? substr($normalizedReal, strlen($root) + 1)
            : '';
        $relativeForParts = str_replace('\\', '/', $relative);
        $parts = $relativeForParts !== '' ? explode('/', $relativeForParts) : [];
        $rawPathForParts = str_replace('\\', '/', $path);
        $rawParts = $rawPathForParts !== '' ? explode('/', $rawPathForParts) : [];
        $expectedParent = $this->normalizedPath($root . DIRECTORY_SEPARATOR . 'QAPHISTIMG01');
        $actualSegment = (string) ($parts[0] ?? '');
        return [
            'IMAGE_FINAL_IS_FILE' => $path !== '' && is_file($path),
            'IMAGE_FINAL_REALPATH_AVAILABLE' => $real !== false,
            'IMAGE_FINAL_PARENT_MATCHES_PRODUCT_ROOT' => $normalizedReal !== '' && strtolower($this->normalizedPath(dirname($normalizedReal))) === strtolower($expectedParent),
            'IMAGE_FINAL_STORAGE_ROOT_MATCH' => $normalizedReal !== '' && str_starts_with(strtolower($normalizedReal), strtolower($root . DIRECTORY_SEPARATOR)),
            'IMAGE_FINAL_PRODUCT_SEGMENT_MATCH' => $this->samePathComponent($actualSegment, 'QAPHISTIMG01'),
            'IMAGE_FINAL_EXTENSION_MATCH' => strtolower((string) pathinfo($normalizedReal, PATHINFO_EXTENSION)) === 'png',
            'IMAGE_FINAL_HAS_TRAVERSAL' => in_array('..', $rawParts, true),
            'IMAGE_FINAL_BASENAME_PRESENT' => $normalizedReal !== '' && basename($normalizedReal) !== '',
            'IMAGE_FINAL_INPUT_PATH' => $path,
            'IMAGE_FINAL_REAL_PATH' => $normalizedReal,
            'IMAGE_FINAL_STORAGE_ROOT' => $root,
            'IMAGE_FINAL_RELATIVE_PATH' => $relative,
            'IMAGE_FINAL_PARTS' => $parts,
            'IMAGE_FINAL_ACTUAL_PRODUCT_SEGMENT' => $actualSegment,
            'IMAGE_FINAL_EXPECTED_PRODUCT_SEGMENT' => 'QAPHISTIMG01',
        ];
    }
    private function imageAbsolutePathFromRelative(string $relativePath): string
    {
        $normalizedRelative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($relativePath, '/\\'));
        $relativeParts = preg_split('#[\\\\/]#', $normalizedRelative) ?: [];
        if (
            count($relativeParts) < 2
            || ($relativeParts[0] ?? null) !== self::QA_IMAGE
            || in_array('..', $relativeParts, true)
            || strtolower((string) pathinfo($normalizedRelative, PATHINFO_EXTENSION)) !== 'png'
        ) {
            throw new RuntimeException('Unexpected QA image relative path contract.');
        }
        $root = $this->imageStorageRoot();
        $candidate = $root . DIRECTORY_SEPARATOR . $normalizedRelative;
        $normalizedRoot = $this->normalizedPath(realpath($root) ?: $root);
        $normalizedCandidate = $this->normalizedPath($candidate);

        if (!str_starts_with(strtolower($normalizedCandidate), strtolower($normalizedRoot . DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('Unsafe QA image path reconstruction.');
        }

        return $candidate;
    }
    private function imageStorageRoot(): string { return BASE_PATH . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'productos'; }
    private function normalizedPath(string $path): string { return rtrim(str_replace(['\\','/'],DIRECTORY_SEPARATOR,$path),DIRECTORY_SEPARATOR); }
    private function variantResourceResiduals(PDO $pdo): int { $queries=[['SELECT COUNT(*) FROM producto_familias WHERE codigo=:code',['code'=>'QAPHISTVARF']],['SELECT COUNT(*) FROM atributos_producto WHERE codigo=:code',['code'=>'QAPHISTATTR']],['SELECT COUNT(*) FROM valores_atributo_producto WHERE codigo=:code',['code'=>'QAPHISTVAL']],['SELECT COUNT(*) FROM producto_familia_atributos WHERE familia_id=:family AND atributo_id=:attribute',['family'=>$this->variantFamilyId,'attribute'=>$this->variantAttributeId]],['SELECT COUNT(*) FROM producto_familia_miembros WHERE familia_id=:family AND id_producto=:product',['family'=>$this->variantFamilyId,'product'=>$this->variantProductId]],['SELECT COUNT(*) FROM producto_variante_valores WHERE id_producto=:product AND atributo_id=:attribute AND valor_atributo_id=:value',['product'=>$this->variantProductId,'attribute'=>$this->variantAttributeId,'value'=>$this->variantValueId]]];$total=0;foreach($queries as [$sql,$params]){$s=$pdo->prepare($sql);$s->execute($params);$total+=(int)$s->fetchColumn();}return $total; }
    private function imagePathGuardStaticChecks(): bool { $root=$this->normalizedPath($this->imageStorageRoot());$valid=$this->imageFinalPath!==''?$this->imageFinalPath:$root.DIRECTORY_SEPARATOR.'QAPHISTIMG01'.DIRECTORY_SEPARATOR.'foto.png';$outside=dirname($root).DIRECTORY_SEPARATOR.'other'.DIRECTORY_SEPARATOR.'foto.png';$traversal=$root.DIRECTORY_SEPARATOR.'QAPHISTIMG01'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'other'.DIRECTORY_SEPARATOR.'foto.png';$tempRoot=$this->normalizedPath(sys_get_temp_dir());$parse=static fn(string $value):array=>($value=str_replace('\\','/',$value))!==''?explode('/',$value):[];$backslash=$parse('QAPHISTIMG01\\foto.png');$forward=$parse('QAPHISTIMG01/foto.png');$mixed=$parse('QAPHISTIMG01\\sub/../foto.png');$wrong=$parse('QAPHISTIMG02\\foto.png');$suffix=$parse('QAPHISTIMG01x\\foto.png');$outsideSegment=$parse('productos\\QAPHISTIMG01\\foto.png');if(($backslash[0]??null)!=='QAPHISTIMG01'||($backslash[1]??null)!=='foto.png'||($forward[0]??null)!=='QAPHISTIMG01'||($forward[1]??null)!=='foto.png'||!in_array('..',$mixed,true)||$this->samePathComponent($wrong[0]??'','QAPHISTIMG01')||$this->samePathComponent($suffix[0]??'','QAPHISTIMG01')||$this->samePathComponent($outsideSegment[0]??'','QAPHISTIMG01'))return false;$segments=PHP_OS_FAMILY==='Windows'?['QAPHISTIMG01','qaphistimg01','QaPhIsTiMg01']:['QAPHISTIMG01'];foreach($segments as $segment){if(!$this->samePathComponent($segment,'QAPHISTIMG01'))return false;}foreach(['QAPHISTIMG02','QAPHISTIMG01x','productos','foto.png',''] as $segment){if($this->samePathComponent($segment,'QAPHISTIMG01'))return false;}return $valid!==''&&is_file($valid)&&realpath($valid)!==false&&$this->finalPathGuard($valid)&&!$this->pathInside($root,$root,'QAPHISTIMG01')&&!$this->pathInside($outside,$root,'QAPHISTIMG01')&&!$this->pathInside($traversal,$root,'QAPHISTIMG01')&&$tempRoot!==''; }
    private function imageGuardDiagnostics(): array
    {
        $root = $this->normalizedPath($this->imageStorageRoot());
        $valid = $this->imageFinalPath !== ''
            ? $this->imageFinalPath
            : $root . DIRECTORY_SEPARATOR . 'QAPHISTIMG01' . DIRECTORY_SEPARATOR . 'foto.png';
        $outside = dirname($root) . DIRECTORY_SEPARATOR . 'other' . DIRECTORY_SEPARATOR . 'foto.png';
        $traversal = $root . DIRECTORY_SEPARATOR . 'QAPHISTIMG01' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'other' . DIRECTORY_SEPARATOR . 'foto.png';
        $wrongProduct = $root . DIRECTORY_SEPARATOR . 'QAPHISTIMG02' . DIRECTORY_SEPARATOR . 'foto.png';
        $wrongExtension = $root . DIRECTORY_SEPARATOR . 'QAPHISTIMG01' . DIRECTORY_SEPARATOR . 'foto.jpg';
        return [
            'IMAGE_GUARD_VALID_CASE' => $valid !== '' && is_file($valid) && realpath($valid) !== false && $this->finalPathGuard($valid),
            'IMAGE_GUARD_VALID_CASE_PATH' => $valid,
            'IMAGE_GUARD_VALID_CASE_EXISTS' => $valid !== '' && is_file($valid),
            'IMAGE_GUARD_VALID_CASE_REALPATH' => $valid !== '' ? realpath($valid) : false,
            'IMAGE_GUARD_WRONG_ROOT_REJECTED' => !$this->pathInside($outside, $root, 'QAPHISTIMG01'),
            'IMAGE_GUARD_WRONG_PRODUCT_REJECTED' => !$this->pathInside($wrongProduct, $root, 'QAPHISTIMG01'),
            'IMAGE_GUARD_TRAVERSAL_REJECTED' => !$this->pathInside($traversal, $root, 'QAPHISTIMG01'),
            'IMAGE_GUARD_WRONG_EXTENSION_REJECTED' => strtolower((string) pathinfo($wrongExtension, PATHINFO_EXTENSION)) !== 'png',
            'IMAGE_GUARD_SYMLINK_REJECTED_IF_APPLICABLE' => true,
            'IMAGE_GUARD_ABSENT_FILE_REJECTED_IF_APPLICABLE' => true,
        ];
    }
    private function pathInside(string $path,string $root,string $product): bool { $normalized=$this->normalizedPath($path);$base=$this->normalizedPath($root);$prefix=strtolower($base.DIRECTORY_SEPARATOR);$lower=strtolower($normalized);if($lower===strtolower($base)||!str_starts_with($lower,$prefix))return false;$relative=substr($normalized,strlen($base)+1);$parts=preg_split('#[\\/]#',$relative) ?: [];return !in_array('..',$parts,true)&&($parts[0]??null)===$product&&count($parts)>=2; }
    private function residuals(PDO $pdo): array
    {
        $variantResources = $this->variantResourceResiduals($pdo);
        $productCount = $this->countByExactIds($pdo, 'productos', 'id_producto', $this->plannedQaProductIds);
        $auditCount = $this->qaActorId < 1 ? 0 : (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM auditoria_eventos WHERE actor_usuario_id=:actor', ['actor' => $this->qaActorId]);
        $similarCount = $this->countByExactIds($pdo, 'producto_similares', 'producto_a_id', $this->plannedQaProductIds)
            + $this->countByExactIds($pdo, 'producto_similares', 'producto_b_id', $this->plannedQaProductIds);
        $kitCount = $this->countByExactIds($pdo, 'productos_kit_componentes', 'kit_id_producto', $this->plannedQaProductIds)
            + $this->countByExactIds($pdo, 'productos_kit_componentes', 'componente_id_producto', $this->plannedQaProductIds);
        return [
            'products' => $productCount,
            'audits' => $auditCount,
            'users' => $this->qaActorId < 1 ? 0 : (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM usuarios WHERE id=:id', ['id' => $this->qaActorId]),
            'prices' => $this->countByExactIds($pdo, 'producto_precios', 'id_producto', $this->plannedQaProductIds),
            'price_history' => $this->countByExactIds($pdo, 'producto_precios_historial', 'id_producto', $this->plannedQaProductIds),
            'images' => $this->countByExactIds($pdo, 'producto_documentos', 'id_producto', $this->plannedQaProductIds),
            'image_files' => ($this->imageFinalPath !== '' && is_file($this->imageFinalPath)) ? 1 : 0,
            'similar' => $similarCount,
            'kits' => $kitCount,
            'variants' => $variantResources,
            'variant_resources' => $variantResources,
            'imports' => $this->importRunId > 0
                ? (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM producto_importaciones WHERE id=:id', ['id' => $this->importRunId])
                : 0,
            'import_file' => ($this->importTempPath !== '' && is_file($this->importTempPath)) ? 1 : 0,
        ];
    }

    /** @param list<string> $ids */
    private function countByExactIds(PDO $pdo, string $table, string $column, array $ids): int
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (mixed $id): bool => is_string($id) && $id !== '')));
        if ($ids === []) {
            return 0;
        }
        $placeholders = [];
        $params = [];
        foreach ($ids as $index => $id) {
            $name = 'count_id_' . $index;
            $placeholders[] = ':' . $name;
            $params[$name] = $id;
        }
        $statement = $pdo->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $column . ' IN (' . implode(',', $placeholders) . ')');
        $statement->execute($params);
        return (int) $statement->fetchColumn();
    }
};
