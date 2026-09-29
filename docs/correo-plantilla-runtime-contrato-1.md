# CORREO-PLANTILLA-RUNTIME-CONTRATO-1

## Estado y alcance

```ini
MAIL_TEMPLATE_CONTRACT_VERSION=1
MAIL_RUNTIME_TEMPLATE_VERSION=1
LANGUAGE=es
EVENTS_SUPPORTED=6
HTML_BODY_REQUIRED=true
TEXT_BODY_REQUIRED=true
HTML_TEXT_PARITY_REQUIRED=true
PREVIEW_RUNTIME_VISUAL_PARITY_REQUIRED=true
VISUAL_STYLE=corporate_erp_refrigeration
MULTI_ITEM_LONG_EMAIL_POLICY=render_first_10_then_cta
MAX_ITEMS_RENDERED=10
TOTAL_ITEMS_REQUIRED_FOR_TRUNCATION=true
REAL_SMTP_TEST=false
DATABASE_USED=false
RUNTIME_IMPLEMENTED=false
```

Este documento define la arquitectura contractual de la futura plantilla
runtime reusable para correos de tickets de producto. No implementa PHP,
integra previews, modifica servicios, consulta base de datos ni conecta SMTP.

Los únicos eventos soportados son:

1. `TICKET_CREADO`;
2. `PARTIDA_APROBADA`;
3. `PARTIDA_RECHAZADA`;
4. `TICKET_RESUELTO_TOTAL`;
5. `TICKET_RESUELTO_PARCIAL`;
6. `TICKET_CANCELADO`.

Comentarios y adjuntos no son eventos. No se agregan fallbacks genéricos ni
eventos implícitos.

## Auditoría del runtime actual

El flujo actual es:

```text
ProductRequestTicketService
  -> ProductTicketEmailNotificationService
  -> MailConfigurationService
  -> ProductTicketEmailOutboxService
  -> ProductTicketEmailOutboxRepository
  -> tickets_productos_correos
  -> MailOutboxProcessor
  -> MailTransport
```

`ProductTicketEmailNotificationService` valida la configuración del evento y
delega el encolado. No compone actualmente el subject ni los cuerpos.

`ProductTicketEmailOutboxService` concentra hoy responsabilidades que deberán
separarse posteriormente:

- carga ticket y partida mediante repository;
- resuelve TO, CC y BCC;
- aplica dedupe;
- selecciona evento y plantilla;
- construye subject, HTML y texto en `payload()`;
- inserta la fila pendiente.

El renderer futuro sustituirá exclusivamente la selección y composición que
hoy reside en `payload()` y `TEMPLATE_SUBJECTS`. La resolución de destinatarios,
dedupe, persistencia y estado de outbox permanecerán fuera del renderer.

### Datos disponibles hoy

El resolver actual de ticket entrega:

- `id`;
- `folio`;
- `estado`;
- `empresa_nombre`;
- `almacen_nombre` y `almacen_codigo`;
- `solicitante_id`, `solicitante_username` y `solicitante_email`.

El resolver actual de partida entrega:

- `id` y `ticket_producto_id`;
- `numero_partida`;
- `estado`;
- `descripcion`;
- `motivo_rechazo`;
- `comentario_resolucion`.

Fecha de creación/cancelación, contadores, observaciones generales y datos SAT
o autorizados existen en el esquema o en relaciones, pero el resolver actual
no los expone. Su incorporación exige una fase de implementación con pruebas.
No existen cantidad ni `id_producto` directo en la partida y no se inventan.

## Componente futuro

Nombre conceptual:

```text
ProductTicketEmailTemplateRenderer
```

Responsabilidad única:

```text
evento + payload normalizado -> RenderedEmail
```

El renderer:

- valida evento y payload;
- construye un view model común;
- genera subject;
- genera un documento HTML completo;
- genera text/plain explícito con paridad semántica;
- devuelve un resultado inmutable conceptual.

El renderer no:

