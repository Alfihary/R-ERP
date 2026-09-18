# TP-PARTIDAS-ESTADOS-CORREO-PROCESADOR-1

## Arquitectura

El flujo de creación del outbox permanece separado del envío:

```text
Ticket -> ProductTicketEmailNotificationService
       -> ProductTicketEmailOutboxService
       -> tickets_productos_correos (PENDIENTE)

MailOutboxProcessor -> MailTransport
                    -> PHPMailerMailTransport -> SMTP
```

`FakeMailTransport` permite validar éxitos y errores sin red. PHPMailer solo aparece en `app/Infrastructure/Mail/PHPMailerMailTransport.php`.

## Claim y concurrencia

Cada mensaje se reclama dentro de una transacción breve mediante `SELECT ... FOR UPDATE`. Antes de confirmar la transacción se cambia a `ENVIANDO`, se incrementa `intentos` y se actualiza `ultimo_intento_at`. La conexión SMTP ocurre después del commit, sin mantener bloqueos de BD durante operaciones de red.

La entrega ofrece semántica **at-least-once**, no exactly-once. Existe una ventana residual si SMTP acepta el mensaje y el proceso termina antes de marcarlo `ENVIADO`; un ciclo posterior podría reintentarlo.

## Estados, reintentos y stale

- Elegibles: `PENDIENTE` y `ERROR` con `intentos < max_intentos`.
- Excluidos: `ENVIADO`, `CANCELADO` y errores agotados.
- Éxito: `ENVIANDO -> ENVIADO`, con `enviado_at` y sin error.
- Fallo: `ENVIANDO -> ERROR`, sin `enviado_at` y con mensaje seguro.
- Un `ENVIANDO` con más de 15 minutos pasa a `ERROR` con `Procesamiento anterior interrumpido.`.

Cada ejecución procesa como máximo 10 registros y no realiza retry infinito inmediato.

## Secreto SMTP

La BD conserva únicamente `smtp_secret_ref`. El procesador valida `^[A-Z][A-Z0-9_]*$` y resuelve el valor con `Env::get()` únicamente cuando el transporte real declara que requiere secreto. `dry-run`, `functional:test` y `FakeMailTransport` no resuelven secretos.

El valor no se persiste, imprime, registra ni devuelve. Un secreto ausente produce `Configuración SMTP incompleta.`.

La configuración actual exige `smtp_username`, por lo que el transporte usa autenticación SMTP. No se habilitó relay anónimo.

## Destinatarios y contenido

`destinatario_email` es el TO principal. `cc_json` se interpreta como sobre estructurado con `to`, `cc` y `bcc`. Los emails se normalizan, validan y deduplican respetando la precedencia TO, CC y BCC. BCC nunca se convierte en CC ni se expone en la salida CLI.

Se envían directamente `subject`, `html` y `text` almacenados en el outbox. No se regeneran plantillas, no se agregan adjuntos y no se leen uploads ni almacenamiento privado.

## Errores seguros

El procesador persiste únicamente mensajes controlados para autenticación, conexión, destinatarios, configuración, timeout o fallo genérico. Nunca guarda mensajes crudos, trazas, host interno, usuario SMTP, contraseña, referencia de secreto, DSN, rutas físicas, headers, cuerpos, sesiones o tokens.

## CLI

Prueba sin red:

```text
php database/tickets-productos-partidas-estados-correo-procesador.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

Vista previa sin cambios:

```text
php database/tickets-productos-partidas-estados-correo-procesador.php dry-run --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test --limit=10
```

El comando real exige entorno local/development/test, doble confirmación de BD y `--confirm-real-email=YES`. No debe ejecutarse hasta contar con cuenta, secreto, destinatario de prueba y autorización explícita:

```text
php database/tickets-productos-partidas-estados-correo-procesador.php process --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test --limit=10 --confirm-real-email=YES
```

Esta fase implementa la capacidad, pero no realiza ningún envío real.
