# VCARD-PUBLICA-CIERRE-QA-2

## Objetivo del cierre

Realizar cierre QA general del bloque vCard pública después del rediseño visual
y las mejoras de productos públicos. Esta fase no implementa funcionalidad
nueva: documenta el contrato final y valida que las superficies públicas y
privadas relacionadas sigan cumpliendo seguridad, privacidad y comportamiento
responsive.

## Commits incluidos en el bloque reciente

- d4d9e40 fix(vcard): add private product linking for public vcard
- 8412e0f feat(vcard): add dedicated vcard products permission
- 55757a7 test(vcard): verify jesus product permission access
- bea9371 feat(vcard): add public products listing page
- 26055ab feat(vcard): add secure public product images
- c2e6003 feat(vcard): add whatsapp product inquiry cta
- c8b9862 style(vcard): compact product whatsapp cta
- bf14397 style(vcard): remove public profile section
- 6f7ff0b style(vcard): redesign public vcard layout

## Contrato final desktop

En desktop / tablet amplia, `GET /v/{slug}` debe mostrar:

- Contactos visibles.
- Acciones principales: Llamar ahora, Enviar correo y Enviar WhatsApp.
- Sin botón principal "Productos" para evitar duplicidad visual.
- Sección "Productos" visible.
- Preview máximo de 4 productos.
- Enlace "Ver todos los productos" cuando hay más productos que el preview.
- CTA "Solicitar información" por producto cuando WhatsApp público está visible.
- Redes y enlaces.
- CTA "Agregar a contactos" hacia VCF.

## Contrato final móvil

En Móvil / celular, `GET /v/{slug}` debe mostrar:

- Contactos visibles.
- Acciones principales: Llamar ahora, Enviar correo, Enviar WhatsApp y acción
  principal “Productos”.
- La acción principal “Productos” enlaza a `/v/{slug}/productos`.
- Redes y enlaces.
- CTA "Agregar a contactos".

En móvil se oculta la sección “Productos” del home público, incluyendo título,
preview de tarjetas y enlace "Ver todos los productos", porque el acceso al
listado completo queda concentrado en el botón principal “Productos”.

## Contrato de productos públicos

- El preview en `/v/{slug}` muestra máximo 4 productos.
- El quinto producto no aparece en `/v/{slug}`.
- El quinto producto sí aparece en `/v/{slug}/productos`.
- `GET /v/{slug}/productos` muestra el listado completo si la vCard está
  publicada, el usuario está activo, la privacidad de productos está habilitada
  y existen vínculos activos hacia productos activos.
- Si privacidad `productos=false`, el listado completo responde 404 seguro.
- Producto inactivo, vínculo inactivo/no visible y producto no vinculado no se
  muestran.
- No aparece “Pieza”.
- No exponen precio, precio mínimo, lista de precio, costo, margen, stock,
  existencia, almacén, proveedor, movimientos, auditoría ni IDs internos de
  relación.

## Contrato de imágenes públicas de producto

- Producto con imagen válida usa ruta pública controlada:
  `/v/{slug}/productos/{id_producto}/imagen`.
- El HTML no contiene `storage/uploads`.
- El HTML no contiene rutas físicas.
- La imagen responde 200 solo para producto público válido con imagen válida.
- Responde 404 seguro si la vCard no existe, no está publicada, privacidad
  productos es falsa, el producto no está vinculado, el vínculo está inactivo,
  el producto está inactivo, la imagen no existe, hay path traversal o el MIME
  no es permitido.
- No se sirven SVG, GIF ni PHP.

## Contrato de CTA WhatsApp

- “Solicitar información” aparece por producto si WhatsApp público está visible.
- El href usa `https://wa.me/`.
- El mensaje incluye descripción e `id_producto`.
- El mensaje está URL encoded.
- El href no contiene precio, stock, costo, proveedor, almacén, IDs internos,
  `storage/uploads`, tokens ni hashes.
