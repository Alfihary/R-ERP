# ERP-USUARIOS-RBAC-ADMIN-1

## Tipo y alcance

**Tipo:** auditoría funcional y diseño de implementación.
**Fecha de revisión:** 2026-10-09.
**Rama revisada:** `jesus`.
**Base de evidencia:** `r_erp_db_core_0_test`, en entorno no productivo.

Esta fase no implementa CRUD, nuevas rutas, migraciones, seeds, permisos ni vistas. No se ejecutaron escrituras de base de datos, SMTP, deploy, staging ni commit.

### Reglas obligatorias

- No usar esta auditoría como autorización para iniciar AUTH-ADMIN, migraciones o cambios de permisos.
- Cualquier implementación futura debe conservar AuthMiddleware, PermissionMiddleware, CSRF, PDO preparado, alcance por empresa/almacén y auditoría.
- Nunca registrar contraseñas, hashes, tokens, secretos, cookies ni rutas privadas en auditoría o logs.

### Fuera de alcance de esta fase

Login, recuperación o bloqueo de cuentas, CRUD de usuarios/roles/permisos, asignaciones administrativas, cambios de esquema, importación, SMTP, deploy y modificación de archivos productivos.

## Preflight y evidencia

| Control | Resultado |
|---|---|
| Rama | `jesus` |
| HEAD | `3e52391a32737bd408bf630b155c6b96e66f65f3` |
| Worktree versionable | limpio |
| Staging | vacío |
| Comparación `jesus...origin/jesus` | `0 0` |
| Ignorados | `.env`, `node_modules/`, `storage/private/`, `storage/uploads/productos/`, `storage/uploads/usuarios/`, `vendor/` |
| Entorno | no producción |
| Base auditada | `r_erp_db_core_0_test` |
| DB writes | `0` |
| SMTP | `false` |

Las credenciales y valores completos de `.env` no se imprimieron.

## Mapa de archivos y runtime

| Archivo/componente | Propósito | Estado actual | Consumidores |
|---|---|---|---|
| `app/Domain/Auth/AuthService.php` | Autenticar por email o username, regenerar sesión y cerrar sesión | funcional para login base; sin lockout, recuperación ni rehash observable | `/login`, `/logout`, `AuthMiddleware` |
| `app/Infrastructure/Repositories/UserRepository.php` | Buscar y persistir identidad mínima | búsqueda de autenticación y bootstrap; sin CRUD administrativo completo | `AuthService`, `InitialAdminService` |
| `app/Domain/Auth/InitialAdminService.php` | Crear/rotar primer ADMIN desde CLI/env local | funcional y transaccional; bootstrap-only | `database/auth.php` |
| `app/Infrastructure/Repositories/RoleRepository.php` | Consultar rol ADMIN, comprobar/asignar relación | soporte mínimo; no CRUD ni sincronización | `PermissionService`, bootstrap |
| `app/Infrastructure/Repositories/PermissionRepository.php` | Resolver permisos efectivos | consulta explícita; no catálogo CRUD | `PermissionService` |
| `app/Domain/Security/PermissionService.php` | Autorizar por relaciones rol-permiso | funcional, sin bypass por nombre de rol | middlewares, controladores, sidebar |
| `app/Http/Middlewares/AuthMiddleware.php` | Exigir sesión autenticada | funcional | rutas privadas |
| `app/Http/Middlewares/PermissionMiddleware.php` | Exigir permiso explícito | funcional; 403 si falta | rutas protegidas |
| `app/Domain/Scope/UserScopeService.php`, `ScopeContextService.php` | Resolver alcance empresa/almacén | existe para operaciones, no administra asignaciones de usuarios | repositorios/controladores de alcance |
| `app/Domain/Profile/ProfileService.php`, `ProfileController.php` | Perfil propio, contraseña y foto | funcional para auto-servicio; no es consola de usuarios | `/perfil/*` |
| `app/Infrastructure/Storage/UserPhotoStorage.php`, `UserPhotoRepository.php` | Almacén privado y ciclo de foto | validación MIME/tamaño y trazabilidad básica | perfil/vcard |
| `app/Domain/Audit/AuditService.php` | Normalizar y persistir eventos sin secretos | funcional y fail-safe; cobertura no universal | credenciales, correo y módulos que lo invocan |
| `app/Domain/Navigation/SidebarNavigationService.php` | Grupos y enlaces visibles por permisos | 6 grupos, 19 elementos; sin enlaces de administración RBAC | layouts/sidebar |
| `routes/web.php` | Login, perfil, módulos y configuración | no contiene rutas de usuarios/roles/permisos | Router |
| `database/migrations/db_core_0_001_create_core_identity_tables.php` | Tablas de identidad/RBAC base | aplicada en DB-TEST | migrador |
| `database/seeds/db_core_0_seed_admin_role.php`, `rbac_0_seed_base_permissions.php` | ADMIN estructural y permisos base | seeds existentes; no crean consola administrativa | runner de seeds |
| `docs/auth-0.md`, `docs/rbac-0.md` | Contratos de autenticación/RBAC | documentan límites actuales | equipo/QA |

