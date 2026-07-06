# DB-PRODUCTOS-2

## Objetivo

Completar el modelo maestro estructural de productos con tipos, características
físicas y políticas futuras de control, sin crear inventario ni operaciones.

## Tabla `tipos_producto`

`tipos_producto` es un catálogo estructural con código único en mayúsculas,
estado lógico y auditoría consistente con el resto del ERP.

El seed idempotente crea exclusivamente:

- `PRODUCTO`: mercancía o producto físico.
- `SERVICIO`: prestación sin características ni control físico.
- `KIT`: agrupación futura cuyo modelo de componentes aún no existe.

No se usa `ENUM`. Los códigos usan charset `ascii`, collation `ascii_bin` y un
CHECK de formato estructural.

## Relación con productos

`productos.tipo_producto_id` es obligatorio y referencia
`tipos_producto(id)`.

La FK usa `ON UPDATE RESTRICT` y `ON DELETE RESTRICT`. Un tipo no puede
eliminarse físicamente mientras esté referenciado porque cambiarlo o retirarlo
alteraría el significado maestro del producto.

Para preservar filas existentes, la migración crea primero el tipo estructural
`PRODUCTO` y lo usa como default de compatibilidad al agregar la columna. El
seed posterior completa y normaliza `PRODUCTO`, `SERVICIO` y `KIT`. El CRUD
debe integrar la selección explícita del tipo en una fase posterior.

## Características físicas

Campos opcionales:

| Campo | Unidad fija | Precisión |
| --- | --- | --- |
| `peso_kg` | kilogramos | `DECIMAL(12,4)` |
| `largo_cm` | centímetros | `DECIMAL(12,3)` |
| `ancho_cm` | centímetros | `DECIMAL(12,3)` |
| `alto_cm` | centímetros | `DECIMAL(12,3)` |

`NULL` significa no capturado o no aplicable. Los CHECK permiten únicamente
`NULL` o valores mayores que cero. No se permite cero ni valores negativos.

No se calcula volumen, peso volumétrico ni conversión de unidades. Estos campos
no reutilizan `unidades_medida` porque su unidad está fijada por contrato.

## Políticas futuras de control

- `controla_series`
- `controla_lotes`
- `controla_pedimentos`

Son flags `TINYINT(1)` con default `0` y CHECK de valores `0/1`. Solo expresan
una política futura; no representan series, lotes o pedimentos reales.

No existen tablas ni registros operativos para estos controles.

## Reglas por tipo

### PRODUCTO

Puede registrar características físicas y activar cualquiera de las tres
políticas.

### SERVICIO

Debe conservar peso y dimensiones en `NULL`, y los controles de series, lotes
y pedimentos en `0`. Tampoco deberá generar existencias futuras.

Esta regla depende del código de una fila relacionada. MySQL no permite que un
CHECK consulte `tipos_producto`, y duplicar el código del tipo dentro de
`productos` produciría una fuente de verdad frágil. Por ello queda como regla
obligatoria del futuro servicio de productos. No se crean triggers.

### KIT

Puede registrar características físicas y podrá definir políticas de control
cuando se apruebe su modelo operativo. En esta fase no existen componentes,
explosión, armado ni desarmado de kits.

## Restricciones aplicadas en DB

- Tipo de producto obligatorio.
- FK de tipo con RESTRICT.
- Peso y dimensiones: `NULL` o mayores que cero.
- Flags de control: solo `0` o `1`.
- Código de tipo único y estructural.
- Estado de tipo: solo `0` o `1`.
- Identidad `id_producto` de DB-PRODUCTOS-1 preservada.
- FKs hijas continúan apuntando directamente a `productos(id_producto)`.

## Migración y seed

```powershell
php database/productos-2.php migrate --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/productos-2.php seed --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/productos-2.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

La migración es `db_productos_2_001_extend_product_master`. El seed es
`db_productos_2_seed_product_types`.

## Rollback

El rollback elimina primero la FK, el índice y las nuevas columnas de
`productos`, y después `tipos_producto`.

El riesgo es medio-alto una vez que los nuevos campos contienen información,
porque el rollback descarta esos valores. Antes de usarlo fuera de la base
desechable se requiere respaldo y validación explícita.

## Fuera de alcance

DB-PRODUCTOS-2 no incluye:

- formularios, vistas, controlador, servicio o repositorio del CRUD;
- inventario, existencias, movimientos o kardex;
- tablas o datos reales de series, lotes o pedimentos;
- componentes, explosión, armado o desarmado de kits;
- precios o listas de precios;
- compras, ventas, tickets, clientes o proveedores;
- archivos, imágenes o endpoints de documentos;
- dashboard, métricas o menú dinámico.
