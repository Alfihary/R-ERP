# PRECIOS-PRODUCTOS-GLOBAL-UI-1

## Objetivo

Implementar una pantalla global para administrar precios de productos por lista de precios.

La fase permite:

- listar precios actuales;
- filtrar por producto, lista, estado, revisión y moneda;
- crear precio para producto/lista;
- editar precio existente;
- desactivar/reactivar precio;
- ver detalle básico;
- ver historial básico.

## Rutas agregadas

- `GET /precios/productos`
- `GET /precios/productos/ver?id=ID`
- `GET /precios/productos/crear`
- `POST /precios/productos`
- `GET /precios/productos/editar?id=ID`
- `POST /precios/productos/actualizar`
- `POST /precios/productos/desactivar`
- `POST /precios/productos/reactivar`
- `GET /precios/productos/historial?id=ID`

No se agregan rutas de ventas, cotizaciones, pedidos, remisiones ni facturas.

## Controlador

Controlador agregado:

- `App\Http\Controllers\ProductPriceController`

Responsabilidades:

- normalizar estructura básica del request;
- delegar reglas a `ProductPriceService`;
- renderizar vistas;
- manejar errores de validación;
- redirigir con mensajes claros.

El controlador no contiene SQL.

## Vistas

- `app/Views/pricing/product-prices/index.php`
- `app/Views/pricing/product-prices/form.php`
- `app/Views/pricing/product-prices/show.php`
- `app/Views/pricing/product-prices/history.php`

CSS modular:

- `public/css/modules/product-prices.css`

## Filtros

La pantalla global permite filtrar por:

- `q`: busca en `id_producto`, descripción, SKU, UPC, EAN y GTIN;
- `lista_precio_id`;
- `moneda_id`;
- `activo`;
- `requiere_revision`.

## Acciones

- Ver.
- Crear.
- Editar.
- Desactivar.
- Reactivar.
- Historial.

Las acciones se muestran según permiso, pero la seguridad real está en `AuthMiddleware`, `PermissionMiddleware`, `CsrfMiddleware` y `ProductPriceService`.

## Permisos aplicados

- `precios.productos.acceder`
- `precios.productos.ver`
- `precios.productos.crear`
- `precios.productos.editar`
- `precios.productos.desactivar`
- `precios.productos.reactivar`
- `precios.productos.historial`

No se crean permisos nuevos.

## Reglas usadas por ProductPriceService

- No se usa `FLOAT` ni `DOUBLE`.
- `moneda_id` se toma desde el producto.
- `incluye_impuestos` se toma desde la lista.
- No se permite precio para producto sin moneda.
- No se permite duplicar producto/lista.
- `precio_minimo` no puede exceder `precio_lista`.
- Al actualizar precio en revisión con valores válidos, `requiere_revision` queda en `0`.
- Desactivar crea historial `DESACTIVACION`.
- Reactivar crea historial `REACTIVACION`.
- Reactivar rechaza precios en revisión.
- Reactivar rechaza precios con `precio_lista <= 0`.
- Editar crea historial `ACTUALIZACION`.
- Crear crea historial `CREACION`.

## Historial

La vista de historial muestra:

- fecha;
- tipo de cambio;
- moneda anterior/nueva;
- precio lista anterior/nuevo;
- precio mínimo anterior/nuevo;
- revisión anterior/nueva;
- estado anterior/nuevo;
- usuario;
- motivo.

No se implementa diff visual complejo.

## Base de datos

No se crean migraciones y no se modifica el esquema.

La fase usa tablas existentes:

- `listas_precios`
- `producto_precios`
- `producto_precios_historial`
- `autorizaciones_precio`

No crea triggers, procedures ni events.

## Pruebas ejecutables

Runner específico:

```bash
php database/precios-productos-global-ui.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

Cobertura:

- index con permiso;
- index sin permiso;
- crear precio válido;
- historial `CREACION`;
- rechazo de producto sin moneda;
- rechazo de producto/lista duplicado;
- rechazo de `precio_minimo > precio_lista`;
- edición válida;
- historial `ACTUALIZACION`;
- limpieza de revisión;
- desactivación;
- historial `DESACTIVACION`;
- reactivación;
- historial `REACTIVACION`;
- rechazo de reactivar precio en revisión;
- historial visible;
- filtros por lista, estado, revisión y búsqueda;
- detalle visible;
- vistas declaradas;
- no se crean ventas/autorizaciones;
- cleanup transaccional.

## Regresiones

Ejecutar:

- `php database/precios.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`
- `php database/precios-service.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`
- `php database/precios-producto-integracion.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`
- `php database/precios-producto-ui.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`
- `php database/precios-listas-ui.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`
- `php database/productos-imagen.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`
- `php database/productos-2.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`
- `php database/crud-productos.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`
- `php database/inventario.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`
- `php database/inventario-service.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`
- `php database/series.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`
- `php database/folios-inventario.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`

No usar `database/productos.php db:test` como bloqueo obligatorio por la excepción documentada del producto persistente real/no-QA:

`102016169 | REFRIGERANTE R-410A 5KG IGAS`

## Pendientes

- UI futura de autorizaciones funcionales.
- Integración futura con ventas.
- Búsqueda asistida/autocomplete de productos si se aprueba JS específico.
- Mejoras visuales de diff de historial si se aprueban posteriormente.
