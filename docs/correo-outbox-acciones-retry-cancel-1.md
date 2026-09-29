# CORREO-OUTBOX-ACCIONES-RETRY-CANCEL-1

## Objetivo

Implementar retry y cancelación administrativos sobre `tickets_productos_correos` de acuerdo con `CORREO-OUTBOX-ACCIONES-CONTRATO-1`, sin ejecutar SMTP desde HTTP, sin reenvío manual y sin scheduler.

## Arquitectura

- `MailOutboxController` valida entradas, resuelve usuario y `effectiveScope()`, delega la transición y responde con redirect y mensaje seguro.
- `MailOutboxActionService` concentra reglas, transacción, clasificación de resultados y auditoría obligatoria.
- `MailOutboxActionRepository` realiza consultas y updates preparados, condicionados por ID, almacén, estado e intentos.
- `AuditService` sanea metadata y `AuditRepository::insertRequired()` garantiza que una transición crítica no quede confirmada sin auditoría.
- `MailOutboxProcessor` permanece sin cambios y procesa posteriormente las filas elegibles.

No se creó un método genérico para asignar estados y el cliente nunca decide el estado destino.

## Permisos y seed

La fase incorpora:

- `correos.cola.reintentar`;
- `correos.cola.cancelar`.

El seed `correo_outbox_acciones_retry_cancel_1_seed_permissions` crea o reactiva ambos permisos y los asigna únicamente al rol estructural `ADMIN`. Es idempotente, no crea grants directos a usuarios y no modifica otros permisos.

## Rutas y middleware

Rutas privadas:

```text
POST /admin/correo/cola/reintentar
POST /admin/correo/cola/cancelar
```

Ambas pasan por:

1. `AuthMiddleware`;
2. `PermissionMiddleware` para `correos.cola.ver`;
3. `PermissionMiddleware` para la acción concreta;
4. `CsrfMiddleware` global.

Una solicitud sin sesión redirige a login. Una sesión sin el permiso específico recibe `403`. CSRF ausente o inválido recibe `419`. No existe mutación por `GET`.

## Retry

Retry solo admite:

```text
ERROR con intentos < max_intentos -> PENDIENTE
```

Conserva:

- ID y fila original;
- ticket, evento y `dedupe_key`;
- `intentos` y `max_intentos`;
- `ultimo_intento_at`;
- `error_mensaje_seguro` hasta que el processor reclame la fila;
- `enviado_at = NULL` y `cancelado_at = NULL`.

No se permite retry de `PENDIENTE`, `ENVIANDO`, `ENVIADO`, `CANCELADO` ni de `ERROR` agotado. El contador nunca se reinicia ni se decrementa.

El mensaje de éxito es: `Reintento solicitado. El mensaje quedó pendiente de procesamiento.` No afirma que el correo haya sido enviado.

## Cancelación

Cancelación admite:

```text
PENDIENTE -> CANCELADO
ERROR -> CANCELADO
```

También permite cancelar un `ERROR` agotado. Asigna `cancelado_at = CURRENT_TIMESTAMP` y conserva intentos, máximo, último intento y error seguro. `ENVIANDO` y `ENVIADO` no pueden cancelarse. `CANCELADO` es terminal y una repetición devuelve `already_changed` sin modificar `cancelado_at` ni duplicar auditoría.

El motivo es obligatorio, se normaliza con `trim` y debe contener entre 1 y 300 caracteres. Se almacena únicamente en la metadata saneada de `auditoria_eventos`; el esquema de outbox no fue modificado.

## Alcance operativo

Cada operación utiliza los almacenes de `ScopeContextService::effectiveScope()`. El repositorio enlaza la fila con `tickets_productos.almacen_id` y aplica el alcance dentro del mismo query bloqueante y del mismo update.

Una fila inexistente o fuera de alcance responde `404`. El rol `ADMIN` no evita esta restricción.

## Concurrencia e idempotencia

La fila se lee con `SELECT ... FOR UPDATE` dentro de una transacción corta. Después se ejecuta un `UPDATE` preparado y condicional. Una transición exitosa exige `rowCount() = 1`.

Resultados del servicio:

