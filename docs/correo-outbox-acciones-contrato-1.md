# CORREO-OUTBOX-ACCIONES-CONTRATO-1

## Propósito y alcance

Esta fase documenta el contrato funcional y de seguridad para futuras acciones mutables sobre `tickets_productos_correos`. No implementa rutas `POST`, botones, servicios mutables, permisos, seeds, migraciones, cambios de base de datos, ejecución del procesador ni conexiones SMTP.

El contrato se apoya en la implementación existente en:

- `MailOutboxProcessor`;
- `ProductTicketEmailOutboxRepository`;
- `ProductTicketEmailOutboxService`;
- `MailOutboxQueryRepository`;
- `MailOutboxController`;
- la migración que creó `tickets_productos_correos`;
- las pruebas actuales de outbox y su interfaz de administración.

## Evidencia del comportamiento actual

El esquema admite exclusivamente los estados `PENDIENTE`, `ENVIANDO`, `ENVIADO`, `ERROR` y `CANCELADO`. También exige `intentos <= max_intentos`, `enviado_at` para `ENVIADO`, `cancelado_at` para `CANCELADO` y una `dedupe_key` única.

El procesador considera elegible una fila cuando:

```text
status = PENDIENTE
o
status = ERROR e intentos < max_intentos
```

La reclamación automática se realiza dentro de una transacción corta con `SELECT ... FOR UPDATE`, después cambia condicionalmente la fila a `ENVIANDO`, incrementa `intentos`, actualiza `ultimo_intento_at` y limpia `error_mensaje_seguro`. La transacción termina antes de iniciar SMTP.

El resultado del envío solo puede cambiar `ENVIANDO -> ENVIADO` o `ENVIANDO -> ERROR`. Las filas `ENVIANDO` abandonadas pueden recuperarse a `ERROR` después del umbral stale actual de 15 minutos. Esta recuperación no incrementa `intentos`.

## Clasificación de estados

| Estado | Elegible para processor | Terminal | Recuperación | Retry manual futuro | Cancelación futura |
|---|---:|---:|---|---|---|
| `PENDIENTE` | Sí | No | No aplica | No; ya es elegible | Sí |
| `ERROR` con intentos disponibles | Sí | No | El processor puede reclamarlo | Sí | Sí |
| `ERROR` agotado | No | No, pero queda detenido | Solo mediante acción administrativa separada, no definida aquí | No | Sí |
| `ENVIANDO` | No | No | Solo recuperación stale del processor | No | No |
| `ENVIADO` | No | Sí para la fila | No | No | No |
| `CANCELADO` | No | Sí para la fila | No | No | No; repetir cancelación es un no-op seguro |

`ENVIADO` y `CANCELADO` son terminales para la fila existente. No deben reactivarse silenciosamente.

## Acciones futuras

### Retry manual

Retry conserva la intención original, la misma fila y la misma `dedupe_key`. Se permitirá únicamente desde `ERROR` cuando `intentos < max_intentos`.

La transición propuesta es:

```text
ERROR -> PENDIENTE
```

El servidor debe ejecutar un `UPDATE` preparado, acotado por alcance y condicional al estado y a los intentos disponibles. Conceptualmente:

```sql
UPDATE tickets_productos_correos
SET status = 'PENDIENTE'
WHERE id = :id
  AND status = 'ERROR'
  AND intentos < max_intentos
```

La implementación deberá verificar `rowCount() = 1`. El cliente nunca enviará el estado destino.

El retry normal:

- no decrementa ni reinicia `intentos`;
- conserva `ultimo_intento_at`;
- conserva `error_mensaje_seguro` hasta que el processor reclame realmente la fila;
- mantiene `enviado_at` y `cancelado_at` en `NULL`;
- no crea otra fila;
- no resuelve secretos SMTP;
- no ejecuta SMTP desde la solicitud HTTP.

Conservar el error evita perder trazabilidad si el processor todavía no se ejecuta. El `claimNextEligible()` actual lo limpia cuando inicia un nuevo intento real.

Una fila `PENDIENTE` no necesita retry manual porque ya es elegible. Una segunda solicitud concurrente sobre la misma fila encontrará un estado diferente y no creará un segundo envío.

### Error agotado

Si `status = ERROR` e `intentos >= max_intentos`, el retry normal queda prohibido. No se restablecerán intentos de forma silenciosa.

Superar el máximo requeriría una acción administrativa separada, con permiso propio, confirmación reforzada, motivo obligatorio y auditoría estricta. Esa eventual acción de reactivación no forma parte de la primera fase mutable ni de este contrato.

### Cancelación manual

La cancelación actúa sobre la fila existente. Se permitirá desde `PENDIENTE` y desde `ERROR`, incluido `ERROR` agotado.

