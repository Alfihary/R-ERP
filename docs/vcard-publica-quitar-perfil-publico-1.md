# VCARD-PUBLICA-QUITAR-PERFIL-PUBLICO-1

## Objetivo

Quitar visualmente la sección "Perfil público" de la vCard pública sin cambiar
datos guardados, privacidad, permisos ni representación interna.

## Decisión aplicada

La vista pública `/v/{slug}` ya no renderiza:

- el título "Perfil público";
- el bloque descriptivo de empresa/ubicación asociado a esa sección;
- el mensaje "No hay datos adicionales publicados.".

La sección "Redes y enlaces" permanece en su posición y comportamiento actual.

## Conservado sin cambios funcionales

- Botones principales de contacto.
- Productos relacionados.
- Enlace "Ver todos los productos".
- CTA "Solicitar información" hacia WhatsApp.
- Imágenes públicas por ruta controlada.
- QR público.
- VCF público.
- Endpoint de foto pública.
- Privacidad pública existente.

## Fuera de alcance

- No se modifican controladores.
- No se modifican rutas.
- No se modifican servicios.
- No se modifican repositorios.
- No se modifican migraciones.
- No se modifican seeds.
- No se modifican datos persistentes.
- No se toca compras, inventario, precios ni productos funcionalmente.

## Validación

La fase incluye el runner:

```bash
php database/vcard-publica-quitar-perfil-publico.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

El test valida que la sección removida no aparece, que redes/enlaces se
conservan, que productos/CTA/imágenes/QR/VCF siguen funcionando y que no se
exponen datos sensibles como rutas de storage, precios, stock, costos,
proveedores, tokens o hashes.
