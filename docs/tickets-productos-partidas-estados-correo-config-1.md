# TP-PARTIDAS-ESTADOS-CORREO-CONFIG-1

## Objetivo

Crear configuración administrable de correo para Tickets de Solicitud de Alta de Productos, sin enviar correos reales.

## Alcance creado

- Tabla `mail_accounts` para datos SMTP visibles/no sensibles.
- Tabla `tickets_productos_correo_reglas` para reglas por evento de tickets de productos.
- Servicio `MailConfigurationService` para validar cuenta, destinatarios y reglas.
- Repositorio `MailConfigurationRepository` con PDO y prepared statements.
- Controlador `MailConfigurationController`.
- Pantalla privada `/admin/correo` dentro del layout ERP.
- Runner `database/tickets-productos-partidas-estados-correo-config.php`.
- DB-TEST `tickets_productos_partidas_estados_correo_config_1_test.php`.

## Manejo de secretos

La base de datos guarda solo `smtp_secret_ref`, por ejemplo:

```text
MAIL_TICKETS_PRIMARY_PASSWORD
```

El valor real del secreto debe configurarse manualmente fuera de la base de datos, por ejemplo en el entorno local o del servidor. La UI no captura ni muestra el secreto real.

## Eventos soportados

- `TICKET_CREADO`
- `PARTIDA_APROBADA`
- `PARTIDA_RECHAZADA`
- `TICKET_RESUELTO_TOTAL`
- `TICKET_RESUELTO_PARCIAL`
- `TICKET_CANCELADO`

## Destinatarios

Cada regla puede activar:

- envío al solicitante;
- envío a responsables configurados;
- lista fija controlada en TO;
- lista fija controlada en CC;
- lista fija controlada en BCC.

El servicio normaliza emails a minúsculas, valida formato y rechaza duplicados entre TO, CC y BCC.

## Permiso

Permiso estructural creado por seed:

```text
configuracion.correo.administrar
```

El seed crea el permiso, pero no lo asigna a usuarios arbitrarios.

## Fuera de alcance

- No se envía correo real.
- No se integra SMTP runtime.
- No se modifica `.env`.
- No se imprime ni guarda contraseña SMTP.
- No se modifica `config/mail.php`.
- No se modifica `ProductTicketEmailOutboxService`.
- No se modifica `ProductTicketEmailOutboxRepository`.
- No se crean productos, precios, inventario, compras ni proveedores.
- No se inicia otra fase.

## Prueba de fase

```powershell
php database/tickets-productos-partidas-estados-correo-config.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

El runner rechaza producción y exige que `--database`, `--confirm-database` y `APP_DB_NAME` coincidan exactamente.
