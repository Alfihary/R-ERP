# ERP-INVENTARIO-CONCURRENCIA-AUDITORIA-1

## Executive summary

`AUDIT_STATUS=PASS_WITH_FINDINGS`

La fase se ejecutó exclusivamente sobre `r_erp_db_core_0_test` con
`APP_ENV=local`. Se usaron dos conexiones PDO independientes para comprobar
locks reales y los servicios actuales para retries secuenciales. No se
implementaron correcciones productivas.

Resultados reproducidos:

- `FOR UPDATE` bloquea una segunda conexión sobre existencias y series.
- Un retry lógico de movimiento crea dos movimientos y duplica el efecto de
  stock (`2x`).
- Un retry lógico de transferencia crea dos pares de movimientos.
- No se generaron eventos de auditoría para esas operaciones.
- La concurrencia end-to-end de dos servicios quedó `INCONCLUSIVE`; no se
  afirma PASS sin dos workers completos.
- Las fixtures se limpiaron y todos los conteos volvieron a su valor inicial.

No se agregaron locks, `GET_LOCK()`, claves de idempotencia, constraints,
migraciones, seeds ni cambios productivos de auditoría o folios.

## Scope and safety gates

```ini
BRANCH=jesus
HEAD=91598d5
DB_NAME=r_erp_db_core_0_test
APP_ENV=local
SMTP=false
DB_PERSISTENT_WRITES=0
```

No se tocaron los tickets 34/197 ni las outbox 1, 36, 698, 699, 700 y 1610.
El producto protegido `102016169` no se utilizó como fixture.

## Existing locking model

| OPERATION | RESOURCE | LOCK_TYPE | LOCK_ORDER | TRANSACTION_SCOPE | UNIQUE_CONSTRAINT | RACE_WINDOW |
| --- | --- | --- | --- | --- | --- | --- |
| Entrada | existencia por almacén/producto | `FOR UPDATE` | productos ordenados | movimiento completo | `(almacen_id,id_producto)` | validación bajo lock |
| Salida | existencia por almacén/producto | `FOR UPDATE` | productos ordenados | movimiento completo | `(almacen_id,id_producto)` | validación bajo lock |
| Transferencia | origen y destino | `FOR UPDATE` | origen, luego destino | transferencia completa | existencia única | dos workers no probados |
| Folio | fila de serie documental | `FOR UPDATE` | una serie | emisión completa | scope documental | contador protegido |

```ini
LOCK_STRATEGY_STOCK=PDO transaction + SELECT FOR UPDATE
LOCK_STRATEGY_TRANSFER=origin then destination, deterministic product order
LOCK_STRATEGY_FOLIO=series row FOR UPDATE + counter update
GET_LOCK=false
LOCK_ORDER_STABLE=true
DEADLOCK_RISK=LOW_BY_CODE_REVIEW
```

## Two-connection harness

Tooling:

```text
database/tests/inventario_concurrencia_auditoria_1_test.php
```

El harness abre dos PDO independientes, fija `innodb_lock_wait_timeout=1`,
mantiene una transacción bloqueando la fila y confirma que la segunda recibe el
bloqueo. Las sondas pasaron:

```ini
STOCK_LOCK_PROBE=PASS_LOCK_SERIALIZATION
FOLIO_LOCK_PROBE=PASS_LOCK_SERIALIZATION
```

Esto valida el primitive de lock, no una ejecución simultánea completa de dos
requests de aplicación.

## Stock, transferencias y lost update

```ini
STOCK_CONCURRENCY_RESULT=INCONCLUSIVE
FINAL_STOCK=NOT_APPLICABLE_SERVICE_LEVEL
SUCCESSFUL_OPERATIONS=NOT_APPLICABLE_SERVICE_LEVEL
FAILED_OPERATIONS=NOT_APPLICABLE_SERVICE_LEVEL
LOST_UPDATE_RISK=THEORETICAL
TRANSFER_CONCURRENCY_RESULT=INCONCLUSIVE
```

La revisión encontró deltas SQL (`cantidad_actual +/- cantidad`) y locks antes
de validar saldo. No se observó una escritura absoluta insegura, pero falta una
prueba con dos servicios concurrentes completos. El orden de locks de una
transferencia es estable y el rollback transaccional ya pasó en DB-TEST.

## Idempotency and retry reproduction

```ini
MOVEMENT_IDEMPOTENCY_CONTROL=NONE
MOVEMENT_RETRY_RESULT=DUPLICATED
STOCK_EFFECT_MULTIPLIER=2x
TRANSFER_IDEMPOTENCY_CONTROL=NONE
TRANSFER_RETRY_RESULT=DUPLICATED
```

