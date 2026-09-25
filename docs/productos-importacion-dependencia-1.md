# PRODUCTOS-IMPORTACION-DEPENDENCIA-1

## Estado y alcance

Esta fase audita la compatibilidad de lectores XLSX para el contrato aprobado
en `PRODUCTOS-IMPORTACION-CONTRATO-1`. No instala paquetes ni modifica
`composer.json`, `composer.lock`, `vendor/`, código, rutas, UI o base de datos.

Fecha de la auditoría: 2026-09-24.

## Resultado

```text
compatibility_result = OPENSPout_COMPATIBLE
recommended_package = openspout/openspout
recommended_version = 4.28.5
recommended_constraint = 4.28.5
HOSTING_PREREQUISITE_PENDING=true
installation_performed = false
```

OpenSpout `v4.28.5` es la última versión estable publicada que acepta PHP
8.2.12. Se recomienda un pin exacto, no `^4.28.5`, porque `v4.29.0` y las
versiones posteriores de la rama 4.x ya requieren PHP 8.3 o superior.

La selección técnica queda aprobada para desarrollo local. El futuro deploy
queda condicionado a verificar en la cuenta real de AwardSpace la versión PHP,
las extensiones requeridas y los límites efectivos de memoria/upload.

## Precheck del repositorio

- Rama: `main`.
- HEAD auditado: `b11bbaa docs(products): define product import contract`.
- Árbol versionable inicial: limpio.
- Staging inicial: vacío.
- Ignorados esperados: `.env`, `node_modules/`, storage privado/uploads y
  `vendor/`.

## Plataforma local

```text
PHP 8.2.12 (cli)
Zend Engine 4.2.12
ZTS
Windows 64 bits
Composer 2.9.7
```

`composer validate --strict` aprobó el manifiesto actual.

## Extensiones PHP locales

| Extensión | Disponible localmente |
| --- | --- |
| `zip` | sí |
| `xml` | sí |
| `xmlreader` | sí |
| `xmlwriter` | sí |
| `dom` | sí |
| `libxml` | sí |
| `fileinfo` | sí |
| `filter` | sí |
| `mbstring` | sí |
| `gd` | sí |
| `iconv` | sí |
| `simplexml` | sí |
| `zlib` | sí |
| `pdo_mysql` | sí |

No se instaló ni activó ninguna extensión durante esta fase.

## Composer actual

El proyecto declara PHP `>=8.2` y PHPMailer. El único paquete instalado y
bloqueado actualmente es:

```text
phpmailer/phpmailer 6.12.0
```

No están instalados:

- `openspout/openspout`;
- `phpoffice/phpspreadsheet`.

El bootstrap ya carga `vendor/autoload.php` cuando existe, por lo que cualquiera
de los dos candidatos usaría el autoload PSR-4 de Composer sin copiar clases al
árbol `app/`.

## Auditoría de OpenSpout

### Versiones relevantes

| Versión | Constraint PHP declarado | Compatibilidad con PHP 8.2.12 |
| --- | --- | --- |
| `v5.12.0` | `~8.4.0 || ~8.5.0 || ~8.6.0` | no |
| `v4.32.0` | `~8.3.0 || ~8.4.0 || ~8.5.0` | no |
| `v4.31.0` | `~8.3.0 || ~8.4.0 || ~8.5.0` | no |
| `v4.29.0` / `v4.29.1` | `~8.3.0 || ~8.4.0` | no |
| `v4.28.5` | `~8.2.0 || ~8.3.0 || ~8.4.0` | **sí** |
| `v4.24.5` | `~8.1.0 || ~8.2.0 || ~8.3.0` | sí, pero anterior |
| `v4.20.0` | `~8.1.0 || ~8.2.0` | sí, pero anterior |
| `v3.7.4` | hasta PHP 8.1 | no |

`v4.28.5` fue publicada el 2025-01-30. Packagist no reportó avisos de
seguridad para `openspout/openspout` al momento de esta auditoría. El proyecto
OpenSpout continúa activo, pero la etiqueta compatible con PHP 8.2 no es la
línea más reciente; fijarla implica asumir seguimiento periódico de avisos y
reevaluación al actualizar PHP.

### Requisitos de OpenSpout 4.28.5

```text
php: ~8.2.0 || ~8.3.0 || ~8.4.0
ext-dom: *
ext-fileinfo: *
ext-filter: *
ext-libxml: *
ext-xmlreader: *
ext-zip: *
```

