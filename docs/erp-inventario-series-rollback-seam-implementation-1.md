# ERP-INVENTARIO-SERIES-ROLLBACK-SEAM-IMPLEMENTATION-1

## 1. Executive summary

Se implementó el seam arquitectónico aprobado para demostrar rollback
post-mutación/pre-commit en transferencia y salida serializadas. El código de
producción no conoce el fake ni contiene lógica de fallo de QA.

```text
IMPLEMENTATION_STATUS=PASS
TRANSACTION_BOUNDARY_IMPLEMENTED=true
TRANSACTION_BOUNDARY_DELEGATES_EXISTING_BEHAVIOR=true
MUTATION_PORT_IMPLEMENTED=true
TEST_ONLY_PRODUCTION_LOGIC_ADDED=false
BUSINESS_BEHAVIOR_CHANGED=false
NEW_EXTERNAL_DEPENDENCY=false
FOLIO_BEHAVIOR_CHANGED=false
```

## 2. Approved design

La implementación sigue el diseño aprobado en
`docs/erp-inventario-series-rollback-seam-design-1.md`:

- `TransactionBoundaryInterface` expresa la responsabilidad productiva de
  ejecutar una unidad de trabajo transaccional.
- `InventoryMutationRepositoryInterface` contiene únicamente los ocho
  mutadores necesarios para sustituir una mutación real desde pruebas.
- `InventoryRepository` implementa ambos contratos y conserva su SQL y su
  política existente de `beginTransaction`, `commit` y `rollBack`.

## 3. Files changed

Producción directamente relacionada:

- `app/Domain/Inventory/TransactionBoundaryInterface.php`
- `app/Domain/Inventory/InventoryMutationRepositoryInterface.php`
- `app/Infrastructure/Repositories/InventoryRepository.php`
- `app/Domain/Inventory/InventoryService.php`
- `app/Domain/Inventory/InventoryTransferService.php`

Prueba y documentación:

- `database/tests/inventario_series_rollback_seam_implementation_1_test.php`
- `docs/erp-inventario-series-rollback-seam-implementation-1.md`

No se modificaron controllers, routes, views, CSS, JavaScript, mail,
tickets, productos, migraciones ni seeds.

## 4. TransactionBoundary implementation

`InventoryRepository::transactional()` continúa siendo la implementación
productiva. El nuevo contrato solo formaliza el comportamiento existente:

```text
TRANSACTION_BOUNDARY_IMPLEMENTED=true
TRANSACTION_BOUNDARY_DELEGATES_EXISTING_BEHAVIOR=true
```

El servicio conserva el fallback al repositorio actual si no se proporciona
un boundary alternativo. No se duplicó `BEGIN`, `COMMIT` ni `ROLLBACK`.

## 5. Mutation port implementation

```text
MUTATION_PORT_IMPLEMENTED=true
MUTATION_PORT_METHOD_COUNT=8
```

El puerto incluye únicamente:

1. `createDraftMovement`
2. `insertMovementDetail`
3. `saveSeriesStock`
4. `insertMovementDetailSeries`
5. `ensureExistenceRow`
6. `increaseExistence`
7. `decreaseExistence`
8. `markMovementApplied`

Las lecturas, locks, validaciones, resultado, auditoría, idempotencia y folios
permanecen en sus colaboradores actuales.

## 6. Dependency composition

`InventoryService` e `InventoryTransferService` reciben opcionalmente el
puerto de mutación y el boundary. En composición productiva ambos hacen
fallback al `InventoryRepository` existente, por lo que no fue necesario
modificar bootstrap ni usar Service Locator o un contenedor nuevo.

El harness inyecta un fake solo desde la prueba; producción sigue recibiendo
el adaptador PDO real.

## 7. Production behavior preservation

```text
TEST_ONLY_PRODUCTION_LOGIC_ADDED=false
BUSINESS_BEHAVIOR_CHANGED=false
NEW_EXTERNAL_DEPENDENCY=false
FOLIO_BEHAVIOR_CHANGED=false
```

