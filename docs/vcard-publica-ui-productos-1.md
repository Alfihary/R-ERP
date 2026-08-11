# VCARD-PUBLICA-UI-PRODUCTOS-1

## Objetivo

Mejorar la presentación pública de `/v/{slug}` con una tarjeta corporativa responsive y una sección clara de productos públicos vinculados.

## Alcance

Esta fase modifica únicamente la superficie pública de vCard:

- `app/Views/vcards/public.php`
- `public/css/modules/vcard-public.css`
- `database/vcard-publica-ui-productos.php`
- `database/tests/vcard_publica_ui_productos_1_test.php`

No crea migraciones ni seeds. No modifica compras, inventario, precios ni productos funcionalmente.

## Diseño aplicado

La vCard pública usa una composición horizontal tipo tarjeta corporativa:

- panel izquierdo oscuro/corporativo con identidad Grupo Refrigerantes;
- foto pública o avatar según privacidad;
- texto institucional breve;
- QR público visible cuando está disponible;
- panel derecho con nombre, puesto, descripción, tarjetas de contacto, enlaces y acciones.

En móvil la tarjeta colapsa a una sola columna: panel de identidad arriba, datos abajo y productos en una columna.

## Productos públicos

La sección de productos se muestra solo cuando se cumplen las reglas existentes:

- vCard publicada;
- usuario activo;
- privacidad `productos = true`;
- vínculo `vcard_productos` activo;
- producto activo y no eliminado lógicamente.

Los productos se renderizan como tarjetas y mantienen destacados primero si el orden configurado lo refleja.

## Campos públicos permitidos en productos

- `id_producto`
- `descripcion`
- `marca`
- `linea`
- `clasificacion`
- `unidad`
- `texto_publico`
- `destacado`

## Campos excluidos

La vista pública no debe mostrar:

- precios;
- precio mínimo;
- lista de precio;
- costo;
- margen;
- stock;
- existencia;
- almacén;
- proveedor interno;
- movimientos;
- auditoría;
- IDs internos de relación;
- rutas privadas;
- campos fiscales;
- tokens;
- `token_hash`;
- `password_hash`;
- `storage/uploads`.

## Estado vacío

Si no hay productos públicos habilitados, la sección de productos no se renderiza. La decisión evita sugerir catálogo público cuando la privacidad o los vínculos no lo permiten.

## Compatibilidad

La fase mantiene:

- `/v/{slug}`;
- `/v/{slug}/foto`;
- `/v/{slug}/vcf`;
- `/v/{slug}/qr`;
- `/perfil/credencial`;
- `/perfil/credencial/qr`;
- verificación pública de credencial sin enlazarla desde la vCard pública.

## Validación

Runner específico:

```bash
php database/vcard-publica-ui-productos.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

La prueba usa datos QA transaccionales y confirma rollback de datos transitorios.
