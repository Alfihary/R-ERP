# PRECIOS-AUTORIZACIONES-SERVICE-1

## Objetivo

Implementar la capa de servicio y repositorio para autorizaciones de precio, sin
crear UI, rutas web, controladores web ni integración con ventas.

La fase permite solicitar, aprobar, rechazar, cancelar, utilizar, consultar y
validar autorizaciones para precios solicitados por debajo de `precio_lista` y
por arriba o igual a `precio_minimo`.

## Archivos creados

- `app/Domain/Pricing/PriceAuthorizationService.php`
- `app/Infrastructure/Repositories/PriceAuthorizationRepository.php`
- `database/precios-autorizaciones-service.php`
- `database/tests/precios_autorizaciones_service_1_test.php`
- `docs/precios-autorizaciones-service-1.md`

## Estructura real usada

La tabla existente `autorizaciones_precio` proviene de PRECIOS-DB-1.

Campos relevantes:

- `id BIGINT UNSIGNED`
- `folio VARCHAR(32) ascii_bin`
- `empresa_id BIGINT UNSIGNED`
- `documento_tipo VARCHAR(32) ascii_bin`
- `documento_id BIGINT UNSIGNED`
- `documento_partida_id BIGINT UNSIGNED`
- `documento_folio VARCHAR(40) NULL`
- `producto_precio_id BIGINT UNSIGNED`
- `id_producto VARCHAR(16) ascii_bin`
- `lista_precio_id BIGINT UNSIGNED`
- `moneda_id BIGINT UNSIGNED`
- `precio_lista_referencia DECIMAL(14,4)`
- `precio_minimo_referencia DECIMAL(14,4)`
- `precio_solicitado DECIMAL(14,4)`
- `cantidad DECIMAL(14,4)`
- `incluye_impuestos TINYINT(1)`
- `motivo_solicitud VARCHAR(1000)`
- `estatus VARCHAR(16) ascii_bin`
- `solicitado_en`, `solicitado_por`
- `decidido_en`, `decidido_por`, `comentario_decision`
- `cancelado_en`, `cancelado_por`, `motivo_cancelacion`
- `utilizado_en`, `utilizado_por`
- `actualizado_en`, `actualizado_por`

Adaptación importante: aunque el input conceptual habla de IDs documentales como
texto, el esquema real usa `documento_id` y `documento_partida_id` como enteros
positivos. El servicio respeta el esquema existente y valida esos campos como
`BIGINT UNSIGNED` conceptuales.

## Estados

Se respetan los estados permitidos por la tabla:

- `PENDIENTE`
- `APROBADA`
- `RECHAZADA`
- `CANCELADA`
- `VENCIDA`
- `UTILIZADA`

La autorización utilizable para una línea es la que está `APROBADA` y sin
`utilizado_en`.

## Repositorio

`PriceAuthorizationRepository` encapsula SQL con PDO/prepared statements.

Métodos principales:

- `findById()`
- `findByIdForUpdate()`
- `findActiveByDocumentLine()`
- `findActiveByDocumentLineForUpdate()`
- `findUsableByDocumentLine()`
- `insert()`
- `update()`
- `list()`
- `count()`
- `activeUserExists()`
- `activeCompanyExists()`

## Servicio

`PriceAuthorizationService` contiene reglas de negocio transaccionales.

Métodos principales:

- `solicitar()`
- `aprobar()`
- `rechazar()`
- `cancelar()`
- `utilizar()`
- `obtenerUtilizableParaLinea()`
- `validarUsoParaPrecio()`
- `listar()`
- `ver()`

## Reglas de solicitud

El servicio usa `ProductPriceService::evaluarPrecioSolicitado()`.

- `PERMITIDO`: rechaza solicitud porque no requiere autorización.
- `REQUIERE_AUTORIZACION`: permite solicitud.
- `BLOQUEADO`: rechaza por debajo de mínimo.
- `SIN_PRECIO`: rechaza.
- `PRECIO_EN_REVISION`: rechaza.
- `SIN_PRECIO_UTILIZABLE`: rechaza.

Se guarda snapshot de:

- `producto_precio_id`
- `id_producto`
- `lista_precio_id`
- `moneda_id`
- `precio_lista_referencia`
- `precio_minimo_referencia`
- `precio_solicitado`
- `cantidad`
- `incluye_impuestos`

No se permite más de una autorización activa para la misma combinación:

`documento_tipo + documento_id + documento_partida_id`

## Reglas de decisión

`aprobar()` y `rechazar()`:

- Solo operan sobre `PENDIENTE`.
- Requieren `decidido_por`.
- Requieren motivo/comentario de decisión.
- Impiden que `decidido_por` sea igual a `solicitado_por`.
- Guardan `decidido_en`, `decidido_por` y `comentario_decision`.

## Reglas de cancelación

`cancelar()`:

- Solo opera sobre `PENDIENTE`.
- Guarda `cancelado_en`, `cancelado_por` y `motivo_cancelacion`.
- El permiso real queda pendiente para el controlador futuro.

## Reglas de uso

`utilizar()`:

- Solo opera sobre `APROBADA`.
- Rechaza `PENDIENTE`, `RECHAZADA` y `CANCELADA`.
- Rechaza autorizaciones ya utilizadas.
- Marca `estatus = UTILIZADA`.
- Guarda `utilizado_en` y `utilizado_por`.

`validarUsoParaPrecio()`:

- Permite sin autorización si `precio_unitario >= precio_lista`.
- Exige autorización aprobada/no utilizada si el precio está entre mínimo y lista.
- Valida que la autorización corresponda a producto, lista y precio.
- Bloquea precio debajo del mínimo o precio no utilizable.
- No marca utilización; eso queda para el consumo futuro.

## Integración futura

Ventas/cotizaciones deberán:

1. Evaluar el precio con `validarUsoParaPrecio()`.
2. Guardar el documento/línea real.
3. Llamar `utilizar()` solo al consumir definitivamente la autorización.
4. Aplicar permisos `precios.autorizaciones.*` en controladores o capa
   aplicativa futura.

## Pruebas

Runner:

```bash
php database/precios-autorizaciones-service.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

Casos cubiertos:

- Solicitud válida entre mínimo y lista.
- Snapshot de producto, lista, moneda y precios.
- Rechazo de precio que no requiere autorización.
- Rechazo de precio debajo de mínimo.
- Rechazo de producto sin precio utilizable.
- Rechazo de precio en revisión.
- Rechazo de autorización activa duplicada por línea.
- Aprobación, rechazo, cancelación y uso.
- Decisor distinto del solicitante.
- Obtención de autorización utilizable.
- Validación futura de uso.
- Listado y detalle.
- Permisos existentes para ADMIN.
- Ausencia de ventas/cotizaciones/pedidos/remisiones/facturas.
- Rollback de datos QA.

## Pendientes

- UI de autorizaciones.
- Controladores web.
- Permisos aplicados en rutas futuras.
- Integración con ventas/cotizaciones.
- Auditoría funcional si se requiere en fase posterior.

## Fuera de alcance confirmado

- No se crean migraciones.
- No se modifica esquema.
- No se crean seeds.
- No se crean triggers, procedures, functions ni events.
- No se crean ventas, cotizaciones, pedidos, remisiones ni facturas.
