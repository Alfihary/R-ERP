# PRODUCTOS-4 — idempotencia DB de importación

Esta microfase añade únicamente la persistencia de control necesaria para que
una futura importación masiva pueda reconocer un `confirmation_token` ya
procesado. No implementa la importación definitiva ni modifica el flujo HTTP
actual.

## Modelo

La tabla `producto_importaciones` es la fuente de verdad para la ejecución
definitiva. `confirmation_token` es `CHAR(64) ascii_bin` y tiene una
restricción `UNIQUE`. `preview_id`, `usuario_id` y `source_sha256` sirven para
trazabilidad y validación de ownership; no se guarda el binding de sesión.

Los estados son `PROCESSING` y `SUCCEEDED`. `PROCESSING` existe solo dentro de
la transacción activa. Si la transacción revierte o la conexión cae antes del
commit, InnoDB elimina el guard. Solo `SUCCEEDED`, con resumen JSON y
`completado_en`, queda durable.

`resultado_resumen_json` es nullable durante `PROCESSING` y obligatorio por
la regla de consistencia cuando el estado es `SUCCEEDED`. No se persiste un
estado `FAILED`: un fallo revierte el lote y permite revalidar un nuevo intento.

## Transacción y replay

El futuro servicio de lote será dueño de la transacción:

1. insertar el guard `PROCESSING`;
2. crear todos los productos y relaciones;
3. registrar la auditoría de lote;
4. actualizar el guard a `SUCCEEDED` con el resumen seguro;
5. hacer `COMMIT`.

Un segundo request con el mismo token espera la restricción única. Después de
un commit devuelve el resumen durable con `status=already_processed` y no
vuelve a insertar ni auditar. Si el primer request revierte, el segundo puede
adquirir el guard y ejecutar el lote.

El JSON `used_at` del preview no es fuente de verdad. La base de datos lo es;
por eso un replay sigue siendo posible aunque el archivo temporal ya haya
sido limpiado. El replay requiere el mismo usuario autenticado, pero no la
misma sesión.

## Auditoría y límites

`auditoria_eventos` continuará siendo la estructura de trazabilidad. El
futuro evento usará `entidad=producto_importacion` y
`entidad_id=producto_importaciones.id`, dentro de la misma transacción del
lote. No se guardan archivos, passwords, cookies, sesiones, SQL ni paths
privados.

La migración es compatible con MySQL 8.0 y no requiere sintaxis específica de
MariaDB. AwardSpace no se valida ni se despliega en esta fase.

## Migración

Archivo:

`database/migrations/productos_importacion_idempotencia_1_001_create_producto_importaciones.php`

Su `down` elimina exclusivamente `producto_importaciones`. No toca productos,
usuarios, auditoría ni otras tablas.

## Fuera de alcance

- ProductImportBatchService;
- integración con `/productos/importar/confirmar`;
- cambios a ProductService o ProductRepository;
- auditoría de lote ejecutable;
- importación real;
- precios, imágenes, inventario, series, lotes o pedimentos;
- nuevos permisos;
- jobs, colas o importación parcial;
- despliegue en AwardSpace.

La siguiente fase debe integrar el repositorio con el servicio de lote y el
endpoint de confirmación después de una revisión independiente.
