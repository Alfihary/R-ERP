# CORREO-OUTBOX-ELEGIBLES-DECISION-1

```ini
PHASE_STATUS=PASS
DECISION_698=CANCEL
DECISION_699=CANCEL
DECISION_700=CANCEL
DECISION_REASON=OLD_UNSENT_NOTIFICATION_TO_REAL_RECIPIENTS
LIKELY_QA_FIXTURE=INSUFFICIENT_EVIDENCE
DELETE_ROWS=false
SEND_ROWS=false
RETRY_ROWS=false
PRESERVE_AUDIT_TRAIL=true
USE_FOR_SMTP_TEST=false
PROCESSOR_MUST_REMAIN_DISABLED=true
NEXT_PHASE=CORREO-OUTBOX-ELEGIBLES-CANCELACION-1
```

## Objetivo y fuente factual

Esta fase registra exclusivamente la decisión operacional sobre las filas
`698`, `699` y `700` de `tickets_productos_correos`. No ejecuta la decisión.
La fuente factual principal es
`docs/correo-outbox-elegibles-auditoria-1.md`, que documenta la auditoría
READ-ONLY realizada sobre `r_erp_db_core_0_test`.

Se conserva sin reinterpretación la conclusión anterior:

```ini
LIKELY_QA_FIXTURE=INSUFFICIENT_EVIDENCE
```

Las filas no se clasifican como fixture QA, evento inválido, duplicado ni fila
corrupta. Son intenciones técnicamente válidas y coherentes con el ticket 3,
folio `BO-000013`.

## Decisión operacional

Las tres filas deben tratarse como una sola unidad de decisión:

| ID | Evento | Estado | Intentos | Decisión |
| ---: | --- | --- | ---: | --- |
| 698 | `PARTIDA_APROBADA` | `PENDIENTE` | 0 | `CANCEL` |
| 699 | `PARTIDA_APROBADA` | `PENDIENTE` | 0 | `CANCEL` |
| 700 | `TICKET_RESUELTO_TOTAL` | `PENDIENTE` | 0 | `CANCEL` |

La razón es `OLD_UNSENT_NOTIFICATION_TO_REAL_RECIPIENTS`. Fueron creadas
varios días antes, nunca se enviaron, continúan elegibles y contienen dos
destinatarios TO reales por mensaje. Al habilitar el processor podrían salir
automáticamente fuera del contexto temporal en el que ocurrieron los eventos.

No se recomienda cancelar solo una fila salvo que aparezca evidencia nueva que
justifique separar el conjunto.

## Por qué no enviarlas

- 698 enviaría una aprobación antigua de la partida 1.
- 699 enviaría una aprobación antigua de la partida 2.
- 700 enviaría una resolución total antigua.
- Procesadas en conjunto podrían generar tres mensajes históricos consecutivos
  y seis entregas, sin explicar al usuario que son notificaciones atrasadas.

La decisión no invalida los eventos de negocio ni altera el ticket
`BO-000013` o sus partidas. Retira deliberadamente tres intenciones de correo
antes de su envío.

## Por qué no borrarlas

```ini
DELETE_ROWS=false
PRESERVE_AUDIT_TRAIL=true
```

No debe usarse `DELETE`. Las filas deben permanecer disponibles para
trazabilidad, auditoría, evidencia del evento, deduplicación histórica y
diagnóstico futuro. La operación futura será exclusivamente:

```text
PENDIENTE -> CANCELADO
```

## Mecanismo oficial existente

El mecanismo de dominio es `MailOutboxActionService::cancel()`, respaldado por
`MailOutboxActionRepository::findScopedForUpdate()` y
`cancelPendingOrError()`.

Comportamiento actual:

- bloquea y busca la fila dentro del alcance efectivo de almacenes;
- exige motivo no vacío de hasta 300 caracteres;
- permite `PENDIENTE -> CANCELADO` y `ERROR -> CANCELADO`;
- rechaza `ENVIANDO` y `ENVIADO` como transición inválida;
- trata `CANCELADO` como estado ya cambiado, sin segunda cancelación;
- exige `enviado_at IS NULL` y `cancelado_at IS NULL` en el UPDATE;
- fija `cancelado_at=CURRENT_TIMESTAMP`;
- registra auditoría `MAIL_OUTBOX_CANCELLED` con estado anterior, estado nuevo,
  intentos, máximo de intentos y motivo;
- usa transacción o savepoint y revierte ante excepciones.

El runner `database/correo-outbox-acciones-retry-cancel.php` solo administra
seed, rollback del seed y DB-TEST. No es una vía operacional para cancelar
estas filas y no debe adaptarse mediante SQL manual ad hoc.

## Permisos, CSRF y alcance

La operación UI existente es `POST /admin/correo/cola/cancelar` y requiere:

- sesión autenticada;
- permiso general `correos.cola.ver`;
- permiso específico `correos.cola.cancelar`;
- CSRF mediante el middleware global;
- alcance efectivo del almacén resuelto por `ScopeContextService`.

La futura microfase controlada debe justificar cualquier interfaz CLI, exigir
un actor autorizado y conservar las mismas reglas de dominio, alcance y
auditoría. No debe introducir una segunda vía de cancelación por SQL directo.

## Políticas de campos

