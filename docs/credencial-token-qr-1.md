# CREDENCIAL-TOKEN-QR-1

## Objetivo

Implementar token verificable y QR privado para la credencial interna del usuario autenticado.

Esta fase prepara la base para una futura verificación pública, pero todavía no crea la ruta pública de verificación.

## Rutas privadas creadas

- `GET /perfil/credencial/qr`
- `GET /perfil/credencial/qr/descargar`
- `POST /perfil/credencial/token/renovar`
- `POST /perfil/credencial/token/revocar`

Todas las rutas requieren sesión activa. Las rutas POST pasan por CSRF global.

## Permisos usados

- `credencial.ver`
- `credencial.qr.ver`
- `credencial.qr.descargar`

`credencial.ver` conserva el acceso a la credencial visual privada. `credencial.qr.ver` permite renovar/revocar token y ver QR. `credencial.qr.descargar` permite descargar el PNG dinámico.

## Servicios creados

- `App\Domain\Credentials\CredentialTokenService`
- `App\Domain\Credentials\CredentialQrService`

## Repositorio creado

- `App\Infrastructure\Repositories\CredentialTokenRepository`

El repositorio usa PDO y prepared statements. No expone token plano ni `token_hash` hacia la vista.

## Mecanismo de token

El token plano se genera con:

```text
bin2hex(random_bytes(32))
```

El resultado es un valor URL-safe de 64 caracteres hexadecimales derivado de 32 bytes aleatorios.

La base de datos almacena únicamente:

- `token_hash = sha256(token_plano)`
- `token_prefix`, derivado del hash para estado técnico no sensible
- `credencial_id`
- `creado_en`
- `activo`
- `revocado_en`
- `expira_en` si existe en la tabla

No se guarda:

- token plano;
- `token`;
- `token_plano`;
- QR físico;
- URL pública funcional.

La fase usa SHA-256 porque todavía no existe un secreto de aplicación formalizado para HMAC/firma de credencial. Firma/HMAC queda fuera de alcance para una fase posterior si se autoriza.

## Renovación y revocación

Al renovar se revocan tokens activos previos de la misma credencial y se crea un nuevo token activo.

Al revocar se marca el token activo como:

- `activo = 0`
- `revocado_en = CURRENT_TIMESTAMP`

## Payload del QR

El QR privado contiene el path futuro:

```text
/credencial/verificar/{token}
```

La ruta pública todavía no existe. El QR solo se entrega dentro del área autenticada y con permiso.

## Manejo de token plano

Como el token plano no se guarda en DB, el QR solo puede generarse con el token recién emitido dentro de la sesión autenticada actual. Si existe un token activo pero el token plano no está disponible en la sesión actual, la vista pide renovar token.

## Fuera de alcance

- No crea `GET /credencial/verificar/{token}`.
- No implementa verificación pública.
- No expone `token_hash`.
- No persiste PNG QR.
- No modifica vCard pública.
- No modifica productos, precios ni inventario.
- No agrega dependencias externas.
- No agrega migraciones ni seeds.

## Comandos de validación

```bash
php -l app/Domain/Credentials/CredentialTokenService.php
php -l app/Domain/Credentials/CredentialQrService.php
php -l app/Infrastructure/Repositories/CredentialTokenRepository.php
php -l app/Domain/Credentials/CredentialService.php
php -l app/Http/Controllers/CredentialController.php
php -l app/Views/credentials/show.php
php -l routes/web.php
php -l bootstrap/app.php
php -l database/credencial-token-qr.php
php -l database/tests/credencial_token_qr_1_test.php
php -l database/tests/credencial_visual_1_test.php
git diff --check
php database/credencial-token-qr.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

## Evidencia esperada

- Token seguro generado.
- Solo existe hash en DB.
- Token anterior revocado al renovar.
- Token activo revocado al revocar.
- QR privado responde PNG.
- Descarga privada responde PNG.
- QR contiene payload futuro de verificación.
- No existe ruta pública de verificación.
- No se persiste QR físico.
- `token_hash` no aparece en HTML.
- Token plano no queda en DB.
- Regresiones de perfil/vCard/productos pasan.

## Siguiente fase sugerida

`CREDENCIAL-VERIFICACION-PUBLICA-1`
