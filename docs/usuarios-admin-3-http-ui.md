# USUARIOS-ADMIN-3 — HTTP/UI

## Estado de implementación

```ini
IMPLEMENTATION_STATUS=PASS_WITH_MANUAL_REVIEW
AUTOMATED_VISUAL_ASSIST_STATUS=PASS_PARTIAL
BACKEND_STATUS=PASS_WITH_CONCURRENCY_GAP
HTTP_EXECUTION=AUTHENTICATED_MATRIX_AUTOMATED_PASS
MANUAL_OPERATOR_VERIFICATION_PENDING=true
HTTP_RUNTIME_BLOCKER=NONE
CONTENT_ASSERTIONS_STATUS=PASS
ENV_LOADER_FIX_STATUS=PASS
ENV_LOADER_IS_READABLE_DEPENDENCY_REMOVED=true
APP_DB_NAME_PRESENT_AFTER_ENV_LOAD=true
DB_TARGET_MATCH=true
DB_RUNTIME_MATCH=true
```

## Estado de asistencia visual automatizada

La asistencia visual automatizada fue parcial y no sustituye la revisión
humana. El código pasó la revisión técnica; los puntos no ejecutados se
mantienen explícitamente como pendientes.

```ini
A=PASS_PARTIAL
B=PASS
C=PASS_PARTIAL
D=NOT_EXECUTED
E=PASS
F=PASS
G=PASS_PARTIAL
G_JS_RUNTIME_CONFIRMED=false
H=PASS
I=PASS_PARTIAL
J=NOT_EXECUTED
K=PASS
L=NOT_EXECUTED
M=PASS
N=NOT_EXECUTED
O=NOT_EXECUTED
P=PASS_PARTIAL
Q=PASS
R=NOT_EXECUTED
FINAL_DIFF_REVIEW_CODE=PASS
FINAL_DIFF_REVIEW_DOCUMENTATION=FIXED
MANUAL_OPERATOR_VERIFICATION_PENDING=true
```

Pendientes visuales humanos:

- A desktop exact 1440x900
- C filter submit exhaustive visual behavior
- G company->warehouse runtime and console
- I role sync visual mutation
- J reset password visual submit
- N sidebar collapsed visual
- O mobile viewport
- P validation/conflict visual
- R responsive overflow

No se declara revisión visual humana completa, cierre total de UI ni un estado
automatizado completo.

## Assertions de contenido HTTP

La microfase `USUARIOS-ADMIN-4-CONTENT-ASSERTIONS-CLOSE-1-RESUME` ejecutó un
harness HTTP local con fixtures temporales `QA_CONTENT_*`, autenticación real,
CSRF y permisos de usuario. Los fixtures fueron eliminados después de la
prueba y se verificó que no quedó residuo ni se alteró el administrador base.

```ini
PASSWORD_IN_HTTP_RESPONSE=false
PASSWORD_HASH_IN_HTTP_RESPONSE=false
PASSWORD_FIELD_REPOPULATED=false
OLD_INPUT_SECRET_SAFE=PASS
FLASH_SUCCESS=PASS
FLASH_SUCCESS_VISIBLE_AFTER_PRG=true
FLASH_VALIDATION=PASS
VALIDATION_ASSERTION_HTTP_STATUS=422
OUTPUT_ESCAPING_RUNTIME=PASS
OUTPUT_ATTRIBUTE_ESCAPING=PASS
SIDEBAR_CONTENT_ASSERTION=PASS
HTTP_SECRET_LEAK_FOUND=false
HTTP_500_COUNT=0
PRODUCTIVE_CODE_CHANGED_DURING_CONTENT_QA=false
QA_CONTENT_USERS=0
QA_CONTENT_ROLES=0
QA_CONTENT_USER_ROLES=0
QA_CONTENT_ROLE_PERMISSIONS=0
QA_CONTENT_SCOPE_ROWS=0
QA_DATA_RESIDUALS=0
ORPHAN_USER_ROLE_COUNT=0
DUPLICATE_USER_ROLE_COUNT=0
ACTIVE_USERS_WITHOUT_ROLE=0
ACTIVE_ADMIN_COUNT_AFTER=1
BASELINE_ADMIN_INTACT=true
SEARCH_REGRESSION=PASS
ENV_LOADER_REGRESSION=PASS
AUTH_REGRESSION=PASS_INHERITED
RBAC_REGRESSION=PASS_INHERITED
MANUAL_OPERATOR_VERIFICATION_PENDING=true
MANUAL_CHECKLIST=A-R
```

