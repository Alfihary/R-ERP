# ERP-MODULOS-GAP-ANALYSIS-1

Fecha de corte: 2026-10-07
Modo: análisis funcional read-only
Rama: `jesus`
HEAD: `36484ed feat(ui): add collapsible sidebar groups`
Base autorizada para evidencia previa: `r_erp_db_core_0_test`

## 1. Executive summary

El ERP tiene una base PHP/MVC modular amplia y funcionalidad real en identidad,
scope, catálogos, productos, precios, inventario, tickets, correo, auditoría,
perfil y vCard. La navegación actual expone 20 accesos, pero no representa todo
lo que existe en backend: usuarios, permisos y varias capacidades auxiliares no
tienen módulo administrativo visible.

El análisis distingue implementación local de readiness de hosting. No se
ejecutaron migraciones, seeds, pruebas mutantes, SMTP, `process`, retry, cambios
de DB ni llamadas a AwardSpace. La documentación global usada como fuente
secundaria fue creada antes del HEAD actual; sus conteos históricos se señalan
como tales y se confrontaron con el código presente.

Veredicto: `PASS_WITH_FINDINGS`. El siguiente módulo funcional recomendado es
`ERP-OPERACION-ALMACENES-SCOPE-HARDENING-1` solo si se prioriza operación; para
abrir nuevos módulos de negocio, el orden seguro inicia por compras/proveedores
después de cerrar alcance y pruebas de inventario.

## 2. Methodology

Se inspeccionaron, sin modificar, `routes/web.php`, controladores, servicios de
dominio, repositorios, vistas, migraciones, DB-TEST, CSS/JS, permisos referidos
por las rutas y los documentos:

- `docs/auditoria-global-erp-1.md`
- `docs/erp-sidebar-rbac-reorganizacion-1.md`
- `docs/erp-sidebar-collapse-ux-1.md`

Se clasificó un módulo como `COMPLETE` solo cuando había DB (si aplica),
backend, rutas, UI, RBAC, validaciones, tests razonables y no había un bloqueo
funcional conocido. La existencia de una tabla, repository o label no se contó
como implementación por sí sola.

Conteos estáticos de referencia:

```text
PHP versionado histórico auditado: 502
Registros de router: 146 (72 GET, 74 POST)
Migraciones en disco/documentadas previamente: 23
DB-TEST disponibles/documentadas previamente: 109
Permisos observados previamente en DB: 119
Sidebar vigente: 20 links, 6 grupos y 1 raíz
```

## 3. Module status legend

| Estado | Criterio usado |
|---|---|
| COMPLETE | Flujo operativo demostrado de extremo a extremo y cobertura razonable |
| PARTIAL | Hay flujo real, pero falta una capacidad relevante, cobertura o readiness |
| BROKEN | Existe flujo que falla o tiene un bloqueo funcional reproducible |
| DB_ONLY | Hay estructura DB, sin backend/UI operativo suficiente |
| BACKEND_ONLY | Hay dominio/repository/rutas internas, sin UI administrativa completa |
| UI_ONLY | Hay presentación sin backend/DB operativo correspondiente |
| SKELETON | Estructura inicial sin flujo de negocio verificable |
| NOT_STARTED | No existe evidencia funcional suficiente |
| UNKNOWN | La evidencia disponible no permite concluir sin ampliar el alcance |

## 4. Master module matrix

Abreviaturas: `Y` sí, `P` parcial, `N` no, `N/A` no aplica, `I` interno/no
expuesto en sidebar.

