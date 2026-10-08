# MAIL-DEPENDENCY-PHPMAILER-1

## Objetivo

Esta fase incorpora Composer y PHPMailer como dependencia externa del ERP. No implementa transporte SMTP, procesamiento del outbox, configuración de correo ni envío real.

## Dependencias

- PHP `>=8.2`.
- `phpmailer/phpmailer` `^6.12`.
- Versión bloqueada inicialmente: `v6.12.0`.

`vendor/` es una dependencia local generada por Composer y permanece fuera de Git. `composer.json` y `composer.lock` sí deben versionarse para que las instalaciones sean reproducibles.

## Autoload

`bootstrap/autoload.php` carga `vendor/autoload.php` cuando está disponible y después conserva el autoloader propio del namespace `App\`. La constante `COMPOSER_AUTOLOAD_AVAILABLE` permite que una capacidad dependiente de Composer detecte su ausencia de forma controlada.

La aplicación base conserva su autoload propio si `vendor/` todavía no existe. Las fases que utilicen PHPMailer deben comprobar explícitamente que la dependencia esté disponible y producir un error seguro si falta.

## Auditoría local

```text
composer install
php database/mail-dependency-phpmailer.php audit
```

El audit solo comprueba manifiestos y carga de clases. No carga configuración de entorno, no abre conexiones de base de datos, no configura SMTP, no instancia conexiones y no envía correos.

## Producción y AwardSpace

En un entorno autorizado con Composer disponible:

```text
composer install --no-dev --optimize-autoloader
```

Si el hosting no permite ejecutar Composer, `vendor/` debe prepararse fuera del servidor usando el `composer.lock` versionado y desplegarse junto con los archivos permitidos. Aunque `vendor/` no se versiona en Git, es necesario en runtime para las funciones que utilicen PHPMailer.

No se debe ejecutar Composer directamente en producción sin el procedimiento de despliegue, respaldo y rollback aprobado. Esta fase no realiza deploy.

## Fuera de alcance

- Procesador del outbox.
- Transporte SMTP.
- Lectura de secretos.
- Configuración de cuentas SMTP.
- Pruebas de conexión.
- Envío real o de prueba.
- Workers, cron, rutas, permisos, migraciones y seeds.