No declara paquetes PHP transitivos de runtime. `ext-zlib` aparece solo como
dependencia de desarrollo. `mbstring` e `iconv` son sugerencias para CSV no
UTF-8, pero el contrato del ERP exige UTF-8 y CSV seguirá usando `fgetcsv`.

Todas las extensiones obligatorias de OpenSpout están disponibles localmente.

### Lectura, memoria y fórmulas

OpenSpout está orientado a lectura/escritura secuencial de CSV, XLSX y ODS. Su
README declara procesamiento de archivos grandes con memoria muy baja. Para el
límite del ERP —1000 filas y 5 MiB— encaja mejor que un modelo completo de
workbook en memoria.

En `v4.28.5`, el lector XLSX representa una celda con fórmula mediante
`OpenSpout\Common\Entity\Cell\FormulaCell`: conserva la expresión y, si existe,
su valor cacheado. No ejecuta un motor de cálculo. La implementación futura
debe detectar el tipo `FormulaCell` y rechazar la fila con
`formula_not_allowed`; no debe consumir el valor cacheado.

Fuentes primarias:

- <https://github.com/openspout/openspout>
- <https://github.com/openspout/openspout/tree/v4.28.5>
- <https://github.com/openspout/openspout/blob/v4.28.5/composer.json>
- <https://github.com/openspout/openspout/blob/v4.28.5/src/Reader/XLSX/Helper/CellValueFormatter.php>
- <https://packagist.org/packages/openspout/openspout#v4.28.5>

## Auditoría de PhpSpreadsheet

### Versión candidata

La versión estable auditada es `phpoffice/phpspreadsheet 5.10.0`:

```text
php: ^8.2
```

La rama actual declara mantenimiento de PHP 8.2 hasta el 30 de junio de 2027.
Los avisos publicados en Packagist al momento de esta auditoría afectan como
máximo la serie 5.x hasta `5.8.0`; `5.10.0` queda fuera de esos rangos.

### Extensiones requeridas por PhpSpreadsheet 5.10.0

```text
ext-ctype
ext-dom
ext-fileinfo
ext-filter
ext-gd
ext-iconv
ext-libxml
ext-mbstring
ext-simplexml
ext-xml
ext-xmlreader
ext-xmlwriter
ext-zip
ext-zlib
```

También requiere estos paquetes de runtime:

```text
composer/pcre
maennchen/zipstream-php
markbaker/complex
markbaker/matrix
psr/simple-cache
```

`ext-intl` y `ext-openssl` son sugerencias para funciones que esta importación
no necesita; no son requisitos obligatorios del paquete.

Todas las extensiones obligatorias están disponibles localmente. Su presencia
en AwardSpace no está confirmada.

### Lectura y memoria

PhpSpreadsheet representa un spreadsheet y sus celdas en memoria. Ofrece
`IReadFilter` y filtros por chunks para restringir qué filas carga, por lo que
puede procesar el archivo propuesto, pero exige más código y sigue teniendo un
modelo de objetos significativamente mayor. Para 1000 filas es viable en un
servidor con memoria suficiente, pero es una opción menos predecible en un
hosting compartido limitado.

Las fórmulas pueden cargarse. Una implementación segura tendría que detectar
celdas cuyo valor sea una expresión de fórmula y evitar cualquier llamada que
calcule valores. El alcance funcional adicional de PhpSpreadsheet no aporta
beneficio al importador v1.

Fuentes primarias:

- <https://github.com/PHPOffice/PhpSpreadsheet>
- <https://github.com/PHPOffice/PhpSpreadsheet/blob/5.10.0/composer.json>
- <https://phpspreadsheet.readthedocs.io/en/latest/topics/architecture/>
- <https://phpspreadsheet.readthedocs.io/en/latest/topics/reading-files/>
- <https://packagist.org/packages/phpoffice/phpspreadsheet#5.10.0>

## Comparación para PRODUCTOS-IMPORTACION-1

