# TP-PARTIDAS-ESTADOS-SERVICE-1

## Objetivo

Implementar la capa de servicio y repositorio para Tickets de Solicitud de Alta de Productos con partidas resolubles individualmente.

Esta fase es exclusivamente documental: permite capturar, consultar, aprobar, rechazar y cancelar solicitudes documentales, pero no convierte una partida en producto real.

## Servicio creado

`App\Domain\Tickets\ProductRequestTicketService`

Métodos públicos:

- `crearTicket(array $input, int $usuarioId): array`
- `resolverPartida(int $ticketId, int $partidaId, string $accion, array $input, int $usuarioId): array`
- `cancelarTicket(int $ticketId, string $motivo, int $usuarioId): array`
- `obtenerTicket(int $ticketId): ?array`

## Repositorio creado

`App\Infrastructure\Repositories\ProductRequestTicketRepository`

Responsabilidades:

- transacciones;
- validación de existencia activa de usuario;
- validación de empresa activa;
- validación de almacén activo perteneciente a la empresa;
- emisión mínima transaccional de folio documental;
- creación de ticket;
- creación de partidas;
- obtención de ticket, partidas, comentarios, adjuntos y eventos;
- bloqueo `FOR UPDATE` para resolución;
- actualización de resolución de partida;
- recálculo de contadores;
- actualización de estado de ticket;
- cancelación;
- registro de eventos.

## Flujo de creación

`crearTicket` valida:

- `empresa_id` obligatorio;
- `almacen_id` obligatorio;
- solicitante activo;
- almacén perteneciente a empresa;
- observaciones generales opcionales;
- mínimo 1 partida;
- máximo 50 partidas.

Cada partida valida:

- descripción obligatoria;
- modelo opcional;
- marca texto opcional;
- proveedor documental opcional;
- unidad SAT opcional;
- clave SAT opcional;
- moneda opcional;
- costo sugerido opcional mayor o igual a cero;
- peso opcional mayor o igual a cero;
- lleva serie booleano;
- observaciones opcionales.

El servicio crea:

- ticket con estado `EN_REVISION`;
- partidas con estado `EN_REVISION`;
- numeración de partidas desde 1;
- contadores iniciales;
- evento `TICKET_CREADO`;
- evento `PARTIDA_AGREGADA` por cada partida.

Todo corre dentro de una transacción.

## Flujo de resolución por partida

`resolverPartida` acepta solo:

- `APROBAR`;
- `RECHAZAR`.

Reglas:

- ticket existente y no eliminado;
- partida existente, no eliminada y perteneciente al ticket;
- resolutor activo;
- no resolver ticket `CANCELADO`;
- no resolver partida ya `APROBADA` o `RECHAZADA`;
- aprobar deja `motivo_rechazo` en `NULL`;
- rechazar exige `motivo_rechazo`;
- actualiza `resuelto_por_usuario_id`, `resuelto_at` y `updated_at`;
- registra `PARTIDA_APROBADA` o `PARTIDA_RECHAZADA`;
- recalcula contadores y estado del ticket.

## Reglas de estado del ticket

El estado del ticket se deriva de las partidas activas:

- si existe al menos una partida `EN_REVISION`, el ticket queda `EN_REVISION`;
- si todas están `APROBADA`, el ticket queda `APROBADO`;
- si todas están `RECHAZADA`, el ticket queda `RECHAZADO`;
- si hay mezcla de aprobadas y rechazadas sin pendientes, queda `RESUELTO_PARCIAL`;
- si se cancela el ticket, queda `CANCELADO`.

## Reglas de folio

La emisión usa una integración mínima transaccional con:

- `series_documentales`;
- `documentos_folios`.

Contrato aplicado:

- prefijo desde `almacenes.codigo`;
- consecutivo por almacén;
- formato público `CODIGOALMACEN-000000`;
- ejemplo validado: `GU-000010`;
- defensa `UNIQUE(folio)` en `tickets_productos`;
- defensa `UNIQUE(folio)` en `documentos_folios`;
- no usa el ID del ticket como folio;
- no usa empresa como prefijo;
- no genera guiones extra.

Nota: `FolioService` existente usa otro formato soportado (`{PREFIJO}-{ALMACEN}{NUMERO}`). Por eso esta fase encapsula una emisión mínima en el repositorio de tickets, sin modificar agresivamente la infraestructura general de folios.

## Guardrails de no creación operativa

El servicio y el repositorio no insertan, actualizan ni eliminan:

- `productos`;
- `producto_precios`;
- `existencias_producto`;
- `movimientos_inventario`;
- `compras`;
- `proveedores`.

Aprobar o rechazar una partida es solo revisión documental.

## Guardrails heredados actualizados

Los guardrails de `TP-PARTIDAS-ESTADOS-CONTRATO-1` y
`TP-PARTIDAS-ESTADOS-DB-1` se actualizaron para reconocer esta evolución de
fases:

- contrato inicial: no existía service/repositorio;
- DB inicial: no existía service/repositorio;
- service actual: se autorizan solo `ProductRequestTicketService`,
  `ProductRequestTicketValidationException` y
  `ProductRequestTicketRepository`.

Siguen bloqueados rutas, controladores, vistas, CSS/JS, correos runtime, seeds y
cualquier creación funcional de productos, precios, inventario, compras o
proveedores.

## Qué NO hace esta fase

Esta fase no crea:

- rutas;
- controladores;
- vistas;
- carga real de adjuntos;
- correos;
- producto real;
- precio;
- inventario;
- compra;
- proveedor real;
- clave definitiva.

## Pruebas ejecutadas

Validaciones esperadas:

- `php -l app/Domain/Tickets/ProductRequestTicketService.php`
- `php -l app/Domain/Tickets/ProductRequestTicketValidationException.php`
- `php -l app/Infrastructure/Repositories/ProductRequestTicketRepository.php`
- `php -l database/tickets-productos-partidas-estados-service.php`
- `php -l database/tests/tickets_productos_partidas_estados_service_1_test.php`
- `git diff --check`
- `php database/tickets-productos-partidas-estados-service.php service:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`

Regresiones:

- `php database/tickets-productos-partidas-estados-db.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`
- `php database/tickets-productos-partidas-estados-contrato.php audit --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`
- `php database/inventario-service.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`
- `php database/precios.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`

## Siguiente fase recomendada

`TP-PARTIDAS-ESTADOS-CONTROLLER-1`

Crear rutas/controlador/vistas solo después de autorizar explícitamente la exposición privada del flujo.
