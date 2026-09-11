# TP-PARTIDAS-ESTADOS-UX-FLUJO-CORRECCIONES-1

## Objetivo

Corregir detalles visuales y de flujo detectados en revisión real del módulo Tickets de Solicitud de Alta de Productos.

La fase no crea productos, precios, inventario, compras ni proveedores. Tampoco agrega rutas nuevas, descargas ni preview de adjuntos.

## Correcciones incluidas

### 1. Detalle con textos legibles

El detalle de ticket deja de depender de IDs crudos cuando existe texto relacionado.

Campos enriquecidos:

- Empresa: código y nombre.
- Almacén: código y nombre.
- Solicitante: username y nombre de perfil cuando existe.
- Unidad SAT solicitada: clave y nombre/descripción.
- Clave SAT solicitada: clave y descripción.
- Unidad SAT autorizada: clave y nombre/descripción.
- Clave SAT autorizada: clave y descripción.
- Moneda: código y nombre.
- Usuario resolutor: username y nombre de perfil cuando existe.

Si no existe texto relacionado, la vista conserva fallback seguro `—`.

### 2. Adjuntos iniciales desde creación

La pantalla `GET /tickets/productos/crear` permite anexar adjuntos generales iniciales del ticket mediante:

```html
<input type="file" name="adjuntos[]" multiple>
```

Contrato aplicado:

- Extensiones permitidas: PDF, JPG, JPEG, PNG, WEBP.
- Validación MIME real con `finfo`.
- Máximo 5 MB por archivo.
- Rechazo de doble extensión peligrosa.
- Almacenamiento privado en `storage/private/tickets_productos/{ticket_id}/`.
- Metadata en `tickets_productos_adjuntos`.
- Sin descarga.
- Sin preview.
- Sin rutas internas expuestas.

Los adjuntos iniciales son generales del ticket. Los adjuntos por partida durante creación quedan fuera de esta fase para evitar acoplar archivos a una partida que todavía no existe al momento de pintar el formulario. Los adjuntos por partida ya siguen disponibles desde el detalle del ticket.

Si falla un adjunto inicial, el servicio revierte la transacción y elimina archivos físicos ya guardados en esa operación.

### 3. Empresa hacia Almacén en creación

La vista de creación ya no renderiza desde PHP todos los almacenes como opciones visibles iniciales.

El contrato visual queda así:

- El JSON seguro contiene solo `empresa_id`, `almacen_id`, `codigo` y `nombre`.
- El select Empresa usa `empresa_id` real.
- El select Almacén se reconstruye desde JavaScript local al cargar la vista y al cambiar Empresa.
- Solo se muestran almacenes de la empresa seleccionada.
- Si no hay almacenes disponibles, se muestra `Sin almacenes asignados para esta empresa` y el mensaje de ayuda `No tienes almacenes asignados para esta empresa.`.
- Backend sigue validando empresa, almacén y alcance del usuario.

## Seguridad

- No se confía en JavaScript.
- No se confía en `accept` del navegador.
- No se confía en MIME enviado por navegador.
- SQL sigue en repositorio con prepared statements.
- Vistas escapan salida.
- Adjuntos privados no se guardan en `public/`.
- No se exponen `ruta_relativa`, `nombre_guardado` ni rutas físicas.

## Archivos de fase

- `app/Http/Controllers/ProductRequestTicketController.php`
- `app/Domain/Tickets/ProductRequestTicketService.php`
- `app/Infrastructure/Repositories/ProductRequestTicketRepository.php`
- `app/Views/tickets/productos/create.php`
- `app/Views/tickets/productos/show.php`
- `database/tickets-productos-partidas-estados-ux-flujo-correcciones.php`
- `database/tests/tickets_productos_partidas_estados_ux_flujo_correcciones_1_test.php`
- `docs/tickets-productos-partidas-estados-ux-flujo-correcciones-1.md`

## Validaciones esperadas

```bash
php -l app/Http/Controllers/ProductRequestTicketController.php
php -l app/Domain/Tickets/ProductRequestTicketService.php
php -l app/Infrastructure/Repositories/ProductRequestTicketRepository.php
php -l app/Views/tickets/productos/create.php
php -l app/Views/tickets/productos/show.php
php -l database/tickets-productos-partidas-estados-ux-flujo-correcciones.php
php -l database/tests/tickets_productos_partidas_estados_ux_flujo_correcciones_1_test.php
git diff --check
php database/tickets-productos-partidas-estados-ux-flujo-correcciones.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

## Criterios de aceptación

- El detalle muestra textos legibles cuando existen.
- Adjuntos iniciales generales funcionan desde creación.
- Adjuntos iniciales cumplen las mismas reglas de seguridad que runtime.
- Empresa hacia Almacén filtra visualmente desde carga y cambio.
- Backend rechaza almacén de otra empresa o fuera de alcance.
- No hay descarga ni preview.
- No se crean productos, precios, inventario, compras ni proveedores.
- No se modifica el producto `102016169`.
