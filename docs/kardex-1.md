# KARDEX-1

## Alcance

KARDEX-1 crea la primera pantalla privada de consulta de kardex de inventario:

```text
GET /inventario/kardex
```

La pantalla permite consultar el historial de movimientos aplicados de un producto por almacén, dentro de la empresa activa del usuario autenticado.

## Principio operativo

```text
MOVIMIENTOS = VERDAD HISTÓRICA
EXISTENCIAS = SALDO MATERIALIZADO
KARDEX = LECTURA HISTÓRICA DERIVADA DE MOVIMIENTOS APLICADOS
```

KARDEX-1 NO MODIFICA SALDOS.

KARDEX-1 NO RECALCULA NI GUARDA EXISTENCIAS.

## Fuente de datos

El kardex se reconstruye desde:

- `movimientos_inventario`
- `movimientos_inventario_detalle`
- `conceptos_movimiento_inventario`
- `productos`
- `almacenes`
- `empresas`

Solo incluye movimientos:

```text
movimientos_inventario.estado = 'APLICADO'
```

## Empresa y almacén

El kardex siempre se limita a la empresa activa.

Por defecto usa el almacén activo. Si el usuario filtra otro almacén, el backend valida que pertenezca a la empresa activa. Un almacén ajeno produce error controlado.

Nunca se muestran movimientos de otra empresa ni almacenes ajenos.

## Producto

La consulta requiere seleccionar producto. No se carga kardex de todos los productos por defecto.

Tipos permitidos:

- `PRODUCTO`
- `KIT`

`SERVICIO` no participa en inventario; si se manipula el filtro para consultarlo, se rechaza con error controlado.

## Filtros

Filtros incluidos:

- producto;
- almacén;
- fecha desde;
- fecha hasta;
- concepto;
- naturaleza.

El buscador privado de productos responde únicamente productos activos de tipo `PRODUCTO` o `KIT`, con límite de 20 resultados y sin datos sensibles.

## Entrada, salida y saldo resultante

La cantidad del detalle es positiva. La vista separa:

- `Entrada`
- `Salida`
- `Saldo resultante`

Regla:

- `ENTRADA`: suma cantidad.
- `SALIDA`: resta cantidad.

El saldo resultante se calcula en SQL para la consulta usando DECIMAL y función de ventana acumulada. No se calcula en JavaScript y no se guarda en base de datos.

## Paginación

La paginación es server-side.

El saldo resultante no se calcula parcialmente desde la página visible. Se calcula con ventana SQL sobre el conjunto filtrado completo antes de aplicar `LIMIT/OFFSET`, por lo que la página 2 conserva saldos que consideran la página 1.

## Saldo actual materializado

La pantalla puede mostrar el saldo actual desde `existencias_producto` para el producto y almacén filtrados.

Ese dato es solo lectura:

- no se edita;
- no se recalcula;
- no se actualiza desde Kardex.

## Permiso

Permiso creado:

```text
inventario.kardex.acceder
```

Asignado a `ADMIN` mediante seed idempotente.

No se crean permisos de crear, editar, eliminar, anular ni recalcular kardex.

## Navegación

Se agrega navegación:

```text
Inventario · Kardex
```

Se mantienen:

- Inventario · Movimientos
- Inventario · Existencias

## Solo lectura

KARDEX-1 no crea:

- POST de kardex;
- edición;
- eliminación;
- anulación;
- reversa;
- ajuste directo;
- recalcular existencias;
- transferencia;
- series;
- lotes;
- pedimentos;
- costos;
- costo promedio;
- último costo;
- valuación;
- compras;
- ventas;
- CFDI;
- facturación;
- dashboard;
- KPIs;
- exportación.

Si un saldo está mal, se corrige mediante un movimiento de ajuste desde:

```text
/inventario/movimientos/crear
```

No desde Kardex.

## Pruebas

Runner:

```text
php database/kardex.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

El DB-TEST crea datos QA mediante `InventoryService`, valida saldos acumulados, paginación con saldo correcto, filtros, scope empresa/almacén, solo lectura, permisos y limpieza transitoria.
