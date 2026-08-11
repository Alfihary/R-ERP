# VCARD-PRODUCTOS-VINCULACION-FIX-1

## Objetivo

Corregir la falta de productos en la vCard pública cuando la privacidad de
productos está activa pero no existen vínculos en `vcard_productos`.

La fase agrega administración privada desde `/perfil` para que el usuario
autenticado seleccione productos activos y decida cuáles se muestran en
`/v/{slug}`.

## Causa confirmada

Para `jesus-g` se confirmó:

- la vCard carga;
- la vCard está publicada;
- la privacidad `productos` está activa;
- existen productos activos disponibles;
- `vcard_productos` no tenía filas vinculadas para esa vCard;
- no existían rutas privadas `/perfil/vcard/productos/...`;
- no existía UI privada en `/perfil` para administrar vínculos.

## Rutas privadas agregadas

Todas las rutas requieren sesión autenticada, `perfil.ver`,
`vcard.productos.administrar` y CSRF:

- `POST /perfil/vcard/productos/agregar`
- `POST /perfil/vcard/productos/actualizar`
- `POST /perfil/vcard/productos/quitar`

## Permiso

La funcionalidad usa el permiso:

```text
vcard.productos.administrar
```

Esta fase no modifica seeds por alcance explícito. El DB-TEST crea el permiso
solo dentro de transacción cuando lo necesita para validar la funcionalidad.

## Administración desde `/perfil`

La sección privada se llama:

```text
Productos en mi vCard
```

Permite:

- ver productos vinculados;
- buscar productos activos por `id_producto` o descripción;
- agregar producto activo;
- actualizar visible/inactivo;
- marcar destacado;
- capturar `texto_publico`;
- quitar vínculo.

Si el usuario no tiene `vcard.productos.administrar`, no se muestran formularios
de administración y los POST responden 403.

## Publicación en `/v/{slug}`

Los productos se muestran solo cuando:

- la vCard está publicada;
- el usuario está activo;
- la privacidad `productos` está visible;
- el vínculo `vcard_productos` está activo y no eliminado;
- el producto está activo y no eliminado.

Si no hay productos públicos vinculados, la sección pública se oculta. La vista
privada muestra el estado vacío:

```text
Aún no has agregado productos a tu vCard.
```

## Campos públicos permitidos

- `id_producto`
- descripción
- marca
- línea
- clasificación
- unidad
- texto público
- destacado

## Campos prohibidos

La vista pública no debe exponer:

- precio;
- precio mínimo;
- lista de precio;
- costo;
- margen;
- stock;
- existencia;
- almacén;
- proveedor;
- movimientos;
- auditoría;
- IDs internos de relación;
- rutas privadas;
- `password_hash`;
- `token_hash`;
- `storage/uploads`.

## Fuera de alcance

Esta fase no crea ni modifica:

- migraciones;
- seeds;
- productos funcionalmente;
- precios;
- inventario;
- compras;
- credenciales;
- rutas públicas de productos independientes;
- endpoint público `/v/{slug}/productos`.

## Validación

Runner específico:

```bash
php database/vcard-productos-vinculacion-fix.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

El test valida administración privada, permiso, CSRF, búsqueda, agregado
idempotente, visibilidad pública, privacidad, vínculo inactivo, producto
inactivo, remoción, escape HTML, ausencia de datos sensibles y rollback de datos
QA.
