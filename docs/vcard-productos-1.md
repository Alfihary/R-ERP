# VCARD-PRODUCTOS-1 — Productos públicos en vCard

## Objetivo

Mostrar productos asociados a una vCard pública solo cuando la privacidad granular `productos` está habilitada.

La fase no crea rutas públicas nuevas de productos y no modifica productos, precios ni inventario core.

## Servicio creado

`App\Domain\Vcards\VcardProductService`

Responsabilidades:

- listar productos vinculados para administración por servicio;
- sincronizar vínculos contra `vcard_productos`;
- listar productos públicos visibles por slug.

## Repositorio creado

`App\Infrastructure\Repositories\VcardProductRepository`

Responsabilidades:

- leer usuarios/vCards activas;
- leer vínculos `vcard_productos`;
- sincronizar vínculos con prepared statements;
- consultar productos públicos de una vCard publicada.

## Campos públicos permitidos

- `id_producto`, como código público natural del producto.
- `descripcion`.
- `texto_publico` del vínculo vCard-producto.
- `destacado`.
- `unidad`.
- `marca`.
- `linea`.
- `clasificacion`.

## Campos prohibidos

- precio.
- precio mínimo.
- costo.
- margen.
- stock.
- existencia.
- cantidad disponible.
- almacén.
- proveedor interno.
- auditoría.
- IDs internos autoincrementales.
- rutas internas.

## Reglas de privacidad

Los productos públicos se muestran solo si:

- la vCard está publicada;
- el usuario está activo y no eliminado;
- `vcard_privacidad.productos = true`;
- el vínculo está activo y no eliminado;
- el producto está activo y no eliminado.

Con `productos=false`, no se renderiza sección ni datos de productos.

## Render público

La vista `/v/{slug}` recibe productos ya filtrados y escapados con `e()`.

No se usa JavaScript, carrito, cotizador, precios, stock, costos ni datos de inventario.

## Fuera de alcance

- No crea rutas públicas de productos.
- No crea JSON público.
- No crea CRUD visual para asociar productos.
- No muestra precios.
- No muestra existencias.
- No muestra costos.
- No modifica inventario.
- No modifica precios.
- No edita productos core.
- No implementa credencial.

## Validación esperada

```bash
php -l app/Domain/Vcards/VcardProductService.php
php -l app/Infrastructure/Repositories/VcardProductRepository.php
php -l app/Domain/Vcards/VcardService.php
php -l app/Http/Controllers/PublicVcardController.php
php -l app/Views/vcards/public.php
php -l bootstrap/app.php
php -l database/vcard-productos.php
php -l database/tests/vcard_productos_1_test.php
git diff --check
php database/vcard-productos.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

## Siguiente fase sugerida

Credencial visual/verificable, solo cuando sea autorizada.