- `success`: se produjo una transición y su auditoría;
- `already_changed`: la operación ya no necesita repetirse, como cancelar una fila ya cancelada;
- `invalid_transition`: el estado o los intentos no permiten la acción;
- `not_found`: la fila no existe dentro del alcance efectivo.

Dos retry o cancelaciones concurrentes no pueden confirmar dos transiciones ni dos auditorías exitosas. `ENVIANDO` nunca es interrumpido desde HTTP.

## Auditoría y atomicidad

Eventos:

- `MAIL_OUTBOX_RETRY_REQUESTED`;
- `MAIL_OUTBOX_CANCELLED`.

La metadata contiene actor, outbox, ticket, estado anterior/nuevo, intentos, máximo y, al cancelar, motivo. IP y user agent se normalizan antes de persistirse.

La transición y la auditoría comparten conexión y transacción. Si la escritura obligatoria de auditoría falla, se revierte la transición. Las respuestas `already_changed`, `invalid_transition` y `not_found` no generan una auditoría de éxito.

No se registran secretos, credenciales SMTP, DSN, bodies completos ni `smtp_secret_ref`.

## Seguridad de entradas

El formulario de retry envía únicamente `_token` e `id`. El formulario de cancelación envía `_token`, `id` y `motivo`.

El controlador no acepta del cliente:

- estado destino;
- intentos o máximo;
- ticket;
- empresa o almacén;
- timestamps;
- dedupe key.

Los IDs se validan como enteros positivos. Todo SQL usa PDO y prepared statements. El motivo se escapa al mostrarse, al igual que el resto de los datos de vistas.

## UI

En el detalle de outbox:

- retry aparece solo para `ERROR` elegible y con permiso;
- cancelación aparece solo para `PENDIENTE` o `ERROR` y con permiso;
- `ERROR` agotado muestra `Máximo de intentos alcanzado` y conserva cancelación si está autorizada;
- `ENVIANDO` muestra `Procesamiento en curso` sin acciones;
- `ENVIADO` y `CANCELADO` no muestran acciones mutables.

Los formularios son HTML `POST` normales, incluyen CSRF y no dependen de JavaScript para seguridad. La QA real verificó 1440, 768 y 390 px sin overflow horizontal.

## DB-TEST y regresiones

Runner:

```text
php database/correo-outbox-acciones-retry-cancel.php seed --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/correo-outbox-acciones-retry-cancel.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

El seed debe ejecutarse dos veces para comprobar idempotencia. DB-TEST cubre 44 verificaciones de estados, campos preservados, scope, concurrencia, doble POST, auditoría, rollback, permisos, CSRF, SQL injection, XSS, UI y autoridad del servidor.

También deben pasar:

```text
php database/correo-outbox-ui.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/mail-dependency-phpmailer.php audit
php database/tickets-productos-partidas-estados-correo-contrato.php audit --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/tickets-productos-partidas-estados-correo-procesador.php dry-run --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/tickets-productos-partidas-estados-correo-orquestacion.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/tickets-productos-partidas-estados-correo-procesador.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

Los tests no usan transport real, no resuelven secretos y limpian sus fixtures mediante rollback o cleanup controlado.

## Integridad protegida

DB-TEST compara hashes antes y después para `tickets_productos` y `tickets_productos_correos`. Deben permanecer:

- ticket `34`, folio `QASMTP-000001`, en `EN_REVISION`;
- outbox `1` en `CANCELADO`;
- outbox `36` en `ENVIADO`, con un intento;
- `eligible_count = 0`.

## Limitaciones y siguientes fases

Retry no ejecuta SMTP, no llama al processor y no garantiza envío inmediato. La fila queda `PENDIENTE` hasta una ejecución posterior del processor.

Quedan fuera:

- reenvío manual y nuevo contrato de deduplicación;
- scheduler o cron;
- reactivación de errores agotados;
- interrupción de filas `ENVIANDO`;
- hardening o deprecación de `markSent()` y `markError()` heredados.

Las fases posteriores deberán permanecer separadas: `CORREO-OUTBOX-SCHEDULER-1`, un contrato específico de resend y, si se autoriza, hardening de métodos heredados.
