# TP-PARTIDAS-ESTADOS-LISTADO-1

## Objetivo

Implementar el listado real de Tickets de Solicitud de Alta de Productos con filtros, búsqueda por folio, paginación básica y navegación al detalle.

El flujo permanece exclusivamente documental.

## Método de listado agregado

Se agrega `ProductRequestTicketRepository::listar(array $filters, int $page = 1, int $perPage = 20): array`.

El método usa PDO con consultas preparadas, filtros normalizados y orden por `created_at DESC, id DESC`.

## Filtros soportados

- `folio`: búsqueda parcial con `LIKE` preparado y escape de comodines.
- `estado`: solo acepta `EN_REVISION`, `RESUELTO_PARCIAL`, `APROBADO`, `RECHAZADO` y `CANCELADO`.
- `empresa_id`: entero positivo.
- `almacen_id`: entero positivo.
- `fecha_desde`: formato `YYYY-MM-DD`, consulta desde `00:00:00`.
- `fecha_hasta`: formato `YYYY-MM-DD`, consulta hasta `23:59:59`.

Valores inválidos se ignoran de forma segura.

## Paginación

- `page` mínimo `1`.
- `per_page` permitido: `10`, `20` o `50`.
- Valor default: `20`.
- Máximo efectivo: `50`.

La respuesta incluye `items`, `pagination` y `filters`.

## Cambios en index

La vista `app/Views/tickets/productos/index.php` ahora renderiza:

- formulario `GET` de filtros;
- total de resultados;
- tabla de tickets reales;
- empresa, almacén y solicitante cuando se pueden unir de forma segura;
- paginación anterior/siguiente;
- estado vacío cuando no hay resultados;
- navegación al detalle condicionada por permiso visual.

## Permisos visuales conservados

- `Nuevo ticket` sigue condicionado por `tickets_productos.crear`.
- `Ver detalle` sigue condicionado por `tickets_productos.ver`.
- Las rutas siguen protegidas por `AuthMiddleware` y `PermissionMiddleware`.

Los permisos visuales solo mejoran la experiencia de usuario y no sustituyen la seguridad real.

## Guardrails de no creación operativa

Esta fase no crea ni modifica:

- productos reales;
- precios;
- inventario;
- existencias;
- movimientos de inventario;
- compras;
- proveedores reales;
- adjuntos reales;
- correos.

## Qué NO hace esta fase

- No crea rutas.
- No modifica `routes/web.php`.
- No modifica `bootstrap/app.php`.
- No modifica `ProductRequestTicketService`.
- No modifica `create.php`.
- No modifica `show.php`.
- No modifica migraciones.
- No modifica seeds.
- No crea JavaScript.
- No implementa correos.
- No implementa adjuntos reales.
- No toca productos, precios, inventario, compras ni proveedores funcionalmente.

## Pruebas esperadas

- `php -l app/Http/Controllers/ProductRequestTicketController.php`
- `php -l app/Infrastructure/Repositories/ProductRequestTicketRepository.php`
- `php -l app/Views/tickets/productos/index.php`
- `php -l database/tickets-productos-partidas-estados-listado.php`
- `php -l database/tests/tickets_productos_partidas_estados_listado_1_test.php`
- `git diff --check`
- `php database/tickets-productos-partidas-estados-listado.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`

## Siguiente fase recomendada

`TP-PARTIDAS-ESTADOS-DETALLE-PULIDO-1`, para mejorar navegación y lectura del detalle sin mezclar correos, adjuntos reales ni conversión a productos.
