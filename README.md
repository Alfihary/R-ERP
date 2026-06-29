# SoporteGR ERP

ERP modular para PHP 8.x y MySQL, diseñado para operar múltiples empresas y
almacenes en una sola base de datos, con permisos por acción, alcance operativo,
auditoría y folios.

## Estado actual

Fases cerradas: `CONFIG-0 + SECURITY-0 + DB-CORE-0`.
Fase autorizada y en revisión: `AUTH-0`.

El repositorio contiene un arranque técnico mínimo con entry point público,
autoload `App\`, configuración por entorno y rutas `GET /` y `GET /health`.
SECURITY-0 agrega sesión técnica, CSRF central, escape HTML, errores seguros y
headers HTTP. DB-CORE-0 prepara la conexión PDO, una migración de identidad,
el rol estructural ADMIN y DB-TEST-CORE. AUTH-0 incorpora login por email o
username, logout, una ruta privada mínima y creación controlada del primer
administrador. Todavía no existe autorización funcional ni módulos de negocio.

## Arranque local

Requiere PHP 8.x. Desde la raíz del repositorio:

```powershell
php -S 127.0.0.1:8000 -t public
```

Después se pueden consultar:

- `http://127.0.0.1:8000/`
- `http://127.0.0.1:8000/health`
- `http://127.0.0.1:8000/login`

La aplicación usa valores seguros documentados en `.env.example` cuando no
existe `.env`. Para configuración local se puede copiar esa plantilla a `.env`;
el archivo real permanece ignorado por Git.

La protección CSRF se aplica centralmente a `POST`, `PUT`, `PATCH` y `DELETE`.
Los formularios futuros podrán generar el campo oculto mediante
`csrf_field($csrf)`. Las vistas deben escapar texto dinámico con `e($value)`.

## DB-CORE-0

La configuración de base de datos usa únicamente variables `APP_DB_*` y no
incluye credenciales reales. El runner es exclusivo de CLI y exige que
`APP_DB_NAME`, `--database` y `--confirm-database` coincidan.

```powershell
php database/console.php migrate --database=<db-test> --confirm-database=<db-test>
php database/console.php seed --database=<db-test> --confirm-database=<db-test>
php database/console.php db:test --database=<db-test> --confirm-database=<db-test>
```

El runner no crea bases de datos y rechaza `APP_ENV=production`. El diseño,
rollback y criterios de aceptación están en `docs/db-core-0.md`.

## AUTH-0

El primer administrador se crea o rota únicamente mediante
`database/auth.php`. El comando exige confirmación doble de la base y toma
email y contraseña temporal del `.env` local. No imprime contraseña ni hash.

```powershell
php database/auth.php create-initial-admin --database=<db-test> --confirm-database=<db-test>
php database/auth.php rotate-initial-admin-password --database=<db-test> --confirm-database=<db-test>
```

El procedimiento completo y sus límites están en `docs/auth-0.md`.

## Decisiones base

- PHP renderizará las vistas principales mediante una arquitectura MVC modular
  propia.
- No se usarán Laravel, microservicios, SPA, React, Vue, Angular, jQuery ni
  Bootstrap como framework visual.
- PDO y prepared statements serán obligatorios cuando se apruebe la capa de
  persistencia.
- La autorización efectiva combinará permiso por acción y alcance por empresa y
  almacén.
- Tailwind CSS se compilará localmente; AwardSpace recibirá únicamente el CSS
  final.
- JavaScript será vanilla, modular y progresivo.
- Los archivos privados vivirán en `storage/`, nunca en `public/`.
- Mail y Notifications serán subsistemas centrales; ningún módulo enviará SMTP
  directamente.
- La base de datos se construirá y aprobará por fases con migración, seed cuando
  aplique y DB-TEST.

## Documentación

- `docs/arquitectura.md`: capas, dependencias y estructura.
- `docs/seguridad.md`: controles y modelo de autorización.
- `docs/convenciones.md`: reglas de nombres, código, rutas y Git.
- `docs/base-datos.md`: modelo conceptual y gobierno por fases.
- `docs/auth-0.md`: login, administrador inicial, rotación y rollback.
- `docs/fases.md`: secuencia de construcción y puertas de aprobación.
- `docs/deploy-awardspace.md`: preparación, exclusiones y rollback.
- `docs/mail-notifications.md`: diseño conceptual del subsistema.

## Flujo de trabajo

1. Identificar la fase.
2. Presentar alcance, archivos, pruebas y riesgo.
3. Obtener autorización expresa.
4. Implementar solo esa fase.
5. Ejecutar verificaciones.
6. Revisar diff y criterios de aceptación.
7. Cerrar la fase antes de solicitar la siguiente.

## Reglas obligatorias

- No iniciar módulos operativos antes de aprobar arquitectura, seguridad,
  permisos, alcance, base de datos inicial y DB-TEST.
- No versionar `.env`, dependencias locales, logs, uploads, dumps ni respaldos.
- No guardar datos privados o secretos en `public/`.
- No avanzar de fase por inferencia.
- Mantener `main` estable cuando exista el primer commit aprobado.

## Pendiente de aprobar

- Pruebas y cierre formal de `AUTH-0`.
- Estrategia concreta de Composer y dependencias PHP.
- Configuración de Tailwind y scripts locales.

## Fuera de alcance de esta fase

- PermissionService y UserScopeService.
- Empresas, almacenes y alcance operativo.
- Dashboard y layout administrativo.
- Productos, inventario, tickets, compras, ventas y reportes.
- Integración SMTP, cron, despliegue o cambios en producción.
