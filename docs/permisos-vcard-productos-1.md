# PERMISOS-VCARD-PRODUCTOS-1

## Objetivo

Formalizar el permiso que habilita la administración privada de productos
visibles en la vCard pública.

## Permiso creado

```text
vcard.productos.administrar
```

Descripción:

```text
Administrar productos visibles en vCard publica.
```

## Rol asignado

El seed asigna el permiso al rol estructural:

```text
ADMIN
```

## Seed

Archivo:

```text
database/seeds/permisos_vcard_productos_1_seed.php
```

El seed es idempotente:

- crea el permiso si no existe;
- si ya existe, lo reactiva y normaliza nombre/descripción;
- asigna el permiso a `ADMIN`;
- si la relación `ADMIN` + permiso ya existe, la reactiva;
- no duplica permisos;
- no duplica `rol_permisos`;
- no elimina permisos existentes;
- no elimina roles existentes;
- no toca usuarios;
- no toca productos;
- no toca inventario;
- no toca precios.

## Rutas protegidas

El permiso protege las rutas privadas de administración de productos de vCard:

```text
POST /perfil/vcard/productos/agregar
POST /perfil/vcard/productos/actualizar
POST /perfil/vcard/productos/quitar
```

Las rutas requieren:

- sesión autenticada;
- `perfil.ver`;
- `vcard.productos.administrar`;
- CSRF global.

## Verificación en `/perfil`

Con `vcard.productos.administrar`, el usuario ve:

```text
Productos en mi vCard
```

La sección permite buscar productos activos, vincularlos, marcar visibilidad,
marcar destacados, editar `texto_publico` y quitar vínculos.

Sin `vcard.productos.administrar`, el usuario no ve formularios de
administración y los POST responden 403.

## Impacto en `/v/{slug}`

El permiso no cambia la superficie pública. `/v/{slug}` sigue mostrando
productos únicamente cuando:

- la vCard está publicada;
- el usuario está activo;
- privacidad `productos=true`;
- el vínculo `vcard_productos` está activo y no eliminado;
- el producto está activo y no eliminado.

La vista pública no expone precios, stock, costos, proveedor, almacén, IDs
internos, tokens, hashes ni rutas `storage/uploads`.

## Validación

Runner:

```bash
php database/permisos-vcard-productos.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

El DB-TEST valida:

- existencia del seed;
- ejecución correcta;
- permiso activo;
- asignación a `ADMIN`;
- idempotencia;
- ausencia de duplicados;
- que no se eliminen permisos ni roles existentes;
- que el seed no modifique usuarios no-QA;
- visibilidad de la sección en `/perfil` con permiso;
- ocultamiento de formularios sin permiso;
- 403 sin permiso;
- 419 sin CSRF;
- flujo privado de administración ya implementado;
- publicación pública sin fugas de datos privados;
- rollback/cleanup.

## Migraciones

No se crearon ni modificaron migraciones.

## Fuera de alcance

No se tocó:

- compras;
- inventario;
- precios;
- productos funcionalmente;
- credenciales funcionalmente;
- rutas públicas nuevas;
- API pública nueva.

## Riesgos residuales

En ambientes reales donde el seed no haya sido ejecutado, los usuarios no verán
la sección privada aunque la implementación exista. La verificación operativa es
confirmar que `ADMIN` tenga `vcard.productos.administrar`.

## Siguiente paso sugerido

Cerrar esta fase con commit separado y después continuar solo con la siguiente
fase explícitamente autorizada.
