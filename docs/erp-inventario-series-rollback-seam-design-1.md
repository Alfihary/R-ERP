# ERP-INVENTARIO-SERIES-ROLLBACK-SEAM-DESIGN-1

## 1. Executive summary

Esta microfase es únicamente de diseño y decisión. No implementa seams,
no modifica servicios productivos, no agrega switches de prueba y no ejecuta
pruebas mutantes.

La evidencia funcional existente es sólida: entrada, transferencia, salida,
kardex de series, idempotencia, auditoría, concurrencia y scans de consistencia
han pasado en `r_erp_db_core_0_test`. La brecha restante es específica: no hay
evidencia directa de una excepción **después de una mutación SQL y antes del
commit** en transferencia serializada y salida serializada.

La decisión recomendada es `IMPLEMENT_CLEAN_ARCHITECTURAL_SEAM` en una fase
posterior. El seam debe ser una mejora real de inversión de dependencias y
boundary transaccional, utilizable por producción y por pruebas, sin lógica
condicionada por entorno ni callbacks de fallo productivos.

```text
DESIGN_STATUS=PASS_WITH_DECISION
INV_INT_004=PARTIAL
PRODUCTION_TEST_SEAM_REQUIRED=true
RECOMMENDED_DECISION=IMPLEMENT_CLEAN_ARCHITECTURAL_SEAM
ACCEPT_RESIDUAL_TEST_GAP=false
```

## 2. Current evidence

Se conserva la evidencia de la fase anterior:

- operaciones serializadas de entrada, transferencia y salida: `PASS`;
- kardex de series y corrección de su falsa negativa histórica: `PASS`;
- idempotencia: `PASS`;
- auditoría: `PASS`;
- concurrencia de inventario: `PASS`;
- `SERIAL_MULTI_WAREHOUSE_COUNT=0`;
- `SERIAL_ORPHAN_EXISTENCE_COUNT=0`;
- `SERIAL_ORPHAN_MOVEMENT_LINK_COUNT=0`;
- `SERIAL_ACTIVE_WITHOUT_EXISTENCE_COUNT=0`;
- `SERIAL_STOCK_MISMATCH_COUNT=0`;
- `QA_DATA_RESIDUALS=0`;
- `P0_COUNT=0`, `P1_COUNT=0`, `P2_COUNT=0`.

El estado no se reclasifica como cierre total:

```text
SERIAL_ENTRY_ROLLBACK=PASS
SERIAL_TRANSFER_ROLLBACK=INCONCLUSIVE
SERIAL_EXIT_ROLLBACK=INCONCLUSIVE
```

## 3. Remaining evidence gap

La prueba pendiente requiere demostrar que una operación que ya insertó o
actualizó datos serializados falla antes de `COMMIT` y que la conexión revierte
todas las mutaciones: movimientos, detalles, vínculos de series, existencias,
estado de la serie, auditoría, idempotencia y folio cuando formen parte de la
misma frontera transaccional.

No se debe convertir `INCONCLUSIVE` en `PASS` por inferencia. Tampoco es un
bug funcional nuevo: es una limitación de testabilidad observada con todas las
invariantes actuales en cero.

## 4. Transfer transaction graph

Orden real observado en `InventoryTransferService::transferir()` y
`InventoryRepository::transactional()`:

| Paso | Operación | Tipo | Recurso | Puede fallar naturalmente | Ya hubo mutación | Trigger sin cambiar producción |
|---|---|---|---|---|---|---|
| TRANSFER_STEP_01 | `BEGIN` si el PDO no tiene transacción | READ/WRITE | PDO/InnoDB | Sí, PDO | No | harness de conexión |
| TRANSFER_STEP_02 | Usuario, empresa, almacenes, conceptos y productos | READ | tablas de identidad, alcance y catálogo | Sí | No | datos QA inválidos |
| TRANSFER_STEP_03 | Reserva de idempotencia `PENDIENTE` con `FOR UPDATE` | WRITE/READ | `inventario_operaciones_idempotencia` | Sí | Sí, dentro de tx | doble clave existente |
| TRANSFER_STEP_04 | Emisión del folio | WRITE | folio y serie documental | Sí | Sí, según el provider de folios | constraint/estado documental existente |
| TRANSFER_STEP_05 | Preparación de existencias destino | WRITE | `existencias_producto` | Sí | Sí | constraint existente |
| TRANSFER_STEP_06 | Inserción de movimiento de salida | WRITE | `movimientos_inventario` | Sí | Sí | FK/NOT NULL existentes |
| TRANSFER_STEP_07 | Inserción de detalles de salida | WRITE | `movimientos_inventario_detalle` | Sí | Sí | FK/UNIQUE existentes |
| TRANSFER_STEP_08 | Inserción de movimiento de entrada | WRITE | `movimientos_inventario` | Sí | Sí | FK/NOT NULL existentes |
| TRANSFER_STEP_09 | Inserción de detalles de entrada | WRITE | `movimientos_inventario_detalle` | Sí | Sí | FK/UNIQUE existentes |
| TRANSFER_STEP_10 | Bloqueo de existencias origen/destino | READ | `existencias_producto` | Sí | Sí, locks | concurrencia controlada |
| TRANSFER_STEP_11 | Validación y vínculos de series | WRITE | `movimiento_detalle_series` | Sí | Sí | constraints existentes |
| TRANSFER_STEP_12 | Cambio de ubicación/estado de series | WRITE | `existencias_serie` | Sí | Sí | constraints existentes |
| TRANSFER_STEP_13 | Disminución y aumento de existencias | WRITE | `existencias_producto` | Sí | Sí | error de PDO natural |
| TRANSFER_STEP_14 | Marcado `APLICADO` de ambos movimientos | WRITE | `movimientos_inventario` | Sí | Sí | constraint existente |
| TRANSFER_STEP_15 | Auditoría e idempotencia completada | WRITE | `auditoria_eventos`, `inventario_operaciones_idempotencia` | Sí | Sí | error de PDO natural |
| TRANSFER_STEP_16 | `COMMIT` | WRITE | PDO/InnoDB | Sí | punto final | error de commit |

El primer punto inequívoco de mutación SQL es `TRANSFER_STEP_03`; los puntos
de negocio visibles comienzan en `TRANSFER_STEP_04` y `TRANSFER_STEP_06`.
La operación completa queda bajo la transacción del repositorio.

## 5. Exit transaction graph

Orden real observado en `InventoryService::aplicarMovimiento()` para una
salida serializada:

| Paso | Operación | Tipo | Recurso | Puede fallar naturalmente | Ya hubo mutación | Trigger sin cambiar producción |
|---|---|---|---|---|---|---|
| EXIT_STEP_01 | `BEGIN` si corresponde | READ/WRITE | PDO/InnoDB | Sí | No | harness de conexión |
| EXIT_STEP_02 | Usuario, alcance, concepto y producto | READ | tablas de identidad, alcance y catálogo | Sí | No | datos QA inválidos |
| EXIT_STEP_03 | Reserva de idempotencia | WRITE/READ | `inventario_operaciones_idempotencia` | Sí | Sí, dentro de tx | doble clave existente |
| EXIT_STEP_04 | Emisión del folio si aplica | WRITE | folio y serie documental | Sí | Sí, según provider | estado documental existente |
| EXIT_STEP_05 | Inserción de movimiento | WRITE | `movimientos_inventario` | Sí | Sí | FK/NOT NULL existentes |
| EXIT_STEP_06 | Inserción de detalle | WRITE | `movimientos_inventario_detalle` | Sí | Sí | FK/UNIQUE existentes |
| EXIT_STEP_07 | Bloqueo y validación de existencia | READ | `existencias_producto` | Sí | Sí | saldo insuficiente |
| EXIT_STEP_08 | Vínculo y salida de series | WRITE | `movimiento_detalle_series`, `existencias_serie` | Sí | Sí | constraints existentes |
| EXIT_STEP_09 | Disminución de existencia | WRITE | `existencias_producto` | Sí | Sí | error de PDO natural |
| EXIT_STEP_10 | Marcado `APLICADO` y lectura del movimiento | WRITE/READ | `movimientos_inventario` | Sí | Sí | constraint existente |
| EXIT_STEP_11 | Auditoría e idempotencia completada | WRITE | `auditoria_eventos`, `inventario_operaciones_idempotencia` | Sí | Sí | error de PDO natural |
| EXIT_STEP_12 | `COMMIT` | WRITE | PDO/InnoDB | Sí | punto final | error de commit |

