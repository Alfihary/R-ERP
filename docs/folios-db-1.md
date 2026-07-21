# FOLIOS-DB-1

## Alcance

FOLIOS-DB-1 crea la estructura base de datos para folios operativos por
almacén.

Esta fase crea únicamente:

- migración de base de datos;
- tabla `series_documentales`;
- tabla `documentos_folios`;
- runner CLI `database/folios.php`;
- DB-TEST `database/tests/folios_1_test.php`;
- esta documentación.

FOLIOS-DB-1 NO EMITE FOLIOS.

FOLIOS-DB-1 NO MODIFICA MOVIMIENTOS NI TRANSFERENCIAS.

No crea UI, rutas web, controladores, permisos RBAC, servicios de emisión de
folios, integración con inventario, integración con transferencias, compras,
ventas, CFDI, dashboard ni KPIs.

## Regla confirmada del producto

LOS FOLIOS OPERATIVOS SERÁN POR ALMACÉN.

El formato base del folio será:

```text
{PREFIJO}-{ALMACEN}{NUMERO}
```

Ejemplos:

- `F-BO000001`
- `R-BO000001`
- `TR-BO000001`
- `AJ-BO000001`
- `EN-BO000001`
- `SA-BO000001`

Donde:

- `PREFIJO` representa el tipo de documento o actividad.
- `ALMACEN` representa el código documental del almacén.
- `NUMERO` representa el consecutivo formateado con ceros a la izquierda.

Cada almacén tiene sus propios consecutivos. Cada tipo de documento tiene su
propio consecutivo por almacén.

## Serie documental

Una serie documental es la configuración del consecutivo para una combinación
operativa.

La serie documental única se define por:

```text
empresa_id + almacen_id + tipo_documento + codigo_serie
```

Reglas:

- `empresa_id` es obligatorio.
- `almacen_id` es obligatorio.
- `tipo_documento` es obligatorio.
- `codigo_serie` es obligatorio.
- No existe `almacen_scope_id`.
- No se permiten series documentales operativas sin almacén.
- No se permite `almacen_id NULL`.

## Tabla series_documentales

Campos:

- `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
- `empresa_id BIGINT UNSIGNED NOT NULL`
- `almacen_id BIGINT UNSIGNED NOT NULL`
- `tipo_documento VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL`
- `codigo_serie VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL`
- `prefijo VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL`
- `codigo_almacen_snapshot VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL`
- `formato VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '{PREFIJO}-{ALMACEN}{NUMERO}'`
- `separador VARCHAR(5) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '-'`
- `siguiente_numero BIGINT UNSIGNED NOT NULL DEFAULT 1`
- `longitud TINYINT UNSIGNED NOT NULL DEFAULT 6`
- `reinicio_anual TINYINT(1) NOT NULL DEFAULT 0`
- `anio_actual SMALLINT UNSIGNED NULL`
- `activo TINYINT(1) NOT NULL DEFAULT 1`
- `creado_por BIGINT UNSIGNED NULL`
- `actualizado_por BIGINT UNSIGNED NULL`
- `eliminado_en DATETIME NULL`
- `eliminado_por BIGINT UNSIGNED NULL`
- `creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
- `actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`

En esta tabla, `prefijo` equivale a `prefijo_documento`.

### FKs

- `empresa_id` referencia `empresas(id)`.
- `almacen_id` referencia `almacenes(id)`.
- `(empresa_id, almacen_id)` referencia `almacenes(empresa_id, id)`.
- `creado_por` referencia `usuarios(id)`.
- `actualizado_por` referencia `usuarios(id)`.
- `eliminado_por` referencia `usuarios(id)`.

La FK compuesta `(empresa_id, almacen_id)` evita configurar una serie para una
empresa y un almacén que no pertenecen entre sí.

### Índices

- `UNIQUE (empresa_id, almacen_id, tipo_documento, codigo_serie)`
- `INDEX (empresa_id)`
- `INDEX (almacen_id)`
- `INDEX (tipo_documento)`
- `INDEX (codigo_serie)`
- `INDEX (prefijo)`
- `INDEX (codigo_almacen_snapshot)`
- `INDEX (activo)`
- `INDEX (eliminado_en)`

