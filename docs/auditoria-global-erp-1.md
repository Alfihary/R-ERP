# ERP-AUDITORIA-GLOBAL-1

Fecha de corte: 2026-10-07
Modo: auditoría read-only
Rama auditada: `jesus`
Commit auditado: `6d3bb94 feat(mail): add controlled single-outbox SMTP QA send`

## 1. Alcance y restricciones

Esta auditoría revisa el estado real del checkout local: Git, arquitectura MVC,
bootstrap, rutas, middleware, PHP, Composer, base de datos, módulos, navegación,
pruebas, documentación y readiness de despliegue. No se modificó código ni
configuración, no se ejecutaron migraciones/seeds, no se abrió SMTP, no se ejecutó
`process`, no se hizo retry, staging, commit, push, merge, rebase ni deploy.

La única escritura autorizada por la fase es este documento. Las consultas de base
de datos fueron `SELECT`/`SHOW` únicamente y se restringieron a
`r_erp_db_core_0_test`; no se publican credenciales, DSN, destinatarios ni secretos.

## 2. Veredicto ejecutivo

**AUDIT_STATUS=PASS_WITH_FINDINGS**. El checkout es coherente y compilable, tiene
una arquitectura modular PHP/MVC amplia, controles de sesión/CSRF/RBAC y una base
de datos integrada. El estado no es todavía apto para declarar deploy listo: la
compatibilidad real de AwardSpace, CLI/cron, document root privado, límites,
storage compartido y SMTP de hosting continúan sin evidencia `ACCOUNT_TEST`.

Hallazgos prioritarios:

1. **Alto — hosting no verificado.** El documento de prerrequisitos conserva
   `READINESS=INCOMPLETE_VERIFICATION` y `HOSTING_PREREQUISITE_PENDING=true`.
2. **Medio — configuración CLI.** En la primera consulta, el bootstrap de base
   recibió configuración DB efectiva incompleta; para completar la auditoría se
   cargaron en memoria los valores ya existentes en `.env` sin imprimirlos. Debe
   verificarse la precedencia de `Env::load`/variables del proceso antes de usar
   CLI en producción.
3. **Medio — cobertura documental.** Hay 137 documentos y 99 coincidencias de
   estados (`UNKNOWN`, `PENDING`, `TODO`, `READY`, etc.); deben gobernarse por fase
   para evitar confundir evidencia local con aceptación de hosting.
4. **Bajo — deuda de prueba.** Hay 109 DB-TEST y muchos escenarios de UI/mail,
   pero esta auditoría no los reejecutó porque la fase es read-only y no debe
   procesar outbox ni tocar datos.

## 3. Git y trazabilidad

| Campo | Resultado |
|---|---|
| Rama actual | `jesus` |
| Upstream | `origin/jesus` |
| Ahead/behind | `0 / 0` |
| HEAD | `6d3bb94351f0e8d389a2ded43907e824f0e784a0` |
| Staging | vacío |
| Cambios versionables | ninguno |
| Ignorados esperados | `.env`, `node_modules/`, `storage/private/`, uploads privados, `vendor/` |
| Main | sin modificar (`07ce383`) |

Últimos commits relevantes: `6d3bb94` (envío QA controlado de una outbox),
`c7d5361` (auditoría de readiness), `9c91b71` (intención de fixture),
`426fa1e` (override CLI), `836057a` (contrato documental), `07ce383` (merge base).

## 4. Mapa de arquitectura

- `public/index.php` es el único entry point público; retira `X-Powered-By`, carga
  `bootstrap/app.php` y envía la respuesta.
- `bootstrap/app.php` registra autoload PSR-4 local, constantes de paths, timezone,
  manejo de errores, sesión, conexión PDO, middleware global y rutas.
- `bootstrap/database.php` expone una configuración para consultas/CLI sin crear
  conexiones adicionales por sí mismo.
- `app/Core` contiene `App`, `Router`, `Request`, `Response`, `View`, `Config` y
  `Env`; la aplicación no depende de Laravel ni de una SPA.
- `app/Http/Controllers`, `Services`, `Repositories`, `Infrastructure` y
  `Views` separan presentación, dominio, persistencia y renderizado.
