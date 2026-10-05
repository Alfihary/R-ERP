# CORREO-SMTP-QA-OVERRIDE-CONTRATO-1

```ini
PHASE_STATUS=PASS
QA_OVERRIDE_CLI_ONLY=true
QA_OVERRIDE_PRODUCTION_ALLOWED=false
QA_OVERRIDE_REPLACES_OPERATIONAL_RECIPIENTS=true
QA_OVERRIDE_APPENDS=false
QA_RECIPIENT_SOURCE=MAIL_TEST_RECIPIENT
QA_ALLOWED_EVENTS=TICKET_CREADO
USE_EXISTING_TICKET_34=false
USE_OUTBOX_36=false
NEW_QA_FIXTURE_REQUIRED=true
DELETE_QA_ARTIFACTS=false
PROCESSOR_MODIFICATION_REQUIRED=false
SMTP_EXECUTED=false
DB_WRITES=0
NEXT_PHASE=CORREO-SMTP-QA-OVERRIDE-IMPLEMENTACION-1
```

## Objetivo y alcance

Esta fase define el contrato de arquitectura para crear, en una fase posterior,
una intención de correo QA con un único destinatario autorizado. El override
será una capacidad explícita de tooling CLI, limitada a un entorno no
productivo y a `r_erp_db_core_0_test`.

El contrato no crea tickets ni filas de outbox, no cambia reglas operativas y
no prueba SMTP. Tampoco modifica código productivo, migraciones, seeds,
processor, transporte o configuración persistida.

La necesidad del contrato proviene de dos condiciones verificadas:

- la regla operativa de `TICKET_CREADO` agrega solicitante, responsables y
  destinatarios explícitos, por lo que no está aislada para QA;
- el dedupe `ticket:34:partida:null:evento:TICKET_CREADO` pertenece al outbox
  histórico `36`, ya enviado, y debe conservarse.

## Arquitectura actual verificada

### Resolución de configuración

`ProductTicketEmailNotificationService::handle()` solicita a
`MailConfigurationService::runtimeEventConfiguration()` la cuenta activa y la
regla del evento. El servicio valida cuenta, regla, estado activo y relación
entre ambas antes de delegar.

`runtimeEventConfiguration()` no resuelve el valor de `smtp_secret_ref`. La
configuración operativa se obtiene de `mail_accounts` y
`tickets_productos_correo_reglas` mediante `MailConfigurationRepository`.

No existe actualmente `config/mail.php`. La configuración base usada al
construir plantillas procede de `config/app.php`, principalmente `APP_URL`,
`APP_TIMEZONE` y `APP_ENV`; la cuenta y las reglas de correo son persistidas.

### Resolución de destinatarios y construcción del envelope

`ProductTicketEmailOutboxService::enqueueConfigured()` carga el contexto del
ticket y delega en su método privado `resolveRecipients()`. Ese método combina:

- `to_json`, `cc_json` y `bcc_json` de la regla;
- el email del solicitante cuando `enviar_solicitante=1`;
- el email del responsable cuando `enviar_responsables=1`;
- normalización y eliminación de duplicados entre TO, CC y BCC.

El envelope normalizado se persiste en `cc_json`; el primer TO también se
mantiene en `destinatario_email` por compatibilidad.

### Payload, renderer y CTA

`ProductTicketEmailTemplatePayloadBuilder` construye el payload usando el
ticket, sus partidas cuando el evento las requiere, la cantidad de adjuntos,
`APP_URL` y la zona horaria. Para `TICKET_CREADO`, la CTA se deriva como:

```text
{APP_URL}/tickets/productos/{ticket_id}
```

`ProductTicketEmailTemplateRenderer` selecciona `ticket_created` para
`TICKET_CREADO` y produce subject, HTML y texto. El override no puede modificar
payload, template, subject, cuerpos ni CTA.

### Dedupe y persistencia

Después de resolver destinatarios, `ProductTicketEmailOutboxService` calcula:

```text
ticket:{ticket_id}:partida:{partida_id|null}:evento:{evento}
```

Consulta el dedupe mediante
`ProductTicketEmailOutboxRepository::findByDedupeKey()`. Solo si no existe,
renderiza y llama a `insertPending()`. La fila queda `PENDIENTE`, con cero
intentos y sin fechas de intento o envío.