| Aspecto | OpenSpout 4.28.5 | PhpSpreadsheet 5.10.0 |
| --- | --- | --- |
| PHP 8.2.12 | Compatible | Compatible |
| Estado de versión | Última compatible; anterior a la línea actual | Versión estable actual auditada |
| Lectura XLSX | Secuencial/streaming nativa | Workbook en memoria con read filters/chunks |
| Memoria | Baja y predecible | Mayor; depende de celdas y objetos cargados |
| Dependencias PHP | Ningún paquete transitorio | Cinco paquetes transitivos |
| Extensiones | Seis obligatorias | Catorce obligatorias |
| Fórmulas | `FormulaCell`, detectable sin cálculo | Detectables, pero la biblioteca incluye motor de cálculo |
| Complejidad v1 | Menor | Mayor |
| Funciones no requeridas | Pocas | Escritura, estilos, gráficos, fórmulas y múltiples formatos |
| Riesgo principal | Pin anterior por límite PHP 8.2 | Memoria, superficie y requisitos en hosting |

Para lectura secuencial de hasta 1000 filas, sin fórmulas ni escritura XLSX,
OpenSpout 4.28.5 satisface el contrato con menor consumo y complejidad. No se
usa un scoring numérico porque los criterios determinantes son compatibilidad,
modelo de memoria, superficie de dependencias y capacidad de rechazar
fórmulas.

## Recomendación

Seleccionar `openspout/openspout` con pin exacto `4.28.5`.

La recomendación no significa que el paquete esté instalado ni que el deploy
esté autorizado. El pin debe reevaluarse si ocurre cualquiera de estos casos:

- el proyecto sube su mínimo efectivo a PHP 8.3 o superior;
- aparece un aviso de seguridad que afecte `4.28.5`;
- AwardSpace no ofrece alguna extensión obligatoria;
- la cuenta real usa una versión PHP fuera de `8.2.x`, `8.3.x` o `8.4.x`;
- las pruebas de memoria/ZIP revelan un riesgo no controlable.

## AwardSpace y prerequisitos pendientes

El documento de deploy del ERP declara expresamente que todavía no se han
verificado la cuenta, versión PHP, extensiones ni límites del plan real. La
documentación pública de AwardSpace permite consultar/cambiar versión PHP y,
según el plan, administrar extensiones, pero no prueba la configuración de esta
cuenta.

### Confirmado localmente

- PHP 8.2.12.
- `dom`, `fileinfo`, `filter`, `libxml`, `xmlreader` y `zip` activos.
- `pdo_mysql`, `xml`, `mbstring`, `gd`, `iconv`, `simplexml`, `xmlwriter` y
  `zlib` activos.

### Requerido por la opción recomendada

- PHP 8.2.x, 8.3.x o 8.4.x.
- `dom`.
- `fileinfo`.
- `filter`.
- `libxml`.
- `xmlreader`.
- `zip`.

### Pendiente en AwardSpace

```text
HOSTING_PREREQUISITE_PENDING=true
```

Se debe verificar en la cuenta real:

- versión PHP efectiva del dominio;
- `dom`;
- `fileinfo`;
- `filter`;
- `libxml`;
- `xmlreader`;
- `zip`;
- `upload_max_filesize`;
- `post_max_size`;
- `memory_limit`;
- disponibilidad de espacio/inodos para desplegar `vendor/`.

No se afirma que AwardSpace tenga esas extensiones activas. El deploy queda
bloqueado mientras esta matriz no se confirme.

Fuentes del hosting:

- <https://www.awardspace.com/kb/how-to-use-php-settings/>
- <https://www.awardspace.com/kb/do-you-support-the-latest-php-version/>
- <https://www.awardspace.com/wordpress-tutorials/post-content-length/>
- <https://www.awardspace.com/kb/maximum-php-memory-size-limit-that-awardspace/>

## Seguridad XLSX adicional

OpenSpout no sustituye la validación de seguridad del contenedor OOXML. Antes
de entregar el archivo a la librería, la implementación debe:

1. aceptar solo extensión `.xlsx`;
2. validar tamaño máximo de 5 MiB antes y después de mover el upload;
3. realizar la prevalidación ZIP comprobando MIME con `finfo` y firma ZIP;
4. abrir mediante `ZipArchive` con una ruta temporal privada aleatoria;
5. exigir estructura OOXML mínima (`[Content_Types].xml`, relaciones y
   workbook esperados);
6. limitar cantidad de entradas, suma de tamaños descomprimidos y razón de
   compresión antes de extraer/leer XML;
7. rechazar paths ZIP absolutos o con `..`, entradas duplicadas y cifrado;
8. rechazar contenido de macros, VBA y relaciones externas;
9. admitir una sola hoja con contenido y detenerse en la fila 1001;
10. rechazar cada `FormulaCell` sin usar su valor cacheado;
11. no extraer el workbook dentro de `public/`;
12. cerrar lector/ZIP y eliminar el temporal en `finally`.