El primer punto de mutación posterior a las validaciones es `EXIT_STEP_03`
si se cuenta la reserva de idempotencia; el primer movimiento de inventario es
`EXIT_STEP_05`. La transacción del repositorio cubre todos los pasos.

## 6. Dependency map

| CLASS | DEPENDENCY | CONSTRUCTION_POINT | INTERFACE | INJECTABLE | REPLACEABLE_FROM_TEST | TRANSACTION_PARTICIPANT | FAILURE_CAPABLE | PRODUCTION_CHANGE_REQUIRED |
|---|---|---|---|---|---|---|---|---|
| `InventoryService` | `InventoryRepository` | bootstrap/container | No | Sí, concrete | No, clase `final` | Sí | Sí | Sí para puerto |
| `InventoryService` | `FolioService` | bootstrap/container | No | Sí, opcional | No, concrete | Indirecto | Sí | Sí para puerto |
| `InventoryService` | `InventoryIdempotencyRepository` | bootstrap/container | No | Sí, opcional | No, concrete | Sí | Sí | Sí para puerto |
| `InventoryService` | `AuditRepository` | bootstrap/container | No | Sí, opcional | No, concrete | Sí | Sí | Sí para puerto |
| `InventoryTransferService` | `InventoryRepository` | bootstrap/container | No | Sí, concrete | No, clase `final` | Sí | Sí | Sí para puerto |
| `InventoryTransferService` | `FolioService` | bootstrap/container | No | Sí, opcional | No, concrete | Indirecto | Sí | Sí para puerto |
| `InventoryTransferService` | idempotencia/auditoría | bootstrap/container | No | Sí, opcional | No, concrete | Sí | Sí | Sí para puerto |
| `InventoryRepository` | `ConnectionProvider` | bootstrap/container | No | Sí | No, clase `final` | Sí | Sí | Sí para abstracción |
| `InventoryIdempotencyRepository` | `ConnectionProvider` | bootstrap/container | No | Sí | No, clase `final` | Sí | Sí | Sí para interfaz |
| `AuditRepository` | `ConnectionProvider` | bootstrap/container | No | Sí | No, clase `final` | Sí | Sí | Sí para interfaz |
| `FolioRepository`/`FolioService` | `ConnectionProvider` | bootstrap/container | No | Sí | No, concrete | Sí propia/compartida | Sí | Sí para boundary explícito |
| `PDO` | MySQL/InnoDB | `Connection::create()` | PDO nativa | No desde servicio | No sin wrapper | Sí | Sí | No, pero wrapper ayuda |

Conclusión: hay construcción por inyección de constructor, pero no hay un
contrato sustituible. Las clases relevantes son concretas y `final`; no existe
un fake/subclass limpio para interrumpir después de una mutación.

## 7. Option A — dependencia ya inyectable

```text
OPTION_A_FEASIBLE=false
TARGET_DEPENDENCY=ninguna con contrato sustituible actual
FAILURE_POINT=ninguno post-mutación demostrable
PRODUCTION_CHANGE_REQUIRED=true
```

Auditoría y folios son inyectados como objetos, pero sus tipos concretos no
permiten reemplazo limpio. Además, el disparador existente
`__simulate_failure_after_folio` ocurre después de emitir el folio y antes de
crear movimientos/stock; no es evidencia del rollback serial solicitado y no
se recomienda ampliarlo.

