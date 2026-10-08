# DB-PRODUCTOS-1 — Esquema base de productos

## Objetivo

Crear la persistencia estructural mínima del catálogo global de productos,
códigos de barras, impuestos y metadatos documentales.

La fase no incluye CRUD, rutas web, servicio de productos, inventario,
existencias, movimientos, kardex, precios, compras, ventas ni carga de
archivos.

## Contrato de identidad

`id_producto` es la única identidad del producto:

```sql
id_producto VARCHAR(16)
    CHARACTER SET ascii
    COLLATE ascii_bin
    NOT NULL,
PRIMARY KEY (id_producto),
CONSTRAINT chk_productos_id_producto CHECK (
    REGEXP_LIKE(
        id_producto,
        '^[A-Z0-9]{1,16}$',
        'c'
    )
)
```

No existe `id`, `producto_id`, UUID, slug como llave ni identidad alternativa.
La regla admite exclusivamente letras ASCII mayúsculas y números.

## Decisión `VARCHAR(16)`

El probe controlado sobre MySQL 8.0.38 demostró que `CHAR(16)` acepta
`ABC123 ` y elimina silenciosamente el espacio final antes de evaluar el
contrato. Por ese motivo `CHAR(16)` fue descartado.

El probe posterior con `VARCHAR(16) ascii_bin` aprobó todos los casos:

- `ABC123` fue aceptado y almacenado sin cambios.
- Minúsculas, espacios iniciales/finales/internos, acentos, guiones,
  caracteres especiales, vacío y longitud mayor a 16 fueron rechazados.
- El espacio final fue rechazado por el `CHECK`; no hubo normalización.

El DB-TEST-PRODUCTOS repite los mismos casos sobre la tabla real.

## Tablas

### `productos`

- `id_producto VARCHAR(16) ascii_bin`: PK natural.
- `descripcion VARCHAR(40)`: descripción corta obligatoria.
- `descripcion_larga VARCHAR(255)`: detalle opcional con límite explícito.
- `unidad_medida_id`: obligatorio.
- `moneda_id`, `linea_producto_id`, `marca_id` y
  `clasificacion_producto_id`: opcionales.
- `activo`, borrado lógico, fechas y actores de auditoría.

`VARCHAR(255)` se eligió para `descripcion_larga` porque esta fase requiere un
límite operativo explícito y no ha demostrado necesidad de texto técnico mayor.

### `producto_codigos_barras`

Permite varios códigos por producto. `codigo_barras` es único global y usa
ASCII sensible a mayúsculas. `tipo` y `es_principal` quedan preparados como
metadata; no existe integración con escáner.

### `producto_impuestos`

Relación N:M con PK compuesta `(id_producto, impuesto_id)`. La PK evita
duplicados sin crear una identidad sustituta. No calcula impuestos.

### `producto_documentos`

Guarda únicamente metadata futura: tipo, nombre original, ruta relativa, MIME,
tamaño, indicador principal, estado y auditoría. No almacena archivos, no
expone endpoints y no sirve descargas.

## Relaciones y reglas de eliminación

| Origen | Destino | `ON DELETE` | Motivo |
|---|---|---|---|
| `productos.unidad_medida_id` | `unidades_medida.id` | `RESTRICT` | La unidad es obligatoria |
| Catálogos opcionales de producto | Catálogo correspondiente | `SET NULL` | El producto puede conservarse sin esa clasificación |
| Hijas `id_producto` | `productos.id_producto` | `RESTRICT` | Impide pérdida destructiva de datos hijos |
| `producto_impuestos.impuesto_id` | `impuestos.id` | `RESTRICT` | Conserva integridad tributaria |
| Campos de actor | `usuarios.id` | `SET NULL` | Conserva el registro si el usuario deja de existir |

Todas usan `ON UPDATE RESTRICT`. No hay cascadas destructivas.

Las tres tablas hijas declaran exactamente:

```sql
id_producto VARCHAR(16)
    CHARACTER SET ascii
    COLLATE ascii_bin
    NOT NULL
```

y referencian directamente `productos(id_producto)`.

## Migración y DB-TEST

```powershell
php database/productos.php migrate --database=<db-test> --confirm-database=<db-test>
php database/productos.php db:test --database=<db-test> --confirm-database=<db-test>
```

El runner:

- Solo funciona por CLI.
- Rechaza producción.
- Exige el nombre de base confirmado dos veces.
- Exige que coincida con `APP_DB_NAME`.

El DB-TEST valida metadata, PK natural, ausencia de identidad alternativa,
índices, FKs, todos los identificadores inválidos, referencias de catálogos,
duplicados en hijos, estados y rollback transaccional.

Conteos persistentes esperados:

```text
productos=0
producto_codigos_barras=0
producto_impuestos=0
producto_documentos=0
```

## Rollback

El rollback elimina primero documentos, impuestos y códigos de barras; después
elimina `productos`. En una base con datos reales requerirá respaldo y
confirmación operativa. El DB-TEST no deja datos transitorios.

## Fuera de alcance

- CRUD, rutas, controladores, vistas o servicio de productos.
- Inventario, existencias, movimientos y kardex.
- Precios y listas de precios.
- Compras, ventas, tickets, clientes o proveedores.
- Carga, almacenamiento o descarga de documentos.
- Dashboard, métricas o menú dinámico.
- Integración MySQL de `/health`.
- Deploy y remoto Git.
