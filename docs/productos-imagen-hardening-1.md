# PRODUCTOS-2A — Imagen principal: hardening

## Alcance

Esta microfase endurece exclusivamente dos aspectos del contrato de imagen
principal:

- auditoría explícita de subir, reemplazar y eliminar;
- recuperación de archivos físicos que no pudieron eliminarse después del
  commit.

No incluye thumbnails, cambios en el listado, precios, importación, productos
similares ni deploy.

La evidencia runtime se obtuvo en el entorno local del ERP sobre MySQL Server
8.0 (no MariaDB ni XAMPP). La prueba se ejecutó contra la base QA autorizada;
no se usó XAMPP/MariaDB como servidor del ERP.

## Auditoría transaccional

`ProductImageService` recibe `AuditRecorderInterface` y usa
`recordRequired()` dentro de la transacción que modifica
`producto_documentos`.

Eventos:

```text
producto_imagen.subir
producto_imagen.reemplazar
producto_imagen.eliminar
```

La metadata mínima contiene identificadores de producto/documentos, MIME y
tamaño cuando corresponde. No se escriben contenido, base64, bytes, rutas
absolutas, nombres temporales, contraseñas ni secretos.

Si la auditoría falla, la transacción se revierte. Si ya se había escrito un
archivo nuevo, el servicio lo elimina como compensación.

```text
IMAGE_AUDIT_CURRENTLY_EXPLICIT=true (antes del hardening era false)
IMAGE_AUDIT_TRANSACTIONAL=true
IMAGE_AUDIT_UPLOAD=PASS
IMAGE_AUDIT_REPLACE=PASS
IMAGE_AUDIT_DELETE=PASS
IMAGE_AUDIT_FAILURE_ROLLBACK=PASS
```

## Archivos huérfanos

La metadata histórica de `producto_documentos` se conserva, pero el endpoint
solo requiere el archivo de una imagen activa (`activo=1` y
`eliminado_en IS NULL`). Por ello, un archivo físico cuyo path solo aparece en
metadata histórica inactiva y no en ninguna metadata activa ya no es necesario
para el funcionamiento vigente y es candidato de cleanup.

```text
ORPHAN_DEFINITION=
archivo regular dentro de storage/uploads/productos cuyo path no está
referenciado por un documento activo y no eliminado; los documentos históricos
inactivos conservan metadata, pero no requieren conservar el binario.
```

El reconciliador es `ProductImageOrphanCleanupService`. Su contrato:

- escanea únicamente `storage/uploads/productos/`;
- normaliza separadores;
- rechaza `..`, rutas absolutas y escapes;
- exige `realpath()` dentro del root;
- ignora directorios;
- nunca recibe una ruta arbitraria desde HTTP;
- soporta `dry-run` sin borrar ni escribir DB;
- una segunda ejecución es segura.

CLI:

```text
php database/productos-imagen-cleanup.php dry-run --database=DB --confirm-database=DB
php database/productos-imagen-cleanup.php cleanup --database=DB --confirm-database=DB
```

El comando rechaza producción y exige confirmar dos veces el nombre de la
base. `dry-run` es el modo recomendado para inspección.

```text
ORPHAN_STRATEGY_IMPLEMENTED=ProductImageOrphanCleanupService + CLI seguro
ORPHAN_CLEANUP_DRY_RUN_SUPPORTED=true
ORPHAN_CLEANUP_PATH_GUARD=PASS
ORPHAN_CLEANUP_IDEMPOTENT=true
```

No se creó migración ni tabla nueva.

## Orden de mutaciones

### Subir

Validar archivo, guardar archivo privado, insertar metadata, registrar auditoría
y confirmar. Ante error se revierte DB y se elimina el archivo nuevo.

### Reemplazar

Validar, guardar archivo nuevo, bloquear producto/documento, insertar metadata,
desactivar la anterior, auditar, hacer commit y finalmente intentar eliminar el
archivo anterior. Un fallo de `unlink()` posterior al commit queda recuperable
por el reconciliador.

### Eliminar

Bloquear, desactivar metadata, auditar, hacer commit y finalmente intentar
eliminar el archivo físico. La metadata histórica permanece disponible.

## Pruebas

Se amplió `database/tests/productos_imagen_1_test.php` para cubrir:

- eventos de subir/reemplazar/eliminar;
- rollback cuando falla la auditoría;
- fallo post-commit de `unlink()` en replace;
- fallo post-commit de `unlink()` en delete;
- detección dry-run de archivos QA;
- eliminación posterior e idempotencia del cleanup;
- preservación de archivos activos referenciados.

No se usa el producto protegido `102016169` como fixture.

Resultado runtime aportado por el host:

```text
SUITE_BASE=PASS
qa_files=0
IMAGE_AUDIT_UPLOAD=PASS
IMAGE_AUDIT_REPLACE=PASS
IMAGE_AUDIT_DELETE=PASS
IMAGE_AUDIT_FAILURE_ROLLBACK=PASS
ORPHAN_DRY_RUN_DETECTS_QA_FILE=PASS
ORPHAN_DRY_RUN_DELETES_NOTHING=PASS
ORPHAN_CLEANUP_REMOVES_QA_ORPHAN=PASS
ORPHAN_CLEANUP_PRESERVES_REFERENCED_FILE=PASS
ORPHAN_CLEANUP_REJECTS_OUTSIDE_STORAGE=PASS
ORPHAN_CLEANUP_SECOND_RUN_SAFE=PASS
REPLACE_POST_COMMIT_UNLINK_FAILURE_HANDLED=PASS
DELETE_POST_COMMIT_UNLINK_FAILURE_HANDLED=PASS
```

También se validó el comando DB-TEST de la fase y el `dry-run` de cleanup con
`candidate_count=0`. Esta evidencia corresponde al host local y no declara
compatibilidad ni despliegue en AwardSpace.

## Estado de hosting

```text
AWARDSPACE_IMAGE_UPLOAD_COMPATIBILITY=UNKNOWN_REQUIRES_HOSTING_VERIFICATION
```

La verificación real queda para `DEPLOY-0`.

## Pendiente

Los thumbnails y la visualización de imágenes en el listado siguen fuera de
esta microfase. `PRODUCTOS-2` no se declara completa mientras `2B` permanezca
pendiente.
