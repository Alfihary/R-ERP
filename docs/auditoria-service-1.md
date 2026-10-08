# AUDITORIA-SERVICE-1

## Objetivo

Centralizar la escritura de eventos en `auditoria_eventos` mediante un servicio reutilizable y evitar SQL directo duplicado en flujos de credencial.

## Componentes creados

- `App\Domain\Audit\AuditService`
- `App\Infrastructure\Repositories\AuditRepository`
- `database/auditoria-service.php`
- `database/tests/auditoria_service_1_test.php`

## Esquema existente usado

AUDITORIA-SERVICE-1 usa la tabla existente `auditoria_eventos` sin modificar migraciones ni columnas.

Columnas usadas:

- `actor_usuario_id`
- `accion`
- `entidad`
- `entidad_id`
- `resultado`
- `ip`
- `user_agent`
- `metadata_json`
- `creado_en`

Si una auditoria ideal requiere columnas adicionales, queda pendiente para una fase DB futura. En esta fase se persiste solo lo compatible con el esquema actual.

## Eventos integrados

Eventos publicos de credencial:

- `credencial.verificacion.publica.ok`
- `credencial.verificacion.publica.fail`
- `credencial.verificacion.publica.rate_limited`

Eventos privados de credencial:

- `credencial.token.renovar`
- `credencial.token.revocar`
- `credencial.qr.ver`
- `credencial.qr.descargar`
- `credencial.foto.ver`

## Eventos pendientes

No quedan eventos pendientes dentro del alcance minimo de credenciales autorizado para esta fase.

Queda pendiente que futuros modulos operativos integren `AuditService` cuando implementen acciones sensibles. Esta fase no agrega auditoria a productos, precios, inventario, ventas, CFDI ni dashboard.

## Sanitizacion de metadata

`AuditService` separa los campos estructurales (`entidad`, `entidad_id`, `resultado`, `ip`, `user_agent`) de `metadata_json` y sanitiza metadata antes de persistirla.

No deben guardarse en metadata valores sensibles como:

- tokens o hashes;
- contrasenas o hashes de contrasena;
- cookies, sesiones, CSRF o secretos;
- rutas internas, `storage/uploads`, rutas absolutas o URL/URI sensibles.

Los valores sensibles se reemplazan por `[REDACTED]`.

## Comportamiento ante fallos

La auditoria no debe romper el flujo funcional principal. Si la tabla no existe o una escritura falla, `AuditService` absorbe la excepcion y el flujo continua.

## Comandos ejecutados / esperados

```bash
php -l app/Domain/Audit/AuditService.php
php -l app/Infrastructure/Repositories/AuditRepository.php
php -l app/Domain/Credentials/CredentialVerificationService.php
php -l app/Domain/Credentials/CredentialTokenService.php
php -l app/Http/Controllers/CredentialController.php
php -l app/Http/Controllers/PublicCredentialController.php
php -l bootstrap/app.php
php -l database/auditoria-service.php
php -l database/tests/auditoria_service_1_test.php
git diff --check
php database/auditoria-service.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

## Evidencia esperada

- `AuditService` y `AuditRepository` existen.
- El DB-TEST crea eventos transitorios y revierte por transaccion.
- `CredentialVerificationService` ya no contiene `INSERT INTO auditoria_eventos`.
- Los eventos publicos y privados de credencial se registran sin token plano, `token_hash`, `password_hash`, rutas privadas ni `storage/uploads`.
- La metadata sensible se redacta con `[REDACTED]`.

## Riesgos residuales

- La auditoria es best-effort: si falla, no bloquea la operacion principal.
- La tabla actual no incluye columnas especializadas para severidad, modulo o correlation id.
- Las futuras acciones criticas de modulos operativos deben integrarse explicitamente con `AuditService`.

## Alcance excluido

AUDITORIA-SERVICE-1 no crea:

- migraciones;
- seeds;
- UI de auditoria;
- buscador de auditoria;
- exports;
- dashboard;
- notificaciones;
- integracion con modulos de productos, precios, inventario, ventas o CFDI.

## Prueba

Ejecutar:

```bash
php database/auditoria-service.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

La prueba valida escritura publica y privada, sanitizacion de metadata, ausencia de SQL directo en `CredentialVerificationService`, eventos minimos de credencial y rollback transaccional de datos QA.

## Siguiente fase sugerida

Una fase posterior puede definir una politica transversal de auditoria por modulo, con catalogo de eventos, severidad, correlation id y reportes privados de consulta.
