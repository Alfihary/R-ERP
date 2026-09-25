# PRODUCTOS-IMPORTACION-LECTOR-SEGURO-1

## Estado y alcance

```text
phase_result=PASS
database_used=false
business_validation_used=false
smtp_used=false
HOSTING_PREREQUISITE_PENDING=true
```

Esta fase implementa únicamente la lectura técnica y segura de archivos CSV y
XLSX ya disponibles en una ubicación privada controlada. No crea productos,
no consulta catálogos, no determina existencia de productos, no genera preview
de negocio y no escribe en base de datos.

Fecha de validación: 2026-09-25.

## Arquitectura

### Dominio neutral

Ubicación: `app/Domain/Products/Import/`.

- `ProductImportFileReader`: contrato neutral `read(string $path)`.
- `ProductImportReadResult`: formato, headers, filas, total, warnings y
  metadatos técnicos.
- `ProductImportRow`: conserva `row_number` físico y valores asociados por
  header.
- `ProductImportTechnicalError`: error seguro con `code`, `message`, `row`,
  `field` y `context` controlado.
- `ProductImportReadException`: transporta el error técnico y conserva una
  excepción original solo como contexto interno.
- `ProductImportLimits`: concentra todos los límites técnicos.

Estas clases no conocen `ProductService`, `ProductRepository`, precios,
inventario, catálogos, tickets, correo ni PDO.

### Infraestructura

Ubicación: `app/Infrastructure/Import/`.

- `ProductImportReader`: valida path local, extensión, tamaño y MIME, y
  coordina el adaptador apropiado.
- `CsvProductImportFileReader`: lectura streaming con `fopen` y `fgetcsv`.
- `XlsxProductImportFileReader`: lectura secuencial con OpenSpout 4.28.5.
- `XlsxPrevalidator`: prevalidación ZIP/OOXML previa a OpenSpout.
- `ProductImportNormalizer`: normalización técnica de headers, UTF-8, tamaño
  de celdas y asociación header/valor.

El coordinador rechaza wrappers de streams, paths inexistentes, archivos no
legibles y archivos ubicados dentro de `public/`. El futuro servicio de upload
debe entregar un path privado controlado; esta API no recibe paths directos de
una petición HTTP.

## Límites técnicos

| Constante | Valor | Justificación |
| --- | ---: | --- |
| `MAX_FILE_BYTES` | 5 MiB | Contrato aprobado y corte antes de lectura pesada |
| `MAX_DATA_ROWS` | 1000 | Límite de filas no vacías, sin contar header |
| `MAX_CELL_BYTES` | 65,535 bytes | Tolera texto amplio sin aceptar celdas gigantes |
| `MAX_ZIP_ENTRIES` | 2,048 | Suficiente para un XLSX pequeño y evita entradas ilimitadas |
| `MAX_UNCOMPRESSED_BYTES` | 50 MiB | Máximo 10 veces el archivo permitido |
| `MAX_COMPRESSION_RATIO` | 100:1 | Detecta expansión anormal por entrada |
| `MAX_XML_ENTRY_BYTES` | 2 MiB | Evita cargar XML técnico desproporcionado |

El conteo de filas se detiene y devuelve `row_limit_exceeded` en la fila no
vacía 1001. El tamaño de archivo se valida antes de MIME, ZIP u OpenSpout.

## Formatos y MIME

Solo se aceptan extensiones `.csv` y `.xlsx`. Se rechazan `.xls`, `.xlsm`,
`.ods`, `.xml`, `.zip`, `.txt`, `.php` y cualquier otra extensión.

La extensión nunca es evidencia suficiente:

- CSV admite MIME textuales habituales y `application/octet-stream` como
  señal genérica; después exige estructura CSV y UTF-8 válido.
- XLSX admite el MIME oficial, MIME ZIP habituales y
  `application/octet-stream`; después exige firma ZIP y estructura OOXML.

Así se tolera que Windows reporte un XLSX válido como ZIP genérico sin aceptar
un archivo falso renombrado.

## CSV

- Apertura binaria de solo lectura.
- Procesamiento secuencial con `fgetcsv`.
- Delimitador coma, enclosure comillas dobles y escape vacío explícito.
- Soporte LF y CRLF.
- Primera fila no vacía como headers.
- BOM UTF-8 removido únicamente al inicio del primer header.
- UTF-8 estricto mediante `mb_check_encoding`; no existe conversión silenciosa
  desde Windows-1252.
- Filas completamente vacías ignoradas sin perder la numeración física.
- Valores que comienzan con `=`, `+`, `-` o `@` se conservan literalmente como
  texto y nunca se ejecutan ni interpretan.

Cada registro conserva su número de línea física, incluso cuando existen filas
vacías intermedias o valores CSV multilínea.

## Headers técnicos

La normalización determinista aplica:

1. BOM UTF-8 solo al primer header;
2. `trim` externo;
3. minúsculas UTF-8;
4. ningún cambio de acentos;
5. ningún reemplazo arbitrario de espacios internos.

Los headers vacíos entre columnas activas producen `empty_header`. Los headers
duplicados después de normalizar producen `duplicate_header`. Las columnas
vacías finales se ignoran. Esta fase no comprueba todavía headers obligatorios,
desconocidos o canónicos de productos.

## Filas y valores neutrales

Cada fila se representa como:

```text
row_number: int
values: array<header, string>
```

Los valores CSV se preservan como texto UTF-8. Los valores XLSX simples se
convierten de forma determinista a texto: strings se conservan, booleanos usan
`1`/`0`, enteros usan representación decimal y floats se serializan sin usar el
locale. No se habilita el formateo automático de fechas.

