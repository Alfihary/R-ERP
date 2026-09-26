# PRODUCTOS-IMPORTACION-CONFIRMACION-1

## Alcance

Esta fase añade la confirmación segura de un preview válido antes de la futura
ejecución de una importación de productos. La confirmación termina en el estado
`CONFIRMATION_READY`; no crea ni modifica productos, precios, existencias,
movimientos, imágenes, tickets ni correos.

La ruta es `POST /productos/importar/confirmar`. Requiere sesión autenticada,
los permisos `productos.acceder` y `productos.crear`, y un token CSRF válido.
El cuerpo permitido contiene únicamente `preview_id` y el campo CSRF.

## Revalidación completa

El servidor recupera la metadata privada mediante un `preview_id` de 64
caracteres hexadecimales y vuelve a validar:

- propietario (`user_id`);
- binding SHA-256 de la sesión mediante `hash_equals()`;
- vigencia del preview (TTL de 1800 segundos);
- `used_at === null`;
- existencia, ubicación dentro del root privado, ausencia de symlink y tamaño;
- SHA-256 actual del archivo frente al digest almacenado;
- lectura completa con `ProductImportReader`;
- reglas actuales con `ProductImportBusinessValidator` y el estado vigente de
  la base de datos.

El resultado revalidado prevalece sobre el preview almacenado. Por ello se
detectan productos, SKU o códigos de barras ocupados después del preview, así
como catálogos eliminados o desactivados. Una fila inválida o un error global
produce `confirmation_revalidation_failed` y devuelve los errores actuales sin
preparar una ejecución.

## Token y almacenamiento privado

Una confirmación válida genera un token criptográfico independiente del
`preview_id` (`random_bytes(32)`, codificado como 64 caracteres hexadecimales).
Su metadata se guarda como JSON mediante escritura atómica en el mismo root
privado del preview, bajo `confirmations/` y nunca en base de datos. No se usa
`serialize()`.

El token queda ligado a `preview_id`, `user_id`, binding de sesión, digest del
archivo, digest SHA-256 determinista del resultado canónico, creación,
expiración y `used_at=null`. Su TTL es de 600 segundos. Una nueva confirmación
del mismo preview invalida cualquier token anterior no usado, por lo que solo
puede existir una confirmación activa por preview.

La metadata incluye además un digest canónico de integridad propio; cualquier
alteración accidental de sus campos sin regenerar el artefacto completo se
rechaza como metadata corrupta.

La fase solo diseña el contrato de uso único. `confirmation.used_at` y
`preview.used_at` se marcarán atómicamente únicamente cuando una fase futura
complete con éxito la escritura definitiva. Generar el token no consume el
preview.

## Cleanup

El cleanup elimina confirmations expiradas o corruptas y previews expirados o
corruptos junto con sus archivos asociados. Un preview expirado se conserva
temporalmente si todavía existe una confirmation activa válida que depende de
él. La metadata corrupta no se conserva.

## Interfaz

Un preview sin errores muestra `Confirmar importación`. En esta fase el botón
solo revalida y prepara el estado `CONFIRMATION_READY`. La vista muestra los
totales, un prefijo del digest de fuente y la expiración, pero no expone el
token completo ni ofrece una acción que escriba productos. Los previews con
errores no muestran la acción de confirmación.

## Pruebas

El runner controlado es:

```text
php database/productos-importacion-confirmacion.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

Rechaza producción y exige que ambos nombres coincidan exactamente con
`APP_DB_NAME`. Cubre ownership, sesión, expiración, `used_at`, integridad de
fuente, relectura, revalidación, cambios concurrentes de producto y catálogo
en transacciones revertidas, token, binding, política de token activo, metadata
alterada, traversal, cleanup, HTTP/CSRF/permisos e integridad antes/después de
las tablas protegidas.

`DB_WRITES_BUSINESS=0`: los cambios transitorios de escenarios DB se revierten
antes de comparar los snapshots finales.

## Prerrequisito de hosting

`HOSTING_PREREQUISITE_PENDING=true`

La compatibilidad real de AwardSpace con la dependencia XLSX continúa pendiente
de verificación; esta fase no cambia ese estado.