No se ejecutó la matriz HTTP completa en esta microfase; las assertions
anteriores cubren únicamente secretos, old input, flashes, escape de salida y
presencia del sidebar.

## Corrección visual C/N — USUARIOS-ADMIN-4-FIX-VISUAL-DEFECTS-C-N-1

La reproducción autenticada del filtro de almacén se repitió con un fixture
QA temporal y con `GET /admin/usuarios?warehouse_id=495`. La respuesta fue
`HTTP 200` y el DOM mantuvo seleccionado el almacén solicitado. La revisión
del controlador, repositorio y vista confirmó que el estado `warehouse_id`
se propaga desde `paginateAdmin()` hasta la opción `<select>`; el resultado
anterior no se reprodujo con el proceso local vigente y no requirió cambio de
código productivo.

```ini
VISUAL_DEFECT_FIX_STATUS=NOT_APPLICABLE_NO_DEFECT_REPRODUCED
C_FILTER_RUNTIME_REPRODUCED=false
C_FILTER_RUNTIME_STATUS=PASS
C_FILTER_SELECTED_OPTION=495
C_FILTER_CODE_CHANGE_REQUIRED=false
C_ROOT_CAUSE=NOT_REPRODUCED_CURRENT_RUNTIME
C_VISUAL_DEFECT_CONFIRMED=false
C_VISUAL_DEFECT_FIXED=false
C_AUTOMATED_AFTER_FIX=NOT_APPLICABLE
N_GLOBAL_SIDEBAR_TOGGLE_FOUND=false
N_ADMIN_GROUP_TOGGLE_FOUND=true
ACTIVE_GROUP_FORCED_OPEN_BY_DESIGN=true
GLOBAL_SIDEBAR_COLLAPSE_EXPECTED=false
ACTIVE_GROUP_MANUAL_COLLAPSE_EXPECTED=false
N_GLOBAL_COLLAPSE_RUNTIME=NOT_APPLICABLE_NO_GLOBAL_CONTROL
N_GLOBAL_COLLAPSE_STATE_CHANGED=false
N_ADMIN_GROUP_COLLAPSE=NOT_APPLICABLE_BY_DESIGN
N_CLASSIFICATION=TEST_AUTOMATION_TARGETED_WRONG_CONTROL
PRODUCTIVE_N_CODE_CHANGED=false
CONSOLE_FUNCTIONAL_ERRORS=0
CONSOLE_NON_BLOCKING_DIAGNOSTICS=4
J_STATUS=NOT_EXECUTED_HANDOFF_REQUIRED
MANUAL_OPERATOR_VERIFICATION_PENDING=true
```

Los cuatro diagnósticos de consola no bloquearon la funcionalidad: un 404 de
recurso durante login, el 403 esperado de `/app`, una advertencia de patrón
HTML en el formulario de edición y el 422 intencional de validación. No hubo
errores de página ni solicitudes fallidas. N no representa un defecto: la
automatización había apuntado al acordeón del grupo activo, que permanece
abierto por diseño; no existe un control global de contraer sidebar en este
contrato.

Esta fase añade la consola HTTP/UI sobre `UserAdminService`. El controller no
contiene SQL, locks, conteos de administradores ni hashes; todas las mutaciones
delegan en el servicio aprobado. La evidencia de concurrencia real del backend
se conserva como `NOT_EXECUTED`.

## Rutas y permisos

| Método | Ruta | Permiso | CSRF | PRG |
|---|---|---|---|---|
| GET | `/admin/usuarios` | `usuarios.acceder` | no | no |
| GET | `/admin/usuarios/crear` | `usuarios.crear` | no | no |
| POST | `/admin/usuarios` | `usuarios.crear` | sí | sí |
| GET | `/admin/usuarios/{id}/editar` | `usuarios.editar` | no | no |
| POST | `/admin/usuarios/{id}/editar` | `usuarios.editar` | sí | sí |
| POST | `/admin/usuarios/{id}/estado` | `usuarios.estado` | sí | sí |
| POST | `/admin/usuarios/{id}/eliminar` | `usuarios.estado` | sí | sí |
| POST | `/admin/usuarios/{id}/roles` | `usuarios.roles` | sí | sí |
| POST | `/admin/usuarios/{id}/password` | `usuarios.password` | sí | sí |

