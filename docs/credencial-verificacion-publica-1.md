# CREDENCIAL-VERIFICACION-PUBLICA-1

## Objetivo

Implementar la verificación pública mínima de credenciales internas mediante un token URL-safe previamente emitido por el flujo privado de credencial.

## Ruta pública

- `GET /credencial/verificar/{token}`

La ruta no requiere sesión autenticada ni CSRF porque es una superficie pública de consulta controlada. Todas las respuestas inválidas usan un 404 seguro y uniforme para no revelar si el token existe, fue revocado, expiró o pertenece a un usuario/credencial inactiva.

## Resolución del token

El token recibido debe cumplir el formato seguro:

```text
^[a-f0-9]{64}$
```

El sistema calcula `sha256(token)` y consulta exclusivamente por `token_hash`. El token plano no se almacena ni se imprime.

## Condiciones para considerar válida una credencial

La verificación pública solo responde 200 cuando se cumplen todas las condiciones:

- token activo;
- token no revocado;
- token no expirado;
- credencial con estatus `VIGENTE`;
- credencial no revocada;
- credencial no expirada;
- usuario activo;
- usuario no eliminado lógicamente.

Si cualquiera de esas condiciones falla, se responde 404 seguro.

## Datos públicos permitidos

La vista pública solo puede mostrar:

- estado público de verificación;
- nombre completo;
- puesto;
- ubicación laboral;
- fecha de emisión;
- fecha/hora de verificación.

Todos los valores dinámicos deben escaparse antes de imprimirse.

## Datos prohibidos

La vista pública no debe mostrar:

- token plano;
- `token_hash`;
- nombres de tablas internas como `credencial_tokens`;
- `password_hash`;
- roles;
- permisos;
- IDs internos;
- rutas privadas;
- `ruta_relativa`;
- `storage/uploads`;
- correo;
- teléfonos;
- foto;
- productos;
- precios;
- stock;
- costos.

## Headers de seguridad

La respuesta pública aplica headers defensivos:

- `Cache-Control: no-store`;
- `Referrer-Policy: strict-origin-when-cross-origin`;
- `X-Content-Type-Options: nosniff`;
- `X-Frame-Options: DENY`.

## Integración con fases previas

`CREDENCIAL-TOKEN-QR-1` ya generaba el payload futuro `/credencial/verificar/{token}` dentro del QR privado. Esta fase habilita la ruta pública que resuelve ese payload.

Los guardrails heredados de fases previas fueron ajustados únicamente para reconocer que la verificación pública ya existe como fase aprobada posterior. No se modificó funcionalidad de vCard pública.

## Fuera de alcance

- No implementa QR nuevo.
- No implementa VCF.
- No implementa credencial visual nueva.
- No modifica vCard pública funcionalmente.
- No modifica productos, precios ni inventario.
- No crea migraciones.
- No crea seeds.
- No crea endpoints JSON públicos.
- No agrega JavaScript.

## Validación

Comandos principales:

```bash
php -l app/Domain/Credentials/CredentialVerificationService.php
php -l app/Http/Controllers/PublicCredentialController.php
php -l app/Views/credentials/verify.php
php -l app/Infrastructure/Repositories/CredentialTokenRepository.php
php -l routes/web.php
php -l bootstrap/app.php
php -l database/credencial-verificacion-publica.php
php -l database/tests/credencial_verificacion_publica_1_test.php
git diff --check
php database/credencial-verificacion-publica.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

Cobertura esperada:

- token válido responde 200;
- token inexistente responde 404;
- token revocado responde 404;
- token previo tras renovación responde 404;
- token renovado responde 200;
- usuario inactivo responde 404;
- usuario eliminado lógicamente responde 404;
- credencial inactiva responde 404;
- token vacío/corto/largo/con caracteres inválidos responde 404;
- respuestas inválidas mantienen cuerpo uniforme;
- HTML no filtra datos sensibles;
- datos transitorios del test se revierten por rollback.
