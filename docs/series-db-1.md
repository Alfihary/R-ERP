# SERIES-DB-1

## Alcance

SERIES-DB-1 crea la estructura base de datos para controlar números de serie
en inventario. La fase prepara el modelo para productos con
`productos.controla_series = 1`, pero no activa todavía el control funcional.

SERIES-DB-1 no activó el control funcional de series. Ese comportamiento se
documenta y prueba en `docs/series-service-1.md`.

## Tablas creadas

### producto_series

Tabla maestra de series.

- `id`
- `id_producto`
- `numero_serie`
- `activo`
- `eliminado_en`
- `creado_en`
- `actualizado_en`

Reglas:

- Una serie pertenece a un producto.
- La serie es única por producto mediante `UNIQUE (id_producto, numero_serie)`.
- La serie no es única global.
- `numero_serie` no permite cadena vacía.
- `activo` solo permite `0` o `1`.

### existencias_serie

Saldo materializado actual por serie.

- `serie_id`
- `almacen_id`
- `estado`
- `creado_en`
- `actualizado_en`

Reglas:

- `serie_id` es la llave primaria.
- Si `estado = EN_EXISTENCIA`, `almacen_id` debe existir.
- Si `estado = FUERA_EXISTENCIA`, `almacen_id` debe ser `NULL`.
- Una serie no puede existir simultáneamente en dos almacenes.

### movimiento_detalle_series

Histórico de asignación de series por detalle de movimiento.

- `movimiento_detalle_id`
- `serie_id`
- `creado_en`

Reglas:

- La llave primaria es `(movimiento_detalle_id, serie_id)`.
- Registra qué series participaron en una línea de movimiento.
- No sustituye a `movimientos_inventario_detalle`.

## Relaciones

- `producto_series.id_producto` referencia `productos(id_producto)`.
- `existencias_serie.serie_id` referencia `producto_series(id)`.
- `existencias_serie.almacen_id` referencia `almacenes(id)`.
- `movimiento_detalle_series.movimiento_detalle_id` referencia
  `movimientos_inventario_detalle(id)`.
- `movimiento_detalle_series.serie_id` referencia `producto_series(id)`.

## Principios de diseño

Se conservan los principios actuales:

- MOVIMIENTOS = VERDAD HISTÓRICA.
- EXISTENCIAS = SALDO MATERIALIZADO.

`existencias_producto` sigue existiendo como saldo agregado por producto y
almacén. `existencias_serie` queda como dimensión complementaria para futuras
validaciones de productos seriados.

## Límites

Esta fase no crea:

- UI.
- rutas web.
- controladores.
- permisos RBAC.
- sidebar o menús.
- captura de series.
- selección de series.
- kardex por serie.
- existencias por serie en UI.
- transferencias con series.
- lotes.
- pedimentos.

Esta fase no modifica:

- `InventoryService`.
- `InventoryTransferService`.
- `InventoryRepository`.
- `InventoryQueryRepository`.
- vistas de inventario.
- CSS o JavaScript.

## Relación futura con SERIES-SERVICE-1

SERIES-SERVICE-1 deberá activar reglas funcionales:

- Si un producto tiene `controla_series = 1`, la cantidad debe ser entera.
- El número de series asignadas debe coincidir con la cantidad del detalle.
- Una entrada debe crear o asociar series válidas.
- Una salida debe consumir series existentes en el almacén origen.
- Una transferencia debe mover las mismas series del almacén origen al destino.
- `existencias_producto` y `existencias_serie` deben actualizarse en la misma
  transacción.

## Riesgos

- Desincronizar `existencias_producto` y `existencias_serie` si la lógica futura
  no se ejecuta en una única transacción.
- Permitir cantidades decimales en productos seriados si SERIES-SERVICE-1 no
  valida cantidad entera.
- Mover una serie en transferencia sin preservar la misma identidad de origen a
  destino.

## DB-TEST

El DB-TEST de esta fase valida:

- creación de tablas;
- columnas esperadas;
- FKs;
- índices;
- checks;
- unicidad por producto;
- mismo número de serie permitido en productos distintos;
- estados válidos e inválidos;
- relación con detalle de movimiento;
- duplicados;
- rollback real de las tablas;
- idempotencia de migración;
- limpieza de datos QA mediante rollback transaccional.