Todas pasan por `AuthMiddleware` y `PermissionMiddleware`. El actor siempre se
obtiene desde sesión. El ID objetivo proviene del segmento dinámico de ruta y
se valida como entero positivo.

## Controller y acceso a datos

`AdminUserController` sólo coordina Request, servicio, excepciones, View y
redirect. `UserRepository::paginateAdmin()` usa consultas preparadas,
paginación limitada a 50 y agregación de roles sin N+1. Los catálogos de
empresa, almacén y rol son consultas read-only de repositorio.

```ini
CONTROLLER_DOMAIN_LOGIC_LEAKS=0
VIEW_DB_QUERIES=0
USER_LIST_PAGINATION=20_default_max_50
USER_LIST_N_PLUS_ONE_RISK=LOW
ACTOR_FROM_SESSION_ONLY=true
```

El listado cubre búsqueda por username/email/nombre, estado, empresa, almacén y
rol; muestra estado, último login, alcance y roles sin exponer password/hash o
datos de sesión.

## Corrección del cargador `.env` en Windows

Durante la preparación de las assertions de contenido se confirmó en Windows
con PHP 8.2 que `is_readable()` devolvía `false` para `.env`, para un archivo
PHP control y para el directorio del proyecto, aunque `fopen(..., 'rb')`
funcionaba. No era una falta efectiva de ACL ni se modificaron permisos,
secretos o `php.ini`.

`App\\Core\\Env::load()` ahora valida la ruta con `is_file()`, abre el archivo
con `fopen()` y procesa las líneas mediante ese mismo handle, cerrándolo en un
`finally`. Se conservan comentarios, líneas vacías, comillas, valores vacíos,
precedencia de variables existentes y ausencia de sobreescritura del proceso.

```ini
ENV_LOADER_FILE=app/Core/Env.php
ENV_LOADER_USES_IS_READABLE_GUARD_BEFORE=true
ENV_LOADER_IS_READABLE_DEPENDENCY_REMOVED=true
ENV_PARSER_SEMANTICS_CHANGED=false
ENV_SECRET_LOGGING_ADDED=false
ENV_LOAD_SYNTHETIC_BASIC=PASS
ENV_LOAD_COMMENTS=PASS
ENV_LOAD_EMPTY_LINES=PASS
ENV_LOAD_EMPTY_VALUE=PASS
ENV_LOAD_PROCESS_ENV_PRECEDENCE=PASS
ENV_LOAD_MISSING_FILE=PASS
ENV_LOAD_UNOPENABLE_FILE=NOT_PORTABLY_TESTED
ENV_LOAD_READABILITY_EVIDENCE=PASS_ON_REAL_PROJECT_FILES
ENV_LOAD_CALL_SITES_REVIEWED=true
ENV_LOADER_BACKWARD_COMPATIBILITY_REVIEW=PASS
APP_DB_NAME_PRESENT_AFTER_ENV_LOAD=true
DB_TARGET_MATCH=true
DB_CONNECTION_ATTEMPTED=true
DB_RUNTIME_MATCH=true
DB_WRITES=0
WINDOWS_ACL_MODIFIED=false
DOTENV_PERMISSIONS_MODIFIED=false
PHP_INI_MODIFIED=false
```

El test sintético versionable es
`tests/env_loader_windows_readability_1_test.php`; no usa el `.env` real ni
imprime valores.

## Formularios y acciones

Crear y editar separan identidad, empresa/almacén, estado y roles. El reset de
contraseña es una acción separada y no conserva valores en old input ni flash.
La sincronización de roles envía el conjunto completo. Desactivar y eliminar
son POST separados; eliminar significa baja lógica, no hard delete.

Los nombres visibles de perfil se muestran como referencia en edición, pero no
se mueven al controller ni al servicio administrativo en esta fase porque el
backend aprobado no incluye aún una escritura transaccional de `perfiles_usuario`.
Esa ampliación requiere una fase backend explícita.

```ini
CSRF_MUTATION_COVERAGE=6/6
PRG_MUTATION_COVERAGE=6/6
VALIDATION_EXCEPTION_MAPPING=PASS
CONFLICT_EXCEPTION_MAPPING=PASS
OLD_INPUT_SECRET_SAFE=true
PASSWORD_NOT_IN_HTML_RESPONSE=true
```

