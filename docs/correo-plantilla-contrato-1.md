# CORREO-PLANTILLA-CONTRATO-1

## Estado, versión y alcance

```text
MAIL_TEMPLATE_CONTRACT_VERSION=1
LANGUAGE=es
HTML_BODY_REQUIRED=true
TEXT_BODY_REQUIRED=true
REAL_SMTP_TEST=false
NEXT_VISUAL_PHASE=CORREO-DISENO-VISUAL-1
PREVIEW_PHASE=CORREO-PREVIEW-LOCAL-1
```

Este documento define el contenido semántico futuro de los correos del módulo
Tickets de Solicitud de Alta de Productos. No define todavía paleta, layout,
CSS, assets, mockups ni implementación de plantillas.

El contrato cubre exclusivamente:

- `TICKET_CREADO`;
- `PARTIDA_APROBADA`;
- `PARTIDA_RECHAZADA`;
- `TICKET_RESUELTO_TOTAL`;
- `TICKET_RESUELTO_PARCIAL`;
- `TICKET_CANCELADO`.

`COMENTARIO_AGREGADO` y `ADJUNTO_CARGADO` no generan correo. No se crean
eventos nuevos.

## Auditoría del runtime actual

### Flujo existente

El flujo actual es:

```text
ProductRequestTicketService
  -> ProductTicketEmailNotificationService
  -> MailConfigurationService
  -> ProductTicketEmailOutboxService
  -> tickets_productos_correos
  -> MailOutboxProcessor
  -> MailTransport
```

La operación del ticket confirma primero su transacción. Un fallo posterior de
configuración o encolado no revierte la operación principal.

### Eventos y plantillas existentes

| Evento | Plantilla actual |
|---|---|
| `TICKET_CREADO` | `ticket_created` |
| `PARTIDA_APROBADA` | `line_approved` |
| `PARTIDA_RECHAZADA` | `line_rejected` |
| `TICKET_RESUELTO_TOTAL` | `ticket_resolved` |
| `TICKET_RESUELTO_PARCIAL` | `ticket_resolved` |
| `TICKET_CANCELADO` | `ticket_cancelled` |

### Contexto disponible hoy para componer

El resolver de ticket entrega actualmente:

- ID interno del ticket;
- folio;
- estado del ticket;
- nombre de empresa;
- nombre y código de almacén;
- ID, username y email del solicitante.

Para eventos de partida entrega:

- ID interno de partida;
- ID interno del ticket;
- número de partida;
- estado de partida;
- descripción solicitada;
- motivo de rechazo;
- comentario de resolución.

El esquema contiene además datos reales que el resolver actual no selecciona:

- ticket: observaciones generales, contadores de partidas, fecha de creación,
  cancelación, motivo y actor de cancelación;
- partida: unidad SAT solicitada, fecha de resolución, clave y descripción
  autorizadas, unidad SAT autorizada y clave SAT autorizada.

Su uso futuro exige ampliar y probar el resolver. Este contrato no asume que ya
estén disponibles en el payload actual.

No existen en el ticket o partida actuales:

- asunto separado;
- prioridad;
- cantidad solicitada;
- un campo `id_producto` ligado directamente a la partida.

`clave_autorizada` existe, pero no se renombra aquí como `id_producto`.

### Cuerpos actuales

El runtime actual crea y persiste:

- `subject`;
- `html`;
- `text`.

El cuerpo actual incluye folio, código de evento, empresa, almacén,
solicitante, estado, número de partida cuando aplica, un resumen y un enlace
relativo. El resumen de ticket sin partida es una frase fija; no usa todavía
observaciones generales ni contadores. El HTML actual es funcional y escapado,
pero no constituye el diseño visual futuro.

`MailOutboxProcessor` envía los tres valores almacenados sin regenerar la
plantilla y sin adjuntar archivos.

### Asuntos actuales

