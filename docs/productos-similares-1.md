# PRODUCTOS-5 — Productos similares

## Estado final

La relación es simétrica y se persiste una sola vez con el orden canónico
`producto_a_id < producto_b_id`. Una fila representa la relación A↔B.

La validación se realizó sobre la base de prueba `r_erp_db_core_0_test`:

- Migración aplicada: `productos_similares_1_001_create_product_similar_tables`.
- Seed aplicado: `productos_similares_1_seed_permissions`.
- DB-TEST final: exit code `0`.
- `_failed_checks=[]`.
- Producción: no desplegado (`DEPLOY_PRODUCTION=false`).

## Contrato de schema

La tabla `producto_similares` usa:

- PK canónica `(producto_a_id, producto_b_id)`.
- CHECK `producto_a_id <> producto_b_id`.
- CHECK `producto_a_id < producto_b_id`.
- `producto_a_id` y `producto_b_id`: `VARCHAR(16)`, charset `ascii`,
  collation `ascii_bin`, compatibles con `productos.id_producto`.

Foreign keys exactas:

| Constraint | Columna | Referencia | ON UPDATE | ON DELETE |
| --- | --- | --- | --- | --- |
| `fk_producto_similares_a` | `producto_a_id` | `productos.id_producto` | `RESTRICT` | `RESTRICT` |
| `fk_producto_similares_b` | `producto_b_id` | `productos.id_producto` | `RESTRICT` | `RESTRICT` |
| `fk_producto_similares_creado_por` | `creado_por` | `usuarios.id` | `RESTRICT` | `SET NULL` |
| `fk_producto_similares_actualizado_por` | `actualizado_por` | `usuarios.id` | `RESTRICT` | `SET NULL` |
| `fk_producto_similares_eliminado_por` | `eliminado_por` | `usuarios.id` | `RESTRICT` | `SET NULL` |

## Dominio y estados

- No se permite relacionar un producto consigo mismo, inexistente, inactivo o eliminado.
- Duplicados en el mismo orden y en orden inverso son rechazados.
- Quitar es borrado lógico; la fila canónica permanece.
- Reactivar reutiliza la misma fila canónica.
- El test cubre `SELF_RELATION_REJECTED`, `DUPLICATE_SAME_ORDER_REJECTED`,
  `DUPLICATE_REVERSE_ORDER_REJECTED`, `INACTIVE_PRODUCT_REJECTED`,
  `DELETED_PRODUCT_REJECTED`, `UNKNOWN_PRODUCT_REJECTED`, `REMOVE_RELATION`,
  `SOFT_DELETE_ASSERTED`, `REACTIVATE_RELATION` y
  `REACTIVATION_REUSES_SAME_CANONICAL_ROW`.

## Auditoría y transacciones

Las mutaciones registran, dentro de la misma transacción:

- `productos.similares.agregar` sobre `producto_similar`.
- `productos.similares.quitar` sobre `producto_similar`.

Checks finales:

```text
ADD_AUDIT_CREATED=true
REMOVE_AUDIT_CREATED=true
ADD_FAILURE_ROLLBACK=true
REMOVE_FAILURE_ROLLBACK=true
NO_PARTIAL_RELATION=true
```

## RBAC, HTTP y UI

Permisos:

- `productos.similares.ver` para visualizar relaciones.
- `productos.similares.editar` para autocomplete, agregar y quitar.

El seed asigna ambos permisos al rol ADMIN de forma idempotente. Las
mutaciones POST requieren autenticación, permiso y CSRF; no existen
mutaciones por GET. El autocomplete devuelve productos activos/no eliminados,
excluye el origen y no carga el catálogo completo.

La revisión HTTP disponible es:

```text
HTTP_QA_LEVEL=STATIC_SOURCE_SMOKE_ONLY
```

Cubre estáticamente rutas, wiring, permisos, CSRF en formularios,
autocomplete y ausencia de éxito hardcodeado. La validación runtime de
sesiones reales, respuestas 403, redirects y respuestas HTTP reales queda
pendiente de forma no bloqueante.

## DB-TEST y limpieza QA

El runner admite explícitamente:

```text
migrate
rollback
seed
db:test
status
```

`db:test` usa una lista explícita de checks obligatorios. No trata cualquier
booleano falso como fallo automáticamente: `QA_USER_CREATED_BY_TEST=false` es
metadata válida porque la suite no crea usuarios QA. Si un check obligatorio es
falso, el runner devuelve exit code distinto de cero.

Evidencia final de limpieza:

```text
QA_PRODUCTS_RESIDUALS=true
QA_SIMILAR_RELATIONS_RESIDUALS=true
QA_AUDIT_RESIDUALS=true
QA_USER_RESIDUALS=true
QA_AUDIT_RESIDUAL_COUNT=0
QA_USER_RESIDUAL_COUNT=0
QA_USER_CREATED_BY_TEST=false
```

En estos checks, `true` significa cero residuos.

El producto protegido `102016169` no fue usado como fixture y conserva su
integridad (`PROTECTED_PRODUCT_INTACT=PASS`).

## Fuera de alcance y rollback

PRODUCTOS-5 no altera precios, inventario, imágenes, series, lotes,
pedimentos, tickets, importaciones ni SMTP/outbox.

La migración puede revertirse únicamente mediante un rollback controlado y
autorizado, considerando dependencias operativas existentes. Las relaciones
normales se eliminan lógicamente y las pruebas usan exclusivamente IDs QA.
