# PRODUCTOS-IMAGEN-1

## Objetivo

Implementar una imagen principal opcional por producto sin crear una gestión
documental general. La imagen se registra en `producto_documentos`, se almacena
fuera de `public/` y se entrega mediante un endpoint autenticado.

## Arquitectura

- Servicio: `ProductImageService`.
- Repositorio: `ProductDocumentRepository`.
- Tabla reutilizada: `producto_documentos`.
- Tipo estructural: `FOTO_PRINCIPAL`.
- Indicador principal: `es_principal = 1`.
- Estado vigente: `activo = 1` y `eliminado_en IS NULL`.

No se agregan columnas a `productos` y no se crea una tabla nueva de imágenes.

## Uso de `producto_documentos`

La imagen principal usa los campos existentes:

- `id_producto`
- `tipo_documento`
- `nombre_original`
- `ruta_relativa`
- `mime_type`
- `tamano_bytes`
- `es_principal`
- `activo`
- campos de auditoría

La fase mantiene trazabilidad lógica: al reemplazar o eliminar, los registros
anteriores se desactivan. El archivo físico anterior se elimina después del
commit.

## Corrección estructural mínima

La tabla ya existía, pero el `CHECK` original de `tipo_documento` solo permitía:

```text
^[A-Z0-9]{1,32}$
```

Ese patrón impedía guardar el contrato funcional autorizado:

```text
FOTO_PRINCIPAL
```

La migración `productos_imagen_1_001_allow_primary_photo_type` modifica
exclusivamente `chk_producto_documentos_tipo` para permitir guion bajo:

```text
^[A-Z0-9_]{1,32}$
```

El constraint conserva sensibilidad a mayúsculas y sigue rechazando minúsculas,
espacios, guion medio, acentos, caracteres especiales distintos de `_`, cadena
vacía y más de 32 caracteres.

Rollback real: antes de restaurar el patrón anterior deben migrarse o eliminarse
los registros cuyo `tipo_documento` contenga `_`. La migración no ejecuta
limpieza destructiva automática.

## Una imagen por producto

La base de datos no tiene una restricción única específica para una sola
`FOTO_PRINCIPAL` activa por producto. En esta fase se protege en servicio:

1. se bloquea el producto con `FOR UPDATE`;
2. se bloquea la imagen principal activa;
3. se inserta la nueva imagen;
4. se desactivan las demás imágenes principales activas del producto;
5. se confirma la transacción;
6. se elimina el archivo anterior.

No se crean triggers.

## Formatos y tamaño

Permitidos:

- `image/jpeg`
- `image/png`
- `image/webp`

Tamaño máximo:

- `5 * 1024 * 1024` bytes.

Se rechazan archivos vacíos, mayores al límite, cargas incompletas y errores de
PHP upload.

## Validación real

La validación no confía en el nombre original, extensión ni `Content-Type` del
navegador.

Se usa:

- `finfo` para MIME real;
- `getimagesize()` para confirmar que el contenido es decodificable como imagen.

GD e Imagick no son obligatorios.

## Storage privado

Raíz:

```text
storage/uploads/productos/
```

Ruta relativa en base de datos:

```text
ABC123/foto-principal-{token}.jpg
```

No se guardan rutas absolutas, rutas públicas ni URLs completas.

## Endpoint

```text
GET /productos/imagen?id_producto=ABC123
```

Requiere autenticación y permiso `productos.ver`.

El endpoint:

- busca metadata activa `FOTO_PRINCIPAL`;
- construye ruta desde una raíz fija;
- valida `realpath`;
- confirma que el archivo está dentro de `storage/uploads/productos/`;
- responde `Content-Type`, `Content-Length` y `X-Content-Type-Options: nosniff`;
- no expone ruta física.

Si el producto no tiene imagen, responde 404. La UI usa placeholder HTML/CSS.

## Reemplazo

El reemplazo valida completamente el archivo nuevo antes de registrar metadata.
Si la transacción falla, se elimina el archivo nuevo. Si el commit es correcto y
la eliminación física anterior falla, la imagen nueva queda vigente y la
inconsistencia no rompe la operación.

## Eliminación

La eliminación no modifica el producto. Solo desactiva el documento principal y,
después del commit, elimina el archivo físico si existe. Si el archivo físico ya
no existe, la metadata se limpia de forma controlada.

## Cache

La primera versión usa:

```text
Cache-Control: no-cache, private
```

La UI agrega un parámetro `v` derivado de metadata para evitar mostrar una imagen
anterior después del reemplazo.

## Permisos

- Ver imagen: `productos.ver`.
- Subir, reemplazar y eliminar imagen: `productos.editar`.

No se crean permisos nuevos.

## Límites

Esta fase no incluye:

- BLOB en MySQL;
- base64 embebido;
- galería;
- imágenes secundarias;
- thumbnails;
- resize;
- crop;
- compresión;
- conversión de formato;
- gestión documental general;
- fichas técnicas;
- manuales;
- certificados;
- inventario;
- precios;
- CFDI;
- facturación;
- GD obligatorio;
- Imagick obligatorio.
