# PRODUCTOS-6 — Variantes de producto

## Estado y alcance

Esta fase implementa familias, atributos, valores y variantes como una capa
relacional sobre productos existentes. Una variante sigue siendo un producto
vendible; la familia no sustituye precio, inventario, SKU, series, lotes ni
pedimentos. Productos heredados sin familia conservan su comportamiento.

Las imágenes, productos similares, tickets e importaciones permanecen fuera
del alcance. El producto protegido `102016169` no se usa como fixture y no se
declara intacto hasta completar el postcheck host de solo lectura.

## Modelo de datos

La migración `productos_variantes_1_001_create_product_variant_tables` crea
seis tablas InnoDB con charset/collation consistente:

- `producto_familias`;
- `atributos_producto` y `valores_atributo_producto`;
- `producto_familia_atributos`;
- `producto_familia_miembros`;
- `producto_variante_valores`.

La combinación de valores se normaliza ordenando por `atributo_id` y se
almacena como SHA-256 binario de 32 bytes de la firma canónica
`id:valor|id:valor`. Por eso el mismo conjunto en distinto orden produce
`SAME_COMBINATION_HASH=true`. La combinación vacía solo puede existir una vez
por familia.

Los guards físicos y sus columnas generadas son:

- `uq_producto_familia_miembros_active_product`
  (`id_producto`, `active_membership_marker`);
- `uq_producto_familia_miembros_default`
  (`familia_id`, `default_active_marker`);
- `uq_producto_familia_miembros_combination`
  (`familia_id`, `combination_hash`, `active_combination_marker`);
- columnas generadas: `active_membership_marker`, `default_active_marker`,
  `active_combination_marker`.

El reparenting directo está prohibido en v1; desvincular es una baja lógica de
la membresía y no elimina el producto.

## Servicios, permisos y HTTP

La lógica transaccional está separada en `ProductFamilyService`,
`ProductAttributeService` y `ProductVariantService`; los repositorios usan PDO
con consultas preparadas. `ProductVariantService` conserva ownership
transaccional propio. El DB-TEST no abre una transacción global: usa limpieza
explícita y `finally`.

Las rutas privadas requieren autenticación y permiso; las mutaciones POST
requieren CSRF. El seed idempotente asigna al rol ADMIN los seis permisos:

```text
productos.familias.ver
productos.familias.editar
productos.atributos.ver
productos.atributos.editar
productos.variantes.ver
productos.variantes.editar
```

La UI está renderizada por servidor y separa familias, atributos y variantes.
No mezcla precio, inventario, series, imágenes ni productos similares.

## Aplicación en DB-TEST

```ini
DATABASE=r_erp_db_core_0_test
MIGRATION=productos_variantes_1_001_create_product_variant_tables
TEST_DB_MIGRATION_APPLIED=true
APPLIED_AT=2026-10-14 17:02:59
PRODUCTION_DEPLOY=false

SEED=productos_variantes_1_seed_permissions
TEST_DB_SEED_APPLIED=true
TEST_DB_SEED_IDEMPOTENT=true

DB_TEST_V4=PASS
_failed_checks=[]
DB_TEST_GLOBAL_TRANSACTION=false
DB_TEST_EXPLICIT_CLEANUP=true
DB_TEST_FINALLY_CLEANUP=true
```

Evidencia V4 validada:

```ini
TABLES_EXIST=true
ENGINE_INNODB=true
CHARSET_COLLATION_VALID=true
FOREIGN_KEYS_VALID=true
UNIQUE_CONSTRAINTS_VALID=true
GENERATED_COLUMNS_VALID=true

ONE_ACTIVE_FAMILY_DB_GUARD=true
ONE_DEFAULT_VARIANT_DB_GUARD=true
COMBINATION_UNIQUE_DB_GUARD=true
SECOND_ACTIVE_FAMILY_DIRECT_DB_REJECTED=true
SECOND_DEFAULT_DIRECT_DB_REJECTED=true
DUPLICATE_COMBINATION_DIRECT_DB_REJECTED=true

COMBINATION_HASH_LENGTH=32
SAME_COMBINATION_HASH=true
RELATION_FAILURE_ROLLBACK=true
ATTRIBUTE_FAILURE_ROLLBACK=true
DEFAULT_FAILURE_ROLLBACK=true
NO_PARTIAL_VARIANT_STATE=true
LEGACY_PRODUCT_WITHOUT_FAMILY_VALID=true
LEGACY_PRICE_BEHAVIOR_UNCHANGED=true
LEGACY_INVENTORY_BEHAVIOR_UNCHANGED=true
```

