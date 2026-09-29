# AWARDSPACE-SCHEDULER-PREREQUISITOS-1

## Estado y alcance

Fecha de auditoría: `2026-09-29`.

Esta microfase contrasta el contrato de `CORREO-OUTBOX-SCHEDULER-CONTRATO-1`
con evidencia local y documentación pública oficial de AwardSpace. No accede a
la cuenta, no despliega archivos, no conecta MySQL o SMTP y no ejecuta el
processor.

```text
PLAN_DECLARED=AwardSpace Basic
READINESS=INCOMPLETE_VERIFICATION
HOSTING_PREREQUISITE_PENDING=true
SCHEDULER_LOCK_STRATEGY=mysql_get_lock_pending_account_test
REAL_SMTP_TEST=false
CRON_ACCOUNT_TEST_PENDING=true
PHP_CLI_AVAILABLE=UNKNOWN
PHP_CLI_PATH=UNKNOWN
PHP_CLI_VERSION=UNKNOWN
PHP_HOSTING_VERSION=UNKNOWN
PHP_EXTENSIONS_REQUIRED=UNKNOWN
COMPOSER_HOSTING_AVAILABLE=UNKNOWN
VENDOR_DEPLOY_STATUS=UNKNOWN
PRIVATE_APP_ROOT_SUPPORTED=UNKNOWN
PRIVATE_STORAGE_STATUS=UNKNOWN
MYSQL_GET_LOCK_STATUS=UNKNOWN
FILESYSTEM_SHARED_BETWEEN_WORKERS=UNKNOWN
SMTP_CONNECTIVITY=UNKNOWN_REQUIRES_CONTROLLED_TEST
HOSTING_LIMITS_STATUS=UNKNOWN
ENV_FILE_READABLE_FROM_CLI=UNKNOWN
```

`AwardSpace Basic` es el plan declarado para esta fase y existe como producto
de hosting compartido pagado en la página pública de AwardSpace. La suscripción
concreta y sus capacidades habilitadas no se comprobaron en el panel de la
cuenta. Por ello, el nombre del plan no se usa como sustituto de una prueba.

## Método de evidencia

Cada conclusión usa una de estas clases:

- `LOCAL`: observación reproducible en el checkout local. No demuestra el
  comportamiento del hosting.
- `HOSTING_DOCS`: afirmación publicada por AwardSpace. Puede describir un plan
  o plataforma, pero no demuestra la configuración efectiva de la cuenta.
- `ACCOUNT_TEST`: prueba realizada en la cuenta real. En esta microfase no se
  realizó ninguna.
- `UNKNOWN`: no existe evidencia suficiente para aceptar el prerrequisito.

Los estados son `AVAILABLE`, `MISSING`, `CONDITIONAL` o `UNKNOWN`. Un valor
`CONDITIONAL` requiere todavía una verificación indicada. Ningún dato local se
promueve a evidencia de hosting.

## Precheck local

| Comprobación | Resultado | Evidencia |
|---|---|---|
| Rama | `main` | `LOCAL` |
| HEAD | `51c00a7 docs(mail): define outbox scheduler contract` | `LOCAL` |
| Worktree versionable | limpio | `LOCAL` |
| Staging | vacío | `LOCAL` |
| Ignorados | `.env`, `node_modules/`, `storage/private/`, uploads y `vendor/` | `LOCAL` |
| PHP CLI | `8.2.12` | `LOCAL` |
| Composer | `2.9.7` | `LOCAL` |
| PHPMailer | `6.12.0` | `LOCAL` |
| OpenSpout | `4.28.5` | `LOCAL` |
| `vendor/` | presente, 240 archivos, 994695 bytes | `LOCAL` |
| `flock()` | disponible | `LOCAL` |
| `.env` | existe y es legible por el CLI local | `LOCAL` |
| `APP_ENV` | pertenece a `local|development|test` | `LOCAL` |
| Nombre DB local | configurado; no se imprimió | `LOCAL` |
| `APP_TIMEZONE` | válido y efectivo como `America/Mexico_City` | `LOCAL` |

El `php.ini` local reportó `max_execution_time=0`, `memory_limit=512M`,
`upload_max_filesize=40M` y `post_max_size=40M`. Son valores exclusivamente
locales.

## Matriz de prerrequisitos

### Cron y tareas programadas