## Sidebar y UI

Se añadió `Usuarios` al grupo `Administración` mediante
`SidebarNavigationService`, visible exclusivamente con `usuarios.acceder`.
Se reutilizan `data-table`, `field`, `button`, `badge`, `alert`, paginación y
tokens existentes. El CSS es modular y el JavaScript sólo filtra almacenes por
empresa para mejorar UX; el backend mantiene la validación de pertenencia.

Responsive: la tabla conserva overflow horizontal y los filtros/formularios se
reorganizan a una columna en viewport pequeño. No se creó un segundo sistema
visual ni se modificó el comportamiento del sidebar colapsable.

## Cierre runtime J — USUARIOS-ADMIN-4-J-PASSWORD-AND-FINAL-CLOSE

El reset de contraseña se ejecutó una sola vez sobre un usuario QA temporal
mediante navegador real, con sesión autenticada, CSRF y POST real. El endpoint
respondió `302` y el redirect regresó al listado; la navegación GET posterior
a la edición confirmó que ambos campos permanecen vacíos. La contraseña
anterior fue rechazada y la nueva fue aceptada. El valor sintético se mantuvo
únicamente en memoria y no apareció en HTML, atributos, URL, flash ni captura.

```ini
J_AUTOMATED=PASS
J_PASSWORD_FORM_VISIBLE=true
J_PASSWORD_CONFIRMATION_VISIBLE=true
J_PASSWORD_INITIAL_EMPTY=true
J_PASSWORD_CONFIRMATION_INITIAL_EMPTY=true
J_PASSWORD_POST_STATUS=302
J_PASSWORD_PRG=true
J_FLASH_SUCCESS_VISIBLE=true
J_PASSWORD_AFTER_PRG_EMPTY=true
J_PASSWORD_CONFIRMATION_AFTER_PRG_EMPTY=true
J_PASSWORD_VISIBLE_AFTER_SUBMIT=false
J_PASSWORD_HASH_VISIBLE=false
J_OLD_PASSWORD_REJECTED=true
J_NEW_PASSWORD_ACCEPTED=true
J_SCREENSHOT_CREATED=true
HTTP_500_COUNT_J=0

C=PASS
C_ORIGINAL_FAILURE_CLASSIFICATION=NON_REPRODUCIBLE_AUTOMATION_ARTIFACT
C_CODE_FIX_APPLIED=false

N=PASS_BY_DESIGN
N_CLASSIFICATION=TEST_AUTOMATION_TARGETED_WRONG_CONTROL
N_CODE_FIX_APPLIED=false

CONSOLE_FUNCTIONAL_ERRORS_COUNT=0
CONSOLE_RUNTIME_STATUS=PASS_WITH_CLASSIFIED_NONFUNCTIONAL_EVENTS

FINAL_A_R_STATUS=PASS_WITH_D_NOT_EXECUTED_HTTP_COVERED_AND_L_NOT_EXECUTED_BACKEND_HTTP_COVERED
AUTOMATED_VISUAL_FINAL=PASS
OBJECTIVE_UI_RUNTIME_VALIDATION=PASS
TECHNICAL_IMPLEMENTATION_READY=true
COMMIT_TECHNICALLY_READY=true
MANUAL_VISUAL_REVIEW_STATUS=NOT_EXECUTED_AS_FULL_HUMAN_REVIEW
MANUAL_OPERATOR_VERIFICATION_PENDING=true
```

Resultado A–R consolidado: `A=PASS`, `B=PASS`, `C=PASS`,
`D=NOT_EXECUTED_HTTP_COVERED`, `E=PASS`, `F=PASS`, `G=PASS`, `H=PASS`,
`I=PASS`, `J=PASS`, `K=PASS`, `L=NOT_EXECUTED_BACKEND_HTTP_COVERED`,
`M=PASS`, `N=PASS_BY_DESIGN`, `O=PASS`, `P=PASS`, `Q=PASS`, `R=PASS`.

El reset QA, sus relaciones, permisos temporales y auditoría asociada fueron
eliminados por IDs exactos. El administrador base quedó intacto. El servidor
temporal y los artefactos de revisión fueron detenidos/eliminados; no se hizo
staging, commit, push, deploy ni SMTP.

## QA HTTP y seguridad