| Evento | Asunto actual |
|---|---|
| `TICKET_CREADO` | `Solicitud de alta de producto {folio} recibida` |
| `PARTIDA_APROBADA` | `Partida aprobada en solicitud {folio}` |
| `PARTIDA_RECHAZADA` | `Partida rechazada en solicitud {folio}` |
| `TICKET_RESUELTO_TOTAL` | `Solicitud de alta de producto {folio} resuelta` |
| `TICKET_RESUELTO_PARCIAL` | `Solicitud de alta de producto {folio} resuelta parcialmente` |
| `TICKET_CANCELADO` | `Solicitud de alta de producto {folio} cancelada` |

### Destinatarios actuales

Las reglas se resuelven internamente; nunca desde parámetros públicos:

- TO configurado en la regla;
- solicitante agregado a TO si `enviar_solicitante=1`;
- CC configurado;
- email del actor agregado a CC si `enviar_responsables=1`;
- BCC configurado, si existe;
- si no hay TO, el primer CC o BCC válido puede promoverse a TO;
- deduplicación con precedencia TO, CC y BCC.

`destinatario_email` conserva el TO principal y `cc_json` almacena el sobre
normalizado `{to, cc, bcc}`. Este contrato no cambia recipients ni inventa BCC.

### Remitente y no-reply

La cuenta real proviene de `mail_accounts` y permite `from_email`, `from_name`
y `reply_to_email`. No existe una cuenta `no-reply` hardcodeada ni puede
afirmarse por código que el buzón real no reciba respuestas. La frase
"no responder" solo se usará si la configuración y política operativa lo
confirman; de lo contrario el footer se limitará a indicar que es un mensaje
automático.

## Estructura semántica común

Todos los eventos seguirán este orden de contenido:

1. encabezado corporativo conceptual;
2. título humano del evento;
3. resumen principal de una frase;
4. bloque de ticket;
5. bloque de partida/producto cuando aplica;
6. bloque de resultado o estado;
7. acción informativa `Ver ticket`, cuando la URL sea válida;
8. nota informativa;
9. footer corporativo conceptual.

La ausencia de un bloque opcional no cambia el orden de los restantes.

## Catálogo de campos

### Ticket

| Campo | Fuente real | Uso contractual |
|---|---|---|
| Folio | `tickets_productos.folio` | Obligatorio en los seis eventos y en el asunto. |
| Estado | `tickets_productos.estado` | Obligatorio como etiqueta humana. |
| Fecha de creación | `tickets_productos.created_at` | Obligatoria en `TICKET_CREADO`; requiere ampliar el resolver actual. |
| Solicitante | usuario relacionado | Obligatorio en `TICKET_CREADO`; usar nombre visible cuando esté disponible y username como fallback seguro. |
| Empresa | relación `empresas` | Obligatoria en todos los eventos. |
| Almacén | relación `almacenes` | Obligatorio en todos los eventos. |
| Observaciones generales | `observaciones_generales` | Opcional como resumen de solicitud, escapado y con límite de presentación. |
| Total de partidas | `total_partidas` | Obligatorio en creación y resoluciones; requiere ampliar el resolver. |
| Partidas aprobadas | `partidas_aprobadas` | Obligatorio en resoluciones; requiere ampliar el resolver. |
| Partidas rechazadas | `partidas_rechazadas` | Obligatorio en resoluciones; requiere ampliar el resolver. |
| Fecha de cancelación | `cancelado_at` | Obligatoria en cancelación; requiere ampliar el resolver. |
| Motivo de cancelación | `motivo_cancelacion` | Opcional en body, saneado; nunca en asunto. |
| Actor de cancelación | `cancelado_por_usuario_id` | Opcional solo si se resuelve un nombre permitido; no mostrar el ID. |
| Fecha de resolución global | no existe como campo de ticket | No mostrar como dato autónomo. Su posible derivación desde partidas queda pendiente de implementación. |
| Asunto/motivo separado | no existe | `N/A`. |
| Prioridad | no existe | `N/A`. |