| Requisito | Estado | Evidencia | Conclusión |
|---|---|---|---|
| Plan Basic identificado | `CONDITIONAL` | `HOSTING_DOCS` + declaración de fase | El producto Basic figura como plan compartido pagado; la cuenta no fue inspeccionada. |
| Crontab disponible en planes pagados | `CONDITIONAL` | `HOSTING_DOCS` | El tutorial oficial limita la función a usuarios de pago y la tabla de hosting enumera soporte de crontab. Debe comprobarse que la cuenta Basic lo muestra habilitado. |
| Frecuencia mínima | `UNKNOWN` | `UNKNOWN` | La documentación consultada no publica el mínimo efectivo. No se acepta todavía el intervalo recomendado de 5 minutos. |
| Máximo de tareas | `UNKNOWN` | `UNKNOWN` | No se encontró un límite oficial aplicable al plan y cuenta. |
| Comando cron y CWD | `UNKNOWN` | `ACCOUNT_TEST` pendiente | El panel debe aceptar rutas absolutas y un CWD explícito. |
| Prevención de overlap por el panel | `UNKNOWN` | `UNKNOWN` | El contrato no dependerá de una garantía no documentada del panel. |

Resultado: cron no queda aprobado para implementación. La existencia general de
la función no demuestra frecuencia, cuota ni ejecución real en esta cuenta.

### PHP web, PHP CLI y extensiones

| Requisito | Local | Hosting | Evidencia de hosting |
|---|---|---|---|
| PHP web `>=8.2` | `8.2.12` | `UNKNOWN` | AwardSpace documenta selección de versión, pero la versión activa requiere `ACCOUNT_TEST`. |
| PHP CLI `>=8.2` | `8.2.12` | `UNKNOWN` | No se confirmó acceso CLI, binario ni que coincida con PHP web. |
| Ruta absoluta del CLI | n/a | `UNKNOWN` | Debe obtenerse con `command -v php` o desde el comando aprobado por el panel. No se asumirá `/usr/bin/php`. |
| `pdo_mysql` | disponible | `UNKNOWN` | `ACCOUNT_TEST` pendiente. |
| `fileinfo` | disponible | `UNKNOWN` | `ACCOUNT_TEST` pendiente. |
| `dom` | disponible | `UNKNOWN` | `ACCOUNT_TEST` pendiente. |
| `libxml` | disponible | `UNKNOWN` | `ACCOUNT_TEST` pendiente. |
| `xmlreader` | disponible | `UNKNOWN` | `ACCOUNT_TEST` pendiente. |
| `zip` | disponible | `UNKNOWN` | `ACCOUNT_TEST` pendiente. |
| `mbstring` | disponible | `UNKNOWN` | `ACCOUNT_TEST` pendiente. |
| `xml` | disponible | `UNKNOWN` | `ACCOUNT_TEST` pendiente. |
| `xmlwriter` | disponible | `UNKNOWN` | `ACCOUNT_TEST` pendiente. |
| `SimpleXML` | disponible | `UNKNOWN` | `ACCOUNT_TEST` pendiente. |
| `filter` | disponible | `UNKNOWN` | `ACCOUNT_TEST` pendiente. |

AwardSpace documenta que los planes pagados pueden editar `php.ini`, activar
extensiones incluidas y cambiar límites. Esto no confirma que cada extensión
requerida esté instalada para la versión seleccionada.

PHPMailer 6.12.0 y OpenSpout 4.28.5 cargan localmente con PHP 8.2.12. Su
compatibilidad en AwardSpace queda `UNKNOWN` hasta reproducir el autoload y
comprobar PHP/extensiones en el CLI real.

### Composer, `vendor/` y cuotas

