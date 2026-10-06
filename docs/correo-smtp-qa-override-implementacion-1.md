# CORREO-SMTP-QA-OVERRIDE-IMPLEMENTACION-1

```ini
PHASE_STATUS=PASS
IMPLEMENTATION_STATUS=VALIDATED
QA_OVERRIDE_CLI_ONLY=true
QA_OVERRIDE_PRODUCTION_ALLOWED=false
QA_OVERRIDE_REPLACES_OPERATIONAL_RECIPIENTS=true
QA_OVERRIDE_APPENDS=false
QA_RECIPIENT_SOURCE=MAIL_TEST_RECIPIENT
QA_ALLOWED_EVENTS=TICKET_CREADO
USE_EXISTING_TICKET_34=false
USE_OUTBOX_36=false
PROCESSOR_MODIFIED=false
TRANSPORT_MODIFIED=false
PROCESSOR_EXECUTED=false
SMTP_CONNECTIONS=0
EMAILS_SENT=0
SECRET_RESOLUTIONS=0
DB_TEST_EXECUTED=true
DB_TEST_PASS=72
DB_TEST_TOTAL=72
DB_WRITES=0
HISTORICAL_STATIC_GUARDRAIL_OBSOLETE_FOR_THIS_PHASE=true
NEXT_PHASE=CORREO-SMTP-QA-FIXTURE-INTENCION-1
```

## Estado de la fase

El seam de override QA quedó implementado y validado sobre
`r_erp_db_core_0_test`. El DB-TEST transaccional pasó `72/72`; las regresiones
de outbox, orquestación, processor seguro, retry/cancel, UI y renderer también
pasaron. Los snapshots inicial y final coinciden.

No se creó un fixture QA persistente, una intención outbox persistente ni una
conexión SMTP. Tampoco se ejecutó el processor, se resolvieron secretos o se
modificó `.env`.

## Seam implementado

`App\Domain\Mail\QaMailContext` representa una capacidad explícita, `final` e
inmutable (`readonly`) que solo puede construirse mediante su factory CLI. El
contexto contiene únicamente:

- destinatario QA normalizado;
- ambiente efectivo;
- nombre de la base validada;
- evento autorizado.

No contiene configuración SMTP, secretos, transporte, sesión, request, CSRF,
HTML, SQL ni una clave dedupe manual.

La entrada separada es:

```php
ProductTicketEmailNotificationService::handleQa(
    string $event,
    int $ticketId,
    ?int $partidaId,
    int $actorUserId,
    QaMailContext $context
): array
```

El método productivo `handle()` conserva su firma y continúa resolviendo los
destinatarios operativos. `handleQa()` exige el contexto validado y delega al
mismo servicio outbox mediante `enqueueConfiguredQa()`.

## Guardas fail-closed

La factory `QaMailContext::fromCli()` exige:

1. ejecución desde PHP CLI;
2. `APP_ENV` en la allowlist `local`, `development` o `test`;
3. base solicitada, base confirmada, `APP_DB_NAME` y `SELECT DATABASE()` iguales
   a `r_erp_db_core_0_test`;
4. evento exacto `TICKET_CREADO`;
5. confirmación exacta `--confirm-no-send=YES`;
6. `MAIL_TEST_RECIPIENT` no vacío y válido.

El destinatario se recorta y normaliza a minúsculas. Se rechazan direcciones de
más de 190 bytes, listas, comas, punto y coma, espacios internos, CR, LF,
caracteres de control y valores que no superen `FILTER_VALIDATE_EMAIL`. Los
mensajes de error no incluyen el destinatario.

## Validación del fixture QA

Antes del enqueue, el servicio vuelve a comprobar la base activa y exige:

- folio `QASMTP-######`;
- observaciones exactamente `[QA_FIXTURE:CORREO_SMTP]`;
- estado `EN_REVISION`;
- contadores de partidas en cero;
- `cancelado_at` nulo;
- cero partidas reales;
- cero adjuntos reales;
- `partidaId` nulo.

Se rechazan expresamente `ticket_id=34` y el folio histórico
`QASMTP-000001`. El outbox `36` no se consulta, modifica ni reutiliza mediante
el seam.

## Semántica de destinatarios

La ruta productiva conserva `resolveRecipients()`. La ruta QA no llama esa
resolución y reemplaza el envelope por:

