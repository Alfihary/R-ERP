# EXISTENCIAS-1

## Alcance

EXISTENCIAS-1 agrega la primera pantalla de consulta de saldos materializados de inventario.

La pantalla usa `existencias_producto` como fuente de lectura y se limita al contexto autenticado de empresa/almacén.

## Principio operativo

EXISTENCIAS = saldo materializado de consulta.

MOVIMIENTOS = verdad histórica.

EXISTENCIAS-1 NO MODIFICA SALDOS.

LOS AJUSTES SE HACEN DESDE MOVIMIENTOS.

Si un usuario necesita corregir una existencia, debe crear un ajuste desde:

```text
/inventario/movimientos/crear
```

La pantalla de existencias no:

- crea existencias;
- edita existencias;
- elimina existencias;
- ajusta saldos directamente;
- recalcula inventario;
- inserta movimientos;
- inserta detalles;
- actualiza `existencias_producto`;
- borra `existencias_producto`.

## Tabla consultada

La consulta parte de:

```text
existencias_producto
```

Estructura confirmada:

- `id`
- `almacen_id`
- `id_producto`
- `cantidad_actual`
- `creado_en`
- `actualizado_en`

El grano es:

```text
almacen_id + id_producto
```

`existencias_producto` no tiene `empresa_id`.

La empresa se deriva por:

```text
existencias_producto.almacen_id -> almacenes.empresa_id
```

## Permiso

Permiso creado por seed idempotente:

```text
inventario.existencias.acceder
```

Asignado a `ADMIN`.

No existen permisos de crear, editar, eliminar o ajustar existencias.

## Ruta

Ruta privada:

```text
GET /inventario/existencias
```

Requiere:

- autenticación;
- `inventario.existencias.acceder`.

No se crea `POST /inventario/existencias`.

No se crea API pública.

## Alcance empresa/almacén

La consulta siempre se limita a la empresa activa.

Por defecto se consulta el almacén activo.

Si se envía filtro de almacén, el backend valida que el almacén pertenezca a la empresa activa.

Un almacén ajeno devuelve error controlado `422`.

## Filtros

Filtros implementados:

- búsqueda por `id_producto` o descripción;
- almacén;
- tipo de producto;
- estado de saldo:
  - positivo;
  - cero;
  - negativo.

El filtro de saldo cero aplica solo sobre filas existentes con `cantidad_actual = 0.000000`.

No se crean filas en cero para todos los productos.

## Listado

Columnas:

- almacén;
- producto;
- tipo;
- cantidad actual;
- actualizado;
- acciones.

La columna acciones muestra estado de solo lectura.

No hay botones de editar, eliminar, ajuste directo ni recalcular.

No se agregó acción “Ver movimientos” porque MOVIMIENTOS-INVENTARIO-1 aún no tiene filtro seguro por producto.

## Resumen

Se muestran tarjetas pequeñas calculadas server-side:

- productos con saldo positivo;
- productos en cero;
- productos con saldo negativo;
- total de filas de existencia.

Estas tarjetas no son dashboard ni KPIs avanzados.

No hay gráficas.

## Cantidades

Las cantidades se muestran como `DECIMAL` string con 6 decimales.

No se convierte a `float`.

JavaScript no formatea cantidades críticas.

## Pruebas

Runner:

```bash
php database/existencias.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

El DB-TEST usa `InventoryService::aplicarMovimiento(...)` para crear saldos positivos de QA.

Para probar filtros de saldo cero y negativo, el test crea filas directas transitorias en `existencias_producto`.

Esa inserción directa es solo QA de consulta: el servicio operativo actual bloquea saldo negativo y los ajustes reales siguen pasando por movimientos.

Todos los datos QA se limpian al final.

## Límites

EXISTENCIAS-1 no incluye:

- edición;
- eliminación;
- ajuste directo;
- recalcular;
- kardex;
- detalle histórico;
- transferencias;
- series;
- lotes;
- pedimentos;
- costos;
- precios;
- valuación;
- compras;
- ventas;
- CFDI;
- facturación;
- exportación;
- dashboard;
- KPIs;
- deploy;
- remoto Git.
