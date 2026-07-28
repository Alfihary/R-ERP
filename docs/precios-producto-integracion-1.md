# PRECIOS-PRODUCTO-INTEGRACION-1

## Objetivo

Integrar la lógica base de precios de producto con el flujo existente de
productos sin crear todavía pantallas completas de precios, menú de precios,
ventas ni autorizaciones funcionales.

## Alcance implementado

- `ProductService` acepta precios iniciales opcionales en creación mediante
  `precios_iniciales`.
- Si se envían precios iniciales, `moneda_id` del producto es obligatoria.
- La creación de producto, impuestos, códigos de barras, imagen opcional y
  precios iniciales queda dentro de la misma transacción de producto.
- `ProductService` no implementa reglas monetarias propias; delega creación,
  validación de listas, importes, historial y cambios de moneda a
  `ProductPriceService`.
- En edición, si cambia `moneda_id` y el producto tiene precios, el cambio se
  delega a `ProductPriceService::cambiarMonedaProductoConPrecios()`.
- Los precios capturados para el cambio de moneda se actualizan a la nueva
  moneda.
- Los precios existentes no capturados en el cambio quedan con `0.0000`,
  `0.0000` y `requiere_revision=1`.
- El historial de esos cambios se registra como `CAMBIO_MONEDA`.

## Entradas integradas

### Creación

`precios_iniciales` debe enviarse como lista de arreglos:

```php
[
    [
        'lista_precio_id' => 1,
        'precio_lista' => '100.0000',
        'precio_minimo' => '80.0000',
    ],
]
```

Filas completamente vacías se ignoran para permitir formularios futuros con
renglones reservados.

### Cambio de moneda

`precios_cambio_moneda` debe enviarse como lista de arreglos:

```php
[
    [
        'lista_precio_id' => 1,
        'precio_lista' => '10.0000',
        'precio_minimo' => '8.0000',
    ],
]
```

Por compatibilidad interna también se acepta `precios_actualizados` cuando
`precios_cambio_moneda` no existe.

## Transacciones

`ProductService` mantiene la transacción principal del flujo de producto.
`ProductPriceService` respeta transacciones existentes, por lo que no confirma
parcialmente cuando se ejecuta dentro de producto.

En creación con imagen, el hook de imagen sigue ejecutándose dentro de la
transacción del producto. Si después falla la creación de precios, las filas de
producto/documento/precio se revierten. El controlador conserva la limpieza de
archivo físico ya existente mediante `discardStoredFile()`.

## DB-TEST

Runner:

```bash
php database/precios-producto-integracion.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

Casos cubiertos:

- producto con precios iniciales se crea correctamente;
- precios iniciales copian moneda del producto;
- historial `CREACION` se genera por precio inicial;
- precios iniciales sin `moneda_id` rechazan la creación;
- producto + imagen + precio inicial inválido revierte producto, documento y
  precio;
- cambio de moneda con precio capturado actualiza ese precio;
- precio no capturado en cambio de moneda queda en revisión con importes cero;
- historial `CAMBIO_MONEDA` se genera por cada precio existente;
- listas duplicadas en cambio de moneda se rechazan y no cambian la moneda;
- producto sin precios puede cambiar moneda sin crear precios ni historial;
- datos transitorios QA se revierten por rollback.

## Excepción conocida

`database/productos.php db:test` no se usa como bloqueador en esta fase porque
ese runner histórico exige tablas persistentes de productos vacías y la base de
prueba actual contiene un producto persistente real/no-QA:

- `102016169 | REFRIGERANTE R-410A 5KG IGAS`

No se elimina ni se limpia porque no es dato QA claramente transitorio.

## Fuera de alcance

- No se creó UI completa de precios.
- No se creó menú de precios.
- No se crearon rutas globales de precios.
- No se implementaron ventas.
- No se implementaron autorizaciones funcionales de precios.
- No se crearon triggers, procedures ni eventos.
- No se modificó inventario, series ni folios.
