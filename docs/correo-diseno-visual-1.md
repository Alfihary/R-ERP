# CORREO-DISENO-VISUAL-1

## Estado, alcance y contrato base

```ini
VISUAL_STYLE=corporate_erp_refrigeration
MAIL_TEMPLATE_CONTRACT_VERSION=1
LANGUAGE=es
HTML_BODY_REQUIRED=true
TEXT_BODY_REQUIRED=true
REAL_SMTP_TEST=false
MULTI_ITEM_LONG_EMAIL_POLICY=PENDING_IMPLEMENTATION_VALIDATION
```

Este documento define la dirección visual oficial para los correos HTML de
tickets de producto de R-ERP. Es una especificación de diseño: no implementa
HTML, CSS, PHP, templates runtime, preview, transporte, outbox ni SMTP.

La dirección ya aprobada es una notificación operativa premium de ERP:
tecnológica, corporativa, limpia, moderna, profesional y sobria. La prioridad
es que una persona administrativa u operativa entienda qué ocurrió, identifique
el ticket y pueda entrar al ERP sin confundir el mensaje con una campaña de
marketing.

La escena de uso principal es una persona que revisa correo durante una jornada
operativa, con luz de oficina y poco tiempo para distinguir el estado y la
acción siguiente. Por eso se adopta light mode, jerarquía compacta, contraste
alto y bloques predecibles.

La especificación respeta el contrato
`docs/correo-plantilla-contrato-1.md` y sus seis eventos:

- `TICKET_CREADO`;
- `PARTIDA_APROBADA`;
- `PARTIDA_RECHAZADA`;
- `TICKET_RESUELTO_TOTAL`;
- `TICKET_RESUELTO_PARCIAL`;
- `TICKET_CANCELADO`.

No se crean eventos de comentarios o adjuntos. El HTML y `text/plain` deberán
conservar la misma información esencial cuando se implemente la plantilla.

## Dirección visual aprobada

La familia visual usa un exterior gris azulado, un contenedor central blanco y
un header navy con degradado azul discreto. El folio, el título del evento y la
etiqueta textual de estado forman la jerarquía principal. Una línea delgada de
azul a cyan y verde tecnológico separa el header del contenido.

El cuerpo emplea saludo, resumen breve, títulos de sección, tarjetas de partida,
datos estructurados, respuesta, aviso de adjuntos, nota, CTA y footer. Las
superficies se diferencian mediante fondo y bordes sutiles, no mediante efectos
decorativos. Los estados usan color como refuerzo y siempre incluyen texto.

No se permite convertir esta dirección en:

- un email totalmente blanco y sin jerarquía;
- una newsletter;
- una pieza promocional;
- un layout de marketing;
- seis plantillas visualmente independientes;
- una interfaz que dependa de imágenes, webfonts, JavaScript o CSS moderno.

## Estructura maestra

El orden semántico y visual es:

```text
BACKGROUND EXTERIOR
  EMAIL CONTAINER
    HEADER
    ACCENT LINE
    GREETING
    EVENT SUMMARY
    SECTION TITLE
    TICKET / PARTIDA CARDS
    ATTACHMENT NOTICE (opcional)
    NOTE (opcional)
    PRIMARY CTA
    FOOTER
```

El orden no cambia entre eventos. Solo cambian título, copy, etiqueta, acento y
bloques condicionales. Esta consistencia permite reconocer todos los mensajes
como parte de R-ERP.

## Paleta y tokens visuales

La paleta azul se reserva para identidad, jerarquía y acción. Verde, ámbar y
rojo se usan únicamente para estados. El acento verde mantiene una relación
discreta con el color primario del ERP actual sin alterar la dirección azul
aprobada para correo.