La transición propuesta es:

```text
PENDIENTE -> CANCELADO
ERROR -> CANCELADO
```

La actualización deberá ser preparada, acotada por alcance y condicional:

```sql
UPDATE tickets_productos_correos
SET status = 'CANCELADO',
    cancelado_at = CURRENT_TIMESTAMP
WHERE id = :id
  AND status IN ('PENDIENTE', 'ERROR')
```

La cancelación:

- conserva `intentos`;
- conserva `ultimo_intento_at`;
- conserva `error_mensaje_seguro`;
- exige que `enviado_at` permanezca en `NULL`;
- asigna `cancelado_at = CURRENT_TIMESTAMP`;
- no crea otra fila;
- no intenta interrumpir SMTP.

No se permite cancelar `ENVIANDO`, `ENVIADO` ni `CANCELADO`. Repetir la cancelación de una fila ya cancelada devuelve un resultado seguro sin crear una segunda transición ni una segunda auditoría de éxito.

### Reenvío manual

Reenvío no es retry. Representa una nueva intención y debe crear una fila nueva, con auditoría nueva y un contrato de deduplicación nuevo. No puede reactivar una fila `ENVIADO` o `CANCELADO`.

La clave actual es única y sigue el contrato:

```text
ticket:{ticket_id}:partida:{partida_id|null}:evento:{evento}
```

Por ello, el reenvío no puede reutilizar esa clave. Antes de implementarlo se debe aprobar una fase separada que defina la nueva `dedupe_key`, la relación con la fila original, la razón del reenvío y cualquier migración necesaria. No se fija aquí un formato definitivo ni se incluye reenvío en la primera fase mutable.

## Matriz de transición propuesta

| Estado actual | Acción | Condición | Estado final | Resultado esperado |
|---|---|---|---|---|
| `PENDIENTE` | Retry | No permitido | Sin cambio | `invalid_transition` |
| `PENDIENTE` | Cancelar | Dentro de alcance | `CANCELADO` | `success` |
| `ERROR` | Retry | `intentos < max_intentos` | `PENDIENTE` | `success` |
| `ERROR` | Retry | `intentos >= max_intentos` | Sin cambio | `invalid_transition` con mensaje de máximo |
| `ERROR` | Cancelar | Dentro de alcance | `CANCELADO` | `success` |
| `ENVIANDO` | Retry o cancelar | Siempre prohibido | Sin cambio | `invalid_transition` |
| `ENVIADO` | Retry o cancelar | Estado terminal | Sin cambio | `invalid_transition` |
| `CANCELADO` | Retry | Estado terminal | Sin cambio | `invalid_transition` |
| `CANCELADO` | Cancelar de nuevo | Ya cancelado | Sin cambio | `already_changed` |

La recuperación stale `ENVIANDO -> ERROR` permanece reservada al processor. Una acción HTTP no intentará detener un envío SMTP en curso.

## Permisos y roles

La primera fase mutable debe crear permisos separados:

- `correos.cola.reintentar`;
- `correos.cola.cancelar`.

Un reenvío futuro requerirá `correos.cola.reenviar`.

No se reutilizarán `correos.cola.ver` ni `configuracion.correo.administrar` para mutar filas. Los permisos se asignarán inicialmente solo a `ADMIN` mediante un seed idempotente. No se otorgarán permisos directamente a usuarios. El seed pertenece a la fase de implementación posterior y no se crea ni ejecuta aquí.

## Autenticación, autorización y CSRF

Cada acción futura será una ruta `POST` protegida por:

1. `AuthMiddleware`;
2. `PermissionMiddleware` con el permiso específico de la acción;
3. validación CSRF obligatoria.

Un token CSRF ausente o inválido responderá `419`. No habrá mutaciones por `GET`.

El controlador aceptará únicamente el identificador, el token CSRF y, para cancelar, el motivo. No aceptará `status`, `intentos`, timestamps, `dedupe_key`, destinatarios ni campos SMTP. El servidor determina la transición y los valores mutables.

## Alcance operativo e IDOR

La interfaz read-only actual resuelve `ScopeContextService::resolveForUser()` y limita consultas a los almacenes de `effectiveScope()`, enlazando el outbox con `tickets_productos.almacen_id`.

Las mutaciones deben aplicar exactamente el mismo alcance efectivo. Tener rol `ADMIN` o permisos de acción no implica saltarse automáticamente el alcance de almacén. Un operador global requeriría una decisión arquitectónica y un permiso explícito posteriores.

El repositorio mutable deberá incluir la restricción por ticket y almacén en la misma operación condicional. Un ID inexistente o fuera de alcance responderá como no encontrado después de pasar autenticación y permiso, evitando revelar la existencia de filas ajenas.

## Concurrencia e idempotencia

