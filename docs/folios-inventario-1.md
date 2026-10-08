# FOLIOS-INVENTARIO-1

FOLIOS-INVENTARIO-1 integra los folios documentales por almacén con movimientos
de inventario y transferencias, sin crear compras, ventas, CFDI ni emisión desde
interfaces nuevas.

## Esquema

La fase agrega a `movimientos_inventario`:

- `folio_id BIGINT UNSIGNED NULL`
- `folio VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL`
- `fk_movimientos_inventario_folio` hacia `documentos_folios(id)`
- índices `idx_movimientos_inventario_folio_id` y
  `idx_movimientos_inventario_folio`

Las columnas son `NULL` para no alterar movimientos históricos. La columna
`referencia` permanece intacta y conserva su significado operativo secundario.

## Reglas operativas

- Ajustes manuales de entrada y salida emiten por defecto
  `AJUSTE_INVENTARIO / AJ`.
- Transferencias emiten por defecto `TRANSFERENCIA_INVENTARIO / TR`.
- Una transferencia usa un solo folio emitido para el almacén origen; el
  movimiento de salida y el de entrada guardan el mismo `folio_id` y `folio`.
- Si no existe serie documental activa para el almacén y tipo de operación, el
  servicio rechaza la operación con error controlado y no persiste movimiento,
  existencia ni folio.

## Transacciones

`FolioService` respeta una transacción externa cuando inventario ya abrió una.
Así, si el movimiento o la transferencia falla después de emitir el folio, el
documento de folio y el avance de consecutivo se revierten junto con el resto de
la operación.

## Consultas y vistas

Las consultas de movimientos, transferencias, kardex y kardex por serie exponen
`folio` junto a `referencia`. Las vistas muestran Folio primero y Referencia
después. Los movimientos históricos con `folio = NULL` se muestran como
`Sin folio`.

## Fuera de alcance

- No emite folios de compras, ventas ni CFDI.
- No modifica transferencias o movimientos fuera de la integración documental.
- No crea lotes ni pedimentos.
- No crea costos, precios, dashboard, KPIs ni módulos comerciales.
- No crea deploy, push ni remoto Git.
