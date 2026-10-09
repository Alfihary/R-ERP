# USUARIOS-ADMIN-1 — Diseño detallado

## 1. Resumen ejecutivo

Esta fase define la consola futura de administración de usuarios y las operaciones mínimas `usuario -> roles`. Es un diseño funcional, de seguridad y arquitectura; no implementa código productivo.

Estado de partida confirmado en `r_erp_db_core_0_test` y en el runtime actual:

```ini
AUTH_BASE=FUNCTIONAL
RBAC_BASE=FUNCTIONAL_READ_ONLY
ADMIN_CONSOLE=ABSENT
PERMISSION_COUNT=119
ADMIN_PERMISSION_ASSIGNMENT_COUNT=119
ACTIVE_USERS=1
ACTIVE_USERS_WITHOUT_ROLE=0
ACTIVE_ADMIN_USER_COUNT=1
ADMIN_ROLE_PROTECTED=true
ADMIN_BYPASS_IMPLEMENTATION=false
LAST_ADMIN_PROTECTION=false
AUDIT_COVERAGE=PARTIAL
PASSWORD_AUDIT_SAFE=true
RBAC_CONCURRENCY_RISK=HIGH
DELETE_POLICY=SOFT_DELETE_DISABLE_ONLY
```

La primera fase productiva recomendada es `USUARIOS-ADMIN-2-BACKEND-CORE`, antes de añadir rutas o UI.

## 2. Alcance V1

| Capacidad | Clasificación |
|---|---|
| Listar usuarios | V1_REQUIRED |
| Buscar y filtrar | V1_REQUIRED |
| Crear usuario | V1_REQUIRED |
| Editar datos no sensibles | V1_REQUIRED |
| Activar/desactivar | V1_REQUIRED |
| Eliminación lógica | V1_REQUIRED |
| Asignar empresa | V1_REQUIRED |
| Asignar almacén | V1_REQUIRED |
| Sincronizar roles | V1_REQUIRED |
| Reset administrativo de contraseña | V1_REQUIRED |
| Ver último login, estado y roles | V1_REQUIRED |
| Restaurar usuario eliminado | DEFER |
| Foto administrativa | DEFER |
| Tema por usuario | NOT_RECOMMENDED en V1 |

## 3. Fuera de alcance V1

Hard delete, recuperación de contraseña por correo, lockout automático, 2FA, sesiones activas, impersonation, historial avanzado de sesiones, CRUD libre de permisos, cambio de tema por usuario y edición avanzada de fotografías. Ninguno se implementa en esta fase.

## 4. Arquitectura existente

`AuthService` autentica por email o username, rechaza usuarios inactivos/eliminados y guarda una sesión mínima. `UserRepository` resuelve autenticación y bootstrap, pero no es todavía un repositorio administrativo completo. `RoleRepository` permite consultar/asignar el rol estructural y `PermissionRepository` consulta permisos efectivos. `PermissionMiddleware` exige permisos explícitos; no existe bypass por nombre `ADMIN`.

El alcance empresa/almacén se resuelve mediante `usuario_empresas`, `usuario_almacenes`, `UserScopeService` y `ScopeContextService`. Perfil propio (`/perfil`) permanece separado de la futura consola (`/admin/usuarios`). `AuditService` sanitiza secretos, pero su cobertura de mutaciones de usuarios/RBAC todavía es parcial.

## 5. Invariantes de seguridad

| ID | Invariante | Aplicación |
|---|---|---|
| INV-USER-001 | Siempre debe existir al menos un ADMIN activo, no eliminado y utilizable. | Obligatoria |
| INV-USER-002 | Un almacén asignado debe pertenecer a la empresa asignada. | Obligatoria |
| INV-USER-003 | Username único, ASCII permitido y canónico en minúsculas. | Obligatoria |
| INV-USER-004 | Email válido, único y normalizado según política única. | Obligatoria |
| INV-USER-005 | Cada rol asignado existe, está activo y no eliminado. | Obligatoria |
| INV-USER-006 | Desactivar o eliminar lógicamente impide login inmediatamente. | Obligatoria |
| INV-USER-007 | Passwords y hashes nunca entran en respuestas ni auditoría. | Obligatoria |
| INV-USER-008 | Datos, roles y auditoría de una operación única son atómicos. | Obligatoria |
| INV-USER-009 | No existen relaciones `usuario_roles` duplicadas. | DB + servicio |
| INV-USER-010 | La concurrencia no puede eludir la protección del último ADMIN. | Obligatoria |

