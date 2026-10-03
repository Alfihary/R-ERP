# CORREO-PLANTILLA-RUNTIME-TEXT-SANITIZATION-1

## Estado

```ini
PHASE_STATUS=PASS
PLAIN_TEXT_RAW_MARKUP_ALLOWED=false
PLAIN_TEXT_STRIP_TAGS=false
PLAIN_TEXT_PRESERVE_CONTENT=true
HTML_TEXT_PARITY_REQUIRED=true
RUNTIME_RENDERER_TESTS=50/50_PASS
PERSISTED_RUNTIME_QA=PASS
RAW_TEXT_MARKUP_EVENTS=0
DATABASE_SCHEMA_CHANGED=false
NETWORK_CONNECTIONS=0
REAL_EMAILS_SENT=0
SECRET_RESOLUTIONS=0
PROCESSOR_EXECUTED=false
```

La microfase corrige exclusivamente la representación de valores dinámicos en
el cuerpo `text/plain` de los correos de tickets. No modifica subjects, HTML,
destinatarios, deduplicación, outbox, transporte ni persistencia.

## Causa

`ProductTicketEmailTemplateRenderer::plain()` normalizaba saltos y caracteres
de control, pero conservaba literalmente los delimitadores `<` y `>` recibidos
en valores dinámicos. Por ello una descripción como
`<script>alert(1)</script>` se persistía con apariencia de markup dentro del
cuerpo plano.

Esto no era ejecución XSS: un cliente que respeta `text/plain` no interpreta
HTML. El defecto era contractual y de higiene de representación. La política
aprobada prohíbe markup HTML crudo o aparente aun cuando no sea ejecutable.

## Política y solución

El helper central `sanitizePlainTextValue()` se aplica una vez al incorporar
cada valor dinámico al cuerpo plano. Mantiene la normalización anterior de
CR/LF y caracteres de control y neutraliza solamente:

```text
<  -> &lt;
>  -> &gt;
```

No usa `strip_tags()`, porque eliminaría contenido y evidencia útil. Tampoco
aplica `htmlspecialchars()` completo: `text/plain` no es HTML y no requiere
transformar ampersands o comillas.

La transformación es idempotente para el contrato definido. Una entidad ya
visible como `&lt;script&gt;` permanece igual y no se convierte en
`&amp;lt;script&amp;gt;`. Ampersands, comillas, acentos, `ñ`, `ü`, símbolos
SAT y Unicode válido se preservan.

La CTA no pasa por esta transformación. Continúa validándose como URL absoluta
y se incorpora literalmente al texto, por ejemplo:

```text
https://erp.example.test/tickets/productos/{id}
```

Los títulos, etiquetas y textos contractuales estáticos tampoco se transforman
innecesariamente. El HTML conserva su escape contextual independiente y no fue
alterado.

## Casos funcionales

La prueba pura cubre:

| Entrada dinámica | Resultado `text/plain` |
|---|---|
| `<script>alert(1)</script>` | `&lt;script&gt;alert(1)&lt;/script&gt;` |
| `<img src=x onerror=alert(1)>` | `&lt;img src=x onerror=alert(1)&gt;` |
| `Texto antes <b>importante</b> texto después` | `Texto antes &lt;b&gt;importante&lt;/b&gt; texto después` |
| `Temperatura < 10°C y presión > 20 psi` | `Temperatura &lt; 10°C y presión &gt; 20 psi` |
| `&lt;script&gt;` | `&lt;script&gt;` |

El contenido semántico se conserva; solo desaparecen los delimitadores crudos.
CRLF continúa siendo el separador final del mensaje. Las protecciones de CRLF
para folio y número de partida no cambiaron.

## Pruebas

```text
php database/correo-plantilla-runtime.php functional:test
```

Resultado: `50/50 PASS`. Los diez casos nuevos validan script, imagen, markup
mixto, comparadores, entidad previa, motivo, ausencia de tags crudos, CTA
intacta, idempotencia y ausencia de `strip_tags()`.

También permanecen en PASS los seis eventos, subjects exactos, HTML/XSS,
paridad, UTF-8, determinismo, CRLF, documentos DOM, bloques condicionales,
cancelación sin partidas y truncación de once a diez partidas más aviso y CTA.
Los hashes HTML de los seis eventos no cambiaron.

## QA persistida

La prueba transaccional sobre `r_erp_db_core_0_test` pasó para los seis eventos:

```ini
PERSISTED_HTML=PASS
PERSISTED_TEXT=PASS
RAW_TEXT_MARKUP_EVENTS=[]
SUBJECTS_EXACT=true
CTA_HTTPS=true
CONDITIONAL_BLOCKS=true
MAX_ITEMS_RENDERED=10
CRLF_INVALID_ROW_INSERTED=false
RESIDUAL_ROWS=0
CLEANUP=transaction_rolled_back
```

Antes y después se conservaron:

```ini
TICKETS_COUNT=2
OUTBOX_COUNT=5
ELIGIBLE_COUNT=3
TICKETS_HASH=b7136fdfaf77c53dd1aa0f5ca402a4a88e67291330bb2c0bd553bc5441f416d2
OUTBOX_HASH=cfc07d66bfac1d12dfbbc669a81e17af6999ab8dcdd8f66ac0d85837b98460ca
```

El ticket `34` y los outbox `1`, `36`, `698`, `699` y `700` permanecieron
idénticos. Las filas `698`, `699` y `700` siguen pendientes con cero intentos;
no fueron procesadas.

## Regresiones y límites

- contrato de correo: `PASS`;
- outbox DB: `PASS`, rollback completo;
- retry/cancel: `44/44 PASS`;
- UI outbox: `PASS`, cero escrituras;
- migraciones: ninguna;
- seeds: ninguno;
- esquema DB: sin cambios;
- SMTP, PHPMailer real y sockets: no ejecutados;
- resolución de secretos: cero;
- `process`: no ejecutado.

Los tres archivos abiertos de `CORREO-RUNTIME-PERSISTIDO-QA-1` se conservaron
sin staging y no pertenecen al futuro commit correctivo. Esa fase puede
reanudarse después de cerrar esta microfase.
