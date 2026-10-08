# KARDEX-SERIES-1

## Objetivo

Agregar una vista privada de solo lectura para consultar el historial de movimientos por número de serie.

## Ruta

- `GET /inventario/kardex-series`

No se crean rutas `POST`, `PUT`, `PATCH` ni `DELETE` para esta pantalla.

## Permiso

- `inventario.kardex_series.acceder`

El permiso se crea mediante seed idempotente y se asigna al rol `ADMIN`.

No se crean permisos de escritura para kardex por serie.

## Alcance

La consulta se limita a la empresa activa del contexto autenticado. El kardex por serie no se limita al almacén activo porque debe mostrar la trazabilidad completa de la serie dentro de la empresa, incluyendo entradas, salidas y transferencias entre almacenes de la misma empresa.

El filtro `almacen_id` filtra por el almacén del movimiento, no por el almacén actual de la serie.

## Datos consultados

La vista muestra:

- fecha del movimiento
- concepto
- naturaleza de entrada o salida
- folio/referencia del movimiento
- producto
- número de serie
- almacén del movimiento
- estado actual de la serie
- almacén actual, si aplica
- observaciones del movimiento

## Filtros

La pantalla usa filtros por `GET`:

- `q`: busca por número de serie, `id_producto`, descripción del producto y referencia de movimiento.
- `id_producto`: filtra por producto.
- `numero_serie`: permite búsqueda exacta o parcial por número de serie.
- `estado_actual`: permite todos, `EN_EXISTENCIA` y `FUERA_EXISTENCIA`.
- `almacen_id`: filtra por almacén del movimiento.
- `fecha_desde`: filtra desde la fecha de movimiento.
- `fecha_hasta`: filtra hasta la fecha de movimiento.

La vista incluye botones `Filtrar` y `Limpiar`. No usa `POST` para filtrar.

## Resumen

Cuando el filtro identifica una sola serie, el resumen muestra:

- producto
- número de serie
- estado actual
- almacén actual
- total de movimientos encontrados
- última fecha de movimiento

Cuando no hay una sola serie identificada, muestra un resumen general de los resultados filtrados.

## Estados

Los estados consultados para la existencia actual de una serie son:

- `EN_EXISTENCIA`
- `FUERA_EXISTENCIA`

`EN_EXISTENCIA` muestra el almacén actual cuando existe. `FUERA_EXISTENCIA` se muestra sin almacén actual.

## Relaciones consultadas

La consulta read-only se apoya en:

- `producto_series`: identifica el número de serie y su producto.
- `existencias_serie`: obtiene estado actual y almacén actual de la serie.
- `movimiento_detalle_series`: vincula cada serie con el detalle de movimiento.
- `movimientos_inventario_detalle`: obtiene el producto y detalle asociado.
- `movimientos_inventario`: obtiene fecha, referencia, estado del movimiento y almacén del movimiento.
- `conceptos_movimiento_inventario`: identifica concepto y naturaleza de entrada o salida.
- `productos`: obtiene la descripción del producto.
- `almacenes`: obtiene nombres de almacén de movimiento y almacén actual.

La consulta no usa `SELECT *`, usa prepared statements, filtros acotados, paginación y alcance por empresa activa.

## Transferencias

Las transferencias se visualizan como dos movimientos aplicados con la misma referencia `TRF-*`:

- `TRANSFERENCIA_SALIDA` en el almacén origen.
- `TRANSFERENCIA_ENTRADA` en el almacén destino.

La tabla muestra el almacén de cada movimiento para que el origen y destino sean comprensibles sin crear acciones desde esta vista.

## Restricciones

- No modifica existencias.
- No ajusta inventario.
- No transfiere series.
- No anula movimientos.
- No edita ni elimina movimientos.
- No crea lotes, pedimentos, costos, precios, compras, ventas ni CFDI.
- No agrega dashboard, KPIs ni métricas analíticas.

## Seguridad

La ruta queda protegida por autenticación y por `PermissionMiddleware` con el permiso `inventario.kardex_series.acceder`.

La vista escapa valores dinámicos con `e()` y no expone IDs internos sensibles, permisos internos, hashes ni credenciales.

## DB-TEST

El DB-TEST `kardex_series_1_test.php` valida:

- permiso creado y asignado a `ADMIN`
- seed idempotente
- ausencia de permisos de escritura
- ruta `GET` existente
- ausencia de ruta `POST`
- entrada por serie visible
- salida por serie visible
- salida por transferencia visible
- entrada por transferencia visible
- estado actual de la serie
- almacén actual de la serie
- filtros por búsqueda, producto, serie, estado, almacén y fechas
- paginación
- aislamiento por empresa
- consulta read-only sin cambios de conteos
- limpieza de datos QA

## Limitaciones y pendientes futuros

Quedan fuera de KARDEX-SERIES-1:

- búsqueda avanzada
- exportación
- lotes
- pedimentos
- costos
- valuación
- compras
- ventas
- CFDI
