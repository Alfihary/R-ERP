# USUARIOS-ADMIN-2 — Backend core

## Estado final

```ini
IMPLEMENTATION_STATUS=PASS_WITH_CONCURRENCY_GAP
PRODUCTIVE_DIFF_REVIEW=APPROVE_AS_IS
DB_TEST=PASS
REAL_DB_CONCURRENCY_EVIDENCE=NOT_EXECUTED
```

La fase implementa el núcleo de administración de usuarios, roles, alcance
empresa/almacén, auditoría obligatoria y protección secuencial del último
administrador utilizable. La ejecución final se realizó una sola vez en
`r_erp_db_core_0_test` después de la rotación manual de credenciales.

El gap de concurrencia no representa una falla observada: el protocolo de
locks fue revisado estáticamente y las invariantes secuenciales pasaron, pero
no se ejecutó una carrera destructiva sobre el ADMIN baseline en la base
compartida.

## Archivos y alcance

Producción:

- `app/Domain/Audit/AuditService.php`
- `app/Domain/Audit/AuditRecorderInterface.php`
- `app/Domain/Users/UserAdminConflictException.php`
- `app/Domain/Users/UserAdminService.php`
- `app/Domain/Users/UserAdminValidationException.php`
- `app/Infrastructure/Repositories/RoleRepository.php`
- `app/Infrastructure/Repositories/ScopeRepository.php`
- `app/Infrastructure/Repositories/UserRepository.php`
- `database/seeds/rbac_0_seed_base_permissions.php`

Pruebas:

- `database/tests/usuarios_admin_backend_core_test.php`

No se implementaron rutas HTTP, controladores administrativos, vistas, sidebar,
dashboard, migraciones, módulos funcionales, SMTP ni deploy.

## Servicios y repositorios

`UserAdminService` implementa `create`, `update`, `changeStatus`, `softDelete`,
`syncRoles` y `resetPassword`. Cada operación usa transacción, validaciones de
dominio y auditoría obligatoria.

`UserRepository` aporta unicidad, inserción, edición, estado, soft delete,
password, bloqueo de usuarios y conjunto de ADMIN utilizables.

`RoleRepository` valida roles activos, resuelve el rol estructural `ADMIN`,
bloquea relaciones y reemplaza el conjunto completo de roles sin IDs
hardcodeados.

`ScopeRepository` valida empresas y almacenes activos, incluyendo que el
almacén pertenezca a la empresa recibida, y reemplaza el scope dentro de la
transacción.

## AuditRecorderInterface

La interfaz es mínima y productiva:

```text
recordRequired(action, actorUserId, metadata)
```

No contiene métodos de QA ni hooks de fallo. `AuditService` la implementa sin
debilitar la sanitización existente. Passwords, hashes, tokens y datos sensibles
continúan excluidos o redactados de la metadata.

## Normalización, create y update

Username: `trim`, lowercase ASCII y regex `^[a-z0-9._-]{3,50}$`.

Email: `trim`, lowercase, `filter_var` y máximo 254 caracteres.

Create valida unicidad, empresa, almacén y roles; calcula `password_hash`,
inserta usuario, asigna scope y roles y registra auditoría antes del commit.

Update excluye expresamente `password`, `password_hash` y
`password_confirmation`; el reset de password es una operación separada.
La unicidad excluye al propio usuario.

## Estado, soft delete y roles

Desactivar establece `activo=0`. Soft delete establece estado inactivo y
`eliminado_en`, conserva historial y desactiva relaciones. No existe hard delete
de usuarios.

`syncRoles` normaliza y deduplica IDs, valida roles activos, reemplaza el
conjunto completo de forma atómica, rechaza roles vacíos en usuarios activos y
protege la eliminación de ADMIN.

## Protección del último ADMIN

ADMIN utilizable significa:

```ini
usuarios.activo=1
usuarios.eliminado_en IS NULL
rol ADMIN activo y no eliminado
usuario_roles activa y no eliminada
```

```ini
LAST_ADMIN_GUARDED_PATHS=update,changeStatus,softDelete,syncRoles
LAST_ADMIN_UNGUARDED_PATHS=0
LAST_ADMIN_COUNT_PROTECTED_BY_LOCKS=true
UNSAFE_LAST_ADMIN_COUNT_FOUND=false
```

Orden estable:

```text
rol ADMIN → conjunto ADMIN utilizable → usuario objetivo → roles objetivo
```

```ini
RBAC_LOCK_ORDER_STABLE=true
```

## Permisos

El seed agrega exactamente seis permisos:

```text
usuarios.acceder
usuarios.crear
usuarios.editar
usuarios.estado
usuarios.roles
usuarios.password
```