| Token | Valor aproximado | Uso | Fallback |
|---|---:|---|---|
| `MAIL_PAGE_BACKGROUND` | `#E7EDF6` | Fondo exterior gris azulado | `#EDF1F6` |
| `MAIL_NAVY` | `#0B2A4A` | Header y fallback del degradado | `#0C2D55` |
| `MAIL_BLUE` | `#14579B` | CTA, links y jerarquía azul | `#164C8C` |
| `MAIL_BLUE_BRIGHT` | `#0B78D1` | Segundo punto del degradado | `#168EEA` |
| `MAIL_CYAN` | `#0D9EC4` | Acento de revisión y línea | `#168EEA` |
| `MAIL_GREEN_ACCENT` | `#0A7A58` | Aprobado, resuelto y final de línea | `#087354` |
| `MAIL_AMBER` | `#9A5B00` | Resolución parcial | `#8A5200` |
| `MAIL_RED` | `#B42318` | Rechazado y cancelación moderada | `#9F241B` |
| `MAIL_SURFACE` | `#FFFFFF` | Contenedor principal | `#FFFFFF` |
| `MAIL_SURFACE_SOFT` | `#F4F7FB` | Cards, nota y footer | `#F5F8FC` |
| `MAIL_BORDER` | `#CBD7E6` | Bordes y separadores | `#D7E1EF` |
| `MAIL_TEXT` | `#152238` | Texto principal | `#172235` |
| `MAIL_TEXT_SECONDARY` | `#465B74` | Descripciones y texto auxiliar | `#52647D` |
| `MAIL_TEXT_MUTED` | `#64748B` | Metadata no crítica | `#5F7087` |
| `MAIL_WHITE` | `#FFFFFF` | Texto sobre navy/blue | `#FFFFFF` |

Los valores son contractuales como dirección aproximada; la implementación
podrá ajustar un tono solo para cumplir contraste o una limitación comprobada
de cliente, sin cambiar la familia visual.

Contrastes objetivo:

- texto normal: al menos `4.5:1`;
- texto grande o bold: al menos `3:1`;
- CTA blanco sobre `MAIL_BLUE`: al menos `4.5:1`;
- labels muted sobre blanco o superficie suave: al menos `4.5:1`;
- estados: texto legible, además de color y etiqueta explícita.

Contrastes medidos para los tokens aprobados:

| Combinación | Ratio |
|---|---:|
| CTA blanco sobre `MAIL_BLUE` | `7.33:1` |
| Header blanco sobre `MAIL_NAVY` | `14.54:1` |
| `MAIL_TEXT` sobre blanco | `15.94:1` |
| `MAIL_TEXT_SECONDARY` sobre blanco | `6.98:1` |
| `MAIL_TEXT_MUTED` sobre blanco | `4.76:1` |

Los cinco valores superan el objetivo aplicable de contraste para texto normal.
La implementación deberá recalcularlos si modifica cualquier color por una
limitación comprobada de cliente de correo.

## Contenedor y fondo exterior

`MAIL_PAGE_BACKGROUND` ocupa el fondo del cliente y nunca debe ser blanco puro.
Su única función es separar el mensaje del entorno del cliente de correo.

`MAIL_CONTAINER`:

- superficie blanca;
- ancho visual desktop recomendado de `720px`, dentro del rango `700–800px`;
- `max-width` compatible con clientes de correo;
- centrado;
- radio visual de `12px` a `16px`;
- sombra opcional muy suave, con blur máximo conceptual de `8px`;
- sin depender de la sombra para delimitarse;
- overflow visual controlado;
- ancho fluido en tablet y móvil.

La implementación futura usará tablas de presentación y atributos compatibles,
no CSS Grid ni flex avanzado como estructura crítica.

## Header

`MAIL_HEADER` es el elemento visual principal. Usa `MAIL_NAVY` como
`background-color` de fallback y, cuando el cliente lo soporte, un degradado
discreto hacia `MAIL_BLUE` o `MAIL_BLUE_BRIGHT`.

### Desktop

La composición visual tiene dos zonas:

- izquierda, aproximadamente 60%: `ERP REFRIGERACIÓN`, título y subtítulo;
- derecha, aproximadamente 40%: folio grande y badge del estado.

Contenido izquierdo:

```text
ERP REFRIGERACIÓN
{titulo_evento}
Notificación automática del sistema
```

Contenido derecho:

```text
{folio}
{estado_humano}
```

El título usa blanco sólido, no texto con degradado. El folio tiene alta
jerarquía pero no debe superar al significado del evento. La alineación puede
ser derecha en desktop y debe conservar suficiente espacio para folios largos.

### Mobile

Por debajo de un breakpoint conceptual cercano a `600px`, las zonas se apilan:

1. `ERP REFRIGERACIÓN`;
2. título;
3. subtítulo;
4. folio;
5. badge.

El folio conserva al menos `26px` conceptuales, permite wrap seguro si fuese
necesario y nunca provoca scroll horizontal.

### Línea de acento

`MAIL_ACCENT_LINE` aparece inmediatamente después del header. Es una línea fina
de `3px` o `4px` con transición azul → cyan → verde tecnológico. Si el cliente
no soporta gradientes, se muestra como azul sólido. No es un arco iris ni un
elemento interactivo.

