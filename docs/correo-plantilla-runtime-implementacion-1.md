# CORREO-PLANTILLA-RUNTIME-IMPLEMENTACION-1

## Estado

```ini
PHASE_STATUS=PASS
RUNTIME_RENDERER_IMPLEMENTED=true
PURE_FUNCTIONAL_TESTS=PASS
OUTBOX_INTEGRATION=PASS
DB_FIX_PREREQUISITE_SATISFIED=true
DB_FIX_COMMIT=8b61eda
HTML_TEXT_PARITY_REQUIRED=true
PREVIEW_RUNTIME_VISUAL_PARITY_REQUIRED=true
MAX_ITEMS_RENDERED=10
MULTI_ITEM_LONG_EMAIL_POLICY=render_first_10_then_cta
PROCESSOR_FUNCTIONAL_TEST=SKIPPED_PREEXISTING_ELIGIBLE_ROWS_3
REAL_SMTP_TEST=false
NETWORK_CONNECTIONS=0
REAL_EMAILS_SENT=0
SECRET_RESOLUTIONS=0
DATABASE_SCHEMA_CHANGED=false
```

La implementación reusable quedó completa y validada de extremo a extremo
hasta la persistencia transaccional en outbox, sin ejecutar SMTP. El
prerrequisito que bloqueaba URLs absolutas fue satisfecho por la microfase
`CORREO-OUTBOX-URL-CHECK-CORRECCION-1`, cerrada en `8b61eda`. El `CHECK`
`chk_tickets_productos_correos_no_sensitive` permite ahora HTTP/HTTPS seguro y
continúa rechazando rutas Windows, `file:`, storage privado y términos
sensibles.

Esta fase runtime no crea ni modifica migraciones. La migración histórica del
outbox permanece intacta y la única migración reciente relacionada es la
corrección DB ya comprometida en `8b61eda`.

## Clases y responsabilidades

- `ProductTicketEmailTemplatePayload` es el DTO readonly normalizado que entra
  al renderer.
- `RenderedEmail` es el resultado readonly con subject, HTML y text/plain.
- `ProductTicketEmailTemplateValidationException` representa errores de
  evento, datos, URL o formato y evita fallbacks silenciosos.
- `ProductTicketEmailTemplatePayloadBuilder` convierte datos reales del ticket
  y sus partidas al contrato del renderer. Construye la CTA desde una base URL
  explícita, aplica timezone explícito y no lee `.env` directamente.
- `ProductTicketEmailTemplateRenderer` valida, crea un único view model y
  produce subject, HTML y text/plain deterministas. No consulta DB, no decide
  destinatarios, no usa SMTP y no conoce el outbox.

`ProductTicketEmailOutboxRepository` amplía consultas preparadas para exponer
fechas, contadores, observaciones, cancelación, partidas ordenadas, datos SAT
solicitados/autorizados y número de adjuntos. El repository conserva la
frontera DB; el renderer permanece DB-free.

`ProductTicketEmailOutboxService` delega exclusivamente la composición al
builder y al renderer. La resolución de TO/CC/BCC, dedupe, estados e inserción
continúan en el servicio/repository existentes. `ProductTicketEmailNotificationService`
no fue modificado.

## Eventos y subjects

| Evento | Plantilla | Subject exacto |
|---|---|---|
| `TICKET_CREADO` | `ticket_created` | `[R-ERP] Ticket {folio} creado` |
| `PARTIDA_APROBADA` | `line_approved` | `[R-ERP] Ticket {folio}: partida {partida_numero} aprobada` |
| `PARTIDA_RECHAZADA` | `line_rejected` | `[R-ERP] Ticket {folio}: partida {partida_numero} rechazada` |
| `TICKET_RESUELTO_TOTAL` | `ticket_resolved` | `[R-ERP] Ticket {folio} resuelto` |
| `TICKET_RESUELTO_PARCIAL` | `ticket_resolved` | `[R-ERP] Ticket {folio} resuelto parcialmente` |
| `TICKET_CANCELADO` | `ticket_cancelled` | `[R-ERP] Ticket {folio} cancelado` |

No existe evento genérico ni fallback para eventos desconocidos.

## HTML, text/plain y paridad

El HTML es un documento completo con estructura basada en tablas, estilos
inline, una regla responsive pequeña, branding corporativo controlado, badge
de estado, metadatos, tarjetas de partidas, bloques condicionales, CTA y pie.
No reutiliza el preview como template ni carga CSS, imágenes o scripts
externos.

