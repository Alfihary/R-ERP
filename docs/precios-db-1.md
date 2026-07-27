# PRECIOS-DB-1

## Alcance

PRECIOS-DB-1 crea la estructura base de datos inicial del módulo de precios.

Esta fase crea únicamente:

- migración de base de datos;
- tabla `listas_precios`;
- tabla `producto_precios`;
- tabla `producto_precios_historial`;
- tabla `autorizaciones_precio`;
- seed de permisos de precios;
- seed de lista inicial `PUBLICO`;
- runner CLI `database/precios.php`;
- DB-TEST `database/tests/precios_1_test.php`;
- esta documentación.

No crea UI, rutas web, controladores web, servicios de negocio completos,
lógica de ventas, cotizaciones, pedidos, remisiones, facturas, triggers,
procedures, eventos, push, deploy ni remoto Git.

## Decisiones de negocio

- Las listas de precios son globales y no tienen `empresa_id`.
- El producto tiene moneda base en `productos.moneda_id`.
- Un producto sin moneda puede existir.
- Un producto sin moneda no debe tener precios; en PRECIOS-DB-1 esto queda
  documentado como regla de servicio futuro porque la base de datos no puede
  validar directamente el valor nullable de `productos.moneda_id` al insertar
  en `producto_precios`.
- Al crear o actualizar precio en una fase posterior,
  `producto_precios.moneda_id` deberá copiarse desde `productos.moneda_id`.
- `producto_precios` guarda solo el precio actual.
- `producto_precios_historial` guarda auditoría de cambios.
- `precio_minimo` es obligatorio.
- `precio_minimo <= precio_lista`.
- Un producto solo puede tener un precio actual por lista.
- Se permite cambiar la moneda del producto aunque tenga precios.
- Si cambia la moneda del producto, el servicio futuro deberá actualizar precios
  en ese momento. Los precios no capturados quedarán en `0.0000 / 0.0000` y con
  `requiere_revision = 1`.
- Un precio con `requiere_revision = 1` no debe usarse en ventas.
- Un precio con `precio_lista = 0.0000` no debe usarse en ventas.
- Las autorizaciones de precio son por partida.
- Una autorización se usa una sola vez.
- Las autorizaciones no se eliminan física ni lógicamente.
- El historial no se genera con triggers; lo generará un servicio futuro.

## Regla futura de ventas

Cuando exista ventas:

```text
precio_unitario >= precio_lista
permitido sin autorización

precio_minimo <= precio_unitario < precio_lista
requiere autorización

precio_unitario < precio_minimo
bloqueado
```

PRECIOS-DB-1 solo deja preparada la estructura para esa regla. No integra
ventas ni emite autorizaciones funcionales desde UI.

## Tabla listas_precios

`listas_precios` define catálogos globales de listas comerciales.

Campos principales:

- `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
- `clave VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL`
- `nombre VARCHAR(100) NOT NULL`
- `observaciones VARCHAR(500) NULL`
- `incluye_impuestos TINYINT(1) NOT NULL DEFAULT 0`
- `es_predeterminada TINYINT(1) NOT NULL DEFAULT 0`
- `activo TINYINT(1) NOT NULL DEFAULT 1`
- columnas de auditoría de creación, actualización y eliminación lógica
- `predeterminada_unica` como columna generada para permitir una sola lista
  predeterminada activa/no eliminada.

Reglas:

- `clave` entre 2 y 32 caracteres.
- `clave` en mayúsculas.
- `clave` con patrón `^[A-Z0-9]+([._-][A-Z0-9]+)*$`.
- flags booleanos en `0` o `1`.
- una lista predeterminada debe estar activa y no eliminada.
- `eliminado_en` y `eliminado_por` deben viajar ambos `NULL` o ambos con valor.

Seed inicial:

- `clave`: `PUBLICO`
- `nombre`: `Precio público`
- `observaciones`: `Lista predeterminada para ventas sin convenio comercial específico.`
- `incluye_impuestos`: `0`
- `es_predeterminada`: `1`
- `activo`: `1`

## Tabla producto_precios

`producto_precios` guarda el precio actual por producto y lista.

Campos principales:

- `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
- `id_producto VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL`
- `lista_precio_id BIGINT UNSIGNED NOT NULL`
- `moneda_id BIGINT UNSIGNED NOT NULL`
- `precio_lista DECIMAL(14,4) NOT NULL`
- `precio_minimo DECIMAL(14,4) NOT NULL`
- `incluye_impuestos TINYINT(1) NOT NULL DEFAULT 0`
- `requiere_revision TINYINT(1) NOT NULL DEFAULT 0`
- `activo TINYINT(1) NOT NULL DEFAULT 1`
- columnas de auditoría de creación, actualización y eliminación lógica.

