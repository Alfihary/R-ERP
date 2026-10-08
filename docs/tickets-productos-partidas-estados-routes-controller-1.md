# TP-PARTIDAS-ESTADOS-ROUTES-CONTROLLER-1

## Objetivo

Crear rutas privadas y controlador mínimo para operar Tickets de Solicitud de Alta de Productos con partidas resolubles individualmente.

El flujo sigue siendo exclusivamente documental: aprobar una partida no crea producto real, precio, inventario, compra ni proveedor.

## Rutas creadas

| Método | Ruta | Permiso | Acción |
| --- | --- | --- | --- |
| `GET` | `/tickets/productos` | `tickets_productos.ver` | `index` |
| `GET` | `/tickets/productos/crear` | `tickets_productos.crear` | `create` |
| `POST` | `/tickets/productos` | `tickets_productos.crear` | `store` |
| `GET` | `/tickets/productos/{id}` | `tickets_productos.ver` | `show` |
| `POST` | `/tickets/productos/{id}/partidas/{partidaId}/aprobar` | `tickets_productos.resolver` | `approveLine` |
| `POST` | `/tickets/productos/{id}/partidas/{partidaId}/rechazar` | `tickets_productos.resolver` | `rejectLine` |
| `POST` | `/tickets/productos/{id}/cancelar` | `tickets_productos.cancelar` | `cancel` |

Todas las rutas son privadas:

- `AuthMiddleware`;
- `PermissionMiddleware`;
- `CsrfMiddleware` global para `POST`.

## Controlador creado

`App\Http\Controllers\ProductRequestTicketController`

Métodos públicos:

- `index(Request $request): Response`
- `create(Request $request): Response`
- `store(Request $request): Response`
- `show(Request $request, array $params): Response`
- `approveLine(Request $request, array $params): Response`
- `rejectLine(Request $request, array $params): Response`
- `cancel(Request $request, array $params): Response`

## Flujo mínimo implementado

- `index` responde HTML mínimo seguro con título de tickets de productos.
- `create` responde HTML mínimo seguro con formulario básico.
- `store` llama a `ProductRequestTicketService::crearTicket()` y redirige al detalle.
- `show` obtiene el ticket por ID y muestra folio, estado, partidas, motivos de rechazo y eventos básicos.
- `approveLine` llama a `resolverPartida()` con acción `APROBAR`.
- `rejectLine` llama a `resolverPartida()` con acción `RECHAZAR` y exige motivo vía servicio.
- `cancel` llama a `cancelarTicket()` y exige motivo vía servicio.

## Guardrails de no creación operativa

El controlador no contiene SQL y delega al servicio documental existente.

Se valida que las acciones `store`, `approveLine`, `rejectLine` y `cancel` no cambien conteos de:

- `productos`;
- `producto_precios`;
- `existencias_producto`;
- `inventario_existencias`;
- `movimientos_inventario`;
- `compras`;
- `proveedores`.

## Qué NO hace esta fase

- No crea vistas completas.
- No crea CSS.
- No crea JavaScript.
- No implementa adjuntos reales.
- No implementa correos.
- No crea productos reales.
- No crea precios.
- No crea inventario.
- No modifica existencias.
- No crea compras.
- No crea proveedores reales.
- No genera claves definitivas.

## Pruebas ejecutadas

Validaciones esperadas:

- `php -l app/Http/Controllers/ProductRequestTicketController.php`
- `php -l routes/web.php`
- `php -l bootstrap/app.php`
- `php -l database/tickets-productos-partidas-estados-routes-controller.php`
- `php -l database/tests/tickets_productos_partidas_estados_routes_controller_1_test.php`
- `git diff --check`
- `php database/tickets-productos-partidas-estados-routes-controller.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`

Regresiones:

- `php database/tickets-productos-partidas-estados-permisos.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`
- `php database/tickets-productos-partidas-estados-service.php service:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`
- `php database/tickets-productos-partidas-estados-db.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`
- `php database/tickets-productos-partidas-estados-contrato.php audit --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`
- `php database/inventario-service.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`
- `php database/precios.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`

No se usa `database/productos.php db:test` como bloqueo obligatorio por la excepción documentada:

`102016169 | REFRIGERANTE R-410A 5KG IGAS`

## Siguiente fase recomendada

`TP-PARTIDAS-ESTADOS-VIEWS-1`

Construir vistas completas solo después de aprobar la superficie privada mínima de rutas/controlador.
