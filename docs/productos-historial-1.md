# PRODUCTOS-HISTORIAL-1 — Emisión canónica

Estado de esta microfase: corrección del harness de auditoría para separar IDs
planificados de IDs realmente creados, evitar la segunda creación accidental de
`QAPHISTVAR01`, endurecer el tracking de la relación de kit y mantener un trace
seguro antes de la creación de `QAPHIST001`. El DB-TEST runtime todavía no se
ha repetido después de estos cambios.

## Contrato implementado

Los eventos directos usan `auditoria_eventos` mediante `AuditRecorderInterface`
`recordRequired()` y se emiten dentro de la transacción propietaria:

- `productos.crear`
- `productos.actualizar`
- `productos.activar`
- `productos.desactivar`

Todos usan:

```ini
entidad=producto
entidad_id=id_producto
resultado=EXITO
version_contrato=1
modulo=productos
producto_id=id_producto
subrecurso=null
```

El registry declarativo está en `config/products_history.php` y fija la
allowlist y el orden determinista de campos. Las actualizaciones guardan solo
los campos base realmente modificados. Un update sin cambios funcionales no
emite `productos.actualizar`.

La auditoría obligatoria falla la transacción y revierte la mutación. El
evento de creación se emite una sola vez desde `ProductService`; `ProductCreateCore`
no emite auditoría.

La normalización estática de subrecursos amplía los eventos existentes sin
renombrarlos ni duplicarlos:

- imágenes: `producto_imagen.subir`, `producto_imagen.reemplazar`,
  `producto_imagen.eliminar`;
- similares: `productos.similares.agregar`, `productos.similares.quitar`;
- kits: `productos.kits.componentes.agregar`, `actualizar`, `quitar`,
  `reactivar`;
- variantes: `productos.variantes.vincular`, `desvincular`,
  `atributos.actualizar`, `predeterminada`;
- importación: un único `productos.importacion.lote`, sin eventos por fila.

Los eventos de imágenes, similares y variantes no fabrican estados
`before/after`; kits sí conserva cantidades decimales reales con claves
canónicas `cantidad_before` y `cantidad_after`. Los eventos de familias,
atributos y valores sin vínculo inequívoco a un producto no se clasifican como
historial individual de producto.

## Alcance diferido

```ini
AUDIT_EMISSION_CORE_IMPLEMENTED=true
SUBRESOURCE_NORMALIZATION=IMPLEMENTED_STATIC_PENDING_RUNTIME_RETEST
SUBRESOURCE_RUNTIME_COVERAGE=CORE_CLOSED_STATIC; SIMILAR_KIT_CLOSED_STATIC; IMAGE_CLOSED_STATIC; VARIANT_CLOSED_STATIC; IMPORT_CLOSED_STATIC
STATIC_ONLY_REQUIRED_CHECKS_AFTER=none
STATIC_ONLY_REQUIRED_CHECKS=none
DB_TEST_RUNTIME=EXECUTED_FAIL_DUPLICATE_QAPHISTVAR01
AUDIT_CONTRACT_DB_TEST=IMPLEMENTED_FIXES_PENDING_STATIC_REVIEW
AUDIT_CONTRACT_RUNNER=database/productos-historial-audit.php
AUDIT_CONTRACT_FIXTURE_PREFIX=QAPHIST
AUDIT_CONTRACT_CLEANUP=EXPLICIT_FINALLY
DB_TEST_GLOBAL_TRANSACTION=false
AUDIT_REQUIRED_CHECKS=EXPLICIT_NON_HARDCODED
VARIANT_RUNTIME_BLOCKER_FIX=STATIC_CLOSED
VARIANT_FAMILY_ATTRIBUTE_RELATION_ASSERTED=true
CLEANUP_HARDENING=FIX_IMPLEMENTED_PENDING_STATIC_REVIEW
IMPORT_RUNTIME_COVERAGE=IMPLEMENTED_PENDING_STATIC_REVIEW
CREATE_ROLLBACK_FULL_SUBRESOURCE_ASSERTIONS=CLOSED_STATIC
FINAL_HOST_DB_TEST=NOT_EXECUTED_THIS_MICROPHASE
PREVIOUS_FAILED_RUN_RESIDUAL_STATUS=RESIDUES_CONFIRMED_HOST_READ_ONLY
AUDIT_HARNESS_QUERY_FIX=producto_similares uses composite primary key (producto_a_id, producto_b_id); similarRelationState no longer orders by nonexistent id
GLOBAL_CLEANUP_EXCEPTION_PATH=implemented with original exception preservation and separate cleanup error reporting
POST_FAILURE_RESIDUAL_VERIFICATION=exact captured IDs attempted and reported separately when safe
PREEXISTING_FIXTURE_GUARDS=IMPLEMENTED_PENDING_STATIC_REVIEW
HOST_READ_ONLY_PREFLIGHT=AVAILABLE_AS_PRECHECK_COMMAND
PLANNED_AND_CREATED_IDS_SEPARATED=true
CLEANUP_PRODUCT_DELETE_SOURCE=createdProductIds
PRE_CREATE_TRACE=IMPLEMENTED_PENDING_STATIC_REVIEW
PRE_CREATE_TRACE_SCOPE=QAPHIST001_DIRECT_COUNT_AND_REPOSITORY_EXISTS
GLOBAL_EXCEPTION_CLEANUP=PASS_RUNTIME
UNKNOWN_ID_FIX=PASS_RUNTIME_NO_REGRESSION
HTTP_QA=DEFERRED
UI_HISTORY=DEFERRED
MIGRATION_REQUIRED=false
PRODUCTION_DEPLOY=false
```

