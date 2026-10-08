# DB-CORE-0 — Identidad, roles, permisos y auditoría

## Estado

- Fase autorizada: `DB-CORE-0`.
- Artefactos implementados: configuración, conexión PDO, runner, migración,
  seed y DB-TEST.
- Ejecución real: aprobada el 2026-06-29 sobre `r_erp_db_core_0_test`.
- Servidor validado: MySQL 8.0.38.
- Producción: prohibida por el runner.

## Objetivo

Crear la estructura mínima de identidad y autorización sobre la que podrán
construirse fases posteriores. Esta fase no autentica usuarios, no concede
permisos y no expone la base mediante rutas web.

## Tablas

### `schema_migrations`

Tabla técnica del runner. Registra el identificador y fecha de cada migración
aplicada. No contiene datos de negocio.

### `usuarios`

- PK `id` BIGINT unsigned.
- `username` obligatorio y único, de 3 a 50 caracteres.
- `username` usa charset ASCII y collation binaria para impedir acentos y
  distinguir mayúsculas.
- La BD acepta únicamente `a-z`, `0-9`, punto, guion bajo y guion medio.
- AUTH-0 deberá normalizar el username a minúsculas antes de persistirlo; la BD
  exige `username = LOWER(username)` y rechaza valores no normalizados.
- `email` obligatorio y único.
- `password_hash` obligatorio con longitud mínima compatible con hashes
  modernos.
- `activo` restringido a 0 o 1.
- Último acceso nullable.
- Auditoría de creación, actualización y eliminación lógica.
- No contiene roles, empresas, almacenes ni perfil extendido.

No se crea ningún usuario mediante seed. AUTH-0 definirá el alta segura del
primer administrador.

### `roles`

- PK `id`.
- `codigo` único y estable.
- Nombre, descripción, estado y marca de rol de sistema.
- Auditoría y eliminación lógica.

El seed estructural crea únicamente `ADMIN`, activo y marcado como sistema.

### `permisos`

- PK `id`.
- `codigo` único.
- Módulo, nombre, descripción, estado y marca de sistema.
- Auditoría y eliminación lógica.

DB-CORE-0 no crea permisos funcionales. Cada módulo deberá aportar sus permisos
mediante un seed propio en una fase autorizada.

### `usuario_roles`

- PK compuesta `(usuario_id, rol_id)`.
- FKs hacia usuario y rol con `ON DELETE RESTRICT`.
- Estado, auditoría y eliminación lógica.
- La PK impide asignaciones duplicadas.

### `rol_permisos`

- PK compuesta `(rol_id, permiso_id)`.
- FKs hacia rol y permiso con `ON DELETE RESTRICT`.
- Estado, auditoría y eliminación lógica.
- La PK impide asignaciones duplicadas.

### `auditoria_eventos`

- PK `id`.
- Actor nullable con `ON DELETE SET NULL` para preservar historia.
- Acción, entidad, identificador, resultado, IP y user agent.
- Metadata JSON.
- Índices por actor/fecha, acción/fecha, entidad/recurso y fecha.
- Es append-only en esta fase: no contiene columnas de actualización o
  eliminación.

Nunca debe almacenar contraseñas, tokens, cookies, secretos o cuerpos privados
completos.

## Motor, charset y relaciones

- Motor: InnoDB.
- Charset: `utf8mb4`.
- Collation: `utf8mb4_unicode_ci`.
- Relaciones críticas: `RESTRICT`.
- Referencias históricas de actor: `SET NULL`.
- No hay `CASCADE` destructivo.
- No existen empresas, almacenes o columnas de alcance en esta fase.

## Configuración

Variables no secretas documentadas en `.env.example`:

```text
APP_DB_HOST
APP_DB_PORT
APP_DB_NAME
APP_DB_USER
APP_DB_PASSWORD
APP_DB_CHARSET
APP_DB_COLLATION
```

El archivo `.env` real permanece ignorado. El runner nunca imprime DSN,
usuario, contraseña ni detalles del driver que puedan exponer credenciales.

## Runner

El runner solo funciona mediante CLI:

```powershell
php database/console.php <comando> --database=<nombre> --confirm-database=<nombre>
```

Controles:

- Rechaza `APP_ENV=production`.
- Exige que ambos nombres CLI sean iguales.
- Exige que coincidan exactamente con `APP_DB_NAME`.
- No crea bases de datos.
- No se invoca desde rutas públicas.
- Rechaza tablas core preexistentes antes de iniciar la migración.
- Nunca ejecuta rollback automático después de un fallo DDL.

Secuencia autorizada después de confirmar la base:

```powershell
php database/console.php migrate --database=<db-test> --confirm-database=<db-test>
php database/console.php seed --database=<db-test> --confirm-database=<db-test>
php database/console.php db:test --database=<db-test> --confirm-database=<db-test>
php database/console.php status --database=<db-test> --confirm-database=<db-test>
```

## DB-TEST-CORE

El test verifica:

1. Nombre exacto de la base activa.
2. Versión del servidor.
3. Existencia de siete tablas, incluida la tabla técnica.
4. InnoDB y `utf8mb4_unicode_ci`.
5. PK, índices únicos y FKs mínimas.
6. Seed `ADMIN`.
7. Ausencia de usuarios y permisos sembrados.
8. INSERT válido de usuario, rol, permiso, relaciones y auditoría.
9. Fallos esperados:
   - email duplicado;
   - username duplicado;
   - username nulo;
   - username con espacios;
   - username con acentos;
   - username con caracteres no permitidos;
   - username con mayúsculas no normalizadas;
   - username menor a 3 caracteres;
   - username mayor a 50 caracteres;
   - `password_hash` nulo;
   - estado de usuario inválido;
   - código de rol duplicado;
   - código de permiso duplicado;
   - relaciones duplicadas;
   - FKs inexistentes.
10. Metadata JSON válida en auditoría.
11. `SHOW CREATE TABLE` de cada tabla.
12. Limpieza de todos los datos de prueba mediante rollback transaccional.

Los hashes usados por DB-TEST se generan en memoria y nunca se versionan.

## Resultado ejecutado

DB-TEST-CORE fue aprobado con los siguientes resultados:

- Siete tablas presentes.
- Todas las tablas usan InnoDB y `utf8mb4_unicode_ci`.
- Migración `db_core_0_001_create_core_identity_tables` registrada.
- Seed ADMIN activo y marcado como sistema.
- Cero usuarios y cero permisos funcionales persistentes.
- Índices únicos de email, username, roles y permisos confirmados.
- FKs críticas confirmadas.
- INSERT válido con `username=qa.user001`.
- Diecisiete casos inválidos rechazados.
- Evento de auditoría y metadata JSON válidos.
- Datos transitorios revertidos mediante transacción.
- Esquema y seed conservados para revisión.

Evidencia específica de `usuarios.username`:

```text
type       = varchar(50)
charset    = ascii
collation  = ascii_bin
nullable   = NO
index      = uq_usuarios_username
unique     = YES
```

El servidor devuelve el código `3988` al rechazar caracteres que no pueden
convertirse al charset ASCII; DB-TEST lo reconoce como fallo esperado.

## Interpretación de errores

- `1048`: columna obligatoria recibió NULL.
- `1062`: índice o PK duplicada.
- `1366`: carácter incompatible con el charset ASCII de `username`.
- `1406`: valor mayor a la longitud permitida.
- `1452`: FK inexistente.
- `3819` o `4025`: restricción CHECK rechazada.
- `3988`: conversión rechazada hacia el charset ASCII de `username`.

Cualquier código distinto durante un caso negativo hace fallar DB-TEST.

## Rollback

Primero se revierte el seed cuando no tiene relaciones:

```powershell
php database/console.php seed:rollback --database=<db-test> --confirm-database=<db-test>
```

Después se eliminan las seis tablas de negocio en orden inverso:

```powershell
php database/console.php rollback --database=<db-test> --confirm-database=<db-test>
```

`schema_migrations` permanece como infraestructura del runner. El rollback es
de riesgo medio porque elimina estructura y no debe ejecutarse sobre una base
con datos útiles. Si la base no es vacía y descartable, se requiere respaldo y
una autorización separada.

MySQL confirma algunas sentencias DDL de forma implícita. Si una migración falla
después de crear parcialmente la estructura, el runner conserva el estado para
diagnóstico y no intenta borrar objetos automáticamente. La limpieza exige
inspección y autorización explícita.

## Criterios de aceptación

- Runner y configuración no exponen secretos.
- Migración reproducible sobre una base MySQL vacía.
- Seed idempotente y sin usuario administrador.
- Todas las tablas usan InnoDB y la collation aprobada.
- Restricciones válidas aceptan datos correctos.
- Casos inválidos fallan con errores esperados.
- DB-TEST deja cero datos transitorios.
- CONFIG-0 y SECURITY-0 continúan funcionando.
- No se crean rutas, login, dashboard, empresas, almacenes o módulos.

## Fuera de alcance

- Crear la base de pruebas.
- Ejecutar contra producción.
- Login, logout o recuperación.
- AuthMiddleware o PermissionMiddleware funcional.
- Empresas, almacenes y alcance.
- Productos, inventario, tickets, compras, ventas, CXC o CXP.
- AuditService funcional.
- Deploy.
