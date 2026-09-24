# PRODUCTOS-IMPORTACION-CONTRATO-1

## Estado y objetivo

Esta fase define el contrato de una futura importación masiva de productos
desde CSV y XLSX. Es una fase documental: no crea rutas, interfaz, servicios,
lectores, dependencias, migraciones, seeds ni datos.

La primera versión será exclusivamente `CREATE-ONLY` y creará catálogo maestro
global de productos. No actualizará productos existentes ni importará precios,
inventario, imágenes, tickets o correo.

## Evidencia auditada

El contrato se deriva de:

- las migraciones de catálogos, SAT, productos e identificadores;
- `ProductService`, `ProductRepository`, `ProductController` y el formulario
  actual de productos;
- los permisos y rutas actuales del CRUD;
- `composer.json`, `composer.lock` y `vendor/composer`;
- PHP CLI 8.2.12 y sus límites locales de carga.

No se consultaron ni modificaron datos operativos para elaborar este contrato.

## Dependencias y formatos

### Estado actual

`composer.lock` contiene únicamente `phpmailer/phpmailer` v6.12.0. No están
instalados OpenSpout, PhpSpreadsheet ni otro lector de hojas de cálculo. No
existe código lector de CSV o XLSX en el proyecto.

PHP dispone de lectura CSV nativa mediante `fgetcsv`, pero todavía no existe un
adaptador del ERP que implemente este contrato.

```text
spreadsheet_library_available = false
needs_new_dependency_for_xlsx = true
native_csv_runtime_available = true
```

### Decisión de dependencia futura

- CSV v1 debe leerse en streaming con primitivas nativas de PHP.
- XLSX v1 debe usar un lector streaming; OpenSpout es el candidato preferido.
- La dependencia XLSX requiere una microfase separada con autorización para
  modificar `composer.json`, `composer.lock` y `vendor/`.
- No se fija todavía una versión: el proyecto admite PHP `>=8.2` y el entorno
  local usa PHP 8.2.12, mientras que las ramas actuales 4.x y 5.x de OpenSpout
  requieren versiones de PHP superiores. La microfase debe comprobar además
  la versión y extensiones reales de AwardSpace antes de seleccionar una
  versión compatible y mantenida.
- La librería deberá cargarse por `vendor/autoload.php`; no se copiarán clases
  manualmente dentro de `app/`.

Fuentes de compatibilidad consultadas:

- <https://github.com/openspout/openspout>
- <https://github.com/openspout/openspout/blob/4.x/composer.json>
- <https://github.com/openspout/openspout/blob/5.x/composer.json>

## Esquema real del producto

`productos.id_producto` es la llave primaria natural. El alta actual persiste
los siguientes campos y relaciones:

