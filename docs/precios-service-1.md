# PRECIOS-SERVICE-1

## Alcance

PRECIOS-SERVICE-1 implementa la capa de servicio y repositorios del módulo de
precios usando las tablas creadas en PRECIOS-DB-1.

Esta fase crea únicamente:

- `ProductPriceService`;
- `PricingValidationException`;
- `PriceListRepository`;
- `ProductPriceRepository`;
- `ProductPriceHistoryRepository`;
- runner CLI `database/precios-service.php`;
- DB-TEST `database/tests/precios_service_1_test.php`;
- esta documentación.

No crea UI, rutas web, controladores web, menús, vistas, ventas, cotizaciones,
pedidos, remisiones, facturas, autorizaciones funcionales completas, triggers,
stored procedures, eventos, push, deploy ni remoto Git.

## Servicios creados

### ProductPriceService

Clase:

```text
App\Domain\Pricing\ProductPriceService
```

Responsabilidades:

- crear precio actual de producto;
- crear precios iniciales para un producto;
- actualizar precio actual;
- desactivar y reactivar precios;
- consultar precio utilizable;
- evaluar precio solicitado contra precio de lista y mínimo;
- cambiar moneda de producto con precios existentes;
- registrar historial obligatorio sin triggers.

El servicio usa transacciones propias solo cuando no existe una transacción
externa activa. Esto permite usarlo en una fase posterior desde `ProductService`
cuando el alta de producto ya esté dentro de una transacción.

## Repositorios creados

### PriceListRepository

Clase:

```text
App\Infrastructure\Repositories\PriceListRepository
```

Métodos principales:

- `findById(int $id): ?array`
- `findActiveById(int $id): ?array`
- `findDefault(): ?array`
- `listActive(): array`
- `existsClave(string $clave, ?int $excludeId = null): bool`
- `insert(array $data): int`
- `update(int $id, array $data): void`
- `softDelete(int $id, int $userId): void`

Lista activa significa:

```text
activo = 1 AND eliminado_en IS NULL
```

Lista predeterminada significa:

```text
es_predeterminada = 1
AND activo = 1
AND eliminado_en IS NULL
```

### ProductPriceRepository

Clase:

```text
App\Infrastructure\Repositories\ProductPriceRepository
```

Métodos principales:

- `findById(int $id): ?array`
- `findByIdForUpdate(int $id): ?array`
- `findByProductAndList(string $idProducto, int $listaPrecioId): ?array`
- `findByProductAndListForUpdate(string $idProducto, int $listaPrecioId): ?array`
- `findAllByProduct(string $idProducto): array`
- `findAllByProductForUpdate(string $idProducto): array`
- `insert(array $data): int`
- `update(int $id, array $data): void`
- `softDelete(int $id, int $usuarioId): void`
- `reactivate(int $id, int $usuarioId): void`
- helpers de producto, moneda, usuario y transacción.

### ProductPriceHistoryRepository

Clase:

```text
App\Infrastructure\Repositories\ProductPriceHistoryRepository
```

Métodos:

- `insert(array $data): int`
- `listByProductPrice(int $productoPrecioId): array`
- `listByProduct(string $idProducto): array`

El historial se ordena por:

```text
cambiado_en DESC, id DESC
```

## Métodos de ProductPriceService

### crearPrecio(array $input): array

Input:

- `id_producto`
- `lista_precio_id`
- `precio_lista`
- `precio_minimo`
- `usuario_id`
- `motivo_cambio`

Reglas:

- no recibe `moneda_id` manualmente;
- no recibe `incluye_impuestos` manualmente;
- copia `moneda_id` desde `productos.moneda_id`;
- copia `incluye_impuestos` desde `listas_precios.incluye_impuestos`;
- producto requerido, activo y no eliminado;
- producto debe tener moneda;
- lista requerida, activa y no eliminada;
- no permite duplicado `id_producto + lista_precio_id`;
- `precio_lista >= 0`;
- `precio_minimo >= 0`;
- `precio_minimo <= precio_lista`;
- genera historial `CREACION`.

### crearPreciosInicialesProducto(string $idProducto, array $precios, int $usuarioId): array

Reglas:

- si `$precios` está vacío, devuelve arreglo vacío;
- no permite listas duplicadas;
- cada precio requiere `lista_precio_id`, `precio_lista` y `precio_minimo`;
- cada precio genera historial `CREACION`;
- respeta una transacción externa si ya existe.

PRECIOS-SERVICE-1 no modifica `ProductService`; la integración queda preparada
para una fase posterior.

### actualizarPrecio(array $input): array

Input:

- `producto_precio_id`
- `precio_lista`
- `precio_minimo`
- `usuario_id`
- `motivo_cambio`

Reglas:

- bloquea el precio con `FOR UPDATE`;
- valida precio existente y no eliminado;
- toma moneda actual desde el producto;
- actualiza `precio_lista`, `precio_minimo`, `moneda_id`,
  `incluye_impuestos`, `requiere_revision = 0`;
- si estaba en revisión con `0/0`, lo habilita con valores válidos;
- genera historial `ACTUALIZACION`.

### desactivarPrecio(int $productoPrecioId, int $usuarioId, string $motivo): array

Reglas:

- no borra físicamente;
- si ya está inactivo, devuelve el precio sin cambios;
- si está activo, actualiza `activo = 0`;
- genera historial `DESACTIVACION`.