La librería por sí sola no garantiza todos los límites anti-ZIP-bomb requeridos
por el contrato. Esos controles pertenecen al futuro adaptador seguro del ERP y
deben tener pruebas específicas con ZIP de alta compresión, rutas maliciosas,
entradas excesivas y XML corrupto.

También se mantienen los límites ya aprobados:

- máximo 5 MiB;
- máximo 1000 filas no vacías;
- XLSX solamente;
- rechazo de XLS, XLSM y ODS;
- upload temporal privado y cleanup;
- preview sin escrituras de negocio;
- CSRF, autenticación y permisos en la futura interfaz.

## CSV permanece sin dependencia

CSV continuará con `fgetcsv` streaming, UTF-8 estricto, BOM opcional, coma,
RFC 4180 y LF/CRLF. OpenSpout no se usará para CSV v1 y no se implementó ningún
lector CSV en esta fase.

## Comando futuro sugerido

Solo después de autorización explícita para instalar:

```powershell
composer require openspout/openspout:4.28.5
```

El constraint es exacto para impedir que una futura actualización seleccione
una versión 4.x que ya no admita PHP 8.2. No se ejecutó este comando.

La futura fase de instalación deberá capturar hashes previos, ejecutar el
comando localmente, revisar `composer.json` y `composer.lock`, verificar el
autoload, ejecutar `composer validate --strict`, `composer audit`, una prueba
real de apertura XLSX controlada y confirmar que no cambió PHPMailer.

## Estrategia futura de deploy/vendor

- Resolver y probar Composer localmente, nunca improvisar la resolución en
  AwardSpace.
- Versionar `composer.json` y `composer.lock` una vez aprobados.
- Mantener `vendor/` ignorado en Git.
- Preparar el artefacto con `composer install --no-dev --optimize-autoloader`
  sobre una plataforma compatible.
- Incluir en el paquete de deploy únicamente el `vendor/` resultante y aprobado
  si AwardSpace no ejecutará Composer.
- No subir caché de Composer, fuentes de prueba, `.env`, logs ni temporales.
- Verificar en staging/hosting el autoload y un XLSX benigno antes de habilitar
  la ruta de importación.

Esta estrategia no constituye autorización de deploy.

## Riesgos y mitigaciones

| Riesgo | Mitigación contractual |
| --- | --- |
| OpenSpout 4.28.5 es una etiqueta anterior | Pin exacto, `composer audit` y reevaluación al subir PHP |
| Extensiones AwardSpace desconocidas | Gate obligatorio de hosting antes de deploy |
| ZIP bomb o XML malicioso | Preflight ZIP/OOXML con límites antes del reader |
| Fórmulas y valor cacheado | Rechazar `FormulaCell` completo |
| Agotamiento de memoria | Streaming, 5 MiB, 1000 filas y corte temprano |
| Dependencia no reproducible | Lock versionado y vendor construido localmente |
| Cambio accidental de PHPMailer | Revisar lock y árbol de dependencias en instalación |
| Temporales abandonados | Storage privado, nombre aleatorio y cleanup en `finally` |

## Validaciones realizadas

- `git branch --show-current`.
- `git log --oneline -1`.
- `git status --short --ignored`.
- `git diff --name-only`.
- `git diff --cached --name-only`.
- `php -v`.
- `composer --version`.
- `composer validate --strict`.
- `php -m`.
- `composer show`.
- `composer show openspout/openspout --all`.
- consultas de requisitos para etiquetas estables de OpenSpout 3.x, 4.x y 5.x.
- `composer show phpoffice/phpspreadsheet --all`.
- revisión de requisitos/autoload y documentación primaria de ambos proyectos.
- consulta de avisos públicos de Packagist.

Todas fueron de solo lectura respecto del proyecto. No se ejecutó
`composer require`, `composer update`, `composer install` ni modificación de
extensiones.

## Rollback y siguiente fase

El rollback de esta fase consiste únicamente en retirar este documento. No hay
dependencia, código, vendor ni dato que revertir.

La siguiente fase recomendada, solo con autorización separada, es
`PRODUCTOS-IMPORTACION-DEPENDENCIA-INSTALACION-1`: instalar localmente el pin
exacto, auditar el lock y ejecutar una prueba mínima del lector. Su eventual
deploy seguirá bloqueado hasta confirmar los prerequisitos de AwardSpace.
