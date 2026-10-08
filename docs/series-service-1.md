# SERIES-SERVICE-1

## Alcance

SERIES-SERVICE-1 activa la lógica funcional de números de serie en los
servicios de inventario y transferencias usando las tablas creadas en
SERIES-DB-1.

No crea rutas, controladores, vistas, menú, RBAC nuevo, buscadores, lotes,
pedimentos, costos, compras, ventas ni UI de series.

## Contrato de entrada

`InventoryService::aplicarMovimiento()` y
`InventoryTransferService::transferir()` aceptan `series` opcional por
partida:

```php
[
    [
        'id_producto' => 'ABC123',
        'cantidad' => '2.000000',
        'series' => ['SERIE-001', 'SERIE-002'],
    ],
]
```

Las series se normalizan con `trim()`. El servicio conserva mayúsculas y
minúsculas porque `producto_series.numero_serie` usa collation `ascii_bin`.
No convierte silenciosamente los números de serie.

## Productos serializados

Para productos con `productos.controla_series = 1`:

- `series` es obligatorio.
- La cantidad debe ser entera positiva.
- Se aceptan formas decimales equivalentes a entero como `2`,
  `2.0` y `2.000000`.
- Se rechazan cantidades fraccionarias como `2.500000`.
- El número de series debe coincidir con la cantidad.
- No se permiten series duplicadas en la misma partida.
- No se permiten series vacías.

## Productos no serializados

Los productos con `controla_series = 0` siguen permitiendo cantidades
decimales. No requieren series.

Si una partida no serializada envía `series`, el servicio rechaza la
operación y no crea filas en `producto_series`.

## Entradas

En movimientos de naturaleza `ENTRADA`:

- Si la serie no existe en `producto_series`, se crea activa.
- Si existe y está `FUERA_EXISTENCIA`, puede reingresar.
- Si existe y está `EN_EXISTENCIA`, se rechaza para evitar duplicidad física.
- `existencias_serie` queda `EN_EXISTENCIA` en el almacén del movimiento.
- `movimiento_detalle_series` registra la relación histórica con el detalle.
- `existencias_producto` se incrementa en la misma transacción.

## Salidas

En movimientos de naturaleza `SALIDA`:

- Cada serie debe existir, estar activa y pertenecer al producto.
- Cada serie debe estar `EN_EXISTENCIA` en el almacén del movimiento.
- `existencias_serie` queda `FUERA_EXISTENCIA` con `almacen_id = NULL`.
- `movimiento_detalle_series` registra la relación histórica.
- `existencias_producto` se decrementa en la misma transacción.

## Transferencias

En transferencias:

- La misma lista de series se asocia al detalle de salida y al detalle de
  entrada.
- Cada serie debe estar `EN_EXISTENCIA` en el almacén origen.
- `existencias_serie` queda `EN_EXISTENCIA` en el almacén destino.
- El agregado origen disminuye y el agregado destino aumenta en la misma
  transacción.

## Estrategia transaccional y bloqueos

La aplicación mantiene el flujo dentro de una transacción del repositorio.
El orden operativo es:

1. Crear movimiento y detalles en borrador.
2. Bloquear `existencias_producto`.
3. Resolver/crear IDs de serie.
4. Bloquear `existencias_serie` ordenado por `serie_id`.
5. Validar estados de serie.
6. Insertar `movimiento_detalle_series`.
7. Actualizar `existencias_serie`.
8. Actualizar `existencias_producto`.
9. Marcar movimiento como `APLICADO`.

Si cualquier validación falla, la transacción revierte movimiento, detalle,
histórico por serie, existencia por serie y existencia agregada.

## Métodos de repositorio agregados

- `activeSeriesByNumbers()`
- `createSeries()`
- `lockSeriesStocks()`
- `saveSeriesStock()`
- `insertMovementDetailSeries()`

Además, `activeProductsByIds()` devuelve `controla_series` y
`insertMovementDetail()` retorna el ID del detalle creado.

## DB-TEST

`database/tests/series_service_1_test.php` cubre entradas, salidas,
transferencias, rechazos por cantidad decimal, conteo incorrecto, duplicados,
series inexistentes, almacén incorrecto, productos no serializados, movimiento
mixto y rollback total ante fallo de una partida serializada.

El runner `database/series.php db:test` ejecuta:

- `SERIES-DB-1` para esquema.
- `SERIES-SERVICE-1` para comportamiento funcional.

## Limitaciones pendientes

- La UI inicial de captura manual queda documentada en `docs/series-ui-1.md`.
- No hay buscador/autocomplete avanzado de series.
- No hay lotes ni pedimentos.
- No hay costos ni valuación.
- No hay reservas.
- No hay anulación de movimientos.