Reglas:

- `UNIQUE (id_producto, lista_precio_id)`.
- `precio_lista >= 0`.
- `precio_minimo >= 0`.
- `precio_minimo <= precio_lista`.
- flags booleanos en `0` o `1`.
- si `requiere_revision = 1`, entonces `precio_lista = 0` y
  `precio_minimo = 0`.
- `eliminado_en` y `eliminado_por` deben viajar ambos `NULL` o ambos con valor.
- se usan llaves foráneas hacia `productos`, `listas_precios`, `monedas` y
  `usuarios`.

PRECIOS-DB-1 no implementa el servicio que copia la moneda desde el producto;
esa responsabilidad queda para una fase posterior.

## Tabla producto_precios_historial

`producto_precios_historial` registra cambios de precio y moneda sin triggers.

Campos principales:

- `producto_precio_id`
- `id_producto`
- `lista_precio_id`
- moneda anterior y moneda nueva
- precios anteriores y nuevos
- flags anteriores y nuevos
- `tipo_cambio`
- `motivo_cambio`
- `cambiado_en`
- `cambiado_por`

Reglas:

- los precios nuevos no pueden ser negativos;
- `precio_minimo_nuevo <= precio_lista_nuevo`;
- los precios anteriores deben ir ambos `NULL` o ambos con valor;
- si existen precios anteriores, también cumplen no negativos y mínimo menor o
  igual a lista;
- flags anteriores pueden ser `NULL` o booleanos;
- flags nuevos deben ser booleanos;
- si `requiere_revision_nuevo = 1`, los precios nuevos deben estar en cero;
- `tipo_cambio` se limita a:
  - `CREACION`
  - `ACTUALIZACION`
  - `CAMBIO_MONEDA`
  - `DESACTIVACION`
  - `REACTIVACION`
  - `ELIMINACION_LOGICA`

## Tabla autorizaciones_precio

`autorizaciones_precio` prepara la autorización por partida para vender por
debajo del precio de lista y por encima o igual al precio mínimo.

Campos principales:

- `folio`
- `empresa_id`
- identificadores de documento y partida
- `producto_precio_id`
- `id_producto`
- `lista_precio_id`
- `moneda_id`
- precio de lista, mínimo y solicitado como referencia
- `cantidad`
- `estatus`
- solicitante, decisor, cancelación, utilización y actualización.

Reglas:

- `folio` entre 5 y 32 caracteres, en mayúsculas y con patrón seguro.
- `documento_tipo` entre 3 y 32 caracteres, en mayúsculas y con patrón
  `^[A-Z0-9_]+$`.
- `cantidad > 0`.
- referencias de precio no negativas.
- `precio_minimo_referencia <= precio_lista_referencia`.
- `precio_solicitado >= precio_minimo_referencia`.
- `precio_solicitado < precio_lista_referencia`.
- `estatus` limitado a:
  - `PENDIENTE`
  - `APROBADA`
  - `RECHAZADA`
  - `CANCELADA`
  - `VENCIDA`
  - `UTILIZADA`
- `decidido_en` y `decidido_por` viajan juntos.
- `cancelado_en`, `cancelado_por` y `motivo_cancelacion` viajan juntos.
- `utilizado_en` y `utilizado_por` viajan juntos.
- `decidido_por` no puede ser igual a `solicitado_por`.
- se permite una sola autorización activa por
  `documento_tipo + documento_id + documento_partida_id` cuando el estatus es
  `PENDIENTE` o `APROBADA`.

