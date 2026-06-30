# DB-SCOPE-1 — Empresas, almacenes y alcance persistente

## Objetivo

Crear la estructura normalizada para empresas, almacenes y asignaciones de
alcance a usuarios. `UserScopeService` se implementa posteriormente en
SCOPE-SERVICE-1; DB-SCOPE-1 no incluye selectores UI, dashboard ni módulos
funcionales.

## Tablas

### `empresas`

- PK: `id`.
- Código estructural único: `codigo`.
- Nombre: `nombre`.
- Estado y borrado lógico: `activo`, `eliminado_en`, `eliminado_por`.
- Auditoría: `creado_en`, `actualizado_en`, `creado_por`,
  `actualizado_por`.

### `almacenes`

- PK: `id`.
- Cada almacén pertenece a una empresa mediante `empresa_id`.
- Código único dentro de su empresa: `(empresa_id, codigo)`.
- Índice único técnico `(empresa_id, id)` para validar la empresa del almacén
  desde `usuario_almacenes`.
- Estado, borrado lógico y auditoría estándar.

### `usuario_empresas`

- PK compuesta: `(usuario_id, empresa_id)`.
- Representa empresas permitidas al usuario.
- FKs con `usuarios` y `empresas`.
- Estado, borrado lógico y auditoría estándar.

### `usuario_almacenes`

- PK compuesta: `(usuario_id, almacen_id)`.
- Conserva `empresa_id` para validar consistencia en la propia base.
- FK directa con usuario y almacén.
- FK compuesta `(usuario_id, empresa_id)` hacia `usuario_empresas`.
- FK compuesta `(empresa_id, almacen_id)` hacia `almacenes`.
- Estado, borrado lógico y auditoría estándar.

Las dos FKs compuestas impiden asignar un almacén de otra empresa o asignarlo
sin que el usuario tenga previamente acceso a su empresa.

## Índices principales

| Tabla | Índice | Finalidad |
|---|---|---|
| `empresas` | `uq_empresas_codigo` | Código global único |
| `almacenes` | `uq_almacenes_empresa_codigo` | Código único por empresa |
| `almacenes` | `uq_almacenes_empresa_id` | FK compuesta empresa-almacén |
| `usuario_empresas` | `PRIMARY` | Evitar asignación duplicada |
| `usuario_empresas` | `idx_usuario_empresas_empresa_activo` | Usuarios activos por empresa |
| `usuario_almacenes` | `PRIMARY` | Evitar almacén duplicado por usuario |
| `usuario_almacenes` | `idx_usuario_almacenes_usuario_empresa` | FK y consulta por usuario/empresa |
| `usuario_almacenes` | `idx_usuario_almacenes_empresa_almacen` | FK empresa-almacén |
| `usuario_almacenes` | `idx_usuario_almacenes_almacen_activo` | Usuarios activos por almacén |

## Seed estructural

El seed `db_scope_1_seed_initial_scope` crea de forma idempotente:

- Empresa `grupo-refrigerantes` — Grupo Refrigerantes.
- Almacén `principal` — Almacén Principal.
- Una asignación empresa al administrador inicial configurado.
- Una asignación almacén al mismo administrador.

No crea usuarios, permisos, clientes, proveedores, productos, inventario ni
datos demo masivos.

## Ejecución controlada

```powershell
php database/scope.php migrate --database=<db-test> --confirm-database=<db-test>
php database/scope.php seed --database=<db-test> --confirm-database=<db-test>
php database/scope.php db:test --database=<db-test> --confirm-database=<db-test>
php database/scope.php status --database=<db-test> --confirm-database=<db-test>
```

El runner rechaza producción y exige que la base configurada coincida con las
dos confirmaciones.

## SELECT de verificación

```sql
SELECT
    u.username,
    e.codigo AS empresa_codigo,
    a.codigo AS almacen_codigo
FROM usuario_almacenes ua
INNER JOIN usuarios u ON u.id = ua.usuario_id
INNER JOIN usuario_empresas ue
    ON ue.usuario_id = ua.usuario_id
   AND ue.empresa_id = ua.empresa_id
INNER JOIN empresas e ON e.id = ua.empresa_id
INNER JOIN almacenes a
    ON a.id = ua.almacen_id
   AND a.empresa_id = ua.empresa_id
WHERE ua.activo = 1
  AND ua.eliminado_en IS NULL;
```

## DB-TEST-SCOPE

DB-TEST valida:

1. DB-CORE-0 y los tres permisos RBAC-0.
2. Las cuatro tablas, InnoDB y `utf8mb4_unicode_ci`.
3. PKs, índices y FKs críticas.
4. Conteos persistentes del seed.
5. Inserción válida de empresa, almacén y alcance dentro de una transacción.
6. Rechazo de empresa y almacén duplicados.
7. Rechazo de almacén huérfano.
8. Rechazo de usuario inexistente.
9. Rechazo de asignación duplicada.
10. Rechazo de almacén perteneciente a otra empresa.
11. Rechazo de almacén sin empresa permitida al usuario.
12. Rechazo de estados fuera de `0` y `1`.
13. Rollback de todos los datos transitorios.

Los errores esperados son clave duplicada, FK inválida o `CHECK` incumplido.
Cualquier otro error invalida DB-TEST.

## Criterios de aceptación

- Una empresa estructural persistente.
- Un almacén estructural persistente.
- Una asignación empresa y una asignación almacén para el administrador.
- Cero duplicados.
- Cero relaciones huérfanas.
- Consistencia usuario-empresa-almacén reforzada por FKs compuestas.
- Ningún permiso o usuario nuevo.
- Datos transitorios revertidos.

## Rollback

Orden estructural:

1. `usuario_almacenes`.
2. `usuario_empresas`.
3. `almacenes`.
4. `empresas`.

Todas las relaciones de negocio usan `RESTRICT`; no existen cascadas
destructivas. El rollback es de riesgo alto porque elimina estructura de
alcance y solo debe ejecutarse en una base controlada y sin dependencias
posteriores.

## Continuidad en `UserScopeService`

- SCOPE-SERVICE-1 resuelve empresas y almacenes efectivos.
- Excluye estados inactivos o eliminados en cada nivel.
- La validación de recursos y destinos operativos queda para cada módulo futuro.
- El contexto activo y los selectores continúan pendientes.

## Fuera de alcance

- Selector de empresa o almacén.
- Dashboard y módulos funcionales.
- Productos, inventario, tickets, compras, ventas, CXC o CXP.
- Clientes, proveedores, catálogos y reportes.
- Deploy o cambios de producción.