| Campo persistido | Tipo/contrato | Alta actual |
| --- | --- | --- |
| `id_producto` | `VARCHAR(16) ascii_bin`, PK, `^[A-Z0-9]{1,16}$` | Obligatorio; normalizado a mayúsculas |
| `descripcion` | `VARCHAR(40)` | Obligatorio |
| `descripcion_larga` | `VARCHAR(255)` | Opcional |
| `sku` | `VARCHAR(40) ascii_bin`, único | Opcional; mayúsculas |
| `sku_alterno` | `VARCHAR(40) ascii_bin` | Opcional; mayúsculas |
| `upc` | `VARCHAR(14) ascii_bin` | Opcional; exactamente 12 dígitos |
| `ean` | `VARCHAR(14) ascii_bin` | Opcional; 8 o 13 dígitos |
| `gtin` | `VARCHAR(14) ascii_bin` | Opcional; 8, 12, 13 o 14 dígitos |
| `codigo_fabricante` | `VARCHAR(60) ascii_bin` | Opcional; mayúsculas |
| `modelo` | `VARCHAR(80)` | Opcional |
| `tipo_producto_id` | FK a `tipos_producto` | Obligatorio |
| `unidad_medida_id` | FK a `unidades_medida` | Obligatorio |
| `moneda_id` | FK a `monedas` | Opcional |
| `linea_producto_id` | FK a `lineas_producto` | Opcional |
| `marca_id` | FK a `marcas` | Opcional |
| `clasificacion_producto_id` | FK a `clasificaciones_producto` | Opcional |
| `clave_sat_id` | FK a `claves_sat` | Opcional |
| `unidad_sat_id` | FK a `unidades_sat` | Opcional |
| `peso_kg` | `DECIMAL(12,4)`, mayor que cero | Opcional |
| `largo_cm` | `DECIMAL(12,3)`, mayor que cero | Opcional |
| `ancho_cm` | `DECIMAL(12,3)`, mayor que cero | Opcional |
| `alto_cm` | `DECIMAL(12,3)`, mayor que cero | Opcional |
| `controla_series` | `TINYINT(1)` | `0` o `1`; default `0` |
| `controla_lotes` | `TINYINT(1)` | `0` o `1`; default `0` |
| `controla_pedimentos` | `TINYINT(1)` | `0` o `1`; default `0` |
| `activo` | `TINYINT(1)` | El repositorio fuerza `1` al crear |

La creación actual también puede sincronizar `producto_impuestos` y
`producto_codigos_barras` dentro de la transacción del producto.

No existe tabla ni campo `tipos_inventario`/`tipo_inventario`. El contrato real
usa el tipo de producto y las tres banderas de trazabilidad. Por tanto, la
importación no aceptará una columna inventada `tipo_inventario`.

## Identidad `id_producto`

- Se recibe como texto, se aplica `trim` y se normaliza a mayúsculas.
- El resultado debe contener entre 1 y 16 caracteres.
- Solo admite letras ASCII `A-Z` y números `0-9`.
- No admite espacios, guiones, acentos, símbolos ni cadena vacía.
- `ascii_bin`, el `CHECK` y la PK refuerzan sensibilidad y unicidad.
- La importación comparará duplicados después de normalizar. `abc1` y `ABC1`
  representan el mismo candidato y producirán conflicto interno.
- Un libro debe dar formato de texto a esta columna para no perder ceros
  iniciales ni convertirla a notación científica.

## Headers canónicos v1

La plantilla completa tendrá exactamente estos headers, en este orden:

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

El importador aceptará cualquier orden de columnas y permitirá omitir columnas
opcionales. Las cuatro columnas obligatorias siempre deben estar presentes.

### Columnas obligatorias

| Header | Regla |
| --- | --- |
| `id_producto` | Identidad normalizada y válida de 1 a 16 caracteres |
| `descripcion` | Texto no vacío, máximo 40 caracteres |
| `tipo_producto_codigo` | Código activo: `PRODUCTO`, `SERVICIO` o `KIT` |
| `unidad_medida_codigo` | Código activo de `unidades_medida` |

### Columnas opcionales

Todas las demás columnas de la plantilla son opcionales. Una celda vacía se
convierte a `NULL`, salvo las banderas, que toman `0`, y las listas, que toman
una lista vacía.

No se aceptan los headers `activo`, `tipo_inventario`, `precio_lista`,
`precio_minimo`, datos de almacén, existencias, imagen, URL, base64 o paths.
Todos los productos importados se crearán activos, igual que en el alta actual.

### Listas dentro de una celda

- `impuestos_codigos`: cero o más códigos activos separados por `|`.
- `codigos_barras`: cero o más códigos alfanuméricos separados por `|`.
- Se aplica `trim` a cada elemento y normalización a mayúsculas.
- Un elemento vacío, repetido o inválido produce error de fila.
- Cada código de barras admite 1 a 64 caracteres `A-Z0-9` y debe conservar la
  unicidad global existente.

El separador interno `|` evita conflicto con el delimitador CSV.

## Resolución de catálogos