| Requisito | Estado | Evidencia | Conclusión |
|---|---|---|---|
| `composer.json`/lock reproducibles | `AVAILABLE` local | `LOCAL` | Composer valida; el pin exacto de OpenSpout genera la advertencia ya aceptada por contrato. |
| Ejecutar Composer en hosting | `UNKNOWN` | `UNKNOWN` | No es necesario ni se autoriza asumirlo. |
| Preparar `vendor/` localmente | `AVAILABLE` local | `LOCAL` | El artefacto puede prepararse desde el lock fuera del servidor. |
| Subir `vendor/` | `CONDITIONAL` | `HOSTING_DOCS` | AwardSpace documenta File Manager/FTP; falta prueba de transferencia, permisos y autoload. |
| Cuota por archivo | `UNKNOWN` | `UNKNOWN` | Las páginas oficiales consultadas publican límites distintos para File Manager; debe prevalecer una prueba de cuenta. |
| Límite de archivos/inodos | `UNKNOWN` | `UNKNOWN` | No se encontró valor oficial aplicable a Basic. |
| Espacio efectivo | `UNKNOWN` | `ACCOUNT_TEST` pendiente | La etiqueta comercial de espacio ilimitado no sustituye cuota y uso observados. |

El `vendor/` actual contiene 240 archivos y ocupa 994695 bytes localmente. Este
conteo es una entrada para la prueba de despliegue, no una aprobación de cuota.

### Directorios privados, raíz pública y logs

AwardSpace documenta `/home/www/<dominio>` como ruta predeterminada y permite
cambiar el hosting path del dominio a otro directorio dentro de la cuenta. Esto
hace conceptualmente posible una disposición como:

```text
/home/www/<dominio>/R-ERP/
  app/
  config/
  database/
  storage/
  vendor/
  public/       <- único document root
```

La ruta exacta no está aprobada. Debe probarse en el panel que el dominio apunta
solo a `public/` y que no se exponen directorios superiores.

| Requisito | Estado | Evidencia | Conclusión |
|---|---|---|---|
| Document root configurable | `CONDITIONAL` | `HOSTING_DOCS` | La capacidad general está documentada; falta configuración real. |
| `app/`, `config/`, `database/`, `storage/`, `.env` y `vendor/` no públicos | `UNKNOWN` | `ACCOUNT_TEST` pendiente | El checkout no contiene `.htaccess` de defensa en profundidad para esos directorios. El document root correcto es obligatorio. |
| Escritura en `storage/private/` | `UNKNOWN` | `ACCOUNT_TEST` pendiente | Deben comprobarse permisos desde PHP CLI y web sin exponer contenido. |
| Log privado persistente | `UNKNOWN` | `ACCOUNT_TEST` pendiente | AwardSpace documenta logs web y `error_log()`, no el contrato del log privado del scheduler. |
| Cuota y rotación de logs | `UNKNOWN` | `UNKNOWN` | Requiere política propia y límites reales de cuenta. |

El scheduler futuro puede usar stdout/stderr hasta aprobar almacenamiento
privado. No se creará un log sin límite ni se escribirá bajo `public/`.

### MySQL y lock global

La página de hosting compartido anuncia MySQL 5/8, pero no identifica la
versión del servidor asignado ni documenta permisos de advisory locks.

| Requisito | Estado | Evidencia | Conclusión |
|---|---|---|---|
| Versión MySQL real | `UNKNOWN` | `ACCOUNT_TEST` pendiente | Consultar `SELECT VERSION()` sin imprimir credenciales. |
| `GET_LOCK()` | `UNKNOWN` | `ACCOUNT_TEST` pendiente | No usar en implementación hasta probar retorno y ownership. |
| `RELEASE_LOCK()` | `UNKNOWN` | `ACCOUNT_TEST` pendiente | Debe probarse en la misma conexión propietaria. |
| Exclusión entre dos conexiones | `UNKNOWN` | `ACCOUNT_TEST` pendiente | Una segunda conexión debe recibir lock ocupado mientras la primera lo conserva. |
| Liberación al cerrar conexión | `UNKNOWN` | `ACCOUNT_TEST` pendiente | Debe observarse de forma controlada. |

Decisión condicionada:

```text
SCHEDULER_LOCK_STRATEGY=mysql_get_lock_pending_account_test
```

`GET_LOCK()` seguirá siendo la opción autoritativa solamente si pasa la prueba
real entre dos conexiones. Si falla o está prohibido, la implementación queda
bloqueada y se debe aprobar otro contrato. `flock()` no se promoverá como lock
autoritativo porque la topología y semántica del filesystem son desconocidas.

### Filesystem, concurrencia y límites de proceso

AwardSpace describe su plataforma como compartida y agrupada, pero la
documentación pública consultada no demuestra que dos ejecuciones cron usen el
mismo host ni el mismo filesystem local.