## Tipografía

Stack seguro recomendado:

```text
Arial, Helvetica, "Segoe UI", sans-serif
```

No se cargan webfonts. Una sola familia cubre títulos, labels, datos y body.

Jerarquía conceptual desktop:

| Elemento | Tamaño | Peso | Line-height |
|---|---:|---:|---:|
| Título del evento | `32px` | `700` | `1.15` |
| Folio | `30px` | `800` | `1.15` |
| Greeting | `22px` | `700` | `1.3` |
| Título de card | `21px` | `700` | `1.3` |
| Body | `16px` | `400` | `1.55` |
| Labels | `12px` | `700` | `1.35` |
| Footer | `13px` | `400` | `1.5` |

El body mantiene líneas de aproximadamente `65–75ch`. No se usa bold en todos
los elementos; se reserva para jerarquía, labels y resultados.

## Espaciado, bordes y sombras

La escala conceptual es:

```text
8 / 12 / 16 / 24 / 32 / 40 px
```

Reglas:

- padding desktop del cuerpo: `32–40px`;
- padding móvil: `20–24px`;
- separación entre secciones: `24–32px`;
- separación interna de cards: `16–24px`;
- border de cards: `1px solid MAIL_BORDER`;
- radios: `8–12px` en componentes, `12–16px` en container;
- separadores finos y claros;
- sombras, si existen, con opacidad baja y blur no mayor a `8px`;
- no combinar borde decorativo con sombra amplia;
- no usar bordes oscuros.

## Badges de estado

Los badges siempre contienen texto. El color nunca es el único indicador.

| Estado humano | Texto | Fondo aproximado | Texto/borde | Uso |
|---|---|---|---|---|
| En revisión | `EN REVISIÓN` | azul/cyan muy claro | azul oscuro | Ticket creado |
| Aprobado | `APROBADO` o `APROBADA` | verde muy claro | verde oscuro | Partida o ticket resuelto |
| Rechazado | `RECHAZADO` o `RECHAZADA` | coral muy claro | rojo oscuro | Partida rechazada |
| Cancelado | `CANCELADO` | gris azulado o rojo tenue | gris oscuro/rojo | Estado final |
| Resuelto | `RESUELTO` | teal muy claro | verde/teal oscuro | Resolución total |
| Resuelto parcialmente | `RESUELTO PARCIALMENTE` | ámbar muy claro | ámbar oscuro | Resultado mixto |

`MAIL_STATUS_BADGE` es principal y aparece junto al folio. `MAIL_ITEM_BADGE` es
más pequeño y vive en la card. Ambos degradan a texto con borde si el cliente
no conserva el fondo.

## Saludo y resumen del evento

`MAIL_GREETING` inicia el cuerpo:

```text
Hola {nombre},
```

Si el nombre visible no existe, se usa el fallback contractual seguro; no se
escribe “Estimado usuario” cuando sí se conoce el nombre.

`MAIL_EVENT_SUMMARY` es una frase breve, directa y amable que explica el hecho.
No debe superar dos párrafos cortos ni repetir todos los datos de las cards.

Ejemplo para ticket creado:

```text
Tu solicitud fue registrada correctamente y está pendiente de revisión.
```

## Título de sección

`MAIL_SECTION_TITLE` separa grupos como `PARTIDAS DEL TICKET`. Puede usar
uppercase, tracking leve y `MAIL_TEXT_SECONDARY`, seguido por una línea fina.
El patrón se usa solo donde existe una sección real, no como eyebrow repetido en
cada bloque.

## Tarjeta de ticket o partida

`MAIL_ITEM_CARD` representa una unidad de información operativa. Puede repetirse
verticalmente y no se anida dentro de otra card.

Estructura:

```text
ACCENT RAIL | IDENTIFICADOR O NÚMERO DE PARTIDA       BADGE DE PARTIDA
            | descripción
            | DATA GRID
            | RESPUESTA / OBSERVACIÓN opcional
```

La barra de acento se implementará, si resulta compatible, como una celda
estrecha de presentación o un bloque separado de `4px`, no como un borde lateral
crítico. Si desaparece, el borde completo y la jerarquía textual mantienen la
estructura.

### Referencia principal

La primera línea usa solo un identificador real disponible:

1. `clave_autorizada`, cuando el evento y payload realmente la contienen;
2. `Partida {partida_numero}` como fallback contractual;
3. nunca un `id_producto` inventado.

