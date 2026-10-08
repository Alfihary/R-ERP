# ERP-INVENTARIO-INTEGRIDAD-1

## Resultado ejecutivo

`AUDIT_STATUS=PASS_WITH_FINDINGS`

La auditoría se ejecutó exclusivamente sobre `r_erp_db_core_0_test`, con
`APP_ENV=local`. El runner de esta fase es SELECT-only y reportó
`persistent_writes=0`; las pruebas transaccionales existentes de movimientos y
transferencias usaron fixtures QA con rollback y dejaron los conteos
persistentes iguales a los iniciales.

No se modificó código productivo, esquema, migraciones, seeds, `.env`, correo,
SMTP ni datos de tickets/outbox.

## Alcance y límites

Esta fase revisa integridad estructural, invariantes de inventario, límites de
empresa/almacén, transacciones, rollback, series, folios, kardex y evidencia de
auditoría. No crea tablas, no ejecuta migraciones, no crea seeds y no cierra
hallazgos funcionales.

La prueba real de dos conexiones concurrentes no se ejecutó desde el runner
CLI; queda pendiente como `TRANSFER-CONCURRENCY-QA-1`/prueba equivalente. La
ausencia de fixtures de series impide una prueba mutacional de trazabilidad.

## Evidencia de base de datos

Base confirmada: `r_erp_db_core_0_test`.

| Tabla | Filas | Engine | Observación |
| --- | ---: | --- | --- |
| `productos` | 12 | InnoDB | catálogo presente |
| `producto_precios` | 15 | InnoDB | precios presentes |
| `empresas` | 8 | InnoDB | alcance por empresa |
| `almacenes` | 14 | InnoDB | alcance por almacén |
| `movimientos_inventario` | 0 | InnoDB | sin residuos persistentes |
| `movimientos_inventario_detalle` | 0 | InnoDB | sin residuos persistentes |
| `existencias_producto` | 0 | InnoDB | balance materializado vacío |
| `producto_series` | 0 | InnoDB | sin fixtures serializados |
| `existencias_serie` | 0 | InnoDB | sin fixtures serializados |
| `movimiento_detalle_series` | 0 | InnoDB | sin trazas serializadas |
| `series_documentales` | 2 | InnoDB | series de folios |
| `documentos_folios` | 5 | InnoDB | folios existentes |
| `auditoria_eventos` | 107 | InnoDB | eventos históricos |
| `usuario_empresas` | 8 | InnoDB | alcance asignado |
| `usuario_almacenes` | 14 | InnoDB | alcance asignado |

Producto protegido `102016169` (`REFRIGERANTE R-410A 5KG IGAS`): activo,
`prices=2`, `stock=0.000000`. Permaneció intacto.

## Modelo e invariantes

- `STOCK_MODEL=MATERIALIZED_BALANCE`: el saldo vive en
  `existencias_producto.cantidad_actual` y se actualiza dentro de la misma
  transacción del movimiento.
- `STOCK_NEGATIVE_POLICY=FORBIDDEN`: las salidas insuficientes son rechazadas
  antes de confirmar.
- La unicidad de series es `(id_producto, numero_serie)`; su ubicación vigente
  se representa en `existencias_serie`.
- Las transferencias no tienen tabla independiente: son un movimiento de
  salida y otro de entrada, creados atómicamente.
- Los folios se validan por empresa, almacén, tipo, serie, número y año.
- Los hallazgos estructurales fueron cero: desbalances de stock, huérfanos,
  duplicados de folio y duplicados de serie.

Clasificación conservadora: `INVARIANTS_PASS=9`, `INVARIANTS_FAIL=1`,
`INVARIANTS_NOT_TESTED=3`. El fallo es de cobertura de auditoría funcional; los
tres no probados son concurrencia real, idempotencia y trazabilidad serial con
datos activos.

## Movimientos y transferencias

Los runners existentes se ejecutaron con base y confirmación idénticas:

```text
php database/inventario-service.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/transferencias.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

Los casos positivos y negativos, precisión decimal, stock insuficiente,
rollback multipartida, alcance de usuario, transferencia entre almacenes y
rechazo de origen igual a destino pasaron. Los conteos persistentes antes y
después quedaron iguales; el cleanup de fixtures fue transaccional.

`MOVEMENT_TRANSACTIONAL=true`, `MOVEMENT_ROLLBACK=PASS`,
`TRANSFER_TRANSACTIONAL=true`, `TRANSFER_ATOMICITY=PASS`,
`TRANSFER_SELF_REJECTED=PASS`, `TRANSFER_INSUFFICIENT_STOCK=PASS`,
`SCOPE_COMPANY_RESULT=PASS` y `SCOPE_WAREHOUSE_RESULT=PASS`.

## Bloqueos, concurrencia e idempotencia

`LOCK_STRATEGY=PDO transaction + SELECT FOR UPDATE; no GET_LOCK`. El código
bloquea existencias y series dentro de la transacción y ordena las operaciones
de transferencia. Esto es una base correcta, pero no sustituye una prueba con
dos conexiones concurrentes.

`CONCURRENCY_STOCK_TEST=NOT_EXECUTED` y `FOLIO_CONCURRENCY_TEST=NOT_EXECUTED`.
`CONCURRENCY_RISK=MEDIUM`.

`IDEMPOTENCY_RISK=MEDIUM/HIGH`: no se evidenció una clave única de referencia o
deduplicación para impedir que un POST repetido cree dos movimientos válidos.
Esto es un hallazgo P1 antes de exponer reintentos automáticos o workers.

## Series, kardex y folios

`SERIAL_SCOPE=PRODUCT` y `SERIAL_UNIQUENESS=PASS` por índice y consulta
estructural. `SERIAL_MULTI_WAREHOUSE_COUNT=0` y
`SERIAL_STOCK_MISMATCH_COUNT=0`; no hay filas serializadas que permitan una
prueba de movimiento real.

`SERIAL_TRACE_RESULT=NOT_TESTED`. `KARDEX_BALANCE_RESULT=PARTIAL`: la lógica y
el modelo fueron revisados y los runners validaron historial QA, pero no quedó
historial persistente en la base después del cleanup.

`FOLIO_UNIQUENESS=PASS` estructuralmente y por consulta de duplicados.
`FOLIO_ROLLBACK_POLICY=ROLLBACK` cuando `FolioService` participa en la
transacción de inventario. La ruta independiente de generación de folio debe
evitar `MAX(id)+1` si llegara a utilizarse bajo concurrencia.

## Auditoría y seguridad de alcance

La tabla `auditoria_eventos` existe y conserva 107 eventos históricos, pero los
servicios de movimiento y transferencia no invocan directamente `AuditService`
en la ruta revisada. Por ello `AUDIT_TRAIL_STATUS=PARTIAL` y se registra
`INV-INT-001` como hallazgo P1: definir y probar el evento de dominio de cada
movimiento, transferencia, serie y folio antes de considerar cobertura completa.

Las validaciones de empresa, almacén, usuario, producto activo y permisos de
servicio se ejecutaron en los runners existentes. No se creó ningún permiso
funcional nuevo.

## Hallazgos y riesgo

| ID | Prioridad | Hallazgo | Acción recomendada |
| --- | --- | --- | --- |
| `INV-INT-001` | P1 | Cobertura de auditoría de inventario parcial | integrar evento auditado en el servicio transaccional y probarlo |
| `INV-INT-002` | P1 | Idempotencia y concurrencia real no demostradas | agregar referencia/dedupe única y prueba de dos conexiones |
| `INV-INT-003` | P2 | `MAX(id)+1` no es seguro si se usa para folios concurrentes | usar bloqueo/contador transaccional o índice y reintento controlado |
| `INV-INT-004` | P2 | No existen fixtures para trazabilidad serial | abrir una prueba QA serial aislada y reversible |
| `INV-INT-005` | INFO | No hay CHECK global de stock negativo | conservar validación de servicio y considerar restricción adicional |

`P0=0`, `P1=2`, `P2=2`.

## Resultado técnico

```ini
AUDIT_STATUS=PASS_WITH_FINDINGS
DB_NAME=r_erp_db_core_0_test
DB_PERSISTENT_WRITES=0
STOCK_MODEL=MATERIALIZED_BALANCE
STOCK_NEGATIVE_POLICY=FORBIDDEN
MOVEMENT_TRANSACTIONAL=true
TRANSFER_TRANSACTIONAL=true
TRANSFER_ATOMICITY=PASS
SERIAL_TRACE_RESULT=NOT_TESTED
KARDEX_BALANCE_RESULT=PARTIAL
CONCURRENCY_STOCK_TEST=NOT_EXECUTED
FOLIO_CONCURRENCY_TEST=NOT_EXECUTED
AUDIT_TRAIL_STATUS=PARTIAL
DB_PROTECTED_PRODUCT_UNCHANGED=true
MAIL_TICKET_OUTBOX_TOUCHED=false
```

No se avanzó a módulos nuevos. No se ejecutaron SMTP, deploy, staging,
commit, push, migraciones ni seeds. El siguiente paso recomendado es una fase
separada para cerrar `INV-INT-001`/`INV-INT-002` y ejecutar concurrencia real,
con autorización explícita.