| Requisito | Estado | Evidencia | Conclusión |
|---|---|---|---|
| `flock()` en hosting | `UNKNOWN` | `ACCOUNT_TEST` pendiente | La disponibilidad local no aplica al hosting. |
| Filesystem compartido entre ejecuciones | `UNKNOWN` | `UNKNOWN` | No puede asumirse. |
| Número de procesos cron simultáneos | `UNKNOWN` | `UNKNOWN` | No se encontró límite oficial aplicable. |
| Timeout PHP CLI | `UNKNOWN` | `ACCOUNT_TEST` pendiente | No se equipara a `max_execution_time` web. |
| `max_execution_time` web | `UNKNOWN` | `ACCOUNT_TEST` pendiente | AwardSpace documenta valor/configuración general; falta valor efectivo. |
| `memory_limit` | `UNKNOWN` | `ACCOUNT_TEST` pendiente | Debe leerse sin publicar información sensible. |
| `upload_max_filesize` | `UNKNOWN` | `ACCOUNT_TEST` pendiente | No usar cifras contradictorias de documentación como valor real. |
| `post_max_size` | `UNKNOWN` | `ACCOUNT_TEST` pendiente | Debe verificarse en la versión PHP activa. |

### SMTP saliente

AwardSpace publica SMTP autenticado en puertos 25/587 y SSL en 465 para sus
cuentas de correo. También indica que el SMTP saliente hacia un servidor
externo en un plan premium puede requerir una solicitud de habilitación a
soporte.

| Requisito | Estado | Evidencia | Conclusión |
|---|---|---|---|
| Configuración SMTP de AwardSpace | `CONDITIONAL` | `HOSTING_DOCS` | Existen valores documentados para cuentas de correo AwardSpace. |
| Conectividad desde PHP/CLI | `UNKNOWN` | `ACCOUNT_TEST` pendiente | No se abrió socket ni conexión SMTP. |
| SMTP externo habilitado | `UNKNOWN` | `ACCOUNT_TEST`/soporte pendiente | Requiere confirmar si el host configurado es externo y si la cuenta está habilitada. |
| TLS/SSL real | `UNKNOWN` | `ACCOUNT_TEST` pendiente | No se realizó handshake. |

No se autoriza una prueba de socket o correo dentro de esta microfase. Debe
existir una autorización separada con un único destino controlado y sin
imprimir secretos.

### Entorno CLI, zona horaria y base productiva

| Requisito | Local | Hosting | Evidencia de hosting |
|---|---|---|---|
| `.env` legible por CLI | sí | `UNKNOWN` | `ACCOUNT_TEST` pendiente. |
| `.env` fuera de la web | n/a | `UNKNOWN` | Depende del document root real. |
| `APP_ENV=production` | no aplica local | `UNKNOWN` | Debe reportarse solo como booleano. |
| `APP_DEBUG=false` | no aplica local | `UNKNOWN` | Debe reportarse solo como booleano. |
| Nombre DB productivo configurado | local configurado | `UNKNOWN` | No imprimir el nombre ni credenciales durante la prueba. |
| `APP_TIMEZONE` | `America/Mexico_City` válido | `UNKNOWN` | El wrapper y CLI real deben validar y aplicar el mismo valor. |

La prueba futura solo debe producir banderas como
`ENV_READABLE=true|false`, `PRODUCTION_DB_CONFIGURED=true|false` y
`TIMEZONE_VALID=true|false`. No debe imprimir `.env`, DSN, usuario, host,
contraseña, secretos ni nombres productivos.

## Pruebas de cuenta pendientes

Estas pruebas requieren una autorización separada para operar en AwardSpace.
No se ejecutaron en esta microfase.

1. Confirmar en el panel el nombre del plan y que `Crontab Settings` está
   habilitado; registrar frecuencia mínima, máximo de jobs y formato de comando.
2. Ejecutar un diagnóstico CLI temporal, privado y eliminado al terminar que
   reporte únicamente PHP version, SAPI, ruta del binario, extensiones
   requeridas, límites no sensibles, CWD y zona horaria.
3. Probar `vendor/autoload.php`, `PHPMailer` y `OpenSpout` sin red, sin correo y
   sin procesar archivos de usuario.
4. Confirmar permisos de lectura/escritura para el árbol privado y denegación
   HTTP para `.env`, `app/`, `config/`, `database/`, `storage/` y `vendor/`.
