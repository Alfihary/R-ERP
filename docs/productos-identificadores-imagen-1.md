# PRODUCTOS-IDENTIFICADORES-IMAGEN-1

## Alcance

Esta fase completa dos puntos del módulo de Productos:

- Identificadores explícitos del producto.
- Flujo visual más claro para imagen principal.

No crea proveedores, compras, ventas, CFDI, costos, precios, lotes ni
pedimentos.

## Campos agregados

La migración `productos_identificadores_imagen_1_001_add_product_identifiers`
agrega a `productos`:

- `sku VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL`
- `sku_alterno VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL`
- `upc VARCHAR(14) CHARACTER SET ascii COLLATE ascii_bin NULL`
- `ean VARCHAR(14) CHARACTER SET ascii COLLATE ascii_bin NULL`
- `gtin VARCHAR(14) CHARACTER SET ascii COLLATE ascii_bin NULL`
- `codigo_fabricante VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NULL`
- `modelo VARCHAR(80) NULL`

`sku` tiene índice único cuando no es `NULL`. Los demás campos tienen índices
de búsqueda.

## `id_producto` y SKU

`id_producto` sigue siendo la llave primaria natural del ERP. No se reemplaza
por SKU, no se convierte SKU en primary key y no se agrega una identidad
alternativa.

El SKU es un identificador interno o comercial principal. Puede cambiar según
decisiones comerciales; por eso no sustituye a `id_producto`.

## SKU, UPC, EAN y GTIN

- `sku`: identificador interno/comercial principal.
- `sku_alterno`: identificador alterno opcional.
- `upc`: código UPC formal de 12 dígitos.
- `ean`: código EAN formal de 8 o 13 dígitos.
- `gtin`: identificador global de 8, 12, 13 o 14 dígitos.

## Código fabricante y modelo

`codigo_fabricante` guarda el código publicado o usado por el fabricante. Se
normaliza a mayúsculas y permite separadores seguros: punto, guion bajo, guion
medio y diagonal.

`modelo` guarda texto descriptivo del modelo, presentación o referencia del
fabricante. Se recorta con `trim()` y no se fuerza a mayúsculas.

## Códigos adicionales

`producto_codigos_barras` se conserva para:

- códigos adicionales;
- códigos secundarios;
- códigos antiguos;
- códigos de empaque;
- códigos de presentación;
- códigos escaneables extra.

No reemplaza a SKU, UPC, EAN ni GTIN principales.

## Imagen principal

La imagen principal ya se almacena mediante `producto_documentos` y
`ProductImageService`. Los archivos siguen fuera de `public/` y se sirven por
ruta autenticada.

Validaciones conservadas:

- JPG, PNG o WEBP.
- Tamaño máximo de 5 MiB.
- MIME real mediante `finfo`.
- Contenido decodificable como imagen.

## Flujo crear/editar imagen

En crear producto se muestra una sección visible de imagen principal, pero no
se permite subir archivo todavía porque el producto aún no existe. La vista
explica que primero debe guardarse el producto y después subir la imagen desde
edición.

En editar producto se muestra:

- imagen actual o placeholder;
- campo para seleccionar archivo;
- acción para subir o reemplazar;
- acción para eliminar si existe imagen;
- restricciones visibles del archivo.

En detalle se muestra la imagen principal o un estado claro de “Sin imagen”.

## Validaciones

El servicio de productos:

- normaliza `sku`, `sku_alterno` y `codigo_fabricante` a mayúsculas;
- aplica `trim()` a todos los identificadores;
- convierte valores vacíos a `NULL`;
- valida longitud y formato;
- valida unicidad de `sku`;
- valida UPC, EAN y GTIN como numéricos con longitudes permitidas;
- conserva `id_producto` inmutable en edición;
- conserva impuestos, códigos adicionales y reglas de trazabilidad existentes.

La base de datos replica restricciones críticas mediante `CHECK` e índices.

## Regresiones

La fase debe conservar:

- DB-PRODUCTOS-1.
- DB-PRODUCTOS-2.
- CRUD-PRODUCTOS-1.
- Inventario.
- Inventario service.
- Series.
- Folios de inventario.

## Pendiente para proveedores/compras

Queda fuera de esta fase:

- proveedores;
- relación producto-proveedor;
- proveedor preferido;
- código de proveedor;
- unidad de compra;
- conversión de unidades;
- costos;
- precios;
- recepción de compras;
- lotes;
- pedimentos.