## 8. Option B — test double / subclass

```text
OPTION_B_FEASIBLE=false
```

`InventoryService`, `InventoryTransferService`, `InventoryRepository` y los
repositorios participantes son concretos; varios son `final` y no hay
interfaces. Subclassing, monkey patching, globales o reflection destructiva
quedan descartados.

## 9. Option C — constraint existente

```text
OPTION_C_FEASIBLE=false
FAILURE_CONSTRAINT=ninguna que garantice el punto requerido
POST_MUTATION_GUARANTEED=false
```

Existen `UNIQUE`, FK, `NOT NULL`, checks y límites de longitud. Sin embargo,
las validaciones de servicio consumen los casos previsibles antes de mutar, y
forzar una constraint con datos inválidos no ofrece un trigger determinista ni
una prueba estable del flujo serial. No se creará una constraint temporal, no
se alterará el schema y no se desactivarán FKs.

## 10. Option D — transacción externa

```text
OPTION_D_FEASIBLE=false
EXTERNAL_TRANSACTION_CONTROL=PARTIAL
```

`InventoryRepository::transactional()` detecta `PDO::inTransaction()` y no
abre ni confirma una transacción que ya exista. Esto permite que un caller
controle parcialmente el rollback con el mismo `ConnectionProvider`, pero no
proporciona por sí solo una excepción post-mutación ni un punto de observación.
Por tanto, no satisface la evidencia requerida como solución autónoma.

## 11. Option E — process failure

```text
OPTION_E_FEASIBLE=false
OPTION_E_RELIABILITY=LOW
```

Un worker separado podría perder la conexión y dejar que MySQL revierta la
transacción, pero sin una barrera instrumentada no puede saberse desde otra
conexión si ya ocurrió la mutación no confirmada. Persisten carreras de tiempo,
portabilidad de terminar procesos en Windows, diferencias del hosting
AwardSpace, limpieza y falsos positivos. No se ejecutará en esta fase.

## 12. Option F — lock/deadlock controlado

```text
OPTION_F_FEASIBLE=false
DETERMINISTIC=false
SAFE=false
```

Un deadlock o lock timeout natural podría provocar rollback, pero no garantiza
el instante post-mutación, es sensible al plan de locks y puede afectar otros
workers. No se provocará un deadlock deliberado.

## 13. Option G — refactor arquitectónico

```text
OPTION_G_FEASIBLE=true
PROPOSED_REFACTOR=TransactionBoundary + InventoryMutationRepositoryInterface
PRODUCTION_VALUE=HIGH
TESTABILITY_VALUE=HIGH
COMPLEXITY=MEDIUM
REGRESSION_RISK=LOW_OR_MANAGEABLE
```

La siguiente fase puede introducir un boundary pequeño y explícito, por
ejemplo:

1. `TransactionBoundary` para comenzar, ejecutar, confirmar y revertir sin
   duplicar la política de `InventoryRepository::transactional()`.
2. Un puerto `InventoryMutationRepositoryInterface` para operaciones de
   mutación, con el adaptador PDO actual como implementación productiva.
3. Inyección de esos contratos en los servicios, conservando el mismo orden,
   locks, SQL, auditoría, idempotencia y comportamiento.
4. Un fake de test que delegue operaciones reales y lance una excepción después
   de una mutación seleccionada. El fake vive en `database/tests` y no requiere
   `APP_ENV`, flags, callbacks productivos ni dependencias externas.

El punto exacto, el tamaño del puerto y la estrategia de compatibilidad deben
ser diseñados y revisados antes de implementar. El refactor solo se acepta si
mantiene `BEHAVIOR_CHANGE=false`, `SECURITY_RISK=LOW` y compatibilidad PHP/MySQL
con AwardSpace.

## 14. Rejected test-only mechanisms