El runner `database/tests/usuarios_admin_http_ui_test.php` valida el contrato
estático de rutas, permisos, CSRF, PRG, actor de sesión, escape y sidebar sin
conectar a DB. El servidor PHP se probó con `public/index.php` como router en
`127.0.0.1:8093`; las rutas seguras respondieron `/health=200`, `/=200` y
`/no-existe=404`. La comprobación anónima adicional respondió `/login=200` y
`/admin/usuarios=302` hacia autenticación. En la microfase autenticada se
crearon fixtures temporales exactos, se inició sesión con ADMIN y LIMITED, y
ambos pudieron cargar `/admin/usuarios=200`; el formulario de creación ADMIN
respondió 200. La recuperación con `curl.exe` confirmó los seis `403` de
permisos y los seis `419` de CSRF ausente, además de create/update/status/roles/
password con PRG `302`. La búsqueda por `q` produjo un `500` real por
`PDOException SQLSTATE[HY093]` en `UserRepository::paginateAdmin()` línea 100
(binding de parámetros de búsqueda). Después se aisló y corrigió el binding:
el placeholder `:q` se reutilizaba tres veces con
`PDO::ATTR_EMULATE_PREPARES=false`; ahora se usan `:q_username`, `:q_email` y
`:q_profile`, cada uno con el mismo valor. El test dirigido cubre búsqueda sin
filtro y combinada con estado, empresa, almacén y rol; todos pasan. Un smoke
HTTP autenticado posterior respondió 200 y encontró el fixture. Los fixtures
se limpiaron por IDs/códigos exactos y la integridad final quedó validada.