### Partida y producto solicitado

| Campo | Fuente real | Uso contractual |
|---|---|---|
| Número de partida | `numero_partida` | Obligatorio en eventos de partida. |
| Estado de partida | `estado` | Obligatorio en eventos de partida. |
| Descripción solicitada | `descripcion` | Obligatoria en eventos de partida. |
| Comentario de resolución | `comentario_resolucion` | Opcional, escapado. |
| Motivo de rechazo | `motivo_rechazo` | Obligatorio en `PARTIDA_RECHAZADA`; escapado. |
| Fecha de resolución | `resuelto_at` | Opcional; requiere ampliar el resolver actual. |
| Clave autorizada | `clave_autorizada` | Opcional en aprobación; no presentarla como `id_producto`. |
| Descripción autorizada | `descripcion_autorizada` | Opcional en aprobación; requiere ampliar el resolver. |
| Unidad SAT solicitada | `unidad_sat_id` y catálogo | Opcional si se resuelve a clave/nombre; no mostrar ID interno. |
| Unidad SAT autorizada | `unidad_sat_id_autorizada` y catálogo | Opcional en aprobación si se resuelve a clave/nombre. |
| Cantidad | no existe | `N/A`; no inventar `1`. |
| `id_producto` | no existe en partida | `N/A`. |
| Precio/costo | existe `costo_sugerido`, pero queda excluido | No mostrar en plantillas v1. |
| Inventario | no forma parte del ticket | Prohibido. |

La plantilla v1 prioriza número, descripción y unidad cuando exista. No incluye
modelo, marca, proveedor, peso, serie, claves SAT, precio o inventario salvo una
fase contractual posterior con justificación funcional.

## Asuntos finales del contrato v1

| Evento | Asunto final |
|---|---|
| `TICKET_CREADO` | `[R-ERP] Ticket {folio} creado` |
| `PARTIDA_APROBADA` | `[R-ERP] Ticket {folio}: partida {partida_numero} aprobada` |
| `PARTIDA_RECHAZADA` | `[R-ERP] Ticket {folio}: partida {partida_numero} rechazada` |
| `TICKET_RESUELTO_TOTAL` | `[R-ERP] Ticket {folio} resuelto` |
| `TICKET_RESUELTO_PARCIAL` | `[R-ERP] Ticket {folio} resuelto parcialmente` |
| `TICKET_CANCELADO` | `[R-ERP] Ticket {folio} cancelado` |

Los asuntos son UTF-8, cortos y reconocibles. No incluyen solicitante,
empresa, descripción, motivo, email, IDs internos ni datos sensibles.

## Títulos y resúmenes principales

| Evento | Título visible | Resumen principal contractual |
|---|---|---|
| `TICKET_CREADO` | Ticket creado | `El ticket {folio} fue recibido y se encuentra en revisión.` |
| `PARTIDA_APROBADA` | Partida aprobada | `La partida {partida_numero} del ticket {folio} fue aprobada.` |
| `PARTIDA_RECHAZADA` | Partida rechazada | `La partida {partida_numero} del ticket {folio} fue rechazada.` |
| `TICKET_RESUELTO_TOTAL` | Ticket resuelto | `El ticket {folio} concluyó la revisión de todas sus partidas.` |
| `TICKET_RESUELTO_PARCIAL` | Ticket resuelto parcialmente | `El ticket {folio} concluyó con partidas aprobadas y rechazadas.` |
| `TICKET_CANCELADO` | Ticket cancelado | `El ticket {folio} fue cancelado y ya no continuará su flujo.` |

El resultado final concreto se expresa además con la etiqueta humana del
estado real; no se deduce únicamente del nombre del evento.

## Contrato por evento

### TICKET_CREADO

Contenido:

- folio, fecha de creación y estado inicial `En revisión`;
- solicitante;
- empresa y almacén;
- total de partidas;
- observaciones generales como resumen opcional;
- CTA informativa al ticket.

