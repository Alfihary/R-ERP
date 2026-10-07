# CORREO-SMTP-QA-ENVIO-1

## Resultado

```ini
PHASE_STATUS=PASS_SUCCESS
AUTHORIZATION_RECEIVED=true
QA_OUTBOX_ID=1610
QA_TICKET_ID=197
DATABASE=r_erp_db_core_0_test
APP_ENV=local
RECIPIENT_MASKED=s***@gruporefrigerantes.com.mx
RECIPIENT_HASH=d2b87c66c1d5d9228cbb5d2c243b26cf86d08f475725275c400d891e94f5e5a8
TO_COUNT=1
CC_COUNT=0
BCC_COUNT=0
OPERATIONAL_RECIPIENTS_INCLUDED=false
MAIL_ACCOUNT_ID=1
MAIL_ACCOUNT_ACTIVE=true
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_ENCRYPTION=tls
SMTP_FROM_CONFIGURED=true
SMTP_USERNAME_CONFIGURED=true
SMTP_SECRET_REFERENCE_CONFIGURED=true
SECRET_RESOLVED=true
SECRET_RESOLUTIONS=1
CLAIM_RESULT=SUCCESS
ATTEMPTS_BEFORE=0
ATTEMPTS_AFTER=1
SMTP_CONNECTION_ATTEMPTED=true
SMTP_SEND_ATTEMPTS=1
SMTP_ACCEPTED_MESSAGE=true
DELIVERY_OUTCOME_AMBIGUOUS=false
FINAL_STATUS=ENVIADO
ULTIMO_INTENTO_AT_PRESENT=true
ENVIADO_AT_PRESENT=true
ERROR_PRESENT=false
EMAILS_SENT=1
RETRY_EXECUTED=false
SECOND_SMTP_ATTEMPT=false
PROCESSOR_BATCH_EXECUTED=false
OTHER_OUTBOX_CLAIMED=false
OUTBOX_COUNT_DELTA=0
DEDUPE_COUNT_BEFORE=1
DEDUPE_COUNT_AFTER=1
ELIGIBLE_COUNT_AFTER=0
DB_WRITES=2
SECRETS_PRINTED=false
RECIPIENT_FULLY_PRINTED=false
```

## Prechecks y tooling

- La autorización directa cubrió únicamente outbox `1610`, `MAIL_TEST_RECIPIENT`, una conexión y un intento.
- `--database` y `--confirm-database` coincidieron exactamente con `r_erp_db_core_0_test`.
- La base activa fue `r_erp_db_core_0_test` y `APP_ENV=local`.
- El DB-test aislado obtuvo `12/12` aserciones aprobadas, usando SQLite en memoria y `FakeMailTransport`.
- Se validaron rechazo de scope, claim exacto, fila ajena intacta, finalización exitosa, finalización fallida segura, no segundo claim, no batch, no retry, cero resolución de secreto y cero socket en pruebas.
- El CLI no acepta destinatario, otra outbox, batch size ni wildcard.

## Intención y envelope

- Outbox `1610`: ticket `197`, evento `TICKET_CREADO`, plantilla `ticket_created`.
- Estado previo: `PENDIENTE`, `intentos=0`, `ultimo_intento_at=NULL`, `enviado_at=NULL`.
- Única fila elegible previa: `1610`.
- Folio QA: `QASMTP-000002`.
- Ticket previo y posterior: `EN_REVISION`, cero partidas, cero adjuntos y cero comentarios; `cancelado_at=NULL`.
- El envelope persistido y el envelope entregado coincidieron: un TO, cero CC y cero BCC.
- No se incluyeron destinatarios operativos.
- Asunto validado: `[R-ERP] Ticket QASMTP-000002 creado`.
- SHA-256 HTML: `317b201ea58cb5e6c6d56bb76f61183e477c518e88eff9a2aff8385e70852931`.
- SHA-256 texto: `ab11ef46190c12bd9ff5ac86acde79d7bb4132dc9848ff673166280003c44d6b`.
- Dedupe: `ticket:197:partida:null:evento:TICKET_CREADO`, conteo antes y después `1`.

## Cuenta y transporte

- Cuenta única aplicable: `MAIL_ACCOUNT_ID=1`, activa.
- Regla activa `TICKET_CREADO` resolvió la misma cuenta primaria `TICKETS_PRODUCTOS`.
- SMTP: `smtp.gmail.com:587` con `tls`/STARTTLS.
- Remitente y usuario están configurados; se documentan solo enmascarados cuando aplica.
- El secreto se resolvió una sola vez, no se imprimió, no se registró y no se persistió.
- El transporte aceptó el mensaje.

## Claim y finalización

1. Claim exacto `1610`: `PENDIENTE → ENVIANDO`.
2. `intentos`: `0 → 1`.
3. `ultimo_intento_at`: establecido.
4. Se ejecutó exactamente una llamada SMTP.
5. Resultado aceptado por SMTP.
6. Finalización exacta por `id + intentos + ultimo_intento_at`: `ENVIANDO → ENVIADO`.
7. `enviado_at` quedó presente.

No hubo segundo `send()`, retry, fallback, recuperación stale ni procesamiento batch.

## Datos protegidos

Los siguientes registros permanecieron sin cambios:

- Ticket `34`: `QASMTP-000001`, `EN_REVISION`, cero partidas.
- Outbox `36`: `ENVIADO`, un intento, `enviado_at` presente.
- Outbox `1`: `CANCELADO`.
- Outbox `698`: `CANCELADO`.
- Outbox `699`: `CANCELADO`.
- Outbox `700`: `CANCELADO`.

## Estado posterior

- Outbox `1610`: `ENVIADO`, `intentos=1`, `ultimo_intento_at` presente, `enviado_at` presente.
- `eligible_count` posterior: `0`.
- Conteo total de outbox: sin incremento.
- Conteo dedupe: `1`.
- El ticket `197` no fue modificado por el envío.
- Las dos escrituras fueron exclusivamente el claim y la finalización de la outbox `1610`; no se creó una nueva intención ni una auditoría manual adicional.

## Límites respetados

- No se procesaron otras outbox.
- No se aceptó destinatario por CLI.
- No se ejecutó retry.
- No se hizo un segundo intento SMTP.
- No se modificó la configuración ni las reglas.
- No se imprimió contraseña, secreto, token, cuerpo completo ni destinatario completo.
- No se hizo commit, push ni deploy.