El override no puede aceptar una clave manual ni cambiar el algoritmo de
dedupe.

### Frontera del transporte y secretos

La creación de la intención termina al persistir el outbox. No utiliza
`MailOutboxProcessor`, `MailTransport` ni PHPMailer.

La frontera de transporte comienza cuando `MailOutboxProcessor::process()`
reclama una fila elegible. Solo entonces, si el transporte declara
`requiresSecret()=true`, el processor resuelve `smtp_secret_ref` y llama a
`MailTransport::send()`. `PHPMailerMailTransport` construye PHPMailer y abre la
conexión SMTP dentro de `send()`.

Por tanto, la futura creación QA debe terminar antes de esa frontera:

```ini
PROCESSOR_EXECUTED=false
SMTP_CONNECTIONS=0
EMAILS_SENT=0
SECRET_RESOLUTIONS=0
```

`MAIL_TEST_RECIPIENT` es un dato autorizado para el envelope QA, no un secreto
SMTP. Su valor no debe imprimirse completo.

## Punto de extensión recomendado

Se elige la opción **B: `QaMailContext`**, como objeto inmutable de capacidad
explícita para tooling QA.

No se recomienda un flag global ni añadir un parámetro opcional ambiguo a
`handle()`. El método productivo actual debe conservar su firma y
comportamiento.

La futura implementación debe agregar una entrada separada, conceptualmente:

```php
ProductTicketEmailNotificationService::handleQa(
    string $event,
    int $ticketId,
    ?int $partidaId,
    int $actorUserId,
    QaMailContext $qa
): array
```

`QaMailContext` debe transportar únicamente valores ya validados:

- destinatario QA normalizado;
- entorno efectivo no productivo;
- base configurada, base activa y base confirmada;
- confirmación explícita de no envío;
- evento autorizado;
- identidad del fixture QA.

Su construcción debe quedar en una factory de CLI/tooling no registrada en
rutas web. La factory debe leer `MAIL_TEST_RECIPIENT` desde el entorno y debe
rechazar datos enviados por query string, POST, sesión o UI.

`handleQa()` debe validar nuevamente el contexto y delegar al mismo núcleo de
`ProductTicketEmailOutboxService` que usa el flujo normal. El outbox service
debe tener una ruta interna explícita para aplicar el envelope del contexto
antes de persistir:

```text
TO  = [MAIL_TEST_RECIPIENT]
CC  = []
BCC = []
```

La ruta QA reemplaza el resultado de `resolveRecipients()`; no modifica la
regla y no combina destinatarios operativos. Después continúa con el mismo
payload builder, renderer, dedupe y repository.

El comportamiento por defecto queda intacto:

```text
handle(...)                  -> reglas operativas actuales
handleQa(..., QaMailContext) -> envelope QA reemplazado
```

El contexto no debe almacenarse globalmente ni quedar disponible mediante el
container web normal. No se autoriza una variable como
`MAIL_OVERRIDE_ALL_RECIPIENTS=true`.

## Guardas obligatorias del tooling CLI

La futura herramienta deberá ser CLI-only y exigir exactamente:

```text
--database=r_erp_db_core_0_test
--confirm-database=r_erp_db_core_0_test
--confirm-event=TICKET_CREADO
--confirm-no-send=YES
```

Antes de construir `QaMailContext` deberá comprobar:

1. `PHP_SAPI === 'cli'`;
2. `APP_ENV` pertenece a `local`, `development` o `test`;
3. `APP_ENV !== production`;
4. el nombre recibido, el confirmado, `APP_DB_NAME` y `SELECT DATABASE()` son
   exactamente `r_erp_db_core_0_test`;
5. `MAIL_TEST_RECIPIENT` existe y contiene exactamente un email válido;
6. el evento es exactamente `TICKET_CREADO`;
7. `--confirm-no-send=YES` está presente;
8. el ticket cumple el marcador estructural QA;
9. el dedupe calculado por el servicio no existe;
10. no hay otra fila elegible antes de crear la intención.

Si cualquiera falla:

```ini
WRITE_REFUSED=true
```

La prohibición de producción es absoluta:

```ini
QA_RECIPIENT_OVERRIDE_ALLOWED_IN_PRODUCTION=false
```

No basta con confiar en argumentos CLI; ambiente, configuración y conexión
activa deben coincidir.

## Validación del destinatario QA

