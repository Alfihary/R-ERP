# CRUD-CATALOGOS-2 — Clasificaciones jerárquicas

## Objetivo

Administrar `clasificaciones_producto` como una jerarquía global padre-hijo,
con permisos por acción, validación backend y prevención transaccional de
ciclos.

La fase no crea productos, inventario, tipos de cambio ni otros módulos
funcionales.

## Arquitectura

```text
ruta privada
  -> AuthMiddleware
  -> PermissionMiddleware de acceso a catálogos
  -> PermissionMiddleware de acción
  -> ClassificationController
  -> ClassificationService
  -> ClassificationRepository
  -> PDO preparado
```

`ClassificationService` concentra las reglas del grafo. El controlador no
resuelve jerarquías y no contiene SQL. `ClassificationRepository` usa
únicamente la tabla fija `clasificaciones_producto`; no acepta nombres de
tabla o columna del navegador.

Las escrituras bloquean las clasificaciones vigentes mediante `SELECT ... FOR
UPDATE`, validan el grafo y escriben dentro de la misma transacción.

## Rutas y permisos

| Método | Ruta | Permiso específico |
|---|---|---|
| GET | `/catalogos/clasificaciones` | `catalogos.clasificaciones.ver` |
| POST | `/catalogos/clasificaciones` | `catalogos.clasificaciones.crear` |
| POST | `/catalogos/clasificaciones/actualizar` | `catalogos.clasificaciones.editar` |
| POST | `/catalogos/clasificaciones/activar` | `catalogos.clasificaciones.estado` |
| POST | `/catalogos/clasificaciones/desactivar` | `catalogos.clasificaciones.estado` |

Todas requieren sesión y `catalogos.acceder`. Las escrituras requieren CSRF.
No existe ruta `DELETE`.

## Seed de permisos

`crud_catalogos_2_seed_permissions` crea y asigna de forma idempotente a
`ADMIN`:

- `catalogos.clasificaciones.ver`
- `catalogos.clasificaciones.crear`
- `catalogos.clasificaciones.editar`
- `catalogos.clasificaciones.estado`

El seed no crea usuarios y no modifica los 21 permisos de CRUD-CATALOGOS-1.

## Reglas de jerarquía

- Código obligatorio, único y normalizado a mayúsculas.
- Nombre obligatorio.
- `parent_id` es opcional; vacío representa una raíz.
- Un padre indicado debe existir y no estar eliminado lógicamente.
- Una clasificación no puede ser su propio padre.
- Al editar, el padre no puede ser un descendiente directo o indirecto.
- Una clasificación activa no puede depender de un padre inactivo.
- Una clasificación no se puede desactivar mientras conserve descendientes
  activos.
- Para reactivar un nodo, su padre debe estar activo.

La validación de descendientes recorre el grafo completo, por lo que impide
ciclos de dos nodos y ciclos indirectos de cualquier profundidad.

## Presentación

- Directorio y pestañas visibles solo con permiso de consulta.
- Formulario compacto para raíces o hijos.
- Selector nativo de padre.
- En edición, el selector excluye el propio nodo y todos sus descendientes.
- Tabla con código, nombre, padre, nivel, ruta, estado y acciones.
- Rutas jerárquicas escapadas con `e()`.
- Tabla responsiva dentro de un contenedor con scroll horizontal.
- Empresa y almacén activos permanecen visibles como contexto; no filtran este
  catálogo global.

No se agrega JavaScript, modal, dashboard, métricas, gráficas ni KPIs.

## Seed y DB-TEST

```powershell
php database/crud-catalogos-2.php seed --database=<db-test> --confirm-database=<db-test>
php database/crud-catalogos-2.php db:test --database=<db-test> --confirm-database=<db-test>
```

El runner exige la base confirmada dos veces, coincidencia con `APP_DB_NAME` y
un entorno distinto de producción.

El DB-TEST confirma cuatro permisos activos, cuatro asignaciones activas a
ADMIN, 21 permisos intactos de CRUD-CATALOGOS-1, cero códigos duplicados y cero
relaciones duplicadas.

## Rollback

El rollback de código elimina la interfaz y sus servicios sin cambiar el
esquema DB-CATALOGOS-1. El rollback del seed retira solo las cuatro relaciones
de esta fase y elimina únicamente permisos sin otras asignaciones.

Las clasificaciones capturadas por usuarios no se eliminan automáticamente.
Los datos transitorios del DB-TEST se limpian explícitamente en la base
descartable confirmada.

## Fuera de alcance

- CRUD de tipos de cambio.
- Productos e inventario.
- Tickets, compras, ventas, CXC y CXP.
- Clientes, proveedores y reportes.
- Dashboard y métricas.
- Menú dinámico desde base de datos.
- Integración MySQL de `/health`.
- Deploy.
