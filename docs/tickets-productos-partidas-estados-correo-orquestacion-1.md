# TP-PARTIDAS-ESTADOS-CORREO-ORQUESTACION-1

## Objetivo

Conectar eventos confirmados de Tickets de Solicitud de Alta de Productos con la configuración administrativa y el outbox, sin enviar correo real.

## Arquitectura

`ProductRequestTicketService` confirma primero la transacción del ticket. Después invoca `ProductTicketEmailNotificationService`, que consulta la cuenta y regla del evento mediante `MailConfigurationService` y delega la composición/registro a `ProductTicketEmailOutboxService`.

Un fallo de configuración o del outbox no revierte la operación del ticket. El resultado controlado usa:

- `notification_enqueued`;
- `notification_reason`.

No se muestran detalles técnicos al usuario.

## Destinatarios

- TO: solicitante real, TO fijo y, cuando corresponde, el primer destino promovido si no existe TO.
- CC: configuración persistida y el usuario autenticado que ejecutó la acción cuando `enviar_responsables` está activo.
- BCC: configuración persistida.

Los correos se normalizan a minúsculas y se eliminan duplicados con precedencia TO, CC y BCC. Ningún destinatario se toma de parámetros públicos del request.

Por compatibilidad con el esquema aprobado, `destinatario_email` conserva el TO principal y `cc_json` almacena el sobre completo `{to, cc, bcc}`. No se requiere migración.

## Dedupe

Se conserva:

```text
ticket:{ticket_id}:partida:{partida_id|null}:evento:{evento}
```

El modelo es suficiente porque una partida solo puede resolverse desde `EN_REVISION`; una segunda aprobación o rechazo es rechazada. Los eventos globales usan `partida:null`.

## Eventos

- `TICKET_CREADO` -> `ticket_created`.
- `PARTIDA_APROBADA` -> `line_approved`.
- `PARTIDA_RECHAZADA` -> `line_rejected`.
- `TICKET_RESUELTO_PARCIAL` -> `ticket_resolved`, con asunto diferenciado como resolución parcial.
- `TICKET_RESUELTO_TOTAL` -> `ticket_resolved`.
- `TICKET_CANCELADO` -> `ticket_cancelled`.

`COMENTARIO_AGREGADO` y `ADJUNTO_CARGADO` no generan outbox.

## Configuración incompleta

- Regla inactiva: acción válida, sin outbox, razón `event_rule_inactive`.
- Cuenta ausente/inactiva: acción válida, sin outbox, razón `no_active_mail_account`.
- Sin destinatarios válidos: acción válida, sin outbox, razón `no_valid_recipients`.
- Dedupe existente: no duplica fila, razón `duplicate_dedupe_key`.

## Seguridad

- No se lee el valor de `smtp_secret_ref`.
- No se consulta ni imprime `.env`.
- No hay SMTP, `mail()`, PHPMailer, HTTP externo, worker ni cron.
- No se adjuntan archivos ni se crean enlaces de descarga.
- No se guardan rutas físicas, secretos, DSN, tokens ni datos de sesión.
- Las rutas, permisos y CSRF existentes de Tickets y `/admin/correo` no cambian.

## Prueba

```powershell
php database/tickets-productos-partidas-estados-correo-orquestacion.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

La prueba usa una transacción y revierte fixtures/outbox al terminar. No crea productos, precios, inventario, compras ni proveedores.

## Fuera de alcance

- Envío real.
- Lectura de secreto SMTP.
- Worker o cron.
- Nuevas tablas, migraciones, seeds, permisos o rutas.
- Comentarios o adjuntos como eventos de correo.