Cleanup final, con counts enteros y checks derivados booleanos:

```ini
QA_FAMILY_RESIDUAL_COUNT=0
QA_ATTRIBUTE_RESIDUAL_COUNT=0
QA_ATTRIBUTE_VALUE_RESIDUAL_COUNT=0
QA_MEMBERSHIP_RESIDUAL_COUNT=0
QA_VARIANT_VALUE_RESIDUAL_COUNT=0
QA_PRODUCT_RESIDUAL_COUNT=0
QA_AUDIT_RESIDUAL_COUNT=0
QA_USER_RESIDUAL_COUNT=0

QA_FAMILY_RESIDUALS_ZERO=true
QA_ATTRIBUTE_RESIDUALS_ZERO=true
QA_ATTRIBUTE_VALUE_RESIDUALS_ZERO=true
QA_MEMBERSHIP_RESIDUALS_ZERO=true
QA_VARIANT_VALUE_RESIDUALS_ZERO=true
QA_PRODUCT_RESIDUALS_ZERO=true
QA_AUDIT_RESIDUALS_ZERO=true
QA_USER_RESIDUALS_ZERO=true
```

## QA HTTP y visual

```ini
HTTP_QA_LEVEL=STATIC_SOURCE_SMOKE_ONLY
HTTP_RUNTIME_QA=NOT_EXECUTED_NON_BLOCKING
VISUAL_UI_REVIEW=NOT_EXECUTED
VISUAL_UI_REVIEW_CLASSIFICATION=NON_BLOCKING
```

No se afirma runtime HTTP ni cierre visual con esta fase documental.

## Postcheck host de solo lectura pendiente

No se ejecutan estas consultas en Codex ni se guardan como script. En el host,
con una conexión ya autorizada a `r_erp_db_core_0_test`, deben ejecutarse
exclusivamente como `SELECT` parametrizados para `:product_id = '102016169'`:

```sql
-- A. Existencia y estado del producto protegido
SELECT id_producto, activo, eliminado_en
FROM productos
WHERE id_producto = :product_id;

-- B. Cantidad de precios del producto protegido
SELECT COUNT(*) AS price_count
FROM producto_precios
WHERE id_producto = :product_id;

-- C. Membresías de variantes (total y activas)
SELECT
    COUNT(*) AS membership_count,
    SUM(CASE WHEN activo = 1 AND eliminado_en IS NULL THEN 1 ELSE 0 END)
        AS active_membership_count
FROM producto_familia_miembros
WHERE id_producto = :product_id;

-- D. Valores de variante relacionados
SELECT COUNT(*) AS variant_value_count
FROM producto_variante_valores
WHERE id_producto = :product_id;
```

Nombres de tablas y columnas anteriores fueron confirmados por la migración y
repositorios existentes. Este postcheck debe registrar existencia del producto,
cantidad de precios, membresías totales/activas y valores de variante, sin
realizar escrituras. El postcheck host fue completado en
`r_erp_db_core_0_test`:

```ini
PROTECTED_PRODUCT_POSTCHECK=PASS
PROTECTED_PRODUCT_INTACT=PASS
PROTECTED_PRODUCT_EXISTS=true
PROTECTED_PRODUCT_ACTIVE=true
PROTECTED_PRODUCT_DELETED=false
PROTECTED_PRODUCT_PRICE_COUNT=2
PROTECTED_PRODUCT_MEMBERSHIP_COUNT=0
PROTECTED_PRODUCT_ACTIVE_MEMBERSHIP_COUNT=0
PROTECTED_PRODUCT_VARIANT_VALUE_COUNT=0
```

## Reglas obligatorias

- No ejecutar migración, seed, DB-TEST o rollback sin autorización separada.
- No usar producción ni imprimir credenciales o `.env`.
- No introducir SQL en controladores o vistas.
- Mantener alcance de empresa/almacén y RBAC existente.
- No declarar intacto el producto protegido sin el postcheck host de solo
  lectura.

## Pendiente de aprobar

- Postcheck host de solo lectura del producto `102016169`.
- Revisión HTTP autenticada y revisión visual.
- Cualquier despliegue posterior, que no está autorizado por esta fase.

## Fuera de alcance de esta fase

Deploy, push, commit, cambios en `main`, SMTP, tickets/outbox, inventario,
series, lotes, pedimentos, importaciones, imágenes, productos similares y
modificación del producto protegido.
