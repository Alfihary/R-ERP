# ERP-INVENTARIO-CONCURRENCIA-AUDITORIA-IMPLEMENTACION-1

## Resultado

`IMPLEMENTATION_STATUS=PASS`

Se corrigieron exclusivamente `INV-INT-001` (auditoría ausente) e
`INV-INT-002` (retries duplicados). `INV-INT-003` queda sin cambios.

La implementación usa una tabla dedicada de idempotencia con fingerprint y
resultado serializado. Reserva, stock, auditoría y finalización ocurren en la
misma transacción PDO.

## Diseño de idempotencia

Migración creada:

```text
database/migrations/inventario_concurrencia_auditoria_implementacion_1_001_create_idempotency.php
```

Tabla: `inventario_operaciones_idempotencia`.

```ini
IDEMPOTENCY_SCOPE_MOVEMENT=movimiento:{empresa_id}
IDEMPOTENCY_SCOPE_TRANSFER=transferencia:{empresa_id}
IDEMPOTENCY_KEY_FORMAT=token alfanumérico de 16 a 128 caracteres
IDEMPOTENCY_KEY_GENERATION=random_bytes(16) en formularios; una nueva operación genera una nueva key
IDEMPOTENCY_KEY_VALIDATION=backend, /^[A-Za-z0-9._~-]{16,128}$/
IDEMPOTENCY_FINGERPRINT=true
FINGERPRINT_CANONICALIZATION=deterministic SHA-256 del payload lógico validado, sin idempotency_key
IDEMPOTENCY_DB_UNIQUE=true
```

Índice aplicado:

```text
UNIQUE(scope_key, idempotency_key)
```

Scopes:

```text
movimiento:{empresa_id}
transferencia:{empresa_id}
```

Una key repetida con el mismo fingerprint devuelve el resultado original. Una
key repetida con payload diferente se rechaza con conflicto. No se comparan
producto, cantidad o fecha como deduplicación implícita.

`InventoryIdempotencyConflictException` se transforma en respuesta HTTP 409
con mensaje genérico; no produce 500, doble ejecución ni detalles internos.
`PAYLOAD_CONFLICT_HTTP_BEHAVIOR=HTTP_409_GENERIC_NO_REEXECUTION`.

## Flujo transaccional

1. validar payload y alcance;
2. reservar/recuperar la key con `FOR UPDATE`;
3. crear y aplicar movimiento o transferencia;
4. insertar auditoría requerida;
5. guardar resultado y marcar `COMPLETADA`;
6. commit.

Un fallo revierte reserva, stock, movimientos y auditoría de éxito.

```ini
MOVEMENT_TRANSACTION_BOUNDARY=reserve + stock + movimiento + detalle + auditoría + complete + commit
TRANSFER_TRANSACTION_BOUNDARY=reserve + salida + entrada + stock + auditoría + complete + commit
PENDING_RECOVERY_POLICY=sin recuperación automática; una excepción revierte la transacción y elimina la reserva PENDIENTE
```

## Auditoría

Eventos implementados:

```text
inventario.movimiento.creado -> movimientos_inventario
inventario.transferencia.creada -> transferencias
```

La metadata incluye actor, empresa, almacén(es), referencia, conceptos,
movimientos relacionados, partidas e idempotency key. No contiene secretos.

```ini
MOVEMENT_AUDIT_COMPLETENESS=COMPLETE
TRANSFER_AUDIT_COMPLETENESS=COMPLETE
AUDIT_SUCCESS_EVENT_MULTIPLIER=1x
AUDIT_ROLLBACK_CONSISTENCY=PASS
```

## Concurrencia real

Tooling:

```text
database/tests/inventario_concurrencia_auditoria_implementacion_1_test.php
```

El test abre dos procesos PHP independientes y los coordina con una barrera de
archivos.