## 6. ADMIN utilizable y protección del último ADMIN

```ini
USABLE_ADMIN_DEFINITION=usuarios.activo=1 AND usuarios.eliminado_en IS NULL AND rol ADMIN activo/no eliminado AND usuario_roles activa/no eliminada y válida
```

Empresa o almacén no son requisitos de autenticación: son alcance operativo. Un administrador sin asignación de alcance sigue siendo autenticable, pero no debe recibir operaciones que exijan scope.

`LAST_ADMIN_PROTECTION_DESIGN`: toda mutación que pueda afectar al último ADMIN debe abrir una transacción, bloquear de forma determinista el rol ADMIN, sus relaciones activas y los usuarios ADMIN relevantes, volver a evaluar la definición anterior y rechazar la operación si dejaría cero administradores utilizables. La comprobación y la mutación deben compartir la misma transacción.

No es seguro hacer `SELECT COUNT(...)` sin locks y mutar después: dos solicitudes pueden observar el mismo valor. La estrategia futura será:

```ini
LAST_ADMIN_LOCK_STRATEGY=BEGIN; lock ADMIN role; lock active ADMIN user-role rows in stable user_id order; lock target user/relations; re-evaluate; mutate; audit; COMMIT
```

`LAST_ADMIN_CONCURRENCY_INVARIANT`: frente a ADMIN A y ADMIN B, como mínimo uno debe permanecer activo, no eliminado, con relación ADMIN válida y autenticable; la operación perdedora recibe rechazo seguro (`409` si la convención HTTP lo permite).

`SELF_ADMIN_MUTATION_POLICY`: un administrador puede editar sus datos y cambiar su password; no puede desactivarse, eliminarse lógicamente ni quitarse ADMIN si es el último ADMIN utilizable. Si existe otro ADMIN utilizable, la auto-degradación puede permitirse con confirmación y auditoría.

`STRUCTURAL_ADMIN_ROLE_POLICY`: no renombrar `codigo=ADMIN`, no eliminarlo, no desactivarlo, no convertirlo en rol ordinario y no permitir edición libre de sus propiedades estructurales.

## 7. Autorización y catálogo de permisos

```ini
EXISTING_USER_ADMIN_PERMISSIONS=ninguno confirmado; los 119 permisos actuales no exponen una consola usuarios/roles/permisos
MISSING_USER_ADMIN_PERMISSIONS=acceso, crear, editar, estado, roles y password de usuarios
RECOMMENDED_USER_ADMIN_PERMISSIONS=usuarios.acceder, usuarios.crear, usuarios.editar, usuarios.estado, usuarios.roles, usuarios.password
USER_ADMIN_AUTHORIZATION_MODEL=PermissionMiddleware + PermissionService; no depender de role==ADMIN
PERMISSION_CATALOG_POLICY=catálogo estructural definido por código/seeds; UI de consulta read-only; roles reciben permisos; sin CRUD libre de permission keys
```

Un rol administrativo no-ADMIN podrá gestionar usuarios si recibe explícitamente los permisos recomendados. La UI nunca sustituye la validación backend.

## 8. Normalización y validaciones

```ini
USERNAME_CANONICAL_POLICY=trim + lowercase ASCII; regex ^[a-z0-9._-]{3,50}$; canonicalizar antes de uniqueness check, create, edit y login
EMAIL_CANONICAL_POLICY=trim + lowercase; filter_var; máximo 254; requerido y único; cambios de case no crean duplicados lógicos
ADMIN_INITIAL_PASSWORD_POLICY=mínimo 12 caracteres, máximo sensible 255 bytes, confirmación obligatoria, password_hash(PASSWORD_DEFAULT), nunca auditar valor/hash
```

Nombres y apellidos no están en `usuarios`; se almacenarán en el perfil existente. Se aceptan caracteres culturales válidos y se aplican límites de longitud, no reglas de ASCII. Roles deben ser IDs enteros existentes, activos y no eliminados. Empresa y almacén se validan server-side, incluyendo pertenencia y estado.

## 9. Flujos de usuario

### Creación

```text
validar entrada -> normalizar -> validar empresa/almacén -> validar roles
-> comprobar unicidad -> hash password -> BEGIN
-> insertar usuario -> sincronizar roles -> registrar auditoría -> COMMIT
```

`USER_CREATE_TRANSACTION`: tablas `usuarios`, `usuario_roles` y `auditoria_eventos`; locks de unicidad/ADMIN cuando aplique; rollback total ante cualquier error.