El text/plain se construye explícitamente desde el mismo view model y usa
CRLF. No deriva de `strip_tags()`. Conserva folio, estado, metadatos, partidas,
respuesta o motivo cuando aplica, aviso de adjuntos, nota, truncación y CTA.

Los valores del dominio llegan como texto raw normalizado. El renderer escapa
por contexto todo dato interpolado en HTML, rechaza controles CR/LF en campos
de cabecera, no acepta HTML arbitrario y valida la CTA antes de renderizarla.

## URL y CTA

La capa superior acepta una URL base absoluta. HTTPS es obligatorio por
defecto; HTTP solo puede habilitarse explícitamente para local/test. Se
rechazan credenciales embebidas, fragmentos y esquemas no permitidos. La CTA
es informativa (`Ver ticket`) y no es una acción firmada.

La CTA HTTPS generada puede persistirse en `tickets_productos_correos`. HTTP
solo se habilita explícitamente para local/test; producción conserva HTTPS. El
CHECK corregido distingue rutas locales como `C:\\archivo` o `C:/archivo` de
URLs absolutas seguras sin relajar las demás protecciones.

## Partidas, bloques condicionales y límites

- `TICKET_CANCELADO` renderiza `items=[]`.
- Los eventos de partida renderizan exactamente la partida indicada.
- Los eventos resueltos cargan partidas ordenadas por número e ID.
- Respuesta, motivo, datos autorizados, adjuntos y nota aparecen solo cuando
  existe información real aplicable.
- `MAX_ITEMS_RENDERED=10`.
- Si `total_items > 10`, se muestran las primeras diez partidas, un aviso de
  truncación y la CTA.
- `total_items` debe ser coherente con las partidas entregadas.
- Los tonos de ticket y partida provienen de mapeos internos controlados.

## Pruebas

Runner puro:

```text
php database/correo-plantilla-runtime.php functional:test
```

Resultado: 40 casos `PASS`, incluyendo seis eventos, subjects, HTML,
text/plain, paridad, UTF-8, XSS, CRLF, campos requeridos, bloques
condicionales, cancelación sin partidas, múltiples partidas, límites 10/11,
CTA segura, URL insegura, determinismo, branding, ausencia de destinatarios y
ausencia de dependencia DB. El runner reportó cero conexiones de red, cero
correos reales y cero resoluciones de secretos.

Regresiones:

- auditoría PHPMailer: `PASS`, sin SMTP, DB, env ni secretos;
- contrato de correo: `PASS`;
- retry/cancel: 44 casos `PASS`, rollback y hashes protegidos intactos;
- UI outbox: `PASS`, cero escrituras, cero SMTP y rollback;
- outbox DB: `PASS`, CTA HTTPS persistida en fixture transaccional y cleanup
  completo;
- orquestación: `PASS` para los seis eventos, sin SMTP y con rollback;
- procesador funcional: no se ejecutó porque su precondición exige outbox
  elegible vacío y la base ya contenía tres filas elegibles antes de esta fase.

El `dry-run` del procesador confirmó esas tres filas preexistentes y no cambió
la base, no resolvió secretos y no usó SMTP.

## Datos protegidos

Tras las pruebas:

- ticket `34`: `QASMTP-000001`, `EN_REVISION`, `total_partidas=0`;
- outbox `1`: `CANCELADO`, `intentos=0`;
- outbox `36`: `ENVIADO`, `intentos=1`, `enviado_at` conservado;
- filas elegibles preexistentes: IDs `698`, `699` y `700`;
- `eligible_count=3`;
- `tickets_count=2`;
- `outbox_count=5`.

Las regresiones con escritura usaron rollback y confirmaron hashes protegidos
sin cambios. No se procesaron, cancelaron ni reenviaron las filas elegibles.

## Integración y limitaciones

`ProductTicketEmailOutboxService` usa exclusivamente `RenderedEmail.subject`,
`RenderedEmail.htmlBody` y `RenderedEmail.textBody`; no conserva una segunda
plantilla legacy activa. `ProductTicketEmailNotificationService`, la resolución
de TO/CC/BCC, el fingerprint de destinatarios y la semántica de `dedupe_key`
permanecen sin cambios. El bootstrap solo realiza el wiring explícito del
builder y renderer.

El alcance termina al registrar el mensaje renderizado en outbox. No se
conectó SMTP, no se ejecutó `process`, no se enviaron correos, no se resolvieron
secretos y no se modificó `.env`. El functional test del procesador permanece
omitido de forma controlada mientras existan las filas elegibles preexistentes
`698`, `699` y `700`; esto no bloquea el renderer ni su integración con outbox.
