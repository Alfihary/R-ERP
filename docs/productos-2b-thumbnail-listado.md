# PRODUCTOS-2B-1 — Paginación y contrato de miniatura

## Estado de la microfase

`PRODUCTOS_2B_1_STATUS=PASS`

Esta microfase prepara el listado de productos para una futura columna de
miniatura sin renderizar imágenes todavía. El contrato devuelve metadatos
deterministas y una paginación segura; la vista solo muestra controles de
paginación.

## Contrato de listado

`GET /productos` acepta los filtros existentes (`search`, `status`,
`type_code`, `line_id`, `brand_id`, `classification_id`) y los parámetros:

- `page`: entero mínimo 1; valor por defecto 1.
- `per_page`: entero entre 1 y 50; valor por defecto 20.

La respuesta interna entregada a la vista tiene esta forma:

```text
items, total, page, per_page, total_pages
```

Cada elemento conserva los campos del listado y agrega:

- `has_primary_image`: booleano.
- `primary_image_version`: `actualizado_en`, o `creado_en` cuando no existe
  actualización, de la fila activa más reciente por `id`.

La detección usa exactamente `tipo_documento = FOTO_PRINCIPAL`,
`es_principal = 1`, `activo = 1` y `eliminado_en IS NULL`. Se usan
subconsultas correlacionadas `EXISTS` y escalares; no se hace un `JOIN` que
duplique productos ni se devuelve `ruta_relativa`.

El conteo reutiliza exactamente las mismas condiciones de búsqueda que la
consulta de elementos. El orden estable es `p.descripcion, p.id_producto`.

## Alcance y pendientes

Incluido: paginación segura, preservación de filtros, metadatos de imagen,
controles de paginación y DB-TEST QA reversible.

Fuera de esta microfase: HTML de miniatura, placeholder, CSS de miniatura,
fallback JavaScript, caché HTTP de imágenes, generación de thumbnails,
cambios de esquema, migraciones, seeds, SMTP, deploy y cambios en
`ProductImageService`.

Pendiente de PRODUCTOS-2B-2: columna visual de thumbnail, placeholder,
fallback para imagen rota, cache busting del `src`, posible caché HTTP,
generación/almacenamiento físico de thumbnails y validación visual en
1440, 390 y 360 píxeles.

El DB-TEST `database/tests/productos_listado_2b_test.php` se ejecutó en la
base QA autorizada. Crea y elimina únicamente IDs `QA2B*`, verifica
paginación, filtros, estados de documentos, versión determinista, ausencia de
N+1 y dejó `QA_PRODUCT_RESIDUALS=0`, `QA_DOCUMENT_RESIDUALS=0`.

La revisión visual humana de la futura miniatura y la implementación de
thumbnails siguen fuera de esta microfase. No se realizó staging ni commit.
