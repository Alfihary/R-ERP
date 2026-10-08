# ERP-INVENTARIO-HTTP-FOLIOS-AUDIT-1

## Executive summary

`AUDIT_STATUS=PASS_WITH_P1_FINDING`

La revisión fue solamente diagnóstica y se ejecutó contra
`r_erp_db_core_0_test`. No se modificaron servicios, repositorios,
controladores, vistas, rutas, migraciones ni seeds.

Se confirmó un defecto HTTP de integración: los formularios generan una
`idempotency_key`, pero ambos métodos `formValues()` omiten ese campo antes de
renderizar la vista. El hidden input queda vacío y el POST normalizado por la
interfaz recibe `422` antes de llegar al servicio.

```ini
FINDING_HTTP_IDEMPOTENCY_KEY=P1_CONFIRMED_BUG
MOVEMENT_HTTP_SUCCESS=FAIL_UI_KEY_NOT_RENDERED
TRANSFER_HTTP_SUCCESS=FAIL_UI_KEY_NOT_RENDERED
```

No se corrigió durante esta fase.

## HTTP movement flow

```ini
MOVEMENT_HTTP_GET=GET /inventario/movimientos/crear
MOVEMENT_HTTP_POST=POST /inventario/movimientos
MOVEMENT_HTTP_CONTROLLER=App\\Http\\Controllers\\InventoryController
MOVEMENT_HTTP_SERVICE=App\\Domain\\Inventory\\InventoryService::aplicarMovimiento
```

La ruta GET y el POST pasan por `AuthMiddleware` y
`PermissionMiddleware('inventario.movimientos.crear')`. CSRF se aplica en el
middleware global del router.

El controlador redirige después de un POST válido:

```text
POST -> 302 /inventario/movimientos/ver?id=...&result=created
```

## HTTP transfer flow

```ini
TRANSFER_HTTP_GET=GET /inventario/transferencias/crear
TRANSFER_HTTP_POST=POST /inventario/transferencias
TRANSFER_HTTP_CONTROLLER=App\\Http\\Controllers\\InventoryTransferController
TRANSFER_HTTP_SERVICE=App\\Domain\\Inventory\\InventoryTransferService::transferir
```

La ruta GET y el POST pasan por `AuthMiddleware` y
`PermissionMiddleware('inventario.transferencias.crear')`. CSRF se aplica en
el middleware global.

```text
POST -> 302 /inventario/transferencias/ver?ref=...&result=created
```

## Idempotency key lifecycle

```ini
MOVEMENT_KEY_GENERATION_POINT=InventoryController::defaultValues(), random_bytes(16)
TRANSFER_KEY_GENERATION_POINT=InventoryTransferController::defaultValues(), random_bytes(16)
MOVEMENT_KEY_VALIDATION=backend, /^[A-Za-z0-9._~-]{16,128}$/
TRANSFER_KEY_VALIDATION=backend, /^[A-Za-z0-9._~-]{16,128}$/
```

Hallazgo confirmado:

- `defaultValues()` sí crea una key nueva por carga inicial del formulario;
- `formValues()` no devuelve `idempotency_key`;
- las vistas sí imprimen `values['idempotency_key']`;
- por tanto el hidden input queda vacío en GET normal;
- el POST del usuario recibe `422` por key ausente;
- la key no llega al servicio desde el flujo UI actual.

Consecuencias observadas por análisis estático:

```ini
MOVEMENT_HTTP_RETRY_RESULT=INCONCLUSIVE_UI_BLOCKED
TRANSFER_HTTP_RETRY_RESULT=INCONCLUSIVE_UI_BLOCKED
MOVEMENT_HTTP_CONFLICT_RESULT=HTTP_409_EXPECTED_BY_CONTROLLER
TRANSFER_HTTP_CONFLICT_RESULT=HTTP_409_EXPECTED_BY_CONTROLLER
MOVEMENT_VALIDATION_ERROR_KEY_REUSABLE=true
TRANSFER_VALIDATION_ERROR_KEY_REUSABLE=true
HTTP_DOUBLE_SUBMIT_PROTECTED=false
```

El backend sí conserva la misma key cuando un POST explícito la envía y el
servicio ya fue validado en DB-TEST-CORE como `DEDUPED`; la protección de doble
submit no es alcanzable desde el formulario hasta corregir el transporte de la
key.

## Conflict, CSRF, Auth y RBAC

```ini
HTTP_409_SAFE=true
CSRF_PRESERVED=true
AUTH_PROTECTION=AuthMiddleware en GET/POST mutantes
RBAC_PROTECTION=PermissionMiddleware por permiso de inventario
POST_REDIRECT_GET_MOVEMENT=true
POST_REDIRECT_GET_TRANSFER=true
```