### Constraints

- `tipo_documento` no vacío.
- `codigo_serie` no vacío.
- `prefijo` no vacío.
- `codigo_almacen_snapshot` no vacío.
- `formato` no vacío.
- `siguiente_numero >= 1`.
- `longitud BETWEEN 1 AND 12`.
- `reinicio_anual IN (0,1)`.
- `activo IN (0,1)`.

## Folio emitido

Un folio emitido es el registro materializado de un número tomado desde una
serie documental.

El folio final se guarda completo:

```text
F-BO000001
```

No debe reconstruirse únicamente desde `almacenes.codigo`, porque el código vivo
del almacén puede cambiar en el futuro. Por eso se guarda
`codigo_almacen_snapshot` tanto en `series_documentales` como en
`documentos_folios`.

## Tabla documentos_folios

Campos:

- `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
- `serie_documental_id BIGINT UNSIGNED NOT NULL`
- `empresa_id BIGINT UNSIGNED NOT NULL`
- `almacen_id BIGINT UNSIGNED NOT NULL`
- `tipo_documento VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL`
- `codigo_serie VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL`
- `prefijo_documento VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL`
- `codigo_almacen_snapshot VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL`
- `formato VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL`
- `folio VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL`
- `numero BIGINT UNSIGNED NOT NULL`
- `anio SMALLINT UNSIGNED NULL`
- `anio_scope SMALLINT UNSIGNED GENERATED ALWAYS AS (COALESCE(anio, 0)) STORED`
- `documento_tipo_origen VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL`
- `documento_id_origen BIGINT UNSIGNED NULL`
- `referencia_externa VARCHAR(100) NULL`
- `creado_por_usuario_id BIGINT UNSIGNED NULL`
- `creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`

`documentos_folios` funciona como auditoría mínima de folios emitidos. Todavía
no sustituye `referencia` en movimientos ni crea relación funcional con
transferencias.

### FKs

- `serie_documental_id` referencia `series_documentales(id)`.
- `empresa_id` referencia `empresas(id)`.
- `almacen_id` referencia `almacenes(id)`.
- `(empresa_id, almacen_id)` referencia `almacenes(empresa_id, id)`.
- `creado_por_usuario_id` referencia `usuarios(id)`.

### Índices

- `UNIQUE (serie_documental_id, anio_scope, numero)`
- `UNIQUE (empresa_id, almacen_id, tipo_documento, folio)`
- `INDEX (empresa_id)`
- `INDEX (almacen_id)`
- `INDEX (tipo_documento)`
- `INDEX (codigo_serie)`
- `INDEX (prefijo_documento)`
- `INDEX (codigo_almacen_snapshot)`
- `INDEX (folio)`
- `INDEX (documento_tipo_origen, documento_id_origen)`
- `INDEX (creado_en)`

### Constraints

- `folio` no vacío.
- `numero >= 1`.
- `tipo_documento` no vacío.
- `codigo_serie` no vacío.
- `prefijo_documento` no vacío.
- `codigo_almacen_snapshot` no vacío.
- `formato` no vacío.

## anio_scope

`anio_scope` es una columna generada que normaliza `anio NULL` a `0`.

Motivo:

- MySQL permite múltiples `NULL` dentro de índices únicos.
- Para impedir duplicados cuando `anio` es `NULL`, el índice único usa
  `anio_scope`.
- `anio = NULL` y `numero = 1` quedan bajo `anio_scope = 0`.
- `anio = 2026` permite otro consecutivo separado dentro de la misma serie.

## Tipos de documento iniciales de prueba

FOLIOS-DB-1 no crea catálogo rígido de tipos de documento. El DB-TEST usa estos
ejemplos mínimos:

| tipo_documento | prefijo_documento | ejemplo |
| --- | --- | --- |
| `FACTURA_VENTA` | `F` | `F-BO000001` |
| `REMISION_VENTA` | `R` | `R-BO000001` |
| `TRANSFERENCIA_INVENTARIO` | `TR` | `TR-BO000001` |
| `AJUSTE_INVENTARIO` | `AJ` | `AJ-BO000001` |
| `ENTRADA_INVENTARIO` | `EN` | `EN-BO000001` |
| `SALIDA_INVENTARIO` | `SA` | `SA-BO000001` |

## Reglas de unicidad

- Una serie documental no se duplica dentro de la misma empresa, almacén, tipo
  de documento y código de serie.
- La misma serie puede existir en empresas distintas.
- La misma serie puede existir en almacenes distintos.
- El mismo número puede existir en distinto tipo de documento.
- El mismo número puede existir en distinto almacén.
- El mismo folio textual puede existir en otra empresa.
- El mismo folio textual puede existir en otro almacén de la misma empresa.
- El mismo folio textual no puede duplicarse dentro de la misma empresa,
  almacén y tipo de documento.

## Validación empresa-almacén

La base de datos valida que:

- la empresa existe;
- el almacén existe;
- el almacén pertenece a la empresa mediante FK compuesta
  `(empresa_id, almacen_id)`.

FOLIOS-SERVICE-1 deberá validar además alcance operativo del usuario,
estado activo y reglas de negocio antes de emitir un folio.

## Estrategia futura de concurrencia

FOLIOS-DB-1 solo crea estructura. La emisión transaccional queda para
FOLIOS-SERVICE-1.

La fase futura deberá:

- bloquear la fila de `series_documentales` con `SELECT ... FOR UPDATE`;
- calcular el folio desde `prefijo`, `codigo_almacen_snapshot`, `longitud` y
  `siguiente_numero`;
- insertar en `documentos_folios`;
- incrementar `siguiente_numero` solo si el folio quedó registrado;
- manejar rollback si falla la operación;
- no permitir saltos por carreras de concurrencia no controladas.

## Relación futura con módulos operativos

Movimientos:

- FOLIOS-DB-1 no agrega `folio_id` a `movimientos_inventario`.
- `movimientos_inventario.referencia` sigue existiendo.
- Una fase futura deberá decidir cómo asociar folios a movimientos sin romper
  históricos.

Transferencias:

- FOLIOS-DB-1 no agrega `folio_id` a transferencias.
- Una fase futura deberá emitir folios como `TR-BO000001` por almacén origen o
  por la regla operativa aprobada.

Compras, ventas y CFDI:

- No se crean en esta fase.
- Deberán consumir `documentos_folios` cuando se autoricen sus fases.

## DB-TEST

El DB-TEST valida:

- tablas creadas;
- columnas requeridas;
- ausencia de `almacen_scope_id`;
- FKs;
- índices;
- columna generada `anio_scope`;
- unicidad de series documentales;
- unicidad de folios emitidos;
- folios `F-BO000001`, `R-BO000001`, `F-MTY000001`, `TR-BO000001`,
  `AJ-BO000001`, `EN-BO000001`, `SA-BO000001`;
- constraints de campos no vacíos y rangos numéricos;
- FKs inválidas rechazadas;
- `anio NULL` normalizado a `0`;
- `anio 2026` como scope separado;
- rollback real de tablas;
- idempotencia de migración;
- limpieza QA.

## Límites

No crear en FOLIOS-DB-1:

- UI;
- rutas web;
- controladores;
- permisos RBAC;
- sidebar;
- menús;
- vistas;
- servicios de folios;
- emisión de folios;
- integración con movimientos;
- integración con transferencias;
- `folio_id` en movimientos;
- `folio_id` en transferencias;
- compras;
- ventas;
- CFDI;
- dashboard;
- KPIs;
- deploy;
- remoto Git.

No modificar en FOLIOS-DB-1:

- `InventoryService`;
- `InventoryTransferService`;
- `InventoryRepository`;
- `InventoryQueryRepository`;
- `CompanyService`;
- `WarehouseService`;
- `movimientos_inventario`;
- `movimientos_inventario_detalle`;
- `conceptos_movimiento_inventario`;
- configuración operativa;
- series;
- lotes;
- productos;
- vistas de inventario;
- vistas de configuración;
- CSS/JS.

## Siguientes fases

- FOLIOS-SERVICE-1
- FOLIOS-INVENTARIO-1
- FOLIOS-UI-1
