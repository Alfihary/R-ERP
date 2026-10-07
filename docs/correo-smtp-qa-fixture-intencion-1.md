# CORREO-SMTP-QA-FIXTURE-INTENCION-1

## Objetivo

Crear, de forma explícita y controlada, un único ticket QA persistente y una única intención de correo en `PENDIENTE` para una prueba SMTP posterior. Esta fase no procesa la cola, no abre conexiones SMTP, no resuelve secretos y no envía correo.

## Contrato

- Entorno permitido: `local`, `development` o `test`.
- Base única: `r_erp_db_core_0_test`, confirmada dos veces y coincidente con la conexión activa.
- Evento único: `TICKET_CREADO`.
- Destinatario único: `MAIL_TEST_RECIPIENT`, validado por `QaMailContext`; no existe opción CLI para sustituirlo.
- Ticket: folio determinista `QASMTP-######` desde `QASMTP-000002`, estado `EN_REVISION`, marcador exacto `[QA_FIXTURE:CORREO_SMTP]`, cero partidas, adjuntos y comentarios.
- Outbox: sobre TO/CC/BCC `1/0/0`, plantilla y deduplicación oficiales, `status=PENDIENTE`, `intentos=0` y sin timestamps de intento o envío.
- Ticket, evento de auditoría e intención outbox se crean dentro de una sola transacción.
- La marca de fase en `tickets_productos_eventos.metadata_json` bloquea cualquier repetición.
- La creación se rechaza si existe cualquier fila elegible previa.

## Comandos

Auditoría segura y sin escritura:

```powershell
php database/correo-smtp-qa-fixture-intencion.php audit --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test --confirm-event=TICKET_CREADO --confirm-no-send=YES
```

DB-TEST transaccional, con rollback total:

```powershell
php database/correo-smtp-qa-fixture-intencion.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test --confirm-event=TICKET_CREADO --confirm-no-send=YES
```

Creación persistente, solo después de aprobar el DB-TEST:

```powershell
php database/correo-smtp-qa-fixture-intencion.php create --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test --confirm-event=TICKET_CREADO --confirm-no-send=YES --confirm-create-qa-fixture=YES
```

La salida enmascara el destinatario y expone únicamente conteos, identificadores, estados, tamaños y huellas del contenido. No imprime cuerpos, credenciales, DSN ni secretos.

## Fuera de alcance

- No ejecutar el procesador real ni su dry-run, porque el preview existente incluye el destinatario completo.
- No conectar SMTP, instanciar PHPMailer, resolver `smtp_secret_ref`, reintentar ni enviar.
- No reutilizar ticket `34`, outbox `36` ni filas `698`, `699`, `700`.
- No modificar reglas de correo de forma persistente.
- No cambiar migraciones, seeds, `.env`, `main` ni código funcional de tickets.
- No hacer staging, commit, push o deploy en esta fase.

## Resultado esperado

Tras `create`, existe exactamente un artefacto de fase, el `eligible_count` es `1` y el auditor seguro lo presenta sin revelar el destinatario completo. El ticket y la intención se conservan para una fase SMTP posterior expresamente autorizada.

## Rollback

No hay rollback automático del `create`: la evidencia debe conservarse. Una limpieza futura requiere una microfase separada y autorizada que mantenga integridad referencial y trazabilidad.

## Evidencia de ejecución

```ini
PHASE_STATUS=PASS
DB_TEST_EXECUTED=true
DB_TEST_RESULT=41/41
DB_TEST_ROLLBACK_VERIFIED=true
DB_TEST_PASS=41
DB_TEST_TOTAL=41
ROLLBACK_VERIFIED=true
PERSISTENT_CREATE_EXECUTED=true
CREATE_EXECUTION_COUNT=1
QA_TICKET_ID=197
QA_FOLIO=QASMTP-000002
QA_OUTBOX_ID=1610
EVENT=TICKET_CREADO
TEMPLATE=ticket_created
STATUS=PENDIENTE
ATTEMPTS=0
ELIGIBLE_BEFORE=0
ELIGIBLE_AFTER=1
QA_FIXTURE_BEFORE=0
QA_FIXTURE_AFTER=1
PROCESSOR_EXECUTED=false
PROCESSOR_DRY_RUN_EXECUTED=false
SMTP_CONNECTIONS=0
EMAILS_SENT=0
SECRET_RESOLUTIONS=0
PHPMAILER_USED=false
NEXT_PHASE=CORREO-SMTP-QA-ENVIO-1
```

El dry-run del procesador se omitió porque su preview actual selecciona el destinatario completo. La elegibilidad se comprobó mediante el auditor seguro de esta fase: existe una sola candidata y corresponde a `QA_OUTBOX_ID=1610`. La reejecución de `create` permanece bloqueada por la metadata de fase; no se ejecutó un segundo `create`.
