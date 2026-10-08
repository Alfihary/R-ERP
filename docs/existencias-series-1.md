# EXISTENCIAS-SERIES-1

## Alcance

EXISTENCIAS-SERIES-1 agrega una vista read-only para consultar existencias por
número de serie. La pantalla usa las tablas `producto_series`,
`existencias_serie`, `productos` y `almacenes` ya creadas y alimentadas por
SERIES-DB-1, SERIES-SERVICE-1 y SERIES-UI-1.

No crea movimientos, no edita series, no cambia estados, no transfiere, no
elimina y no agrega rutas POST.

## Ruta

`GET /inventario/existencias-series`

La ruta es privada y requiere sesión autenticada.

## Permiso

Permiso único:

`inventario.existencias_series.acceder`

El seed `database/seeds/existencias_series_1_seed_permissions.php` lo crea de
forma idempotente y lo asigna al rol `ADMIN`.

No existen permisos de crear, editar, eliminar, ajustar ni transferir para esta
fase.

## Filtros

La pantalla usa filtros GET:

- `q`: busca por número de serie, `id_producto` o descripción.
- `id_producto`: filtra un producto específico.
- `estado`: acepta `EN_EXISTENCIA`, `FUERA_EXISTENCIA` o vacío.
- `almacen_id`: filtra almacenes activos de la empresa activa.
- `page`: página actual.

El tamaño de página es 25.

## Tabla

Campos mostrados:

- producto;
- descripción;
- número de serie;
- estado;
- almacén actual;
- activo;
- fecha de actualización;
- enlace de referencia al producto.

Cuando una serie está `FUERA_EXISTENCIA`, `existencias_serie.almacen_id` debe
ser `NULL` y la vista muestra `Sin almacén`. No se inventa ubicación.

## Consulta read-only

La consulta vive en `InventoryQueryRepository::serialStock()`.

Reglas:

- usa PDO prepared statements;
- no usa `SELECT *`;
- aplica filtros seguros;
- aplica paginación con límite controlado;
- respeta empresa activa;
- limita series al almacén activo o a series fuera de existencia asociadas a la
  empresa activa por movimientos aplicados;
- no modifica datos.

## Relación con tablas de series

`producto_series` contiene la identidad del número de serie por producto.

`existencias_serie` contiene el estado operativo actual:

- `EN_EXISTENCIA` con `almacen_id` obligatorio;
- `FUERA_EXISTENCIA` con `almacen_id = NULL`.

## Relación con existencias_producto

`existencias_producto` mantiene saldos agregados por producto y almacén.

Esta fase no recalcula ni modifica esos saldos. La vista por serie consulta el
detalle seriado actual y lo complementa con el historial aplicado para respetar
el contexto de empresa.

## Limitaciones

- No incluye kardex por serie.
- No incluye búsqueda avanzada/autocomplete de series.
- No permite acciones sobre series desde esta pantalla.
- No muestra lotes ni pedimentos.

## Pendientes futuros

- Kardex por serie.
- Búsqueda avanzada de series.
- Lotes.
- Pedimentos.