La fuente única es:

```ini
QA_RECIPIENT_SOURCE=MAIL_TEST_RECIPIENT
```

La factory deberá:

- recortar y normalizar a minúsculas;
- rechazar vacío;
- rechazar CR, LF y cualquier carácter de control;
- rechazar espacios internos;
- rechazar separadores de listas como coma o punto y coma;
- validar con `FILTER_VALIDATE_EMAIL`;
- exigir exactamente una dirección;
- producir exactamente `TO=1`, `CC=0`, `BCC=0`;
- evitar imprimir el valor completo, su hash, longitud o fragmentos sensibles;
- reportar solamente `configured=true|false` y una forma enmascarada.

El envelope QA reemplaza completamente al operativo:

```ini
QA_OVERRIDE_REPLACES_OPERATIONAL_RECIPIENTS=true
QA_OVERRIDE_APPENDS=false
```

No se consultarán destinatarios del ticket para completar el envelope. La
cuenta remitente persistida puede seguir siendo la cuenta normal porque el
override afecta únicamente destinatarios, no transporte ni credenciales.

## Evento permitido

La primera implementación debe usar una lista cerrada:

```ini
QA_ALLOWED_EVENTS=TICKET_CREADO
```

Se rechazarán `PARTIDA_APROBADA`, `PARTIDA_RECHAZADA`,
`TICKET_RESUELTO_TOTAL`, `TICKET_RESUELTO_PARCIAL`, `TICKET_CANCELADO` y
cualquier valor desconocido.

`TICKET_CREADO` mantiene:

- template `ticket_created`;
- ausencia de `partida_id`;
- subject producido por el renderer;
- HTML, texto y CTA producidos por el pipeline oficial.

## Política del nuevo fixture QA

```ini
USE_EXISTING_TICKET_34=false
USE_OUTBOX_36=false
NEW_QA_FIXTURE_REQUIRED=true
```

El ticket `34`, folio `QASMTP-000001`, y el outbox `36` son evidencia histórica
y quedan fuera del nuevo ciclo. No se reintentan, reabren, clonan ni modifican.

La futura fase debe crear un ticket dedicado que cumpla simultáneamente:

- existe solo en `r_erp_db_core_0_test`;
- no representa una operación real;
- estado inicial `EN_REVISION`;
- cero partidas y cero adjuntos;
- cero efectos de productos, precios, inventario o finanzas;
- no puede convertirse en producto;
- utiliza empresa, almacén y actor controlados de QA;
- queda identificable en forma persistente.

### Marcador estructural

El esquema actual no contiene una columna específica de fixture QA. No se
agregará una columna en esta fase. La opción mínima segura usa una combinación
obligatoria, no solo el aspecto del folio:

1. base activa y confirmada `r_erp_db_core_0_test`;
2. `observaciones_generales` exactamente
   `[QA_FIXTURE:CORREO_SMTP]`;
3. folio dentro del namespace `QASMTP-`;
4. cero partidas;
5. cero adjuntos;
6. ausencia de vínculos a producto, inventario o flujo financiero;
7. evento permitido `TICKET_CREADO`.

La ausencia de cualquiera de estos elementos invalida el fixture. El marker
de observaciones es persistente y debe verificarse desde la fila bloqueada
antes de crear el outbox.

### Creación del fixture y folio

`QASMTP-000002` es solo un ejemplo conceptual. La implementación deberá
consultar disponibilidad y reservar el siguiente folio QA válido. Si un
servicio oficial puede manejar un namespace QA sin consumir folios operativos,
debe reutilizarse. No se permite hardcodear un folio sin consultar la base.

`ProductRequestTicketService::crearTicket()` no es apropiado sin adaptación:
actualmente crea partidas según el formulario y después invoca la notificación
operativa. La futura implementación debe proporcionar un servicio CLI de
fixture, transaccional y limitado a la base QA, que reutilice repositorios y el
mecanismo de folios aplicable pero no dispare la notificación normal. Al
terminar el commit del fixture deberá invocar `handleQa()` una sola vez.

No se autoriza `INSERT` manual en `tickets_productos_correos`. La creación del
fixture tampoco debe introducir SQL ad hoc cuando exista un repositorio
oficial reutilizable.

## Política de dedupe e idempotencia

