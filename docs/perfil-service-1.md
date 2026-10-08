# PERFIL-SERVICE-1

## Objetivo

Crear la capa backend reutilizable para que un usuario autenticado pueda consultar y mantener su propio perfil, cambiar su contraseña y administrar metadatos de su foto activa.

Esta fase no implementa interfaz, rutas, controladores, vCard pública, QR, VCF ni credencial visual.

## Archivos creados

- `app/Domain/Profile/ProfileValidationException.php`
- `app/Domain/Profile/ProfileService.php`
- `app/Infrastructure/Repositories/ProfileRepository.php`
- `app/Infrastructure/Repositories/UserPhotoRepository.php`
- `database/perfil-service.php`
- `database/tests/perfil_service_1_test.php`
- `docs/perfil-service-1.md`

No requiere modificar `bootstrap/app.php` porque no se exponen rutas ni controladores en esta fase.

## Métodos del servicio

- `obtenerPerfil(int $usuarioId): array`
- `asegurarPerfil(int $usuarioId): array`
- `actualizarPerfil(int $usuarioId, array $input): array`
- `cambiarPassword(int $usuarioId, array $input): void`
- `registrarFoto(int $usuarioId, array $metadata, ?int $actorId = null): array`
- `eliminarFoto(int $usuarioId, ?int $actorId = null): void`
- `obtenerFotoActiva(int $usuarioId): ?array`

## Reglas de perfil

- El usuario debe existir, estar activo y no estar eliminado lógicamente.
- Los strings se normalizan con `trim`.
- Los strings vacíos se guardan como `NULL`.
- Campos de nombre y apellidos: máximo 80 caracteres.
- `puesto`: máximo 120 caracteres.
- Teléfonos y WhatsApp: máximo 40 caracteres.
- URLs públicas: solo `http` o `https`.
- `google_maps_url`: máximo 500 caracteres.
- `ubicacion_publica`: máximo 255 caracteres.
- No se permite actualizar roles, permisos, empresa, almacén, `activo`, email de login, username, contraseña ni hash desde `actualizarPerfil()`.

## Reglas de contraseña

- Requiere contraseña actual.
- Verifica contraseña actual con `password_verify()`.
- Requiere nueva contraseña y confirmación.
- La nueva contraseña debe tener al menos 8 caracteres.
- Rechaza contraseña nueva igual a la actual.
- Guarda con `password_hash()`.
- No devuelve contraseña ni hash.
- No imprime contraseña ni hash en errores.
- La revocación de sesiones queda pendiente para una fase posterior porque aún no existe servicio/tabla formal de sesiones persistentes.

## Reglas de foto

- Esta fase maneja solo metadatos, no archivos físicos.
- `ruta_relativa` es obligatoria y no puede ser absoluta, URL, pública ni contener traversal.
- `nombre_archivo` es obligatorio y debe ser seguro.
- MIME permitido: `image/jpeg`, `image/png`, `image/webp`.
- Extensiones permitidas: `jpg`, `jpeg`, `png`, `webp`.
- Tamaño máximo documentado: 5 MB.
- `sha256` debe tener 64 caracteres hexadecimales.
- `ancho` y `alto` deben ser positivos o ambos `NULL`.
- Rechaza dobles extensiones peligrosas.
- Registrar una foto desactiva la foto activa anterior.
- Eliminar foto marca la activa como inactiva/eliminada sin borrar archivo físico.

## Fuera de alcance

- UI de perfil.
- Rutas.
- Controladores.
- Vistas.
- CSS/JS.
- vCard pública.
- QR.
- VCF.
- Credencial visual.
- Productos vCard.
- Movimiento físico de archivos.
- Descarga pública o privada de fotos.
- Permisos nuevos.
- Seeds nuevos.
- Migraciones nuevas o cambios de tablas.

## Comandos de validación

```bash
php -l app/Domain/Profile/ProfileValidationException.php
php -l app/Domain/Profile/ProfileService.php
php -l app/Infrastructure/Repositories/ProfileRepository.php
php -l app/Infrastructure/Repositories/UserPhotoRepository.php
php -l database/perfil-service.php
php -l database/tests/perfil_service_1_test.php
git diff --check
php database/perfil-service.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

Regresiones mínimas:

```bash
php database/perfil-vcard.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/rbac.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/productos-imagen.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/productos-2.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

## Evidencia esperada de DB-TEST

- Perfil base creado de forma idempotente para usuario QA activo.
- Perfil existente leído correctamente.
- Campos permitidos actualizados.
- Strings vacíos guardados como `NULL`.
- URLs inválidas rechazadas.
- Longitudes máximas rechazadas.
- Campos prohibidos rechazados.
- Usuario inexistente e inactivo rechazados.
- Contraseña actual incorrecta, confirmación distinta, contraseña corta y contraseña igual rechazadas.
- Hash actualizado con `password_hash()` y verificable con `password_verify()`.
- Foto activa creada.
- Foto previa desactivada al registrar otra.
- MIME, extensión, traversal, hash y doble extensión peligrosa rechazados.
- Eliminación de foto activa idempotente.
- Sin rutas, controladores, vistas, CSS/JS ni vCard pública.
- Rollback transaccional de datos QA.

## Riesgos conocidos

- La revocación de sesiones posteriores al cambio de contraseña queda diferida.
- La validación real de MIME con `finfo` y movimiento seguro de archivos queda para la fase de upload físico.
- La exposición controlada de foto/vCard queda pendiente para fases de UI/API pública.

## Siguiente fase sugerida

`PERFIL-UI-1` o una fase intermedia de upload físico seguro, según se decida. No iniciar sin autorización explícita.
