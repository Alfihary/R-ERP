# ERP-INVENTARIO-SERIES-TRAZABILIDAD-1

## 1. Executive summary

Auditoría controlada de `INV-INT-004` sobre `r_erp_db_core_0_test`. No se
modificó código productivo, esquema, migraciones ni seeds. Los runners
existentes validan entrada serializada, existencia por serie, transferencia,
salida, duplicados, validaciones, auditoría y limpieza QA.

`AUDIT_STATUS=PASS_WITH_PARTIAL_ROLLBACK_EVIDENCE`
`INV_INT_004=PARTIAL`
`P0_COUNT=0`
`P1_COUNT=0`
`P2_COUNT=0`

La evidencia funcional es correcta para entrada/transferencia/salida y no se
detectaron inconsistencias persistentes. La clasificación permanece `PARTIAL`
porque no existe un hook productivo autorizado para provocar una falla
intermedia específica de rollback de transferencia/salida, y el runner
histórico de kardex tiene una aserción textual frágil de la ruta.

## 2. Serial model

`SERIAL_MODEL=PER_PRODUCT`

Un producto requiere series cuando `productos.controla_series = 1`. La serie
se crea bajo el producto y el servicio valida que las series entregadas sean
enteras, únicas dentro de la partida y coincidan con la cantidad.

`SERIAL_UNIQUENESS_SCOPE=PRODUCT_AND_SERIAL_NUMBER`

La unicidad física es `producto_series (id_producto, numero_serie)`. El mismo
número puede existir asociado a otro producto sólo si el modelo lo permite por
esa clave compuesta; el servicio, sin embargo, busca siempre por producto y
rechaza una serie inexistente para el producto de la operación.

## 3. Technical map and tables

| Tabla | Propósito | Relaciones/índices relevantes |
| --- | --- | --- |
| `producto_series` | Identidad de número de serie por producto | PK `id`; FK a `productos`; UNIQUE `(id_producto, numero_serie)`; índices por producto/número/estado |
| `existencias_serie` | Estado y almacén actual de la serie | PK `serie_id`; FK a `producto_series` y `almacenes`; índices por almacén/estado |
| `movimiento_detalle_series` | Liga serie-detalle de movimiento | PK `(movimiento_detalle_id, serie_id)`; FKs a detalle y serie |
| `existencias_producto` | Saldo agregado por almacén/producto | UNIQUE `(almacen_id, id_producto)`; FK a almacén/producto |
| `movimientos_inventario` | Encabezado aplicado/borrador/anulado | PK `id`; FKs a empresa, almacén, concepto y usuarios |
| `movimientos_inventario_detalle` | Cantidad por producto | UNIQUE `(movimiento_id, id_producto)`; FKs a movimiento, producto y usuario |

Servicios: `InventoryService` y `InventoryTransferService`. Repositorio de
escritura: `InventoryRepository`. Consultas read-only: métodos
`serialStock`, `serialKardex` y `serialKardexSummary` de
`InventoryQueryRepository`. UI: rutas GET de existencias/kardex por serie,
protegidas por `AuthMiddleware`, `PermissionMiddleware` y el layout existente.

## 4. Fixture QA and cycle

El runner [inventario_series_trazabilidad_1_test.php](../database/tests/inventario_series_trazabilidad_1_test.php)
compone los runners aprobados de servicio y existencias por serie. Esos
runners crean productos, empresa/almacenes y series con prefijos QA propios,
registran conteos before/during/after y ejecutan cleanup. El producto
`102016169` nunca se utiliza destructivamente.

La evidencia de servicio confirma:

- entrada de dos series: PASS;
- rechazo de serie duplicada: true;
- transferencia serializada: PASS;
- salida serializada: PASS;
- serie fuera del almacén origen: rechazada;
- serie inexistente: rechazada;
- doble salida/salida sin existencia: rechazada;
- cantidad y número de series incompatibles: rechazados;
- rollback total de entrada con partida serializada inválida: PASS.

