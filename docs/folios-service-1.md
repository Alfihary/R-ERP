# FOLIOS-SERVICE-1

## Alcance

FOLIOS-SERVICE-1 agrega el servicio transaccional para emitir folios documentales desde la estructura creada en FOLIOS-DB-1.

FOLIOS-SERVICE-1 EMITE FOLIOS DESDE SERVICIO DE DOMINIO.

Esta fase sí emite folios desde código de dominio, pero todavía no integra esa emisión con movimientos de inventario, transferencias, ventas, compras, CFDI ni pantallas administrativas.

FOLIOS-SERVICE-1 NO MODIFICA MOVIMIENTOS NI TRANSFERENCIAS.

## Regla documental

Los folios operativos son por almacén.

La serie documental única queda definida por:

```text
empresa_id + almacen_id + tipo_documento + codigo_serie
```

Cada empresa, almacén, tipo de documento y serie mantiene su propio consecutivo.

## Formato soportado

Formato base:

```text
{PREFIJO}-{ALMACEN}{NUMERO}
```

Ejemplos:

```text
F-BO000001
R-BO000001
TR-BO000001
AJ-BO000001
EN-BO000001
SA-BO000001
```

El folio se materializa completo en `documentos_folios.folio`.

## Snapshots

El servicio usa los valores guardados en la serie documental:

- `prefijo`
- `codigo_almacen_snapshot`
- `formato`

El valor de `codigo_almacen_snapshot` protege la configuración documental y los históricos ante cambios futuros en `almacenes.codigo`.

El servicio no reconstruye folios leyendo dinámicamente el código actual del almacén.

## Flujo de emisión

`FolioService::emitir()` realiza:

1. Validación mínima de entrada.
2. Validación de que el almacén pertenece a la empresa.
3. Validación opcional de usuario creador activo.
4. Apertura de transacción.
5. Búsqueda y bloqueo de la serie documental con `SELECT ... FOR UPDATE`.
6. Validación de serie activa y no eliminada.
7. Cálculo del número siguiente.
8. Construcción del folio materializado.
9. Inserción en `documentos_folios`.
10. Actualización de `series_documentales.siguiente_numero`.
11. Confirmación de transacción.

Si ocurre un error, la transacción se revierte.

## Reinicio anual

Cuando la serie tiene `reinicio_anual = 1`, el servicio evalúa `anio_actual`.

- Si `anio_actual` está vacío o es diferente al año actual, el consecutivo inicia en `1`.
- Si `anio_actual` coincide con el año actual, continúa desde `siguiente_numero`.
- Después de emitir, actualiza `anio_actual` y `siguiente_numero`.

Cuando `reinicio_anual = 0`, el consecutivo continúa sin scope anual.

## Bloqueo transaccional

La serie se lee con `SELECT ... FOR UPDATE` dentro de la transacción para evitar que dos emisiones concurrentes tomen el mismo consecutivo.

La restricción única de `documentos_folios` sigue siendo una defensa adicional de base de datos contra duplicados.

## Excepciones controladas

El servicio expone errores mediante `FolioValidationException`.

Los errores SQL internos no se devuelven al consumidor. Se traducen a mensajes controlados para evitar filtrar detalles de base de datos.

Casos controlados:

- Serie documental inexistente.
- Serie documental inactiva o eliminada.
- Almacén ajeno a empresa.
- Usuario creador inválido.
- Formato no soportado.
- Datos obligatorios faltantes.
- Error de emisión por colisión o restricción de base de datos.

## DB-TEST

`database/folios-service.php db:test` valida:

- Emisión `F-BO000001`.
- Incremento `F-BO000002`.
- Consecutivo independiente por tipo documental `R-BO000001`.
- Consecutivo independiente por almacén `F-MTY000001`.
- Mismo folio textual permitido en otra empresa/almacén conforme a la unicidad de scope.
- Snapshot de almacén usado aunque cambie `almacenes.codigo`.
- Serie inexistente rechazada.
- Serie inactiva rechazada.
- Serie eliminada lógicamente rechazada.
- Almacén ajeno a empresa rechazado.
- Usuario creador inválido rechazado.
- Formato no soportado rechazado.
- Rollback ante falla controlada.
- Reinicio anual desde `anio_actual` nulo o previo.
- Duplicado de folio controlado por base de datos.
- Limpieza de datos transitorios.

## Fuera de alcance

FOLIOS-SERVICE-1 no crea:

- UI de folios.
- CRUD de series documentales.
- Emisión automática desde inventario.
- Emisión automática desde transferencias.
- Integración con compras.
- Integración con ventas.
- Integración con CFDI.
- Folios visibles en movimientos existentes.
- Jobs, colas o API externa.
- Deploy.

## Fases posteriores

Queda pendiente integrar este servicio en fases posteriores, por ejemplo:

- FOLIOS-INVENTARIO-1
- FOLIOS-TRANSFERENCIAS-1
- FOLIOS-UI-1