- Si privacidad `whatsapp=false`, no aparece CTA.
- Si no hay número WhatsApp público, no aparece CTA.
- No usa teléfono fijo ni móvil como reemplazo si WhatsApp está oculto.

## Contrato de QR / VCF / foto pública

- `GET /v/{slug}/qr` responde PNG y apunta a `/v/{slug}`.
- El QR no contiene productos, tokens, IDs internos ni datos sensibles.
- `GET /v/{slug}/vcf` responde vCard descargable.
- El VCF respeta privacidad.
- VCF no incluye productos.
- `GET /v/{slug}/foto` respeta privacidad de foto.
- La foto pública no expone ruta física ni `storage/uploads`.

## Guardrail heredado actualizado

El cierre QA de credencial ya no prohíbe `GET /v/{slug}/productos`. Esa regla
era válida antes del listado público de productos, pero el contrato actual acepta
las rutas:

- `GET /v/{slug}/productos`
- `GET /v/{slug}/productos/{id_producto}/imagen`

El guardrail vigente las valida como rutas públicas controladas: no son API,
dependen de publicación, privacidad, vínculos activos, producto activo e imagen
válida, responden 404 seguro cuando no corresponde mostrar y no exponen datos
sensibles.

## Seguridad y campos prohibidos

Ninguna superficie pública debe exponer:

- `password_hash`
- `token`
- `token_hash`
- `credencial_tokens`
- `roles`
- `permisos`
- `usuario_roles`
- `rol_permisos`
- `storage/uploads`
- rutas físicas
- precio
- stock
- costo
- proveedor
- almacén
- movimientos
- auditoría interna
- IDs internos de relación

## Rutas públicas finales

- GET /v/{slug}
- GET /v/{slug}/foto
- GET /v/{slug}/vcf
- GET /v/{slug}/qr
- GET /v/{slug}/productos
- GET /v/{slug}/productos/{id_producto}/imagen
- GET /credencial/verificar/{token}

## Rutas privadas relacionadas

- GET /perfil
- POST /perfil/vcard/configuracion
- POST /perfil/vcard/privacidad
- POST /perfil/vcard/publicar
- POST /perfil/vcard/despublicar
- POST /perfil/vcard/productos/agregar
- POST /perfil/vcard/productos/actualizar
- POST /perfil/vcard/productos/quitar
- GET /perfil/credencial
- GET /perfil/credencial/qr
- GET /perfil/credencial/qr/descargar

## Riesgos residuales

- El diseño visual final depende de QA manual adicional en navegadores reales y
  dispositivos físicos.
- La publicación pública depende de que el usuario mantenga privacidad y vínculos
  correctamente configurados.
- Los enlaces sociales externos dependen de URLs válidas capturadas en perfil.
- El CTA WhatsApp usa el número público configurado; no infiere ni reemplaza con
  teléfono fijo o móvil si WhatsApp está oculto.

## Pruebas ejecutadas

Runner específico:

```bash
php database/vcard-publica-cierre-qa-2.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

Regresiones obligatorias:

```bash
php database/vcard-publica-redistribucion-layout.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/vcard-publica-quitar-perfil-publico.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/vcard-productos-whatsapp-cta.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/vcard-productos-imagen-publica-ui.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/vcard-publica-ui-productos.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/vcard-publico.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/vcard-productos.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/vcard-qr.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/vcard-vcf.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/vcard-foto-publica.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/permisos-vcard-productos.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/jesus-vcard-productos-permission.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/credencial-visual.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/credencial-token-qr.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/credencial-cierre-qa.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

## Pendientes sugeridos

- Cerrar esta fase con commit documental/QA cuando el usuario lo autorice.
- Hacer QA visual manual adicional en desktop y móvil si se requiere evidencia
  visual humana.
- Definir la siguiente fase funcional solo después del cierre de este QA.
