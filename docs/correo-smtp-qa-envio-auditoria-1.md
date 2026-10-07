# CORREO-SMTP-QA-ENVIO-AUDITORIA-1

## Resultado

```ini
PHASE_STATUS=PASS
READY_FOR_SMTP_SEND=true
QA_OUTBOX_ID=1610
QA_TICKET_ID=197
ELIGIBLE_COUNT=1
ONLY_ELIGIBLE_OUTBOX=1610
RECIPIENT_MATCH=true
RECIPIENT_MASKED=s***@gruporefrigerantes.com.mx
RECIPIENT_HASH=d2b87c66c1d5d9228cbb5d2c243b26cf86d08f475725275c400d891e94f5e5a8
MAIL_ACCOUNT_ID=1
MAIL_ACCOUNT_ACTIVE=true
ACCOUNT_RESOLUTION_UNIQUE=true
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_ENCRYPTION=tls
SMTP_FROM_CONFIGURED=true
SMTP_FROM_MASKED=s***@gruporefrigerantes.com.mx
SMTP_USERNAME_CONFIGURED=true
SMTP_USERNAME_MASKED=s***@gruporefrigerantes.com.mx
SMTP_SECRET_REFERENCE_CONFIGURED=true
SECRET_RESOLVED=false
SECRET_RESOLUTIONS=0
SMTP_CONNECTIONS=0
EMAILS_SENT=0
PROCESSOR_EXECUTED=false
PHPMAILER_USED=false
DB_WRITES=0
```

`READY_FOR_SMTP_SEND=true` significa que la configuración persistida permite preparar una fase de envío controlada. No autoriza el envío ni confirma conectividad con el servidor SMTP o el valor del secreto; esas comprobaciones requieren autorización expresa en una fase posterior.

## Intención auditada

- Outbox `1610`, asociada al ticket `197`.
- Evento `TICKET_CREADO` y plantilla `ticket_created`.
- Estado `PENDIENTE`, cero intentos y máximo de tres intentos.
- `ultimo_intento_at` y `enviado_at` permanecen `NULL`.
- Es la única fila elegible.
- Sobre persistido: un destinatario TO, cero CC y cero BCC.
- El destinatario coincide exactamente con `MAIL_TEST_RECIPIENT`; no se documenta la dirección completa.
- Asunto: `[R-ERP] Ticket QASMTP-000002 creado`.
- HTML SHA-256: `317b201ea58cb5e6c6d56bb76f61183e477c518e88eff9a2aff8385e70852931`.
- Texto SHA-256: `ab11ef46190c12bd9ff5ac86acde79d7bb4132dc9848ff673166280003c44d6b`.
- La CTA contiene `/tickets/productos/197` y no contiene token, magic link, sesión o CSRF.

## Resolución de cuenta

Existe una sola cuenta primaria con código arquitectónico `TICKETS_PRODUCTOS` y una sola regla para `TICKET_CREADO`. La regla está activa, referencia `MAIL_ACCOUNT_ID=1` y coincide con la cuenta primaria activa.

El enqueue usa la regla activa para validar la configuración del evento. El processor no selecciona cuentas por destinatario, empresa ni almacén: `MailOutboxProcessor` solicita directamente `MailConfigurationRepository::primaryAccount()`, cuya búsqueda usa el código fijo `TICKETS_PRODUCTOS`. No existe provider/type en el esquema actual y no se detectó fallback alternativo.

La combinación `smtp.gmail.com:587` con `tls` es coherente con STARTTLS. Remitente, nombre remitente, usuario y referencia de secreto están configurados. No se leyó el valor del secreto.

## Flujo futuro del processor

Una ejecución real seguiría este orden:

1. `claimNextEligible()` cambiaría la fila de `PENDIENTE` a `ENVIANDO`, incrementaría `intentos` de `0` a `1` y establecería `ultimo_intento_at`.
2. `MailOutboxProcessor` validaría la cuenta primaria y construiría el mensaje persistido.
3. Si el transporte requiere secreto, `resolveSecret()` invocaría el callback configurado por el comando `process`; ese callback usaría la referencia persistida para consultar el entorno.
4. `PHPMailerMailTransport` intentaría el envío.
5. En éxito, `markClaimSent()` cambiaría `ENVIANDO` a `ENVIADO` y establecería `enviado_at`.
6. En fallo, `markClaimError()` cambiaría `ENVIANDO` a `ERROR`, conservaría `intentos=1` y guardaría solo un error seguro.

La recuperación de stale considera interrumpida una fila `ENVIANDO` con más de `15` minutos y la devuelve a `ERROR` mediante el mensaje seguro definido por el processor. No se ejecutó recuperación en esta auditoría.

## Límites respetados

- No se ejecutó `process` ni dry-run.
- No se reclamó la fila `1610`.
- No se abrió socket ni conexión SMTP.
- No se instanció PHPMailer.
- No se resolvió la referencia de secreto.
- No se imprimieron cuerpos, contraseña, secreto ni destinatario completo.
- No se modificó la base de datos.
- No se modificó código.
- No se realizó commit adicional ni deploy.