## Modelo de datos auditado

### `usuarios`

Existe `id`, `username` (`VARCHAR(50) ASCII ascii_bin`), `email` (`VARCHAR(254)`), `password_hash`, `activo`, `ultimo_acceso_en`, timestamps y campos de eliminación lógica. Hay índices únicos independientes para username y email. No existen aún nombres visibles, empresa/almacén directo, foto, tema, bloqueo, contador de intentos ni versión de concurrencia en esta tabla.

**USER_MODEL_COMPLETE=false** para una consola administrativa completa.

### Estado y normalización

- `USERNAME_REQUIRED=true`, `USERNAME_UNIQUE=true`, `USERNAME_CASE_SENSITIVE=true` en DB.
- Normalización a minúsculas en login: `true`.
- Normalización en creación general: `PARTIAL` (garantizada por `InitialAdminService`, no por `UserRepository::create()` genérico).
- `EMAIL_REQUIRED=true`, `EMAIL_UNIQUE=true`, collation no binaria; normalización de entrada también es `PARTIAL` fuera del bootstrap.
- Password hash con `PASSWORD_DEFAULT` en creación inicial; nunca se copia a sesión.
- Usuario inactivo o con `eliminado_en` no puede iniciar sesión: `true`.

### Roles, permisos y relaciones

La base contiene un único rol estructural ADMIN activo (`es_sistema=1`), **119 permisos activos** y **119 asignaciones ADMIN**. Las claves compuestas de `usuario_roles` y `rol_permisos`, índices únicos y FKs evitan duplicados y huérfanos en la evidencia consultada:

```text
active_users=1
active_users_without_role=0
active_admins=1
orphan_user_role=0
orphan_role_permission=0
duplicate_usernames=0
duplicate_emails=0
duplicate_user_roles=0
duplicate_role_permissions=0
```

**STRUCTURAL_ROLES:** solo `ADMIN` está confirmado.
**ADMIN_ROLE_PROTECTED=true** en cuanto a marca estructural/seed; **LAST_ADMIN_PROTECTION=false** en operaciones administrativas porque todavía no existe servicio que impida retirar o desactivar al último administrador.

## Autenticación, autorización y alcance

`AuthService` acepta `login` por email o username, aplica trim/lowercase al identificador, usa consulta preparada, hash dummy para usuario inexistente, `password_verify()`, regeneración de sesión y sesión mínima (`user_id`, `username`, `email`). El mensaje de fallo es genérico. No hay recuperación, lockout, MFA, rehash explícito ni reset administrativo.