En la referencia visual se usa `MBZXH451M6C` únicamente como mock data. No se
inserta ni se asume en runtime.

### Descripción

La descripción usa `MAIL_TEXT_SECONDARY`, tamaño menor al identificador y wrap
natural. Se busca una lectura de una o dos líneas, pero no se corta de forma que
cambie el significado. La política de extractos largos se validará durante la
implementación.

### Badge de partida

Ejemplos contractuales:

- `Partida registrada`;
- `Partida aprobada`;
- `Partida rechazada`.

El badge es secundario, más pequeño que el estado principal y conserva texto.

## Data grid

`MAIL_DATA_GRID` organiza propiedades en hasta tres columnas visuales en
desktop. No usa CSS Grid como dependencia; la implementación futura deberá usar
tablas o celdas compatibles y una lectura lineal correcta.

Cada celda contiene:

```text
LABEL
Valor
```

Ejemplo visual:

| RESULTADO | UNIDAD SAT SOLICITADA | CLAVE SAT SOLICITADA |
|---|---|---|
| En revisión | H87 · Pieza | 40101704 · Unidades de condensación |

Los labels usan `12px`, peso `700` y texto azul/gris. Los valores usan `15–16px`
y `MAIL_TEXT`. Los separadores son `MAIL_BORDER`.

En móvil las propiedades se apilan en una columna. Dos columnas solo se permiten
si una prueba real demuestra ancho suficiente; la preferencia es una columna.

## Respuesta u observación

`MAIL_RESPONSE` aparece al final de la card únicamente si existe contenido
útil. Usa una etiqueta visible:

```text
Respuesta: {respuesta}
```

“Respuesta:” se destaca en azul. El contenido permanece como texto escapado y
no interpreta HTML del usuario. Puede representar, según el evento, un
comentario de resolución o un motivo de rechazo real; no mezcla fuentes ni
inventa una respuesta genérica.

El icono conceptual de chat es opcional. La información debe funcionar sin él.

## Aviso de adjuntos

`MAIL_ATTACHMENTS` es una caja azul clara que informa, no descarga:

```text
Adjuntos
Este ticket tiene {adjuntos_count} archivo(s) de soporte.
Consulta el detalle del ticket para revisarlos.
```

Reglas:

- se omite por completo cuando el conteo es cero o no está disponible;
- no se muestra “0 archivos”;
- no contiene URLs directas a archivos, storage o descargas privadas;
- dirige al CTA general del ticket;
- `{adjuntos_count}` queda sujeto a disponibilidad real del resolver y a
  `PENDING_IMPLEMENTATION_VALIDATION`;
- no crea un evento de adjuntos.

El paperclip es opcional y no comunica información crítica.

## Nota

`MAIL_NOTE` es una caja gris azulada clara con título `Nota`. Puede incluir un
indicador de información simple y una pieza separada de acento azul, pero no
depende de un borde lateral grueso.

Ejemplo:

```text
Nota
El ticket se encuentra registrado para seguimiento.
```

Si no existe una nota útil, el bloque se omite. No se rellenan espacios con
placeholders, guiones, `N/A` o copy redundante.

## CTA principal

`MAIL_CTA` conserva el texto contractual:

```text
Ver ticket
```

Dirección contractual:

```text
APP_URL + /tickets/productos/{id}
```

Diseño:

- botón azul sólido `MAIL_BLUE`;
- texto blanco en bold moderado;
- altura clicable mínima conceptual de `44px`;
- padding horizontal amplio;
- radio de `6–8px`;
- centrado o alineado con la columna principal;
- ancho completo en móvil;
- URL visible como fallback en texto plano.

El CTA representa solo navegación GET. No aprueba, rechaza, resuelve o cancela.
No incluye tokens, sesiones, credenciales, magic links ni side effects. El ERP
vuelve a validar autenticación, permiso y alcance.

La futura implementación deberá usar el patrón de botón compatible con Outlook
si las pruebas lo exigen, manteniendo un link HTML normal como fallback.

## Footer

`MAIL_FOOTER` usa `MAIL_SURFACE_SOFT`, separador superior y texto centrado.

Copy conceptual:

```text
SoporteGR ERP · Correo automático
Este mensaje informa sobre una operación registrada en el sistema.
```

No se inventan teléfono, email, dirección física, razón social, soporte o cuenta
`no-reply`. “No responder a este mensaje” solo podrá incorporarse si la cuenta y
política reales lo confirman.

