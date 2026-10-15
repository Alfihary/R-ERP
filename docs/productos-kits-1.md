# PRODUCTOS-KITS-1

## Estado

```ini
PHASE=PRODUCTOS-KITS-1
IMPLEMENTATION_STATUS=LOCAL_STATIC_READY_FOR_HOST_DB
TEST_DB_MIGRATION_APPLIED=true
TEST_DB_SEED_APPLIED=true
DB_TEST_RUNTIME=PASS
DB_TEST_FAILED_CHECKS=0
SEED_STATIC_IDEMPOTENCY_REVIEW=PASS
SEED_RUNTIME_IDEMPOTENCY=NOT_REEXECUTED
HTTP_QA_LEVEL=STATIC_SOURCE_SMOKE_ONLY
HTTP_RUNTIME_QA=NOT_EXECUTED_NON_BLOCKING
VISUAL_QA=NOT_EXECUTED
PRODUCTION_DEPLOY=false
HTTP_STATIC_QA=PASS
PHP_LINT=PASS
JS_SYNTAX=PASS
GIT_DIFF_CHECK=PASS
STATIC_BLOCKERS_FIX=PASS_READY_FOR_STATIC_REVIEW_V2
AUDIT_FAILURE_ROLLBACK_TEST=PREPARED_REAL_INJECTION
DIRECT_DB_GUARD_TESTS=PREPARED
UI_MUTATIONS=PREPARED_WITH_PERMISSION_AND_CSRF
JS_WIRING=IMPLEMENTED
PRODUCT_KIT_ACTIVE_PRODUCT_FILTER_FIX=PASS
PRODUCT_KIT_ACTIVE_PRODUCT_REQUIRES_ACTIVE=true
PRODUCT_KIT_ACTIVE_PRODUCT_REQUIRES_NOT_DELETED=true
COMPONENT_REMOVE=true
RELATION_SOFT_DELETED=true
RELATION_REACTIVATION=true
NO_PARTIAL_KIT_STATE=true
DIRECT_DB_SELF_REFERENCE_REJECTED=true
DIRECT_DB_ZERO_QUANTITY_REJECTED=true
DIRECT_DB_ACTIVE_DUPLICATE_REJECTED=true
AUDIT_FAILURE_ROLLBACK=true
PERSISTENCE_FAILURE_NO_PARTIAL_STATE=true
PROTECTED_PRODUCT_USED_AS_FIXTURE=false
```

## Contrato

Un KIT es un producto con identidad propia compuesto por productos reales. La relación es dirigida (`KIT -> componente`), no se permiten kits anidados, autorreferencias ni componentes duplicados activos. La cantidad usa `DECIMAL(18,6)` y el precio del KIT es independiente de los componentes.

Las relaciones se conservan mediante soft delete. Una adición existente actualiza cantidad; una relación eliminada se reactiva en la misma fila.

## Alcance

Esta fase cubre composición de catálogo, permisos, auditoría, UI y pruebas estáticas/DB diseñadas. No cubre inventario, ventas, compras, explosión, armado, desarmado, importación, imágenes, similares, variantes, series, lotes, pedimentos ni tickets.

## Evidencia QA

La migration `productos_kits_1_001_create_product_kit_components` fue aplicada en `r_erp_db_core_0_test` y el seed `productos_kits_1_seed_permissions` fue aplicado una vez. El runtime DB-test terminó en PASS con `_failed_checks=[]` y sin residuos QA.

Durante QA se corrigió `ProductKitRepository::activeProduct()` para exigir `activo=1` y `eliminado_en IS NULL`, asegurando el rechazo de componentes inactivos y soft-deleted. La cobertura de remove, soft delete, reactivación y estado final no parcial quedó en PASS. También pasaron las guardias directas de autorreferencia, cantidad cero y duplicado activo, además de rollback ante fallo de auditoría y ausencia de estado parcial ante fallo de persistencia.

```ini
QA_KIT_RELATION_RESIDUAL_COUNT=0
QA_KIT_RELATION_RESIDUALS_ZERO=true
QA_PRODUCT_RESIDUAL_COUNT=0
QA_PRODUCT_RESIDUALS_ZERO=true
QA_AUDIT_RESIDUAL_COUNT=0
QA_AUDIT_RESIDUALS_ZERO=true
QA_USER_RESIDUAL_COUNT=0
QA_USER_RESIDUALS_ZERO=true
```

La idempotencia del seed fue revisada estáticamente (`SEED_STATIC_IDEMPOTENCY_REVIEW=PASS`), pero no se ejecutó una segunda vez (`SEED_RUNTIME_IDEMPOTENCY=NOT_REEXECUTED`). El producto protegido `102016169` no fue usado como fixture; no se afirma un postcheck específico de ese producto.

HTTP permanece en nivel `STATIC_SOURCE_SMOKE_ONLY`; `HTTP_RUNTIME_QA=NOT_EXECUTED_NON_BLOCKING` y `VISUAL_QA=NOT_EXECUTED`. No se realizó deploy productivo.