- `routes/web.php` centraliza las rutas y compone middleware por alcance/permiso.
- `database/migrations`, `database/seeds` y `database/tests` mantienen la
  construcción por fases.

Inventario PHP versionado fuera de `vendor/` y `node_modules/`: **502** archivos
(app 225, bootstrap 3, config 6, database 266, public 1, routes 1).

Cobertura explícita del informe: arquitectura, Git, migraciones/seeds, seguridad,
RBAC, rutas, módulos, sidebar/navbar, UI/temas, productos, inventario, tickets,
correo, usuarios, empresas/almacenes, catálogos, perfil/vCard, pruebas,
documentación, readiness de deploy, deuda técnica, findings, matriz de módulos,
matriz de sidebar, sidebar propuesto y roadmap priorizado.

## 5. Calidad PHP y Composer

- `php -l` sobre los 502 PHP: **502 PASS / 0 FAIL**.
- `composer validate --strict`: válido; exit no-cero únicamente por la advertencia
  conocida de pin exacto de `openspout/openspout`.
- `composer.json` y `composer.lock`: sin diferencias durante la auditoría.
- Dependencias declaradas: PHPMailer `^6.12` (lock `6.12.0`) y OpenSpout exacto
  `4.28.5` (lock `4.28.5`).
- No se ejecutó `composer install`, `composer update` ni `composer require`.

## 6. Rutas y superficie HTTP

Conteo estático en `routes/web.php`: **146 registros**: 72 GET, 74 POST, 0 PUT,
0 PATCH y 0 DELETE. Se observaron 58 instanciaciones explícitas de middleware.

| Área | Evidencia |
|---|---|
| Pública | `/`, `/health`, `/login`, `/v/{slug}` y recursos públicos de vCard |
| Sesión | `/login`, `/logout`, `/app`, `/app/contexto` |
| Cuenta | `/perfil`, contraseña, foto, credencial y vCard |
| Productos | CRUD, imagen y flujo importar/validar/confirmar/descartar |
| Tickets | listado, alta, detalle, partidas, comentarios, adjuntos, aprobar/rechazar/cancelar |
| Inventario | existencias, kardex, movimientos y transferencias |
| Configuración | empresas, almacenes, folios, listas de precios, correo y auditoría |
| Catálogos | catálogos generales, clasificaciones, tipos de cambio y SAT |

Las rutas sensibles se construyen con `AuthMiddleware`, `PermissionMiddleware` y
`CsrfMiddleware` según el grupo; no se encontró un endpoint de base de datos ni
la integración de `/health` con MySQL.

## 7. Seguridad estática

**Fortalezas observadas:** sesiones con regeneración en autenticación, CSRF para
acciones mutantes, headers de seguridad, errores ocultos en producción,
`X-Powered-By` removido, `password_hash`/`password_verify`, PDO con prepares y
middleware de autenticación/permisos. Las vistas usan helpers de escape en los
puntos auditados.

**Puntos a vigilar:**

- La separación de `public/`, `storage/private/`, `.env` y `vendor/` depende del
  document root correcto del hosting; debe probarse en `ACCOUNT_TEST`.
- Cualquier alta de ruta nueva debe conservar el patrón Auth + permiso + CSRF y
  validación de empresa/almacén (`UserScopeService`).
- Las subidas y descargas privadas requieren repetir la revisión de MIME, tamaño,
  nombre/ruta y autorización al incorporar nuevos módulos.
- La auditoría no realizó ataques, fuzzing ni pruebas activas por restricción de
  solo lectura.

## 8. Base de datos: inventario read-only

La conexión fue validada contra la base autorizada `r_erp_db_core_0_test`; no se
crearon bases ni se emitieron escrituras.

| Métrica | Resultado |
|---|---:|
| Tablas | 53 |
| Tablas InnoDB | 53 |
| FKs reportadas por `information_schema` | 193 |
| Grupos de índices | 374 |
| Migraciones en disco | 23 |
| Filas `schema_migrations` | 23 |
| Seeds en disco | 29 |
| DB-TEST en disco | 109 |

