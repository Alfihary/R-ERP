# PERMISOS-AUDITORIA-1

## Objetivo

Formalizar el permiso operativo de consulta de auditoria:

- `auditoria.ver`

Este permiso reemplaza el uso temporal de `seguridad.rbac.ver` en `GET /auditoria`.

## Seed

Archivo:

- `database/seeds/permisos_auditoria_1_seed.php`

El seed:

- crea `auditoria.ver` si no existe;
- mantiene el permiso activo;
- asigna el permiso al rol `ADMIN`;
- es idempotente;
- no duplica `permisos`;
- no duplica `rol_permisos`;
- no modifica usuarios;
- no toca productos, precios ni inventario.

## Ruta privada

Ruta:

- `GET /auditoria`

Contrato:

- requiere sesion autenticada;
- requiere `auditoria.ver`;
- sigue siendo read-only;
- no crea rutas `POST`, `PUT`, `PATCH` ni `DELETE`;
- no agrega edicion;
- no agrega borrado;
- no agrega exportacion.

## Navegacion

La entrada de navegacion "Auditoria" se muestra unicamente cuando el usuario autenticado tiene `auditoria.ver`.

`seguridad.rbac.ver` ya no concede acceso por si solo a `/auditoria`.

## Seguridad

La consulta mantiene:

- SQL con PDO/prepared statements;
- filtros controlados;
- paginacion controlada;
- metadata escapada;
- metadata sensible redactada;
- sin inserciones por consultar;
- sin actualizaciones por consultar.

## DB-TEST

Runner:

```bash
php database/permisos-auditoria.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

Valida:

- seed existente y ejecutable;
- creacion de `auditoria.ver`;
- activacion de `auditoria.ver`;
- asignacion a `ADMIN`;
- idempotencia;
- cero duplicados de permiso;
- cero duplicados en `rol_permisos`;
- `GET /auditoria` con `auditoria.ver`;
- rechazo 403 con solo `seguridad.rbac.ver`;
- navegacion visible solo con `auditoria.ver`;
- consulta read-only;
- filtros basicos vigentes;
- rollback de datos QA transitorios.

## Fuera de alcance

Esta fase no modifica:

- migraciones;
- `AuditService`;
- `AuditRepository`;
- productos;
- precios;
- inventario;
- vCard funcionalmente;
- credenciales funcionalmente;
- exportaciones de auditoria;
- endpoints JSON de auditoria.
