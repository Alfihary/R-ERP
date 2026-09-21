# PRODUCTOS-PRECIOS-INICIALES-OPCIONAL-UX-FIX-1

## Objetivo

Corregir la creación de productos para que los precios iniciales sean
realmente opcionales, como indica la interfaz.

## Causa

El formulario incluye un `lista_precio_id` oculto por cada lista activa. El
controlador interpretaba esa referencia estructural como una fila de precio
iniciada, aunque `precio_lista` y `precio_minimo` estuvieran vacíos, y respondía
con HTTP 422.

## Contrato corregido

- Si `precio_lista` y `precio_minimo` están vacíos o son `null`, la fila se
  omite aunque incluya `lista_precio_id`.
- No se convierte vacío a `0` ni se crea una fila en `producto_precios`.
- Si cualquiera de los importes tiene contenido, se conserva la validación
  completa de lista, precio de lista, precio mínimo, moneda y relación entre
  importes.
- Producto, relaciones, imagen y precios válidos continúan dentro de la misma
  transacción existente.

## Alcance técnico

- Normalización condicional en `ProductController`.
- Texto de ayuda inequívoco en el formulario de producto.
- Cobertura de filas de catálogo con importes vacíos y `null`, además de filas
  parcialmente capturadas.

No se modifican migraciones, seeds, inventario, tickets, correo ni dependencias.

## Prueba principal

```bash
php database/precios-producto-ui.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

El runner usa transacción y rollback para sus fixtures.

## Riesgo de rollback

Bajo. Revertir el cambio restaura únicamente la normalización anterior y el
texto de ayuda; no existe cambio de esquema ni persistencia requerida.