| MODULE | DB | MIGRATION | REPOSITORY | SERVICE | CONTROLLER | ROUTES | UI | RBAC | SIDEBAR | TESTS | DOCS | STATUS | MISSING | PRIORITY |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Dashboard `/app` | N/A | N/A | N | P | P | Y | P | Y | Y | P | Y | PARTIAL | métricas, acciones y dashboard real | P2 |
| Ventas | N | N | N | N | N | N | N | N | N | N | Y | NOT_STARTED | modelo, flujo, permisos y pruebas | P2 |
| Clientes | N | N | N | N | N | N | N | N | N | N | Y | NOT_STARTED | entidad y alcance comercial | P2 |
| Cuentas por cobrar | N | N | N | N | N | N | N | N | N | N | Y | NOT_STARTED | cartera, pagos y conciliación | P2 |
| Compras | N | N | N | N | N | N | N | N | N | N | Y | NOT_STARTED | órdenes, recepción y costos | P1 |
| Proveedores | N | N | N | N | N | N | N | N | N | N | Y | NOT_STARTED | entidad, contactos y alcance | P1 |
| Cuentas por pagar | N | N | N | N | N | N | N | N | N | N | Y | NOT_STARTED | obligaciones y pagos | P2 |
| Productos | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | PARTIAL | completar capacidades pendientes y cobertura de importación/hosting | P1 |
| Precios | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | PARTIAL | vigencias/reglas comerciales y más pruebas integrales | P1 |
| Existencias | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | PARTIAL | datos operativos y pruebas de alcance en escenarios reales | P1 |
| Existencias por serie | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | PARTIAL | captura/operación completa de series | P1 |
| Movimientos | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | PARTIAL | cierre de flujo operacional y evidencia de inventario | P1 |
| Kardex | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | PARTIAL | conciliación exhaustiva contra movimientos | P1 |
| Kardex por serie | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | PARTIAL | cobertura completa de series | P1 |
| Transferencias | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | PARTIAL | atomicidad, series, rollback y pruebas de concurrencia | P1 |
| Series | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | PARTIAL | CRUD/captura operativa diferenciada de consultas | P1 |
| Lotes/Pedimentos | N | N | N | N | N | N | N | N | N | N | Y | NOT_STARTED | modelo y trazabilidad | P3 |
| Listas de precios | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | PARTIAL | reglas de vigencia y operación comercial | P1 |
| Catálogos | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | PARTIAL | completar matriz CRUD/activación por catálogo | P1 |
| Empresas | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | PARTIAL | validación fiscal y escenarios de scope | P1 |
| Almacenes | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | PARTIAL | reglas de folios, usuarios e inventario | P1 |
| Folios | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | PARTIAL | evidencia de concurrencia y duplicados | P1 |
| Usuarios | Y | Y | Y | P | P | I | P | Y | N | P | Y | BACKEND_ONLY | UI CRUD administrativo, activación, roles y scope | P1 |
| Roles | Y | Y | Y | N | N | N | N | Y | N | P | Y | BACKEND_ONLY | servicio, UI y CRUD real de roles | P1 |
| Permisos | Y | Y | Y | P | N | N | N | Y | N | P | Y | BACKEND_ONLY | UI de administración y gobernanza | P2 |
| Temas | N/A | N/A | N | N | N | N | UI tokens | N | N | N | Y | UI_ONLY | selección/persistencia administrativa | P4 |
| Tickets de producto | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | PARTIAL | cerrar flujos excepcionales y pruebas de estados/correo | P1 |
| Correo configuración | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | PARTIAL | hosting/secretos y readiness de producción | P1 |
| Cola de correo | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | PARTIAL | scheduler real, lock y hosting | P1 |
| Auditoría | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | PARTIAL | ampliar cobertura de eventos y reportes | P2 |
| Perfil | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | Y | PARTIAL | completar criterios de edición/publicación | P2 |
| Credencial | Y | Y | Y | Y | Y | Y | Y | I | N | Y | Y | PARTIAL | operación y rotación documentada en UI | P2 |
| vCard/QR | Y | Y | Y | Y | Y | Y | Y | Y | I | Y | Y | PARTIAL | cierre de variantes públicas y soporte operativo | P2 |
| Reportes | N | N | N | N | N | N | N | N | N | N | Y | NOT_STARTED | definición de reportes y exportaciones | P3 |
| Configuración general | N/A | N/A | N | N | N | N | N | N/A | N | N | Y | NOT_STARTED | parámetros editables diferenciados de `config/*.php` | P3 |

