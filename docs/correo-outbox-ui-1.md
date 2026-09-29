# CORREO-OUTBOX-UI-1

## Objetivo

Esta fase incorpora una superficie administrativa privada y estrictamente de solo lectura para consultar `tickets_productos_correos`. No procesa la cola, no abre conexiones SMTP y no cambia estados, intentos ni destinatarios.

## Acceso y permiso

- `GET /admin/correo/cola`: listado, contadores y filtros.
- `GET /admin/correo/cola/detalle?id={id}`: detalle seguro de un mensaje.
- Ambas rutas pasan por `AuthMiddleware` y `PermissionMiddleware` con `correos.cola.ver`.
- El seed `correo_outbox_ui_1_seed_permissions` crea o reactiva el permiso de forma idempotente y lo asigna únicamente al rol estructural `ADMIN`.
- La navegación muestra **Cola de correo** solo cuando el usuario tiene el permiso.

No existen rutas `POST`, `PUT`, `PATCH` o `DELETE` para esta superficie.

## Alcance operativo

El controlador obtiene el alcance efectivo con `ScopeContextService` y el repositorio limita cada consulta a los almacenes autorizados. Un ID fuera del alcance se comporta como recurso inexistente. Los tickets eliminados lógicamente conservan su referencia documental, pero no generan un enlace navegable.

## Listado, filtros y paginación

La lista muestra ID, ticket/folio, partida cuando aplica, evento, estado, destinatario principal, intentos, último intento, fecha de envío y fecha de creación. Los estados `PENDIENTE`, `ENVIANDO`, `ENVIADO`, `ERROR` y `CANCELADO` se distinguen mediante texto, símbolo y color.

Filtros GET disponibles:

- `estado`, limitado al catálogo de estados;
- `evento`, limitado al catálogo de eventos de tickets de productos;
- `folio`, búsqueda parcial escapada;
- `ticket_id`, entero positivo.

Los filtros inválidos se ignoran de forma segura y se informa al usuario. Todas las variables se enlazan con prepared statements. La página usa 25 filas de forma predeterminada y permite 25, 50 o 100; la navegación conserva filtros.

Los contadores por estado se obtienen con una única consulta agrupada dentro del mismo alcance operativo.

## Detalle seguro

El detalle incluye contexto del ticket, evento, plantilla, estado, intentos, fechas, destinatarios TO/CC/BCC, asunto, cuerpos de texto y HTML, error seguro, usuario creador y clave de deduplicación.

- `cc_json` se interpreta defensivamente. Una estructura JSON inválida o no reconocida muestra **Datos de destinatarios no disponibles** y no causa error fatal.
- El HTML almacenado se muestra como fuente escapada dentro de un bloque de texto; nunca se interpreta como HTML activo.
- Asunto, error, folio y demás datos se escapan en la vista.
- Solo se muestra `error_mensaje_seguro`; no se exponen excepciones, trazas, secretos SMTP, DSN ni rutas internas.

## Garantía read-only

`MailOutboxQueryRepository` solo contiene operaciones `SELECT`. El controlador no depende de `MailOutboxProcessor`, no llama `claim`, `markSent`, `markError`, reintento ni cancelación. La interfaz no contiene controles mutables.

La prueba funcional captura las filas QA antes y después de todas las consultas y exige `DB_WRITES=0`. Los fixtures se crean dentro de una transacción y se revierten al terminar.

## Ejecución controlada

Solo en la base descartable `r_erp_db_core_0_test`, con doble confirmación y nunca en producción:

```text
php database/correo-outbox-ui.php seed --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/correo-outbox-ui.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

El seed puede ejecutarse varias veces. La prueba cubre acceso guest, 403 sin permiso, 200 con permiso, 404 seguro, filtros, inyección SQL, paginación, contadores, alcance, JSON de destinatarios, XSS almacenado, ausencia de controles mutables y rollback de fixtures.

## Integridad y regresiones

La aceptación requiere conservar sin cambios:

- ticket `34`, folio `QASMTP-000001`;
- outbox `1`, estado `CANCELADO`;
- outbox `36`, estado `ENVIADO`;
- `eligible_count = 0`.

También se ejecutan las auditorías y pruebas funcionales existentes del contrato, orquestación y procesador de correo. El comando real `process` no forma parte de esta fase.

## Limitaciones y fases posteriores

- No hay reintento, cancelación o reenvío manual.
- No hay edición de destinatarios ni estados.
- No se conecta a SMTP desde la UI.
- Cualquier operación mutable futura deberá abrir una fase separada con permiso propio, CSRF, auditoría, reglas de transición y pruebas de idempotencia.