## Resultado del primer DB-TEST host

El primer `db:test` host terminó con `PDOException` (`SQLSTATE[42S22]`) por
`ORDER BY id` en `producto_similares`. La tabla usa la clave primaria compuesta
`(producto_a_id, producto_b_id)`, por lo que la consulta de estado se corrigió
sin añadir columnas ni modificar el esquema. El orden no era necesario porque
ambos componentes de la clave se fijan en el `WHERE`.

El harness ahora garantiza cleanup exacto mediante una ruta `finally` global
para fallos antes o después de capturar fixtures. Si el cleanup también falla,
el runner conserva la excepción original como causa principal y reporta el
error de cleanup por separado. El cleanup solo usa IDs y rutas capturados por
la ejecución; no usa patrones globales ni `TRUNCATE`.

```ini
DB_TEST_RUNTIME=EXECUTED_FAIL_DUPLICATE_QAPHISTVAR01
FINAL_HOST_DB_TEST=NOT_EXECUTED_THIS_MICROPHASE
PREVIOUS_FAILED_RUN_RESIDUAL_STATUS=RESIDUES_CONFIRMED_HOST_READ_ONLY
STATIC_ONLY_REQUIRED_CHECKS=none
CLEANUP_HARDENING=FIXED_PENDING_STATIC_REVIEW
CREATE_ROLLBACK_FULL_SUBRESOURCE_ASSERTIONS=CLOSED_STATIC
HTTP_RUNTIME_QA=NOT_EXECUTED
VISUAL_UI_REVIEW=NOT_EXECUTED
PRODUCTION_DEPLOY=false
```

No se modificaron las reglas funcionales de imágenes, similares, kits,
variantes o importación; tampoco precios, UI, rutas ni permisos.
`producto_precios_historial` permanece como fuente
autoritativa del historial de precios.

El producto protegido `102016169` no se utilizó ni se consultó.

## Corrección de ownership del harness y trace previo

```ini
DB_TEST_RUNTIME=EXECUTED_FAIL_DUPLICATE_QAPHISTVAR01
DUPLICATE_ROOT_CAUSE=ACCIDENTAL_SECOND_QAPHISTVAR01_CREATE
CLEANUP_OWNERSHIP_DEFECT=KIT_RELATION_TRACKING_AND_RESIDUAL_SCOPE_HARDENED
PRE_CREATE_TRACE=IMPLEMENTED_PENDING_STATIC_REVIEW
LATEST_FAILED_RUN_RESIDUAL_STATUS=ACTOR_4597_7_PRODUCTS_KIT_RELATION_13_CONFIRMED
GLOBAL_EXCEPTION_CLEANUP=PASS_RUNTIME
UNKNOWN_ID_FIX=PASS_RUNTIME_NO_REGRESSION
PLANNED_AND_CREATED_IDS_SEPARATED=true
CLEANUP_PRODUCT_DELETE_SOURCE=createdProductIds
PRE_CREATE_TRACE_READ_ONLY=true
PRE_CREATE_TRACE_SECRET_SAFETY=PASS
PRODUCTION_CODE_FIX_REQUIRED=false
DB_TEST_EXECUTED_THIS_MICROPHASE=false
PLANNED_QA_PRODUCT_IDS_COUNT=11
PLANNED_QA_PRODUCT_IDS_MUTATED_AFTER_INIT=false
SECOND_QAPHISTVAR01_CREATE_REMOVED=true
KIT_RELATION_ID_CAPTURE_FAIL_CLOSED=true
CHILD_FK_ZERO_ASSERT_BEFORE_PRODUCTS=true
```

La última corrida host confirmó la causa raíz: `QAPHISTVAR01` se creó una vez
en la preparación general y se intentó crear de nuevo al iniciar la sección de
variantes. La validación de ID duplicado terminó la corrida. El cleanup falló al
intentar borrar productos mientras permanecía la relación de kit `id=13`; los
contadores anteriores ocultaban ese residuo porque usaban un subconjunto de IDs.

El harness corregido conserva los 11 IDs planificados para preflight y
verificación de residuos, pero solo agrega a `createdProductIds` productos cuyo
`create()` retornó correctamente. La sección de variantes reutiliza
`QAPHISTVAR01` y ya no lo crea por segunda vez. Las relaciones de kit se capturan
con fail-closed mediante su PK real; antes de borrar productos se comprueba que
no queden relaciones por PK ni referencias child-FK para los productos creados.
Las relaciones similares, recursos de variante, documentos e importaciones se
limpian mediante IDs o pares capturados después de una creación exitosa. El
trace previo de `QAPHIST001` realiza únicamente el conteo activo directo y
`ProductRepository::exists()`; no altera el payload ni el flujo de control.