### Edición

`USER_EDITABLE_FIELDS=username,email,nombres,apellidos,empresa,almacen`. `activo`, roles y password son operaciones separadas. `USER_GENERAL_EDIT_ACCEPTS_PASSWORD=false`.

`USER_UPDATE_TRANSACTION`: bloquear target y relaciones de scope, validar versión/estado, actualizar datos permitidos, auditar campos modificados y confirmar atómicamente.

### Estado y soft delete

`USER_STATUS_CHANGE_FLOW=authorize -> CSRF -> lock target + ADMIN set -> validate last-admin -> update activo -> audit -> COMMIT`.

`USER_SOFT_DELETE_FLOW=authorize -> lock -> activo=0 y eliminado_en/por -> conservar historial y relaciones -> audit -> COMMIT`.

`SOFT_DELETE_ROLE_RELATIONS_POLICY=conservar relaciones históricas; marcarlas inactivas/eliminadas sin hard delete`.
`USER_RESTORE=DEFER`: restaurar requiere revisar username/email, roles, alcance y último ADMIN; no se incluye en V1.

### Roles

`USER_ROLE_SYNC_FLOW`: validar el conjunto completo antes de mutar, bloquear ADMIN cuando corresponda, comprobar roles activos, reemplazar relaciones de forma atómica y auditar before/after sin secretos.

`USER_ROLE_SYNC_SEMANTICS=replace full set` para que la API sea determinista.
`ACTIVE_USER_EMPTY_ROLE_POLICY=REJECT_FOR_ACTIVE_USERS` en V1: un usuario activo debe tener al menos un rol aplicable.
`LAST_ADMIN_ROLE_SYNC_PROTECTION=true`.

### Password reset

`ADMIN_PASSWORD_RESET_FLOW=endpoint separado; autorizar usuarios.password; validar nueva password y confirmación; hash; transacción; invalidar sesiones si el mecanismo futuro lo soporta; auditar solo target/actor/evento; COMMIT`. Editar usuario nunca acepta password vacío o implícito.

`PASSWORD_REHASH_PHASE=AUTH-HARDENING-1`: rehash oportunista pertenece al flujo de login, no al CRUD V1.

## 10. Empresa y almacén

```ini
USER_COMPANY_POLICY=empresa_id requerido para usuarios operativos; empresa debe existir, estar activa y no eliminada
USER_WAREHOUSE_POLICY=almacen_id requerido cuando el usuario opera inventario; almacén activo, no eliminado y perteneciente a empresa_id
COMPANY_CHANGE_WAREHOUSE_POLICY=REJECT si no se recibe un almacén válido de la nueva empresa en la misma operación
RBAC_SCOPE_MODEL=permisos globales por rol; alcance empresa/almacén separado por relaciones de scope; no row-level permissions inventados
```

Así, cambiar de empresa nunca conserva silenciosamente A1 al pasar a B; la operación exige seleccionar un almacén válido de B o se rechaza completa.

## 11. Repositorios y servicios

```ini
EXISTING_USER_REPOSITORY_CAPABILITIES=findForAuthentication, create bootstrap, updatePasswordHash
REQUIRED_USER_REPOSITORY_ADDITIONS=list/filter, findById, insertAdmin, updateProfileData, changeStatus, softDelete, lockedUsableAdminSet, counts
EXISTING_ROLE_REPOSITORY_CAPABILITIES=findActiveSystemRole, userHasRole, assignUser
REQUIRED_ROLE_REPOSITORY_ADDITIONS=listActive, validateSet, replaceUserRoles, lockAdminRole, lockRelations
RECOMMENDED_DOMAIN_SERVICES=UserAdminService (orquestación), UserValidationService, UserRoleSyncService, UserPasswordResetService, LastAdminGuard
RECOMMENDED_CONTROLLERS=AdminUserController; separar ProfileController y CredentialController
```

No crear un repositorio gigante: consultas de usuarios, roles, scope y auditoría deben conservar responsabilidades separadas. El seam transaccional de Inventario sirve como referencia conceptual, pero no se debe acoplar Users a `Domain\Inventory`; si aparece repetición, definir posteriormente una abstracción transaccional genérica.

`SHARED_TRANSACTION_ABSTRACTION_RECOMMENDED=true` como decisión futura y aislada.

## 12. Rutas, PRG y CSRF