- consulta DB o repositories;
- decide TO, CC o BCC;
- conoce PHPMailer, SMTP o secretos;
- crea, consulta o actualiza outbox;
- calcula dedupe;
- modifica ticket o partida;
- autoriza acciones ni consulta sesión/request;
- lee `$_POST`, `.env` o configuración global mutable.

## Contrato de entrada

El método conceptual es:

```text
render(event, ProductTicketEmailTemplatePayload) -> RenderedEmail
```

El payload es una estructura explícita, normalizada antes de entrar al
renderer. No es un PDO row, entidad de repository, `Request`, `$_POST` ni un
array arbitrario. Sus valores textuales son raw normalizados: nunca llegan
preescapados ni contienen HTML confiable.

### Estructura superior

```text
event
ticket
recipient_context
items[]
attachments_count
total_items
note
cta
branding
render_context
```

| Campo | Clasificación | Regla |
|---|---|---|
| `event` | `REQUIRED` | Uno de los seis eventos exactos. |
| `ticket` | `REQUIRED` | Datos normalizados del ticket. |
| `recipient_context` | `REQUIRED` | Solo nombre visible para saludo; nunca emails. |
| `items` | `REQUIRED` | Lista, vacía solo cuando el evento lo permite. |
| `attachments_count` | `REQUIRED` | Entero mayor o igual a cero. |
| `total_items` | `REQUIRED` | Total real calculado por capa superior. |
| `note` | `OPTIONAL` | Texto raw normalizado; `null` si no existe. |
| `cta` | `REQUIRED` | Label contractual y URL absoluta validada. |
| `branding` | `REQUIRED` | Branding permitido, no controlado por usuario. |
| `render_context` | `REQUIRED` | Locale y timezone explícitos. |

## Ticket normalizado

| Campo | Clasificación | Fuente/decisión |
|---|---|---|
| `ticket_id` | `REQUIRED` | ID positivo; se usa para correlación/CTA, no se imprime como dato. |
| `folio` | `REQUIRED` | Fuente real; requerido en subject, HTML y texto. |
| `estado` | `REQUIRED` | Estado persistido permitido. |
| `estado_label` | `DERIVED` | Mapeo contractual, no texto libre del caller. |
| `created_at` | `REQUIRED_BY_EVENT` | Requerido para `TICKET_CREADO`; exige ampliar el resolver. |
| `cancelled_at` | `REQUIRED_BY_EVENT` | Requerido para `TICKET_CANCELADO`; exige ampliar el resolver. |
| `empresa_nombre` | `REQUIRED` | Texto raw normalizado y escapado. |
| `almacen_nombre` | `REQUIRED` | Nombre; código como fallback normalizado. |
| `observaciones_generales` | `OPTIONAL` | Solo para creación; texto raw. |
| `partidas_aprobadas` | `REQUIRED_BY_EVENT` | Requerido en resoluciones; exige ampliar el resolver. |
| `partidas_rechazadas` | `REQUIRED_BY_EVENT` | Requerido en resoluciones; exige ampliar el resolver. |
| `motivo_cancelacion` | `OPTIONAL` | Texto raw, nunca subject. |
| `cancelado_por_nombre` | `OPTIONAL` | Solo nombre permitido; nunca ID interno. |
| `priority` | `NOT_ALLOWED` | No existe. |
| `subject` | `NOT_ALLOWED` | El renderer lo construye. |
| `recipient_email` | `NOT_ALLOWED` | Pertenece a reglas de destinatarios. |

Mientras el resolver no entregue fechas o contadores, la implementación no
puede inventarlos. La fase de implementación debe ampliar el resolver o
rechazar el payload del evento que contractualmente los requiera.

## Contexto de saludo

```text
recipient_context.display_name
```

`display_name` es texto normalizado. La capa superior usa nombre visible si
existe y `solicitante_username` como fallback seguro. El renderer no recibe ni
infiera TO/CC/BCC y un mismo render puede reutilizarse para todos los
destinatarios.

## Partida normalizada

```text
items[] = {
  partida_numero,
  reference,
  description,
  item_status,
  item_status_label,
  item_tone,
  unidad_sat_label,
  clave_sat_label,
  response,
  reason
}
```