Las tablas usan `utf8mb4_unicode_ci` e InnoDB. El modelo cubre identidad/RBAC,
empresas/almacenes/scope, catálogos, productos/precios, inventario, tickets,
folios, auditoría, credenciales, vCards y correo.

### RBAC y datos de control

- `usuarios`: 1; `roles`: 1; `permisos`: 119; `usuario_roles`: 1;
  `rol_permisos`: 119; `auditoria_eventos`: 103.
- Índices únicos presentes en `usuarios.email`, `usuarios.username`,
  `roles.codigo`, `permisos.codigo` y `tickets_productos_correos.dedupe_key`.
- No se observó un rol/permiso funcional fuera del modelo existente que deba
  agregarse durante esta auditoría.

### Outbox y evidencia QA

Estado de las seis filas actuales de `tickets_productos_correos`:

| Estado | Filas |
|---|---:|
| `CANCELADO` | 4 |
| `ENVIADO` | 2 |
| `PENDIENTE`/`ENVIANDO` elegibles | 0 |

La evidencia persistida confirma:

- id 36, ticket 34: `ENVIADO`, intentos 1, `enviado_at` presente.
- id 1610, ticket 197: `ENVIADO`, intentos 1, `enviado_at` presente.
- id 1: `CANCELADO`.

No se creó ninguna fila, no se cambió estado, no se ejecutó SMTP, `process` ni
retry durante esta auditoría.

## 9. Módulos y estado funcional

| Módulo | Estado de evidencia | Nota |
|---|---|---|
| Config/bootstrap | IMPLEMENTADO | entry point, config, autoload y errores seguros |
| Auth/sesiones | IMPLEMENTADO | login username/email, logout y sesión mínima |
| RBAC/scope | IMPLEMENTADO | permisos y alcance por empresa/almacén |
| Perfil/credenciales/vCard | IMPLEMENTADO | rutas, servicios y vistas presentes |
| Empresas/almacenes/folios | IMPLEMENTADO | CRUD protegido y trazabilidad |
| Catálogos/SAT | IMPLEMENTADO | vistas, servicios y permisos presentes |
| Productos | IMPLEMENTADO | CRUD, imagen y estado |
| Importación productos | IMPLEMENTADO/PARCIAL | CSV y lector XLSX/preview/confirmación; hosting XLSX pendiente |
| Precios | IMPLEMENTADO | listas, precios, historial y autorizaciones |
| Inventario | IMPLEMENTADO/PARCIAL | existencias, kardex, movimientos y transferencias; datos operativos aún acotados |
| Tickets productos | IMPLEMENTADO/PARCIAL | partidas, estados, adjuntos, comentarios y correo |
| Mail/outbox | IMPLEMENTADO/PARCIAL | plantillas, reglas, cola, acciones y transporte; scheduler real pendiente |
| Auditoría | IMPLEMENTADO | eventos y consulta protegida |
| Deploy AwardSpace | BLOQUEADO | faltan pruebas reales de cuenta/hosting |

No se clasifican como iniciados en esta fase nuevos módulos funcionales fuera de
lo ya presente en el checkout.

## 10. Sidebar y navegación

La fuente única observada es `app/Views/layouts/app.php`. La navegación actual
agrupa Inicio, Mi cuenta, Catálogos, Productos, Precios, Tickets, Inventario y
Configuración. Incluye correo/outbox y auditoría bajo Configuración y condiciona
entradas a permisos.

Propuesta de gobierno (no ejecutada): conservar esas secciones, no agregar nuevas
entradas hasta que exista fase aprobada, permiso propio, ruta protegida, prueba y
documentación. Mantener la navegación de correo separada de operaciones de
productos/tickets para reducir riesgo de envíos accidentales.

### Navbar, UI y temas

El layout de aplicación auditado contiene la navegación lateral y el encabezado
operativo asociado; no se propone reorganizarlo en esta fase. Las vistas y hojas
de estilo existentes se clasifican como UI administrativa modular, no como un
tema final único. No se agregaron componentes visuales, CSS, JavaScript ni enlaces
de navegación. Cualquier reorganización futura debe comparar ruta, permiso y
estado funcional antes de mostrar una entrada.