5. Consultar la versión MySQL y, con autorización expresa, probar
   `GET_LOCK()`/`RELEASE_LOCK()` en dos conexiones sin crear tablas ni datos.
6. Confirmar si procesos cron diferentes comparten host/filesystem; probar
   `flock()` únicamente como defensa suplementaria.
7. Medir límites de tiempo, memoria, archivos/inodos y espacio desde el panel o
   mecanismo aprobado.
8. Confirmar stdout/stderr del cron, retención y rotación antes de habilitar log
   privado.
9. Confirmar con soporte si el SMTP configurado requiere habilitación saliente.
   No abrir socket ni enviar hasta una autorización específica.

La prueba controlada del advisory lock deberá demostrar, sin persistir datos:

```text
conexion A: GET_LOCK(nombre_controlado, 0) = 1
conexion B: GET_LOCK(mismo_nombre, 0) = 0
conexion A: RELEASE_LOCK(mismo_nombre) = 1
conexion B: GET_LOCK(mismo_nombre, 0) = 1
conexion B: RELEASE_LOCK(mismo_nombre) = 1
```

El nombre debe ser fijo y no contener el nombre de la base, dominio o secreto.

## Fuentes oficiales consultadas

- AwardSpace, Shared Hosting:
  <https://www.awardspace.com/web-hosting/shared-hosting/>
- AwardSpace, How to Setup Cron Jobs:
  <https://www.awardspace.com/video-tutorials/how-to-setup-cron-jobs/>
- AwardSpace, How Do I Update My PHP Version?:
  <https://www.awardspace.com/kb/change-php-version/>
- AwardSpace, How Do I Customize My PHP Settings?:
  <https://www.awardspace.com/kb/customize-php-settings/>
- AwardSpace, SSH Manager:
  <https://www.awardspace.com/kb/control-panel/ssh-manager/>
- AwardSpace, default domain path:
  <https://www.awardspace.com/kb/domai-default-path/>
- AwardSpace, File Manager:
  <https://www.awardspace.com/kb/file-manager-how-to-use/>
- AwardSpace, upload restrictions:
  <https://www.awardspace.com/kb/unable-upload-files-using-file-manager/>
- AwardSpace, Email Account Settings:
  <https://www.awardspace.com/kb/email-settings/>
- AwardSpace, SMTP from WordPress:
  <https://www.awardspace.com/wordpress-tutorials/wordpress-smtp/>
- AwardSpace, Access and Error Logs:
  <https://www.awardspace.com/kb/access-error-logs/>

Las fuentes públicas describen capacidades generales y pueden cambiar. La
evidencia definitiva debe provenir de la cuenta real y conservar fecha y método
sin secretos.

## Riesgos y rollback

Riesgos pendientes:

- cron inexistente o con frecuencia superior a 5 minutos;
- PHP CLI ausente o distinto del PHP web;
- extensión requerida ausente;
- cuota insuficiente para `vendor/` o logs;
- document root que exponga archivos privados;
- timeout menor que el batch previsto;
- `GET_LOCK()` restringido o con semántica no validada;
- filesystem no compartido que invalide `flock()` como autoridad;
- SMTP saliente bloqueado;
- logging sin rotación.

Esta fase no altera hosting, base de datos ni runtime. Su rollback consiste
únicamente en retirar este documento no versionado.

## Resultado

```text
DOCUMENTATION_RESULT=PASS
READINESS=INCOMPLETE_VERIFICATION
HOSTING_PREREQUISITE_PENDING=true
SCHEDULER_IMPLEMENTATION_AUTHORIZED=false
DEPLOY_AUTHORIZED=false
```

Hay prerrequisitos críticos `UNKNOWN`: frecuencia y cuotas de cron, PHP CLI,
extensiones, límites efectivos, privacidad del árbol, topología, advisory lock,
logs y conectividad SMTP. Por contrato, no puede declararse
`READY_FOR_SCHEDULER_IMPLEMENTATION` ni `READY_WITH_CONDITIONS` hasta ejecutar
las pruebas de cuenta aplicables.

No se implementó scheduler, cron, wrapper, lock, ruta HTTP, UI, migración,
seed, SMTP, conexión a base productiva, proceso de outbox, staging, commit,
push o deploy.
