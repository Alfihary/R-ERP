# SoporteGR ERP

ERP modular para PHP 8.x y MySQL, diseñado para operar múltiples empresas y
almacenes en una sola base de datos, con permisos por acción, alcance operativo,
auditoría y folios.

## Estado actual

Fases cerradas:
`CONFIG-0 + SECURITY-0 + DB-CORE-0 + AUTH-0 + RBAC-0 + UI-SHELL-0 + DB-SCOPE-1 + SCOPE-SERVICE-1 + SCOPE-CONTEXT-1 + APP-HOME-1 + DB-CATALOGOS-1 + CRUD-CATALOGOS-1`.
Fase autorizada y en revisión: `CRUD-CATALOGOS-2`.

El repositorio contiene un arranque técnico mínimo con entry point público,
autoload `App\`, configuración por entorno y rutas `GET /` y `GET /health`.
SECURITY-0 agrega sesión técnica, CSRF central, escape HTML, errores seguros y
headers HTTP. DB-CORE-0 prepara la conexión PDO, una migración de identidad,
el rol estructural ADMIN y DB-TEST-CORE. AUTH-0 incorpora login por email o
username, logout, una ruta privada mínima y creación controlada del primer
administrador. RBAC-0 incorpora permisos estructurales, resolución efectiva y
protección de `/app`. UI-SHELL-0 agrega el cascarón visual autenticado sin
convertirlo en dashboard. DB-SCOPE-1 incorpora la persistencia de empresas,
almacenes y asignaciones. SCOPE-SERVICE-1 resuelve ese alcance en backend sin
crear módulos de negocio. SCOPE-CONTEXT-1 mantiene en sesión el par activo
empresa/almacén validado contra ese alcance.
APP-HOME-1 convierte `/app` en un inicio operativo sin métricas, módulos ni
datos ficticios.
DB-CATALOGOS-1 incorpora siete catálogos globales estructurales sin crear
productos, inventario, CRUD ni operaciones empresariales.
CRUD-CATALOGOS-1 administra monedas, unidades, impuestos, líneas y marcas con
permisos por acción, CSRF y validación backend.
CRUD-CATALOGOS-2 administra clasificaciones de producto con jerarquía
padre-hijo, prevención de ciclos y reglas seguras de estado.

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

## RBAC-0

RBAC-0 crea únicamente `sistema.acceder`, `sistema.app.ver` y
`seguridad.rbac.ver`, los asigna de forma idempotente a `ADMIN` y protege
`GET /app` con `PermissionMiddleware`.

La autorización se resuelve desde la base en cada petición y exige usuario,
roles, relaciones y permisos activos y no eliminados. No existe bypass por
nombre de rol.

La fase no incorpora empresas, almacenes, `UserScopeService`, dashboard, menús
ni permisos de módulos funcionales. El contrato completo está en
`docs/rbac-0.md`.

## UI-SHELL-0

`GET /app` usa un layout autenticado mínimo con sidebar, topbar, área principal
y logout visible mediante `POST` con CSRF. La navegación es estática y solo
incluye el inicio privado existente.

El shell no incluye dashboard, métricas, módulos funcionales, menú dinámico,
temas ni alcance por empresa o almacén. El CSS vive en
`public/css/core/app.css` y no requiere Tailwind, Node ni herramientas de build.

## DB-SCOPE-1

DB-SCOPE-1 crea empresas, almacenes y asignaciones normalizadas de alcance a
usuarios. Las llaves foráneas compuestas impiden asignar un almacén de otra
empresa o sin acceso previo a esa empresa.

La fase incorpora una empresa, un almacén y las asignaciones estructurales del
administrador inicial. El contrato completo está en `docs/db-scope-1.md`.

## SCOPE-SERVICE-1

`UserScopeService` resuelve empresas y almacenes activos y no eliminados desde
el ID del usuario autenticado. `ScopeRepository` encapsula las consultas PDO
preparadas y valida en SQL la relación usuario-empresa-almacén.

`GET /app` muestra información mínima del alcance efectivo. No acepta IDs de
empresa o almacén del navegador, no guarda el alcance completo en sesión y no
incluye módulos funcionales. El contrato está en `docs/scope-service-1.md`.

## SCOPE-CONTEXT-1

`ScopeContextService` conserva únicamente `active_company_id` y
`active_warehouse_id` en sesión. El par se valida contra `UserScopeService` y
se limpia si deja de ser permitido.

Con un solo par el contexto se establece automáticamente. Con múltiples pares,
`GET /app` muestra un selector mínimo que actualiza mediante
`POST /app/contexto`, protegido por autenticación, `sistema.app.ver` y CSRF.
El contrato está en `docs/scope-context-1.md`.

## APP-HOME-1

`GET /app` presenta la sesión, el contexto activo y el estado descriptivo de
los controles base. La navegación mantiene únicamente el inicio privado y el
cierre de sesión ya disponibles.

La pantalla no es un dashboard analítico o funcional: no contiene métricas,
gráficas, actividad ficticia, CRUD ni enlaces a módulos pendientes. El contrato
está en `docs/app-home-1.md`.

## DB-CATALOGOS-1

DB-CATALOGOS-1 crea monedas, tipos de cambio, unidades de medida, impuestos,
líneas, marcas y clasificaciones de producto. El seed incorpora tres monedas,
cinco unidades y tres impuestos; no crea líneas, marcas, clasificaciones,
productos ni inventario.

```powershell
php database/catalogos.php migrate --database=<db-test> --confirm-database=<db-test>
php database/catalogos.php seed --database=<db-test> --confirm-database=<db-test>
php database/catalogos.php db:test --database=<db-test> --confirm-database=<db-test>
```

El contrato está en `docs/db-catalogos-1.md`.

## CRUD-CATALOGOS-1

La sección privada `/catalogos` permite administrar cinco catálogos simples
mediante formularios y tablas operativas. Todas las escrituras usan POST, CSRF,
permisos específicos y PDO preparado.

Tipos de cambio, clasificaciones jerárquicas, productos e inventario permanecen
fuera de alcance. El contrato está en `docs/crud-catalogos-1.md`.

## CRUD-CATALOGOS-2

`GET /catalogos/clasificaciones` administra una jerarquía global mediante un
servicio específico. Las escrituras validan padre existente y activo,
autorreferencia, descendientes, ciclos y cambios de estado dentro de una
transacción.

Tipos de cambio, productos e inventario permanecen fuera de alcance. El
contrato está en `docs/crud-catalogos-2.md`.

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
- `docs/rbac-0.md`: permisos estructurales, resolución, pruebas y rollback.
- `docs/db-scope-1.md`: empresas, almacenes, alcance, DB-TEST y rollback.
- `docs/scope-service-1.md`: resolución backend y pruebas del alcance efectivo.
- `docs/scope-context-1.md`: contexto activo, selector y pruebas de sesión.
- `docs/app-home-1.md`: inicio operativo, límites y pruebas de presentación.
- `docs/db-catalogos-1.md`: catálogos base, seed, DB-TEST y rollback.
- `docs/crud-catalogos-1.md`: rutas, permisos, validaciones y límites del CRUD.
- `docs/crud-catalogos-2.md`: jerarquía, ciclos, estado y permisos de clasificaciones.
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

- Pruebas y cierre formal de `CRUD-CATALOGOS-2`.
- Estrategia concreta de Composer y dependencias PHP.
- Configuración de Tailwind y scripts locales.

## Fuera de alcance de esta fase

- Dashboard, CRUD y operaciones empresariales.
- Dashboard y layout administrativo final.
- Productos, inventario, tickets, compras, ventas y reportes.
- Integración SMTP, cron, despliegue o cambios en producción.