```ini
CONCURRENT_SAME_KEY_RESULT=PASS_UNIQUE_TRANSACTIONAL_REPLAY
CONCURRENT_DIFFERENT_KEYS_RESULT=PASS_WORKERS_COMPLETED
STOCK_CONCURRENCY_RESULT=PASS_ONE_SUCCESS_ONE_REJECTED
TRANSFER_CONCURRENCY_RESULT=PASS_ONE_SUCCESS_ONE_REJECTED
LOCK_ORDER_STABLE=true
DEADLOCK_RESULT=NOT_OBSERVED
```

Con la misma key ambos workers recibieron el mismo movimiento y hubo un solo
efecto de stock. Con keys distintas se permitieron operaciones legítimas. Con
stock suficiente para una sola salida/transferencia, una operación completó y
la otra fue rechazada tras revalidar saldo bajo lock.

## Rollback y payload conflict

La prueba de salida insuficiente después de reservar la key confirmó:

```ini
STOCK_DELTA_ON_ROLLBACK=0
MOVEMENT_DELTA_ON_ROLLBACK=0
AUDIT_SUCCESS_DELTA_ON_ROLLBACK=0
IDEMPOTENCY_DELTA_ON_ROLLBACK=0
```

```text
MOVEMENT_RETRY_RESULT=DEDUPED
MOVEMENT_PAYLOAD_CONFLICT=true
MOVEMENT_STOCK_EFFECT_MULTIPLIER=1x
TRANSFER_RETRY_RESULT=DEDUPED
TRANSFER_PAYLOAD_CONFLICT=true
```

## Migración y regresión

La migración se aplicó únicamente a:

```text
r_erp_db_core_0_test
```

Nunca se ejecutó sobre producción ni otra base.

Pasaron:

```text
php database/tests/inventario_concurrencia_auditoria_implementacion_1_test.php
php database/inventario-service.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/transferencias.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

Conteos del harness antes/después:

```text
existencias_producto                 0 -> 0
movimientos_inventario               0 -> 0
movimientos_inventario_detalle       0 -> 0
auditoria_eventos                  107 -> 107
inventario_operaciones_idempotencia  0 -> 0
```

```ini
DB_QA_RESIDUALS=0
QA_DATA_RESIDUALS=0
QA_FIXTURE_PERSISTENT_WRITES=0
TEST_DB_SCHEMA_MIGRATION_APPLIED=true
TEST_DB_SCHEMA_PERSISTENT_CHANGE=true
PRODUCTION_MIGRATION_APPLIED=false
```

## Seguridad y compatibilidad

La idempotency key no sustituye CSRF. Se preservan AuthMiddleware,
PermissionMiddleware y CsrfMiddleware. Los formularios generan una key oculta
por operación y las rutas HTTP rechazan keys ausentes o inválidas. El fallback
aleatorio solo queda para tooling interno legado sin repositorio de idempotencia.

No se modificaron ventas, compras, clientes, proveedores, CxC, CxP, mail,
tickets, sidebar, productos ni folios.

## Producto y mail protegidos

Producto `102016169 | REFRIGERANTE R-410A 5KG IGAS`:

```text
activo=1
precios=2
stock=0.000000
```

No se tocaron tickets 34/197 ni outbox 1, 36, 698, 699, 700 y 1610. `SMTP=false`.

## Clasificación final

```ini
INV-INT-001=FIXED
INV-INT-002=FIXED
INV-INT-003=THEORETICAL_RISK_UNCHANGED
MIGRATION_CREATED=true
MIGRATION_APPLIED_TEST_DB_ONLY=true
```

Archivos creados:

- `database/migrations/inventario_concurrencia_auditoria_implementacion_1_001_create_idempotency.php`;
- `database/tests/inventario_concurrencia_auditoria_implementacion_1_test.php`;
- `app/Domain/Inventory/InventoryIdempotencyConflictException.php`;
- `app/Infrastructure/Repositories/InventoryIdempotencyRepository.php`;
- este documento.

Archivos modificados: servicios de inventario/transferencia, controladores y
formularios de movimientos/transferencias, y `bootstrap/app.php`.

No se hizo staging, commit, push, deploy ni SMTP. La siguiente revisión debe
validar el flujo HTTP visual y documentar cualquier consumidor externo de
`MAX(id)+1`, sin modificarlo en esta fase.