| Método | Ruta propuesta | Acción | Permiso | CSRF | PRG |
|---|---|---|---|---|---|
| GET | `/admin/usuarios` | index | `usuarios.acceder` | no | no |
| GET | `/admin/usuarios/crear` | create | `usuarios.crear` | no | no |
| POST | `/admin/usuarios` | store | `usuarios.crear` | sí | sí |
| GET | `/admin/usuarios/editar?id=` | edit | `usuarios.editar` | no | no |
| POST | `/admin/usuarios/actualizar` | update | `usuarios.editar` | sí | sí |
| POST | `/admin/usuarios/estado` | changeStatus | `usuarios.estado` | sí | sí |
| POST | `/admin/usuarios/roles` | syncRoles | `usuarios.roles` | sí | sí |
| POST | `/admin/usuarios/password` | resetPassword | `usuarios.password` | sí | sí |
| POST | `/admin/usuarios/eliminar` | softDelete | `usuarios.estado` | sí | sí |
| GET | `/admin/usuarios/ver?id=` | show | `usuarios.acceder` | no | no |

`ADMIN_USER_PRG_REQUIRED=true` y `ADMIN_USER_CSRF_REQUIRED=true`. Se adaptarán nombres al Router existente antes de implementación; no se crean ahora.

## 13. Auditoría

```ini
RECOMMENDED_USER_AUDIT_EVENTS=usuario.creado, usuario.actualizado, usuario.estado_actualizado, usuario.eliminado, usuario.roles_actualizados, usuario.password_reiniciada
USER_AUDIT_METADATA_POLICY=target_user_id, changed_fields, old_status/new_status, role_ids_before/after, empresa_before/after, almacen_before/after; excluir password, password_hash, confirmación, sesión y CSRF
PASSWORD_RESET_AUDIT_SAFE=true
ADMIN_AUDIT_ACTOR_TARGET_REQUIRED=true
```

Siempre se registran `actor_user_id` y `target_user_id`, incluso cuando actor y target coinciden. `auditoria_eventos` dispone de actor, acción, entidad, resultado, IP, user-agent y metadata sanitizada; es suficiente para V1 si se usan eventos y límites actuales.

## 14. Transacciones y concurrencia

| Operación | Boundary y locks | Punto de commit |
|---|---|---|
| `USER_CREATE_TRANSACTION` | usuarios; scope; roles; validar ADMIN si aplica | después de auditoría |
| `USER_UPDATE_TRANSACTION` | target, scope y unicidad | después de auditoría |
| `USER_STATUS_TRANSACTION` | rol ADMIN, relaciones ADMIN y target | después de guard last-admin |
| `USER_DELETE_TRANSACTION` | mismo conjunto que status | después de soft delete + auditoría |
| `USER_ROLE_SYNC_TRANSACTION` | ADMIN role, relaciones y target | después de replace + auditoría |
| `USER_PASSWORD_RESET_TRANSACTION` | target; auditoría sin secreto | después de hash + auditoría |

```ini
USER_EDIT_CONCURRENCY_POLICY=optimistic check sobre actualizado_en; conflicto => 409 y no overwrite silencioso
USER_ROLE_CONCURRENCY_POLICY=pessimistic locks del rol ADMIN, relaciones y target; replace full set
USER_STATUS_CONCURRENCY_POLICY=lock conjunto ADMIN antes de decidir; revalidar target dentro de la misma transacción
RBAC_LOCK_ORDER=1) rol ADMIN; 2) usuario_roles ADMIN por usuario_id; 3) usuarios ADMIN por id; 4) target user; 5) relaciones de roles/scope del target
LAST_ADMIN_CONFLICT_RESULT=409/validación segura, rollback completo, sin enviar respuesta de éxito
```

El orden es estable y debe mantenerse idéntico en servicios que combatan por los mismos recursos para reducir deadlocks.

## 15. UX y sidebar

Listado: Usuario, Nombre, Email, Empresa, Almacén, Roles, Estado, Último login y Acciones. Filtros: texto, estado, empresa, almacén y rol. Nunca mostrar hash, sesión ni metadata sensible.

Crear agrupa Identidad, Asignación organizacional, Acceso y Roles; la password inicial se muestra separada. Editar no precarga password y ofrece botón independiente “Restablecer contraseña”. Desactivar/eliminar requieren confirmación visual, pero la seguridad pertenece al backend. `LAST_ADMIN_UI_GUARD=RECOMMENDED`.

