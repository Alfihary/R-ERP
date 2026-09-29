# CORREO-PREVIEW-LOCAL-1

## Estado y objetivo

```ini
PREVIEW_MOCK_DATA_ONLY=true
LOCAL_STATIC_PREVIEW=true
MASTER_VARIANT=TICKET_CREADO
REAL_SMTP_TEST=false
RUNTIME_MODIFIED=false
DATABASE_USED=false
JAVASCRIPT_DEPENDENCY=false
IMAGE_DEPENDENCY=false
```

La fase traduce los contratos `CORREO-PLANTILLA-CONTRATO-1` y
`CORREO-DISENO-VISUAL-1` a un preview HTML estático para inspección local en
navegador. No integra la plantilla con el runtime y no prueba clientes de
correo reales.

## Archivo y ubicación segura

Preview:

```text
docs/previews/mail/ticket-creado.html
```

La ubicación está fuera de `public/`. No se creó ruta, controlador o endpoint.
El archivo se abre directamente mediante `file://` y no requiere PHP, servidor,
sesión, autenticación, base de datos o red.

## Cómo abrir

Desde el explorador de archivos, abrir:

```text
C:\Users\GER005OTU\Desktop\R-ERP\docs\previews\mail\ticket-creado.html
```

También puede arrastrarse a un navegador local. La barra de dirección mostrará
una URL `file:///.../docs/previews/mail/ticket-creado.html`.

El CTA usa `href="#"`. Es visual y no navega a producción, no contiene tokens
y no ejecuta ninguna acción.

## Mock data

La variante maestra es `TICKET_CREADO` y usa exclusivamente:

```text
folio: GU-000014
nombre: Rosalba
estado: EN REVISIÓN
partida: MBZXH451M6C
descripción: UNIDAD COND COMP SCROLLEXT 4.5 HP R-404A 208-230/3/60
resultado: En revisión
unidad SAT: H87 · Pieza
clave SAT: 40101704 · Unidades de condensación
respuesta: Solicitud registrada correctamente.
adjuntos: 1 archivo de soporte
```

Los valores están codificados como contenido estático escapado. No proceden de
DB, repositorios, servicios, outbox, variables de entorno o parámetros de URL.

## Diseño implementado en el preview

El HTML conserva:

- fondo exterior `#E7EDF6`;
- contenedor blanco centrado, máximo `720px`;
- header `#0B2A4A` con degradado discreto a `#14579B`;
- folio prominente y badge textual `EN REVISIÓN`;
- línea azul/cyan/verde con fallback sólido;
- saludo y resumen operativo;
- título `PARTIDAS DEL TICKET`;
- card de partida con acento, referencia, descripción y badge;
- data grid de tres propiedades;
- respuesta;
- aviso de adjuntos sin link directo;
- nota condicional representada con mock data;
- CTA azul `Ver ticket`;
- footer corporativo sin datos inventados.

La estructura principal, header, grid, CTA y footer usan tablas de presentación.
El CSS está embebido en `<style>` y se diseñó para poder traducirse después a
estilos inline. No se usa Tailwind, Bootstrap, CSS externo o framework.

## Responsive

El mismo archivo cubre desktop, tablet y mobile mediante una media query
conceptual de `600px` y una estructura base fluida.

Comportamiento esperado:

- `1440×900`: container de `720px`, centrado, header en dos zonas y grid de tres columnas;
- `768×900`: container casi completo, header de dos zonas y grid legible;
- `390×844`: header apilado, folio visible, grid en una columna, CTA a ancho completo y padding reducido.

No existe un HTML móvil separado.

## QA visual

El archivo se abrió directamente mediante `file://` en Chrome headless y se
inspeccionaron capturas completas de los tres viewports exigidos. Las medidas
se obtuvieron desde el DOM renderizado; no se inició servidor ni hubo tráfico
de red.

| Viewport | Horizontal overflow | Text clipping | Badge wrap | Grid stack | CTA width | Resultado |
|---|---|---|---|---|---|---|
| `1440×900` | No | No | Íntegro, sin corte | 3 columnas | Contenido, 27% del body | PASS |
| `768×900` | No | No | Íntegro, sin corte | 3 columnas | Contenido, 27% del body | PASS |
| `390×844` | No | No | Íntegro, sin corte | 1 columna | Completo, 91% del body | PASS |

Geometría observada:

- `1440×900`: container `720px`, centrado en `x=360`, header en dos zonas;
- `768×900`: container `720px`, margen exterior de `24px`, header en dos zonas;
- `390×844`: container `374px`, margen exterior de `8px`, header apilado;
- ancho del documento igual al viewport en los tres casos;
- badge principal de `107×28px`, sin clipping en los tres casos;
- folio con `scrollWidth=clientWidth`, sin clipping horizontal;
- ninguna card, grid, descripción o CTA sale del viewport.

La inspección visual confirmó fondo exterior claro, header azul premium, folio
visible, badge, línea de acento, card, grid, respuesta, adjuntos, nota, CTA y
footer. El resultado conserva apariencia de notificación operativa de ERP y no
de newsletter o marketing.

## Accesibilidad

- `lang="es"`, charset UTF-8 y viewport presentes;
- body de `16px`; valores secundarios de `15px`; footer de `13px`;
- CTA de `16px` y área clicable amplia;
- estados expresados con texto y no solo color;
- orden de lectura lineal;
- contraste basado en los tokens ya validados;
- CTA con `aria-label="Ver ticket"`;
- tablas de layout marcadas `role="presentation"`;
- contenido comprensible sin degradado, sombra, iconos o imágenes.

## Seguridad y privacidad

El preview no contiene:

- `<script>`;
- event handlers inline;
- `javascript:`;
- dependencias externas;
- secretos o referencias de secretos;
- credenciales o configuración SMTP;
- DSN o datos de DB;
- session, cookie, CSRF o tokens;
- outbox ID, `dedupe_key` o intentos;
- rutas de storage o filesystem dentro del contenido visual;
- links directos a adjuntos;
- datos obtenidos del runtime.

No se modificaron servicios, repositories, controladores, rutas, transportes,
outbox ni plantillas productivas.

## Limitaciones

- Es un preview de navegador local, no una prueba de Gmail, Outlook, Apple Mail
  o Samsung Mail.
- Los estilos siguen en `<style>`; todavía no se han convertido a inline.
- El CTA no navega al ERP.
- No se implementa `text/plain` runtime.
- No se cubren todavía las otras cinco variantes de evento.
- No se valida todavía la política de muchas partidas.
- No existe integración con resolver, plantilla, cola o transporte.
- No se ejecuta dark mode específico.

## Datos protegidos

No se consulta ni modifica DB. Permanecen intactos:

```text
ticket 34 / QASMTP-000001
outbox 1 / CANCELADO
outbox 36 / ENVIADO
```

## Siguiente fase

Después de aprobar visualmente este preview se recomienda definir una fase
separada para convertir el diseño en una plantilla HTML/texto plano reusable y
probar sus seis variantes sin SMTP. Esa fase deberá recibir autorización y un
nombre contractual antes de iniciarse.