### reactivarPrecio(int $productoPrecioId, int $usuarioId, string $motivo): array

Reglas:

- rechaza precios con `requiere_revision = 1`;
- rechaza precios con `precio_lista <= 0`;
- si ya está activo, devuelve el precio sin cambios;
- si está inactivo y es utilizable, actualiza `activo = 1`;
- genera historial `REACTIVACION`.

### cambiarMonedaProductoConPrecios(array $input): array

Input:

- `id_producto`
- `moneda_id_nueva`
- `precios_actualizados`
- `usuario_id`
- `motivo_cambio`

Reglas:

- producto requerido, activo y no eliminado;
- moneda nueva requerida, activa y no eliminada;
- si la moneda nueva es igual a la actual, devuelve resultado controlado sin
  cambios;
- bloquea producto y precios con `FOR UPDATE`;
- actualiza `productos.moneda_id`;
- no crea precios nuevos para listas no existentes;
- no permite listas duplicadas en `precios_actualizados`;
- por cada precio existente:
  - si la lista fue capturada, actualiza moneda y precios y deja
    `requiere_revision = 0`;
  - si la lista no fue capturada, deja `precio_lista = 0.0000`,
    `precio_minimo = 0.0000` y `requiere_revision = 1`;
  - genera historial `CAMBIO_MONEDA`.

### obtenerPrecioUtilizable(string $idProducto, int $listaPrecioId): ?array

Devuelve precio solo si cumple:

```text
activo = 1
eliminado_en IS NULL
requiere_revision = 0
precio_lista > 0
```

No devuelve precios en revisión.

### evaluarPrecioSolicitado(array $input): array

Resultados posibles:

- `SIN_PRECIO`
- `PRECIO_EN_REVISION`
- `SIN_PRECIO_UTILIZABLE`
- `PERMITIDO`
- `REQUIERE_AUTORIZACION`
- `BLOQUEADO`

Regla:

```text
precio_unitario >= precio_lista
PERMITIDO

precio_minimo <= precio_unitario < precio_lista
REQUIERE_AUTORIZACION

precio_unitario < precio_minimo
BLOQUEADO
```

### listarHistorialProductoPrecio(int $productoPrecioId): array

Devuelve historial del precio ordenado por:

```text
cambiado_en DESC, id DESC
```

### listarPreciosProducto(string $idProducto): array

Devuelve precios del producto con datos básicos de lista y moneda.

## Decimales

PRECIOS-SERVICE-1 evita `float` y `double`.

Los importes se validan y comparan como strings decimales con escala 4:

```text
DECIMAL(14,4)
```

Internamente las comparaciones convierten strings a unidades enteras de cuatro
decimales para evitar errores de punto flotante.

## Transacciones

Las operaciones de escritura son transaccionales:

- alta de precio + historial;
- altas iniciales múltiples;
- actualización + historial;
- desactivación/reactivación + historial;
- cambio de moneda de producto + actualización de precios + historial.

El servicio inicia transacción solo si no existe una transacción activa. Si se
usa dentro de una transacción externa, no hace commit ni rollback propio de esa
transacción externa.

## DB-TEST

Runner:

```bash
php database/precios-service.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

El DB-TEST valida:

- repositorio de listas;
- `crearPrecio`;
- copia de moneda desde producto;
- copia de `incluye_impuestos` desde lista;
- rechazo de producto sin moneda;
- rechazo de `precio_minimo > precio_lista`;
- rechazo de duplicado producto/lista;
- `crearPreciosInicialesProducto`;
- rechazo de listas duplicadas;
- cambio de moneda con precios capturados;
- cambio de moneda dejando precios no capturados en `0/0` y revisión;
- historial `CAMBIO_MONEDA`;
- precio utilizable;
- precio en revisión no utilizable;
- evaluación `PERMITIDO`;
- evaluación `REQUIERE_AUTORIZACION`;
- evaluación `BLOQUEADO`;
- evaluación `PRECIO_EN_REVISION`;
- evaluación `SIN_PRECIO`;
- actualización removiendo revisión;
- historial `ACTUALIZACION`;
- desactivación y reactivación;
- rechazo de reactivación para precio en revisión;
- consultas de historial y precios por producto;
- rollback de datos QA transitorios.

## Excepción heredada

`database/productos.php db:test` no se usa como bloqueo obligatorio sobre
`r_erp_db_core_0_test`, porque ese runner histórico exige tablas persistentes de
productos vacías y la base de prueba actual contiene un producto persistente
real/no-QA:

```text
102016169 | REFRIGERANTE R-410A 5KG IGAS
```

No se debe borrar ese producto para cerrar esta fase.

## Limitaciones y pendientes

Pendiente para fases posteriores:

- integración desde `ProductService`;
- UI de listas de precios;
- UI de precios por producto;
- UI de autorizaciones;
- emisión funcional de autorizaciones;
- integración con ventas;
- integración con cotizaciones, pedidos, remisiones o facturas.

## Límites confirmados

No se crea en PRECIOS-SERVICE-1:

- UI;
- controladores web nuevos;
- rutas web;
- vistas;
- menús;
- ventas;
- cotizaciones;
- pedidos;
- remisiones;
- facturas;
- triggers;
- stored procedures;
- eventos;
- deploy;
- remoto Git.