```ini
RECOMMENDED_SIDEBAR_ENTRY=grupo Administración; label Usuarios; ruta /admin/usuarios; icon estrategia igual a los iconos existentes; visible si usuarios.acceder
USER_ADMIN_VISIBILITY_POLICY=PERMISSION_DEPENDENT; por defecto vista global solo para usuarios con usuarios.acceder y scope administrativo aprobado
USER_ADMIN_PHOTO_SCOPE=DEFER_PHOTO_ADMIN
```

`/perfil` seguirá siendo autoservicio; `/admin/usuarios/...` será administración y no reutilizará `ProfileController` para mutaciones sensibles.

## 16. Respuestas HTTP y mensajes

GET exitoso: `200`; mutaciones HTML: `302/303` PRG; no autorizado: `403`; target inexistente: `404`; conflicto de invariante/concurrencia: `409`; validación: `422`; CSRF: `419`, respetando la convención actual.

Mensaje de último ADMIN: “No es posible quitar el rol ADMIN al último administrador activo.” No revelar hashes, existencia de credenciales ni información sensible.

## 17. Estrategia de pruebas y fixtures

Pruebas futuras mínimas: creación válida/duplicados; empresa-almacén cruzados; rol inválido; edición y conflictos; estado normal/último ADMIN; soft delete; role sync normal/último ADMIN; reset; password ausente de auditoría; concurrencia de último ADMIN.

Fixtures QA conceptuales: `QA_ADMIN_A`, `QA_ADMIN_B`, `QA_USER`, con cleanup transaccional. El administrador real nunca se muta en pruebas destructivas:

```ini
BASELINE_ADMIN_NEVER_MUTATED_IN_DESTRUCTIVE_QA=true
```

## 18. Evaluación de migración y modelo

```ini
MIGRATION_REQUIRED=false
AUDIT_SCHEMA_SUFFICIENT=true
```

El diseño V1 puede usar el schema existente y las relaciones `usuario_empresas`/`usuario_almacenes`; las mejoras de nombre visible, lockout, versionado o tema son decisiones posteriores y no deben añadirse sin una fase aprobada. Gaps reales: ausencia de un modelo administrativo de bloqueo/reset, falta de versionado explícito para edición optimista y ausencia de campos de perfil visible en `usuarios` (ya existe `perfiles_usuario`). No se considera gap la ausencia de hard delete.

## 19. Hallazgos

| ID | Área | Clasificación | Severidad | Riesgo | Recomendación |
|---|---|---|---|---|---|
| UA-001 | Último ADMIN | capacidad futura faltante | P2 | una futura consola podría dejar cero ADMIN sin guard | implementar `LastAdminGuard` transaccional antes de UI |
| UA-002 | CRUD RBAC | funcionalidad ausente | P2 | no existe administración operativa | backend core antes de rutas |
| UA-003 | Concurrencia | diseño pendiente | P2 | lost update y carreras de roles | locks estables + optimistic check |
| UA-004 | Password | hardening pendiente | P2 | no hay lockout/reset administrativo | fase AUTH-HARDENING posterior |
| UA-005 | UI | consola ausente | P3 | operación manual inexistente | diseñar después de invariantes |

`P0_COUNT=0`, `P1_COUNT=0`, `P2_COUNT=4`, `P3_COUNT=1`.

## 20. Fases de implementación

```text
USUARIOS-ADMIN-2-BACKEND-CORE
  permisos, validación, repositorios, servicios, invariantes, scope,
  transacciones, auditoría y last-admin guard.

USUARIOS-ADMIN-3-HTTP-UI
  rutas, controller, PRG, CSRF, list/create/edit/status/roles/reset,
  sidebar y mensajes.

USUARIOS-ADMIN-4-QA-HARDENING
  pruebas negativas, concurrencia, autorización, scope y verificación visual.
```

`RECOMMENDED_IMPLEMENTATION_PHASE=USUARIOS-ADMIN-2-BACKEND-CORE`.

## 21. Estado de la fase

```ini
DESIGN_STATUS=PASS_WITH_GAPS
FILES_CREATED=docs/usuarios-admin-1-diseno-detallado.md
FILES_MODIFIED=ninguno
PRODUCTION_FILES_MODIFIED=false
DB_WRITES=0
SMTP=false
COMMIT=false
PUSH=false
DEPLOY=false
MANUAL_OPERATOR_VERIFICATION_PENDING=true
```

Esta fase no implementa CRUD, rutas, vistas, permisos, CSS, JS, migraciones ni seeds. El staging permanece vacío y el siguiente paso requiere una nueva autorización explícita.
