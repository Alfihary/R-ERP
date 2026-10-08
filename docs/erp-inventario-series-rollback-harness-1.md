# ERP-INVENTARIO-SERIES-ROLLBACK-HARNESS-1

## 1. Executive summary

`AUDIT_STATUS=PARTIAL_PRODUCTION_TEST_SEAM_REQUIRED`
`PRODUCTION_TEST_SEAM_REQUIRED=true`
`INV_INT_004=PARTIAL`

La fase revisó el call graph real, corrigió únicamente el falso negativo del
runner histórico de kardex y creó un harness QA que se niega a inventar fallos
intermedios. No se agregaron flags, callbacks ni parámetros de prueba a
servicios productivos.

## 2. Previous evidence

La fase anterior demostró entrada, transferencia, salida, validaciones,
auditoría y cleanup de series. El rollback de entrada tiene evidencia PASS;
transferencia y salida permanecían inconclusas por ausencia de un seam seguro.

## 3. Transaction call graph

### Entrada serializada

`BEGIN -> validación de request/producto/series -> locks de existencia y series
-> INSERT movimiento -> INSERT detalle -> crear/actualizar producto_series y
existencias_serie -> INSERT movimiento_detalle_series -> actualizar saldo ->
auditoría/idempotencia -> COMMIT`.

### Transferencia serializada

`BEGIN -> validación de empresa/almacenes/producto/series -> locks ordenados de
origen/destino y series -> INSERT movimiento salida + detalle -> INSERT
movimiento entrada + detalle -> INSERT links de serie -> actualizar saldo y
existencias_serie -> auditoría/idempotencia -> COMMIT`.

### Salida serializada

`BEGIN -> validación -> lock existencia y serie -> INSERT movimiento/detalle ->
INSERT link -> disminuir saldo -> marcar serie FUERA_EXISTENCIA -> auditoría/
idempotencia -> COMMIT`.

## 4. Failure-point strategy

Se buscaron constraints reales, collaborators sustituibles, repositorios fake,
workers QA y errores de repositorio. Los servicios reciben
`InventoryRepository` concreto y no una interfaz/double inyectable; tampoco
existe hook de error después de una mutación.

Por tanto:

```text
SERIAL_TRANSFER_FAILURE_POINT=NOT_EXECUTED_NO_SAFE_POST_MUTATION_SEAM
SERIAL_TRANSFER_FAILURE_AFTER_MUTATION=false
SERIAL_TRANSFER_ROLLBACK=INCONCLUSIVE
SERIAL_EXIT_FAILURE_POINT=NOT_EXECUTED_NO_SAFE_POST_MUTATION_SEAM
SERIAL_EXIT_FAILURE_AFTER_MUTATION=false
SERIAL_EXIT_ROLLBACK=INCONCLUSIVE
```

Una validación que falla antes del primer INSERT no se contabiliza como
rollback. No se modificó producción para fabricar un PASS.

## 5. Kardex historical false negative

El runner histórico fallaba en `route_get_exists` porque buscaba una secuencia
textual concreta de espacios/saltos de línea. La inspección semántica del
Router registró:

```text
KARDEX_SERIES_GET_ROUTE_REGISTERED=true
KARDEX_SERIES_GET_ROUTE_PATH=/inventario/kardex-series
KARDEX_SERIES_GET_ROUTE_HANDLER=InventorySerialKardexController::index
```

Se corrigió sólo `database/tests/kardex_series_1_test.php`: la aserción GET
usa dispatch real del Router y la aserción POST usa el registro semántico. No
se debilitó el contrato ni se modificó una ruta productiva.

```text
KARDEX_TEST_ROUTE_ASSERTION=TOOLING_BUG
KARDEX_HISTORICAL_FALSE_NEGATIVE_FIXED=true
KARDEX_SERIES_DBTEST_RESULT=PASS
```

## 6. Full trace and balances

