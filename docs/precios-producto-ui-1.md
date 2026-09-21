# PRECIOS-PRODUCTO-UI-1

## Objetivo

Agregar una UI mínima de precios dentro del flujo existente de productos para
usar la integración backend cerrada en PRECIOS-PRODUCTO-INTEGRACION-1.

Esta fase no crea pantalla global de precios, administración de listas,
menú nuevo, ventas ni autorizaciones funcionales.

## Archivos tocados

- `app/Domain/Pricing/ProductPriceService.php`
- `app/Domain/Products/ProductService.php`
- `app/Http/Controllers/ProductController.php`
- `app/Views/products/form.php`
- `app/Views/products/detail.php`
- `public/css/modules/products.css`
- `database/precios-producto-ui.php`
- `database/tests/precios_producto_ui_1_test.php`
- `docs/precios-producto-ui-1.md`

## Cambios de controlador

`ProductController` normaliza input de precios antes de llamar a
`ProductService`.

Reglas aplicadas:

- filas sin importes se ignoran, aunque la UI envíe el identificador oculto de
  la lista;
- filas parciales se rechazan con mensaje visible;
- listas duplicadas se rechazan;
- si el usuario no tiene permiso de precios, los datos de precios se ignoran y
  el producto puede seguir creándose o editándose sin precios;
- no se acepta moneda manual por precio; la moneda se toma del producto.

## Cambios de vista

`app/Views/products/form.php` agrega:

- sección `Precios iniciales` al crear producto;
- tabla de `Precios actuales` al editar producto;
- sección `Actualizar precios por cambio de moneda` al editar productos que ya
  tienen precios.

`app/Views/products/detail.php` muestra una tabla simple de precios existentes.

No se agregan acciones de precio, historial, desactivación, reactivación ni
edición directa desde tabla.

## Estructura de `precios_iniciales`

```php
[
    [
        'lista_precio_id' => '1',
        'precio_lista' => '100.0000',
        'precio_minimo' => '80.0000',
    ],
]
```

Si se captura al menos una fila, el producto debe tener `moneda_id`.

La presencia de `lista_precio_id` por sí sola no inicia un precio: el
formulario la envía como dato estructural de cada lista activa. Si
`precio_lista` y `precio_minimo` llegan vacíos o `null`, la fila se omite y no
se convierte en `0`. Si cualquiera de los dos importes tiene contenido, la
fila se considera iniciada y se validan lista, precio de lista y precio mínimo.

## Estructura de `precios_cambio_moneda`

```php
[
    [
        'lista_precio_id' => '1',
        'precio_lista' => '20.0000',
        'precio_minimo' => '18.0000',
    ],
]
```

Solo se envían listas existentes del producto. Las listas existentes no
capturadas quedan bajo la regla backend ya aprobada: `0.0000 / 0.0000` y
`requiere_revision=1` al cambiar moneda.

## Permisos

Se usan permisos estructurales ya existentes:

- `precios.productos.crear` para capturar precios iniciales;
- `precios.productos.editar` para capturar precios por cambio de moneda;
- `precios.productos.ver` para mostrar precios existentes.

Si el usuario no tiene permiso de precios, conserva el flujo normal de producto
sin capturar ni modificar precios.

## DB-TEST

Runner:

```bash
php database/precios-producto-ui.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

Casos cubiertos:

- crear producto sin precios desde input UI;
- ignorar listas estructurales con importes vacíos o `null`;
- confirmar que vacío no se convierte en cero;
- crear producto con precios iniciales;
- crear historial `CREACION`;
- ignorar fila totalmente vacía;
- rechazar fila parcial;
- rechazar precio inicial sin moneda;
- rechazar precio mínimo mayor al precio de lista;
- rechazar listas duplicadas;
- editar sin cambio de moneda sin alterar precios;
- editar cambiando moneda y actualizar precio capturado;
- dejar lista no capturada en revisión con importes cero;
- rechazar fila parcial en cambio de moneda;
- cargar listas activas para formulario;
- exponer precios existentes para formulario/detalle;
- verificar que las vistas declaren las secciones mínimas.

## Regresiones

Requeridas para cierre:

- `database/precios.php db:test`
- `database/precios-service.php db:test`
- `database/precios-producto-integracion.php db:test`
- `database/precios-producto-ui.php db:test`
- `database/productos-imagen.php db:test`
- `database/productos-2.php db:test`
- `database/crud-productos.php functional:test`
- `database/inventario.php db:test`
- `database/inventario-service.php db:test`
- `database/series.php db:test`
- `database/folios-inventario.php db:test`

## Excepción conocida

`database/productos.php db:test` no se usa como bloqueo obligatorio porque la
base de prueba contiene un producto persistente real/no-QA:

- `102016169 | REFRIGERANTE R-410A 5KG IGAS`

No se elimina ni modifica.

## Pendientes

- pantalla global de precios;
- administración completa de listas;
- edición directa de precios;
- desactivar/reactivar precios;
- historial detallado desde UI;
- autorizaciones funcionales;
- ventas, cotizaciones, pedidos, remisiones y facturas.
