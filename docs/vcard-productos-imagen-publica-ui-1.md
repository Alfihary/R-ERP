# VCARD-PRODUCTOS-IMAGEN-PUBLICA-UI-1

## Objetivo

Pulir la visualización pública de productos en la vCard para mostrar imagen real
cuando exista una imagen principal válida, mantener placeholder seguro cuando no
exista, y quitar la unidad de medida visible en tarjetas públicas.

## Ruta pública controlada

La fase agrega:

```text
GET /v/{slug}/productos/{id_producto}/imagen
```

La ruta no requiere sesión, pero solo responde imagen cuando se cumple todo el
contrato público:

- vCard existente y publicada.
- usuario activo.
- privacidad `productos=true`.
- producto vinculado a esa vCard.
- vínculo activo/no eliminado.
- producto activo/no eliminado.
- imagen principal activa.
- archivo real dentro de `storage/uploads/productos`.
- MIME real permitido: JPEG, PNG o WebP.

Si cualquier condición falla, responde `404` seguro sin revelar si el producto,
archivo o vínculo existen.

## Seguridad

El HTML público no imprime rutas físicas ni `storage/uploads`. Las tarjetas usan
la ruta controlada y no redirigen a archivos privados.

La ruta rechaza:

- SVG.
- GIF.
- PHP.
- archivos vacíos.
- rutas con `..`.
- rutas absolutas.
- backslashes.
- dobles extensiones peligrosas PHP.
- MIME que no coincide con el archivo.

## Presentación pública

Las tarjetas de `/v/{slug}` y `/v/{slug}/productos` muestran:

- Imagen real mediante endpoint controlado cuando existe.
- Placeholder con inicial cuando no existe imagen.
- Código, marca, línea y clasificación cuando aplican.

Por decisión visual de esta fase, la unidad de medida no se renderiza en las
tarjetas públicas, aunque sigue existiendo internamente.

## Fuera de alcance

- No modifica migraciones.
- No modifica seeds.
- No cambia productos funcionalmente.
- No toca compras, inventario ni precios.
- No convierte imágenes en assets públicos.
- No crea API pública.
- No hace staging ni commit.

## Validación

Runner específico:

```bash
php database/vcard-productos-imagen-publica-ui.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```
