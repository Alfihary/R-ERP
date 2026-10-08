# TP-PARTIDAS-ESTADOS-COMENTARIOS-1

## Objetivo

Implementar comentarios documentales para Tickets de Solicitud de Alta de Productos usando la tabla existente `tickets_productos_comentarios`.

La fase mantiene el flujo exclusivamente documental. Agregar un comentario no aprueba, rechaza, cancela ni convierte partidas en productos reales.

## Contrato funcional

- Los comentarios pueden ser generales al ticket.
- Los comentarios pueden asociarse a una partida mediante `partida_id`.
- `partida_id` es opcional.
- Si se informa `partida_id`, debe pertenecer al ticket indicado.
- El comentario es obligatorio.
- El comentario admite hasta 2000 caracteres.
- La visibilidad registrada es `INTERNA`.
- Cada comentario registra usuario y fecha.
- Cada alta de comentario registra evento documental `COMENTARIO_AGREGADO`.

## Ruta privada

Se agrega la ruta:

```text
POST /tickets/productos/{id}/comentarios
```

La ruta usa el stack privado existente de tickets de productos:

- `AuthMiddleware`
- `PermissionMiddleware`
- permiso `tickets_productos.comentarios.crear`
- protección CSRF global para POST

No se agrega ruta pública para comentarios.

## Controlador

`ProductRequestTicketController::comment()`:

- Lee el ticket desde `{id}`.
- Lee `comentario`.
- Lee `partida_id` como valor opcional.
- Delega en `ProductRequestTicketService::agregarComentario()`.
- Redirige al detalle del ticket.
- En errores de validación reutiliza la respuesta segura existente.
- No contiene SQL.

## Servicio de dominio

`ProductRequestTicketService::agregarComentario()`:

- Valida `ticket_id`, `partida_id` opcional, usuario y comentario.
- Rechaza comentario vacío.
- Rechaza comentario mayor a 2000 caracteres.
- Confirma usuario activo.
- Confirma ticket existente.
- Confirma pertenencia de partida cuando aplica.
- Inserta el comentario mediante repositorio.
- Registra evento `COMENTARIO_AGREGADO`.
- Devuelve el ticket normalizado.

No modifica estados de ticket ni de partida.

## Repositorio

`ProductRequestTicketRepository::agregarComentario()` inserta en:

```text
tickets_productos_comentarios
```

Campos usados:

- `ticket_producto_id`
- `partida_id`
- `usuario_id`
- `comentario`
- `visibilidad`
- `created_at`

La escritura usa prepared statements.

## Vista de detalle

`app/Views/tickets/productos/show.php` ahora muestra:

- sección `Comentarios`;
- advertencia de que los comentarios son documentales;
- comentarios generales;
- formulario para comentario general cuando existe permiso;
- comentarios por partida;
- formulario para comentario de partida cuando existe permiso.

Los formularios solo se muestran con `tickets_productos.comentarios.crear`.

La vista escapa valores con `e()`, no expone rutas físicas, no imprime metadata cruda y no muestra datos sensibles.

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
- correos runtime.

También mantiene sin cambios:

- migraciones;
- seeds;
- bootstrap;
- rutas públicas;
- JavaScript;
- correo;
- adjuntos, salvo el runtime autorizado posterior `POST /tickets/productos/{id}/adjuntos` de `TP-PARTIDAS-ESTADOS-ADJUNTOS-RUNTIME-1`.

## Pruebas esperadas

Runner específico:

```bash
php database/tickets-productos-partidas-estados-comentarios.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

Validaciones:

- `php -l` de archivos PHP modificados y nuevos.
- `git diff --check`.
- Test específico de comentarios.
- Regresiones heredadas de detalle pulido, listado, permisos visuales, UI, routes/controller, permisos, service, DB y contrato.
- Guardrails de inventario y precios.

## Criterios de aceptación

- Se puede agregar comentario general al ticket.
- Se puede agregar comentario a una partida.
- Se rechaza comentario vacío.
- Se rechaza partida ajena al ticket.
- La ruta exige autenticación, permiso y CSRF.
- Los comentarios aparecen en el detalle.
- Los formularios se ocultan sin permiso visual.
- Los estados del ticket y de la partida no cambian.
- No se crea información operativa.

## Siguiente fase recomendada

`TP-PARTIDAS-ESTADOS-ADJUNTOS-1`
