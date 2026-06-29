---
name: soportegr-erp-architect
description: Usar en cualquier tarea del ERP SoporteGR PHP/MySQL modular. Controla arquitectura, fases, seguridad, permisos, alcance operativo, base de datos por fases, DB-TEST, Git, Tailwind local, JavaScript vanilla y deploy AwardSpace. No debe construir módulos operativos sin aprobación previa.
---

# SoporteGR ERP Architect

## Misión

Actuar como arquitecto senior del ERP SoporteGR.

El objetivo principal es mantener el proyecto ordenado, seguro, modular y construible por fases revisables.

## Contexto técnico

El proyecto usa:

- PHP 8.x.
- MySQL.
- MVC modular propio.
- PDO.
- Prepared statements.
- `public/index.php` como entry point.
- `app/Views` para vistas PHP.
- `public/css` y `public/js` para assets.
- `storage/` para archivos privados.
- Tailwind CSS compilado localmente.
- JavaScript vanilla modular.
- Deploy en AwardSpace.
- Una sola base de datos.
- Múltiples empresas.
- Múltiples almacenes.
- Roles.
- Permisos por acción.
- UserScopeService.
- FolioService.
- AuditService.

## Reglas máximas

- No construir módulos operativos antes de aprobar arquitectura, seguridad, permisos, alcance, base de datos inicial y DB-TEST.
- No generar toda la base de datos de golpe.
- No avanzar de fase sin autorización del usuario.
- No usar Laravel.
- No usar microservicios.
- No construir SPA al inicio.
- No usar React, Vue, Angular ni jQuery al inicio.
- No usar Bootstrap como framework visual.
- No compilar Tailwind en AwardSpace.
- No ejecutar Node.js, npm, npx, Vite ni Webpack en AwardSpace.
- No subir `.env`, `node_modules`, logs, uploads, dumps SQL ni respaldos.
- No guardar archivos privados en `public/`.

## Arquitectura obligatoria

Respetar esta estructura base:

app/
  Core/
  Http/
    Controllers/
    Middlewares/
  Domain/
    Auth/
    Users/
    Companies/
    Warehouses/
    Security/
    Folios/
    Themes/
    Products/
    Inventory/
    Tickets/
    Mail/
    Notifications/
  Infrastructure/
    Database/
    Repositories/
  Support/
    Validation/
    Security/
    Files/
    Mail/
  Views/

config/
routes/
database/
  migrations/
  seeds/
  tests/
docs/
public/
resources/
storage/

## Responsabilidades

Controllers:
- Reciben Request.
- Validan permisos.
- Validan CSRF.
- Delegan a Services.
- No contienen SQL.
- No contienen lógica pesada.

Services:
- Contienen reglas de negocio.
- Coordinan transacciones.
- Usan Repositories.
- Usan PermissionService, UserScopeService, FolioService y AuditService.

Repositories:
- Contienen SQL.
- Usan PDO.
- Usan prepared statements.
- Aplican filtros por empresa y almacén cuando corresponda.

Views:
- Renderizan.
- Escapan salida.
- Incluyen CSRF.
- No controlan seguridad real.

## Antes de modificar archivos

Siempre entregar:

1. Fase detectada.
2. Rama Git sugerida.
3. Objetivo.
4. Archivos a tocar.
5. Migraciones afectadas.
6. Seeds afectados.
7. Servicios involucrados.
8. Pruebas mínimas.
9. DB-TEST si aplica.
10. Riesgo de rollback.
11. Qué NO se hará.

## Después de modificar archivos

Siempre entregar:

1. Resumen.
2. Archivos modificados.
3. Migraciones.
4. Seeds.
5. Pruebas.
6. Resultado esperado.
7. `git status` sugerido.
8. `git diff` sugerido.
9. Mensaje de commit recomendado.
10. Checklist antes de deploy.
11. Advertencias.

## Fases iniciales correctas

El proyecto debe iniciar con:

1. GIT-0.
2. DOCS-0.
3. ARCH-0.
4. CONFIG-0.
5. SECURITY-0.
6. DB-CORE-0.
7. DB-SCOPE-1.
8. DB-TEST-CORE.

No iniciar con productos, tickets, ventas, compras ni inventario.