Se reserva un espacio conceptual para un logo aprobado, pero no se crea ni se
descarga ningún asset. El footer y header deben funcionar completamente sin
logo o imágenes.

## Iconografía

Los únicos iconos conceptuales son chat, paperclip e información. No se depende
de Font Awesome, Material Icons, SVG externo o webfont. La futura implementación
podrá elegir Unicode seguro, imagen embebida aprobada, HTML simple o ausencia de
icono. Ningún dato crítico se representa solo mediante iconografía.

## Variantes por evento

### TICKET_CREADO

- título: `Ticket creado`;
- badge principal: `EN REVISIÓN`;
- acento: azul/cyan;
- badge de partida: `Partida registrada`;
- resumen: `Tu solicitud fue registrada correctamente y está pendiente de revisión.`;
- cards: partidas solicitadas disponibles;
- bloques opcionales: adjuntos y nota;
- CTA: `Ver ticket`.

### PARTIDA_APROBADA

- título: `Partida aprobada`;
- badge: `APROBADA`;
- acento: verde;
- resumen: la partida fue aprobada;
- card: partida afectada, descripción y datos autorizados disponibles;
- respuesta: comentario de resolución si existe;
- CTA: `Ver ticket`.

### PARTIDA_RECHAZADA

- título: `Partida rechazada`;
- badge: `RECHAZADA`;
- acento: rojo/coral controlado;
- resumen: la partida fue rechazada;
- card: partida afectada y descripción;
- respuesta: motivo de rechazo visible y escapado;
- regla visual: el rojo se limita a badge/acento, no cubre todo el correo;
- CTA: `Ver ticket`.

### TICKET_RESUELTO_TOTAL

- título: `Ticket resuelto`;
- badge: `RESUELTO`;
- acento: verde/teal;
- resumen: concluyó la revisión de todas las partidas;
- cards o resumen: resultados disponibles;
- CTA: `Ver ticket`.

### TICKET_RESUELTO_PARCIAL

- título: `Ticket resuelto parcialmente`;
- badge: `RESUELTO PARCIALMENTE`;
- acento: ámbar moderado;
- resumen: existen resultados mixtos aprobados y rechazados;
- cards o resumen: distinguen cada resultado con texto y color;
- regla visual: no usar amarillo brillante;
- CTA: `Ver ticket`.

### TICKET_CANCELADO

- título: `Ticket cancelado`;
- badge: `CANCELADO`;
- acento: gris oscuro con rojo moderado;
- resumen: el ticket fue cancelado y no continuará el flujo;
- nota o respuesta: motivo real si existe y está permitido;
- regla visual: comunicar un estado final, no un error técnico;
- CTA: `Ver ticket`.

## Matriz por evento

| Evento | Título | Badge | Color/acento | Resumen | Bloques visibles |
|---|---|---|---|---|---|
| `TICKET_CREADO` | Ticket creado | EN REVISIÓN | Azul/cyan | Solicitud registrada y pendiente | Saludo, resumen, partidas, adjuntos opcional, nota opcional, CTA |
| `PARTIDA_APROBADA` | Partida aprobada | APROBADA | Verde | Partida aprobada | Saludo, resumen, card afectada, respuesta opcional, CTA |
| `PARTIDA_RECHAZADA` | Partida rechazada | RECHAZADA | Coral/rojo | Partida rechazada | Saludo, resumen, card afectada, motivo, CTA |
| `TICKET_RESUELTO_TOTAL` | Ticket resuelto | RESUELTO | Verde/teal | Revisión concluida | Saludo, resumen, cards o resumen, nota opcional, CTA |
| `TICKET_RESUELTO_PARCIAL` | Ticket resuelto parcialmente | RESUELTO PARCIALMENTE | Ámbar | Resultado mixto | Saludo, resumen, cards o resumen, nota opcional, CTA |
| `TICKET_CANCELADO` | Ticket cancelado | CANCELADO | Gris/rojo moderado | Flujo cancelado | Saludo, resumen, motivo opcional, nota opcional, CTA |

## Mockup maestro estructurado

La variante maestra para aprobación e implementación futura es
`TICKET_CREADO`. La composición reproducible es:

1. Fondo exterior `MAIL_PAGE_BACKGROUND` con espacio de `24–40px`.
2. Container blanco de `720px`, centrado, radio moderado.
3. Header navy/azul de dos zonas.
4. Izquierda: `ERP REFRIGERACIÓN`, `Ticket creado` y subtítulo automático.
5. Derecha: `GU-000014` y badge `EN REVISIÓN`.
6. Línea fina azul/cyan/verde.
7. Cuerpo blanco con `Hola Rosalba,` y resumen de registro.
8. Título de sección `PARTIDAS DEL TICKET`.
9. Card con referencia `MBZXH451M6C`, descripción y badge
   `Partida registrada`.
10. Data grid con resultado, unidad SAT y clave SAT.
11. Respuesta: `Solicitud registrada correctamente.`
12. Aviso de un archivo de soporte sin link directo.
13. Nota de seguimiento, solo si aporta información.
14. CTA azul `Ver ticket`.
15. Footer gris azulado con identidad conceptual y copy automático.

Mock data exclusivo de referencia:

```text
folio: GU-000014
estado: EN REVISIÓN
saludo: Hola Rosalba,
referencia: MBZXH451M6C
descripción: UNIDAD COND COMP SCROLLEXT 4.5 HP R-404A 208-230/3/60
unidad SAT: H87 · Pieza
clave SAT: 40101704 · Unidades de condensación
respuesta: Solicitud registrada correctamente.
adjuntos: 1 archivo de soporte
```

Estos valores no se insertan en runtime, fixtures, outbox ni base de datos.

## Datos dinámicos permitidos y pendientes

La notación siguiente describe posiciones visuales, no crea variables runtime.

| Variable conceptual | Estado | Fuente o restricción |
|---|---|---|
| `{nombre}` | Permitida | Nombre visible disponible; username como fallback contractual |
| `{folio}` | Permitida | Folio real del ticket |
| `{estado}` | Permitida | Estado real convertido a etiqueta humana |
| `{fecha}` | Condicional | Solo una fecha real del evento; no inventar fecha global de resolución |
| `{producto}` | No genérica | No existe `id_producto` directo; usar clave autorizada real o número de partida |
| `{descripcion}` | Permitida | Descripción solicitada real |
| `{unidad_sat}` | Condicional | Solo si el resolver entrega el dato real aprobado |
| `{clave_sat}` | Condicional | Solo si el resolver entrega el dato real aprobado |
| `{respuesta}` | Condicional | Motivo o comentario real según el evento |
| `{adjuntos_count}` | Pendiente | `PENDING_IMPLEMENTATION_VALIDATION`; omitir si no existe |
| `{url_ticket}` | Permitida futura | `APP_URL + /tickets/productos/{id}`, GET y con validación al abrir |

Todo dato dinámico debe escaparse para HTML y normalizarse en texto plano.
Motivos, observaciones, nombres y descripciones nunca se interpretan como HTML.

## Múltiples partidas

Para 1, 2 o 5 partidas, las cards se repiten verticalmente con la misma
estructura y separación. No se usa carrusel, scroll horizontal ni columnas de
cards paralelas.

Para muchas partidas:

```ini
MULTI_ITEM_LONG_EMAIL_POLICY=PENDING_IMPLEMENTATION_VALIDATION
```

La estrategia candidata es mostrar un resumen, un subconjunto coherente y el
CTA al ERP. No se fija cantidad, truncado ni criterio hasta auditar payload,
límites de tamaño y casos reales. Nunca se omiten resultados de forma que el
resumen sea engañoso.

## Bloques condicionales

| Bloque | Mostrar cuando | Omitir cuando |
|---|---|---|
| Card de partida | El evento tiene partida o lista real | No hay datos de partida aplicables |
| Respuesta | Existe comentario o motivo útil | Valor vacío o no aplicable |
| Adjuntos | Conteo real mayor a cero | Cero, null o fuente no disponible |
| Nota | Existe información operativa adicional | Solo repetiría el resumen |
| Datos autorizados | Existen y aplican al evento | Aún no están resueltos |
| Logo | Existe asset aprobado y estrategia compatible | No hay logo aprobado o no carga |

No se renderizan placeholders vacíos, `0 archivos`, `null`, `undefined` o `N/A`.

## Catálogo de componentes visuales