`InventoryIdempotencyConflictException` se convierte en `409` con mensaje
genérico; no se expone fingerprint, stack trace ni la key. La key de
idempotencia es independiente del token CSRF.

El PRG existe en el código, aunque la ruta UI no alcanza el éxito mientras la
key siga omitida por `formValues()`.

## HTTP tests

No se creó un harness HTTP nuevo porque el análisis del pipeline existente
identificó el bloqueo determinista antes de ejecutar una operación. Se validó
el contrato por inspección de Router, middlewares, controladores, vistas y
servicios; los runners DB existentes validaron la ejecución con key explícita.

```ini
HTTP_REAL_POST=NOT_EXECUTED_UI_BLOCKED
HTTP_TEST_REASON=hidden idempotency_key vacío desde ambos formValues()
```

## Cleanup y alcance DB

Los runners oficiales ejecutados fueron:

```text
php database/folios-inventario.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/inventario-service.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/transferencias.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

Resultados relevantes:

```ini
FOLIO_DB_TEST=PASS
INVENTORY_DB_TEST=PASS
TRANSFER_DB_TEST=PASS
QA_DATA_RESIDUALS=0
```

Los conteos QA regresaron a cero después de cada runner. No se usó otra base,
no se tocó producción y no se ejecutó SMTP.

## FolioRepository y call graph

```ini
NEXT_FOLIO_ID_REFERENCE_COUNT=2
NEXT_FOLIO_ID_RUNTIME_CONSUMERS=0
```

Las dos líneas encontradas son la definición en
`app/Infrastructure/Repositories/FolioRepository.php:239` y una mención
documental previa. `nextFolioId()` ejecuta:

```sql
SELECT COALESCE(MAX(id), 0) + 1 FROM documentos_folios
```

No recibe scope y no tiene consumidor runtime activo. No fue eliminado ni
modificado.

La única relación de `FolioRepository` con folios activos se produce mediante
`FolioService::emitir()`:

```ini
ACTIVE_FOLIO_METHOD=FolioService::emitir()
ACTIVE_FOLIO_STORAGE=series_documentales + documentos_folios
ACTIVE_FOLIO_LOCK=findActiveSeriesForUpdate() ... FOR UPDATE
ACTIVE_FOLIO_SCOPE=empresa_id + almacen_id + tipo_documento + codigo_serie
LEGACY_MAX_PLUS_ONE_METHOD=FolioRepository::nextFolioId()
FOLIO_METHODS_SAME_STORAGE=false
FOLIO_RUNTIME_ENTRYPOINTS=InventoryService::emitMovementFolio(); InventoryTransferService::emitTransferFolio(); FolioService callers
INV_INT_003_RUNTIME_CLASSIFICATION=LEGACY_UNUSED_RISK
```

El método activo usa una fila de contador (`series_documentales.siguiente_numero`)
bloqueada con `FOR UPDATE`, inserta en `documentos_folios` y actualiza el
contador dentro de la misma transacción. `nextFolioId()` solo consulta
`documentos_folios` y no participa en ese call graph.

```ini
FOLIO_METHODS_SAME_STORAGE=false
P0_COUNT=0
P1_COUNT=1
P2_COUNT=1
```

El P1 es la key omitida del formulario. El P2 es el riesgo legacy no activo de
`MAX(id)+1`.

## Findings

| Hallazgo | Clasificación | Severidad | Evidencia | Acción |
|---|---|---:|---|---|
| `formValues()` omite `idempotency_key` en movimiento y transferencia | `CONFIRMED_BUG` | P1 | controladores líneas 745/341 y hidden inputs de ambas vistas | corregir en fase posterior |
| `nextFolioId()` no tiene consumidores runtime | `LEGACY_RISK` | P2 | única definición ejecutable; call graph activo usa `FolioService::emitir()` | no borrar ni refactorizar ahora |
| CSRF/Auth/RBAC y 409 genérico | `CONFIRMED_SAFE` | INFO | Router, middlewares y controladores | conservar |

## Protected data and restrictions

```ini
PROTECTED_PRODUCT=102016169 intacto (activo=1, precios=2, stock intacto)
PROTECTED_MAIL_UNTOUCHED=true
SMTP=false
DB_WRITES=QA_FIXTURES_ONLY_AND_CLEANED
PRODUCTION_DB=false
```

No se tocaron tickets `34`/`197` ni outbox `1`, `36`, `698`, `699`, `700`,
`1610`.

## Files

```ini
FILES_CREATED=docs/erp-inventario-http-folios-audit-1.md
FILES_MODIFIED=none
```

No hubo staging, commit, push ni deploy.

## Recommended next action

Abrir una fase correctiva separada para preservar `idempotency_key` en
`formValues()` de ambos controladores, añadir prueba HTTP real de GET/POST,
retry, conflicto y CSRF, y repetir la validación visual antes de versionar.