```text
TO  = [MAIL_TEST_RECIPIENT]
CC  = []
BCC = []
```

Después del reemplazo, ambas rutas usan el mismo
`ProductTicketEmailTemplatePayloadBuilder`,
`ProductTicketEmailTemplateRenderer`, algoritmo `dedupeKey()` y
`ProductTicketEmailOutboxRepository`.

El contexto y el runner no aceptan dedupe manual. Una clave existente conserva
la idempotencia actual: no inserta una segunda fila y no reabre estados
terminales.

## Tooling CLI

El runner seguro está en:

```text
database/correo-smtp-qa-override.php
```

Expone únicamente `contract:test` y `db:test`; no contiene comando de envío ni
invoca el processor. Requiere:

```text
--database=r_erp_db_core_0_test
--confirm-database=r_erp_db_core_0_test
--confirm-event=TICKET_CREADO
--confirm-no-send=YES
```

Su salida enmascara el destinatario y no imprime credenciales, DSN, secretos ni
la dirección completa.

## DB-TEST ejecutado

El test está en:

```text
database/tests/correo_smtp_qa_override_implementacion_1_test.php
```

El test abre una transacción y comprobó:

- inmutabilidad y validaciones negativas del contexto;
- fixture QA temporal válido;
- override exacto `TO=1`, `CC=0`, `BCC=0`;
- exclusión de destinatarios operativos aunque existan en las reglas;
- estado `PENDIENTE`, cero intentos y fechas de intento/envío nulas;
- reutilización de payload, renderer, subject y CTA;
- dedupe oficial e idempotencia;
- rechazo de un ticket normal, ticket `34`, marker incorrecto, ticket con
  partidas, ticket con adjuntos y evento distinto;
- compatibilidad del `handle()` productivo;
- restauración de conteos y ausencia de residuo tras rollback;
- conservación de outbox `1`, `36`, `698`, `699`, `700` y ticket `34`.

Resultado:

```ini
DB_TEST_PASS=72
DB_TEST_TOTAL=72
QA_TO_COUNT=1
QA_CC_COUNT=0
QA_BCC_COUNT=0
QA_OPERATIONAL_RECIPIENTS_INCLUDED=false
ROLLBACK_VERIFIED=true
```

La primera ejecución detectó dos aserciones incorrectas del propio test para
columnas SQL `NULL`: el operador `??` convertía `NULL` en `false`. Se corrigió
el test para exigir la existencia de cada columna y comparar su valor directo
con `NULL`. No fue necesario modificar código productivo.

## Validaciones ejecutadas

- DB-TEST específico: `72/72 PASS`.
- PHP lint de todos los archivos PHP nuevos o modificados: `PASS`.
- outbox DB: `PASS`.
- orquestación y compatibilidad productiva: `PASS`.
- processor seguro con transporte fake: `PASS`.
- retry/cancel: `44/44 PASS`.
- UI outbox: `PASS`.
- renderer: `50/50 PASS`.
- recipients/reglas: todas las aserciones funcionales y de seguridad `PASS`;
  el guardrail histórico `outbox_service_not_modified` devuelve `false` porque
  esta fase modifica deliberadamente ese archivo.
- processor real: no ejecutado.
- SMTP: no ejecutado.
- secretos SMTP: no resueltos.

## Integridad posterior

Los snapshots read-only anterior y posterior confirmaron:

```ini
eligible_count=0
OUTBOX_1=CANCELADO
OUTBOX_36=ENVIADO
OUTBOX_698=CANCELADO
OUTBOX_699=CANCELADO
OUTBOX_700=CANCELADO
TICKET_34=INTACTO
```

También confirmaron `tickets_count=2`, `outbox_count=5`, ausencia de fixture QA
residual y cero escrituras persistentes. Outbox `36` conserva un intento y
`enviado_at`; los demás outbox protegidos conservan cero intentos.

## Rollback

Los cambios de código son aditivos y se mantuvieron sin staging durante la
validación. Todos los fixtures de prueba fueron revertidos por transacción y no
hubo escrituras persistentes.
Si se descarta la implementación, el rollback consiste en retirar los archivos
nuevos y revertir únicamente las modificaciones enumeradas para esta fase. No
hay migraciones ni seeds.
