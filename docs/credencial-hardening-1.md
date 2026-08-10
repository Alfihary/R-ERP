# CREDENCIAL-HARDENING-1

## Objetivo

Endurecer la verificación pública de credenciales sin cambiar el flujo principal ya cerrado.

La fase refuerza:

- anti-enumeración pública;
- validación estricta del token;
- rate limit básico;
- auditoría pública mínima;
- headers de seguridad;
- manejo uniforme de errores;
- pruebas de no filtrado.

## Anti-enumeración

`GET /credencial/verificar/{token}` mantiene el mismo comportamiento público:

- token válido: `200`;
- token inválido: `404`;
- token inexistente: `404`;
- token revocado: `404`;
- token anterior tras renovación: `404`;
- token de usuario inactivo: `404`;
- token de usuario eliminado: `404`;
- token de credencial inactiva: `404`.

Los casos inválidos usan el mismo cuerpo 404 genérico. La respuesta no indica si el token existe, fue revocado, expiró o pertenece a una credencial/usuario no vigente.

## Validación de token

El token público conserva el formato existente:

```text
^[a-f0-9]{64}$
```

No se normaliza silenciosamente. Se rechazan espacios iniciales o finales, caracteres reservados, unicode fuera del patrón y longitudes incorrectas.

## Rate limit

Se implementó un rate limit mínimo en memoria dentro de `CredentialVerificationService`:

```text
30 intentos por IP cada 5 minutos
```

Al exceder el límite, la ruta responde `429 Too Many Requests` con cuerpo genérico.

Riesgo residual: el rate limit es por proceso PHP. En servidores con múltiples procesos o reinicios frecuentes, el conteo no es persistente. No se creó migración ni tabla nueva porque la fase lo prohíbe.

## Auditoría

Se registra auditoría pública mínima en `auditoria_eventos` si la tabla existe:

- `credencial.verificacion.publica.ok`;
- `credencial.verificacion.publica.fail`;
- `credencial.verificacion.publica.rate_limited`.

La metadata solo guarda:

```json
{"surface":"public_credential_verification"}
```

No se registra token plano, `token_hash`, URL completa, path con token ni datos sensibles.

La auditoría privada de renovar/revocar/ver/descargar QR queda pendiente porque todavía no existe un `AuditService` central reutilizable. Agregar SQL directo al controlador privado duplicaría responsabilidad.

`PERFIL-CREDENCIAL-FOTO-1` agrega un endpoint privado para la foto de la
credencial visual, pero mantiene la superficie pública endurecida sin foto ni
endpoint público de imagen de credencial.

## Headers de seguridad

La ruta pública aplica los mismos headers en `200`, `404` y `429`:

- `Cache-Control: no-store`;
- `X-Content-Type-Options: nosniff`;
- `Referrer-Policy: strict-origin-when-cross-origin`;
- `X-Frame-Options: DENY`.

## Datos que no se exponen

La vista pública no debe mostrar token, `token_hash`, `credencial_tokens`, `password_hash`, roles, permisos, IDs internos, `ruta_relativa`, `storage/uploads`, email, teléfonos, foto, productos, precios, stock ni costos.

## Comandos de validación

```bash
php -l app/Domain/Credentials/CredentialVerificationService.php
php -l app/Domain/Credentials/CredentialTokenService.php
php -l app/Http/Controllers/PublicCredentialController.php
php -l app/Http/Controllers/CredentialController.php
php -l app/Views/credentials/verify.php
php -l app/Views/credentials/show.php
php -l routes/web.php
php -l bootstrap/app.php
php -l database/credencial-hardening.php
php -l database/tests/credencial_hardening_1_test.php
php -l database/tests/credencial_verificacion_publica_1_test.php
php -l database/tests/credencial_token_qr_1_test.php
php -l database/tests/credencial_visual_1_test.php
git diff --check
php database/credencial-hardening.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

## Evidencia esperada

- Token válido responde `200`.
- Invalidaciones responden `404` uniforme.
- Rate limit responde `429`.
- Headers seguros presentes en `200`, `404` y `429`.
- Auditoría pública no contiene token plano ni hash.
- QR privado sigue resolviendo a `/credencial/verificar/{token}`.
- Renovar/revocar siguen funcionando.
- vCard pública/QR/VCF/foto/productos siguen funcionando.
- Datos QA revertidos por rollback.

## Siguiente fase sugerida

Definir un `AuditService` central reutilizable para auditar acciones privadas de credencial y futuras acciones críticas sin duplicar SQL en controladores.
