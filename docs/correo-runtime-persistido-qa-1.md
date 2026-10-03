# CORREO-RUNTIME-PERSISTIDO-QA-1

## Estado

```ini
PHASE_STATUS=PASS
DATABASE=r_erp_db_core_0_test
RUNTIME_MODIFIED=false
HTML_TEXT_PARITY_REQUIRED=true
MAX_ITEMS_RENDERED=10
BLOCKER_RESOLVED_BY=f61a19d
TEXT_RAW_MARKUP_EVENTS=0
FIXTURE_CLEANUP=transaction_rolled_back
RESIDUAL_ROWS=0
NETWORK_CONNECTIONS=0
REAL_EMAILS_SENT=0
SECRET_RESOLUTIONS=0
PROCESSOR_EXECUTED=false
SKIPPED_PREEXISTING_ELIGIBLE_ROWS=3
```

La fase audita de forma controlada la cadena productiva
`ProductTicketEmailTemplateRenderer -> ProductTicketEmailOutboxService ->
ProductTicketEmailOutboxRepository -> tickets_productos_correos`. El alcance
termina en la persistencia transaccional del mensaje. No se ejecutan el
procesador, PHPMailer, SMTP ni resolución de secretos.

El bloqueo original por markup aparente crudo en `text/plain` fue corregido en
`f61a19d`. La ejecución final confirmó `text_raw_markup_events=[]` sin que esta
fase QA modificara código productivo. Los fixtures XSS quedaron escapados en
HTML y neutralizados de forma visible en el cuerpo plano.

## Ejecución segura

El runner es exclusivo de CLI, rechaza producción y exige que las dos opciones
coincidan con la base configurada y con la base autorizada:

```text
php database/correo-runtime-persistido-qa.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

La prueba crea dos tickets aislados dentro de una única transacción:

- `QARP-900001`, con dos partidas, para los seis eventos canónicos;
- `QARP-900002`, con once partidas, para el límite de correo largo.

También crea usuario, empresa, almacén, destinatarios mock, datos SAT y un
adjunto mock controlado. Todo termina con `ROLLBACK`, aun ante una excepción.

## Eventos, plantillas y subjects

| Evento | Plantilla | Subject exacto comprobado |
|---|---|---|
| `TICKET_CREADO` | `ticket_created` | `[R-ERP] Ticket QARP-900001 creado` |
| `PARTIDA_APROBADA` | `line_approved` | `[R-ERP] Ticket QARP-900001: partida 1 aprobada` |
| `PARTIDA_RECHAZADA` | `line_rejected` | `[R-ERP] Ticket QARP-900001: partida 2 rechazada` |
| `TICKET_RESUELTO_TOTAL` | `ticket_resolved` | `[R-ERP] Ticket QARP-900001 resuelto` |
| `TICKET_RESUELTO_PARCIAL` | `ticket_resolved` | `[R-ERP] Ticket QARP-900001 resuelto parcialmente` |
| `TICKET_CANCELADO` | `ticket_cancelled` | `[R-ERP] Ticket QARP-900001 cancelado` |

Las seis filas se observaron con estado `PENDIENTE`, cero intentos, evento y
plantilla correctos. La repetición del mismo ticket/evento no generó un
duplicado y conservó la semántica de `dedupe_key`.

## HTML, texto y paridad

Cada HTML persistido es no vacío y contiene documento completo, idioma
español, UTF-8, viewport, branding `ERP REFRIGERACIÓN`, folio, título, estado,
CTA y pie. No carga imágenes, CSS, fuentes, scripts, píxeles ni recursos CDN.
La única URL externa admitida es la CTA QA:

```text
https://erp.example.test/tickets/productos/{id}
```

El constraint `chk_tickets_productos_correos_no_sensitive` aceptó esta URL
HTTPS sin ser modificado.

Cada cuerpo `text/plain` es no vacío y se construyó con CRLF consistente. El
texto conserva la misma información esencial: folio, resumen, estado, partida
aplicable, respuesta o motivo, aviso de adjuntos, nota y CTA. Los delimitadores
`<` y `>` de valores dinámicos se persistieron como `&lt;` y `&gt;`, sin tags
HTML crudos o aparentes. La CTA HTTPS permaneció literal y funcional.

## Seguridad

Los fixtures incluyeron deliberadamente:

```text
description=<script>alert(1)</script>
response=<img src=x onerror=alert(1)>
reason="><script>alert(1)</script>
```

En el HTML persistido quedaron escapados. El escaneo confirmó ausencia de
`<script>`, handlers ejecutables, `javascript:`, `data:` y `file:`. No se
detectaron referencias externas inesperadas.

La ejecución final confirmó `text_raw_markup_events=[]`. Los valores
`<script>alert(1)</script>` y `<img src=x onerror=alert(1)>` quedaron
neutralizados en `text/plain`, sin doble neutralización de entidades
preexistentes. La regresión pura también preservó comparadores legítimos como
`Temperatura &lt; 10°C y presión &gt; 20 psi`.

Payloads con CRLF en `folio` y `partida_numero` fueron rechazados antes de la
inserción (`row_inserted=false`). También se rechazó un payload con
`total_items < count(items)`.

## Bloques condicionales y partidas

Los seis eventos cubren respuesta presente/ausente, motivo presente/ausente,
adjuntos cero/mayor a cero y nota presente/ausente, sin placeholders vacíos.
Los resultados total y parcial persistieron dos partidas ordenadas; el parcial
conservó una aprobada y una rechazada. La cancelación usó `items=[]` y no creó
una tarjeta ficticia.

La prueba adicional de once partidas confirmó:

- `MAX_ITEMS_RENDERED=10`;
- diez tarjetas persistidas;
- la partida once no se renderiza;
- aviso de partidas adicionales presente;
- CTA conservada;
- `total_items=11` coherente.

## Destinatarios y determinismo

La persistencia mantuvo la lógica existente del sobre: TO, CC y BCC
controlados, además de solicitante y responsable mock. No se modificó el
algoritmo de destinatarios. Los valores se limitan al fixture transaccional y
no representan destinatarios reales.

Dos renders consecutivos del mismo payload produjeron hashes idénticos de
subject, HTML y texto. El HTML de los seis eventos cargó con `DOMDocument` sin
errores (`DOM_LOAD=true`, `DOM_ERRORS=0`).

## Tamaños observados

| Evento | Subject bytes | HTML bytes | Text bytes |
|---|---:|---:|---:|
| `TICKET_CREADO` | 33 | 4670 | 491 |
| `PARTIDA_APROBADA` | 46 | 5285 | 628 |
| `PARTIDA_RECHAZADA` | 47 | 5611 | 722 |
| `TICKET_RESUELTO_TOTAL` | 35 | 8027 | 926 |
| `TICKET_RESUELTO_PARCIAL` | 48 | 8126 | 1006 |
| `TICKET_CANCELADO` | 36 | 4643 | 539 |

El caso de once partidas midió 35 bytes de subject, 19749 bytes de HTML y
2369 bytes de texto. No hubo cuerpos vacíos ni duplicación masiva accidental.
Estos valores son evidencia del fixture, no límites contractuales.

## Rollback e integridad

Antes y después del DB-TEST se conservaron:

```ini
TICKETS_COUNT=2
OUTBOX_COUNT=5
ELIGIBLE_COUNT=3
TICKETS_HASH=b7136fdfaf77c53dd1aa0f5ca402a4a88e67291330bb2c0bd553bc5441f416d2
OUTBOX_HASH=cfc07d66bfac1d12dfbbc669a81e17af6999ab8dcdd8f66ac0d85837b98460ca
RESIDUAL_ROWS=0
```

Los datos protegidos permanecieron idénticos:

- ticket `34`: `QASMTP-000001`, `EN_REVISION`, `total_partidas=0`;
- outbox `1`: `CANCELADO`, `intentos=0`;
- outbox `36`: `ENVIADO`, `intentos=1`, `enviado_at` preservado;
- outbox `698`, `699` y `700`: `PENDIENTE`, `intentos=0`, metadatos intactos.

Ninguno de esos IDs fue fixture de escritura. Las filas elegibles
preexistentes quedaron fuera del alcance y no se ejecutó `process`. El test
funcional del procesador se omitió expresamente porque requiere cero filas
elegibles y existen tres filas preexistentes autorizadas fuera de scope.

## Limitaciones

La fase valida composición, persistencia, seguridad y rollback en la base
local descartable. No valida entrega SMTP, renderizado en clientes de correo,
hosting AwardSpace, credenciales, scheduler ni comportamiento del procesador.
No modifica el renderer, builder, DTO, servicio, repository, constraint,
migraciones, seeds ni `.env`.
