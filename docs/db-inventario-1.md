# DB-INVENTARIO-1

## Objetivo

Crear la estructura base del núcleo de inventario sin implementar lógica operativa.

Principio obligatorio:

```text
MOVIMIENTOS = VERDAD HISTÓRICA
EXISTENCIAS = SALDO MATERIALIZADO
```

DB-INVENTARIO-1 no aplica movimientos y no actualiza existencias automáticamente.

## Diagnóstico de empresa y almacén

- `empresas.id` es `BIGINT UNSIGNED AUTO_INCREMENT` y llave primaria.
- `almacenes.id` es `BIGINT UNSIGNED AUTO_INCREMENT` y llave primaria.
- `almacenes.empresa_id` referencia `empresas.id`.
- `almacenes` tiene `UNIQUE(empresa_id, id)` mediante `uq_almacenes_empresa_id`.
- Esa llave permite validar en DB que un movimiento con `empresa_id` y `almacen_id` no mezcle una empresa con un almacén ajeno.

Decisión de scope:

- `movimientos_inventario` guarda `empresa_id` y `almacen_id`.
- La coherencia se garantiza con FK compuesta `(empresa_id, almacen_id)` hacia `almacenes(empresa_id, id)`.
- `existencias_producto` no guarda `empresa_id`; su empresa se deriva de `almacenes.empresa_id`.

## Diagnóstico de productos

- `productos.id_producto` es la PK natural.
- Tipo: `VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin`.
- No existe `producto_id` ni identidad alternativa.
- `productos` es catálogo global; no está ligado directamente a empresa.
- `tipos_producto` se resuelve por `codigo`, no por IDs fijos.
- Códigos estructurales existentes: `PRODUCTO`, `SERVICIO`, `KIT`.

`SERVICIO` no debe generar existencias como regla operativa futura. DB-INVENTARIO-1 no crea trigger ni FK especial para bloquearlo; esa validación queda para INVENTARIO-SERVICE-1.

## Tablas creadas

### conceptos_movimiento_inventario

Define por qué se mueve inventario.

Conceptos estructurales seed:

- `ENTRADA_AJUSTE` con naturaleza `ENTRADA`.
- `SALIDA_AJUSTE` con naturaleza `SALIDA`.

Naturalezas permitidas:

- `ENTRADA`
- `SALIDA`

No se crean naturalezas especiales para ajuste o transferencia. Una transferencia futura debe registrarse como salida del almacén origen más entrada al almacén destino.

### movimientos_inventario

Representa el encabezado histórico.

Estados permitidos:

- `BORRADOR`: todavía no afecta existencias.
- `APLICADO`: ya afectó existencias.
- `ANULADO`: invalidado mediante procedimiento controlado futuro.

Un movimiento `APLICADO` nunca debe editarse para cambiar su historia. Una corrección futura debe hacerse con movimiento de reversa más nuevo movimiento correcto.

DB-INVENTARIO-1 solo modela el estado; no implementa aplicación, anulación ni reversa.

### movimientos_inventario_detalle

Representa partidas del movimiento.

- `id_producto` referencia directamente `productos(id_producto)`.
- `cantidad` usa `DECIMAL(18,6)`.
- `cantidad` debe ser mayor a `0`.
- Un mismo producto no puede repetirse en el mismo movimiento: `UNIQUE(movimiento_id, id_producto)`.
- La cantidad no usa signo para representar entrada o salida.

### existencias_producto

Mantiene el saldo materializado actual.

Grano:

```text
almacén + producto = una existencia
```

La unicidad se garantiza con `UNIQUE(almacen_id, id_producto)`.

`cantidad_actual` usa `DECIMAL(18,6)`.

DB-INVENTARIO-1 no agrega `CHECK cantidad_actual >= 0`. La política de inventario negativo queda pendiente para INVENTARIO-SERVICE-1.

## Índices principales

- `uq_conceptos_movimiento_inventario_codigo`: evita códigos duplicados.
- `idx_conceptos_movimiento_inventario_activo`: filtra conceptos activos.
- `idx_movimientos_inventario_empresa_almacen`: consultas por scope.
- `idx_movimientos_inventario_almacen_fecha`: historial por almacén y fecha.
- `idx_movimientos_inventario_concepto`: consultas por concepto.
- `idx_movimientos_inventario_estado`: consultas por estado.
- `uq_movimientos_inventario_detalle_producto`: evita duplicar producto en un movimiento.
- `idx_movimientos_inventario_detalle_producto`: consultas por producto.
- `uq_existencias_producto_almacen_producto`: grano materializado único.
- `idx_existencias_producto_producto`: consultas por producto.

## FKs principales

- `movimientos_inventario.empresa_id -> empresas.id`.
- `movimientos_inventario(empresa_id, almacen_id) -> almacenes(empresa_id, id)`.
- `movimientos_inventario.concepto_movimiento_id -> conceptos_movimiento_inventario.id`.
- `movimientos_inventario_detalle.movimiento_id -> movimientos_inventario.id`.
- `movimientos_inventario_detalle.id_producto -> productos.id_producto`.
- `existencias_producto.almacen_id -> almacenes.id`.
- `existencias_producto.id_producto -> productos.id_producto`.

No se usan cascadas destructivas sobre inventario.

## Sin triggers ni procedures

No se crean triggers, stored procedures, functions SQL, events ni cron.

La actualización futura:

```text
BEGIN TRANSACTION
registrar movimiento
registrar partidas
actualizar existencias
COMMIT
```

queda para INVENTARIO-SERVICE-1.

## Campos no incluidos

No se agregan:

- disponible
- apartado
- comprometido
- en tránsito
- mínimos
- máximos
- punto de reorden
- costos
- precio
- valuación
- lotes
- series
- pedimentos
- reservas

Cada concepto tendrá fase propia.

## DB-TEST

Comando:

```powershell
php database/inventario.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

Valida:

- migración idempotente;
- seed idempotente;
- conceptos base;
- restricciones de código, naturaleza, estado y cantidad;
- FKs;
- unicidad de producto por movimiento;
- unicidad de existencia por producto/almacén;
- precisión decimal con strings;
- protección histórica sin cascadas destructivas;
- rollback de datos QA transitorios.

## Límites de fase

No se crea:

- `InventoryService`;
- `ProductInventoryService`;
- UI;
- rutas web;
- API;
- aplicar movimientos;
- anular movimientos;
- reversas;
- entradas, salidas, transferencias o ajustes operativos;
- kardex;
- costos;
- precios;
- compras;
- ventas;
- CFDI;
- facturación;
- dashboard.