No lista todas las partidas en v1. No incluye precio, inventario o adjuntos.

### PARTIDA_APROBADA

Contenido:

- folio y estado general del ticket;
- número de partida;
- descripción solicitada;
- estado `Aprobada`;
- comentario de resolución opcional;
- clave, descripción y unidad autorizadas solo cuando existan y el resolver las
  exponga correctamente;
- CTA informativa al ticket.

No existe cantidad real que mostrar. No se presenta `clave_autorizada` como
`id_producto`.

### PARTIDA_RECHAZADA

Contenido:

- folio y estado general del ticket;
- número de partida;
- descripción solicitada;
- estado `Rechazada`;
- motivo de rechazo obligatorio, saneado y escapado;
- comentario de resolución opcional cuando aporte información distinta;
- CTA informativa al ticket.

### TICKET_RESUELTO_TOTAL

Contenido:

- folio y estado final real (`Aprobado` o `Rechazado`);
- empresa y almacén;
- total de partidas;
- contadores de aprobadas y rechazadas;
- resultado total expresado en lenguaje humano;
- CTA informativa al ticket.

El esquema no tiene fecha global de resolución. No se inventa. Su derivación
desde `resuelto_at` de partidas queda pendiente de implementación y prueba.

### TICKET_RESUELTO_PARCIAL

Contenido:

- folio y estado `Resuelto parcialmente`;
- empresa y almacén;
- total de partidas;
- partidas aprobadas y rechazadas;
- resultado parcial expresado sin ambigüedad;
- CTA informativa al ticket.

No se incluye fecha global de resolución porque no existe como campo de ticket.

### TICKET_CANCELADO

Contenido:

- folio;
- fecha de cancelación;
- estado `Cancelado`;
- empresa y almacén;
- motivo de cancelación opcional, saneado y escapado;
- actor opcional únicamente como nombre permitido y si el resolver lo obtiene;
- CTA informativa al ticket.

Nunca se muestra `cancelado_por_usuario_id`.

## Etiquetas humanas de estado

### Ticket

| Estado persistido | Etiqueta visible |
|---|---|
| `EN_REVISION` | En revisión |
| `RESUELTO_PARCIAL` | Resuelto parcialmente |
| `APROBADO` | Aprobado |
| `RECHAZADO` | Rechazado |
| `CANCELADO` | Cancelado |

### Partida

| Estado persistido | Etiqueta visible |
|---|---|
| `EN_REVISION` | En revisión |
| `APROBADA` | Aprobada |
| `RECHAZADA` | Rechazada |

Evento y estado son conceptos diferentes. Por ejemplo,
`PARTIDA_APROBADA` puede coexistir con un ticket todavía `EN_REVISION`. La
plantilla muestra el título del evento y, por separado, el estado real del
ticket.

## Matriz de contenido

Leyenda: `REQUIRED`, `OPTIONAL`, `N/A`.