Para el nuevo ticket, la clave será calculada exclusivamente por
`ProductTicketEmailOutboxService::dedupeKey()`:

```text
ticket:{nuevo_ticket_id}:partida:null:evento:TICKET_CREADO
```

Reglas:

- el caller no puede proporcionar ni alterar el dedupe;
- debe verificarse que no exista antes de crear;
- una segunda invocación debe devolver `duplicate_dedupe_key`;
- el conteo del dedupe debe permanecer en uno;
- la segunda invocación no puede crear otra candidata;
- no se reutiliza el dedupe del ticket 34;
- no se elimina un histórico para liberar una clave.

## Processor y SMTP

```ini
PROCESSOR_MODIFICATION_REQUIRED=false
```

El processor debe seguir tratando la fila QA como un outbox normal. No debe
conocer `QaMailContext`, el marker del fixture ni `MAIL_TEST_RECIPIENT`.

La fase de implementación del override únicamente crea la intención y termina
con una candidata `PENDIENTE`. No ejecuta `dry-run` si este pudiera cambiar
estado, y nunca ejecuta `process`.

La futura conexión SMTP requiere una fase y autorización separadas. En esta
fase contractual y en la fase de creación de intención:

```ini
SMTP_EXECUTED=false
SMTP_CONNECTIONS=0
EMAILS_SENT=0
SECRET_RESOLUTIONS=0
```

## Condiciones fail-closed

La herramienta debe bloquear sin crear fixture ni outbox cuando ocurra al
menos una de estas condiciones:

- ejecución fuera de CLI;
- `APP_ENV=production` o ambiente no permitido;
- argumento de base ausente o distinto;
- confirmación de base ausente o distinta;
- `APP_DB_NAME` o base activa distinta;
- `MAIL_TEST_RECIPIENT` ausente, inválido o múltiple;
- CRLF, caracteres de control o separadores de lista en el destinatario;
- evento distinto de `TICKET_CREADO`;
- falta `--confirm-no-send=YES`;
- ticket fuera de la base QA;
- marker estructural incompleto;
- ticket con partidas, adjuntos o impacto operativo;
- dedupe ya existente;
- `eligible_count` inicial distinto de cero;
- intento de usar ticket 34 u outbox 36;
- intento de recibir destinatario desde web, UI, POST, query string o sesión;
- intento de agregar el destinatario QA a destinatarios operativos;
- intento de resolver secretos o construir transporte.

Todo rechazo debe usar mensajes seguros sin imprimir emails completos,
credenciales, DSN, rutas privadas o cuerpos de correo.

## Ciclo QA futuro

1. Confirmar rama, HEAD, Git limpio y staging vacío.
2. Confirmar ambiente y las cuatro identidades de base.
3. Validar `MAIL_TEST_RECIPIENT` sin exponerlo.
4. Confirmar `eligible_count=0`.
5. Crear el fixture QA transaccional con marker persistente.
6. Confirmar cero partidas, cero adjuntos y cero impacto operativo.
7. Construir `QaMailContext` desde tooling CLI.
8. Invocar `ProductTicketEmailNotificationService::handleQa()` con
   `TICKET_CREADO`.
9. Resolver el envelope QA antes del renderer, sin combinar reglas.
10. Construir payload, renderizar y persistir por el pipeline oficial.
11. Confirmar exactamente una fila `PENDIENTE`, intentos cero y fechas nulas.
12. Repetir la invocación y confirmar idempotencia sin nueva fila.
13. Confirmar `eligible_count=1`.
14. Revisar subject, tamaños/hashes de cuerpos y CTA sin imprimir cuerpos.
15. Detenerse sin processor, secreto ni SMTP.
16. Abrir una fase separada para enviar exactamente una candidata.
17. Verificar `ENVIADO` o `ERROR` y preservar la trazabilidad.

## Política de conservación

```ini
DELETE_QA_ARTIFACTS=false
```

El fixture QA y sus outbox deben conservarse como evidencia. No habrá DELETE
automático, reciclaje de identificadores, reapertura de outbox ni borrado de
dedupe. Si se necesita retirarlos del flujo operativo, se documentará una
microfase separada que mantenga la trazabilidad y no genere otro correo.

## Plan de pruebas para la implementación

La fase `CORREO-SMTP-QA-OVERRIDE-IMPLEMENTACION-1` deberá cubrir al menos:

| Caso | Resultado esperado |
| --- | --- |
| A. Override con `APP_ENV=production` | Rechazo y cero escrituras |
| B. Base pedida, confirmada, configurada o activa distinta | Rechazo |
| C. `MAIL_TEST_RECIPIENT` ausente | Rechazo |
| D. Destinatario inválido, CRLF o carácter de control | Rechazo |
| E. Dos destinatarios o separadores de lista | Rechazo |
| F. Evento no autorizado | Rechazo |
| G. Ticket sin marker QA completo | Rechazo |
| H. Dedupe ocupado | Rechazo sin duplicado |
| I. Override válido | TO=1, CC=0, BCC=0 |
| J. Regla operativa con destinatarios adicionales | No se agregan |
| K. `handle()` sin contexto QA | Comportamiento actual intacto |
| L. Crear intención | Cero SMTP y cero processor |
| M. Crear intención | Cero resoluciones de secretos |
| N. Segunda creación idéntica | Idempotencia, una sola fila |
| O. Fixture | Cero partidas, adjuntos e impacto operativo |
| P. Fila creada | `PENDIENTE`, intentos 0, fechas nulas |
| Q. Conteos | tickets +1, outbox +1, eligible 0 a 1 |
| R. Fallo posterior al inicio del fixture | Rollback completo |

Los fixtures de DB-TEST deben ejecutarse en transacción o limpiarse por
rollback, sin tocar evidencia histórica ni destinatarios reales.

## Compatibilidad obligatoria

La implementación debe demostrar que permanecen sin cambios:

- la firma y semántica de `ProductTicketEmailNotificationService::handle()`;
- las reglas productivas de solicitante, responsables, TO, CC y BCC;
- las pruebas existentes de destinatarios productivos;
- las pruebas de outbox, renderer, processor, retry y cancelación;
- el contrato de dedupe;
- `MailConfigurationService::runtimeEventConfiguration()` sin resolver
  secretos;
- `MailOutboxProcessor` y `MailTransport`;
- las rutas web, controladores, UI y permisos;
- ticket 34 y outbox 36;
- outbox 698, 699 y 700.

No debe añadirse el runner QA a `bootstrap/app.php` ni exponerse como servicio
web. El wiring CLI será independiente y explícito.

## Archivos previstos para la siguiente fase

La fase de implementación podrá proponer, sujeto a precheck y autorización:

- un `QaMailContext` inmutable;
- una factory CLI que aplique las guardas de ambiente, base y destinatario;
- una entrada `handleQa()` separada;
- el seam interno mínimo en el outbox service;
- un runner CLI específico;
- un DB-TEST con rollback;
- documentación de evidencia.

No se autoriza anticipadamente un nombre o ubicación exactos. La siguiente
fase deberá justificar cada archivo antes de modificarlo.

## Criterios de aceptación contractual

- override explícito y CLI-only;
- producción rechazada de forma fail-closed;
- base limitada a `r_erp_db_core_0_test` con doble confirmación y base activa;
- destinatario obtenido solo de `MAIL_TEST_RECIPIENT`;
- un TO, sin CC ni BCC;
- reemplazo, nunca append;
- evento limitado a `TICKET_CREADO`;
- fixture nuevo, persistente y estructuralmente identificable;
- ticket 34 y outbox 36 excluidos;
- dedupe nuevo y calculado por el servicio;
- renderer y repository oficiales preservados;
- processor sin cambios;
- ninguna resolución de secretos durante la creación;
- prueba SMTP separada;
- plan de fallos e idempotencia definido;
- compatibilidad productiva exigida;
- conservación de evidencia QA;
- cero escrituras DB en esta fase;
- cero código productivo en esta fase.

## Estado de esta fase

```ini
PHP_MODIFIED=false
SQL_MODIFIED=false
MIGRATIONS_CREATED=false
SEEDS_CREATED=false
TESTS_CREATED=false
DB_WRITES=0
PROCESSOR_EXECUTED=false
SMTP_CONNECTIONS=0
EMAILS_SENT=0
SECRET_RESOLUTIONS=0
STAGING=false
COMMIT=false
PUSH=false
DEPLOY=false
NEXT_PHASE=CORREO-SMTP-QA-OVERRIDE-IMPLEMENTACION-1
```

La siguiente fase no queda iniciada por este documento.
