# Plan de fases del ERP

## Estado del documento

- Fases cerradas: `GIT-0 + DOCS-0 + ARCH-0 + CONFIG-0 + SECURITY-0`.
- Fase autorizada y en revisión: `DB-CORE-0`.
- Las fases siguientes son planificación, no autorización.

## Objetivo

Controlar la construcción del ERP mediante entregas pequeñas, verificables y
reversibles. Ninguna fase futura empieza por inferencia, dependencia técnica o
porque figure en este documento.

## Regla de avance

Cada fase sigue este ciclo:

```text
diagnóstico
  -> propuesta y preflight
  -> autorización expresa
  -> implementación limitada
  -> pruebas
  -> revisión de diff
  -> criterios de aceptación
  -> cierre
  -> solicitud de la siguiente fase
```

Si una prueba falla, la fase no se considera cerrada. Si aparece una dependencia
no aprobada, se detiene el trabajo y se presenta una propuesta separada.

## Contrato obligatorio por fase

Antes de modificar archivos se entregará:

1. Fase detectada.
2. Rama sugerida.
3. Objetivo.
4. Archivos afectados.
5. Migraciones.
6. Seeds.
7. Servicios.
8. Pruebas mínimas.
9. DB-TEST si aplica.
10. Riesgo de rollback.
11. Qué no se hará.

Después de modificar archivos se entregará:

1. Resumen.
2. Archivos modificados.
3. Migraciones.
4. Seeds.
5. Pruebas ejecutadas.
6. Resultado esperado y observado.
7. Comando `git status`.
8. Comando `git diff`.
9. Commit recomendado.
10. Checklist previo a deploy.
11. Advertencias.

Una fase de BD además debe cubrir tablas, relaciones, llaves, índices,
restricciones, campos de auditoría, casos válidos e inválidos, interpretación de
errores y rollback.

## Fases iniciales de plataforma

### `GIT-0` — Higiene del repositorio

Objetivo:

- Definir exclusiones.
- Proveer `.env.example` sin secretos.
- Establecer flujo de ramas y commits.

Archivos:

- `.gitignore`
- `.env.example`
- `README.md`

Pruebas:

- Archivos sensibles ignorados.
- Dependencias locales ignoradas.
- Archivos protegidos sin cambios.

Criterios de aceptación:

- `node_modules/`, `.env`, logs, uploads, dumps y respaldos no son
  versionables por defecto.
- No se eliminan archivos locales.

No hacer:

- Commit automático.
- Eliminar dependencias.
- Configurar producción.

Riesgo: bajo.

Commit recomendado: `init: establish repository hygiene`.

### `DOCS-0` — Documentación vinculante

Objetivo:

- Documentar arquitectura, seguridad, convenciones, BD, fases, deploy y correo.

Archivos:

- `docs/*.md`
- `README.md`

Pruebas:

- Documentos presentes.
- Secciones obligatorias presentes.
- Coherencia de fases y exclusiones.

Criterios de aceptación:

- Las reglas distinguen decisiones, pendientes y fuera de alcance.
- No hay secretos ni SQL ejecutable.

No hacer:

- Implementar las decisiones.
- Aprobar automáticamente fases futuras.

Riesgo: bajo.

Commit recomendado: `docs: define ERP foundation and phase gates`.

### `ARCH-0` — Árbol y límites

Objetivo:

- Crear el árbol vacío.
- Fijar responsabilidades y dependencias.

Archivos:

- Directorios base conservados mediante `.gitkeep`.

Pruebas:

- Árbol coincide con arquitectura.
- No existen clases PHP ni `public/index.php`.

Criterios de aceptación:

- Capas y módulos están separados.
- Los directorios vacíos usan únicamente `.gitkeep`.

No hacer:

- Bootstrap funcional.
- Rutas o clases.

Riesgo: bajo.

Commit recomendado: `init: create modular architecture skeleton`.

### `CONFIG-0` — Configuración y bootstrap

Objetivo:

- Implementar entry point, autoload, carga segura de configuración y manejo de
  entornos.

Archivos:

- `public/index.php`
- `bootstrap/app.php`
- `config/app.php` y `config/paths.php`
- clases mínimas de `app/Core`
- `routes/web.php`
- `app/Views/welcome.php`

Prueba local:

```powershell
php -S 127.0.0.1:8000 -t public
```

Rutas mínimas:

- `GET /`
- `GET /health`

Tablas: ninguna.

Servicios: bootstrap, entorno, configuración y núcleo HTTP mínimo.

