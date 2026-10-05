# CORREO-OUTBOX-ELEGIBLES-CANCELACION-1

```ini
PHASE_STATUS=PASS
SOURCE_DECISION_COMMIT=8c82ef2
DATABASE=r_erp_db_core_0_test
IDS=698,699,700
CANCELLED_IDS=698,699,700
REASON=OLD_UNSENT_NOTIFICATION_TO_REAL_RECIPIENTS
DELETE_ROWS=false
SEND_ROWS=false
RETRY_ROWS=false
ATOMIC=true
CANCELLATION_ATOMIC=true
PARTIAL_CANCELLATION=false
ELIGIBLE_BEFORE=3
ELIGIBLE_AFTER=0
RECIPIENT_DATA_CHANGED_BY_CANCEL=false
SMTP_CONNECTIONS=0
EMAILS_SENT=0
SECRET_RESOLUTIONS=0
PROCESS_EXECUTED=false
PROCESSOR_EXECUTED=false
```

## Decisión ejecutada

Las filas `698`, `699` y `700` se cancelaron el 5 de octubre de 2026 mediante
`App\Domain\Mail\MailOutboxActionService::cancel()` y
`App\Infrastructure\Repositories\MailOutboxActionRepository`. La operación usó
una transacción exterior única; cualquier fallo habría revertido el conjunto
completo. No se usó un `UPDATE` manual como mecanismo de cancelación.

Motivo funcional exacto:

> Cancelación administrativa de notificación antigua no enviada; preservada por trazabilidad.

El actor derivado de las filas contaba con el permiso
`correos.cola.cancelar` y alcance sobre el almacén del ticket. El runner exige
doble confirmación de base, confirmación exacta de IDs y confirmación expresa de
no envío; además rechaza producción.

## Snapshot anterior

| ID | Evento | Plantilla | Estado | Intentos | Último intento | Enviado | Dedupe |
| ---: | --- | --- | --- | ---: | --- | --- | --- |
| 698 | `PARTIDA_APROBADA` | `line_approved` | `PENDIENTE` | 0 | `NULL` | `NULL` | `ticket:3:partida:3:evento:PARTIDA_APROBADA` |
| 699 | `PARTIDA_APROBADA` | `line_approved` | `PENDIENTE` | 0 | `NULL` | `NULL` | `ticket:3:partida:4:evento:PARTIDA_APROBADA` |
| 700 | `TICKET_RESUELTO_TOTAL` | `ticket_resolved` | `PENDIENTE` | 0 | `NULL` | `NULL` | `ticket:3:partida:null:evento:TICKET_RESUELTO_TOTAL` |

Las tres filas pertenecían al ticket `3`, folio `BO-000013`, estado
`APROBADO`. Las partidas `3` y `4` estaban `APROBADA`; la fila `700` no tiene
partida. Antes de ejecutar: `eligible_count=3`, `tickets_count=2` y
`outbox_count=5`.

## Evidencia de integridad

| ID | Subject SHA-256 | HTML SHA-256 | Text SHA-256 |
| ---: | --- | --- | --- |
| 698 | `0022f2afbf38162cf8b7cd1e621586a4ac50c277c5c6af02c27f7a432afd4372` | `62f0dd24961ef1940a0212df59618f62e417229c4729124297739cba4ab7d492` | `8bdaf7e3f254015804b773c4fd99a15a1042e284b7f95e122898bebd8552e556` |
| 699 | `0022f2afbf38162cf8b7cd1e621586a4ac50c277c5c6af02c27f7a432afd4372` | `e1a4179fd374abbb385b262fe72e4bc6be633eace278c6b9f0852bf885905b66` | `0903bcb25369277788173edfbec01ba2b0229e99ac549f03512eda885c23c38a` |
| 700 | `448a0d376330cee67ba2ff420667a1fbd93f7ca374ce3dd727c4af905dc9a524` | `46ceada7f9c52c0b5cb3b855ca2e370c2cc6d4e43263d0ce7cdc656797149bb3` | `0645c6a1d035e227a7ee4f82891d31a49e8483075b8fa12608e15647584cc033` |