El orden de rutas privadas es AuthMiddleware seguido de PermissionMiddleware. No hay bypass por llamarse ADMIN: el permiso debe existir y estar asignado. **ADMIN_BYPASS_IMPLEMENTATION=false** y **ADMIN_BYPASS_SCOPE=none**.

El alcance operativo se resuelve mediante `usuario_empresas` y `usuario_almacenes` con `UserScopeService`/`ScopeContextService`; no debe derivarse de campos inventados en `usuarios`. La futura consola debe validar el alcance tanto en UI como en backend.

CSRF global cubre POST/PUT/PATCH/DELETE y las rutas de perfil existentes; **CSRF_ADMIN_MUTATIONS=true** para cualquier ruta futura si conserva el pipeline actual.

## Rutas, permisos y UI

### Rutas existentes

Existen `/login`, `/logout`, `/app`, `/perfil`, `/perfil/password`, `/perfil/foto` y configuraciones/módulos ya aprobados. No existen:

```text
/admin/usuarios
/admin/roles
/admin/permisos
```

### UI actual

- Pantalla de login: existente.
- Perfil y cambio de contraseña propios: existentes.
- Administración de usuarios: `NONE`.
- Administración de roles: `NONE`.
- Administración de permisos: `NONE`.
- Reset/bloqueo/reactivación administrativa: `NONE`.
- Enlaces de sidebar para usuarios/roles/permisos: `false`.
- `ADMIN_ITEMS=0` para administración RBAC; los enlaces administrativos actuales son correo, folios y auditoría según permisos.

La navegación centralizada conserva 6 grupos y 19 elementos. No se propone agregar enlaces hasta definir permisos, alcance y pruebas.

### Fotos, perfil y temas

`usuarios_fotos` y `UserPhotoStorage` soportan foto propia con almacenamiento privado, validación de MIME, tamaño y eliminación lógica. **USER_PHOTO_STATUS=PARTIAL**: no existe gestión administrativa de fotos. El perfil guarda datos extendidos en `perfiles_usuario`; la tabla `usuarios` no contiene nombre visible. No se encontró `ui_tema_id` ni selector global de tema; el layout actual es claro. **USER_THEME_STATUS=NOT_PRESENT**.

## Auditoría y controles de seguridad

`auditoria_eventos` registra actor, acción, entidad, resultado, IP, user-agent y metadata sanitizada. `AuditService` redacciona claves sensibles (`password`, `hash`, `token`, `secret`, `cookie`, `session`, `csrf`, rutas y storage) y no debe recibir secretos.

- Login fallido/exitoso: cobertura parcial según llamada del controlador/servicio; debe verificarse y estandarizarse para AUTH-ADMIN.
- Cambio de contraseña y credenciales: cobertura existente en servicios de credenciales, con redacción; revisar catálogo de acciones en implementación.
- Alta/baja/edición de usuarios: no existe operación administrativa; cobertura `MISSING`.
- Cambios de roles/permisos: no existe operación administrativa; cobertura `MISSING`.
- Acciones críticas actuales: módulos que inyectan `AuditService` sí registran; no es una cobertura transversal garantizada.

**PASSWORD_AUDIT_SAFE=true** para el sanitizador, sujeto a que nuevos callers no incluyan secretos en claves no reconocidas.
**AUDIT_COVERAGE=PARTIAL**.
**TRANSACTIONAL_USER_CREATE=true** para el bootstrap inicial; sincronización RBAC administrativa no implementada.
**RBAC_CONCURRENCY_RISK=HIGH** para el futuro CRUD: faltan locks/versionado y protección del último ADMIN.

## Política recomendada de ciclo de vida

- Deshabilitar (`activo=0`) para suspensión operativa reversible.
- Eliminación lógica (`eliminado_en`, `eliminado_por`) para baja administrativa con trazabilidad.
- No permitir hard delete de usuarios con historial, relaciones o auditoría.
- Toda operación debe ejecutarse en transacción, bloquear el usuario/rol afectado y volver a comprobar que no elimina el último ADMIN.
- Mantener `usuario_roles` y `rol_permisos` con estado activo/eliminado; no borrar físicamente relaciones históricas.

