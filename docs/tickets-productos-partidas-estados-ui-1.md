# TP-PARTIDAS-ESTADOS-UI-1

## Objetivo

Crear vistas mínimas funcionales para Tickets de Solicitud de Alta de Productos
con partidas resolubles individualmente.

El flujo sigue siendo exclusivamente documental.

## Vistas creadas

- `app/Views/tickets/productos/index.php`
- `app/Views/tickets/productos/create.php`
- `app/Views/tickets/productos/show.php`

## Qué muestra cada vista

`index.php` muestra:

- título `Tickets de productos`;
- subtítulo `Solicitudes de alta de productos`;
- enlace `Nuevo ticket`;
- estado vacío cuando no hay listado disponible;
- estructura de tabla preparada para folio, estado, almacén, solicitante,
  fecha y contadores.

`create.php` muestra:

- formulario `POST /tickets/productos`;
- `csrf_field($csrf)`;
- campos de empresa, almacén y observaciones generales;
- una partida inicial estática con descripción, modelo, marca documental,
  proveedor documental, unidad SAT, clave SAT, moneda, costo sugerido
  documental, peso, lleva serie y observaciones;
- advertencia visible: `Este ticket es documental y no crea productos reales.`

`show.php` muestra:

- folio y estado del ticket;
- empresa, almacén y solicitante;
- observaciones generales;
- contadores de partidas;
- partidas con estado, descripción, modelo, marca, proveedor documental,
  unidad SAT / clave SAT, costo sugerido documental, peso, lleva serie,
  observaciones, motivo de rechazo y comentario de resolución;
- eventos básicos;
- formularios `POST` para aprobar partida, rechazar partida y cancelar ticket,
  todos con `csrf_field($csrf)`;
- advertencia visible: `Autorizar una partida no crea el producto en el catálogo.`

## Formularios disponibles

- Crear ticket documental.
- Aprobar partida documentalmente.
- Rechazar partida con motivo obligatorio.
- Cancelar ticket con motivo obligatorio.

## Advertencias documentales

Las vistas informan explícitamente que:

- crear un ticket no crea productos reales;
- autorizar una partida no crea el producto en el catálogo.

## Guardrails de no creación operativa

Esta fase no crea productos reales.

Esta fase no crea precios.

Esta fase no crea inventario.

Esta fase no modifica existencias.

Esta fase no crea compras.

Esta fase no crea proveedores reales.

Esta fase no genera claves definitivas.

El test valida conteos antes, durante y después para:

- `productos`
- `producto_precios`
- `existencias_producto`
- `inventario_existencias`
- `movimientos_inventario`
- `compras`
- `proveedores`

## Qué NO hace esta fase

- No crea CSS.
- No crea JS.
- No implementa adjuntos reales.
- No implementa correos.
- No modifica rutas.
- No modifica bootstrap.
- No modifica migraciones.
- No modifica seeds.
- No modifica `ProductRequestTicketService`.
- No modifica `ProductRequestTicketRepository`.
- No toca compras, inventario, precios ni productos funcionalmente.

## Pruebas

Runner específico:

```bash
php database/tickets-productos-partidas-estados-ui.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

Validaciones mínimas:

```bash
php -l app/Http/Controllers/ProductRequestTicketController.php
php -l app/Views/tickets/productos/index.php
php -l app/Views/tickets/productos/create.php
php -l app/Views/tickets/productos/show.php
php -l database/tickets-productos-partidas-estados-ui.php
php -l database/tests/tickets_productos_partidas_estados_ui_1_test.php
git diff --check
```

## Siguiente fase recomendada

```text
TP-PARTIDAS-ESTADOS-UI-PULIDO-1
```

Objetivo sugerido: aplicar CSS modular administrativo y mejoras de usabilidad
sin cambiar el contrato documental ni crear productos reales.