Seguridad:

- Secretos solo desde entorno.
- `APP_DEBUG=false` en producción.
- `public` como única raíz pública.

Pruebas mínimas:

- Arranque local.
- Configuración faltante falla de forma segura.
- Producción no muestra trazas.

Criterios de aceptación:

- Aplicación mínima responde sin módulo operativo.

No hacer:

- Login, usuarios o BD de negocio.

Riesgo: medio por afectar el arranque.

Commit recomendado: `config: add secure application bootstrap`.

### `SECURITY-0` — Primitivas de seguridad

Objetivo:

- Crear escape, CSRF, sesión segura, manejo de errores y headers base.

Tablas: ninguna salvo que una propuesta separada justifique persistencia.

Servicios previstos:

- CSRF, sesión, errores y helpers de salida.

Implementación:

- Sesión técnica con cookies `HttpOnly`, `SameSite` y modo seguro por entorno.
- Token CSRF con expiración y middleware para métodos mutables.
- Helpers `e()` y `csrf_field()`.
- Middleware general de headers y manejo de excepciones.
- Respuestas seguras 404, 419 y 500.

Pruebas:

- CSRF válido e inválido.
- Cookies.
- Escape.
- Errores de producción.

Criterios:

- Controles centrales y reutilizables.

No hacer:

- Roles, permisos o módulos.

Riesgo: alto si los controles quedan incompletos.

Commit recomendado: `security: establish web security primitives`.

## Fases de base de datos y acceso

### `DB-CORE-0`

Objetivo:

- Crear usuarios, roles, permisos, relaciones y auditoría base sin implementar
  autenticación.

Tablas de negocio:

- `usuarios`
- `roles`
- `permisos`
- `usuario_roles`
- `rol_permisos`
- `auditoria_eventos`

Tabla técnica:

- `schema_migrations`

Artefactos:

- Migración PHP `db_core_0_001_create_core_identity_tables`.
- Seed idempotente del rol `ADMIN`.
- Runner CLI con confirmación doble de la base.
- DB-TEST transaccional con casos válidos e inválidos.

La migración, el seed y DB-TEST-CORE se ejecutaron correctamente el 2026-06-29
sobre `r_erp_db_core_0_test` con MySQL 8.0.38. El esquema y el rol ADMIN se
conservaron; los datos transitorios se revirtieron.

Servicios relacionados: conexión PDO y runner de migraciones. Auth, Permission
y Audit permanecen a nivel de diseño.

Seguridad:

- Password hash.
- Unicidades.
- Sin roles ni alcance dentro del perfil.
- Sin credenciales reales en seeds.

Criterios:

- Migración reproducible.
- Restricciones válidas.
- Casos inválidos fallan.
- DB-TEST aprobado.

No hacer:

- Empresas, almacenes o módulos operativos.

Riesgo: alto por ser fundamento de identidad y autorización.

Commit recomendado: `db: add approved core identity schema`.

### `DB-SCOPE-1`

Objetivo futuro:

- Crear empresas, almacenes y asignaciones de alcance.

Tablas previstas:

- `empresas`
- `almacenes`
- `usuario_empresas`
- `usuario_almacenes`

Migración, seeds y DB-TEST: obligatorios.

Servicios relacionados: `UserScopeService`.

Seguridad:

- Alcance global explícito.
- Almacén consistente con empresa.
- Sin duplicados.

Criterios:

- Relaciones válidas e inválidas verificadas.
- Consultas de alcance revisadas.

No hacer:

- Existencias o movimientos.

Riesgo: alto por posible fuga entre organizaciones.

Commit recomendado: `db: add approved company and warehouse scope`.

### `DB-SECURITY-2`

Objetivo futuro:

- Persistencia de intentos, recuperación y sesiones solo si el diseño aprobado
  la requiere.

Tablas candidatas:

- `intentos_login`
- `password_resets`
- `sesiones`, si se aprueba

Seguridad:

- Tokens almacenados de forma no reversible.
- Expiración y uso único.
- Retención limitada.

No hacer:

- Guardar tokens o contraseñas en claro.

Riesgo: alto.

Commit recomendado: `db: add approved authentication security records`.

### `DB-FOLIOS-3`

Objetivo futuro:

- Persistir series y consecutivos seguros.

Tabla prevista:

- `series_folios`

Servicios: `FolioService`.

Pruebas:

- Unicidad.
- Concurrencia.
- Rollback de operación.

No hacer:

- Folios fuera de transacción.

Riesgo: alto.

Commit recomendado: `db: add transactional folio series`.