Residuos runtime actuales conocidos, pendientes de cleanup exacto separado:

```ini
CURRENT_RUNTIME_RESIDUES_TOUCHED=false
CURRENT_RUNTIME_ACTOR_ID=4597
CURRENT_RUNTIME_PRODUCT_COUNT=7
CURRENT_RUNTIME_KIT_RELATION_ID=13
DB_TEST_RERUN_AFTER_FIX=false
```

## Corrección Windows `tempnam()` y diagnóstico de imagen

```ini
WINDOWS_TEMPNAM_PROBE=PASS_TECHNICAL
WINDOWS_TEMPNAM_RUNTIME_SAMPLE=QAP2D78.tmp
WINDOWS_TEMPNAM_FULL_PREFIX_GUARD_ROOT_CAUSE=true
IMPORT_TEMP_GUARD_FIX_IMPLEMENTED=true
IMPORT_TEMP_GUARD_EFFECTIVE_PREFIX_DERIVED=true
IMAGE_FINAL_CLEANUP_BLOCKER=OPEN_DIAGNOSTIC_PENDING
DB_TEST_RERUN_AFTER_WINDOWS_GUARD_FIX=false
```

La propagación del diagnóstico de imagen queda implementada en el harness:
`image_final_diagnostics` se captura antes de evaluar `finalPathGuard()` y se
añade de forma aditiva al payload de fallo de cleanup. El guard, sus rutas
aceptadas/rechazadas y el orden de cleanup no cambian. No se ha ejecutado
`db:test`; la fase continúa abierta y el siguiente paso es una ejecución
diagnóstica controlada.

En Windows, `tempnam(sys_get_temp_dir(), 'QAPHISTIMP')` generó un basename
`QAP2D78.tmp`; por ello el guard ya no exige que el basename conserve todo el
prefijo solicitado. Mantiene la raíz temporal exacta, `is_file()`, `realpath()`,
la revisión de traversal y un prefijo efectivo derivado de `QAPHISTIMP` (`QAP`
en Windows). El cleanup continúa registrando y eliminando únicamente el path
exacto retornado por `tempnam()`.

El bloqueo independiente de `finalPathGuard()` para la imagen permanece abierto
para diagnóstico; la fase no está cerrada y no se volvió a ejecutar `db:test`.

## Corrección estática de cleanup de locks y contrato de ruta final

```ini
WINDOWS_TEMPNAM_FIX_RUNTIME_PROGRESS=true
IMPORT_CLEANUP_ROOT_CAUSE=STALE_PREVIEW_LOCK_FILE_NOT_REMOVED_BEFORE_RMDIR
IMPORT_LOCK_CLEANUP_IMPLEMENTED=true
IMPORT_LOCK_CLEANUP_SCOPE_EXACT=true
IMPORT_LOCK_SYMLINKS_FOLLOWED=false
IMPORT_LOCK_GENERIC_RECURSIVE_DELETE_USED=false
IMAGE_CASE_MISMATCH_HYPOTHESIS=DISPROVEN
IMAGE_FINAL_PATH_CONTRACT=storage/uploads/productos/<producto>/<basename>.png
IMAGE_PATH_FIX_IMPLEMENTED=HARNESS_ONLY
IMAGE_PATH_ROOT_CAUSE=STALE_STATE_DEFECT
IMAGE_GUARDS_RELAXED=false
CURRENT_RESIDUES_TOUCHED=false
DB_TEST_EXECUTED=false
```

El harness elimina únicamente archivos regulares `.lock` del directorio exacto
`private/product_import_previews/locks` perteneciente al workspace generado por
`importRuntime()`. Rechaza symlinks, entradas no regulares y nombres no
terminados en `.lock`; exige que el directorio quede vacío antes de continuar
con `rmdir()` en el orden establecido.

La ruta final de imagen se reconstruye con una sola raíz:
`BASE_PATH/storage/uploads/productos` más `ruta_relativa` (`QAPHISTIMG01/`
`foto-principal-<random>.png`). `finalPathGuard()`,
`imageFinalPathDiagnostics()` y `unlinkFinalExact()` conservan los mismos
guards de archivo, `realpath`, parent, raíz, segmento, extensión, traversal y
basename. Se añadió un assert harness-only del segmento derivado antes del
unlink. La hipótesis de diferencia de casing quedó descartada por la evidencia
host; la discrepancia anterior se clasifica como reconstrucción/estado del path
del harness. El estado `$this->imageFinalPath` se reinicia al inicio de cada
`run()` y se vacía únicamente después de que `unlinkFinalExact()` completa el
unlink y verifica la postcondición. Si el cleanup falla, la ruta y su
diagnóstico se conservan para reporting.

Los guards de workspace y locks validan realpath, parent directo, symlinks,
traversal, segmento exacto del workspace y límites del entry antes de borrar.

La validación de esta microfase es estática: no se ejecutó `db:test`, cleanup ni
ningún write de DB. La fase permanece abierta para revisión final.
