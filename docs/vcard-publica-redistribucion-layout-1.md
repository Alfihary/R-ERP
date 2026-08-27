# VCARD-PUBLICA-REDISTRIBUCION-LAYOUT-1

## Objetivo

Redistribuir visualmente `/v/{slug}` para acercarla al patrón aprobado por
producto: encabezado claro, datos de contacto a la izquierda, acciones
principales a la derecha, productos como bloque central y redes/VCF al cierre.

## Cambios de layout

- El encabezado de contenido muestra nombre, puesto y acento visual bajo el
  puesto.
- Los datos públicos de contacto se agrupan en un panel propio.
- Las acciones principales se agrupan aparte:
  - Llamar ahora.
  - Enviar correo.
  - Enviar WhatsApp.
- En desktop/tablet amplia no se muestra "Productos" como acción principal,
  para evitar duplicidad con la sección de productos.
- En móvil se muestra "Productos" como acción principal hacia
  `/v/{slug}/productos`.
- La sección de productos se renombra visualmente a "Productos".
- Si existen más productos que el límite del preview, la sección muestra el
  enlace visible "Ver todos los productos" hacia `/v/{slug}/productos`.
- En móvil la sección "Productos", el preview y "Ver todos los productos" se
  ocultan en `/v/{slug}` porque la navegación se concentra en el botón principal
  "Productos".
- Redes y enlaces se conservan en el cierre de la tarjeta.
- "Agregar a contactos" se conserva como CTA inferior hacia VCF.

## Contratos conservados

- No reaparece "Perfil público".
- No se modifica privacidad.
- No se modifican datos guardados.
- No se cambian controladores, rutas, servicios, repositorios, migraciones ni
  seeds.
- Productos públicos siguen sin exponer precio, stock, costo, proveedor,
  almacén, tokens, hashes ni rutas físicas.
- QR, VCF, foto pública e imágenes públicas siguen por rutas controladas.

## Validación

Runner específico:

```bash
php database/vcard-publica-redistribucion-layout.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

El test usa datos QA transaccionales, valida el nuevo layout, verifica rutas
públicas relacionadas y confirma rollback/cleanup.