Los identificadores equivalentes usados por los runners son temporales y se
eliminan al terminar; no se introducen `SER-QA-001` persistentes.

## 5. Existence, stock and trace

`existencias_serie` representa una sola fila actual por serie; una serie en
`EN_EXISTENCIA` tiene un solo almacén y una serie `FUERA_EXISTENCIA` no tiene
almacén. El saldo agregado se actualiza junto con la existencia serializada.

El runner de existencias por serie confirmó filtros por serie/producto/estado/
almacén, estados `EN_EXISTENCIA` y `FUERA_EXISTENCIA`, aislamiento por empresa
y lectura sin cambios de conteos.

`SERIAL_EXISTENCE_QUERY_RESULT=PASS`
`SERIAL_STOCK_ENTRY_MATCH=PASS`
`SERIAL_MULTI_WAREHOUSE_AFTER_TRANSFER=false`

La implementación de kardex general y kardex por serie está localizada en
`InventoryQueryRepository`; el runner histórico `kardex_series_1_test.php`
contiene una aserción de formato textual de ruta que devuelve
`route_get_exists` aunque la ruta funcional existe. Se clasifica como
`INCONCLUSIVE` documental, no como fallo de datos, y no se modificó el runner
histórico en esta fase.

## 6. Rollbacks, concurrency and idempotency

`SERIAL_ENTRY_ROLLBACK=PASS` por rollback transaccional con fallo de partida
serializada. `SERIAL_TRANSFER_ROLLBACK` y `SERIAL_EXIT_ROLLBACK` quedan
`INCONCLUSIVE_NO_PRODUCTION_FAILURE_HOOK`: no se agregó un hook de fallo sólo
para pruebas.

`SERIAL_CONCURRENCY_RESULT=NOT_EXECUTED_BY_THIS_AUDIT`; la cobertura de
concurrencia e idempotencia permanece en
`inventario_concurrencia_auditoria_implementacion_1_test.php`, sin duplicar
series, stock o auditoría.

## 7. Consistency scan

Después del cleanup:

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

No se tocaron tickets 34/197 ni outbox 1/36/698/699/700/1610.

## 8. Findings

| ID | Área | Clasificación | Severidad | Evidencia | Acción |
| --- | --- | --- | --- | --- | --- |
| SER-TRACE-001 | Servicio serializado | `CONFIRMED_SAFE` | INFO | Entrada, transferencia, salida y validaciones pasan; cleanup cero | Conservar contrato |
| SER-TRACE-002 | Rollback transfer/salida | `INCONCLUSIVE` | P2 | No hay hook productivo de fallo intermedio autorizado | Diseñar harness transaccional posterior |
| SER-TRACE-003 | Runner kardex | `THEORETICAL_RISK` | P2 | Aserción textual `route_get_exists` frágil | Normalizar test en fase separada; no corregir ahora |

No se detectó P0: no hubo doble ubicación, doble salida, divergencia
persistentemente almacenada, transferencia parcial ni scope cross-company
indebido.

## 9. Regressions and scope

El nuevo runner pasó el servicio y existencias por serie. El runner global
`series.php db:test` queda bloqueado por el falso negativo histórico de
`kardex_series_1_test.php`; esto se reporta, no se oculta ni se corrige aquí.

No se modificaron servicios, repositorios, controladores, vistas, schema,
migraciones, seeds ni permisos. No hubo staging, commit, push, deploy, SMTP ni
acceso a otra base.

## 10. Conclusion and next phase

`INV_INT_004=PARTIAL`: la trazabilidad funcional principal está demostrada y
la base queda consistente, pero el cierre total requiere una fase posterior
para normalizar el runner de kardex y probar fallos intermedios de transferencia
y salida con un mecanismo de prueba seguro.

`RECOMMENDED_NEXT_PHASE=ERP-INVENTARIO-SERIES-ROLLBACK-HARNESS-1`
