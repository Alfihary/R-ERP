# PRODUCTOS-2B-1 — Paginación y contrato de miniatura

## Estado de la microfase

`PRODUCTOS_2B_1_STATUS=PASS`
`PRODUCTOS_2B_2_STATUS=PASS`
`PRODUCTOS_2B_2_CODEX_VISUAL_REVIEW=PASS`
`VISUAL_RUNTIME_AVAILABLE=true`

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

## PRODUCTOS-2B-2 implementado

El listado ahora incluye una columna Imagen con thumbnail de 48 × 48 píxeles
en escritorio y 40 × 40 píxeles en pantallas pequeñas. El `src` usa solamente
`/productos/imagen`, `id_producto` y `primary_image_version` como token `v`.
La vista no expone rutas relativas ni rutas de storage.

También se incorporó placeholder interno, `loading="lazy"`,
`decoding="async"` y fallback externo en
`public/js/modules/products-list.js` para ocultar una imagen cuyo archivo ya
no esté disponible y revelar el mismo placeholder.

No se generan thumbnails físicos, no se usa GD ni Imagick y no se modificó el
endpoint ni su política de caché HTTP; el cache busting por versión es
suficiente para esta fase.

La validación visual real confirmó PASS en 1440 × 900, 390 × 844 y
360 × 800: alineación, placeholder, overflow horizontal interno, acciones y
paginación. `FALLBACK_IMAGEN_ROTA=NOT_TESTED`; la cobertura automatizada del
fallback permanece PASS. El riesgo de ancho de banda por escalar imágenes
originales queda documentado como optimización futura.
AwardSpace y la validación de hosting siguen pendientes; esta fase es local.

El DB-TEST `database/tests/productos_listado_2b_test.php` se ejecutó en la
base QA autorizada. Crea y elimina únicamente IDs `QA2B*`, verifica
paginación, filtros, estados de documentos, versión determinista, ausencia de
N+1 y dejó `QA_PRODUCT_RESIDUALS=0`, `QA_DOCUMENT_RESIDUALS=0`.

La revisión visual automatizada asistida se cerró con PASS; no se altera el
alcance funcional de la microfase. No se realizó deploy ni envío SMTP.