| Campo | Clasificación | Regla |
|---|---|---|
| `partida_numero` | `REQUIRED` | Positivo; obligatorio en eventos de partida. |
| `reference` | `OPTIONAL` | Clave autorizada u otra referencia contractual, nunca `id_producto` inventado. |
| `description` | `REQUIRED` | Descripción solicitada raw normalizada. |
| `item_status` | `REQUIRED` | Estado persistido permitido. |
| `item_status_label` | `DERIVED` | Etiqueta controlada por renderer. |
| `item_tone` | `DERIVED` | `registered`, `approved` o `rejected`. |
| `unidad_sat_label` | `OPTIONAL` | Clave/nombre resueltos; nunca ID interno. |
| `clave_sat_label` | `OPTIONAL` | Dato autorizado resuelto cuando exista. |
| `response` | `OPTIONAL` | Comentario de resolución raw. |
| `reason` | `OPTIONAL` condicionado | Obligatorio en `PARTIDA_RECHAZADA`. |
| `id_producto` | `NOT_ALLOWED` | No existe directamente en la partida. |
| `cantidad` | `NOT_ALLOWED` | No existe. |
| `style`, `class` | `NOT_ALLOWED` | El caller no controla presentación. |
| `raw_html`, `html_note`, `html_description` | `NOT_ALLOWED` | Todos los valores son texto. |

El payload builder conserva el orden funcional de origen. El renderer no
reordena ni deduplica partidas.

## Contrato de salida

Resultado conceptual inmutable:

```text
RenderedEmail {
  subject: string,
  html_body: string,
  text_body: string
}
```

Los tres campos son obligatorios, UTF-8 y no vacíos. Es inválido devolver:

- subject vacío;
- HTML vacío;
- text/plain vacío;
- HTML sin cuerpo textual equivalente;
- un resultado parcial después de una validación fallida.

La adaptación al esquema actual de outbox será explícita:

```text
html_body -> tickets_productos_correos.html
text_body -> tickets_productos_correos.text
subject   -> tickets_productos_correos.subject
```

## Mapeo de eventos y asuntos

| Evento | Método conceptual de contenido | Subject exacto |
|---|---|---|
| `TICKET_CREADO` | `buildTicketCreatedModel()` | `[R-ERP] Ticket {folio} creado` |
| `PARTIDA_APROBADA` | `buildItemApprovedModel()` | `[R-ERP] Ticket {folio}: partida {partida_numero} aprobada` |
| `PARTIDA_RECHAZADA` | `buildItemRejectedModel()` | `[R-ERP] Ticket {folio}: partida {partida_numero} rechazada` |
| `TICKET_RESUELTO_TOTAL` | `buildTicketResolvedTotalModel()` | `[R-ERP] Ticket {folio} resuelto` |
| `TICKET_RESUELTO_PARCIAL` | `buildTicketResolvedPartialModel()` | `[R-ERP] Ticket {folio} resuelto parcialmente` |
| `TICKET_CANCELADO` | `buildTicketCancelledModel()` | `[R-ERP] Ticket {folio} cancelado` |

El dispatch futuro será un `match` exhaustivo del enum/lista cerrada. Cada rama
crea contenido semántico; todas comparten view model, layout HTML y renderer de
texto. No se duplican header, CTA ni footer.

### Requisitos por evento

| Evento | Items | Datos específicos obligatorios | Opcionales permitidos |
|---|---|---|---|
| `TICKET_CREADO` | Lista vacía en v1 | `created_at`, solicitante/display name, empresa, almacén, `total_items` | Observaciones, nota, aviso de adjuntos |
| `PARTIDA_APROBADA` | Exactamente una | Número, estado y descripción de partida | Referencia, datos SAT autorizados, response, nota, aviso de adjuntos |
| `PARTIDA_RECHAZADA` | Exactamente una | Número, estado, descripción y reason | Response distinto del reason, nota, aviso de adjuntos |
| `TICKET_RESUELTO_TOTAL` | Una o más, limitadas por política | `total_items`, aprobadas y rechazadas | Nota y aviso de adjuntos |
| `TICKET_RESUELTO_PARCIAL` | Una o más con resultado mixto, limitadas por política | `total_items`, aprobadas y rechazadas | Nota y aviso de adjuntos |
| `TICKET_CANCELADO` | Lista vacía | `cancelled_at`, empresa y almacén | Motivo, actor visible, nota y aviso de adjuntos |

