# TRANSFERENCIAS-UI-1

## Alcance

Esta fase agrega la primera interfaz operativa para transferencias entre
almacenes de una misma empresa activa:

- listado de transferencias aplicadas;
- formulario de creación;
- detalle de transferencia;
- búsqueda privada de productos inventariables;
- navegación autorizada desde el layout privado.

La fase no agrega cancelación, reversa, edición, eliminación, folios avanzados,
transferencias en tránsito, lotes, series, pedimentos, costos, precios, compras,
ventas ni CFDI.

## Permisos creados

El seed `transferencias_ui_1_seed_permissions` crea y asigna al rol `ADMIN`:

- `inventario.transferencias.acceder`
- `inventario.transferencias.ver`
- `inventario.transferencias.crear`

No se crean permisos de edición, anulación, cancelación, reversa ni eliminación.

## Flujo autorizado

El flujo implementado es:

```text
HTTP
  -> InventoryTransferController
  -> InventoryTransferService::transferir(...)
  -> InventoryRepository
  -> transacción
  -> salida + entrada + existencias
```

La UI y el controlador no modifican existencias directamente, no insertan
movimientos ni detalles directamente, no crean manualmente salida/entrada y no
duplican la lógica transaccional de transferencias.

## Contexto y seguridad

- `empresa_id` se resuelve desde el contexto activo del backend.
- `usuario_id` se resuelve desde la sesión autenticada.
- No se envían `empresa_id` ni `usuario_id` como campos ocultos.
- Los almacenes origen/destino se validan en backend contra la empresa activa.
- Toda ruta privada usa autenticación y permiso específico.
- El `POST /inventario/transferencias` usa CSRF global.
- Los errores de dominio se devuelven como `422` controlado.

## Búsqueda de productos

La búsqueda privada:

- requiere `inventario.transferencias.crear`;
- acepta mínimo dos caracteres;
- consulta por `id_producto` y `descripcion`;
- devuelve máximo 20 resultados;
- incluye solo productos activos tipo `PRODUCTO` o `KIT`;
- excluye `SERVICIO`;
- devuelve únicamente `id_producto`, `descripcion` y `tipo_codigo`.

## Listado y detalle

El listado reconstruye transferencias aplicadas por referencia común `TRF-*`,
con movimiento de salida `TRANSFERENCIA_SALIDA` y movimiento de entrada
`TRANSFERENCIA_ENTRADA`, ambos en estado `APLICADO`.

El detalle muestra datos de encabezado, almacén origen, almacén destino,
movimientos relacionados y partidas. Los enlaces a movimientos solo se muestran
si el usuario tiene `inventario.movimientos.ver`.

## Prueba

Runner:

```bash
php database/transferencias-ui.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

El DB-TEST crea datos QA transitorios, aplica una transferencia real mediante
`InventoryTransferService::transferir(...)`, valida consultas read-only,
permisos, rechazos relevantes y elimina los datos QA al finalizar.