La implementación debe proteger las carreras entre administrador y processor, doble clic y dos administradores:

- utilizar `UPDATE` condicional por ID, alcance, estado esperado y límite de intentos;
- comprobar `rowCount() = 1`;
- no hacer una lectura y una actualización independientes sin protección;
- no bloquear una fila mientras se ejecuta SMTP;
- registrar auditoría solo cuando se confirma una transición real;
- volver a leer de forma acotada el estado actual cuando el `UPDATE` no afecte una fila, para clasificar el resultado sin filtrar datos fuera de alcance.

Si el worker gana la carrera, la fila pasa a `ENVIANDO` y retry/cancel devuelven `already_changed` o `invalid_transition`. Si la cancelación gana, la fila deja de ser elegible. Si retry gana, solo la fila original pasa a `PENDIENTE` y el processor puede reclamarla una vez.

Los resultados de dominio serán:

- `success`: se realizó exactamente una transición válida;
- `already_changed`: la solicitud repetida encontró el resultado ya aplicado o un cambio concurrente compatible;
- `invalid_transition`: el estado actual no permite la acción.

Una respuesta idempotente no debe generar otra fila, otro intento ni otro evento de auditoría de transición exitosa.

## Auditoría obligatoria

Las transiciones futuras se integrarán con `AuditService` y `auditoria_eventos`. Los eventos propuestos son:

- `MAIL_OUTBOX_RETRY_REQUESTED`;
- `MAIL_OUTBOX_CANCELLED`.

Cada evento debe incluir, usando columnas y metadata saneada:

- usuario actor;
- ID de outbox;
- ID de ticket;
- estado anterior y nuevo;
- `intentos` y `max_intentos`;
- motivo de cancelación, cuando corresponda;
- resultado;
- IP y user agent mediante el contexto de request establecido;
- timestamp provisto por `auditoria_eventos`.

No se almacenarán secretos, credenciales SMTP, cuerpos HTML/texto, headers SMTP, errores internos sin sanear ni destinatarios cuando no sean necesarios.

El `AuditService` actual captura y oculta cualquier excepción porque fue diseñado como auditoría best-effort. Para estas mutaciones administrativas críticas, la fase de implementación debe añadir una vía estricta soportada —por ejemplo `recordRequired()`— o persistir transición y auditoría dentro de la misma transacción. Si la auditoría obligatoria falla, la transición debe revertirse. Esta garantía no se implementa en la fase documental.

## Motivos

La cancelación requiere un motivo corto obligatorio de 1 a 300 caracteres, normalizado y escapado al mostrarse. El esquema actual no contiene una columna para motivo ni actor de cancelación.

Para la primera fase mutable, el motivo puede persistirse únicamente en `auditoria_eventos`, siempre que la operación use la garantía transaccional estricta descrita arriba. Si se requiere consultar el motivo directamente desde el detalle de outbox o conservarlo independientemente del subsistema de auditoría, deberá aprobarse una fase DB separada para agregar columnas. No se crea migración en esta fase.

El retry normal no requiere motivo. La auditoría obligatoria de la acción es suficiente y evita sobrecargar la UX.

## Processor, SMTP y scheduler

Retry manual solo marca la fila como elegible. No llama a `MailOutboxProcessor`, no resuelve `smtp_secret_ref` y no abre una conexión SMTP desde HTTP.

El processor existente puede recoger posteriormente la fila `PENDIENTE`, incrementará `intentos` cuando la reclame y hará el envío fuera de la transacción de claim. No necesita modificarse para soportar el retry propuesto.

Actualmente no existe scheduler. En consecuencia, solicitar un retry no garantiza envío inmediato: la fila espera hasta la siguiente ejecución normal del processor. La automatización periódica debe resolverse en una fase posterior como `CORREO-OUTBOX-SCHEDULER-1`.

## Métodos heredados y riesgo

`ProductTicketEmailOutboxRepository::markSent()` y `markError()` actualizan cualquier fila por ID, no validan el estado previo e incrementan `intentos`. Sus equivalentes públicos en `ProductTicketEmailOutboxService` exponen esa semántica. La búsqueda actual muestra uso en pruebas heredadas; el processor operativo utiliza en cambio `markClaimSent()` y `markClaimError()`, que exigen `ENVIANDO`.

Las futuras acciones administrativas no deben llamar `markSent()`, `markError()` ni sus wrappers. Restringir, deprecar o eliminar esos métodos requiere una fase de hardening separada con revisión de compatibilidad y actualización de pruebas; no se modifican aquí.

## UX futura y mensajes seguros

En el detalle read-only podrán aparecer, en una fase posterior:

- `Reintentar` solo para `ERROR` elegible y con `correos.cola.reintentar`;
- `Cancelar` solo para `PENDIENTE` o `ERROR` y con `correos.cola.cancelar`;
- ninguna acción para `ENVIANDO`, `ENVIADO` o `CANCELADO`.

La cancelación requiere confirmación clara y captura de motivo. Retry puede usar una confirmación simple. Se prefieren formularios `POST` normales y progresivos, sin JavaScript inseguro.

Mensajes permitidos:

- `Reintento solicitado.`
- `Mensaje cancelado.`
- `El estado del mensaje cambió y ya no permite esta acción.`
- `Se alcanzó el máximo de intentos.`
- `No tienes permiso para realizar esta acción.`

No se muestran SQL, excepciones, configuración SMTP, secretos ni diagnósticos internos.

## Plan de pruebas para la fase mutable

1. Retry de `ERROR` elegible cambia exactamente una fila a `PENDIENTE`.
2. Retry de `ERROR` agotado es rechazado sin reiniciar intentos.
3. Retry de `PENDIENTE` es rechazado porque ya es elegible.
4. Retry de `ENVIANDO` es rechazado.
5. Retry de `ENVIADO` es rechazado.
6. Retry de `CANCELADO` es rechazado.
7. Cancelación de `PENDIENTE` produce `CANCELADO` y `cancelado_at`.
8. Cancelación de `ERROR`, incluido agotado, produce `CANCELADO`.
9. Cancelación de `ENVIANDO` es rechazada.
10. Cancelación de `ENVIADO` es rechazada.
11. Cancelación repetida de `CANCELADO` es un no-op seguro.
12. Invitado es redirigido o rechazado por autenticación.
13. Usuario autenticado sin permiso recibe `403` y no hay mutación.
14. `POST` sin CSRF o con token inválido recibe `419`.
15. ID inexistente recibe `404` y no hay auditoría de transición exitosa.
16. ID fuera del alcance recibe `404` y no filtra su existencia.
17. Dos `POST` concurrentes de retry producen una sola transición.
18. Dos `POST` concurrentes de cancelación producen una sola transición.
19. Carrera con estado cambiado por processor devuelve resultado seguro y no pisa `ENVIANDO`.
20. Se respeta `max_intentos` en repositorio y servicio.
21. Retry no decrementa ni reinicia `intentos` y conserva historial.
22. Retry exitoso crea exactamente un evento `MAIL_OUTBOX_RETRY_REQUESTED` saneado.
23. Cancelación exitosa crea exactamente un evento `MAIL_OUTBOX_CANCELLED` con motivo saneado.
24. Las rutas HTTP no abren conexión SMTP ni llaman al transport.
25. Las rutas HTTP no resuelven secretos ni exponen `smtp_secret_ref`.
26. Un processor fake posterior reclama una fila reintentada y conserva la semántica de intentos.
27. Ticket `34`, outbox `1` y outbox `36` permanecen intactos durante DB-TEST.
28. La UI muestra acciones solo con permiso y transición válida, y escapa todo contenido.
29. Confirmaciones y formularios funcionan en escritorio y viewport móvil sin desbordamiento.
30. IDs y motivos maliciosos no producen inyección SQL, mass assignment ni XSS.

## Datos protegidos y no regresión

Las pruebas futuras deben preservar:

- ticket `id = 34`, folio `QASMTP-000001`;
- outbox `id = 1` en `CANCELADO`;
- outbox `id = 36` en `ENVIADO`;
- `eligible_count = 0` antes y después de esta fase documental.

Esta fase no modifica filas, productos, precios, existencias, tickets, intentos, dedupe keys ni timestamps.

## Riesgos y rollback

Riesgos que debe resolver la implementación futura:

- carrera entre acción administrativa y processor;
- salto de alcance e IDOR;
- doble submit;
- reinicio silencioso de intentos;
- pérdida de auditoría por el comportamiento best-effort actual;
- uso accidental de métodos heredados sin precondición de estado;
- confundir retry con resend;
- aparentar envío inmediato cuando no existe scheduler.

El rollback de esta fase documental consiste únicamente en eliminar este documento no versionado. No hay rollback de base de datos ni de código funcional.

## Fases siguientes recomendadas

Después de aprobar este contrato puede abrirse `CORREO-OUTBOX-ACCIONES-RETRY-CANCEL-1`, limitada a retry y cancelación, con permisos separados, seed idempotente para `ADMIN`, auditoría estricta, operaciones condicionales con alcance y DB-TEST completo.

El reenvío debe permanecer en `CORREO-OUTBOX-REENVIO-CONTRATO-1`, porque requiere definir nueva intención, dedupe y posible relación con la fila original. El scheduler debe permanecer separado en `CORREO-OUTBOX-SCHEDULER-1`.

No debe iniciarse ninguna de esas fases sin autorización expresa.