El movimiento inicial y el segundo tuvieron IDs distintos. La transferencia
repitió sus dos encabezados de salida/entrada. `INV-INT-002=CONFIRMED_BUG`, P1:
un retry lógico puede duplicar inventario.

## Folios

La ruta activa bloquea `series_documentales` con `FOR UPDATE`, usa
`siguiente_numero`, inserta `documentos_folios` y actualiza el contador dentro
de la transacción.

```ini
FOLIO_GENERATION_PATTERN=COUNTER_ROW
FOLIO_SCOPE=empresa + almacen + tipo_documento + codigo_serie + anio
FOLIO_CONCURRENCY_RESULT=PASS_LOCK_SERIALIZATION
DUPLICATE_FOLIO_CREATED=false
```

También existe `FolioRepository::nextFolioId()` con `MAX(id)+1`, pero no es la
ruta activa observada: `INV-INT-003=THEORETICAL_RISK`, P2.

## Auditoría

```ini
MOVEMENT_AUDIT_HOOK=NONE
TRANSFER_AUDIT_HOOK=NONE
MOVEMENT_AUDIT_COMPLETENESS=MISSING
TRANSFER_AUDIT_COMPLETENESS=MISSING
AUDIT_ROLLBACK_CONSISTENCY=NOT_TESTED
```

`auditoria_eventos` permaneció en 107 filas y no apareció evento QA para las
referencias de movimiento o transferencia. `INV-INT-001=CONFIRMED_BUG`, P1.
No se fabricaron eventos ni se ocultó el resultado.

## Security, RBAC and CSRF

Las rutas mutantes continúan bajo AuthMiddleware, PermissionMiddleware y
CsrfMiddleware según la revisión previa. El harness no modifica rutas,
middleware, usuarios, roles, permisos ni sesiones.

## Findings and severity

| ID | Clasificación | Severidad | Evidencia |
| --- | --- | --- | --- |
| `INV-INT-001` | `CONFIRMED_BUG` | P1 | cero eventos después de movimiento/transferencia |
| `INV-INT-002` | `CONFIRMED_BUG` | P1 | retries duplicados; efecto de movimiento `2x` |
| `INV-INT-003` | `THEORETICAL_RISK` | P2 | método `MAX(id)+1` fuera de la ruta activa |

```ini
P0_COUNT=0
P1_COUNT=2
P2_COUNT=1
```

No hubo stock negativo persistente, transferencia parcial ni folio duplicado
operativo; por eso no se asignó P0.

## Reproducción y cleanup

La invocación requiere confirmar dos veces la DB y rechaza producción:

```text
php -r 'define("BASE_PATH", getcwd()); $c=require BASE_PATH . "/bootstrap/database.php"; $d=$c->get("database",[]); if(($d["name"]??"")!=="r_erp_db_core_0_test" || strtolower((string)$c->get("app.env","production"))==="production") throw new RuntimeException("unsafe preflight"); $pdo=App\\Infrastructure\\Database\\Connection::create($d); $t=require BASE_PATH . "/database/tests/inventario_concurrencia_auditoria_1_test.php"; echo json_encode($t->run($pdo,"r_erp_db_core_0_test"),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;'
```

Conteos antes y después:

```text
productos                         12 -> 12
almacenes                         14 -> 14
existencias_producto               0 -> 0
movimientos_inventario             0 -> 0
movimientos_inventario_detalle     0 -> 0
producto_series                     0 -> 0
existencias_serie                   0 -> 0
movimiento_detalle_series           0 -> 0
auditoria_eventos                 107 -> 107
documentos_folios                  5 -> 5
```

```ini
FIXTURE_CLEANUP=true
DB_PERSISTENT_WRITES=0
PROTECTED_PRODUCT_INTACT=true
```

Producto protegido final: `activo=1`, 2 precios y stock `0.000000`.

## Recommended fix phases

Esta fase solo demuestra riesgos. La siguiente fase debe diseñar y probar una
clave de idempotencia, integrar eventos de auditoría transaccionales, ejecutar
dos workers reales y revisar cualquier consumidor de `MAX(id)+1` antes de
implementar cambios.

```ini
RECOMMENDED_NEXT_PHASE=ERP-INVENTARIO-CONCURRENCIA-AUDITORIA-IMPLEMENTACION-1
```

No se hizo staging, commit, push, deploy ni SMTP.
