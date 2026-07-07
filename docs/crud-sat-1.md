# CRUD-SAT-1

CRUD-SAT-1 agrega administración estructural para los catálogos SAT creados en
DB-SAT-1:

- `unidades_sat`
- `claves_sat`

La fase no relaciona estos catálogos con productos, inventario, CFDI,
facturación, XML, timbrado ni importaciones masivas.

## Rutas

Unidades SAT:

- `GET /catalogos/unidades-sat`
- `GET /catalogos/unidades-sat/crear`
- `POST /catalogos/unidades-sat`
- `GET /catalogos/unidades-sat/editar?id=...`
- `POST /catalogos/unidades-sat/actualizar`
- `POST /catalogos/unidades-sat/activar`
- `POST /catalogos/unidades-sat/desactivar`

Claves SAT:

- `GET /catalogos/claves-sat`
- `GET /catalogos/claves-sat/crear`
- `POST /catalogos/claves-sat`
- `GET /catalogos/claves-sat/editar?id=...`
- `POST /catalogos/claves-sat/actualizar`
- `POST /catalogos/claves-sat/activar`
- `POST /catalogos/claves-sat/desactivar`

Todas las rutas privadas requieren autenticación. Las rutas `POST` requieren
CSRF válido.

## Permisos

CRUD-SAT-1 crea y asigna al rol `ADMIN` los permisos estructurales:

- `catalogos.unidades_sat.acceder`
- `catalogos.unidades_sat.crear`
- `catalogos.unidades_sat.editar`
- `catalogos.unidades_sat.estado`
- `catalogos.claves_sat.acceder`
- `catalogos.claves_sat.crear`
- `catalogos.claves_sat.editar`
- `catalogos.claves_sat.estado`

El seed `crud_sat_1_seed_permissions` es idempotente y no crea usuarios.

## Validación

`unidades_sat`:

- `codigo`: requerido, normalizado a mayúsculas, `^[A-Z0-9]{1,16}$`.
- `nombre`: requerido, máximo 120 caracteres.
- `descripcion`: opcional, máximo 255 caracteres.
- `activo`: solo cambia por rutas de estado controladas.

`claves_sat`:

- `codigo`: requerido, `^[0-9]{1,16}$`.
- `descripcion`: requerida, máximo 255 caracteres.
- `activo`: solo cambia por rutas de estado controladas.

El navegador no es fuente de verdad. La validación vive en el servicio y la base
mantiene restricciones estructurales creadas por DB-SAT-1.

## Búsqueda y paginación

`claves_sat` usa paginación del lado del servidor con límite fijo de 20
registros por página. No existe carga completa del catálogo, selector HTML con
todas las claves ni autocomplete.

La búsqueda acepta código exacto, prefijo de código y coincidencia parcial por
descripción. Los filtros de estado soportan `all`, `active` e `inactive` y se
preservan al paginar.

`unidades_sat` permite búsqueda y filtro de estado. Su listado está limitado
explícitamente y no se usa como selector de producto.

## Límites explícitos

CRUD-SAT-1 no incluye:

- relación SAT-producto;
- campos `clave_sat_id` o `unidad_sat_id` en productos;
- CRUD de productos;
- inventario, existencias, movimientos, precios, compras o ventas;
- importación masiva;
- descarga o consumo externo del SAT;
- CFDI, facturación, XML o timbrado;
- APIs JSON para productos;
- menú dinámico desde base de datos.