## 11. Pruebas, documentación y deuda

- Documentación Markdown: **137** archivos.
- Coincidencias de estados contractuales o pendientes: **99**; predominan
  `UNKNOWN`, `PENDING` y `HOSTING_PREREQUISITE_PENDING` en documentos de fases.
- DB-TEST disponibles: **109**; incluyen RBAC, productos, importación, precios,
  inventario, tickets, vCard, mail y UI. No se reejecutaron pruebas mutantes en
  este audit porque podrían escribir DB, outbox o archivos.
- La prueba FakeMail/SMTP QA de la fase previa queda como evidencia histórica
  (`12/12` en memoria SQLite); no se repitió el envío real.

Las migraciones registradas son 23 y coinciden con las 23 filas observadas en
`schema_migrations`; no se detectó drift de conteo en esta auditoría. Los 29
seeds y 109 DB-TEST permanecen versionados, pero no fueron ejecutados durante el
cierre read-only.

Deuda recomendada: mantener un índice de fases cerradas/abiertas, marcar
explícitamente qué documentos son históricos, y automatizar una matriz de rutas
contra permiso/CSRF/scope sin activar side effects.

## 12. Readiness de deploy

`DEPLOY_READY=false`. La evidencia local confirma PHP 8.2.12, Composer 2.9.7,
PHPMailer/OpenSpout en `vendor/`, extensiones locales y `flock()` local. Eso no
prueba AwardSpace.

Bloqueadores de hosting aún abiertos: cron real y frecuencia/cuota, PHP CLI y
versión web efectiva, extensiones, document root privado, permisos y cuota de
`storage/private`, despliegue de `vendor/`, lock contra solapamiento, límites de
memoria/tiempo/upload, logs/rotación, filesystem compartido, lectura segura de
`.env` desde CLI y conectividad SMTP/TLS. El siguiente paso correcto sigue siendo
`AWARDSPACE-SCHEDULER-ACCOUNT-TEST-1`; no fue iniciado aquí.

## 13. Roadmap recomendado (sin ejecutar)

1. Resolver la anomalía de precedencia de entorno en CLI y añadir una prueba no
   destructiva de configuración efectiva.
2. Ejecutar `ACCOUNT_TEST` en AwardSpace con checklist y evidencia enmascarada.
3. Mantener DB-TEST aislado y ampliar pruebas read-only de integridad/orfandad.
4. Revisar accesos de cada nueva ruta antes de abrir módulos operativos.
5. Separar cierre documental de implementación/SMTP/deploy y conservar los
   commits publicados en `jesus` hasta una integración revisada a `main`.

## 14. Resumen de consola

```text
AUDIT_STATUS=PASS_WITH_FINDINGS
BRANCH=jesus
HEAD=6d3bb94351f0e8d389a2ded43907e824f0e784a0
WORKTREE_VERSIONABLE_CLEAN=true
STAGING_EMPTY=true
PHP_FILES=502
PHP_LINT_PASS=502
PHP_LINT_FAIL=0
ROUTES_TOTAL=146
ROUTES_GET=72
ROUTES_POST=74
DB_TABLES=53
DB_FKS=193
DB_INDEX_GROUPS=374
MIGRATIONS=23
SEEDS=29
DB_TESTS=109
DOCS_MARKDOWN=137
HOSTING_PREREQUISITE_PENDING=true
DEPLOY_READY=false
SMTP_CONNECTION=false
OUTBOX_WRITES=false
CODE_MODIFIED=false
ENV_MODIFIED=false
STAGING=false
COMMIT=false
PUSH=false
MERGE=false
REBASE=false
DEPLOY=false
NEXT_PHASE=AWARDSPACE-SCHEDULER-ACCOUNT-TEST-1
```

## 15. Cierre de la auditoría

La fase queda documentada como auditoría global read-only con hallazgos
controlados. No se avanzó a una fase funcional nueva, no se modificó `main`, no
se hizo staging/commit y no se alteraron código, `.env`, esquema, seeds, outbox ni
el estado de los correos QA.
