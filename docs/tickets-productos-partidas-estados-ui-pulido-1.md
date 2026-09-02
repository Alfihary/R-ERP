# TP-PARTIDAS-ESTADOS-UI-PULIDO-1

## Objetivo

Pulir visualmente las vistas de Tickets de Solicitud de Alta de Productos para que se integren mejor al ERP, manteniendo el flujo exclusivamente documental.

Esta fase no crea productos reales, precios, inventario, compras, proveedores ni claves definitivas.

## Vistas pulidas

- `app/Views/tickets/productos/index.php`
- `app/Views/tickets/productos/create.php`
- `app/Views/tickets/productos/show.php`

## CSS modular

Se agrega `public/css/modules/tickets-productos.css` con prefijo de módulo `ticket-products__`.

Las vistas cargan:

- `/css/core/app.css`
- `/css/modules/tickets-productos.css`

No se modifica el layout global.

## Componentes visuales usados

- Encabezados de página con `page-heading`.
- Tarjetas con `home-section`.
- Botones `button`, `button--secondary` y `button--sm`.
- Tabla `data-table` con contenedor horizontal.
- Formularios con `field` y `form-actions`.
- Alertas `alert`.
- Badges de estado por ticket y partida.

Estados visuales contemplados:

- `EN_REVISION`
- `RESUELTO_PARCIAL`
- `APROBADO`
- `RECHAZADO`
- `CANCELADO`
- `APROBADA`
- `RECHAZADA`

## Responsive

Las vistas usan grids fluidos y media queries para escritorio, tablet y celular.

En celular:

- Los formularios pasan a una columna.
- Las acciones se apilan.
- Las tablas mantienen scroll horizontal cuando el contenido lo requiere.
- Los botones se mantienen tocables.

## Guardrails de no creación operativa

El runner de esta fase valida que renderizar las vistas no cambie conteos de:

- `productos`
- `producto_precios`
- `existencias_producto`
- `inventario_existencias`
- `movimientos_inventario`
- `compras`
- `proveedores`

También valida que no existan:

- JS nuevo del módulo.
- Correos runtime.
- Adjuntos reales.
- Rutas físicas en HTML.
- `storage/uploads` en HTML.
- `password_hash`, `token_hash` o datos crudos de sesión en HTML.

## Qué NO hace esta fase

- No cambia rutas.
- No cambia bootstrap.
- No cambia controladores.
- No cambia servicios.
- No cambia repositorios.
- No crea migraciones.
- No crea seeds.
- No implementa correos.
- No implementa adjuntos reales.
- No crea productos reales.
- No crea precios.
- No crea inventario.
- No crea compras.
- No crea proveedores.

## Pruebas esperadas

```bash
php -l app/Views/tickets/productos/index.php
php -l app/Views/tickets/productos/create.php
php -l app/Views/tickets/productos/show.php
php -l database/tickets-productos-partidas-estados-ui-pulido.php
php -l database/tests/tickets_productos_partidas_estados_ui_pulido_1_test.php
git diff --check
php database/tickets-productos-partidas-estados-ui-pulido.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

Regresiones obligatorias:

```bash
php database/tickets-productos-partidas-estados-ui.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/tickets-productos-partidas-estados-routes-controller.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/tickets-productos-partidas-estados-permisos.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/tickets-productos-partidas-estados-service.php service:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/tickets-productos-partidas-estados-db.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/tickets-productos-partidas-estados-contrato.php audit --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/inventario-service.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/precios.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

## Siguiente fase recomendada

`TP-PARTIDAS-ESTADOS-UI-PERMISOS-VISUALES-1`, para condicionar acciones visibles según permisos disponibles sin cambiar la seguridad real del backend.
