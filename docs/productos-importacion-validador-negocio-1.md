# PRODUCTOS-IMPORTACION-VALIDADOR-NEGOCIO-1

## Objetivo y alcance

Esta fase incorpora una validación de negocio separada que recibe el
`ProductImportReadResult` neutral del lector seguro. No relee archivos, no
persiste productos y no implementa rutas, upload HTTP ni preview visual.

El modo es exclusivamente `CREATE-ONLY`: un producto existente produce
`product_already_exists`; nunca se actualiza, activa, desactiva ni completa.

## Arquitectura

- `ProductImportBusinessValidator` normaliza y valida headers y filas.
- `ProductImportBusinessLookup` es el puerto read-only del dominio.
- `PdoProductImportBusinessLookup` resuelve catálogos, productos, SKU y códigos
  de barras con consultas PDO preparadas y agrupadas.
- `ProductImportValidationResult`, `ProductImportValidatedRow` y
  `ProductImportValidationError` forman la salida estructurada para una futura
  etapa de preview.

El lector CSV/XLSX no conoce repositorios. El validador no conoce detalles de
PDO y no ejecuta SQL.

## Headers

Los headers canónicos son:

```text
id_producto
descripcion
descripcion_larga
sku
sku_alterno
upc
ean
gtin
codigo_fabricante
modelo
tipo_producto_codigo
unidad_medida_codigo
moneda_codigo
linea_producto_codigo
marca_codigo
clasificacion_producto_codigo
clave_sat_codigo
unidad_sat_codigo
peso_kg
largo_cm
ancho_cm
alto_cm
controla_series
controla_lotes
controla_pedimentos
impuestos_codigos
codigos_barras
```

Son obligatorios `id_producto`, `descripcion`, `tipo_producto_codigo` y
`unidad_medida_codigo`. La ausencia genera `missing_required_header` global.
Todo header no incluido genera `unknown_header`; esto rechaza expresamente
`activo`, `tipo_inventario`, `precio_lista`, `precio_minimo`, `existencia`,
`imagen`, `url_imagen`, `path` y `base64`.

## Reglas de campos

- `id_producto`: `trim`, mayúsculas y `^[A-Z0-9]{1,16}$`. Duplicados se
  comparan después de normalizar, por lo que `abc1` y `ABC1` son iguales.
- `descripcion`: obligatoria, máximo 40 caracteres UTF-8.
- `descripcion_larga`: opcional, máximo 255 caracteres UTF-8.
- `sku` y `sku_alterno`: máximo 40; `codigo_fabricante`: máximo 60. Admiten
  `A-Z`, `0-9`, `.`, `_`, `-` y `/`; se normalizan a mayúsculas.
- UPC admite 12 dígitos; EAN 8 o 13; GTIN 8, 12, 13 o 14.
- `modelo`: texto opcional de hasta 80 caracteres.
- peso usa `DECIMAL(12,4)` y dimensiones `DECIMAL(12,3)`. Se conservan como
  string, deben ser positivas, sin coma ni agrupadores y respetar precisión.
- los controles aceptan exclusivamente `0`, `1` o vacío; vacío se normaliza a
  `0`. Un `SERVICIO` no admite físicos ni controles activos.

## Catálogos y listas

Los catálogos se reciben por código estable, nunca por ID. Deben existir,
estar activos y no eliminados. Esto aplica a tipo, unidad, moneda, línea,
marca, clasificación, clave SAT, unidad SAT e impuestos. No se exige que una
clasificación sea hoja porque el CRUD actual no contiene esa restricción.

`impuestos_codigos` y `codigos_barras` usan `|`, aplican `trim` por elemento y
mayúsculas, y rechazan elementos vacíos o repetidos. Los códigos de barras
admiten `^[A-Z0-9]{1,64}$` y se comprueba su unicidad persistida y dentro del
archivo.

## Consultas por lote y solo lectura

Antes de validar filas se reúnen los códigos únicos por catálogo, IDs de
producto, SKU y códigos de barras. El adaptador ejecuta como máximo una
consulta por catálogo utilizado y una consulta por cada conjunto de conflictos;
no existe una consulta por celda o por fila.

El adaptador contiene únicamente `SELECT` preparados. La suite toma hashes
antes y después de `productos`, `producto_precios`, `existencias_producto`,
`movimientos_inventario`, `tickets_productos` y
`tickets_productos_correos`. También compara por separado el producto
`102016169`, sus precios y su inventario. El resultado esperado es
`DB_WRITES=0`.

## Modelo de errores y resultado

Cada error contiene `row`, `field`, `code`, `message` y `context`. Los errores
de estructura usan `row = null`. Los mensajes no exponen SQL, DSN, rutas,
credenciales ni stack traces. Una fila acumula todos los errores razonables.

La salida contiene `total_rows`, `valid_rows`, `invalid_rows`, `rows` y
`errors`. Cada fila conserva su número, datos normalizados, referencias
internas de catálogo resueltas y estado `valid` o `invalid`. No se persiste.

## Pruebas

Ejecutar exclusivamente sobre la base descartable autorizada:

```text
php database/productos-importacion-validacion.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

El runner exige CLI, doble confirmación exacta, coincidencia con
`APP_DB_NAME` y `APP_ENV` distinto de producción. La suite cubre más de 50
aserciones funcionales, consultas batch, cero escrituras e integridad.

Regresiones requeridas:

```text
php database/productos-importacion-reader.php functional:test
php database/productos-2.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/crud-productos.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/precios-producto-integracion.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/inventario-service.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

## Exclusiones y siguiente fase

No se crean productos, precios, inventario, imágenes, tickets, correo ni
catálogos. No hay migraciones, seeds, rutas, controllers, views, SMTP, push ni
deploy. `HOSTING_PREREQUISITE_PENDING=true` continúa vigente.

La siguiente fase puede diseñar el preview privado y efímero, con permiso,
CSRF, digest, expiración y revalidación; no queda iniciada por este documento.
