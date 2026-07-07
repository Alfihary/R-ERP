# CRUD-PRODUCTOS-1

## Objetivo

Administrar el catálogo estructural global de productos sin introducir
inventario, precios ni documentos funcionales.

## Identidad

`id_producto` conserva el contrato aprobado en DB-PRODUCTOS-1:

```sql
VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin
PRIMARY KEY
```

El servicio convierte la entrada de creación a mayúsculas y valida
`^[A-Z0-9]{1,16}$`. La base mantiene su `CHECK` como segunda barrera.

La identidad no se edita después de la creación. Es una llave natural
referenciada directamente por impuestos, códigos de barras y metadatos de
documentos. El formulario de edición la presenta como solo lectura y el
servicio rechaza cualquier discrepancia entre el ID original y el enviado.

## Rutas y permisos

| Método | Ruta | Permiso |
| --- | --- | --- |
| GET | `/productos` | `productos.acceder` |
| GET | `/productos/crear` | `productos.acceder`, `productos.crear` |
| POST | `/productos` | `productos.acceder`, `productos.crear` |
| GET | `/productos/ver?id_producto=...` | `productos.acceder`, `productos.ver` |
| GET | `/productos/editar?id_producto=...` | `productos.acceder`, `productos.editar` |
| POST | `/productos/actualizar` | `productos.acceder`, `productos.editar` |
| POST | `/productos/activar` | `productos.acceder`, `productos.estado` |
| POST | `/productos/desactivar` | `productos.acceder`, `productos.estado` |

Todas las rutas pasan por `AuthMiddleware` y `PermissionMiddleware`. El
middleware CSRF global protege todas las escrituras. No existen rutas DELETE.

## Permisos estructurales

- `productos.acceder`
- `productos.ver`
- `productos.crear`
- `productos.editar`
- `productos.estado`

El seed `crud_productos_1_seed_permissions` crea o reactiva estos permisos y
los asigna al rol estructural `ADMIN` de forma idempotente. No crea usuarios.

## Validación

- ID requerido, máximo 16 y solo letras mayúsculas o números.
- Descripción requerida, máximo 40 caracteres.
- Descripción larga opcional, máximo 255 caracteres.
- Tipo activo obligatorio, resuelto siempre por código estructural:
  `PRODUCTO`, `SERVICIO` o `KIT`. La aplicación no asume IDs numéricos.
- Unidad activa obligatoria.
- Moneda, línea, marca y clasificación activas opcionales.
- Impuestos activos opcionales, sin IDs duplicados.
- Códigos de barras opcionales, uno por línea, alfanuméricos, normalizados a
  mayúsculas y únicos globalmente.
- Peso opcional en kilogramos con precisión `DECIMAL(12,4)`.
- Largo, ancho y alto opcionales en centímetros con precisión
  `DECIMAL(12,3)`.
- Los atributos físicos deben ser mayores que cero. Se validan como cadenas
  decimales; no se convierten a `float`.
- Los controles de series, lotes y pedimentos aceptan exclusivamente `0/1`.
- Los datos de empresa o almacén enviados por navegador no se consumen.

## Reglas por tipo

- `PRODUCTO` puede registrar físicos y activar cualquier control.
- `SERVICIO` debe mantener físicos en `NULL` y controles en `0`. El servicio
  rechaza con validación 422 cualquier intento incompatible; no limpia datos
  silenciosamente.
- `KIT` puede registrar físicos y controles únicamente como política futura.
  Esta fase no crea componentes, explosión, armado ni desarmado de kits.

## Persistencia transaccional

La creación y edición coordinan producto, impuestos y códigos de barras dentro
de una sola transacción. Al editar, las asociaciones retiradas se desactivan
lógicamente y las seleccionadas se insertan o reactivan. No se elimina
físicamente el producto ni se actualiza su llave primaria.

## Catálogos y alcance

El CRUD usa las unidades, monedas, líneas, marcas, clasificaciones e impuestos
activos. Productos es un catálogo global estructural en esta fase. El shell
continúa mostrando empresa y almacén activos, pero el contexto no filtra ni
modifica productos.

## Interfaz

El listado ofrece búsqueda por ID o descripción y filtros por tipo, estado,
línea, marca y clasificación. Muestra el nombre del tipo sin exponer su ID
interno. El detalle presenta identidad, tipo, físicos, controles,
descripciones, catálogos, impuestos, códigos de barras y estado. Las acciones
se renderizan según permisos, sin sustituir la autorización del servidor.

El formulario separa identidad, tipo, clasificación comercial,
características físicas, controles, impuestos y códigos de barras. Las
unidades `kg` y `cm` se muestran junto a los campos. No se depende de
JavaScript para aplicar reglas de seguridad o negocio.

Los datos dinámicos se escapan con `e()`. Las tablas estrechas mantienen el
scroll horizontal dentro de su contenedor.

## Fuera de alcance

CRUD-PRODUCTOS-1 no contiene:

- inventario, existencias, movimientos o kardex;
- precios o listas de precios;
- compras, ventas o tickets;
- clientes o proveedores;
- componentes, explosión, armado o desarmado de kits;
- tablas o registros reales de lotes, series o pedimentos;
- lector, generación o impresión de códigos de barras;
- carga, descarga o eliminación de archivos;
- imágenes o endpoints para `producto_documentos`;
- dashboard, métricas o menú dinámico desde base de datos.

## Pruebas

```powershell
php database/crud-productos.php seed --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/crud-productos.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/crud-productos.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/productos.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/catalogos.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

El test funcional opera dentro de una transacción y revierte productos,
impuestos, códigos y catálogos transitorios.

## Rollback

El rollback del seed retira únicamente las relaciones de `ADMIN` y permisos
estructurales de esta fase que no tengan otras relaciones. El código puede
revertirse con Git. No se deben eliminar productos para revertir la interfaz.