| Campo | TICKET_CREADO | PARTIDA_APROBADA | PARTIDA_RECHAZADA | RESUELTO_TOTAL | RESUELTO_PARCIAL | CANCELADO |
|---|---|---|---|---|---|---|
| Folio | REQUIRED | REQUIRED | REQUIRED | REQUIRED | REQUIRED | REQUIRED |
| Estado del ticket | REQUIRED | REQUIRED | REQUIRED | REQUIRED | REQUIRED | REQUIRED |
| Fecha de creación | REQUIRED | N/A | N/A | N/A | N/A | N/A |
| Solicitante | REQUIRED | OPTIONAL | OPTIONAL | OPTIONAL | OPTIONAL | OPTIONAL |
| Empresa | REQUIRED | REQUIRED | REQUIRED | REQUIRED | REQUIRED | REQUIRED |
| Almacén | REQUIRED | REQUIRED | REQUIRED | REQUIRED | REQUIRED | REQUIRED |
| Observaciones generales | OPTIONAL | N/A | N/A | N/A | N/A | N/A |
| Total de partidas | REQUIRED | OPTIONAL | OPTIONAL | REQUIRED | REQUIRED | OPTIONAL |
| Partidas aprobadas | N/A | N/A | N/A | REQUIRED | REQUIRED | N/A |
| Partidas rechazadas | N/A | N/A | N/A | REQUIRED | REQUIRED | N/A |
| Número de partida | N/A | REQUIRED | REQUIRED | N/A | N/A | N/A |
| Estado de partida | N/A | REQUIRED | REQUIRED | N/A | N/A | N/A |
| Descripción solicitada | N/A | REQUIRED | REQUIRED | N/A | N/A | N/A |
| Clave autorizada | N/A | OPTIONAL | N/A | N/A | N/A | N/A |
| Descripción autorizada | N/A | OPTIONAL | N/A | N/A | N/A | N/A |
| Unidad solicitada/autorizada | N/A | OPTIONAL | OPTIONAL | N/A | N/A | N/A |
| Cantidad | N/A | N/A | N/A | N/A | N/A | N/A |
| Motivo de rechazo | N/A | N/A | REQUIRED | N/A | N/A | N/A |
| Comentario de resolución | N/A | OPTIONAL | OPTIONAL | N/A | N/A | N/A |
| Fecha de cancelación | N/A | N/A | N/A | N/A | N/A | REQUIRED |
| Motivo de cancelación | N/A | N/A | N/A | N/A | N/A | OPTIONAL |
| Actor de cancelación | N/A | N/A | N/A | N/A | N/A | OPTIONAL |
| CTA Ver ticket | REQUIRED* | REQUIRED* | REQUIRED* | REQUIRED* | REQUIRED* | REQUIRED* |

`REQUIRED*` significa contenido contractual obligatorio, condicionado a que la
implementación valide `APP_URL`, la ruta y los permisos descritos abajo.

## Matriz de asuntos

| Evento | Incluye folio | Incluye evento/resultado | Datos sensibles | Longitud esperada |
|---|---|---|---|---|
| `TICKET_CREADO` | Sí | Creado | No | Corta |
| `PARTIDA_APROBADA` | Sí | Partida aprobada | No | Corta; solo número de partida |
| `PARTIDA_RECHAZADA` | Sí | Partida rechazada | No | Corta; solo número de partida |
| `TICKET_RESUELTO_TOTAL` | Sí | Resuelto | No | Corta |
| `TICKET_RESUELTO_PARCIAL` | Sí | Resuelto parcialmente | No | Corta |
| `TICKET_CANCELADO` | Sí | Cancelado | No | Corta |

## Links y CTA

La ruta GET privada `/tickets/productos/{id}` existe. El CTA contractual es
`Ver ticket` para los seis eventos y solo navega; nunca aprueba, rechaza,
cancela o ejecuta otra mutación.

La URL absoluta futura se construirá como:

```text
APP_URL + /tickets/productos/{id}
```

Reglas:

- no hardcodear dominio ni localhost;
- validar `APP_URL` como origen aprobado y normalizar barras;
- no construir origen o path desde datos libres del usuario;
- no incluir session ID, CSRF, tokens, IDs de autorización o secretos;
- no incluir links directos a archivos o adjuntos;
- la aplicación vuelve a validar autenticación, permiso y alcance al abrir;
- solo GET de navegación, sin side effects.

El runtime actual usa un path relativo. La generación absoluta y su prueba
quedan `PENDING_IMPLEMENTATION_VALIDATION`.

### Matriz CTA

