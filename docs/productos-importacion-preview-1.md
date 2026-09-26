# PRODUCTOS-IMPORTACION-PREVIEW-1

## Alcance

Esta fase implementa exclusivamente el preview de importación de productos. El flujo recibe un archivo CSV o XLSX, lo conserva temporalmente en almacenamiento privado, ejecuta el lector seguro y el validador de negocio, y presenta un resultado estructurado. No crea ni actualiza productos, precios, existencias, imágenes, tickets o correos.

`HOSTING_PREREQUISITE_PENDING=true` continúa vigente.

## Rutas y controles de acceso

| Método | Ruta | Propósito |
| --- | --- | --- |
| `GET` | `/productos/importar` | Formulario y consulta de un preview propio vigente. |
| `POST` | `/productos/importar/validar` | Carga, lectura, validación y creación del preview. |
| `POST` | `/productos/importar/descartar` | Eliminación del preview y de su archivo privado. |

Las tres rutas requieren sesión autenticada y, conjuntamente, los permisos existentes `productos.acceder` y `productos.crear`. Un invitado es redirigido al login y un usuario sin permisos recibe `403`. Ambos `POST` pasan por la validación CSRF global; un token ausente o inválido produce `419`.

No existe la ruta `POST /productos/importar/confirmar` en esta fase.

## Pipeline

1. Validar autenticación, permisos y CSRF.
2. Validar la carga HTTP, la extensión CSV/XLSX y el límite de 5 MiB.
3. Mover el archivo a `storage/private/product_import_previews/uploads/` con un nombre físico aleatorio.
4. Calcular SHA-256 sobre la copia privada.
5. Ejecutar `ProductImportReader`, incluida su protección técnica para CSV/XLSX.
6. Ejecutar `ProductImportBusinessValidator` con consultas de catálogo y existencia de solo lectura.
7. Separar errores globales de errores asociados a filas.
8. Escribir atómicamente el artefacto JSON privado.
9. Redirigir al `GET` con una referencia de preview segura.

Un error del lector o de almacenamiento elimina la carga parcial cuando corresponde y se presenta como mensaje seguro, sin stack trace, SQL, DSN o rutas internas.

## Identidad, integridad y expiración

- El `preview_id` se genera con 32 bytes criptográficamente aleatorios y se representa como 64 caracteres hexadecimales.
- El nombre original solo se conserva como metadato sanitizado; nunca forma el path físico.
- El SHA-256 permite detectar modificaciones posteriores del archivo temporal.
- El artefacto se liga al `user_id` autenticado y a un hash SHA-256 del identificador de sesión. No se guarda ni expone el identificador de sesión directo.
- Un preview ajeno responde como recurso no encontrado para no revelar su existencia.
- El TTL es de 1,800 segundos (30 minutos). Un preview vencido queda inválido y es elegible para cleanup.
- `used_at` queda reservado con valor `null` para una futura fase de confirmación de uso único; esta fase no lo consume.

## Artefacto privado

La metadata se guarda en `storage/private/product_import_previews/metadata/` como JSON, nunca mediante `serialize()` o `unserialize()`. Contiene únicamente:

- versión de esquema;
- identificador, propietario y binding de sesión;
- nombre original sanitizado y referencia privada server-side;
- SHA-256, formato y tiempos de creación/expiración;
- `used_at` reservado;
- totales y resultado normalizado del validador.

La escritura usa archivo temporal y renombrado atómico, permisos privados y un límite de tamaño. Al leer se valida la estructura, el tamaño, los identificadores, el path autorizado y el digest. La respuesta pública nunca incluye el path privado, el binding de sesión ni referencias internas innecesarias.

## Cleanup y descarte

`ProductImportPreviewService::cleanupExpired()` elimina metadata vencida o corrupta y el upload asociado. Las rutas de eliminación se derivan del identificador validado en el servidor y permanecen dentro del root privado; el cliente nunca envía un path.

El descarte verifica ownership, elimina metadata y upload y es idempotente cuando el preview ya no existe. Después redirige al formulario con el mensaje `Preview descartado.`

## Interfaz

La pantalla reutiliza el layout, tokens, alertas, botones y tablas del ERP. Informa los formatos, los límites de 5 MiB y 1,000 filas y el contrato `CREATE-ONLY`. El resumen muestra total, válidas e inválidas. El estado es `LISTO PARA IMPORTAR` solo cuando no hay filas inválidas; de lo contrario es `CON ERRORES`.

Se muestran como máximo 20 filas con las columnas fila, ID de producto, descripción, tipo, unidad y estado. Los errores globales aparecen separados de los errores por fila, y las filas inválidas incluyen texto e iconografía además de color. No hay botón ni acción de importación definitiva. El CSS local adapta formulario, resumen, tabla, mensajes y descarte a 1440, 768 y 390 píxeles sin introducir estilos globales.

## Pruebas

El runner seguro es:

```text
php database/productos-importacion-preview.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

El runner rechaza producción y exige que `--database`, `--confirm-database` y `APP_DB_NAME` coincidan. La suite valida creación, errores, aleatoriedad, digest, ownership, binding, expiración, JSON, ausencia de serialización PHP, cleanup, descarte, traversal, archivo faltante, metadata corrupta, límite de 20 filas, separación de errores, totales, rutas HTTP, permisos, CSRF y ausencia de escrituras de negocio.

También compara antes y después `productos`, `producto_precios`, `existencias_producto`, `movimientos_inventario`, `tickets_productos` y `tickets_productos_correos`, incluido el producto protegido `102016169`, sus dos precios y su inventario.

## Fuera de alcance

- confirmación o importación definitiva;
- `INSERT`, `UPDATE`, `UPSERT` o eliminación de datos de negocio;
- migraciones, seeds o nueva tabla de previews;
- cambios a `ProductService`, inventario, tickets o correo;
- cambios a Composer;
- cron de cleanup;
- verificación final del hosting AwardSpace.