### `DB-THEMES-4`

Objetivo futuro:

- Catálogo de temas y tokens controlados.

Tablas candidatas:

- `ui_temas`
- `ui_tema_tokens`
- asignación a usuario conforme al diseño aprobado

Seguridad:

- Sin CSS arbitrario.
- Solo assets y tokens permitidos.

No hacer:

- Editor libre de CSS.

Riesgo: medio.

Commit recomendado: `db: add controlled UI themes`.

### `DB-CATALOGOS-5`

Objetivo futuro:

- Crear catálogos globales iniciales.

Tablas candidatas:

- `productos`
- `unidades_medida`
- `marcas`
- `lineas_producto`
- `clasificaciones_producto`
- `monedas`
- `impuestos`
- `unidades_sat`
- `claves_sat`

No hacer:

- Existencias, compras o ventas.

Riesgo: medio-alto.

Commit recomendado: `db: add approved global catalogs`.

### `DB-INVENTARIO-6`

Objetivo futuro:

- Crear persistencia de existencias y movimientos.

Tablas candidatas:

- `existencias`
- `inventario_movimientos`
- `conceptos_movimiento_inventario`
- series, lotes y pedimentos solo si se aprueban

Seguridad:

- Empresa y almacén obligatorios.
- Concurrencia y trazabilidad.
- Sin edición destructiva del historial.

Riesgo: alto.

Commit recomendado: `db: add approved inventory foundation`.

### `DB-TICKETS-7`

Objetivo futuro:

- Crear persistencia del flujo de solicitudes.

Tablas candidatas:

- `tickets`
- `ticket_partidas`
- `ticket_comentarios`
- `ticket_archivos`

Seguridad:

- Permisos por transición.
- Alcance.
- Archivos privados.

Riesgo: alto.

Commit recomendado: `db: add approved ticket schema`.

### `DB-MAIL-8`

Objetivo futuro:

- Persistir plantillas, cola, logs y reglas.

Tablas candidatas:

- `mail_templates`
- `mail_queue`
- `mail_logs`
- `notification_rules`
- `notification_events`
- preferencias de usuario, si se aprueban

Seguridad:

- Sin SMTP en BD.
- Variables en whitelist.
- Logs sin secretos.

Riesgo: alto por datos personales y efectos externos.

Commit recomendado: `db: add approved mail and notification schema`.

## Fases de servicios base

| Fase | Objetivo | Prerrequisito | No hacer todavía | Riesgo |
|---|---|---|---|---|
| `AUTH-0` | Autenticación y logout | SECURITY-0, DB-CORE-0 | Recuperación avanzada no aprobada | alto |
| `SESSION-0` | Política y persistencia de sesión | SECURITY-0 | Recordar sesión indefinidamente | alto |
| `CSRF-0` | Integración central en rutas mutables | SECURITY-0 | Exentar AJAX | alto |
| `RBAC-0` | Roles y PermissionService | DB-CORE-0 | Usar solo rol en vistas | alto |
| `SCOPE-0` | UserScopeService | DB-SCOPE-1, RBAC-0 | Filtrar solo en PHP | crítico |
| `AUDIT-0` | AuditService | DB-CORE-0 | Registrar secretos | alto |
| `FOLIOS-0` | FolioService | DB-FOLIOS-3, SCOPE-0 | Generar fuera de transacción | alto |
| `USUARIOS-0` | Administración base | AUTH/RBAC/SCOPE | Módulos operativos | alto |
| `PERFIL-0` | Perfil sin control de permisos | USUARIOS-0 | Asignar roles desde perfil | medio |
| `THEMES-0` | Temas controlados | DB-THEMES-4, RBAC | CSS arbitrario | medio |

Cada fila requiere un preflight completo, pruebas de seguridad y autorización
separada.

## Fases de módulos operativos