La importación acepta códigos estables, nunca IDs numéricos internos:

| Header | Fuente | Identificador |
| --- | --- | --- |
| `tipo_producto_codigo` | `tipos_producto` | `codigo` |
| `unidad_medida_codigo` | `unidades_medida` | `codigo` |
| `moneda_codigo` | `monedas` | `codigo` |
| `linea_producto_codigo` | `lineas_producto` | `codigo` |
| `marca_codigo` | `marcas` | `codigo` |
| `clasificacion_producto_codigo` | `clasificaciones_producto` | `codigo` |
| `clave_sat_codigo` | `claves_sat` | `codigo` |
| `unidad_sat_codigo` | `unidades_sat` | `codigo` |
| `impuestos_codigos` | `impuestos` | lista de `codigo` |

Todos deben existir, estar activos y no estar eliminados lógicamente. Una
referencia inexistente o inactiva invalida la fila. La importación nunca crea,
reactiva o modifica catálogos automáticamente.

En particular, la importación no crea marcas, líneas de producto,
clasificaciones, unidades de medida, claves SAT, unidades SAT, impuestos,
tipos de producto ni monedas. Cada ausencia se reporta como error de la fila y
debe corregirse en el catálogo autorizado antes de volver a validar el archivo.

## Reglas funcionales por fila

- `sku`, `sku_alterno` y `codigo_fabricante` permiten letras, números y
  `.`, `_`, `-`, `/`, con los límites actuales de 40, 40 y 60 caracteres.
- `sku` conserva unicidad global. Los demás identificadores conservan las
  restricciones e índices actuales.
- `modelo` conserva mayúsculas, minúsculas, espacios y acentos; máximo 80.
- `descripcion` y `descripcion_larga` preservan contenido UTF-8, acentos y
  capitalización después de recortar espacios externos.
- UPC, EAN y GTIN se tratan como texto, nunca como números.
- Los decimales usan punto, nunca separador dependiente del locale. No se
  aceptan signo, agrupadores, cero ni negativos. Se conservan como cadenas
  decimales hasta llegar a PDO; no se convierten a `float`.
- `controla_series`, `controla_lotes` y `controla_pedimentos` aceptan solo
  `0`, `1` o vacío. Vacío equivale a `0`.
- Un `SERVICIO` exige físicos vacíos y las tres banderas en `0`.
- Impuestos y códigos de barras no pueden repetirse dentro de una fila.
- Ningún identificador único puede pertenecer a otro producto.

## Headers y filas

- La primera fila no vacía es la fila de encabezados.
- Se elimina un BOM UTF-8 únicamente del primer header.
- Para comparar headers se aplica `trim` y minúsculas; después deben coincidir
  exactamente con un nombre canónico.
- Headers duplicados después de normalizar producen error global.
- La ausencia de un header obligatorio produce error global.
- Cualquier header desconocido produce error global; no se ignora.
- Una fila con un número de celdas incompatible con sus headers es inválida.
- Una fila completamente vacía después de normalizar se ignora y no cuenta.
- Una fila parcialmente vacía se valida normalmente.
- La numeración de errores usa la fila lógica del archivo: header `1`, primer
  registro `2`.

## Modo `CREATE-ONLY`

- Si `id_producto` no existe y la fila es válida, es candidato a creación.
- Si ya existe, la fila recibe `product_already_exists`.
- Si se repite dentro del archivo después de normalizar, todas las apariciones
  implicadas reciben `duplicate_product_in_file`.
- Nunca se toma silenciosamente la primera o la última aparición.
- No se modifica, activa, desactiva ni completa un producto existente.
- Un modo `UPDATE` podría diseñarse después, con permiso, preview y contrato de
  campos modificables propios; queda fuera de PRODUCTOS-IMPORTACION-1.

## CSV v1

