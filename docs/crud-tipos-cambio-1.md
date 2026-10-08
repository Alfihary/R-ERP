# CRUD-TIPOS-CAMBIO-1 — Administración de tipos de cambio

## Objetivo

Administrar registros globales de `tipos_cambio` mediante autenticación,
permisos por acción, CSRF, validación backend y PDO preparado.

Esta fase no convierte importes, no calcula precios y no agrega productos,
inventario, compras, ventas ni otros módulos funcionales.

## Arquitectura

```text
ruta privada
  -> AuthMiddleware
  -> PermissionMiddleware de acceso a catálogos
  -> PermissionMiddleware de acción
  -> ExchangeRateController
  -> ExchangeRateService
  -> ExchangeRateRepository
  -> PDO preparado
```

`ExchangeRateService` valida las reglas del par, fecha y valor. El controlador
coordina entrada, errores y presentación, pero no contiene SQL. El repositorio
usa únicamente las tablas fijas `tipos_cambio` y `monedas`.

El catálogo es global. La empresa y almacén activos permanecen visibles como
contexto operativo, pero no filtran ni determinan los tipos de cambio.

## Rutas y permisos

| Método | Ruta | Permiso específico |
|---|---|---|
| GET | `/catalogos/tipos-cambio` | `catalogos.tipos_cambio.ver` |
| POST | `/catalogos/tipos-cambio` | `catalogos.tipos_cambio.crear` |
| POST | `/catalogos/tipos-cambio/actualizar` | `catalogos.tipos_cambio.editar` |
| POST | `/catalogos/tipos-cambio/activar` | `catalogos.tipos_cambio.estado` |
| POST | `/catalogos/tipos-cambio/desactivar` | `catalogos.tipos_cambio.estado` |

Todas las rutas requieren sesión y `catalogos.acceder`. Cada escritura pasa
por CSRF. No existe ruta `DELETE` ni borrado físico.

## Seed de permisos

`crud_tipos_cambio_1_seed_permissions` crea y asigna de forma idempotente a
`ADMIN`:

- `catalogos.tipos_cambio.ver`
- `catalogos.tipos_cambio.crear`
- `catalogos.tipos_cambio.editar`
- `catalogos.tipos_cambio.estado`

El seed no crea usuarios y no modifica los 25 permisos de catálogos de fases
anteriores.

## Validaciones

- Moneda origen obligatoria, existente, activa y no eliminada.
- Moneda destino obligatoria, existente, activa y no eliminada.
- Origen y destino deben ser diferentes.
- Fecha obligatoria y válida en formato `YYYY-MM-DD`.
- Valor obligatorio y mayor que cero.
- Valor decimal seguro para `DECIMAL(20,8)`: máximo 12 enteros y 8 decimales.
- Unicidad por moneda origen, moneda destino y fecha.
- En edición se excluye el propio registro al comprobar unicidad.
- Una edición no puede ocupar la combinación de otro registro.
- Las acciones de estado conservan el registro; no existe borrado físico.

La precisión se valida como texto decimal antes de enviar el valor a PDO. No se
usa `float` para normalizar o calcular el tipo de cambio.

## Estado y auditoría

Crear, editar, activar o desactivar registra el actor autenticado en
`creado_por` o `actualizado_por`, según corresponda. No existe borrado físico.
El registro de eventos mediante `AuditService` permanece reservado para la
fase `AUDIT-0` ya definida y no se improvisa dentro de este CRUD.

## Presentación

- Navegación visible únicamente con permiso de consulta.
- Formulario con selects de monedas activas, fecha y valor.
- Tabla con origen, destino, fecha, valor, estado y acciones.
- Datos dinámicos escapados mediante `e()`.
- Tabla dentro de un contenedor responsive con scroll horizontal.
- Contexto activo visible dentro del shell autenticado.

No se agregan dashboard, métricas, gráficas, KPIs ni menú dinámico.

## Seed y DB-TEST

```powershell
php database/crud-tipos-cambio.php seed --database=<db-test> --confirm-database=<db-test>
php database/crud-tipos-cambio.php db:test --database=<db-test> --confirm-database=<db-test>
```

El runner exige la base confirmada dos veces, coincidencia con `APP_DB_NAME` y
un entorno distinto de producción.

El DB-TEST comprueba cuatro permisos activos, cuatro asignaciones activas a
ADMIN, 25 permisos anteriores intactos, 29 permisos totales de catálogos, cero
códigos duplicados, cero relaciones duplicadas y cero usuarios creados.

## Rollback

El rollback de código retira rutas, controlador, servicio, repositorio y vista
sin cambiar la migración de DB-CATALOGOS-1. El rollback del seed retira solo
las relaciones y permisos de esta fase cuando no existen otras asignaciones.

Los tipos de cambio capturados fuera de QA no se eliminan automáticamente. Los
datos transitorios de las pruebas deben limpiarse en la base descartable.

## Fuera de alcance

- Servicio de conversión monetaria y cálculo de precios.
- Productos, inventario y tickets.
- Compras, ventas, CXC y CXP.
- Clientes, proveedores y reportes.
- Dashboard, métricas, gráficas y KPIs.
- Menú dinámico desde base de datos.
- Integración MySQL de `/health`.
- Deploy y remoto Git.