No se añadieron `APP_ENV` branches, `TEST_MODE`, `QA_MODE`,
`FAIL_AFTER_X`, `FORCE_EXCEPTION`, `SIMULATE_FAILURE`, `ROLLBACK_TEST`,
callbacks de prueba, headers secretos ni query params QA.

El flag histórico `__simulate_failure_after_folio` no se amplió ni se usó:
ocurre antes de las mutaciones relevantes de movimientos y series.

## 8. Test fake design

`SeamFailureAfterSeriesMutation` vive exclusivamente en
`database/tests/inventario_series_rollback_seam_implementation_1_test.php`.
Delega primero `saveSeriesStock()` al repositorio PDO real, consulta en la
misma conexión el estado modificado, confirma que la transacción está activa y
después lanza una excepción.

```text
TEST_FAKE_FAILURE_AFTER_REAL_MUTATION=true
```

No es un fake que simule una respuesta: la primera mutación SQL es real y la
excepción ocurre antes del commit.

## 9. Transfer post-mutation rollback

## 9. Entrada serializada post-mutation rollback

El arnés crea una serie QA sin existencia, ejecuta una entrada serializada y
provoca el fallo después de `saveSeriesStock()`. La serie, movimiento,
idempotencia y auditoría se verifican contra DB antes de `cleanup`:

```text
SERIAL_ENTRY_FAILURE_AFTER_MUTATION=true
SERIAL_ENTRY_STOCK_ROLLBACK=PASS
SERIAL_ENTRY_SERIAL_ROLLBACK=PASS
SERIAL_ENTRY_MOVEMENTS_ROLLBACK=PASS
ENTRY_AUDIT_BASELINE_COUNT=0
ENTRY_AUDIT_POST_ROLLBACK_COUNT=0
ENTRY_FALSE_SUCCESS_AUDIT_COUNT=0
SERIAL_ENTRY_AUDIT_ROLLBACK=PASS
SERIAL_ENTRY_IDEMPOTENCY_ROLLBACK=PASS
SERIAL_ENTRY_ROLLBACK=PASS
```

Fixture:

```text
SER-QA-SEAM-TRANSFER-001
stock origen=1
stock destino=0
```

Evidencia:

```text
SERIAL_TRANSFER_FAILURE_POINT=TRANSFER.saveSeriesStock
SERIAL_TRANSFER_FAILURE_AFTER_MUTATION=true
TRANSFER_MUTATION_OBSERVED_BEFORE_THROW=true
TRANSACTION_ACTIVE_DURING_TRANSFER_FAILURE=true
COMMIT_REACHED=false
ROLLBACK_OCCURRED=true
SERIAL_TRANSFER_STOCK_ROLLBACK=PASS
SERIAL_TRANSFER_SERIAL_ROLLBACK=PASS
SERIAL_TRANSFER_MOVEMENTS_ROLLBACK=PASS
TRANSFER_AUDIT_BASELINE_COUNT=0
TRANSFER_AUDIT_POST_ROLLBACK_COUNT=0
TRANSFER_FALSE_SUCCESS_AUDIT_COUNT=0
SERIAL_TRANSFER_AUDIT_ROLLBACK=PASS
SERIAL_TRANSFER_IDEMPOTENCY_ROLLBACK=PASS
SERIAL_TRANSFER_ROLLBACK=PASS
```

Después del rollback la serie siguió en el almacén origen, el destino quedó
en cero, no quedaron movimientos ni detalles y no quedó una reserva de
idempotencia persistente. La auditoría se consulta por acción, entidad y
`metadata_json.referencia` antes y después del rollback, antes de limpiar el
fixture.

## 10. Exit post-mutation rollback

Fixture:

```text
SER-QA-SEAM-EXIT-001
stock almacén=1
```

Evidencia:

```text
SERIAL_EXIT_FAILURE_POINT=EXIT.saveSeriesStock
SERIAL_EXIT_FAILURE_AFTER_MUTATION=true
EXIT_MUTATION_OBSERVED_BEFORE_THROW=true
TRANSACTION_ACTIVE_DURING_EXIT_FAILURE=true
COMMIT_REACHED=false
ROLLBACK_OCCURRED=true
SERIAL_EXIT_STOCK_ROLLBACK=PASS
SERIAL_EXIT_SERIAL_ROLLBACK=PASS
SERIAL_EXIT_MOVEMENTS_ROLLBACK=PASS
EXIT_AUDIT_BASELINE_COUNT=0
EXIT_AUDIT_POST_ROLLBACK_COUNT=0
EXIT_FALSE_SUCCESS_AUDIT_COUNT=0
SERIAL_EXIT_AUDIT_ROLLBACK=PASS
SERIAL_EXIT_IDEMPOTENCY_ROLLBACK=PASS
SERIAL_EXIT_ROLLBACK=PASS
```

La serie continuó disponible y la existencia regresó exactamente al baseline.

## 11. Idempotency rollback

Las reservas usadas por los escenarios fallidos no quedaron persistidas:

```text
TRANSFER_IDEMPOTENCY_AFTER_ROLLBACK=0
EXIT_IDEMPOTENCY_AFTER_ROLLBACK=0
```

No se cambió la política de idempotencia.

## 12. Audit rollback

No quedó evento de éxito falso. Los conteos se derivan de consultas a
`auditoria_eventos` comparando baseline y post-rollback; no son literales:

```text
TRANSFER_FALSE_SUCCESS_AUDIT_COUNT=0
EXIT_FALSE_SUCCESS_AUDIT_COUNT=0
```

La auditoría sigue dentro de la misma frontera transaccional.

## 13. Folio consistency

Los escenarios del harness no solicitaron folio para aislar el seam de series.
No se modificó `FolioService`, `FolioRepository` ni `series_documentales`.

```text
FOLIO_BEHAVIOR_CHANGED=false
```

## 14. Kardex/trace regression

El runner histórico ya corregido y la trazabilidad permanecen como regresión
obligatoria. El harness de implementación no altera el contrato del kardex.

```text
KARDEX_SERIES_DBTEST_RESULT=PASS
SERIAL_FULL_TRACE_RESULT=PASS
```

## 15. Concurrency regression

La concurrencia no se reimplementó. Se conserva la regresión existente:

```text
concurrent_same_key_result=PASS_UNIQUE_TRANSACTIONAL_REPLAY
stock_concurrency_result=PASS_ONE_SUCCESS_ONE_REJECTED
transfer_concurrency_result=PASS_ONE_SUCCESS_ONE_REJECTED
```

## 16. Consistency scans

Después de las pruebas y cleanup, cada residuo QA se consulta por sus
identificadores y referencias únicas; no se exige vaciar tablas completas:

```text
SERIAL_MULTI_WAREHOUSE_COUNT=0
SERIAL_ORPHAN_EXISTENCE_COUNT=0
SERIAL_ORPHAN_MOVEMENT_LINK_COUNT=0
SERIAL_ACTIVE_WITHOUT_EXISTENCE_COUNT=0
SERIAL_STOCK_MISMATCH_COUNT=0
QA_RESIDUAL_PRODUCTOS=0
QA_RESIDUAL_EXISTENCIAS=0
QA_RESIDUAL_PRODUCTO_SERIES=0
QA_RESIDUAL_EXISTENCIAS_SERIE=0
QA_RESIDUAL_MOVIMIENTOS=0
QA_RESIDUAL_MOVIMIENTO_DETALLES=0
QA_RESIDUAL_MOVIMIENTO_SERIES=0
QA_RESIDUAL_AUDITORIA=0
QA_RESIDUAL_IDEMPOTENCIA=0
QA_RESIDUAL_FOLIOS=NOT_APPLICABLE
QA_DATA_RESIDUALS=0
```

## 17. Protected data