`REQUIRED_BY_EVENT` significa que el dato puede no aplicar a los demás eventos,
pero es obligatorio cuando se renderiza el evento indicado. Si el resolver
actual todavía no lo entrega, la implementación debe ampliar el resolver antes
de habilitar esa variante; no puede degradar el campo a opcional ni inventarlo.

## Seguridad del subject

Folio y número de partida se normalizan antes de interpolarse. Todo valor
dinámico del subject debe:

- eliminar `CR` y `LF`;
- rechazar otros caracteres de control;
- respetar el límite vigente del outbox;
- excluir email, nombre, motivo, descripción e información sensible.

Un intento de inyección CRLF invalida el payload. No se corrige silenciosamente
para continuar el envío.

## View model común

```text
mail:
  event
  title
  subtitle
  folio
  status_label
  status_tone
  greeting
  summary
  metadata[]
  items[]
  attachments_notice?
  note?
  cta?
  footer
```

El mismo view model alimenta HTML y texto. El caller no puede proporcionar
título, subtitle, status tone, CSS, clases, markup o footer arbitrarios.

### Tonos de estado

| Semántica | Uso |
|---|---|
| `review` | Ticket creado/en revisión. |
| `success` | Aprobado o resuelto total. |
| `danger` | Rechazado. |
| `warning` | Resuelto parcialmente. |
| `cancelled` | Cancelación administrativa. |

Para cards se permiten exclusivamente:

- `registered`;
- `approved`;
- `rejected`.

El renderer mapea estos tonos a los tokens visuales aprobados. El dominio no
inyecta colores hexadecimales, CSS o nombres de clase.

## Reglas de bloques condicionales

| Bloque | Regla |
|---|---|
| `items` | Visible si hay items y el evento los permite. |
| `response` | Visible solo cuando el texto normalizado no está vacío. |
| `reason` | Visible solo cuando existe; obligatorio para rechazo. |
| `attachments_notice` | Visible solo cuando `attachments_count > 0`. |
| `note` | Visible solo cuando el texto normalizado no está vacío. |
| `cta` | Visible solo con URL absoluta válida y label contractual. |

No se producen títulos, contenedores o placeholders vacíos. Si
`attachments_count=0`, no se muestra `0 archivos`.

El payload contiene únicamente el count de adjuntos. Quedan prohibidos paths,
URLs privadas, nombres sensibles y links directos.

`TICKET_CANCELADO` permite `items=[]` y usa un resumen administrativo; no se
crea una card ficticia. Los eventos resueltos admiten un array de tamaño
variable y no asumen exactamente dos partidas.

## Política de muchas partidas

Decisión v1:

```ini
MULTI_ITEM_LONG_EMAIL_POLICY=render_first_10_then_cta
MAX_ITEMS_RENDERED=10
TOTAL_ITEMS_REQUIRED_FOR_TRUNCATION=true
```

Reglas:

- si `total_items <= 10`, se renderizan todas las partidas recibidas;
- si `total_items > 10`, se renderizan las primeras 10 conservando orden;
- después se muestra: `Este ticket contiene más partidas. Consulta el ticket completo en R-ERP.`;
- se conserva el total real y el CTA;
- el renderer no inventa `total_items` ni lo calcula a partir de una lista ya truncada;
- el payload builder debe entregar `total_items` real y una lista coherente;
- discrepancias entre count, total y política invalidan el payload.

La política limita tamaño sin ocultar que existen más partidas. El límite de
tamaño total del mensaje se medirá en implementación; no se fija un número de
bytes sin evidencia.

## HTML

`html_body` será un documento HTML completo con:

- `<!doctype html>`;
- `<html lang="es">`;
- charset UTF-8;
- viewport;
- `<body>` completo.

La salida implementará `VISUAL_STYLE=corporate_erp_refrigeration` con alta
fidelidad a los previews aprobados. Los previews son referencia y fixtures
visuales; runtime no los incluye, lee ni parsea.

Estrategia futura:

- layout crítico basado en tablas;
- CSS compatible e inline en salida final;
- sin CSS Grid, JavaScript o assets externos;
- sin dependencia de webfonts, logos o imágenes;
- common layout, header, item card, attachments, note, CTA y footer;
- implementación mediante PHP nativo, métodos privados o partials internos;
- sin Twig, Blade, Mustache, Handlebars ni motor nuevo.

El renderer controla todo el markup. El caller no pasa HTML, styles o clases.

## Text/plain

`text_body` se genera de forma explícita desde el mismo view model. No se usa
`strip_tags(html_body)` como estrategia principal.

Debe incluir, cuando apliquen:

- evento/título humano;
- folio y estado;
- saludo;
- resumen;
- empresa y almacén;
- partidas y resultados;
- motivo, respuesta, nota y aviso de adjuntos;
- URL textual del CTA;
- footer informativo.

Formato conceptual:

```text
R-ERP
Ticket creado
Folio: GU-000014
Estado: En revisión

Hola Rosalba,

...

PARTIDAS
1. ...

Ver ticket:
https://origen-validado/tickets/productos/123
```

La salida conserva UTF-8 y usa `CRLF` (`\r\n`) de forma consistente. Se
normalizan saltos de entrada y caracteres de control; no se inserta HTML y se
evitan líneas generadas absurdamente largas cuando exista un punto natural de
corte.

```ini
HTML_TEXT_PARITY_REQUIRED=true
```

La información esencial debe aparecer en ambos cuerpos. Badges, colores,
cards, bordes y composición visual pueden omitirse en text/plain.

## Escaping y frontera de confianza

Son datos no confiables aunque provengan de DB administrable:

- nombres y username;
- empresa y almacén;
- folio si no ha sido normalizado;
- descripciones y observaciones;
- motivos, notas y respuestas;
- labels resueltos desde catálogos.

El payload conserva texto raw normalizado. El renderer aplica `escapeHtml()`
contextual con política equivalente a `htmlspecialchars` usando quotes,
substitución segura y UTF-8. Nunca confía en strings preescapados.

Para text/plain se eliminan caracteres de control no permitidos, se normalizan
saltos y se conserva contenido Unicode válido. Los datos dinámicos nunca se
interpretan como markup.

## CTA y APP_URL

Decisión de responsabilidad:

1. una capa superior `ProductTicketEmailTemplatePayloadBuilder` recibe la
   configuración ya resuelta;
2. valida el origen de `APP_URL` y combina el `ticket_id` positivo con
   `/tickets/productos/{id}`;
3. entrega al renderer una `cta.url` absoluta y normalizada;
4. el renderer vuelve a validar el esquema y renderiza la URL escapada.

El renderer no lee `.env`, no concatena hosts libres ni conoce `APP_URL`
global. La URL solo puede usar `https`; `http` se permite exclusivamente en
entorno local/test explícito. Debe pertenecer al origen configurado.

CTA v1:

```text
label=Ver ticket
method=GET
```

No contiene tokens, sesión, CSRF, credenciales, magic links, signed actions ni
parámetros que muten estado. Abrirla vuelve a pasar por autenticación, permiso
y alcance del ERP.

## Branding y footer

Branding futuro permitido:

```text
system_name=R-ERP
brand_label=ERP REFRIGERACIÓN
```

Se entrega mediante un objeto/config inmutable de aplicación, no datos del
usuario. No hay logo obligatorio. El footer es común, informativo y no depende
del destinatario. No inventa teléfonos, direcciones o contactos.

El renderer no decide política de reply ni añade `No responder` salvo que una
configuración futura, explícita y aprobada lo autorice.

## Fechas, timezone y locale

Política elegida:

- el payload builder convierte fechas reales a `DateTimeImmutable`;
- el renderer recibe timezone explícita en `render_context.timezone`;
- el renderer aplica un único formato contractual en español;
- no se usa timezone implícita del servidor;
- valores sin timezone o fechas inválidas fallan validación;
- idioma v1 es `es`; no se implementa i18n todavía.

Esto mantiene el dato temporal sin formatear en la frontera y evita display
values ambiguos. La timezone forma parte de la entrada determinista.

## Determinismo

Mismo evento, payload, branding y render context producen exactamente la misma
salida normalizada. El renderer no usa:

- `NOW()` o reloj del sistema;
- random;
- DB;
- sesión o request;
- environment mutable;
- red o filesystem;
- estado estático mutable.

## Validación y excepciones

La implementación futura usará una excepción específica conceptual:

```text
ProductTicketEmailTemplateValidationException
```

El renderer valida todo antes de producir el resultado y falla cerrado. No
retorna un correo incompleto ni usa fallback silencioso.

Validaciones mínimas:

- evento soportado;
- folio no vacío y sin CR/LF;
- estado permitido;
- display name conforme a política de saludo;
- empresa y almacén requeridos;
- `partida_numero` en eventos de partida;
- motivo en rechazo;
- items permitidos/coherentes por evento;
- count y `total_items` no negativos y coherentes;
- URL de CTA válida cuando está activa;
- branding, locale y timezone permitidos;
- ausencia de campos `NOT_ALLOWED` y raw HTML;
- subject y ambos cuerpos no vacíos.

Evento desconocido, payload inválido o dato requerido ausente producen la
excepción específica. El error seguro identifica código de evento o nombre de
campo, pero nunca imprime:

- payload completo;
- PII innecesaria;
- HTML/text completos;
- secretos o configuración;
- paths, DSN o stack trace para usuario final.

## Frontera de integración futura

```text
Ticket domain/service
  -> repository/resolver
  -> ProductTicketEmailTemplatePayloadBuilder
  -> ProductTicketEmailTemplateRenderer
  -> RenderedEmail
  -> ProductTicketEmailOutboxService
  -> outbox repository/DB
  -> MailOutboxProcessor
  -> MailTransport
```

Responsabilidades:

- resolver: obtiene datos reales;
- payload builder: normaliza, calcula total real, resuelve display name,
  configuración y CTA;
- renderer: contenido puro subject/HTML/text;
- outbox service: recipients, dedupe y persistencia;
- processor/transport: entrega.

### Migración desde servicios actuales

Una fase posterior deberá:

1. introducir payload/result/exception y renderer;
2. ampliar el resolver solo para campos contractualmente necesarios;
3. mover `EVENT_TEMPLATES`, `TEMPLATE_SUBJECTS` y `payload()` fuera de
   `ProductTicketEmailOutboxService`;
4. conservar inicialmente la resolución actual de destinatarios y dedupe;
5. adaptar `RenderedEmail` a `subject`, `html` y `text` del outbox;
6. comparar los seis eventos con previews y snapshots;
7. retirar la composición legacy solo después de regresión completa.

`ProductTicketEmailNotificationService` conserva su papel de coordinación de
configuración y no se convierte en renderer. Ninguna migración ocurre en esta
fase documental.

## Fronteras de outbox, eventos y destinatarios

Outbox seguirá recibiendo subject, HTML, text, envelope, evento, plantilla,
dedupe y metadata operativa. No conoce partials ni decisiones visuales.

El código de evento se conserva para selección de contenido, dedupe y
auditoría. El renderer solo lo usa para crear el view model.

El renderer no decide ni recibe TO, CC o BCC. `recipient_context.display_name`
no implica dirección y evita acoplar el render a una lista de destinatarios.

## Paridad con previews