- Extensión permitida: `.csv`.
- Codificación única: UTF-8 válido; se tolera BOM UTF-8 inicial.
- Delimitador canónico: coma (`,`), sin autodetección por locale.
- Comillas dobles y escape por duplicación de comillas según RFC 4180.
- Se aceptan finales de línea LF y CRLF.
- Debe leerse registro por registro con `fgetcsv`, sin cargar todo el archivo.
- No se convierte silenciosamente Windows-1252, Latin-1 u otra codificación.
- Valores que comiencen con `=`, `+`, `-` o `@` se mantienen como texto y
  nunca se evalúan. Después se someten a la validación del campo.

## XLSX v1

- Extensión permitida: `.xlsx` únicamente.
- Se rechazará XLS, XLSM, ODS, ZIP arbitrario y cualquier archivo cifrado.
- Debe leerse en streaming.
- Solo se admite una hoja con contenido. Hojas adicionales con datos producen
  error para evitar información ignorada silenciosamente.
- La primera hoja con contenido debe seguir el mismo contrato de headers y
  filas que CSV.
- Celdas de fórmula producen `formula_not_allowed`; no se evalúa la fórmula ni
  se confía en su resultado cacheado.
- Identidades, códigos, UPC, EAN y GTIN deben estar formateados como texto.
- Se valida extensión, MIME y estructura OOXML real antes de abrir el lector.
- Se rechazan macros, relaciones externas y una estructura ZIP no esperada.

## Límites

- Tamaño máximo de archivo: `5 MiB` (`5 * 1024 * 1024` bytes).
- Máximo: `1000` filas de datos no vacías; la fila de headers no cuenta.
- Al detectar la fila 1001 se detiene la lectura con `row_limit_exceeded`.
- Para XLSX se debe limitar además el número de entradas ZIP, el tamaño total
  descomprimido y una razón de compresión anómala antes de procesar XML.
- Los límites se aplican durante lectura streaming, no solo después de cargar.

El PHP local permite actualmente cargas y POST de 40 MiB, memoria de 512 MiB
y tiene `fileinfo`, `xmlreader` y `zip`. Esto no prueba la configuración de
producción. AwardSpace documenta un máximo PHP predeterminado de 5 MB y que
`upload_max_filesize`, `post_max_size` y `memory_limit` dependen del plan y su
configuración. Antes de implementar o desplegar se deben verificar esos valores
en el hosting; `post_max_size` debe dejar margen por encima de los 5 MiB para el
envoltorio multipart.

Fuentes del hosting:

- <https://www.awardspace.com/wordpress-tutorials/post-content-length/>
- <https://www.awardspace.com/kb/maximum-php-memory-size-limit-that-awardspace/>

## Validación, preview y errores

La validación completa sucede antes de cualquier escritura:

1. recibir upload;
2. validar errores nativos de PHP y tamaño;
3. validar extensión, MIME y estructura;
4. abrir un lector streaming seguro;
5. normalizar y validar headers;
6. leer como máximo 1000 filas no vacías;
7. normalizar y validar todas las filas;
8. resolver catálogos activos por código;
9. detectar duplicados internos e identificadores globales;
10. detectar productos existentes;
11. construir el resultado y preview;
12. permitir confirmar solo cuando `invalid_rows = 0`.

Preview no inserta, actualiza ni elimina registros de negocio. Puede conservar
únicamente un archivo temporal privado y un manifiesto efímero ligado al
usuario/sesión, con digest, expiración corta y uso único. Ambos se eliminan al
cancelar, expirar o terminar. La confirmación debe verificar el mismo digest y
revalidar catálogos, conflictos y permisos para evitar cambios entre preview e
importación.

La estructura conceptual del resultado será:

```json
{
  "total_rows": 0,
  "valid_rows": 0,
  "invalid_rows": 0,
  "errors": [
    {
      "row": 7,
      "field": "marca_codigo",
      "code": "catalog_not_found",
      "message": "La marca XXXXX no existe o no está activa."
    }
  ]
}
```