Los runners existentes muestran eventos de entrada, salida de transferencia,
entrada de transferencia y salida final; la identidad de `producto_series` se
conserva y el historial permanece después de `FUERA_EXISTENCIA`.

```text
SERIAL_TRACE_EVENTS_FOUND=ENTRY,TRANSFER_EXIT,TRANSFER_ENTRY,EXIT
SERIAL_TRACE_SEQUENCE_VALID=PARTIAL_KARDEX_DELEGATED
SERIAL_FULL_TRACE_RESULT=PARTIAL
SERIAL_KARDEX_BALANCE_CONSISTENCY=PARTIAL_KARDEX_DELEGATED
SERIAL_IDENTITY_PRESERVED_ON_TRANSFER=true
SERIAL_HISTORY_PRESERVED_AFTER_EXIT=true
```

La parte pendiente es una evidencia aislada de rollback intermedio de
transferencia/salida, no una inconsistencia persistente de saldos.

## 7. Concurrency, locks, idempotency and audit

```text
SERIAL_CONCURRENCY_RESULT=NOT_EXECUTED
SERIAL_LOCK_STRATEGY=SELECT_FOR_UPDATE on stock and series rows; transactional InnoDB
SERIAL_LOCK_ORDER_STABLE=true
SERIAL_IDEMPOTENCY_RESULT=COVERED_BY_INVENTARIO_CONCURRENCIA_AUDITORIA_IMPLEMENTACION_1
SERIAL_AUDIT_RESULT=PASS
```

No se intentó una concurrencia de la misma serie sin una garantía adicional de
cleanup y aislamiento.

## 8. Consistency and cleanup

```text
SERIAL_MULTI_WAREHOUSE_COUNT=0
SERIAL_ORPHAN_EXISTENCE_COUNT=0
SERIAL_ORPHAN_MOVEMENT_LINK_COUNT=0
SERIAL_ACTIVE_WITHOUT_EXISTENCE_COUNT=0
SERIAL_STOCK_MISMATCH_COUNT=0
QA_DATA_RESIDUALS=0
PROTECTED_PRODUCT_INTACT=true
PROTECTED_MAIL_UNTOUCHED=true
SMTP=false
```

El harness exige `r_erp_db_core_0_test`, aborta en producción y no usa ni
modifica el producto `102016169`, tickets 34/197 ni outbox 1/36/698/699/700/1610.

## 9. Findings

| ID | Área | Clasificación | Severidad | Evidencia | Acción |
| --- | --- | --- | --- | --- | --- |
| RB-HARNESS-001 | Rollback transfer/salida | `INCONCLUSIVE` | P2 | Falta seam post-mutación y no se añadió uno | Diseñar collaborator/harness externo autorizado |
| RB-HARNESS-002 | Runner kardex | `TOOLING_BUG` | P2 | `route_get_exists` era una aserción de formato textual | Corregido en test; runner pasa |
| RB-HARNESS-003 | Concurrencia misma serie | `INCONCLUSIVE` | P2 | No ejecutada por seguridad de cleanup | Fase separada con workers aislados |

No hay P0/P1 y no se detectó bug productivo nuevo.

## 10. Production files unchanged

El único cambio productivo permitido no ocurrió. Se modificó únicamente:

- `database/tests/inventario_series_rollback_harness_1_test.php` (nuevo);
- `database/tests/kardex_series_1_test.php` (tooling histórico);
- `docs/erp-inventario-series-rollback-harness-1.md` (nuevo).

No se modificaron `app/`, `bootstrap/`, `routes/`, `config/`, migraciones,
schema ni seeds.

## 11. Recommended next phase

`ERP-INVENTARIO-SERIES-ROLLBACK-HARNESS-2`

Debe evaluar un collaborator o worker QA externo, sin flags permanentes en
producción, para demostrar rollback post-mutación de transferencia y salida y
concurrencia de una misma serie.