| Fase | Objetivo futuro | Prerrequisitos mínimos | Qué no debe mezclarse |
|---|---|---|---|
| `CATALOGOS-0` | CRUD de catálogos aprobados | DB-CATALOGOS-5, RBAC, SCOPE | Inventario |
| `PRODUCTOS-0` | Producto global y archivos | CATALOGOS-0 | Existencias y movimientos |
| `EXISTENCIAS-0` | Consulta de saldo | DB-INVENTARIO-6, SCOPE | Ajustes |
| `INVENTARIO-0` | Movimientos y ajustes | EXISTENCIAS-0, FOLIOS-0, AUDIT-0 | Compras/ventas completas |
| `TICKETS-0` | Flujo de solicitudes | DB-TICKETS-7, RBAC, SCOPE, AUDIT | SMTP directo |
| `COMPRAS-0` | Flujo de compras | Catálogos, inventario, folios | Ventas |
| `VENTAS-0` | Flujo de ventas | Catálogos, inventario, folios | CXC completa |
| `CXC-0` | Clientes y cobranza | VENTAS-0 | CXP |
| `CXP-0` | Proveedores y pagos | COMPRAS-0 | CXC |
| `REPORTES-0` | Reportes con permisos propios | Módulos fuente aprobados | Consultas sin alcance |
| `IMPORT-EXPORT-0` | Procesos controlados | Permisos y validadores | Archivos públicos |
| `AUDIT-ADV-0` | Consulta y retención avanzada | AUDIT-0 | Alterar historial |

Cada módulo requerirá migración/seed/DB-TEST si modifica persistencia, además de
pruebas de permiso, alcance, CSRF, auditoría y rollback.

## Fases Mail/Notifications

El subsistema se separará para evitar una entrega monolítica:

| Fase | Resultado futuro |
|---|---|
| `MAIL-0` | Diseño aprobado |
| `MAIL-1` | Migraciones aprobadas |
| `MAIL-2` | Seeds de permisos y plantillas |
| `MAIL-3` | Configuración y entorno |
| `MAIL-4` | MailTemplateService |
| `MAIL-5` | MailQueueService |
| `MAIL-6` | Sender en modo log |
| `MAIL-7` | Sender SMTP |
| `MAIL-8` | MailLogService |
| `MAIL-9` | NotificationService |
| `MAIL-10` | Integración con eventos aprobados |
| `MAIL-11` | DB-TEST-MAIL |
| `MAIL-12` | QA local |
| `MAIL-13` | QA controlado en AwardSpace |

No se integrarán tickets antes de que tanto Tickets como Mail estén aprobados.

## QA y deploy

### `QA-LOCAL-0`

Objetivo futuro:

- Validar rutas, seguridad, permisos, alcance, BD, assets y flujos aprobados.

Criterio:

- Evidencia reproducible y sin defectos críticos abiertos.

No hacer:

- Usar producción como ambiente de pruebas.

Riesgo: medio.

Commit recomendado: `qa: validate approved ERP foundation`.

### `DEPLOY-0`

Objetivo futuro:

- Preparar y ejecutar un deploy reversible en AwardSpace.

Prerrequisitos:

- Rama estable.
- Commit aprobado.
- QA local.
- DB-TEST.
- CSS final.
- Backups.
- Checklist y rollback.

No hacer:

- Compilar en AwardSpace.
- Subir `.env`, dependencias Node, logs, uploads, dumps o respaldos.

Riesgo: crítico por afectar producción.

Commit recomendado: `deploy: prepare approved AwardSpace release`.

## Criterios de cierre de la fase actual

- `main` contiene únicamente el diff revisable de `DB-CORE-0`.
- `/` y `/health` continúan respondiendo localmente.
- El runner rechaza producción y bases no confirmadas.
- La migración crea solo las tablas aprobadas con InnoDB y `utf8mb4`.
- El seed crea `ADMIN`, cero usuarios y cero permisos funcionales.
- DB-TEST prueba unicidad, FKs, checks, relaciones y auditoría.
- Los datos de DB-TEST se revierten en transacción.
- Los archivos PHP pasan revisión de sintaxis.
- `package.json` y `package-lock.json` permanecen sin cambios.
- `git diff --check` no reporta errores.
- No se ha avanzado a `DB-SCOPE-1`, AUTH-0 o módulos funcionales.

## Reglas obligatorias

- Una fase requiere autorización expresa.
- Una fase de BD requiere migración, seed cuando aplique y DB-TEST.
- No mezclar módulos o fases sensibles.
- Cerrar pruebas y revisión antes de continuar.
- Detenerse ante dependencias no aprobadas.
- Mantener rollback proporcional al riesgo.
- No confundir documentación futura con autorización.

## Pendiente de aprobar

- Cierre formal de `DB-CORE-0`.
- Fases posteriores de BD, autenticación y módulos.
- Criterios específicos de cada caso de uso.

## Fuera de alcance de esta fase

- Ejecutar cualquiera de las fases futuras.
- Crear login, dashboard o módulos funcionales.
- Crear empresas, almacenes o alcance.
- Ejecutar contra producción.
- Hacer deploy.
- Integrar tickets, inventario, correo u otros módulos.