## 5. Database coverage

### 5.1 Tablas por dominio

| Dominio | Tablas reales observadas | Cobertura |
|---|---|---|
| Identidad/RBAC | `usuarios`, `roles`, `permisos`, `usuario_roles`, `rol_permisos`, `auditoria_eventos` | Integrada; UI de roles/permisos ausente |
| Scope | `empresas`, `almacenes`, `usuario_empresas`, `usuario_almacenes` | Integrada; depende de asignaciones válidas |
| Catálogos | `monedas`, `tipos_cambio`, `unidades_medida`, `impuestos`, `lineas_producto`, `marcas`, `clasificaciones_producto`, `unidades_sat`, `claves_sat`, `tipos_producto` | CRUD/hub mixto |
| Productos | `productos`, `producto_codigos_barras`, `producto_impuestos`, `producto_documentos` | CRUD, imagen y relaciones presentes |
| Precios | `listas_precios`, `producto_precios`, `producto_precios_historial`, `autorizaciones_precio` | Flujo real, aún parcial comercialmente |
| Inventario | `conceptos_movimiento_inventario`, `movimientos_inventario`, `movimientos_inventario_detalle`, `existencias_producto` | Lectura y operación presentes |
| Series | `producto_series`, `existencias_serie`, `movimiento_detalle_series` | Consulta/integración; CRUD operativo pendiente |
| Folios | `series_documentales`, `documentos_folios` y extensión a movimientos | Configuración y asignación presentes |
| Tickets | `tickets_productos`, partidas, comentarios, adjuntos, respuestas y estados | Flujo amplio, requiere cierre de edge cases |
| Mail | `mail_accounts`, reglas, outbox, intentos/auditoría de correo | Processor y UI presentes; scheduler depende de hosting |
| Perfil/vCard | `perfiles_usuario`, `usuarios_fotos`, `vcards_usuario`, `vcard_privacidad`, `vcard_productos`, `credenciales_usuario`, `credencial_tokens` | Integrada, variantes públicas aún parciales |

### 5.2 Hallazgos DB

- La estructura cubre más dominios que el sidebar: no se debe inferir que una
  tabla de clientes, ventas o compras existe; no se encontró evidencia de esas
  entidades en las migraciones revisadas.
- Las migraciones usan FKs e índices únicos en los dominios implementados.
- No se ejecutaron `SELECT` nuevos durante esta fase sobre una base diferente ni
  se hicieron escrituras; la evidencia DB anterior permanece read-only.
- No se detectó un endpoint que exponga consultas arbitrarias de DB.
- Riesgo principal: completar nuevos módulos antes de cerrar scope, auditoría,
  folios e integridad de inventario produciría relaciones huérfanas o permisos
  incompletos.

## 6. Route coverage

La superficie se concentra en `routes/web.php` y mantiene Auth/Permission/CSRF
por grupo. Resumen por área:

| Área | LIST/SHOW | CREATE/STORE | EDIT/UPDATE | DELETE/acciones | Estado |
|---|---|---|---|---|---|
| Productos | Y | Y | Y | imagen/documentos/importación | CRUD real + importación |
| Importación | preview/resultado | validar/confirmar | N/A | descartar | flujo especial completo local; hosting pendiente |
| Catálogos | Y | Y | Y | activar/desactivar según catálogo | mixto |
| Empresas/almacenes | Y/show | Y | Y | activar/eliminar lógico | real |
| Folios | Y/show | Y | Y | acciones de serie/documento | real |
| Precios | Y/show/history | Y | Y | autorizaciones/cancelación | real parcial |
| Inventario | existencias/kardex | movimientos/transferencias | N/A | aplicar/confirmar | real parcial |
| Tickets | listado/detalle | ticket/partidas/comentarios | estados | aprobar/rechazar/cancelar/adjuntos | real parcial |
| Mail | config/outbox/show | reglas/acciones | config | retry/cancel/process controlado | real parcial |
| Perfil/vCard | perfil/credencial/public | foto/vcard | update/privacy | revocar/despublicar | real parcial |
| Auditoría | listado/filtros | N/A | N/A | N/A | consulta protegida |

