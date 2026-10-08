# SERIES-UI-1

## Alcance

SERIES-UI-1 conecta la interfaz existente de movimientos y transferencias de
inventario con el contrato funcional de `SERIES-SERVICE-1`.

No crea tablas, migraciones, servicios de dominio nuevos, RBAC nuevo, menú,
sidebar, kardex por serie, existencias por serie, lotes, pedimentos, costos,
valuación, compras, ventas, CFDI ni dashboard.

## Contrato de formulario

Cada partida del formulario puede enviar un textarea `series_text`:

```text
SERIE-001
SERIE-002
```

El controlador transforma ese valor a:

```php
[
    [
        'id_producto' => 'ABC123',
        'cantidad' => '2.000000',
        'series' => ['SERIE-001', 'SERIE-002'],
    ],
]
```

Reglas del parsing:

- aplica `trim()` por línea;
- ignora líneas vacías visuales;
- preserva mayúsculas/minúsculas;
- no deduplica silenciosamente;
- no convierte a mayúsculas;
- deja la validación real al servicio.

## Movimientos de inventario

En `GET /inventario/movimientos/crear` el buscador de productos devuelve
`controla_series`.

Cuando el producto controla series, la UI muestra un bloque por partida con la
ayuda:

> Este producto controla números de serie. Captura una serie por cada unidad.

Para entradas, el usuario captura manualmente una serie por línea. Para salidas,
la fase mantiene captura manual; si la serie no existe, está fuera de existencia
o pertenece a otro almacén, el servicio rechaza el POST con mensaje seguro.

Los productos no seriados no muestran el bloque y no envían series.

## Transferencias

En `GET /inventario/transferencias/crear` el comportamiento es equivalente:

- producto seriado muestra textarea de series;
- producto no seriado oculta el bloque;
- el POST envía las mismas series a `InventoryTransferService::transferir()`;
- el servicio mueve esas series de almacén origen a destino.

## Detalle de movimiento

`/inventario/movimientos/ver?id=...` muestra las series asociadas por partida
cuando existen. Las partidas no seriadas muestran `No aplica` y no renderizan
bloques vacíos innecesarios.

## Detalle de transferencia

`/inventario/transferencias/ver?ref=...` muestra series de salida y entrada
por partida. Esto permite verificar visualmente que la misma serie salió del
origen y entró al destino sin crear kardex avanzado por serie.

## Errores esperados

El servicio puede rechazar:

- producto seriado sin series;
- cantidad decimal para producto seriado;
- cantidad distinta al número de series;
- serie duplicada;
- serie inexistente;
- serie en otro almacén;
- serie fuera de existencia;
- producto no seriado con series enviadas.

Los controladores devuelven errores de formulario sin SQL, SQLSTATE, stack
trace, rutas internas ni datos sensibles.

## Endpoint de búsqueda de series

SERIES-UI-1 no crea endpoint de búsqueda/autocomplete de series. La decisión es
mantener la fase acotada a captura manual y validación transaccional del
servicio ya aprobado. Un endpoint privado de búsqueda queda pendiente para una
fase posterior si se requiere operación más asistida.

## Limitaciones

- No hay búsqueda avanzada de series.
- No hay kardex por serie.
- No hay vista de existencias por serie.
- No hay lotes.
- No hay pedimentos.
- No hay costos ni valuación.
