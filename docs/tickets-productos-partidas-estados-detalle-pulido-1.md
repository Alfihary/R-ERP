# TP-PARTIDAS-ESTADOS-DETALLE-PULIDO-1

## Objetivo

Pulir la pantalla de detalle de Tickets de Solicitud de Alta de Productos para que la revisión documental de partidas, estados, motivos, comentarios, eventos y acciones permitidas sea más clara.

La fase mantiene el flujo exclusivamente documental.

## Cambios en `show`

- Encabezado más claro con folio y estado del ticket.
- Advertencias documentales visibles:
  - `Este ticket es documental y no crea productos reales.`
  - `Autorizar una partida no crea el producto en el catálogo.`
- Bloque de contexto con empresa, almacén, solicitante, fecha de creación, fecha de actualización y observaciones generales.
- Resumen de partidas con total, en revisión, aprobadas y rechazadas.
- Partidas presentadas como tarjetas legibles.
- Eventos presentados como timeline simple sin metadata cruda.

## Bloques visuales agregados

- `ticket-products__detail-head`
- `ticket-products__warnings`
- `ticket-products__metrics`
- `ticket-products__line-card`
- `ticket-products__event-type`
- `ticket-products__event-copy`
- `ticket-products__event-meta`

## Permisos visuales conservados

- `tickets_productos.resolver` controla aprobar/rechazar partidas.
- `tickets_productos.cancelar` controla cancelar ticket.
- `tickets_productos.eventos.ver` controla eventos.
- `tickets_productos.adjuntos.ver` controla placeholder de adjuntos.
- `tickets_productos.comentarios.crear` controla placeholder de comentarios.
- `tickets_productos.correo.reenviar` controla placeholder deshabilitado de reenvío.

La seguridad real sigue dependiendo de middleware, controlador y servicio existentes; la vista solo refleja permisos visuales.

## Acciones ocultas por estado y permiso

- Aprobar/rechazar solo se muestra cuando:
  - el usuario tiene permiso `tickets_productos.resolver`;
  - la partida está en `EN_REVISION`.
- Las partidas `APROBADA` y `RECHAZADA` no muestran formularios de aprobación/rechazo.
- Cancelar solo se muestra cuando:
  - el usuario tiene permiso `tickets_productos.cancelar`;
  - el ticket no está en `CANCELADO`.

## Placeholders agregados o conservados

- Adjuntos: `Adjuntos documentales pendientes de fase posterior.`
- Comentarios: `Comentarios documentales pendientes de fase posterior.`
- Correo: `Reenvío de correo pendiente de fase posterior.`

Estos placeholders no ejecutan lógica, no escriben archivos y no envían correos.

## Guardrails de no creación operativa

Esta fase no crea ni modifica:

- productos reales;
- precios;
- existencias;
- inventario;
- movimientos de inventario;
- compras;
- proveedores reales;
- claves definitivas;
- adjuntos reales;
- correos runtime.

Aprobar una partida continúa significando únicamente revisión documental.

## Qué NO hace esta fase

- No modifica rutas.
- No modifica `bootstrap/app.php`.
- No modifica el servicio de dominio.
- No modifica el repositorio.
- No modifica `index.php`.
- No modifica `create.php`.
- No crea JavaScript.
- No implementa correos.
- No implementa adjuntos reales.
- No toca migraciones ni seeds.
- No toca compras, inventario, precios ni productos funcionalmente.

## Pruebas ejecutadas

Runner específico:

```bash
php database/tickets-productos-partidas-estados-detalle-pulido.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

Validaciones esperadas:

- `php -l` de vista, runner y test.
- `git diff --check`.
- Regresiones heredadas de listado, UI, permisos visuales, routes/controller, permisos, service, DB y contrato.
- Guardrails de inventario y precios.

## Siguiente fase recomendada

`TP-PARTIDAS-ESTADOS-COMENTARIOS-1`