No se encontró una superficie CRUD de ventas, compras, clientes, proveedores,
CXC, CXP, reportes, roles, permisos o configuración general. Las rutas de
credencial/vCard son internas o públicas específicas, no un módulo de negocio
general.

## 7. Backend coverage

La separación Controller → Service/Domain → Repository está presente en los
dominios principales. Excepciones o riesgos observados:

- `routes/web.php` compone muchos servicios y puede concentrar wiring; no se
  refactoriza en esta fase.
- Productos/importación tiene lector, límites, preview store y validadores; la
  compatibilidad XLSX de hosting sigue sin `ACCOUNT_TEST`.
- Inventario y precios tienen repositories/servicios separados y consultas
  complejas; necesitan pruebas de integridad/concurrencia, no una reescritura.
- Mail separa renderer, outbox, transport, acciones y processor; scheduler real
  y lock compartido no están demostrados en AwardSpace.
- Usuarios tiene repository/auth inicial, pero no un `UserAdminController` ni
  UI CRUD de administración.
- Roles/permiso tienen consultas y servicio de autorización, pero no gestión
  administrativa completa.

## 8. UI coverage

| Módulo | Listado | Filtros/búsqueda | Create/Edit | Detalle | Acciones/estados | Empty/responsive |
|---|---|---|---|---|---|---|
| Productos | YES | YES | YES | YES | imagen/importación | PARTIAL |
| Precios | YES | YES | YES | YES/history | autorizaciones | PARTIAL |
| Catálogos | YES | PARTIAL | YES | PARTIAL | activar/desactivar | PARTIAL |
| Empresas/almacenes | YES | PARTIAL | YES | YES | activar/scope | PARTIAL |
| Folios | YES | PARTIAL | YES | YES | series/documentos | PARTIAL |
| Inventario/Kardex | YES | YES | movimientos | detail | aplicar/transferir | PARTIAL |
| Tickets | YES | YES | YES | YES | estados/adjuntos/correo | PARTIAL |
| Mail/outbox | YES | PARTIAL | config | show | retry/cancel | PARTIAL |
| Auditoría | YES | YES | N/A | event detail in list | N/A | PARTIAL |
| Perfil/credencial/vCard | YES | N/A | YES | public/private | foto/QR/privacy | PARTIAL |
| Dashboard | YES | N/A | N/A | N/A | no métricas | PLACEHOLDER |
| Ventas/compras/clientes/proveedores/reportes | NO | NO | NO | NO | NO | NO |

## 9. RBAC coverage

- Se observaron 119 permisos en la base según la auditoría read-only previa.
- Las rutas sensibles aplican `AuthMiddleware` y `PermissionMiddleware`; POST
  y acciones mutantes mantienen CSRF.
- El sidebar solo oculta enlaces; no es frontera de seguridad.
- El runtime limitado de sidebar demostró que `inventario.existencias.acceder`
  renderiza Existencias y oculta grupos sin hijos visibles.
- `PERMISSION_MISSING`: probable para cualquier futuro módulo de ventas/compras;
  no se deben inventar códigos hasta definir esos módulos.
- `PERMISSION_UNUSED`: existen permisos de capacidades internas o fuera del
  sidebar; requieren inventario/gobernanza antes de eliminarse.
- `PERMISSION_MISMATCH`: las cuatro referencias históricas incorrectas del
  sidebar fueron corregidas; quedan en cero en los archivos auditados.
