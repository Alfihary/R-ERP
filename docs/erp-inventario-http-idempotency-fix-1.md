# ERP-INVENTARIO-HTTP-IDEMPOTENCY-FIX-1

## Estado

`IMPLEMENTATION_STATUS=PASS`
`AUTOMATED_HTML_RENDER_VERIFICATION=PASS`
`AUTOMATED_HTTP_VERIFICATION=PASS`
`MANUAL_VISUAL_VERIFICATION=OPTIONAL`

La fase corrige exclusivamente la pérdida de `idempotency_key` al construir
los valores iniciales de los formularios HTTP de movimientos y transferencias.
No se modificaron folios, esquema, migraciones, seeds ni módulos funcionales.

## Causa y corrección

- `ROOT_CAUSE=formValues omitía idempotency_key`.
- `LONG_PAGE_LAYOUT_FIX=NOT_APPLICABLE`.
- `InventoryController::formValues()` conserva la clave recibida.
- `InventoryTransferController::formValues()` conserva la clave recibida.
- Cada formulario nuevo genera una clave nueva mediante el flujo existente.

## Evidencia HTTP

| Invariante | Resultado |
| --- | --- |
| GET movimiento renderiza `idempotency_key` no vacío | PASS |
| GET transferencia renderiza `idempotency_key` no vacío | PASS |
| MOVEMENT_HIDDEN_KEY_PRESENT / NONEMPTY / VALID | true / true / true |
| TRANSFER_HIDDEN_KEY_PRESENT / NONEMPTY / VALID | true / true / true |
| MOVEMENT_KEY_HIDDEN_ONLY / TRANSFER_KEY_HIDDEN_ONLY | true / true |
| MOVEMENT_NEW_FORM_GETS_NEW_KEY / TRANSFER_NEW_FORM_GETS_NEW_KEY | true / true |
| POST movimiento válido | PASS (302 PRG) |
| POST transferencia válido | PASS (302 PRG) |
| Reintento movimiento | DEDUPED |
| Reintento transferencia | DEDUPED |
| Conflicto de clave movimiento | PASS (409 seguro) |
| Conflicto de clave transferencia | PASS (409 seguro) |
| Validación movimiento conserva clave reutilizable | PASS (422) |
| Validación transferencia conserva clave reutilizable | PASS (422) |
| MOVEMENT_VALIDATION_ERROR_KEY_PRESERVED / TRANSFER_VALIDATION_ERROR_KEY_PRESERVED | true / true |
| CSRF movimiento y transferencia | PASS (419 sin token) |
| Auth/RBAC | PASS |
| PRG | PASS |
| HTTP_409_SAFE | true |
| Double submit protegido | true |

## DB-TEST

La prueba se ejecutó únicamente sobre `r_erp_db_core_0_test`, en entorno no
productivo, con limpieza transaccional de los fixtures `QA-HTTPFIX-*`.

- `QA_DATA_RESIDUALS=0`.
- Los conteos de productos, almacenes, existencias, movimientos, auditoría e
  idempotencia regresaron exactamente a su línea base.
- El producto protegido `102016169` permaneció intacto.
- El fixture operativo usó `102016100`; no se modificaron folios.
- No se creó migración ni seed.
- No se escribió en ninguna base distinta.

## Archivos

Modificados:

- `app/Http/Controllers/InventoryController.php`
- `app/Http/Controllers/InventoryTransferController.php`

Creados:

- `database/tests/inventario_http_idempotency_fix_1_test.php`
- `docs/erp-inventario-http-idempotency-fix-1.md`

## Pruebas y límites

El test HTTP CLI de esta fase pasó con movimiento, transferencia, reintento,
conflicto, validación y CSRF. Se ejecutarán también lint PHP, regresiones de
inventario, `git diff --check` y revisión de secretos antes de entregar.

La validación automatizada obtuvo el HTML real de
`/inventario/movimientos/crear` y `/inventario/transferencias/crear`, confirmó
que `idempotency_key` es un único input `hidden`, no es un campo visible,
cumple el contrato de longitud/caracteres y cambia en GETs independientes.
También confirmó su conservación exacta en respuestas de validación 422.
La revisión visual humana es opcional; no se requiere ni se solicita otro
login.

`DB_WRITES=QA_ONLY`
`SMTP=false`
`DEPLOY=false`
`COMMIT=false`

## Fuera de alcance

No se inició AUTH-0 ni otra fase funcional; no se modificaron folios, schema,
migraciones, seeds, productos, precios, inventario fuera de los fixtures
transitorios, correo, SMTP, sesiones, permisos nuevos, deploy ni Git history.