Un identificador almacenado como texto, por ejemplo `00123`, conserva sus
ceros iniciales. Si Excel ya guardó un identificador como número, los ceros se
perdieron antes del lector; la plantilla futura debe marcar esos campos como
texto.

Las filas con celdas finales omitidas se completan con texto vacío. Una fila
con columnas activas adicionales produce `row_column_count_mismatch`.

## Prevalidación ZIP y OOXML

`XlsxPrevalidator` se ejecuta antes de OpenSpout y comprueba:

- firma `PK` de archivo ZIP;
- apertura de solo lectura con `ZipArchive`;
- límite de entradas;
- suma de tamaños descomprimidos declarados;
- ratio de compresión por entrada, evitando división por cero;
- cifrado mediante `encryption_method` cuando la extensión lo expone;
- nombres duplicados después de normalización y comparación sin distinguir
  mayúsculas;
- paths absolutos, drive letters, NUL, `../`, `..\\` y escapes lógicos;
- `[Content_Types].xml`;
- `_rels/.rels`;
- `xl/workbook.xml`;
- `xl/_rels/workbook.xml.rels`;
- al menos una entrada `xl/worksheets/*.xml`;
- XML válido, sin `DOCTYPE` y con `LIBXML_NONET`;
- content type de workbook XLSX no macro;
- relaciones de office document y worksheet requeridas.

No se extrae físicamente el ZIP ni se descomprime el workbook completo para la
prevalidación.

## Macros, cifrado y relaciones externas

Se rechazan:

- `vbaProject.bin` y rutas VBA;
- content types macro-enabled o VBA;
- relaciones con `TargetMode="External"`;
- relaciones o targets de `externalLink`;
- archivos `xl/externalLinks/*`;
- entradas cifradas o protegidas.

Códigos principales:

```text
xlsx_macro_not_allowed
xlsx_external_relationship_not_allowed
xlsx_encrypted_not_supported
xlsx_unsafe_zip_path
xlsx_duplicate_zip_entry
xlsx_zip_bomb_suspected
```

## Lectura XLSX y fórmulas

El lector usa exactamente `openspout/openspout 4.28.5` y su API instalada:

```text
OpenSpout\Reader\XLSX\Reader
OpenSpout\Common\Entity\Cell\FormulaCell
```

La lectura es secuencial y conserva filas vacías durante la iteración para
mantener el número físico original. OpenSpout se cierra en `finally` y se
liberan ciclos antes de que el caller intente eliminar el archivo en Windows.

Cualquier instancia de `FormulaCell` rechaza el archivo completo con
`xlsx_formula_not_allowed`. No se consume la fórmula, el resultado calculado
ni el valor cacheado.

## Hojas

Se acepta exactamente una hoja con contenido. Pueden existir hojas vacías antes
o después. El lector no presupone que `Sheet1` sea la hoja válida.

Si existe una segunda hoja con contenido, devuelve:

```text
xlsx_multiple_non_empty_sheets
```

No se fusionan hojas.

## Modelo de errores

Formato neutral:

```text
code: string
message: string seguro
row: null|int
field: null|string
context: array controlado
```

Los mensajes no incluyen paths absolutos, stack traces, SQL, DSN, secretos ni
detalles internos de ZipArchive/OpenSpout. Las excepciones de infraestructura
se mapean a errores técnicos; la excepción original queda únicamente como
`previous` interno.

Archivos vacíos, ilegibles, ZIP corruptos, OOXML incompleto y XML inválido
terminan en errores controlados, no en fatales expuestos a UI.

## Runner y pruebas

Comando:

```powershell
php database/productos-importacion-reader.php functional:test
```

El runner es CLI-only, carga únicamente `bootstrap/autoload.php`, no carga
`.env`, no usa `bootstrap/database.php` y no recibe credenciales.

Los fixtures se generan bajo un directorio aleatorio en `storage/temp/` y se
eliminan al terminar, incluso ante excepción. No se versionan binarios XLSX.

Casos cubiertos:

- CSV válido, BOM, LF, CRLF, acentos y solo headers;
- UTF-8 inválido, vacío, header duplicado y fila vacía;
- 1001 filas, archivo mayor de 5 MiB y celda sobredimensionada;
- extensión no permitida y archivo falso renombrado;
- XLSX válido y conservación de `00123` textual;
- hojas vacías toleradas y dos hojas con contenido rechazadas;
- fórmula y fórmula con resultado cacheado rechazadas;
- ZIP inválido y OOXML incompleto;
- macro, relación externa, traversal y duplicado normalizado;
- ratio ZIP bomb, límite de entradas y cifrado;
- ausencia de PDO, `ConnectionProvider`, `ProductRepository`,
  `ProductService` y SMTP;
- ausencia de temporales residuales.

Resultado validado:

```text
status=PASS
database_used=false
smtp_used=false
business_files_processed=false
cleanup=complete
```

## Fuera de alcance confirmado

- No ProductService ni ProductRepository.
- No consultas SQL, transacciones o PDO.
- No catálogos ni resolución de IDs.
- No creación o modificación de productos.
- No precios, inventario, imágenes, tickets o correo.
- No rutas, controllers, vistas o preview.
- No SMTP.
- No deploy.

## Hosting pendiente

```text
HOSTING_PREREQUISITE_PENDING=true
```

La prueba local no confirma AwardSpace. Antes de deploy siguen pendientes PHP,
`dom`, `fileinfo`, `filter`, `libxml`, `xmlreader`, `zip`, límites de upload y
memoria, espacio/inodos y empaquetado de `vendor/`.

## Siguiente fase recomendada

Solo con autorización separada: `PRODUCTOS-IMPORTACION-VALIDADOR-NEGOCIO-1`,
para validar headers canónicos, campos de producto y catálogos sin crear datos.
No debe iniciarse preview ni persistencia como efecto de esta fase.