```text
TEST_MODE=false
QA_MODE=false
FAIL_AFTER_X=false
FORCE_EXCEPTION=false
SIMULATE_FAILURE=false
ROLLBACK_TEST=false
debug_callbacks=false
env_branches_for_test=false
secret_headers=false
qa_query_params=false
TEST_ONLY_PRODUCTION_HOOK_RECOMMENDED=false
```

No se recomienda añadir ni ampliar esos mecanismos. El flag histórico de fallo
después de folio, ya presente en el código, es anterior a las mutaciones de
movimientos/series y no debe reinterpretarse como el seam de esta fase.

## 15. Decision matrix

| OPTION | FEASIBLE | PROD_CHANGE | ARCHITECTURAL_VALUE | TEST_RELIABILITY | COMPLEXITY | RISK | RECOMMEND |
|---|---|---|---|---|---|---|---|
| A. Dependencia actual | LOW | HIGH | LOW | LOW | LOW | MEDIUM | NO |
| B. Double/subclass actual | LOW | HIGH | LOW | LOW | MEDIUM | HIGH | NO |
| C. Constraint existente | LOW | NONE | LOW | LOW | LOW | MEDIUM | NO |
| D. Transacción externa sola | MEDIUM | NONE | MEDIUM | LOW | LOW | MEDIUM | NO |
| E. Process failure | LOW | NONE | LOW | LOW | HIGH | HIGH | NO |
| F. Lock/deadlock | LOW | NONE | LOW | LOW | MEDIUM | HIGH | NO |
| G. Refactor arquitectónico limpio | HIGH | YES, controlado | HIGH | HIGH | MEDIUM | LOW_OR_MANAGEABLE | YES, fase posterior |

## 16. Recommended decision

```text
RECOMMENDED_DECISION=IMPLEMENT_CLEAN_ARCHITECTURAL_SEAM
IMPLEMENTATION_PHASE_JUSTIFIED=true
```

La recomendación única es diseñar e implementar posteriormente un boundary de
transacción y puertos de mutación. Cumple los criterios solo si la fase de
implementación confirma:

```text
PRODUCTION_SPECIFIC_TEST_LOGIC=false
ARCHITECTURAL_VALUE=true
BEHAVIOR_CHANGE=false
SECURITY_RISK=LOW
REGRESSION_RISK=LOW_OR_MANAGEABLE
AWARDSPACE_COMPATIBLE=true
NEW_EXTERNAL_DEPENDENCY=false
POST_MUTATION_FAILURE_DETERMINISTIC=true
```

## 17. INV_INT_004 recommendation

```text
INV_INT_004_RECOMMENDED_STATUS=PARTIAL
```

No corresponde `CLOSED`. La evidencia directa de rollback post-mutación en
transferencia y salida solo podrá cerrar la brecha en una fase posterior con
un seam limpio implementado y probado.

## 18. Inventory blocking assessment

```text
INVENTORY_CORE_BLOCKED=false
```

La brecha no revela un riesgo funcional nuevo: las operaciones pasan, los
locks son transaccionales, la idempotencia y auditoría están cubiertas, y los
scans de consistencia permanecen en cero. Por eso no bloquea por sí sola
usuarios/roles/permisos, compras, ventas, proveedores o clientes.

## 19. Recommended roadmap next phase

```text
RECOMMENDED_NEXT_PHASE=ERP-INVENTARIO-SERIES-ROLLBACK-SEAM-IMPLEMENTATION-1
```

Esa fase debe aprobar primero el contrato de `TransactionBoundary` y los
puertos, luego probar transferencia y salida con un fake que falle después de
una mutación, y finalmente repetir los scans de consistencia. Esta fase de
diseño no inicia esa implementación.

## 20. Scope and evidence controls

```text
PRODUCTION_FILES_MODIFIED=false
DB_WRITES=0
SMTP=false
TEST_ONLY_PRODUCTION_HOOK_RECOMMENDED=false
```

No se ejecutaron pruebas mutantes, no se modificaron archivos productivos, no
se tocaron migraciones/seeds, no hubo escritura DB, no hubo SMTP y no se hizo
staging, commit, push ni deploy.