| Evento | CTA | Estado |
|---|---|---|
| `TICKET_CREADO` | Ver ticket | `PENDING_IMPLEMENTATION_VALIDATION` |
| `PARTIDA_APROBADA` | Ver ticket | `PENDING_IMPLEMENTATION_VALIDATION` |
| `PARTIDA_RECHAZADA` | Ver ticket | `PENDING_IMPLEMENTATION_VALIDATION` |
| `TICKET_RESUELTO_TOTAL` | Ver ticket | `PENDING_IMPLEMENTATION_VALIDATION` |
| `TICKET_RESUELTO_PARCIAL` | Ver ticket | `PENDING_IMPLEMENTATION_VALIDATION` |
| `TICKET_CANCELADO` | Ver ticket | `PENDING_IMPLEMENTATION_VALIDATION` |

## Privacidad y datos prohibidos

No se incluyen en asunto, HTML o texto plano:

- password, password hash, secretos o secret refs;
- configuración SMTP, usuario SMTP o respuestas crudas del proveedor;
- host, usuario, contraseña, nombre o DSN de base de datos;
- session ID, cookie, CSRF, token o headers de autenticación;
- stack trace, excepciones o rutas físicas;
- paths de `storage/private` o `storage/uploads`;
- `error_mensaje_seguro` del outbox;
- outbox ID, `dedupe_key`, intentos, `max_intentos`, timestamps técnicos o
  estado del processor;
- metadata de auditoría;
- emails de BCC o lista de destinatarios dentro del body;
- IDs internos de usuario, empresa, almacén o autorización;
- precios, costos o inventario;
- contenido o links directos de adjuntos.

La dirección TO/CC/BCC forma parte del sobre, no del contenido visible.

## HTML y texto plano

Cada evento tendrá dos cuerpos obligatorios con la misma información esencial:

- `text/html` compatible con clientes de correo;
- `text/plain` legible sin markup.

El HTML futuro:

- no depende de CSS, JavaScript, fuentes o imágenes externas para entenderse;
- no ejecuta PHP ni JavaScript;
- puede usar tablas y CSS inline en la fase visual si la compatibilidad lo
  requiere;
- mantiene orden de lectura semántico y links visibles;
- no convierte texto de usuario en HTML.

El texto plano:

- mantiene título, resumen, campos esenciales, URL y nota automática;
- usa saltos y etiquetas consistentes;
- no contiene tags, entidades sin decodificar ni una versión reducida que
  omita el resultado principal.

Clientes objetivo de la implementación futura: Gmail, Outlook desktop,
Outlook web, Apple Mail y clientes móviles. La investigación de CSS pertenece
a `CORREO-DISENO-VISUAL-1`.

## Escapado, normalización y fallback

- Todo dato dinámico se escapa por contexto HTML con UTF-8 y sustitución segura.
- La versión texto normaliza controles y saltos; no interpreta HTML.
- Nombres, observaciones, descripciones, comentarios y motivos se tratan como
  texto plano no confiable.
- Los valores opcionales vacíos omiten su fila o bloque completo.
- No se muestran `null`, `undefined`, `N/A` o `-` como fallback automático.
- Folio, estados y números se validan antes de componer.
- Se soportan UTF-8, acentos, `ñ` y símbolos habituales.
- Datos de varias líneas conservan lectura clara sin permitir markup.

### Longitudes de presentación

La implementación deberá fijar y probar límites de presentación para
observaciones, descripciones, comentarios y motivos. El asunto no se trunca de
forma que pierda el folio o resultado. En body podrá presentarse un extracto
seguro con indicación de consulta en el ERP; el dato completo permanece en el
sistema. Este documento no implementa ni fija todavía el algoritmo de truncado.

## Idioma y tono

Idioma inicial: español. La internacionalización queda fuera de alcance.

Tono:

- profesional;
- claro;
- neutral;
- operativo;
- sin exclamaciones innecesarias;
- sin jerga técnica o de desarrollo;
- sin culpar al usuario ni prometer acciones no ejecutadas.

## Branding y footer conceptuales

Placeholders conceptuales para la fase visual:

- logo aprobado de la empresa;
- nombre de empresa;
- nombre `R-ERP`;
- color principal aprobado.

