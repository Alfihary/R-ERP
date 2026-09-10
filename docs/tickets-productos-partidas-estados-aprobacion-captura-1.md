# TP-PARTIDAS-ESTADOS-APROBACION-CAPTURA-1

## Objetivo

Implementar la captura real de datos autorizados al aprobar partidas de Tickets de Solicitud de Alta de Productos.

La fase mantiene el flujo documental: aprobar una partida no crea productos reales, precios, inventario, compras ni proveedores.

## Alcance

En `GET /tickets/productos/{id}`, los usuarios con permiso `tickets_productos.resolver` ven una sección `Aprobar partidas` cuando existen partidas en estado `EN_REVISION`.

Cada tarjeta de aprobación permite capturar:

- `clave_autorizada`, texto opcional, máximo 16 caracteres, con caracteres seguros.
- `descripcion_autorizada`, requerida, precargada con la descripción solicitada.
- `unidad_sat_autorizada`, campo único buscable con `datalist`.
- `clave_sat_autorizada`, campo único buscable con `datalist`.
- `comentario_resolucion`, respuesta para el solicitante, con texto sugerido.

El rechazo de partida se conserva como acción separada y mantiene motivo obligatorio.

## Persistencia

Se agregó migración aditiva:

`database/migrations/tp_partidas_estados_aprobacion_captura_1_001_add_authorized_line_fields.php`

Columnas nuevas en `tickets_productos_partidas`:

- `clave_autorizada VARCHAR(16) NULL`
- `descripcion_autorizada VARCHAR(255) NULL`
- `unidad_sat_id_autorizada BIGINT UNSIGNED NULL`
- `clave_sat_id_autorizada BIGINT UNSIGNED NULL`

Relaciones:

- `unidad_sat_id_autorizada` referencia `unidades_sat(id)`.
- `clave_sat_id_autorizada` referencia `claves_sat(id)`.

Índices:

- `idx_tickets_productos_partidas_unidad_sat_autorizada`
- `idx_tickets_productos_partidas_clave_sat_autorizada`

La migración no modifica migraciones cerradas y es reversible.

## Validaciones backend

La aprobación de partida valida:

- ticket existente;
- partida existente;
- partida perteneciente al ticket;
- partida en estado `EN_REVISION`;
- usuario activo;
- descripción autorizada requerida;
- clave autorizada con máximo 16 caracteres y patrón seguro;
- unidad SAT autorizada contra catálogo activo si se captura;
- clave SAT autorizada contra catálogo activo si se captura;
- no aprobar una partida ya resuelta.

Los catálogos SAT se resuelven en backend con prepared statements. El `datalist` solo mejora UX y no se usa como seguridad.

## Seguridad

Se conserva:

- ruta POST existente `/tickets/productos/{id}/partidas/{partidaId}/aprobar`;
- `AuthMiddleware`;
- `PermissionMiddleware` con `tickets_productos.resolver`;
- CSRF;
- escape de salida en vista;
- prepared statements;
- sin rutas nuevas;
- sin JS externo;
- sin descarga ni preview de adjuntos.

No se exponen rutas físicas, nombres internos de adjuntos, tokens ni SQL.

## Archivos principales

- `app/Http/Controllers/ProductRequestTicketController.php`
- `app/Domain/Tickets/ProductRequestTicketService.php`
- `app/Infrastructure/Repositories/ProductRequestTicketRepository.php`
- `app/Views/tickets/productos/show.php`
- `public/css/modules/tickets-productos.css`
- `database/tickets-productos-partidas-estados-aprobacion-captura.php`
- `database/tests/tickets_productos_partidas_estados_aprobacion_captura_1_test.php`

## Runner

Comando de prueba:

```bash
php database/tickets-productos-partidas-estados-aprobacion-captura.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

El runner aplica primero la migración base de tickets y luego la migración aditiva de esta fase.

## Criterios de aceptación

- La sección `Aprobar partidas` existe.
- Solo aparece con permiso `tickets_productos.resolver`.
- Solo contiene partidas `EN_REVISION`.
- El formulario usa la ruta de aprobación existente.
- Hay CSRF.
- SAT autorizado usa campos únicos buscables.
- No hay campos separados de búsqueda SAT.
- SAT inválido se rechaza.
- Partida ajena se rechaza.
- Partida ya resuelta no se puede aprobar de nuevo.
- Los datos autorizados se guardan.
- El comentario de resolución se guarda.
- El ticket recalcula contadores y estado.
- Rechazo sigue funcionando.
- Adjuntos runtime sigue funcionando.
- No se crean productos, precios, inventario, compras ni proveedores.
- No se hace staging ni commit en esta fase abierta.

## Fuera de alcance

- Crear producto real.
- Crear precio.
- Crear inventario.
- Crear compra.
- Crear proveedor.
- Implementar descarga o preview de adjuntos.
- Crear rutas nuevas.
- Crear permisos o seeds nuevos.
- Crear correo runtime.
- Crear JavaScript externo.