El hash SHA-256 del almacenamiento del envelope fue
`29bef7351bd5f9b53ea8da5e8ec07d153f1f47dd7b65a833f78a050caf17dce5`
en las tres filas. La interpretación correcta del envelope es
`TO_COUNT=2`, `CC_COUNT=0` y `BCC_COUNT=0`. No se documentan direcciones ni
contenidos.

La auditoría histórica había informado correctamente dos destinatarios TO. El
resumen inicial de cancelación informó uno porque contó solamente la columna
`destinatario_email`, que conserva el destinatario primario por compatibilidad.
El campo `cc_json` es un objeto con las claves `to`, `cc` y `bcc`; su lista
`to` contiene dos entradas e incluye nuevamente al primario. El processor
combina la columna primaria con la lista completa y deduplica, dando dos TO
distintos, no uno ni tres. Por tanto, la discrepancia fue de interpretación del
reporte y no un cambio de datos.

```ini
STORED_ENVELOPE_TO_COUNT=2
PRIMARY_REPEATED_IN_ENVELOPE_TO=true
RESOLVED_DISTINCT_TO_COUNT=2
RESOLVED_CC_COUNT=0
RESOLVED_BCC_COUNT=0
RECIPIENT_DATA_CHANGED_BY_CANCEL=false
```

El runner comparó dentro de la transacción los hashes de contenido y envelope,
el dedupe, ticket, partida, evento, plantilla, intentos, límite de intentos,
error seguro, marcas de intento/envío y actor. El resultado fue
`protected_content_unchanged=true`.

## Snapshot posterior

| ID | Estado | Intentos | Último intento | Enviado | Cancelado |
| ---: | --- | ---: | --- | --- | --- |
| 698 | `CANCELADO` | 0 | `NULL` | `NULL` | presente |
| 699 | `CANCELADO` | 0 | `NULL` | `NULL` | presente |
| 700 | `CANCELADO` | 0 | `NULL` | `NULL` | presente |

Las tres filas conservan sus `dedupe_key`; ninguna fue eliminada. Después de la
transacción: `eligible_count=0`, `tickets_count=2` y `outbox_count=5`.

Se creó exactamente un evento requerido `MAIL_OUTBOX_CANCELLED` exitoso por
cada ID, con el motivo exacto. La fila `1` sigue `CANCELADO`, con cero intentos;
la fila `36` sigue `ENVIADO`, con un intento y `enviado_at` presente. El ticket
`34`, folio `QASMTP-000001`, sigue `EN_REVISION` y con cero partidas.

## Pruebas y regresiones

- Sintaxis PHP del runner y DB-TEST: PASS.
- DB-TEST específico: 15/15 PASS y rollback final.
- El DB-TEST usa tres fixtures controlados dentro de una transacción, los
  elimina mediante rollback y confirma que 698/699/700 permanecen idénticas.
- Rechazos cubiertos: ID, estado, intentos, ticket, evento y dedupe incorrectos.
- Atomicidad cubierta: fallo simulado en segunda y tercera operación revierte 3/3.
- Regresión retry/cancel: 44/44 PASS y rollback final.
- Regresión UI outbox: PASS, `DB_WRITES=0`, `SMTP_CONNECTIONS=0`.
- Processor `dry-run`: cero elegibles, `database_changed=false`,
  `secret_resolved=false`, `smtp_used=false`.
- No se ejecutó `process`, retry, conexión SMTP ni envío.

## Comandos operativos

Auditoría previa de solo lectura:

```powershell
php database/correo-outbox-elegibles-cancelacion.php audit --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

La ejecución real requirió las confirmaciones exactas documentadas en el
runner. El comando no es reutilizable sobre estas filas: las precondiciones
exigen `PENDIENTE` y ahora están en estado terminal `CANCELADO`.

## Rollback

No existe rollback automático aprobado. Reabrir filas canceladas alteraría una
decisión auditada y podría volverlas elegibles para envío; requiere una fase y
autorización explícitas. La trazabilidad se conserva en las filas y en
`auditoria_eventos`.