### Intentos

```ini
EXPECTED_ATTEMPTS_AFTER_CANCEL=0
```

Cancelar no equivale a intentar enviar. `intentos` debe permanecer en cero.

### Último intento

```ini
EXPECTED_ULTIMO_INTENTO_AT_AFTER_CANCEL=NULL
```

No debe inventarse `ultimo_intento_at`. El repositorio actual no lo modifica
durante cancelación.

### Fecha de envío

```ini
EXPECTED_ENVIADO_AT_AFTER_CANCEL=NULL
```

La cancelación no debe simular un envío.

### Error y motivo

`error_mensaje_seguro` no debe usarse para narrativa administrativa. El motivo
de cancelación pertenece al evento de auditoría, conforme al servicio actual.

### Dedupe

Las claves `dedupe_key` deben conservarse sin modificación. La cancelación no
debe borrar filas, crear otra intención ni permitir que estas notificaciones
antiguas se regeneren accidentalmente durante la microfase.

## Diseño de la futura cancelación

La fase `CORREO-OUTBOX-ELEGIBLES-CANCELACION-1` deberá verificar, bajo bloqueo
y antes de mutar, este contrato exacto:

| ID | Ticket | Evento | Estado | Intentos | Dedupe esperado |
| ---: | ---: | --- | --- | ---: | --- |
| 698 | 3 | `PARTIDA_APROBADA` | `PENDIENTE` | 0 | `ticket:3:partida:3:evento:PARTIDA_APROBADA` |
| 699 | 3 | `PARTIDA_APROBADA` | `PENDIENTE` | 0 | `ticket:3:partida:4:evento:PARTIDA_APROBADA` |
| 700 | 3 | `TICKET_RESUELTO_TOTAL` | `PENDIENTE` | 0 | `ticket:3:partida:null:evento:TICKET_RESUELTO_TOTAL` |

También deberá comprobar que el ticket 3 conserva el folio `BO-000013`, que
`enviado_at` y `cancelado_at` permanecen `NULL`, y que el actor tiene alcance
sobre el almacén del ticket.

### Atomicidad

La cancelación debe ejecutarse como conjunto atómico: una transacción exterior
común para 698, 699 y 700, reutilizando el servicio oficial. Como el servicio
admite una transacción ya activa mediante savepoints, el orquestador futuro
puede validar las tres filas, llamar `cancel()` para cada una y confirmar la
transacción exterior solamente si las tres respuestas son `success`.

Si una validación o llamada falla, debe lanzar el fallo y hacer rollback del
conjunto completo, incluidos los registros de auditoría. No se admite
cancelación parcial automática.

### Fail closed

La operación futura debe bloquearse si alguna fila presenta cualquiera de
estas condiciones:

- ID ausente o repetido;
- `status != PENDIENTE`;
- `intentos != 0`;
- `ticket_id != 3`;
- folio distinto de `BO-000013`;
- evento distinto del esperado;
- `dedupe_key` distinta de la esperada;
- `enviado_at` o `cancelado_at` no nulo;
- almacén fuera del alcance del actor;
- motivo inválido;
- resultado del servicio distinto de `success`;
- aparición de una nueva fila que cambie el supuesto operacional y requiera
  revisión humana.

No debe intentarse corregir el estado, reintentar ni continuar con las filas
restantes.

## Snapshot requerido en la futura fase

Antes y después deben capturarse, sin cuerpos ni emails completos:

- `id`;
- `ticket_id`;
- `evento`;
- `status`;
- `intentos`;
- `ultimo_intento_at`;
- `enviado_at`;
- `cancelado_at`;
- hash de `dedupe_key` y verificación contra el valor esperado;
- `eligible_count`;
- evidencia de auditoría de las tres cancelaciones.

El estado esperado, todavía no ejecutado, es:

```ini
CURRENT_ELIGIBLE_COUNT=3
EXPECTED_AFTER_CANCEL=0
EXPECTED_AFTER_CANCEL_CONDITION=no_new_external_eligible_rows
```

## Processor y futura prueba SMTP

```ini
PROCESSOR_MUST_REMAIN_DISABLED_UNTIL_DECISION_EXECUTED=true
USE_698_699_700_FOR_SMTP_TEST=false
```

El orden recomendado es:

```text
DECISION
-> CANCELACION
-> verificar eligible_count
-> crear una intención nueva y controlada
-> ejecutar SMTP QA controlado
```

Las filas antiguas no deben reutilizarse para una prueba real. Una prueba SMTP
futura requiere una intención nueva, evento explícitamente creado para QA y
destinatario previamente autorizado.

## Garantías de esta fase

```ini
DB_WRITES=0
APP_MAIL_NETWORK_CONNECTIONS=0
SMTP_CONNECTIONS=0
EMAILS_SENT=0
SECRET_RESOLUTIONS=0
PHP_MODIFIED=false
MIGRATIONS_MODIFIED=false
SEEDS_MODIFIED=false
CANCELLATIONS_EXECUTED=0
RETRIES_EXECUTED=0
PROCESS_EXECUTIONS=0
```

No se consultó ni modificó la base de datos en esta fase. No se ejecutó la UI,
no se resolvieron secretos y no se tomó todavía ninguna acción sobre las filas.