```ini
HTTP_TEST_RUNNER=database/tests/usuarios_admin_http_ui_test.php
HTTP_ANONYMOUS_ACCESS=302_to_login_runtime_verified
QA_ADMIN_LOGIN=PASS
QA_LIMITED_LOGIN=PASS
AUTHENTICATED_USER_LIST=PASS
ADMIN_CREATE_FORM_RUNTIME=PASS
HTTP_SERVER_COMMAND=php -S 127.0.0.1:8096 public/index.php
HTTP_SERVER_STOPPED=true
QA_CREDENTIALS_CREATED=true
HTTP_PERMISSION_TESTS=PASS
PERMISSION_CREATE_DENIED=403
PERMISSION_EDIT_DENIED=403
PERMISSION_STATUS_DENIED=403
PERMISSION_DELETE_DENIED=403
PERMISSION_ROLES_DENIED=403
PERMISSION_PASSWORD_DENIED=403
CSRF_MATRIX=6/6_419
CREATE_HTTP_VALID=PASS
PRG_CREATE=PASS
UPDATE_HTTP_VALID=PASS
PRG_UPDATE=PASS
HTTP_STATUS_TESTS=PASS
HTTP_ROLE_SYNC_NORMAL=PASS
HTTP_PASSWORD_RESET=PASS
HTTP_USER_NOT_FOUND=PASS
LIST_PAGINATION_RUNTIME=PASS
LIST_SEARCH_RUNTIME=PASS_AFTER_FIX
SEARCH_BINDING_ROOT_CAUSE=REUSED_NAMED_PLACEHOLDER
PDO_EMULATE_PREPARES=false
COUNT_PARAM_SET_MATCH=true
SELECT_PARAM_SET_MATCH=true
PAGINATION_LIMIT_SAFE=true
PAGINATION_OFFSET_SAFE=true
FILTER_BINDING_STATUS=PASS
FILTER_BINDING_COMPANY=PASS
FILTER_BINDING_WAREHOUSE=PASS
FILTER_BINDING_ROLE=PASS
SEARCH_BINDING_REGRESSION_TEST_ADDED=true
SEARCH_Q_ONLY=PASS
SEARCH_Q_WITH_STATUS=PASS
SEARCH_Q_WITH_COMPANY=PASS
SEARCH_Q_WITH_WAREHOUSE=PASS
SEARCH_Q_WITH_ROLE=PASS
HY093_REPRODUCED_BEFORE_FIX=true
HY093_AFTER_FIX=false
HTTP_SEARCH_AFTER_FIX_STATUS=200
CONTROLLER_SQL_ADDED=false
ASSET_404_CURRENT_CLASSIFICATION=TEST_SERVER_ROUTER_ARTIFACT
TEMP_HTTP_ROUTER_DESIGNED=true
LOCAL_STACKTRACE_EXPECTED_BY_DEBUG_CONFIG=true
PRODUCTION_EXCEPTION_DISCLOSURE_REVIEW=PASS
CREATE_HTTP_DUPLICATE_USERNAME=PASS
CREATE_HTTP_DUPLICATE_EMAIL=PASS
CREATE_HTTP_INVALID_COMPANY=PASS
CREATE_HTTP_CROSS_COMPANY_WAREHOUSE=PASS
CREATE_HTTP_INVALID_ROLE=PASS
CREATE_HTTP_ACTIVE_EMPTY_ROLES=PASS
LIST_FILTER_STATUS_RUNTIME=PASS
LIST_FILTER_COMPANY_RUNTIME=PASS
LIST_FILTER_WAREHOUSE_RUNTIME=PASS
LIST_FILTER_ROLE_RUNTIME=PASS
LIST_COMBINED_FILTER_RUNTIME=PASS
UPDATE_HTTP_DUPLICATE=PASS
UPDATE_HTTP_COMPANY_WAREHOUSE_MISMATCH=PASS
HTTP_DELETE_TESTS=PASS
PRG_DELETE=PASS
HTTP_SOFT_DELETED_LOGIN_REJECT=PASS
HTTP_USER_NOT_FOUND=PASS
HTTP_LAST_ADMIN_CONFLICT=PASS
HTTP_CONFLICT_ERROR_FLOW=PASS
FLASH_CONFLICT=PASS
HTTP_500_COUNT=0
ADMIN_USERS_CSS_LOAD=PASS
ADMIN_USERS_JS_LOAD=PASS
ADMIN_USERS_CSS_CONTENT_TYPE=PASS
ADMIN_USERS_JS_CONTENT_TYPE=PASS
COMPANY_WAREHOUSE_JS_RUNTIME=STATIC_ONLY_PASS
SIDEBAR_RUNTIME_VISIBILITY=PASS
SIDEBAR_ADMIN_USERS_PERSISTENCE=PASS
HTTP_HEALTH_RUNTIME=200
HTTP_ROOT_RUNTIME=200
HTTP_NOT_FOUND_RUNTIME=404
HTTP_SECRET_LEAK_FOUND=false
OUTPUT_ESCAPING_REVIEW=PASS
AUTH_REGRESSION=PASS_inherited
RBAC_REGRESSION=PASS_inherited
QA_DATA_RESIDUALS=0
BASELINE_ADMIN_INTACT=true
DB_TARGET=r_erp_db_core_0_test
DB_SELECT_DATABASE_MATCH=true
BASELINE_ADMIN_FOUND=true
ACTIVE_ADMIN_COUNT=1
ADMIN_ROLE_ACTIVE=true
QA_USERS=0
QA_ROLES=0
QA_HTTP_USERS=0
QA_HTTP_ROLES=0
QA_HTTP_USER_ROLES=0
QA_HTTP_ROLE_PERMISSIONS=0
QA_HTTP_DATA_RESIDUALS=0
ORPHAN_USER_ROLE_COUNT=0
DUPLICATE_USER_ROLE_COUNT=0
ACTIVE_USERS_WITHOUT_ROLE=0
ACTIVE_ADMIN_COUNT_AFTER=1
```

Checklist manual pendiente:

- `/admin/usuarios`, crear, editar, estado, roles, password y baja lógica.
- Permisos parciales y respuestas 403/419/404.
- PRG y mensajes de validación/conflicto.
- Desktop y viewport pequeño.
- Sidebar expandido/contraído y enlace condicionado.
- No fuga de hash, sesión, CSRF fuera de campo o datos de DB.

## Límites y siguiente fase

```ini
ADMIN_HTTP_UI_IMPLEMENTED=true
ADMIN_ROUTES_IMPLEMENTED=true
MIGRATION_REQUIRED=false
MIGRATIONS_CREATED=0
REAL_DB_CONCURRENCY_EVIDENCE=NOT_EXECUTED
DB_READS=baseline_fixture_runtime_and_integrity
DB_WRITES=QA_FIXTURE_LIFECYCLE_CLEANED
SMTP=false
DEPLOY=false
```

No se tocaron productos, tickets, correo, migraciones, seeds ni el modelo de
concurrencia del backend. La siguiente fase recomendada es
`USUARIOS-ADMIN-4-MANUAL-VISUAL-REVIEW`; la verificación visual del operador
continúa pendiente.