- No se encontró evidencia de `ROUTE_UNPROTECTED` en los grupos implementados,
  pero falta una matriz automatizada global ruta-permiso-CSRF-scope.

## 10. Sidebar coverage

El árbol vigente contiene 20 links, seis grupos colapsables y `Inicio` raíz.
Visibles: Tickets, Productos, Precios, Inventario, Listas, Catálogos, Empresas,
Almacenes, Folios, Correo, Cola, Auditoría, Perfil y Credencial. No visibles:

| Módulo no visible | Razón |
|---|---|
| Ventas, compras, clientes, proveedores, CXC, CXP | `NOT_IMPLEMENTED` |
| Usuarios, roles, permisos | `NO_EVIDENCE` de UI administrativa aprobada |
| Reportes | `NOT_IMPLEMENTED` |
| Temas | `UI_ONLY`, sin módulo de administración |
| Lotes/pedimentos | `NOT_IMPLEMENTED` |
| Credenciales auxiliares/QR | `ACCESS_VIA_PARENT` desde Perfil/Credencial |

No se propone modificar el menú en esta fase.

## 11. Test coverage

Existe cobertura estática, funcional, DB y QA para productos, catálogos, SAT,
precios, inventario, series, transferencias, tickets, mail, perfil, vCard,
credenciales, RBAC, folios y sidebar. La cobertura de mayor riesgo sigue siendo:

- ventas/compras/clientes/proveedores/CXC/CXP/reportes: `NONE`;
- usuarios/roles/permisos administrativos: `NONE` o solo pruebas de autorización;
- scheduler/hosting AwardSpace: `NONE` real;
- concurrencia de folios, transferencias y locks: `PARTIAL`;
- pruebas cross-module completas sin side effects: `PARTIAL`.

No se ejecutaron tests mutantes ni DB-TEST en esta fase read-only.

## 12. Documentation coverage

| Fuente | Estado | Observación |
|---|---|---|
| `docs/auditoria-global-erp-1.md` | HISTORICAL | corte anterior a `36484ed`; útil como baseline, no como estado Git actual |
| `docs/erp-sidebar-rbac-reorganizacion-1.md` | CURRENT | refleja cierre funcional del sidebar previo |
| `docs/erp-sidebar-collapse-ux-1.md` | CURRENT | contrato de colapsado y scroll |
| Contratos por fases de productos/precios/tickets/mail | CURRENT/HISTORICAL | conservar fase y evidencia separadas |
| Módulos ventas/compras/reportes/configuración general | MISSING | no existe contrato funcional aprobado |

La presencia de `TODO`, `PENDING` o `UNKNOWN` en documentos antiguos no prueba
que el código actual esté pendiente; cada conclusión de esta matriz se contrastó
con archivos reales.

## 13. Module-by-module findings

### Productos

Es el dominio de negocio más maduro: CRUD, identificadores, impuestos, imágenes,
documentos, SAT, tipo y clasificación tienen backend, UI y pruebas. Importación
CSV/XLSX dispone de lector secuencial, límites, preview, validación y confirmación;
la dependencia exacta OpenSpout y los prerrequisitos del hosting continúan siendo
riesgo de despliegue. No hay evidencia de un módulo separado de productos
similares; no debe marcarse como implementado por documentación histórica.

### Precios e inventario

Listas, precios, historial y autorizaciones existen. Existencias, movimientos,
kardex y transferencias tienen modelos y pantallas reales. El estado es parcial
porque falta cerrar evidencia de concurrencia, atomicidad, scope y datos
operativos representativos, no porque falten rutas básicas.

### Tickets y correo

Tickets tiene partidas, estados, aprobación/rechazo/cancelación, comentarios,
adjuntos y orquestación de correo. Mail separa configuración, plantillas,
outbox, acciones, retry, cancelación y processor. El envío SMTP QA local fue
demostrado, pero scheduler, cron, lock y SMTP de hosting permanecen no verificados.

