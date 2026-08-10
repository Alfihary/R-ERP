# CREDENCIAL-VISUAL-1

## Objetivo

Implementar una credencial visual privada para el usuario autenticado dentro del área del ERP.

## Ruta creada

- `GET /perfil/credencial`

La ruta requiere sesión activa y permiso `credencial.ver`.

## Servicio creado

- `App\Domain\Credentials\CredentialService`

Responsabilidades:

- asegurar una fila base en `credenciales_usuario`;
- validar usuario existente, activo y no eliminado;
- obtener datos seguros para la credencial visual;
- no exponer hashes ni rutas privadas.

## Repositorio creado

- `App\Infrastructure\Repositories\UserCredentialRepository`

Responsabilidades:

- buscar credencial por usuario;
- crear credencial base si no existe;
- leer usuario, perfil y metadata segura de foto activa;
- usar PDO y prepared statements.

## Vista creada

- `app/Views/credentials/show.php`

Muestra una credencial compacta e imprimible dentro del layout autenticado.

## CSS creado

- `public/css/modules/credential.css`

Usa CSS modular, tokens globales disponibles, reglas responsivas y soporte básico de impresión.

## Datos mostrados

- Nombre completo o username.
- Username.
- Email interno.
- Puesto.
- Teléfono móvil o fijo si existen.
- Ubicación si existe.
- Estado de credencial.
- Fecha de emisión o creación.
- Metadata segura de foto activa si existe.

## Datos prohibidos

No se muestran:

- `password_hash`;
- token plano;
- `token_hash`;
- datos de `credencial_tokens`;
- roles;
- permisos internos;
- IDs internos sensibles;
- `ruta_relativa`;
- rutas físicas;
- `storage/uploads`;
- QR;
- URL pública de verificación.

## Permiso usado

- `credencial.ver`

El permiso ya pertenece al contrato de PERFIL-VCARD-DB-1 y se valida con `PermissionMiddleware`.

## Fuera de alcance

CREDENCIAL-VISUAL-1 no implementa:

- `/credencial/verificar/{token}`;
- token público;
- firma criptográfica;
- vCard pública;
- productos, precios o inventario;
- migraciones;
- seeds.

## Comandos de validación

```bash
php -l app/Domain/Credentials/CredentialValidationException.php
php -l app/Domain/Credentials/CredentialService.php
php -l app/Infrastructure/Repositories/UserCredentialRepository.php
php -l app/Http/Controllers/CredentialController.php
php -l app/Views/credentials/show.php
php -l routes/web.php
php -l bootstrap/app.php
php -l app/Views/layouts/app.php
php -l database/credencial-visual.php
php -l database/tests/credencial_visual_1_test.php
git diff --check
php database/credencial-visual.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

## Evidencia esperada

- `GET /perfil/credencial` existe.
- Usuario sin sesión redirige a `/login`.
- Usuario autenticado sin `credencial.ver` recibe 403.
- Usuario con `credencial.ver` recibe 200.
- `asegurarCredencial()` crea credencial base.
- `asegurarCredencial()` es idempotente.
- Usuario inactivo o inexistente es rechazado.
- HTML no expone secretos, tokens, rutas privadas ni roles/permisos.
- No existen rutas públicas de verificación de credencial.
- Datos QA revertidos por transacción.

Después de `CREDENCIAL-TOKEN-QR-1`, puede existir QR privado autenticado y token hash en base de datos.

Después de `CREDENCIAL-VERIFICACION-PUBLICA-1` y `CREDENCIAL-HARDENING-1`, la verificación pública existe solo mediante token/QR activo. La vista privada de credencial no imprime token plano, `token_hash`, rutas privadas ni archivos de storage.

## Siguiente fase sugerida

- `CREDENCIAL-TOKEN-QR-1`
