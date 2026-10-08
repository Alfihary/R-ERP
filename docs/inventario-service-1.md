# INVENTARIO-SERVICE-1

## Objetivo

Implementar la aplicación transaccional de movimientos reales de inventario.

El servicio coordina explícitamente:

```text
MOVIMIENTOS = VERDAD HISTÓRICA
EXISTENCIAS = SALDO MATERIALIZADO
```

El resultado de una operación válida es:

```text
movimiento histórico
+ detalle histórico
+ existencia actual
```

Todos coherentes o ninguno persistido.

## Arquitectura

Archivos principales:

- `app/Domain/Inventory/InventoryService.php`
- `app/Infrastructure/Repositories/InventoryRepository.php`

El servicio contiene validación de dominio y coordina la transacción.

El repositorio contiene SQL explícito con PDO y prepared statements.

No hay controlador, rutas web, API ni UI en esta fase.

## Contrato de aplicación

Operación pública:

```php
InventoryService::aplicarMovimiento(array $input): array
```

Datos requeridos:

- `empresa_id`
- `almacen_id`
- `concepto_codigo`
- `fecha_movimiento`
- `referencia` opcional
- `observaciones` opcionales
- `usuario_id`
- `partidas[]`

Cada partida contiene:

- `id_producto`
- `cantidad`
- `observaciones` opcionales

Resultado controlado:

- `movimiento_id`
- `estado`
- `empresa_id`
- `almacen_id`
- `concepto_codigo`
- `naturaleza`
- `fecha_movimiento`
- `partidas_aplicadas`

No devuelve PDO, SQL ni datos internos innecesarios.

## Atomicidad

Secuencia:

```text
BEGIN
validar datos dependientes de DB
crear movimiento BORRADOR
insertar partidas
bloquear existencias en orden determinista
actualizar saldos
marcar movimiento APLICADO
COMMIT
```

Ante cualquier error:

```text
ROLLBACK
```

No puede persistir movimiento sin todas sus partidas, partida sin movimiento aplicado, existencia modificada con movimiento fallido ni movimiento aplicado sin saldos actualizados.

## BORRADOR → APLICADO

El servicio crea primero el encabezado con:

```text
estado=BORRADOR
```

Después de actualizar existencias:

```text
estado=APLICADO
```

No crea directamente movimientos aplicados antes de tocar existencias.

No deja borradores residuales ante errores.

## Locks y concurrencia

Antes de bloquear existencias, las partidas se ordenan por:

```text
id_producto
```

Esto reduce el riesgo de deadlocks cuando dos transacciones afectan productos en distinto orden.

Cada existencia se bloquea con:

```sql
SELECT ... FOR UPDATE
```

La actualización usa aritmética DECIMAL en MySQL:

```sql
cantidad_actual = cantidad_actual + :cantidad
cantidad_actual = cantidad_actual - :cantidad
```

No se usa `SELECT saldo → PHP suma → UPDATE` sin lock.

## Precisión DECIMAL

Las cantidades se validan y normalizan como strings a escala 6.

Ejemplos:

```text
1        → 1.000000
1.5      → 1.500000
0.000001 → 0.000001
```

No se usa `float`.

No se usa `double`.

No se requiere BCMath.

La aritmética crítica se ejecuta sobre columnas `DECIMAL(18,6)` en MySQL.

## Política de no negativos

INVENTARIO-SERVICE-1 no permite que una salida deje saldo negativo.

Regla:

```text
saldo_actual - cantidad < 0
→ rechazar
→ rollback completo
```

La base de datos sigue sin:

```text
CHECK cantidad_actual >= 0
```

La política vive temporalmente en el servicio porque una fase futura podrá configurar inventario negativo por empresa o almacén.

## Productos

El servicio:

- normaliza `id_producto` a mayúsculas;
- valida existencia;
- valida producto activo;
- resuelve tipo por código estructural;
- rechaza `SERVICIO`;
- permite `PRODUCTO`;
- permite `KIT` como identidad independiente.

No implementa explosión de KIT.

No crea componentes.

No consume componentes.

## Conceptos

El servicio resuelve conceptos por código.

Conceptos iniciales aplicables:

- `ENTRADA_AJUSTE`
- `SALIDA_AJUSTE`

Rechaza:

- concepto inexistente;
- concepto inactivo;
- naturaleza distinta de `ENTRADA` o `SALIDA`.

No crea nuevos conceptos.

No modifica seeds.

## Historia

Los movimientos aplicados son historia.

INVENTARIO-SERVICE-1 no ofrece métodos para:

- editar encabezado aplicado;
- editar fecha;
- cambiar concepto;
- cambiar almacén;
- cambiar partidas;
- cambiar cantidades;
- borrar movimiento;
- anular movimiento.

Las correcciones futuras serán por reversa.

No se implementa reversa en esta fase.

## ATOMICIDAD ≠ IDEMPOTENCIA

INVENTARIO-SERVICE-1 garantiza atomicidad.

INVENTARIO-SERVICE-1 no deduplica reintentos externos.

No existe todavía:

- `idempotency_key`
- UUID de request
- token externo

Eso requiere diseño de integración/API futura.

## Sin sincronización oculta

No se crean:

- triggers;
- stored procedures;
- SQL functions;
- SQL events;
- cron.

Toda modificación de existencia en esta fase es coordinada explícitamente por `InventoryService`.

## Límites

No incluye:

- UI;
- controlador;
- rutas web;
- API;
- permisos RBAC;
- menú;
- sidebar;
- pantalla de existencias;
- kardex;
- anulación;
- reversa;
- transferencia;
- recepción de compra;
- entrega de venta;
- reservas;
- apartados;
- comprometido;
- disponible;
- en tránsito;
- mínimos;
- máximos;
- punto de reorden;
- costos;
- precios;
- series operativas;
- lotes operativos;
- pedimentos operativos;
- compras;
- ventas;
- CFDI;
- facturación;
- dashboard;
- KPIs;
- métricas.