| Componente | Responsabilidad | Elemento crítico | Degradación segura |
|---|---|---|---|
| `MAIL_CONTAINER` | Delimitar mensaje | Superficie y ancho | Tabla blanca sin radio/sombra |
| `MAIL_HEADER` | Identidad y evento | Título, folio y estado | Navy sólido |
| `MAIL_EVENT_TITLE` | Nombrar evento | Texto blanco | Texto sólido sin efecto |
| `MAIL_FOLIO` | Identificar ticket | Folio legible | Bloque apilado |
| `MAIL_STATUS_BADGE` | Estado principal | Texto de estado | Texto con borde |
| `MAIL_GREETING` | Personalizar apertura | Nombre/fallback | Saludo genérico contractual |
| `MAIL_SECTION_TITLE` | Separar secciones | Label y separador | Heading simple |
| `MAIL_ITEM_CARD` | Agrupar partida | Referencia y descripción | Tabla con borde |
| `MAIL_ITEM_BADGE` | Resultado de partida | Texto del resultado | Texto inline |
| `MAIL_DATA_GRID` | Mostrar propiedades | Labels y valores | Lista vertical |
| `MAIL_RESPONSE` | Mostrar respuesta | Texto escapado | Párrafo con label |
| `MAIL_ATTACHMENTS` | Informar existencia | Conteo y acceso por ERP | Párrafo sin icono |
| `MAIL_NOTE` | Contexto adicional | Nota útil | Párrafo simple |
| `MAIL_CTA` | Navegar al ERP | Link GET | Link visible |
| `MAIL_FOOTER` | Cierre e identidad | Copy automático | Texto centrado simple |

## Responsive y mobile

Breakpoint conceptual: aproximadamente `600px`. La implementación no dependerá
solo de media queries; debe conservar orden lineal y anchos seguros incluso en
clientes que las ignoren.

| Componente | Desktop/tablet | Mobile |
|---|---|---|
| Background | Margen exterior `24–40px` | Margen `8–12px` |
| Container | `720px`, centrado | Ancho completo disponible |
| Header | Dos zonas 60/40 | Zonas apiladas |
| Título | `32px` | `26–28px` |
| Folio | Derecha, `30px` | Debajo del subtítulo, mínimo `26px` |
| Status badge | Bajo folio | Bajo folio, wrap permitido |
| Body padding | `32–40px` | `20–24px` |
| Section title | Label y línea | Label y línea reducida |
| Item card | Header y datos amplios | Contenido apilado |
| Data grid | Hasta 3 columnas | 1 columna preferida |
| Response | Fila inferior | Bloque completo |
| Attachments | Caja horizontal | Caja vertical, sin overflow |
| Note | Caja compacta | Caja completa |
| CTA | Ancho contenido | Ancho completo y mínimo 44px alto |
| Footer | Dos líneas centradas | Wrap natural y padding reducido |

Mobile debe asegurar:

- ningún scroll horizontal;
- folio visible y sin recorte;
- badges con wrap y texto completo;
- descripción con wrap natural;
- CTA ancho y fácil de tocar;
- padding reducido sin amontonar contenido;
- grid en una columna;
- URL de fallback capaz de romperse de forma segura.

## Accesibilidad

- Objetivo WCAG 2.1 AA razonable para contraste.
- Texto base mínimo conceptual de `15–16px`.
- Color acompañado siempre por título o estado textual.
- Links distinguibles mediante color y subrayado cuando sean inline.
- CTA con texto explícito y área suficiente.
- Orden de lectura lineal coherente en desktop y mobile.
- No depender de iconos, imágenes, degradados, sombras o radius.
- Copia breve y estados expresados en lenguaje humano.
- Folio y resultado permanecen legibles al aumentar texto.
- El texto alternativo solo se exige para una imagen futura que aporte
  información; un logo decorativo no sustituye el nombre visible.

## Compatibilidad de clientes y fallbacks

Clientes objetivo:

- Gmail;
- Outlook desktop;
- Outlook web;
- Apple Mail;
- Samsung Mail;
- clientes móviles Android e iOS.

| Característica | Riesgo | Diseño deseado | Fallback obligatorio |
|---|---|---|---|
| Degradado | Outlook puede ignorarlo | Navy → azul discreto | `background-color: MAIL_NAVY` |
| Border radius | Puede no aplicarse | Radios 8–16px | Esquinas rectas legibles |
| Sombra | Puede omitirse | Sombra máxima 8px | Borde/superficie suficientes |
| Columnas header | Media queries limitadas | 60/40 desktop | Orden lineal apilable |
| Data grid | CSS Grid no confiable | Hasta 3 columnas | Tabla o lista vertical |
| Iconos | Unicode/imágenes variables | Chat, clip e info opcionales | Labels de texto |
| Botón | Outlook requiere tratamiento | Botón azul grande | Link HTML visible y URL en text/plain |
| Línea degradada | Gradiente no soportado | Azul/cyan/verde | Azul sólido |
| Imágenes | Bloqueo remoto | Logo futuro opcional | Nombre `ERP REFRIGERACIÓN` visible |
| Media queries | Soporte parcial | Ajustes bajo `600px` | Estructura fluida y orden lineal |
| Dark mode | Inversión del cliente | Light mode con fondos definidos | Texto/fondos sólidos y contraste |

