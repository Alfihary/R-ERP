# TRANSFERENCIAS-1

## Alcance

TRANSFERENCIAS-1 implementa la base de dominio para transferir inventario entre dos almacenes de la misma empresa usando el núcleo de inventario existente.

Esta fase no crea UI, controladores web, rutas web, API, permisos RBAC, sidebar ni vistas.

## Principio

TRANSFERENCIA = SALIDA + ENTRADA

AMBAS APLICADAS O NINGUNA

MOVIMIENTOS = VERDAD HISTÓRICA

EXISTENCIAS = SALDO MATERIALIZADO

La transferencia no actualiza saldos directamente fuera del flujo transaccional de movimientos. Cada transferencia exitosa genera dos movimientos aplicados:

- `TRANSFERENCIA_SALIDA` en almacén origen.
- `TRANSFERENCIA_ENTRADA` en almacén destino.

Ambos movimientos comparten una misma referencia `TRF-*`.

## Conceptos

El seed `transferencias_1_seed_conceptos` crea de forma idempotente:

- `TRANSFERENCIA_SALIDA` con naturaleza `SALIDA`.
- `TRANSFERENCIA_ENTRADA` con naturaleza `ENTRADA`.

No modifica `ENTRADA_AJUSTE` ni `SALIDA_AJUSTE`.

## Servicio

El servicio `App\Domain\Inventory\InventoryTransferService` expone:

```php
transferir(array $input): array
```

Entrada conceptual:

- `empresa_id`
- `almacen_origen_id`
- `almacen_destino_id`
- `fecha_movimiento`
- `referencia` opcional
- `observaciones` opcionales
- `usuario_id`
- `partidas[]`

Cada partida contiene:

- `id_producto`
- `cantidad`
- `observaciones` opcionales

## Atomicidad

La transferencia completa corre dentro de una sola transacción real mediante `InventoryRepository::transactional()`.

Si falla cualquier validación o actualización, la transacción revierte:

- movimiento salida;
- detalle salida;
- decremento en origen;
- movimiento entrada;
- detalle entrada;
- incremento en destino.

No deben quedar movimientos `BORRADOR` residuales ni existencias modificadas parcialmente.

## Referencia común

Mientras no exista una tabla formal de transferencias, la relación entre salida y entrada se mantiene por una referencia común:

```text
TRF-{fecha-hora}-{random corto}
```

No se crean folios empresariales, consecutivos complejos ni tabla de folios.

## Almacenes

El servicio valida:

- empresa activa;
- almacén origen activo;
- almacén destino activo;
- ambos almacenes pertenecen a la misma empresa;
- origen y destino son distintos.

TRANSFERENCIAS-1 no permite transferencias entre empresas.

## Productos

El servicio valida:

- producto existente;
- producto activo;
- tipo de producto activo;
- `PRODUCTO` permitido;
- `KIT` permitido como identidad independiente;
- `SERVICIO` rechazado.

No explota componentes de KIT y no implementa armado o desarmado.

## Cantidades

Las cantidades siguen las mismas reglas del servicio de inventario:

- decimal positivo como string;
- escala máxima 6;
- sin float;
- sin double;
- sin BCMath obligatorio;
- rechazo de 0, negativos, más de 6 decimales y fuera de rango.

Ejemplos normalizados:

- `1` a `1.000000`
- `1.5` a `1.500000`
- `0.000001` a `0.000001`

## Locking

Las partidas se ordenan por `id_producto` antes de bloquear existencias.

Por cada producto se bloquea origen y después destino con `SELECT ... FOR UPDATE`.

No se ejecutó harness real de concurrencia en TRANSFERENCIAS-1.

Pendiente técnico futuro:

```text
INVENTARIO-TRANSFER-CONCURRENCY-QA-1
```

## Idempotencia

TRANSFERENCIAS-1 garantiza atomicidad, no deduplicación de reintentos externos.

ATOMICIDAD no equivale a IDEMPOTENCIA.

No se crea `idempotency_key`, UUID de request ni token externo de deduplicación.

## Anulación y reversa

No se implementa:

- anular transferencia;
- revertir transferencia;
- cancelar transferencia;
- editar transferencia.

La corrección futura será una fase propia.

## DB-TEST

Runner:

```bash
php database/transferencias.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

El test cubre:

- transferencia simple;
- transferencia multipartida;
- cantidad mínima;
- KIT activo;
- validaciones negativas;
- rollback crítico por saldo insuficiente multipartida;
- precisión decimal;
- historia de salida y entrada con referencia común;
- limpieza de datos QA.

El fallo simulado después de salida no se implementó con hook de producción. La limitación queda documentada para evitar introducir mecanismos peligrosos solo para pruebas.
