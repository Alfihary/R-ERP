# CORREO-PREVIEW-VARIANTES-1

## Objetivo

Validar localmente cinco variantes visuales del correo de tickets de producto, manteniendo la familia aprobada en `docs/correo-diseno-visual-1.md` y el preview maestro `docs/previews/mail/ticket-creado.html`.

Esta fase es exclusivamente documental y visual. Los archivos no forman parte del runtime, no resuelven secretos, no acceden a base de datos y no envían correo.

## Archivos de preview

- `docs/previews/mail/partida-aprobada.html`
- `docs/previews/mail/partida-rechazada.html`
- `docs/previews/mail/ticket-resuelto-total.html`
- `docs/previews/mail/ticket-resuelto-parcial.html`
- `docs/previews/mail/ticket-cancelado.html`

El preview maestro `docs/previews/mail/ticket-creado.html` se conserva intacto.

## Datos mock

Todos los ejemplos usan datos ficticios, seguros y destinados solamente a comparación visual. El folio `GU-000014`, el nombre `Rosalba`, las descripciones, las respuestas y los motivos no representan datos operativos.

Cada HTML declara:

```text
PREVIEW_MOCK_DATA_ONLY=true
REAL_SMTP_TEST=false
```

## Variantes y diferencias semánticas

| Evento | Título | Badge principal | Acento | Contenido representativo |
| --- | --- | --- | --- | --- |
| `PARTIDA_APROBADA` | Partida aprobada | APROBADA | Verde | Una partida aprobada, datos SAT autorizados y respuesta |
| `PARTIDA_RECHAZADA` | Partida rechazada | RECHAZADA | Rojo moderado | Una partida rechazada, motivo ficticio y un adjunto |
| `TICKET_RESUELTO_TOTAL` | Ticket resuelto | RESUELTO | Verde/teal | Dos partidas aprobadas y una nota final |
| `TICKET_RESUELTO_PARCIAL` | Ticket resuelto parcialmente | RESUELTO PARCIALMENTE | Ámbar moderado | Una partida aprobada, una rechazada, motivo y dos adjuntos |
| `TICKET_CANCELADO` | Ticket cancelado | CANCELADO | Rojo moderado | Resumen administrativo final, sin cards de producto |

La estructura visual permanece común: fondo exterior, container, header, jerarquía, folio, badges, espaciado, cards, CTA, footer, tipografía y comportamiento responsive.

## Bloques condicionales

| Variante | Cards | Adjuntos | Nota | Respuesta o motivo |
| --- | ---: | ---: | --- | --- |
| Partida aprobada | 1 | Ausente | Presente | Respuesta presente |
| Partida rechazada | 1 | 1 | Ausente | Motivo presente |
| Ticket resuelto total | 2 | Ausente | Presente | Ausente |
| Ticket resuelto parcial | 2 | 2 | Ausente | Motivo en partida rechazada |
| Ticket cancelado | 0; resumen administrativo | Ausente | Presente | Motivo administrativo presente |

Cuando el número de adjuntos es cero, el bloque se omite por completo. Lo mismo ocurre con nota y respuesta cuando no aplican.

## Múltiples partidas y correo largo

Las variantes de resolución total y parcial muestran dos cards para verificar repetición, separación visual, lectura lineal y crecimiento vertical. En móvil, las celdas de datos pasan a una columna y las cards permanecen apiladas sin desplazamiento horizontal.

La validación confirma que dos cards son legibles. La política definitiva para tickets con muchas partidas requiere validación posterior con datos reales del contrato de implementación; esta fase no introduce paginación, carrusel, truncamiento ni límites nuevos.

```text
MULTI_ITEM_LONG_EMAIL_POLICY=PENDING_IMPLEMENTATION_VALIDATION
```

## Responsive y accesibilidad

- Breakpoint conceptual: `600px`.
- En móvil, el header se apila y el CTA ocupa el ancho disponible.
- Las celdas de datos cambian de tres columnas a una columna.
- Las cards conservan orden lineal y separación suficiente.
- `RESUELTO PARCIALMENTE` permanece completo y sin clipping a 390 px.
- Cada documento declara `lang="es"`, UTF-8 y viewport.
- Los estados se expresan con texto; el significado no depende únicamente del color.
- El CTA usa `aria-label="Ver ticket"` y `href="#"`.

## Matriz de QA visual

Se inspeccionaron las cinco variantes en tres viewports. Cada comprobación incluyó overflow horizontal, clipping, badge, grid, CTA y cards.

| Variante | 1440 x 900 | 768 x 900 | 390 x 844 |
| --- | --- | --- | --- |
| Partida aprobada | PASS | PASS | PASS |
| Partida rechazada | PASS | PASS | PASS |
| Ticket resuelto total | PASS | PASS | PASS |
| Ticket resuelto parcial | PASS | PASS | PASS |
| Ticket cancelado | PASS | PASS | PASS |

Resultados comunes:

- `VISUAL_CHECK_COUNT=15`
- `horizontal_overflow=false` en las 15 comprobaciones.
- Clipping detectado: ninguno.
- Badges: íntegros y legibles.
- Grid de datos: alineado en escritorio/tablet y apilado en móvil.
- CTA: visible, sin recorte y de ancho cómodo en móvil.
- Cards: alineadas y apiladas correctamente.
- Textos largos: wrap correcto en título, descripción y motivo.

## Validez y seguridad

Los cinco HTML fueron cargados con DOM sin errores:

```text
DOM_LOAD=true
DOM_ERRORS=0
HTML_SCRIPT_TAGS=0
HTML_INLINE_EVENTS=0
HTML_JAVASCRIPT_URLS=0
HTML_EXTERNAL_REFS=0
HTML_REAL_URLS=0
HTML_PROTECTED_VALUES=0
JAVASCRIPT_DEPENDENCY=false
IMAGE_DEPENDENCY=false
```

No se usan scripts, manejadores inline, URLs JavaScript, recursos externos, imágenes, frameworks ni valores protegidos. El layout es table-based y el CSS está embebido para conservar compatibilidad conceptual con clientes de correo.

## Límites de la fase

```text
PREVIEW_VARIANT_COUNT=5
VISUAL_CHECK_COUNT=15
REAL_SMTP_TEST=false
RUNTIME_MODIFIED=false
DATABASE_USED=false
JAVASCRIPT_DEPENDENCY=false
IMAGE_DEPENDENCY=false
MULTI_ITEM_LONG_EMAIL_POLICY=PENDING_IMPLEMENTATION_VALIDATION
```

No se modificaron servicios, transportes, procesadores de outbox, repositorios, controladores, rutas ni vistas del runtime. No se consultaron ni alteraron los tickets u outbox protegidos por contrato. No hubo conexión SMTP, acceso a base de datos, resolución de secretos ni envío real.

## Siguiente fase recomendada

Abrir `CORREO-PLANTILLA-RUNTIME-CONTRATO-1` para definir, antes de integrar código, el mapeo exacto entre eventos, datos permitidos, bloques condicionales, compatibilidad con clientes de correo y política de tickets con muchas partidas.

La integración al runtime, la plantilla reusable y cualquier prueba SMTP requieren autorización independiente.
