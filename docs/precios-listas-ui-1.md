# PRECIOS-LISTAS-UI-1

## Objetivo

Implementar una UI administrativa básica para listas de precios globales.

La fase permite listar, crear, editar, activar/desactivar, marcar como predeterminada y ver detalle básico de listas de precios.

## Rutas agregadas

- `GET /configuracion/listas-precios`
- `GET /configuracion/listas-precios/ver?id=ID`
- `GET /configuracion/listas-precios/crear`
- `POST /configuracion/listas-precios`
- `GET /configuracion/listas-precios/editar?id=ID`
- `POST /configuracion/listas-precios/actualizar`
- `POST /configuracion/listas-precios/activar`
- `POST /configuracion/listas-precios/desactivar`
- `POST /configuracion/listas-precios/predeterminada`

No se agregan rutas para una pantalla global de precios por producto.

## Controlador y servicio

- Controlador: `App\Http\Controllers\PriceListController`
- Servicio: `App\Domain\Pricing\PriceListService`
- Repositorio extendido: `App\Infrastructure\Repositories\PriceListRepository`

El controlador valida sesión/permisos vía middleware, valida CSRF en rutas `POST` por el middleware global existente y delega reglas de negocio al servicio.

## Vistas

- `app/Views/pricing/lists/index.php`
- `app/Views/pricing/lists/form.php`
- `app/Views/pricing/lists/show.php`

CSS modular:

- `public/css/modules/pricing-lists.css`

La navegación se agrega bajo Configuración solo cuando el usuario tiene `precios.listas.acceder`.

## Permisos aplicados

- `precios.listas.acceder`: listado
- `precios.listas.ver`: detalle
- `precios.listas.crear`: crear
- `precios.listas.editar`: editar
- `precios.listas.activar`: activar/desactivar
- `precios.listas.predeterminada`: marcar predeterminada

El permiso `precios.listas.eliminar` existe por esquema de permisos, pero la eliminación no se expone en esta fase.

## Reglas implementadas

- Las listas de precios son globales.
- No tienen `empresa_id`.
- `PUBLICO` se conserva como lista inicial predeterminada activa.
- La clave se normaliza a mayúsculas.
- La clave debe cumplir `^[A-Z0-9]+([._-][A-Z0-9]+)*$`.
- La clave es única.
- `incluye_impuestos` usa `0/1`.
- `activo` usa `0/1`.
- Una lista inactiva no puede ser predeterminada.
- Al marcar una lista activa como predeterminada, se desmarca la anterior en la misma transacción.
- No se permite desactivar la lista predeterminada. Primero debe marcarse otra lista como predeterminada.

## Acciones no implementadas

- No se implementa eliminar desde UI.
- No se implementa edición global de precios de producto.
- No se implementa historial de precios desde UI.
- No se implementan autorizaciones funcionales.
- No se implementan ventas.

## Base de datos

No se crean migraciones y no se modifica el esquema.

La fase usa tablas existentes de PRECIOS-DB-1:

- `listas_precios`
- `producto_precios`

## Pruebas

Runner:

```bash
php database/precios-listas-ui.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

Cobertura principal:

- `PUBLICO` existe y permanece activa/predeterminada.
- Permisos `precios.listas.*` activos para ADMIN.
- Ruta/vistas de listas declaradas.
- Creación válida.
- Normalización uppercase.
- Rechazo de clave duplicada.
- Rechazo de nombre vacío.
- Rechazo de clave inválida.
- Edición válida.
- Activar/desactivar lista no predeterminada.
- Rechazo de desactivar predeterminada.
- Marcar predeterminada lista activa.
- Desmarcar predeterminada anterior.
- Rechazo de marcar predeterminada una lista inactiva.
- Detalle básico.
- No se crean precios de producto.
- Cleanup/rollback de datos QA.

## Regresiones esperadas

- `php database/precios.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`
- `php database/precios-service.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`
- `php database/precios-producto-integracion.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`
- `php database/precios-producto-ui.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`
- `php database/productos-imagen.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`
- `php database/productos-2.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`
- `php database/crud-productos.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`
- `php database/inventario.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`
- `php database/inventario-service.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`
- `php database/series.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`
- `php database/folios-inventario.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`

No se usa `database/productos.php db:test` como bloqueo obligatorio por la excepción documentada del producto persistente real/no-QA:

`102016169 | REFRIGERANTE R-410A 5KG IGAS`

## Pendientes

- Definir si la eliminación lógica de listas se habilitará en una fase posterior.
- Definir UI futura para historial de precios.
- Definir UI futura para autorizaciones de precio.
- Definir integración futura con ventas.