**DELETE_POLICY=SOFT_DELETE + DISABLE_ONLY; HARD_DELETE=false.**

## Brechas priorizadas

### P0

`P0_COUNT=0`: no se observó una vulnerabilidad crítica nueva en la auditoría read-only.

### P1

`P1_COUNT=0`: no hay CRUD expuesto que requiera cierre inmediato; la ausencia de consola es una brecha funcional, no una ruta insegura existente.

### P2

- `P2-01`: crear servicios/repositorios de usuarios con validación centralizada de username/email, estado y scope.
- `P2-02`: crear roles, asignaciones y catálogo de permisos sin bypass ADMIN.
- `P2-03`: añadir lockout/ratelimit y flujo de recuperación/reset antes de operación multiusuario.
- `P2-04`: proteger último ADMIN y registrar todas las mutaciones.
- `P2-05`: completar pruebas de concurrencia y rollback.

### P3

- `P3-01`: UI administrativa consistente con el sidebar actual.
- `P3-02`: selector de tema y nombre visible, si se aprueban en modelo funcional.
- `P3-03`: fotos administradas y matriz de alcance/rol documentada.

## Diseño de implementación propuesto

1. **USUARIOS-ADMIN-1:** contrato, permisos `usuarios.*`, repositorio/servicio, DTO/validación, listados y detalle; sin roles todavía.
2. **USUARIOS-ESTADOS-1:** activar/desactivar, baja lógica, último ADMIN, locks y auditoría.
3. **RBAC-ADMIN-1:** CRUD de roles y asignaciones usuario-rol transaccionales.
4. **RBAC-PERMISOS-1:** catálogo de permisos de solo lectura/sincronización controlada; nunca edición arbitraria de permisos estructurales.
5. **AUTH-HARDENING-1:** lockout, reset, rehash, política de contraseña y pruebas de sesión.
6. **UI-RBAC-1:** rutas, vistas, filtros, sidebar y pruebas de visibilidad/CSRF/scope.

Cada fase deberá incluir migración solo si es necesaria, seed explícito, DB-TEST, SELECT de verificación, INSERT válido/INVÁLIDO que revierta, criterio de aceptación y plan de rollback. La siguiente fase recomendada es **USUARIOS-ADMIN-1-DISEÑO-DETALLADO**, no implementación directa.

## Dictamen

```ini
AUDIT_STATUS=PASS_WITH_GAPS
USER_MODEL_COMPLETE=false
AUTH_BASE=FUNCTIONAL
RBAC_BASE=FUNCTIONAL_READ_ONLY
ADMIN_CONSOLE=ABSENT
ADMIN_BYPASS_IMPLEMENTATION=false
ADMIN_ROLE_PROTECTED=true
LAST_ADMIN_PROTECTION=false
CSRF_ADMIN_MUTATIONS=true
AUDIT_COVERAGE=PARTIAL
PASSWORD_AUDIT_SAFE=true
TRANSACTIONAL_USER_CREATE=true
RBAC_CONCURRENCY_RISK=HIGH
DELETE_POLICY=SOFT_DELETE_DISABLE_ONLY
STRUCTURAL_ROLES=ADMIN
USER_PHOTO_STATUS=PARTIAL
USER_THEME_STATUS=NOT_PRESENT
SIDEBAR_USER_ROLE_PERMISSION_LINKS=false
PRODUCTION_FILES_MODIFIED=false
DB_WRITES=0
SMTP=false
COMMIT=false
PUSH=false
DEPLOY=false
MANUAL_OPERATOR_VERIFICATION_PENDING=true
```

La auditoría queda documentada, pero **no cierra** la implementación de administración de usuarios/RBAC ni autoriza AUTH-ADMIN, DB-TEST adicional, staging o commit.
