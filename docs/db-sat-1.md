# DB-SAT-1 — Catálogos SAT base

## Objetivo

Crear la estructura base para catálogos SAT que podrán relacionarse con
productos en una fase posterior. Esta fase solo agrega tablas, restricciones y
DB-TEST; no integra SAT al CRUD de productos.

## Tabla `unidades_sat`

`unidades_sat` representa unidades fiscales SAT, distintas de las unidades
comerciales internas del ERP.

Campos principales:

- `id`: llave primaria técnica.
- `codigo`: código estructural SAT, único, sensible a mayúsculas.
- `nombre`: nombre requerido.
- `descripcion`: descripción opcional.
- `activo`: estado lógico `0/1`.
- `eliminado_en`, `creado_por`, `actualizado_por`, `eliminado_por`,
  `creado_en`, `actualizado_en`: auditoría coherente con los catálogos del ERP.

Los servicios futuros deberán resolver unidades SAT por `codigo`, no por ID
numérico como regla de negocio.

## Tabla `claves_sat`

`claves_sat` representa claves fiscales SAT de productos/servicios.

Campos principales:

- `id`: llave primaria técnica.
- `codigo`: código estructural SAT, único.
- `descripcion`: descripción requerida.
- `activo`: estado lógico `0/1`.
- `eliminado_en`, `creado_por`, `actualizado_por`, `eliminado_por`,
  `creado_en`, `actualizado_en`: auditoría coherente con los catálogos del ERP.

No se crea jerarquía SAT en esta fase.

## Unidad comercial vs unidad SAT

`unidades_medida` es un catálogo comercial interno para operación del ERP.
`unidades_sat` es un catálogo fiscal que se usará para cumplimiento SAT cuando
se autorice la integración correspondiente.

En DB-SAT-1 no existe mapeo entre ambas tablas.

## Datos iniciales

Los catálogos quedan vacíos:

- `unidades_sat=0`
- `claves_sat=0`

No se inventaron claves SAT y no se descargaron datos externos.

## Fuera de alcance

DB-SAT-1 no incluye:

- carga masiva;
- CRUD de claves SAT;
- CRUD de unidades SAT;
- relación con productos;
- `clave_sat_id`;
- `unidad_sat_id`;
- CFDI;
- facturación;
- XML;
- timbrado;
- inventario;
- imagen de producto.

La relación con productos queda pendiente para `PRODUCTOS-SAT-1`.