### Identidad, usuarios y permisos

Login username/email, sesión y ADMIN existen. La base contiene roles y permisos,
pero no hay un módulo de administración de usuarios/roles/permisos expuesto en
sidebar. Perfil, credencial y vCard sí tienen UI separada; no sustituyen un
administrador RBAC.

### Ventas, compras y reportes

No se encontró código funcional suficiente para afirmar su existencia. Inventario,
folios o tickets no se reinterpretan como ventas/compras. Deben iniciar con
contrato de dominio, DB-TEST, permisos, alcance y auditoría propios.

## 14. Gap register

| GAP_ID | MODULE | CURRENT_STATE | MISSING_COMPONENT | IMPACT | DEPENDENCY | EFFORT | PRIORITY |
|---|---|---|---|---|---|---|---|
| GAP-001 | Hosting | local-only | ACCOUNT_TEST AwardSpace | bloquea deploy | hosting/ops | L | P0 |
| GAP-002 | Correo | processor local | scheduler/cron/lock real | riesgo de duplicados o cola detenida | hosting + DB | L | P0 |
| GAP-003 | Usuarios | auth/profile | UI CRUD administrativo | operación RBAC manual | RBAC + scope | L | P1 |
| GAP-004 | Roles/permisos | DB/backend | UI de gobernanza | cambios de acceso no administrables | usuarios | L | P1 |
| GAP-005 | Transferencias | flujo real | pruebas atomicidad/concurrencia/series | riesgo de integridad stock | inventario/series | M | P1 |
| GAP-006 | Inventario | funcional parcial | pruebas de scope y saldos reales | decisiones operativas incompletas | empresas/almacenes | M | P1 |
| GAP-007 | Productos importación | local completo | compatibilidad hosting XLSX | importación no portable | OpenSpout/hosting | M | P1 |
| GAP-008 | Compras | no iniciado | dominio, DB, CRUD y recepción | impide ciclo de abastecimiento | productos/proveedores/inventario | XL | P1 |
| GAP-009 | Proveedores | no iniciado | entidad, contactos y permisos | compras sin actor | scope/RBAC | L | P1 |
| GAP-010 | Ventas/clientes | no iniciado | dominio comercial y salidas | no existe ciclo de venta | inventario/precios/clientes | XL | P2 |
| GAP-011 | Reportes | no iniciado | definiciones y exportaciones | baja visibilidad operativa | módulos fuente | L | P3 |
| GAP-012 | Dashboard | landing privada | métricas y enlaces reales | navegación sin resumen operativo | reportes/módulos | M | P2 |
| GAP-013 | Lotes/pedimentos | no iniciado | trazabilidad por lote | cumplimiento/inventario incompleto | productos/series | L | P3 |
| GAP-014 | Configuración general | no iniciado | parámetros editables | ajustes dispersos | arquitectura/config | M | P3 |

## 15. Dependency map

```text
Identidad
  -> RBAC + UserScopeService
  -> Empresas -> Almacenes -> Folios
  -> Productos -> Precios -> Inventario
  -> Series -> Existencias/Kardex/Transferencias
  -> Tickets -> Mail/Outbox -> Scheduler de hosting
  -> Compras -> Proveedores -> Entradas -> Inventario
  -> Clientes -> Ventas -> Salidas -> CXC
  -> Compras -> CXP
  -> Módulos operativos -> Auditoría -> Reportes -> Dashboard
```

El grafo separa dependencias demostradas por FK/servicio de capacidades futuras;
no implica que Compras, Ventas o CXC ya existan.

## 16. Prioritized roadmap

### P0 — integridad/seguridad/operación bloqueante

1. `AWARDSPACE-SCHEDULER-ACCOUNT-TEST-1` y verificación de document root,
   PHP CLI, storage privado, límites y SMTP.
2. Cerrar el contrato de scheduler, lock y recuperación de outbox en hosting.
3. Resolver cualquier anomalía de precedencia de entorno CLI antes de producción.