| Evento | Referencia visual |
|---|---|
| `TICKET_CREADO` | `docs/previews/mail/ticket-creado.html` |
| `PARTIDA_APROBADA` | `docs/previews/mail/partida-aprobada.html` |
| `PARTIDA_RECHAZADA` | `docs/previews/mail/partida-rechazada.html` |
| `TICKET_RESUELTO_TOTAL` | `docs/previews/mail/ticket-resuelto-total.html` |
| `TICKET_RESUELTO_PARCIAL` | `docs/previews/mail/ticket-resuelto-parcial.html` |
| `TICKET_CANCELADO` | `docs/previews/mail/ticket-cancelado.html` |

Los mock values no pasan a runtime. La implementación deberá mantener alta
fidelidad estructural y semántica sin incluir o parsear esos archivos.

## Plan de pruebas de implementación

1. seis eventos soportados;
2. subject exacto por evento;
3. HTML no vacío;
4. text/plain no vacío;
5. paridad HTML/text;
6. UTF-8 y acentos;
7. descripción XSS mostrada como texto;
8. reason XSS mostrado como texto;
9. response XSS mostrado como texto;
10. inyección CRLF en folio/subject rechazada;
11. evento inválido falla cerrado;
12. folio ausente rechazado;
13. número de partida ausente rechazado;
14. adjuntos cero ocultan bloque;
15. adjuntos positivos muestran aviso sin links;
16. nota vacía oculta bloque;
17. response vacío oculta bloque;
18. cancelación sin items válida;
19. múltiples items conservan orden;
20. más de diez items aplica truncado y mensaje;
21. `total_items` ausente o incoherente se rechaza;
22. CTA URL válida y textual en ambos cuerpos;
23. CTA sin token, sesión o side effects;
24. APP_URL/origen normalizado por builder;
25. raw HTML y presentation fields rechazados;
26. HTML sin scripts ni handlers;
27. HTML sin referencias externas;
28. salida sin secretos ni metadata interna;
29. salida determinista;
30. text/plain usa CRLF consistente;
31. marcadores y estructura con paridad visual del preview;
32. renderer ejecuta sin DB;
33. renderer ejecuta sin SMTP;
34. renderer no contiene lógica de recipients;
35. evento y estado se mantienen distintos;
36. HTML completo carga sin errores DOM;
37. size sanity con cero, diez y más de diez items;
38. timezone explícita produce fecha contractual;
39. timezone ausente se rechaza cuando hay fecha;
40. errores seguros no contienen payload ni PII.

Se recomiendan assertions semánticas obligatorias y snapshots normalizados
opcionales por evento. Los snapshots no deben fallar solo por whitespace sin
impacto.

## Versionado

```ini
MAIL_TEMPLATE_CONTRACT_VERSION=1
MAIL_RUNTIME_TEMPLATE_VERSION=1
```

Cambios visuales compatibles pueden mantener versión 1. Cambios de campos,
semántica, subject, eventos o requisitos de payload deben evaluar incremento
de versión. No se crea columna ni almacén de versiones en esta fase.

## Seguridad y límites

El contrato prohíbe:

- secretos, credenciales y secret refs;
- DB, PDO o repositories dentro del renderer;
- sesiones, cookies, CSRF y tokens;
- HTML raw o URLs no confiables;
- assets externos, JavaScript y tracking;
- SMTP, PHPMailer o transportes;
- mutaciones desde CTA;
- paths privados, filenames sensibles o links de adjuntos;
- datos de inventario, precio, cantidad o producto no contractuales.

No se accedió a DB y permanecen protegidos por contrato:

```text
ticket 34 / QASMTP-000001
outbox 1 / CANCELADO
outbox 36 / ENVIADO
```

```ini
REAL_SMTP_TEST=false
DATABASE_USED=false
RUNTIME_IMPLEMENTED=false
```

## Criterios para la siguiente fase

La siguiente fase recomendada es:

```text
CORREO-PLANTILLA-RUNTIME-IMPLEMENTACION-1
```

Requerirá autorización independiente. Deberá implementar primero renderer,
payload/result tipados y tests puros, sin conectar SMTP. La integración con
outbox deberá permanecer separada y no se considerará aprobada hasta demostrar
los seis eventos, seguridad, paridad HTML/text, política de muchas partidas y
regresión del flujo existente.