No se selecciona asset, paleta ni layout en esta fase. El correo debe seguir
siendo comprensible si logo o color no están disponibles.

Footer futuro:

- nombre de empresa, solo desde configuración aprobada;
- indicación de mensaje automático;
- instrucción de no responder únicamente si la cuenta/política lo confirma;
- información de soporte solo si existe una fuente configurada y aprobada.

Texto conceptual sujeto a revisión de copy:

```text
Este es un mensaje automático generado por el sistema.
```

No se inventan teléfono, email de soporte o dirección física.

## Acciones excluidas

Los correos son informativos. No incluyen:

- aprobar por correo;
- rechazar por correo;
- cancelar por correo;
- responder con comandos;
- mutaciones por GET;
- magic links o tokens firmados;
- tracking de apertura o clic;
- adjuntos.

## Preview futuro

`CORREO-PREVIEW-LOCAL-1` deberá renderizar los seis eventos con datos sintéticos
sin insertar outbox, resolver secretos, conectar SMTP o enviar correo. Debe
permitir comparar HTML y texto plano, tamaños de contenido, escaping, UTF-8 y
fallbacks.

## Fase visual futura

```text
NEXT_VISUAL_PHASE=CORREO-DISENO-VISUAL-1
```

Esa fase decidirá paleta, encabezado, logo, contenedores, presentación de
estados, CTA, footer, responsive y mockups. No podrá cambiar eventos, datos
permitidos, privacidad o recipients sin una nueva decisión contractual.

## Plan de pruebas de implementación

1. asunto exacto por evento;
2. folio presente en asunto y body;
3. todos los campos `REQUIRED` presentes;
4. opcionales vacíos omitidos sin placeholders;
5. HTML escapado;
6. texto plano equivalente;
7. UTF-8 válido;
8. acentos, `ñ` y símbolos habituales;
9. motivo con payload XSS mostrado como texto;
10. descripción larga con política de presentación;
11. URL basada en `APP_URL`;
12. URL sin token, sesión o CSRF;
13. CTA GET hacia la ruta existente;
14. metadata interna de outbox ausente;
15. secretos y datos técnicos ausentes;
16. IDs internos no visibles;
17. seis eventos cubiertos;
18. ningún correo para comentarios;
19. ningún correo para adjuntos;
20. preview local sin SMTP;
21. diferencia entre evento y estado conservada;
22. cantidad e `id_producto` no inventados;
23. fecha global de resolución no inventada;
24. motivo de rechazo obligatorio en rechazo;
25. contadores coherentes en resolución;
26. BCC nunca expuesto en body, HTML o texto;
27. HTML comprensible sin CSS externo o imágenes;
28. fallo de dato opcional no rompe composición;
29. subjects dentro del límite del outbox;
30. `no responder` condicionado a la cuenta real.

Las pruebas usarán datos sintéticos o fixtures con rollback. No enviarán SMTP.

## Datos protegidos y fuera de alcance

No se modifica base de datos. Permanecen protegidos por contrato:

```text
ticket 34 / QASMTP-000001
outbox 1 / CANCELADO
outbox 36 / ENVIADO
```

No se implementan HTML, CSS, preview, servicio de plantillas, cambios de
repositorio, outbox, eventos, recipients, rutas, vistas, permisos, migraciones,
seeds, SMTP, DB, push o deploy.

## Resultado contractual

```text
MAIL_TEMPLATE_CONTRACT_VERSION=1
EVENTS_COVERED=6
HTML_BODY_REQUIRED=true
TEXT_BODY_REQUIRED=true
CTA_ACTION=GET_NAVIGATION_ONLY
APP_URL_REQUIRED=true
INTERNAL_METADATA_EXCLUDED=true
REAL_SMTP_TEST=false
NEXT_VISUAL_PHASE=CORREO-DISENO-VISUAL-1
PREVIEW_PHASE=CORREO-PREVIEW-LOCAL-1
```
