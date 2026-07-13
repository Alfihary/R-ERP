# MOVIMIENTOS-INVENTARIO-1

## Alcance

Esta fase crea la primera interfaz operativa de inventario para ajustes manuales.

Incluye:

- listado de movimientos;
- detalle de movimiento;
- formulario para ajuste de entrada;
- formulario para ajuste de salida;
- buscador privado y limitado de productos;
- permisos RBAC mínimos.

No incluye anulación, reversa, transferencias, compras, ventas, kardex, pantalla
de existencias, series, lotes, pedimentos, costos, precios, CFDI ni facturación.

## Arquitectura

El flujo autorizado es:

```text
HTTP
→ InventoryController
→ InventoryService
→ InventoryRepository
→ transacción
→ movimientos + detalles + existencias
```

LA UI NO MODIFICA EXISTENCIAS DIRECTAMENTE.

TODO MOVIMIENTO SE APLICA MEDIANTE INVENTORYSERVICE.

El controlador no abre transacciones, no actualiza existencias y no inserta
movimientos o detalles por su cuenta.

## Contexto activo

La creación de movimientos usa el contexto activo autenticado del servidor:

- `empresa_id`;
- `almacen_id`;
- `usuario_id`.

El formulario muestra empresa y almacén activos, pero no permite editarlos ni
los envía como fuente de verdad desde el navegador.

Si no existe contexto activo válido, la creación se bloquea y no se invoca
`InventoryService`.

## Permisos

Permisos creados:

- `inventario.movimientos.acceder`;
- `inventario.movimientos.ver`;
- `inventario.movimientos.crear`.

Se asignan al rol estructural `ADMIN` mediante seed idempotente.

No existen permisos de editar, eliminar, anular o revertir porque esas
operaciones no están implementadas.

## Conceptos autorizados

La UI y el backend permiten únicamente:

- `ENTRADA_AJUSTE`;
- `SALIDA_AJUSTE`.

El backend valida whitelist aunque el navegador sea manipulado.

## Listado

El listado se limita al almacén activo y usa paginación server-side.

Filtros:

- referencia o identificador visual `MOV-{id}`;
- concepto;
- estado;
- fecha desde;
- fecha hasta.

El identificador `MOV-{id}` es presentación, no folio empresarial ni nueva
identidad funcional.

## Formulario

Campos:

- tipo de ajuste;
- fecha y hora;
- referencia opcional;
- observaciones opcionales;
- partidas.

Cada partida incluye:

- producto;
- cantidad;
- observaciones opcionales.

La cantidad admite escala 6 y el backend conserva a `InventoryService` como
autoridad final.

## Buscador

La búsqueda de productos es privada:

```text
GET /inventario/productos/buscar?q=...
```

Requiere autenticación y permiso `inventario.movimientos.crear`.

Devuelve máximo 20 resultados con:

- `id_producto`;
- `descripcion`;
- `tipo_codigo`.

Solo devuelve productos activos de tipo `PRODUCTO` o `KIT`. Excluye `SERVICIO`.
No devuelve costos, precios, auditoría, permisos ni rutas internas.

## Detalle

El detalle muestra encabezado y partidas del movimiento histórico.

No permite edición, eliminación, reapertura, anulación ni reversa.

Un movimiento `APLICADO` es inmutable en esta fase.

## Errores

Los errores de dominio se presentan como mensajes controlados con HTTP 422 o el
patrón equivalente de formulario.

No se muestran stack traces, SQL, SQLSTATE ni rutas internas.

## Seguridad

- Toda ruta es privada.
- Toda ruta pasa por permiso por acción.
- Todo POST pasa por CSRF.
- La consulta respeta empresa y almacén activos.
- Las vistas escapan valores dinámicos con `e()`.
- JavaScript solo mejora UX; no concede permisos ni decide stock.

## Límites

MOVIMIENTOS-INVENTARIO-1 no crea:

- migraciones;
- anulación;
- reversa;
- transferencia;
- kardex general;
- pantalla de existencias;
- series;
- lotes;
- pedimentos;
- costos;
- precios;
- compras;
- ventas;
- CFDI;
- facturación;
- dashboard;
- KPIs;
- métricas;
- deploy;
- remoto Git.
