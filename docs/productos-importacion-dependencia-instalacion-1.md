# PRODUCTOS-IMPORTACION-DEPENDENCIA-INSTALACION-1

## Estado

```text
installation_result=INSTALLED_LOCALLY
phase_gate=PASS
COMPOSER_VALIDATE=PASS
COMPOSER_VALIDATE_STRICT_EXIT=1
COMPOSER_STRICT_EXCEPTION=APPROVED
EXCEPTION_REASON=exact OpenSpout 4.28.5 pin intentionally preserves PHP 8.2 compatibility
HOSTING_PREREQUISITE_PENDING=true
```

La dependencia aprobada quedó instalada localmente en su versión exacta. La
fase se clasifica como PASS bajo la excepción controlada y explícitamente
aprobada para la única advertencia de `composer validate --strict` contra
constraints exactos. El pin `4.28.5` es obligatorio por contrato y no se
cambió ni se suavizó para ocultar la advertencia.

No se autoriza deploy en AwardSpace. La compatibilidad del hosting real sigue
pendiente de verificación.

## Fecha y base

- Fecha: 2026-09-25.
- Rama: `main`.
- HEAD base: `1f858a9 docs(products): define XLSX dependency compatibility`.
- Worktree versionable inicial: limpio.
- Staging inicial: vacío.

## Plataforma local

- PHP: `8.2.12 (cli)`, ZTS, Windows x64.
- Composer: `2.9.7`.
- Extensiones requeridas presentes: `dom`, `fileinfo`, `filter`, `libxml`,
  `xmlreader` y `zip`.
- Extensión adicional registrada: `mbstring`.
- No se instalaron ni modificaron extensiones PHP.

## Estado Composer previo

Antes de instalar:

- `phpmailer/phpmailer`: `6.12.0`.
- `openspout/openspout`: ausente.
- `composer validate --strict`: válido sin advertencias.

Hashes SHA256 previos:

```text
composer.json=830AC5BFFEC94F9FD43A08C62718E79D97A82895B107D6FE85454645DC15724F
composer.lock=FAEE9180F21072E5E46CFBA210E8B2E18D98B359F2AB4A95C2471AD8A80D418D
```

## Instalación ejecutada

Comando exacto:

```powershell
composer require openspout/openspout:4.28.5
```

Resolución obtenida:

```text
Lock file operations: 1 install, 0 updates, 0 removals
openspout/openspout=v4.28.5
```

No se usó `^4.28.5`, `~4.28.5`, `4.*`, `dev-main` ni `latest`.

## composer.json

La restricción generada por Composer es exacta:

```json
"openspout/openspout": "4.28.5"
```

`composer.json` no se editó manualmente.

## composer.lock

El lock registra:

```text
name=openspout/openspout
version=v4.28.5
php=~8.2.0 || ~8.3.0 || ~8.4.0
```

Extensiones requeridas por el paquete:

- `ext-dom`;
- `ext-fileinfo`;
- `ext-filter`;
- `ext-libxml`;
- `ext-xmlreader`;
- `ext-zip`.

`composer.lock` no se editó manualmente.

## Dependencias transitivas

OpenSpout no agregó paquetes PHP transitivos de ejecución. El árbol instalado
solo declara PHP y las extensiones anteriores. El conjunto final de paquetes
PHP es:

- `openspout/openspout 4.28.5`;
- `phpmailer/phpmailer 6.12.0`.

## Autoload y smoke tests

Las pruebas se ejecutaron con `bootstrap/autoload.php`, sin arrancar la
aplicación completa, abrir sesiones, conectar a DB ni enviar correo.

```text
VENDOR_AUTOLOAD=PASS
COMPOSER_AUTOLOAD_AVAILABLE=true
APP_AUTOLOAD=PASS
PHPMAILER_AUTOLOAD=PASS
OPENSPout_AUTOLOAD=PASS
```

Clases verificadas:

- ERP: `App\Core\App`.
- PHPMailer: `PHPMailer\PHPMailer\PHPMailer`.
- OpenSpout: `OpenSpout\Reader\XLSX\Reader`.

`vendor/autoload.php` existe y carga correctamente. El autoloader propio para
`App\` continúa funcionando.

## FormulaCell

FQCN real instalado:

```text
OpenSpout\Common\Entity\Cell\FormulaCell
```

`class_exists()` devolvió PASS. En esta fase no se implementó el rechazo de
fórmulas ni el procesamiento de archivos XLSX.

## ZipArchive

```text
ZIPARCHIVE=PASS
```

`ZipArchive` está disponible en la plataforma local.

## Composer audit

Comando:

```powershell
composer audit
```

Resultado:

```text
No security vulnerability advisories found.
```

No se realizó ninguna actualización automática.

## Validación y dry-run

`composer validate` devolvió código 0:

```text
COMPOSER_VALIDATE=PASS
```

El archivo `composer.json` y su lock son válidos. La validación normal muestra
la recomendación genérica sobre constraints exactos, pero no la trata como
error y no reporta ninguna otra observación.

`composer install --dry-run` devolvió código 0:

```text
Verifying lock file contents can be installed on current platform.
Nothing to install, update or remove
```

Esto confirma que `composer.json`, `composer.lock` y la plataforma local están
sincronizados.

## Excepción controlada de composer validate --strict

```text
COMPOSER_VALIDATE_STRICT_EXIT=1
COMPOSER_STRICT_EXCEPTION=APPROVED
EXCEPTION_REASON=exact OpenSpout 4.28.5 pin intentionally preserves PHP 8.2 compatibility
```

`composer validate --strict` confirmó que el JSON y el lock son válidos, pero
devolvió código 1 con esta única advertencia:

```text
require.openspout/openspout: exact version constraints (4.28.5) should be avoided if the package follows semantic versioning
```

La advertencia fue revisada, no fue ocultada y no representa una vulnerabilidad,
una inconsistencia de lock ni un fallo de instalación. No se cambiará el
constraint a un rango. Cualquier warning o error adicional bloquearía la fase.

La excepción se limita exclusivamente a esta advertencia porque:

- OpenSpout 4.28.5 soporta PHP 8.2;
- las versiones posteriores de la línea 4.x pasan a requerir PHP 8.3 o mayor;
- el entorno local actual usa PHP 8.2.12;
- el hosting objetivo todavía no está validado;
- un rango semántico permitiría resolver en el futuro una versión incompatible;
- el pin exacto preserva compatibilidad, reproducibilidad y despliegue.

## Vendor y alcance

- `vendor/` fue actualizado localmente.
- `vendor/` continúa ignorado por `/.gitignore`.
- No se modificó el bootstrap.
- No se implementó importación.
- No se crearon rutas, UI, controllers ni services de importación.
- No se procesaron archivos de negocio.
- No se modificó la base de datos.
- No se modificaron productos, precios, inventario, imágenes ni tickets.
- No se ejecutó SMTP ni se envió correo.
- No se hizo staging, commit, push ni deploy.

## Gate resuelto y hosting pendiente

La excepción controlada de Composer fue aprobada exclusivamente para la
advertencia documentada. El gate local de instalación queda en PASS sin
convertir la excepción en una tolerancia general a warnings.

```text
phase_gate=PASS
COMPOSER_STRICT_EXCEPTION=APPROVED
HOSTING_PREREQUISITE_PENDING=true
```

La excepción local no confirma que AwardSpace cumpla los requisitos. Antes de
un deploy siguen pendientes la versión PHP, `dom`, `fileinfo`, `filter`,
`libxml`, `xmlreader`, `zip`, `upload_max_filesize`, `post_max_size`,
`memory_limit`, espacio/inodos y el empaquetado de `vendor/`.

No debe iniciarse la implementación de importación ni un deploy por efecto de
esta instalación local sin autorización de una fase posterior.
