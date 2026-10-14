# PRODUCTOS-4 — importación definitiva CREATE-ONLY

## Estado y alcance

La confirmación HTTP conecta ahora el preview privado, la revalidación y un
lote definitivo de productos. El flujo acepta CSV y XLSX, conserva el límite
de 5 MiB y máximo 1000 filas, y solo crea productos nuevos (`CREATE_ONLY`). No
existe `upsert`, `UPDATE` ni creación automática de catálogos.

El `ProductImportBatchService` es el único dueño de la transacción:

1. valida el token y revalida propiedad, sesión inicial, expiración, digest,
   encabezados, catálogos y conflictos actuales;
2. inserta `PROCESSING` en `producto_importaciones` como guard DB;
3. crea todos los productos y sus relaciones;
4. registra `productos.importacion.lote` en `auditoria_eventos`;
5. marca `SUCCEEDED` antes de `COMMIT`;
6. confirma una sola transacción ALL-OR-NOTHING.

Un fallo revierte productos, relaciones, auditoría y el guard. La restricción
`UNIQUE` de `confirmation_token` es el backstop de carreras; una carrera que
encuentra un `SUCCEEDED` devuelve el resumen durable sin repetir escrituras.

## Replay y filesystem

El replay del mismo usuario se resuelve desde `producto_importaciones` antes
de requerir el preview o el upload. Por ello `status=already_processed` puede
responder aunque el filesystem ya se haya limpiado y no requiere la misma
sesión. La primera ejecución sí exige el binding actual de sesión. El JSON
`used_at` es auxiliar y no autoritativo. La limpieza posterior al commit es
best-effort y nunca invalida un éxito DB.

El endpoint `POST /productos/importar/confirmar` conserva autenticación,
`productos.acceder`, `productos.crear` y CSRF. Acepta el preview para la
primera ejecución y `confirmation_token` para replay durable. No se agregó UI,
permiso ni módulo nuevo.

## Contrato canónico

El contrato canónico de importación contiene exactamente **28 campos** y es el
contrato existente del lector/validador; esta fase no inventa ni renombra
columnas.

`ProductImportBatchService` posee una seam opcional de testing, inerte por
defecto, utilizada exclusivamente por DB-TEST para demostrar rollback después
de persistencia real. No es configurable por HTTP, request, sesión, `.env`,
configuración pública ni rutas. Producción la construye como `NULL`/no-op; no
cambia reglas de negocio ni forma parte del contrato funcional de importación.

## Fuera de alcance

No se importan precios iniciales, imágenes, inventario, existencias, series,
lotes, pedimentos o similares. No se crean jobs asíncronos ni se modifica el
flujo de despliegue. La migración de idempotencia solo está aplicada en la DB
de prueba; producción sigue pendiente. AwardSpace permanece sin verificación
real.

## Prueba controlada

```text
php database/productos-importacion-definitiva.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

La prueba usa IDs QA generados por ejecución, elimina exclusivamente esos IDs,
verifica el producto protegido `102016169` y no imprime credenciales. El test
de concurrencia física de dos conexiones queda como `NOT_EXECUTED_SAFE_LIMITATION`;
la unicidad DB y el replay durable sí se verifican.

## Pendiente de aprobar

- aplicación de la migración en producción;
- validación real de hosting/AwardSpace;
- revisión final de diff y autorización separada de staging/commit/push.