Usa upsert de permisos y relaciones, por lo que es idempotente y no crea
duplicados.

```ini
PERMISSION_COUNT_BEFORE=125
PERMISSION_COUNT_AFTER=125
ADMIN_PERMISSION_ASSIGNMENT_COUNT_BEFORE=125
ADMIN_PERMISSION_ASSIGNMENT_COUNT_AFTER=125
PERMISSION_SEED_IDEMPOTENCY=PASS
```

## Evidencia DB-TEST-CORE

### Rollback post-mutación

```ini
ROLLBACK_SCENARIO=CREATE_AFTER_MUTATION
ROLLBACK_MUTATION_OCCURRED_BEFORE_FAILURE=true
ROLLBACK_POST_MUTATION=PASS
```

El doble de auditoría falla después de insertar usuario, scope y roles. La
transacción revierte esas mutaciones y no deja evento de éxito.

### Último ADMIN secuencial

```ini
STATUS_DISABLE_LAST_ADMIN_REJECT=PASS
SOFT_DELETE_LAST_ADMIN_REJECT=PASS
ROLE_SYNC_LAST_ADMIN_REJECT=PASS
SEQUENTIAL_LAST_ADMIN=PASS
```

Cada intento validó un único ADMIN utilizable y el baseline quedó intacto.

### Password y autenticación

```ini
PASSWORD_RESET_VALID=PASS
OLD_PASSWORD_INVALID=PASS
NEW_PASSWORD_VALID=PASS
OLD_AND_NEW_PASSWORD_USE_SAME_IDENTIFIER=true
PASSWORD_NOT_IN_AUDIT=PASS
PASSWORD_HASH_NOT_IN_AUDIT=PASS

AUTH_LOGIN_USERNAME=PASS
AUTH_LOGIN_EMAIL=PASS
AUTH_INACTIVE_REJECT=PASS
AUTH_SOFT_DELETED_REJECT=PASS
AUTH_REGRESSION=PASS
RBAC_REGRESSION=PASS
```

### Auditoría

```ini
AUDIT_USER_CREATE=PASS
AUDIT_USER_UPDATE=PASS
AUDIT_USER_STATUS=PASS
AUDIT_USER_DELETE=PASS
AUDIT_USER_ROLE_SYNC=PASS
AUDIT_USER_PASSWORD_RESET=PASS
```

### Integridad y cleanup

```ini
ORPHAN_USER_ROLE_COUNT=0
DUPLICATE_USER_ROLE_COUNT=0
ACTIVE_USERS_WITHOUT_ROLE=0
ACTIVE_ADMIN_COUNT_AFTER=1
QA_USERS=0
QA_ROLES=0
QA_USER_ROLE_ROWS=0
QA_SCOPE_ROWS=0
QA_DATA_RESIDUALS=0
BASELINE_ADMIN_INTACT=true
```

El cleanup se limita a fixtures QA por IDs/códigos exactos. No elimina el
baseline, el rol ADMIN estructural, permisos estructurales ni la auditoría
legítima de recuperación.

## Gap de concurrencia

```ini
STATIC_LOCK_PROTOCOL=PASS
REAL_DB_CONCURRENCY_EVIDENCE=NOT_EXECUTED
CONCURRENCY_TEST_PLAN=NOT_SAFELY_EXECUTABLE_IN_CURRENT_SHARED_TEST_DB
LAST_ADMIN_CONCURRENCY_RESULT=NOT_EXECUTED
```

No se afirma que la concurrencia esté probada. La base compartida no permite
ejecutar una carrera destructiva sin arriesgar el ADMIN baseline. La evidencia
concurrente real requiere una base aislada y una fase posterior.

## Límites y siguiente fase

```ini
ADMIN_HTTP_UI_IMPLEMENTED=false
ADMIN_ROUTES_IMPLEMENTED=false
SIDEBAR_CHANGED=false
HTTP_UI_SCOPE_CLEAN=true
MIGRATION_REQUIRED=false
MIGRATIONS_CREATED=0
```

La siguiente fase recomendada, sólo después del versionado y cierre de ésta,
es:

```text
USUARIOS-ADMIN-3-HTTP-UI
```

## Calidad y control Git

```ini
PHP_LINT=PASS
GIT_DIFF_CHECK=PASS
SECRET_SCAN=PASS
DB_TEST=PASS
SMTP=false
STAGING=EMPTY
COMMIT=false
PUSH=false
DEPLOY=false
```

Esta documentación no autoriza staging, commit, push, deploy ni el inicio de la
fase HTTP/UI.
