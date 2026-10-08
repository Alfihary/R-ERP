# TP-PARTIDAS-ESTADOS-CORREO-OUTBOX-DB-1

## Objetivo

Crear la estructura DB y servicios mínimos aislados para registrar historial/outbox de correos del módulo Tickets de Solicitud de Alta de Productos.

Esta fase registra correos pendientes, enviados o con error de forma segura, pero no envía correos reales, no configura SMTP, no usa PHPMailer directo, no crea worker, no crea cron, no crea rutas y no integra todavía el outbox con `ProductRequestTicketService`.

## Tabla creada

`tickets_productos_correos`

## Campos

- `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`;
- `ticket_id BIGINT UNSIGNED NOT NULL`;
- `partida_id BIGINT UNSIGNED NULL`;
- `evento VARCHAR(40) NOT NULL`;
- `plantilla VARCHAR(80) NOT NULL`;
- `destinatario_email VARCHAR(190) NOT NULL`;
- `cc_json JSON NULL`;
- `subject VARCHAR(190) NOT NULL`;
- `html MEDIUMTEXT NULL`;
- `text MEDIUMTEXT NULL`;
- `status VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE'`;
- `intentos SMALLINT UNSIGNED NOT NULL DEFAULT 0`;
- `max_intentos SMALLINT UNSIGNED NOT NULL DEFAULT 3`;
- `error_mensaje_seguro VARCHAR(500) NULL`;
- `ultimo_intento_at DATETIME NULL`;
- `enviado_at DATETIME NULL`;
- `cancelado_at DATETIME NULL`;
- `creado_por_usuario_id BIGINT UNSIGNED NULL`;
- `dedupe_key VARCHAR(190) NOT NULL`;
- `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`;
- `updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP`.

## Relaciones

- `ticket_id` referencia `tickets_productos(id)`.
- `partida_id` referencia `tickets_productos_partidas(id)` y es nullable.
- `creado_por_usuario_id` referencia `usuarios(id)` y es nullable.

## Índices

- `uq_tickets_productos_correos_dedupe` sobre `dedupe_key`;
- `idx_tickets_productos_correos_ticket`;
- `idx_tickets_productos_correos_partida`;
- `idx_tickets_productos_correos_status`;
- `idx_tickets_productos_correos_evento`;
- `idx_tickets_productos_correos_created_at`;
- `idx_tickets_productos_correos_destinatario`;
- `idx_tickets_productos_correos_pendientes` sobre `status, created_at`.

## Estados permitidos

- `PENDIENTE`;
- `ENVIANDO`;
- `ENVIADO`;
- `ERROR`;
- `CANCELADO`.

## Eventos permitidos

- `TICKET_CREADO`;
- `PARTIDA_APROBADA`;
- `PARTIDA_RECHAZADA`;
- `TICKET_RESUELTO_TOTAL`;
- `TICKET_RESUELTO_PARCIAL`;
- `TICKET_CANCELADO`.

`COMENTARIO_AGREGADO` y `ADJUNTO_CARGADO` siguen siendo eventos de ticket registrables, pero no generan correo en este outbox.

## Plantillas permitidas

- `ticket_created`;
- `line_approved`;
- `line_rejected`;
- `ticket_resolved`;
- `ticket_cancelled`.

## Dedupe key

La columna `dedupe_key` evita duplicados accidentales. La regla mínima aprobada es:

`ticket:{ticket_id}:partida:{partida_id|null}:evento:{evento}`

El índice único impide duplicar el mismo correo para el mismo evento, ticket y partida.

## Servicio/repositorio aislados

Se crean:

- `ProductTicketEmailOutboxService`;
- `ProductTicketEmailOutboxRepository`.

Responsabilidad:

- componer payload documental mínimo;
- resolver email del solicitante desde el ticket;
- crear fila `PENDIENTE`;
- devolver la fila existente si la `dedupe_key` ya existe;
- marcar `ENVIADO` sin enviar realmente;
- marcar `ERROR` con mensaje seguro;
- devolver `null` si el solicitante no tiene email válido.

No se integran todavía con el flujo real de creación, aprobación, rechazo o cancelación.

## Payload guardado

Cada fila puede guardar:

- `to` implícito en `destinatario_email`;
- `cc_json` controlado o `NULL`;
- `subject`;
- `html`;
- `text`;
- link interno relativo `/tickets/productos/{ticket_id}`.

No incluye adjuntos ni links directos a archivos.

## Seguridad y privacidad

La migración y el servicio rechazan contenido con:

- `storage/private`;
- `storage/uploads`;
- rutas físicas tipo `C:\`;
- `DSN`;
- `password`;
- `secret`;
- `token`.

El outbox no guarda credenciales, password hash, tokens, información de sesión, rutas internas, nombres internos de archivo ni errores técnicos crudos.

## Reglas fuera de alcance

No se crea:

- envío real;
- SMTP;
- PHPMailer directo;
- `mail()`;
- worker;
- cron;
- endpoint de correo;
- ruta nueva;
- permiso nuevo;
- seed nuevo;
- producto;
- precio;
- inventario;
- compra;
- proveedor;
- descarga de adjuntos;
- preview de adjuntos.

No se modifica:

- `ProductRequestTicketService`;
- `ProductRequestTicketController`;
- `routes/web.php`;
- `bootstrap/app.php`;
- `database/seeds/`;
- `config/mail.php`;
- `.env`;
- `package.json`;
- `package-lock.json`.

## Permiso futuro

El reenvío manual futuro seguirá usando el permiso existente:

`tickets_productos.correo.reenviar`

Esta fase no crea permisos nuevos, no modifica roles y no modifica seeds.

## DB-TEST

El DB-TEST de esta fase valida:

- creación de tabla;
- columnas;
- FKs;
- índices;
- checks de estado, evento, plantilla, email, intentos, dedupe y datos sensibles;
- inserción `PENDIENTE`;
- actualización a `ENVIADO`;
- actualización a `ERROR` con mensaje seguro;
- rechazo de datos sensibles;
- `enqueue` aislado sin envío;
- idempotencia por `dedupe_key`;
- solicitante sin email no crea pendiente falso;
- ausencia de SMTP, PHPMailer directo, `mail()`, rutas, seeds y dependencias.

## Siguiente fase recomendada

La siguiente fase recomendada, solo bajo autorización explícita, es:

`TP-PARTIDAS-ESTADOS-CORREO-ORQUESTACION-1`

Esa fase debería conectar eventos reales de tickets con el outbox, todavía sin obligar a SMTP productivo si no está aprobada la fase de envío.