### P1 — capacidades existentes que bloquean operación

1. Completar pruebas de scope, atomicidad y concurrencia de inventario,
   transferencias, series y folios.
2. Cerrar compatibilidad de importación XLSX en hosting.
3. Diseñar y aprobar administración de Usuarios/Roles/Permisos.
4. Diseñar Proveedores y Compras sobre el inventario existente.

### P2 — capacidades core faltantes

1. Clientes y Ventas, después de definir salidas, precios, folios y auditoría.
2. CXC/CXP vinculadas a ventas/compras reales.
3. Dashboard basado en métricas ya definidas, no en placeholders.

### P3/P4

Reportes, lotes/pedimentos, configuración general editable, tema administrativo
y mejoras visuales posteriores a los flujos core.

## 17. Recommended next module

Recomendación inmediata de fase técnica: `ERP-USUARIOS-RBAC-ADMIN-1`, precedida
por un contrato de permisos y scope. Es el hueco más importante entre el backend
existente y la operación administrativa: hoy existe autenticación y autorización,
pero no UI segura para gestionar usuarios, roles y asignaciones.

Si la prioridad es valor de negocio transaccional en lugar de gobierno, la
alternativa siguiente es `ERP-PROVEEDORES-COMPRAS-CORE-1`, siempre después de
cerrar inventario/folios y su DB-TEST.

## 18. Deferred items

- Ventas, clientes, CXC, compras, proveedores, CXP y reportes no se implementan
  en esta fase.
- Lotes/pedimentos y productos similares permanecen sin evidencia funcional.
- No se corrigen gaps ni se crean permisos/rutas/tablas.
- No se ejecuta DB-TEST, SMTP, scheduler, deploy ni pruebas con side effects.
- La documentación histórica debe revisarse por fase antes de usarla como
  aceptación actual.

## 19. Resumen de consola

```text
TOTAL_MODULES=37
COMPLETE=0
PARTIAL=23
BROKEN=0
DB_ONLY=0
BACKEND_ONLY=3
UI_ONLY=1
SKELETON=0
NOT_STARTED=10
UNKNOWN=0
P0_GAPS=3
P1_GAPS=7
P2_GAPS=2
P3_GAPS=2
P4_GAPS=0
MODULES_WITHOUT_TESTS=10
MODULES_WITHOUT_RBAC=10
MODULES_NOT_IN_SIDEBAR=10
PRODUCTS_STATUS=PARTIAL
INVENTORY_STATUS=PARTIAL
TICKETS_STATUS=PARTIAL
MAIL_STATUS=PARTIAL
USERS_STATUS=BACKEND_ONLY
ROLES_STATUS=BACKEND_ONLY
PERMISSIONS_STATUS=BACKEND_ONLY
SALES_STATUS=NOT_STARTED
PURCHASES_STATUS=NOT_STARTED
CUSTOMERS_STATUS=NOT_STARTED
SUPPLIERS_STATUS=NOT_STARTED
CXC_STATUS=NOT_STARTED
CXP_STATUS=NOT_STARTED
REPORTS_STATUS=NOT_STARTED
TOP_10_GAPS=GAP-001,GAP-002,GAP-003,GAP-004,GAP-005,GAP-006,GAP-007,GAP-008,GAP-009,GAP-010
RECOMMENDED_NEXT_MODULE=ERP-USUARIOS-RBAC-ADMIN-1
RECOMMENDED_NEXT_PHASE=ERP-USUARIOS-RBAC-ADMIN-1
DB_WRITES=0
SMTP=false
DEPLOY=false
COMMIT=false
PUSH=false
```

## 20. Cierre

Este documento es el único archivo nuevo de la fase. El análisis deja el
checkout sin cambios funcionales, sin staging y sin commit. `main` no fue
modificada y no se avanzó a ningún módulo nuevo.