La implementación futura no basará información crítica en CSS Grid, flex
avanzado, `position:absolute`, `backdrop-filter`, `filter`, animaciones o
JavaScript. Usará HTML conservador, estilos inline cuando corresponda y tablas
de presentación accesibles para layout de email.

## Light mode y riesgo de dark mode

El diseño oficial es light mode. No se implementa dark mode específico en esta
fase. El riesgo futuro es la inversión automática de colores por algunos
clientes. Para reducirlo:

- se definen fondos sólidos en superficies importantes;
- no se usa texto gris demasiado claro;
- no se depende de transparencia;
- badges conservan texto y borde;
- el contenido no desaparece si el cliente cambia fondos;
- se probará la implementación real antes de cerrar compatibilidad.

## Límites de implementación

Esta fase no modifica:

- `ProductTicketEmailNotificationService`;
- `ProductTicketEmailOutboxService`;
- `MailOutboxProcessor`;
- `PHPMailerMailTransport`;
- `MailTransport`;
- repositories;
- controllers;
- routes;
- views runtime;
- CSS global o modular;
- JavaScript;
- base de datos.

Tampoco crea PNG, JPG, SVG, HTML, CSS, PHP, assets, logos o mockups gráficos.
No ejecuta `send()`, `process`, resolución de secretos, socket SMTP o PHPMailer
real.

```ini
REAL_SMTP_TEST=false
```

## Datos protegidos

No se consulta ni modifica base de datos. Permanecen protegidos:

```text
ticket 34 / QASMTP-000001
outbox 1 / CANCELADO
outbox 36 / ENVIADO
```

Los datos del mockup no se insertan en runtime, outbox, fixtures, seeds o DB.

## Criterios de aceptación para implementación futura

La fase de implementación posterior deberá demostrar:

1. correspondencia con `MAIL_TEMPLATE_CONTRACT_VERSION=1`;
2. una plantilla maestra para los seis eventos;
3. HTML y `text/plain` con información esencial equivalente;
4. títulos, folios y estados correctos;
5. escape de todo dato dinámico;
6. badges con texto y contraste;
7. CTA GET sin tokens ni side effects;
8. layout legible sin imágenes, gradientes, sombras o radius;
9. desktop, tablet y mobile sin overflow;
10. data grid degradable a lista;
11. bloques opcionales realmente omitidos;
12. muchas partidas bajo política aprobada;
13. Gmail, Outlook, Apple Mail, Samsung Mail y móviles probados;
14. dark mode automático auditado como riesgo;
15. preview local sin SMTP antes de cualquier envío real;
16. ausencia de datos internos, secretos y links privados;
17. ninguna mutación desde el email;
18. datos protegidos sin cambios.

## Resultado de diseño

```ini
VISUAL_STYLE=corporate_erp_refrigeration
MASTER_VARIANT=TICKET_CREADO
MASTER_CONTAINER_WIDTH=720px
MOBILE_BREAKPOINT_CONCEPTUAL=600px
PRIMARY_CTA_LABEL=Ver ticket
PRIMARY_CTA_METHOD=GET
VISUAL_EVENT_COUNT=6
IMAGE_DEPENDENCY=false
WEBFONT_DEPENDENCY=false
JAVASCRIPT_DEPENDENCY=false
MULTI_ITEM_LONG_EMAIL_POLICY=PENDING_IMPLEMENTATION_VALIDATION
REAL_SMTP_TEST=false
RUNTIME_IMPLEMENTED=false
```

La siguiente fase deberá implementar o previsualizar esta especificación de
forma controlada, sin alterar el contrato de contenido ni iniciar SMTP real.

Fase recomendada para validar la traducción visual antes de tocar transporte:

```ini
NEXT_RECOMMENDED_PHASE=CORREO-PREVIEW-LOCAL-1
```

`CORREO-PREVIEW-LOCAL-1` deberá usar datos sintéticos, cubrir los seis eventos
y comparar HTML con `text/plain` sin crear outbox, resolver secretos, conectar
SMTP o enviar correo. Esta fase no se inicia en el presente documento.
