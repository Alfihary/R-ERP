# TP-PARTIDAS-ESTADOS-ADJUNTOS-RUNTIME-1

## Objetivo

Implementar subida real y segura de adjuntos para Tickets de Solicitud de Alta de Productos desde el detalle del ticket ya creado.

La fase no adjunta archivos durante la creación inicial del ticket.

## Alcance implementado

- Ruta privada `POST /tickets/productos/{id}/adjuntos`.
- Protección con `AuthMiddleware`.
- Protección con `PermissionMiddleware` usando el permiso existente `tickets_productos.adjuntos.ver`.
- CSRF global para el POST.
- Formulario de adjunto general en `GET /tickets/productos/{id}`.
- Formulario de adjunto por partida en `GET /tickets/productos/{id}`.
- Almacenamiento privado fuera de `public/` en `storage/private/tickets_productos/{ticket_id}/`.
- Registro de metadata en `tickets_productos_adjuntos`.
- Listado visual de adjuntos existentes sin descarga ni preview.

## Tipos permitidos

Extensiones permitidas:

- `pdf`
- `jpg`
- `jpeg`
- `png`
- `webp`

MIME real permitido:

- `application/pdf`
- `image/jpeg`
- `image/png`
- `image/webp`

Tamaño máximo: 5 MB.

## Validaciones de archivo

- Archivo presente.
- `UPLOAD_ERR_OK`.
- Tamaño mayor a 0 bytes.
- Tamaño menor o igual a 5 MB.
- Extensión permitida.
- MIME real validado con `finfo`.
- Coincidencia extensión/MIME.
- Rechazo de doble extensión peligrosa como `factura.php.pdf`.
- Nombre original sanitizado para uso visual.
- Nombre interno generado aleatoriamente.
- Hash SHA-256 calculado y almacenado.

## Metadata registrada

La tabla `tickets_productos_adjuntos` registra:

- `ticket_producto_id`
- `partida_id` opcional
- `subido_por_usuario_id`
- `nombre_original`
- `nombre_guardado`
- `ruta_relativa`
- `mime`
- `extension`
- `tamano_bytes`
- `hash_sha256`
- `created_at`

La vista solo muestra datos visuales seguros:

- nombre original
- extensión
- MIME
- tamaño legible
- fecha
- usuario
- alcance general o por partida

La vista no muestra:

- ruta física
- ruta relativa
- nombre interno generado
- link de descarga
- preview

## Eventos

La migración cerrada ya permite `ADJUNTO_CARGADO`, por lo que la fase registra ese evento al subir un adjunto.

No se modifica la migración cerrada.

## Guardrails

- No se crea producto real.
- No se crea precio.
- No se crea inventario.
- No se crea compra.
- No se crea proveedor.
- No se crea permiso nuevo.
- No se crea migración nueva.
- No se crea seed nuevo.
- No se implementa descarga.
- No se implementa preview.
- No se crea JavaScript.
- No se toca `app/Views/tickets/productos/create.php`.
- No se toca `app/Views/tickets/productos/index.php`.
- No se toca `bootstrap/app.php`.

## Validación esperada

```powershell
php -l routes/web.php
php -l app/Http/Controllers/ProductRequestTicketController.php
php -l app/Domain/Tickets/ProductRequestTicketService.php
php -l app/Infrastructure/Repositories/ProductRequestTicketRepository.php
php -l app/Views/tickets/productos/show.php
php -l app/Support/SafeUpload.php
php -l database/tickets-productos-partidas-estados-adjuntos-runtime.php
php -l database/tests/tickets_productos_partidas_estados_adjuntos_runtime_1_test.php
git diff --check
php database/tickets-productos-partidas-estados-adjuntos-runtime.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

## Regresiones mínimas

```powershell
php database/tickets-productos-partidas-estados-create-catalogos.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/tickets-productos-partidas-estados-comentarios.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/tickets-productos-partidas-estados-detalle-pulido.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/tickets-productos-partidas-estados-listado.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/tickets-productos-partidas-estados-ui.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/tickets-productos-partidas-estados-routes-controller.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/tickets-productos-partidas-estados-permisos.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/tickets-productos-partidas-estados-service.php service:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/tickets-productos-partidas-estados-db.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/inventario-service.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/precios.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

## Criterios de aceptación

- Se pueden subir adjuntos generales.
- Se pueden subir adjuntos por partida.
- Se aceptan PDF, JPG, JPEG, PNG y WEBP con MIME real válido.
- Se rechazan PHP, JS, HTML, SVG, ZIP, MIME falso, doble extensión peligrosa, archivo vacío y archivo mayor a 5 MB.
- La metadata queda registrada en `tickets_productos_adjuntos`.
- Los archivos quedan fuera de `public/`.
- `show.php` no expone rutas ni nombres internos.
- No hay descarga ni preview.
- El estado del ticket y de la partida no cambia por subir adjuntos.
- No se crean datos operativos de productos, precios, inventario, compras ni proveedores.
