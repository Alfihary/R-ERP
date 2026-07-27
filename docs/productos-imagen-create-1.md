# PRODUCTOS-IMAGEN-CREATE-1

## Alcance

Esta fase permite seleccionar y guardar la imagen principal durante la creación
del producto. No crea proveedores, compras, ventas, CFDI, costos, precios,
lotes ni pedimentos. Tampoco modifica inventario ni folios.

## Flujo anterior

Antes de esta fase, el producto debía guardarse primero y la imagen principal
se cargaba después desde edición. Ese flujo sigue vigente para reemplazar o
eliminar imagen.

## Nuevo flujo crear con imagen

En `POST /productos`, el controlador acepta un archivo opcional `imagen`.

Cuando viene imagen:

1. La imagen se valida antes de insertar el producto.
2. El producto se crea dentro de una transacción.
3. Se insertan impuestos y códigos adicionales.
4. Se mueve el archivo a almacenamiento privado.
5. Se registra `producto_documentos` como `FOTO_PRINCIPAL`.
6. Se confirma la transacción.

Si la imagen es válida, el detalle y edición del producto muestran la imagen
principal inmediatamente después de crear.

## Flujo crear sin imagen

Crear producto sin archivo sigue funcionando igual que antes. No se crea
registro en `producto_documentos`.

## Edición, reemplazo y eliminación

El flujo existente de edición se conserva:

- reemplazar imagen principal desde edición;
- eliminar imagen principal desde edición;
- servir imagen desde endpoint autenticado.

## Seguridad de archivos

Se conserva la seguridad existente:

- solo JPG, PNG o WEBP;
- máximo 5 MiB;
- MIME real validado con `finfo`;
- contenido validado con `getimagesize`;
- SVG rechazado;
- archivos privados fuera de `public/`;
- entrega por endpoint autenticado;
- sin impresión de rutas internas de storage.

## Rollback y limpieza

La operación de creación con imagen evita consumos parciales:

- si la imagen falla, no se crea producto;
- si los datos del producto fallan, no se guarda imagen;
- si falla después de mover archivo, se revierte DB y se borra el archivo;
- no deben quedar archivos huérfanos ni `producto_documentos` huérfanos.

## Pruebas

La cobertura de `productos_imagen_1_test.php` valida:

- crear producto sin imagen;
- crear producto con imagen válida;
- crear producto con imagen inválida;
- crear producto con datos inválidos e imagen válida;
- rollback tras guardar archivo;
- reemplazar imagen desde edición;
- eliminar imagen desde edición;
- rechazos de SVG, falso MIME, archivo no imagen y tamaño excedido;
- limpieza QA de productos, documentos, códigos, impuestos y archivos.

## Regresiones

Debe seguir pasando:

- `database/productos-2.php db:test`;
- `database/crud-productos.php functional:test`;
- `database/inventario.php db:test`;
- `database/inventario-service.php db:test`;
- `database/series.php db:test`;
- `database/folios-inventario.php db:test`.

### Excepción aceptada para `database/productos.php`

`database/productos.php db:test` no aplica sobre
`r_erp_db_core_0_test` mientras existan productos persistentes reales/no-QA,
porque ese runner histórico exige tablas persistentes de productos vacías.

Producto persistente detectado:

- `102016169 | REFRIGERANTE R-410A 5KG IGAS`.

Ese producto no se eliminó porque no es dato QA claramente transitorio.

La cobertura relevante de PRODUCTOS-IMAGEN-CREATE-1 queda cubierta por:

- `database/productos-imagen.php db:test`;
- `database/productos-2.php db:test`;
- `database/crud-productos.php functional:test`;
- `database/inventario.php db:test`;
- `database/inventario-service.php db:test`;
- `database/series.php db:test`;
- `database/folios-inventario.php db:test`.

## Pendientes fuera de esta fase

Quedan fuera:

- proveedores;
- compras;
- ventas;
- CFDI;
- costos;
- precios;
- lotes;
- pedimentos.
