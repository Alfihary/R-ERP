# VCARD-PUBLICACION-FIX-1

## Problema

Después del rediseño de la vCard pública, `/v/jesus-g` mostraba:

- `Información no disponible`
- `No es posible mostrar esta vCard pública.`

La ruta pública sí estaba registrada y el controlador público funcionaba. El
problema no era visual.

## Causa encontrada

El usuario `jesus.g` existía y estaba activo, y la vCard `jesus-g` existía,
pero:

- `vcards_usuario.publicada = 0`;
- `publicado_en = NULL`;
- la privacidad pública tenía todos los campos en `visible = 0`;
- no había productos vinculados visibles.

`PublicVcardController::show()` llama a
`VcardService::resolverPublicaPorSlug()`, que usa
`UserVcardRepository::publicDataForSlug()`. Esa consulta solo devuelve vCards
cuando:

- el slug existe;
- la vCard está publicada;
- `despublicado_en IS NULL`;
- el usuario está activo;
- el usuario no está eliminado.

Por lo tanto, devolver la pantalla no disponible era correcto mientras la vCard
no estuviera publicada.

## Corrección aplicada

Se agregó un flujo privado mínimo en `/perfil` para que un usuario con permisos
pueda:

- configurar slug, título, descripción y canal de contacto público;
- configurar privacidad pública granular;
- publicar la vCard;
- despublicar la vCard.

Las rutas privadas nuevas son:

- `POST /perfil/vcard/configuracion`
- `POST /perfil/vcard/privacidad`
- `POST /perfil/vcard/publicar`
- `POST /perfil/vcard/despublicar`

Todas pasan por sesión autenticada, permisos existentes y CSRF global.

## Permisos usados

- `vcard.ver`
- `vcard.editar`
- `vcard.publicar`
- `vcard.privacidad.editar`

No se crearon permisos nuevos.

## Seguridad

La ruta pública `/v/{slug}` no publica automáticamente ninguna vCard.

Si una vCard no está publicada, el slug no existe, el usuario está inactivo o
el usuario está eliminado, el resultado público sigue siendo no disponible.

La privacidad pública sigue siendo granular. Los productos solo se muestran si:

- la vCard está publicada;
- el usuario está activo;
- `productos` está visible en privacidad;
- existe vínculo visible;
- el producto está activo.

La vCard pública no debe exponer:

- `password_hash`;
- `token_hash`;
- rutas `storage/uploads`;
- precios;
- costos;
- stock;
- proveedor;
- almacén;
- rutas privadas.

## Fuera de alcance

Esta corrección no incluye:

- migraciones;
- seeds;
- compras;
- inventario;
- precios;
- cambios funcionales de productos;
- API pública nueva;
- rutas públicas nuevas de productos;
- staging;
- commit.

## Prueba

El runner específico es:

```bash
php database/vcard-publicacion-fix.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

La prueba usa transacción y rollback. No modifica de forma persistente datos
reales/no-QA.
