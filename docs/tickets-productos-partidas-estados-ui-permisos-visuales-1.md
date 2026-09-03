# TP-PARTIDAS-ESTADOS-UI-PERMISOS-VISUALES-1

## Objetivo

Aplicar permisos visuales en las vistas privadas de Tickets de Solicitud de Alta de Productos para que botones, enlaces, formularios y placeholders se muestren solo cuando el usuario autenticado tenga el permiso correspondiente.

Esta fase mantiene el flujo exclusivamente documental.

## Permisos visuales aplicados

- `tickets_productos.ver`
- `tickets_productos.crear`
- `tickets_productos.resolver`
- `tickets_productos.cancelar`
- `tickets_productos.adjuntos.ver`
- `tickets_productos.comentarios.crear`
- `tickets_productos.correo.reenviar`
- `tickets_productos.eventos.ver`

## Acciones visibles u ocultas

- `index.php`
  - Muestra `Nuevo ticket` solo con `tickets_productos.crear`.
  - Muestra enlace `Ver detalle` solo con `tickets_productos.ver`.
  - Si no hay permiso de creación, muestra el aviso discreto `No tienes permiso para crear tickets.`
- `create.php`
  - Conserva formulario y CSRF si existe `tickets_productos.crear`.
  - Si no existe permiso visual de creación, muestra alerta segura y no renderiza el formulario.
- `show.php`
  - Muestra `Aprobar partida` y `Rechazar partida` solo con `tickets_productos.resolver`.
  - Muestra `Cancelar ticket` solo con `tickets_productos.cancelar`.
  - Muestra eventos solo con `tickets_productos.eventos.ver`.
  - Muestra placeholder de adjuntos solo con `tickets_productos.adjuntos.ver`.
  - Muestra placeholder de comentarios documentales solo con `tickets_productos.comentarios.crear`.
  - Muestra únicamente un placeholder deshabilitado de reenvío de correo con `tickets_productos.correo.reenviar`.

## Seguridad

Las rutas siguen siendo el control principal de seguridad mediante `AuthMiddleware` y `PermissionMiddleware`. Los permisos visuales mejoran UX, pero no sustituyen middleware ni autorizaciones del servidor.

El controlador reutiliza `PermissionService::allows()` para derivar flags visuales del usuario autenticado. No consulta SQL directo y mantiene la delegación operativa en `ProductRequestTicketService`.

## Guardrails de no creación operativa

Esta fase no crea ni modifica:

- productos reales;
- precios;
- inventario;
- existencias;
- movimientos de inventario;
- compras;
- proveedores reales;
- claves definitivas.

Aprobar una partida sigue significando únicamente revisión documental.

## Qué NO hace esta fase

- No crea rutas.
- No modifica `routes/web.php`.
- No modifica `bootstrap/app.php`.
- No modifica servicios.
- No modifica repositorios.
- No modifica migraciones.
- No modifica seeds.
- No crea JavaScript.
- No implementa mail runtime.
- No implementa adjuntos reales.
- No toca productos, precios, inventario, compras ni proveedores funcionalmente.

## Pruebas esperadas

- `php -l app/Views/tickets/productos/index.php`
- `php -l app/Views/tickets/productos/create.php`
- `php -l app/Views/tickets/productos/show.php`
- `php -l app/Http/Controllers/ProductRequestTicketController.php`
- `php -l database/tickets-productos-partidas-estados-ui-permisos-visuales.php`
- `php -l database/tests/tickets_productos_partidas_estados_ui_permisos_visuales_1_test.php`
- `git diff --check`
- `php database/tickets-productos-partidas-estados-ui-permisos-visuales.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`

## Siguiente fase recomendada

Cerrar esta fase con commit selectivo si las regresiones obligatorias pasan. Después se recomienda una fase separada para conectar o ampliar acciones documentales futuras como comentarios, adjuntos privados o reenvío de correo, sin mezclarlo con productos reales.