Los errores globales de archivo o headers usan `row: null` y el campo afectado
cuando exista. Los mensajes nunca se reducen a “Archivo inválido” si se puede
identificar formato, fila o campo. El preview mostrará conteos, errores por
fila, catálogos no encontrados y como máximo las primeras 20 filas seguras; las
salidas HTML siempre deben escaparse.

## Atomicidad y arquitectura futura

Solo un archivo con cero filas inválidas podrá confirmarse. La escritura será:

```text
BEGIN
  revalidar permiso, digest, productos, identificadores y catálogos
  insertar todos los productos y relaciones permitidas
  registrar auditoría del lote
COMMIT
```

Cualquier error produce `ROLLBACK` completo. No existe importación parcial,
`skip errors`, reemplazo ni upsert en v1.

`ProductService::create()` abre actualmente su propia transacción y su
validador es privado. La implementación futura no debe invocarlo en un bucle
que confirme fila por fila. Debe extraer/reutilizar las reglas comunes y usar
un servicio de importación que coordine una sola transacción de lote mediante
repositorios PDO y prepared statements. Las restricciones únicas y FKs siguen
siendo la última barrera de concurrencia.

La auditoría debe registrar una operación de lote con actor, timestamp, digest,
nombre seguro, total solicitado, total insertado y resultado, sin guardar el
archivo ni valores sensibles. La ausencia actual de auditoría directa en el
alta de productos es deuda preexistente y no autoriza omitirla en una acción
masiva.

## Seguridad y autorización futuras

- Todas las rutas serán privadas con `AuthMiddleware`.
- Se reutilizarán conjuntamente `productos.acceder` y `productos.crear`.
- No se crea `productos.importar` en esta fase; solo se reconsiderará si se
  demuestra que el negocio necesita separar alta individual y masiva.
- Productos es un catálogo global; no se reciben ni confían `empresa_id` o
  `almacen_id`, y `UserScopeService` no aplica al alta maestra actual.
- Validar y confirmar serán POST con CSRF. Descargar plantilla podrá ser GET
  autenticado y autorizado.
- Ninguna validación de frontend sustituye la validación servidor.
- El upload se guardará con nombre aleatorio únicamente en storage privado,
  nunca bajo `public/`, sin reutilizar el nombre proporcionado por el usuario.
- El archivo temporal se abre con permisos mínimos y siempre se elimina en un
  bloque de limpieza, tanto en éxito como en error.
- Se valida tamaño real, extensión, MIME con `finfo`, firma/estructura y límites
  de contenido. No se ejecutan fórmulas, macros, enlaces o código.
- Errores y logs no muestran paths físicos, SQL, stack traces ni contenido
  completo de filas.
- La futura exportación CSV debe neutralizar Excel/CSV injection ante valores
  que comiencen con `=`, `+`, `-` o `@`; esa exportación queda fuera de v1.

Veredicto de seguridad del contrato: **aprobado con observaciones**. Antes de
implementar se deben cerrar compatibilidad de la librería XLSX, límites reales
de AwardSpace, protección ZIP y auditoría transaccional.

## UX futura

Ruta conceptual: `/productos/importar`.

El flujo administrativo será:

1. descargar plantilla CSV o XLSX;
2. elegir un archivo;
3. validar;
4. revisar preview y errores;
5. confirmar la importación completa;
6. revisar resultado final.

La pantalla debe usar el layout autenticado del ERP, instrucciones compactas,
resumen de límites, una tabla eficiente para preview, errores asociados a fila
y campo, y acciones primarias inequívocas. No debe usar diseño de landing,
tarjetas repetitivas ni ocultar fallos en tooltips. En móvil, la tabla puede
usar scroll horizontal contenido. No se crea UI, CSS ni JavaScript en esta
fase.

Las plantillas futuras se llamarán `plantilla-productos.csv` y
`plantilla-productos.xlsx` y contendrán los headers canónicos. No se generan en
esta fase.