La FK compuesta hacia `producto_precios` asegura que la autorización referencie
el precio actual esperado por producto, lista y moneda.

## Permisos sembrados

PRECIOS-DB-1 siembra permisos estructurales y los asigna al rol `ADMIN` de
forma idempotente:

- `precios.listas.acceder`
- `precios.listas.ver`
- `precios.listas.crear`
- `precios.listas.editar`
- `precios.listas.activar`
- `precios.listas.eliminar`
- `precios.listas.predeterminada`
- `precios.productos.acceder`
- `precios.productos.ver`
- `precios.productos.crear`
- `precios.productos.editar`
- `precios.productos.desactivar`
- `precios.productos.reactivar`
- `precios.productos.historial`
- `precios.autorizaciones.acceder`
- `precios.autorizaciones.ver`
- `precios.autorizaciones.solicitar`
- `precios.autorizaciones.aprobar`
- `precios.autorizaciones.rechazar`
- `precios.autorizaciones.cancelar`
- `precios.autorizaciones.utilizar`

Estos permisos no crean UI ni rutas web por sí mismos.

## DB-TEST

El DB-TEST valida:

- existencia de las cuatro tablas;
- `InnoDB` como motor;
- índices principales;
- FKs principales;
- migración idempotente;
- seed de permisos idempotente;
- seed de lista `PUBLICO` idempotente;
- lista `PUBLICO` activa y predeterminada;
- rechazo de clave duplicada en listas;
- rechazo de segunda predeterminada activa;
- rechazo de predeterminada inactiva;
- rechazo de `precio_minimo > precio_lista`;
- rechazo de `requiere_revision = 1` con precios distintos de cero;
- aceptación de `requiere_revision = 1` con precios cero;
- rechazo de duplicado `id_producto + lista_precio_id`;
- rechazo de FKs inválidas en precios;
- aceptación de historial `CREACION` con moneda anterior `NULL`;
- aceptación de historial `CAMBIO_MONEDA`;
- rechazo de historial en revisión con precios nuevos distintos de cero;
- aceptación de autorización si
  `precio_minimo <= precio_solicitado < precio_lista`;
- rechazo de autorización debajo del mínimo;
- rechazo de autorización igual o mayor al precio de lista;
- rechazo de decisor igual al solicitante;
- una sola autorización activa por partida;
- rollback de datos transitorios.

## Comandos de fase

Comandos principales:

```bash
php database/precios.php migrate --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/precios.php seed --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/precios.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/precios.php status --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

Regresiones relacionadas:

```bash
php database/productos-imagen.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/productos-2.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/crud-productos.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/inventario.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/inventario-service.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/series.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/folios-inventario.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

## Excepción documentada

`database/productos.php db:test` no se usa como bloqueo obligatorio para
PRECIOS-DB-1 sobre `r_erp_db_core_0_test`, porque ese runner histórico exige
tablas persistentes de productos vacías y la base de prueba actual contiene un
producto persistente real/no-QA:

```text
102016169 | REFRIGERANTE R-410A 5KG IGAS
```

No se debe borrar ese producto para cerrar esta fase.

## Límites

No crear en PRECIOS-DB-1:

- UI;
- rutas web;
- controladores web;
- `ProductPriceService` completo;
- lógica de ventas;
- cotizaciones;
- pedidos;
- remisiones;
- facturas;
- integración con folios operativos;
- integración con movimientos de inventario;
- triggers;
- stored procedures;
- eventos;
- dashboard;
- KPIs;
- deploy;
- remoto Git.

No modificar en PRECIOS-DB-1:

- `.env`;
- `package.json`;
- `package-lock.json`;
- rutas web;
- controladores existentes;
- servicios de productos;
- servicios de inventario;
- servicios de folios;
- migraciones cerradas.

## Siguientes fases posibles

- PRECIOS-SERVICE-1
- PRECIOS-UI-1
- integración futura con ventas