```text
PROTECTED_PRODUCT_INTACT=true
PROTECTED_MAIL_UNTOUCHED=true
SMTP=false
```

El producto protegido `102016169` conservó su estado activo y sus dos
precios. El harness no ejecuta código de correo ni modifica tickets/outbox.

## 18. Cleanup

Los fixtures `QASEAM001`, `SER-QA-SEAM-TRANSFER-001`,
`SER-QA-SEAM-EXIT-001` y el almacén QA se eliminan en `finally`. Después del
`finally` se vuelven a consultar las entidades y se compara el resultado con
el baseline capturado antes de crear fixtures:

```text
ROLLBACK_VERIFIED_BEFORE_CLEANUP=true
CLEANUP_RESIDUALS_VERIFIED_AFTER_FINALLY=true
QA_DATA_RESIDUALS=0
```

La base puede conservar datos baseline legítimos; no se exige vaciar tablas
completas.

## 19. INV-INT-004 decision

El estado de `INV_INT_004` ya no se declara como constante. Se calcula después
de las verificaciones y solo puede ser `CLOSED` si pasan rollback de entrada
(delegado a la regresión de trazabilidad), transferencia, salida, auditoría,
idempotencia, consistencia y residuos QA:

```text
SERIAL_ENTRY_ROLLBACK=PASS
SERIAL_TRANSFER_FAILURE_AFTER_MUTATION=true
SERIAL_TRANSFER_ROLLBACK=PASS
SERIAL_EXIT_FAILURE_AFTER_MUTATION=true
SERIAL_EXIT_ROLLBACK=PASS
KARDEX_SERIES_DBTEST_RESULT=PASS
SERIAL_FULL_TRACE_RESULT=PASS
```

El resultado se deriva de esas condiciones:

```text
INV_INT_004=CLOSED
P0_COUNT=0
P1_COUNT=0
P2_COUNT=0
```

## 20. Risks

- El puerto agrega una superficie de contrato pequeña que debe mantenerse
  alineada con el adaptador PDO.
- Los argumentos opcionales preservan compatibilidad, pero futuras
  composiciones deben preferir inyección explícita de los contratos.
- La prueba usa MySQL/InnoDB y debe repetirse en cualquier entorno de hosting
  antes de afirmar equivalencia operacional.

No se identificó regresión funcional en las regresiones obligatorias.

## 21. Evidence fix contract

La corrección de evidencia de esta microfase modifica únicamente el harness y
este documento:

```text
AUDIT_COUNTS_DB_DERIVED=true
QA_RESIDUALS_DB_DERIVED=true
INV_INT_004_DERIVED=true
CRITICAL_FAILURES_AFFECT_EXIT_CODE=true
PRODUCTIVE_DIFF_CHANGED_DURING_EVIDENCE_FIX=false
SMTP=false
```

El runner captura baseline específico antes de crear fixtures, verifica la
auditoría inmediatamente después del rollback y antes de `cleanup`, y vuelve
a consultar cada entidad aplicable después del `finally`. Los campos de
folios se reportan como `NOT_APPLICABLE` porque estos escenarios no solicitan
folios.

La ejecución autorizada sobre `r_erp_db_core_0_test` terminó con exit code 0.
El baseline y post-rollback de auditoría fueron `0/0` para entrada,
transferencia y salida. Todos los residuos QA aplicables fueron `0`, el
producto protegido conservó activo, precios y existencias por almacén, y las
regresiones completas terminaron con exit code 0.

```text
HARNESS_PASS=true
REGRESSION_RESULTS=PASS
PROTECTED_PRODUCT_INTACT=true
PROTECTED_MAIL_UNTOUCHED=true
INV_INT_004=CLOSED
```

## 22. Recommended next phase

La implementación queda abierta para revisión. No se creó commit ni se hizo
staging en esta fase.

```text
RECOMMENDED_NEXT_PHASE=ERP-INVENTARIO-SERIES-ROLLBACK-SEAM-REVIEW-1
```
