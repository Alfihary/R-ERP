# VCARD-PRODUCTOS-WHATSAPP-CTA-1

## Objetivo

Agregar un CTA público `Solicitar información` en cada tarjeta de producto
público de la vCard cuando WhatsApp esté publicado por privacidad.

## Comportamiento

El CTA aparece en:

- `/v/{slug}`, dentro del preview máximo de 4 productos.
- `/v/{slug}/productos`, dentro del listado completo.

El CTA no se muestra cuando:

- La vCard no está publicada.
- El usuario no está activo.
- La privacidad `whatsapp` está desactivada.
- La vCard no tiene número de WhatsApp público.
- La privacidad `productos` está desactivada.
- El producto no está vinculado a la vCard.
- El vínculo está inactivo/no visible.
- El producto está inactivo o eliminado.

## URL de WhatsApp

Formato:

```text
https://wa.me/{numero}?text={mensaje_codificado}
```

El número se normaliza eliminando todo excepto dígitos. Si el número no incluye
prefijo internacional, esta fase no lo infiere ni lo agrega porque todavía no
existe una configuración de país para vCard pública.

Mensaje prellenado:

```text
Hola, me interesa recibir información sobre el producto {descripcion}, código {id_producto}.
```

Si existe marca pública visible en la tarjeta, se agrega:

```text
Marca: {marca}.
```

## Datos excluidos

El mensaje y el HTML público no deben exponer:

- precio;
- stock o existencia;
- costo;
- proveedor;
- almacén;
- IDs internos de relación;
- rutas privadas;
- `storage/uploads`;
- tokens;
- hashes.

La unidad de medida permanece fuera de las tarjetas públicas; no se vuelve a
mostrar `Pieza`.

## Validación

Runner:

```bash
php database/vcard-productos-whatsapp-cta.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

El test cubre:

- CTA en preview y listado completo;
- privacidad de WhatsApp;
- ausencia de CTA sin número público;
- ocultamiento por privacidad de productos;
- productos/vínculos inactivos;
- escapado HTML;
- no exposición de datos sensibles;
- continuidad de imágenes públicas, placeholder, QR, VCF y foto pública;
- rollback de datos QA y limpieza de archivos temporales.

## Fuera de alcance

Esta fase no modifica:

- migraciones;
- seeds;
- compras;
- inventario;
- precios;
- productos funcionalmente;
- credenciales funcionalmente;
- perfil privado.

No hace staging, commit, push ni deploy.