## Exclusiones e invariantes

La importación v1 no debe:

- aceptar ni crear `precio_lista` o `precio_minimo`;
- insertar filas en `producto_precios`;
- insertar filas en `existencias_producto` o `movimientos_inventario`;
- crear existencias, movimientos, lotes, series, pedimentos o almacenes;
- cargar, descargar, enlazar o eliminar imágenes/documentos;
- crear o modificar tickets, outbox o correo;
- crear catálogos faltantes;
- cambiar productos existentes;
- tocar `102016169` ni sus precios;
- modificar los fixtures `QAIMG001`, `QAIMG002`, `QAPOFIX001`, `QAPOFIX002`,
  `QAPOFIX004`, `QAREIMG001`, `QAREIMG002` o `QASMTP-000001`.

## Plan QA de la implementación futura

1. CSV válido.
2. XLSX válido.
3. Archivo vacío.
4. Headers obligatorios faltantes.
5. Header desconocido.
6. Header duplicado después de normalizar.
7. Producto duplicado dentro del archivo, incluida diferencia de case.
8. Producto ya existente.
9. `id_producto` mayor a 16.
10. `id_producto` con espacios, guion, acento o símbolo.
11. Descripción vacía o mayor a 40.
12. Cada catálogo inexistente, inactivo o eliminado.
13. UTF-8 con acentos preservados.
14. UTF-8 con BOM.
15. CSV LF.
16. CSV CRLF y campos entre comillas.
17. Fila completamente vacía ignorada.
18. Fila parcial validada.
19. Exactamente 1000 filas.
20. Fila 1001 rechazada.
21. Archivo exactamente en el límite y archivo mayor a 5 MiB.
22. XLS, XLSM, ODS, ZIP, PHP renombrado y MIME falso rechazados.
23. XLSX corrupto, cifrado, con fórmula o estructura anómala rechazado.
24. Conflicto concurrente durante confirmación causa rollback completo.
25. Cero filas nuevas en `producto_precios`.
26. Cero cambios en existencias y movimientos.
27. Cero documentos/imágenes nuevas.
28. Cero tickets y cero filas de correo/outbox nuevas.
29. Producto protegido y fixtures persistentes intactos.
30. Archivo temporal eliminado y auditoría segura en éxito y error.
31. POST sin CSRF rechazado.
32. Usuario sin alguno de los dos permisos rechazado.
33. Reenvío del token de confirmación no duplica productos.
34. Fallo en la última fila deja cero productos del lote.

El futuro DB-TEST debe ejecutarse solo sobre una base exclusiva y descartable,
comparar conteos/fingerprints antes y después y revertir todos sus fixtures.

## Criterios de aceptación de este contrato

- Dependencia disponible/no disponible y riesgo de compatibilidad documentados.
- CSV y XLSX, límites, headers y normalización definidos.
- Campos obligatorios y opcionales derivados del alta real.
- Catálogos resueltos por código activo, sin altas implícitas.
- `CREATE-ONLY`, duplicados y existentes definidos.
- Preview sin escrituras de negocio y errores por fila/campo definidos.
- Confirmación atómica con rollback completo definida.
- Seguridad, permisos, CSRF, storage temporal y fórmulas cubiertos.
- Precios, inventario, imágenes, tickets y correo expresamente excluidos.
- Plan QA y protección del producto real documentados.

## Rollback documental y siguiente fase

El rollback de esta fase consiste únicamente en retirar este documento antes
de adoptarlo; no existe rollback de base de datos ni datos creados.

La siguiente fase recomendada, únicamente con autorización separada, es
`PRODUCTOS-IMPORTACION-DEPENDENCIA-1`: verificar PHP/extensiones en AwardSpace,
seleccionar y fijar una versión compatible del lector XLSX, instalarla
localmente y validar su autoload sin construir todavía rutas ni importación.
