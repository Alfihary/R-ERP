# DB-CATALOGOS-1 — Catálogos generales base

## Objetivo

Crear catálogos globales mínimos para fases posteriores de productos,
inventario, compras y ventas. Esta fase no crea productos, existencias,
operaciones, permisos, rutas ni interfaces CRUD.

Los catálogos son globales por diseño y no incluyen `empresa_id` ni
`almacen_id`. Los módulos futuros deberán aplicar su propio alcance operativo.

## Tablas y relaciones

### `monedas`

- PK: `id`.
- Código ISO estructural único: `codigo`.
- Datos: `nombre`, `simbolo`, `decimales`.
- `es_base` identifica la moneda base.
- La columna generada `base_unica` y su índice único permiten una sola moneda
  base efectiva no eliminada.
- Estado, borrado lógico y auditoría estándar.

### `tipos_cambio`

- PK: `id`.
- FKs `moneda_origen_id` y `moneda_destino_id` hacia `monedas`.
- `fecha` y `valor DECIMAL(20,8)`.
- Unicidad por `(moneda_origen_id, moneda_destino_id, fecha)`.
- Origen y destino deben ser distintos.
- El valor debe ser mayor que cero.
- No incluye servicio de consulta o conversión.

### `unidades_medida`

- PK: `id`.
- Código global único, nombre y abreviatura.
- Estado, borrado lógico y auditoría estándar.

### `impuestos`

- PK: `id`.
- Código global único, nombre, tasa y tipo.
- Tipos admitidos: `IVA`, `IEPS` y `EXENTO`.
- La tasa se limita al intervalo `0..100`.
- Un impuesto `EXENTO` debe tener tasa cero.
- La tabla no calcula impuestos.

### `lineas_producto` y `marcas`

- PK: `id`.
- Código global único y nombre.
- Estado, borrado lógico y auditoría estándar.
- El seed no crea registros en estas tablas.

### `clasificaciones_producto`

- PK: `id`.
- Código global único y nombre.
- `parent_id` nullable referencia la misma tabla con `RESTRICT`.
- La FK rechaza padres inexistentes.
- Estado, borrado lógico y auditoría estándar.

MySQL no permite que un `CHECK` compare una FK con la columna auto-incremental
de la misma fila. La detección de autorreferencias y ciclos de varios niveles
queda pendiente para el servicio transaccional del futuro CRUD. DB-CATALOGOS-1
no declara que la jerarquía sea segura sin esa validación de escritura.

## Índices principales

| Tabla | Índice | Finalidad |
|---|---|---|
| `monedas` | `uq_monedas_codigo` | Código único |
| `monedas` | `uq_monedas_base_unica` | Una moneda base efectiva |
| `tipos_cambio` | `uq_tipos_cambio_par_fecha` | Un valor por par y fecha |
| `tipos_cambio` | `idx_tipos_cambio_destino_fecha` | Consulta por destino y fecha |
| `unidades_medida` | `uq_unidades_medida_codigo` | Código único |
| `impuestos` | `uq_impuestos_codigo` | Código único |
| `lineas_producto` | `uq_lineas_producto_codigo` | Código único |
| `marcas` | `uq_marcas_codigo` | Código único |
| `clasificaciones_producto` | `uq_clasificaciones_producto_codigo` | Código único |
| `clasificaciones_producto` | `idx_clasificaciones_producto_parent` | Navegación padre-hijo |

Cada tabla contiene FKs de auditoría hacia `usuarios`. Todas usan `InnoDB`,
`utf8mb4` y `utf8mb4_unicode_ci`; los códigos estructurales usan `ascii_bin`.

## Seed estructural

`db_catalogos_1_seed_base_catalogs` es idempotente y utiliza al administrador
inicial como actor de auditoría.

Valores persistentes:

- Monedas: `MXN`, `USD`, `EUR`.
- Moneda base: `MXN`.
- Unidades: `PIEZA`, `KG`, `LITRO`, `METRO`, `SERVICIO`.
- Impuestos: `IVA_16`, `IVA_0`, `EXENTO`.

El seed no crea tipos de cambio, líneas, marcas, clasificaciones, productos,
inventario ni datos demostrativos.

## Ejecución controlada

```powershell
php database/catalogos.php migrate --database=<db-test> --confirm-database=<db-test>
php database/catalogos.php seed --database=<db-test> --confirm-database=<db-test>
php database/catalogos.php db:test --database=<db-test> --confirm-database=<db-test>
php database/catalogos.php status --database=<db-test> --confirm-database=<db-test>
```

El runner exige confirmación doble, coincidencia con `APP_DB_NAME` y un entorno
distinto de producción.

## Verificación SQL

```sql
SELECT codigo, nombre, simbolo, decimales, es_base
FROM monedas
ORDER BY codigo;

SELECT codigo, nombre, abreviatura
FROM unidades_medida
ORDER BY codigo;

SELECT codigo, nombre, tasa, tipo
FROM impuestos
ORDER BY codigo;
```

Inserción válida de jerarquía:

```sql
INSERT INTO clasificaciones_producto (codigo, nombre, activo)
VALUES ('REFRIGERACION', 'Refrigeración', 1);

INSERT INTO clasificaciones_producto (parent_id, codigo, nombre, activo)
VALUES (LAST_INSERT_ID(), 'COMPRESORES', 'Compresores', 1);
```

Inserciones inválidas que deben fallar:

```sql
INSERT INTO impuestos (codigo, nombre, tasa, tipo, activo)
VALUES ('INVALIDO', 'Tasa inválida', -1, 'IVA', 1);

INSERT INTO tipos_cambio (
    moneda_origen_id,
    moneda_destino_id,
    fecha,
    valor,
    activo
)
VALUES (1, 2, CURRENT_DATE, 0, 1);
```

Los errores esperados son clave duplicada, FK inválida o `CHECK` incumplido.
Un error distinto requiere diagnóstico y no debe interpretarse como prueba
aprobada.

## DB-TEST-CATALOGOS

El DB-TEST valida:

1. Migraciones prerrequisito y destino confirmado.
2. Siete tablas, InnoDB y collation.
3. PKs, índices únicos y FKs estructurales.
4. Veintiuna FKs de auditoría.
5. Seeds y moneda base única.
6. Idempotencia del seed mediante ejecución repetida.
7. Inserciones válidas en los siete catálogos.
8. Duplicados de códigos y tipo de cambio.
9. Tipo de cambio cero, negativo, huérfano o con el mismo origen/destino.
10. Tasa de impuesto negativa.
11. Segunda moneda base.
12. Jerarquía padre-hijo válida y padre inexistente rechazado.
13. Rollback de todos los registros transitorios.

## Conteos persistentes esperados

```text
monedas=3
tipos_cambio=0
unidades_medida=5
impuestos=3
lineas_producto=0
marcas=0
clasificaciones_producto=0
```

No existen tablas de productos o inventario en esta fase.

## Rollback

Orden:

1. `clasificaciones_producto`
2. `marcas`
3. `lineas_producto`
4. `impuestos`
5. `unidades_medida`
6. `tipos_cambio`
7. `monedas`

No hay cascadas destructivas. El rollback elimina estructura y datos de
catálogo, por lo que requiere respaldo y revisión de dependencias fuera de una
base desechable.

## Pendiente

- CRUD y permisos de catálogos.
- Validación transaccional de ciclos de clasificación.
- Servicio de tipos de cambio.
- Servicio de cálculo de impuestos.
- Uso en productos, inventario, compras y ventas.
- Catálogos fiscales adicionales.
