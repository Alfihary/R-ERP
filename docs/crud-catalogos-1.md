# CRUD-CATALOGOS-1 — Administración de catálogos base

## Objetivo

Administrar cinco catálogos globales aprobados mediante interfaces privadas,
permisos por acción, validación backend, PDO preparado y cambios de estado sin
borrado físico.

Incluye:

- Monedas.
- Unidades de medida.
- Impuestos.
- Líneas de producto.
- Marcas.

Tipos de cambio y clasificaciones de producto permanecen fuera de esta fase.

## Arquitectura

```text
ruta privada
  -> AuthMiddleware
  -> PermissionMiddleware de acceso
  -> PermissionMiddleware de acción
  -> CatalogController
  -> CatalogService
  -> CatalogRepository
  -> PDO preparado
```

`CatalogRepository` acepta únicamente cinco identificadores internos definidos
en una whitelist. Ningún nombre de tabla o columna procede del navegador.

Los controladores no contienen SQL y las vistas no autorizan acciones. Los
botones se muestran según permisos para mejorar la experiencia, pero la
decisión efectiva siempre se aplica en middleware.

## Rutas

| Método | Ruta | Permiso específico |
|---|---|---|
| GET | `/catalogos` | `catalogos.acceder` |
| GET | `/catalogos/{catalogo}` | `catalogos.{catalogo}.ver` |
| POST | `/catalogos/{catalogo}` | `catalogos.{catalogo}.crear` |
| POST | `/catalogos/{catalogo}/actualizar` | `catalogos.{catalogo}.editar` |
| POST | `/catalogos/{catalogo}/activar` | `catalogos.{catalogo}.estado` |
| POST | `/catalogos/{catalogo}/desactivar` | `catalogos.{catalogo}.estado` |

Los valores reales de `{catalogo}` son:

- `monedas`
- `unidades`
- `impuestos`
- `lineas`
- `marcas`

Cada ruta exige además sesión, `catalogos.acceder` y CSRF cuando usa POST.

## Permisos

El seed `crud_catalogos_1_seed_permissions` crea y asigna a ADMIN:

- `catalogos.acceder`
- `catalogos.monedas.{ver,crear,editar,estado}`
- `catalogos.unidades.{ver,crear,editar,estado}`
- `catalogos.impuestos.{ver,crear,editar,estado}`
- `catalogos.lineas.{ver,crear,editar,estado}`
- `catalogos.marcas.{ver,crear,editar,estado}`

Son 21 permisos estructurales. El seed es idempotente, no crea usuarios y no
modifica los tres permisos base de RBAC-0.

## Validaciones

### Monedas

- Código de exactamente tres letras, normalizado a mayúsculas.
- Nombre y símbolo obligatorios.
- Decimales entre 0 y 6.
- Código único.
- Una sola moneda base efectiva.
- La moneda base no puede perder la marca base ni desactivarse directamente.
- Marcar otra moneda como base cambia la base dentro de una transacción.

### Unidades

- Código normalizado a mayúsculas y único.
- Nombre y abreviatura obligatorios.

### Impuestos

- Código normalizado a mayúsculas y único.
- Nombre obligatorio.
- Tasa entre 0 y 100.
- Tipo limitado a `IVA`, `IEPS` o `EXENTO`.
- Un impuesto `EXENTO` debe tener tasa cero.

### Líneas y marcas

- Código normalizado a mayúsculas y único.
- Nombre obligatorio.

Los duplicados y errores de validación responden `422` con mensajes
controlados. Las operaciones exitosas redirigen al listado.

## Estado y auditoría

Activar o desactivar actualiza `activo` y `actualizado_por`. No existe ruta ni
método de borrado físico. Los registros eliminados lógicamente por procesos
futuros no se muestran.

Las escrituras utilizan como actor el ID de la sesión autenticada. No se
aceptan IDs de empresa o almacén desde el navegador.

## Alcance

Los cinco catálogos son globales. La empresa y almacén activos permanecen
visibles en el shell para orientar al usuario, pero no filtran estos registros.

Esto no concede acceso global a módulos futuros. Productos, inventario y
operaciones deberán aplicar su propio permiso y alcance.

## UI

- Navegación estática hacia Catálogos, visible solo con permiso.
- Directorio de los cinco catálogos autorizados.
- Formulario de alta compacto.
- Tabla operativa con estado y acciones.
- Edición progresiva mediante `details`, sin modal ni JavaScript.
- Tablas responsivas dentro de un contenedor horizontal.
- Valores dinámicos escapados con `e()`.

No hay dashboard, métricas, menú dinámico, tipos de cambio o jerarquías.

## Ejecución del seed y DB-TEST

```powershell
php database/crud-catalogos.php seed --database=<db-test> --confirm-database=<db-test>
php database/crud-catalogos.php db:test --database=<db-test> --confirm-database=<db-test>
```

El runner exige confirmación doble, coincidencia con la base configurada y un
entorno distinto de producción.

## Rollback

El rollback del seed retira las relaciones ADMIN y elimina únicamente permisos
de esta fase que no estén asignados a otro rol.

El código puede revertirse sin modificar la migración DB-CATALOGOS-1. Los
registros creados por usuarios deben conservarse o limpiarse mediante un plan
de datos separado; nunca se eliminan automáticamente al revertir la interfaz.

## Pendiente

- CRUD de tipos de cambio.
- CRUD jerárquico de clasificaciones y prevención transaccional de ciclos.
- Productos e inventario.
- Auditoría funcional avanzada.
- Búsqueda, filtros y paginación cuando el volumen real lo requiera.
