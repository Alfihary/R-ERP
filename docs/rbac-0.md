# RBAC-0 — Autorización base

## Alcance

RBAC-0 incorpora autorización por permisos estructurales sobre las tablas
aprobadas en DB-CORE-0. No introduce dashboard, menús, empresas, almacenes,
alcance operativo ni módulos funcionales.

## Permisos estructurales

| Código | Módulo | Uso en RBAC-0 |
|---|---|---|
| `sistema.acceder` | `sistema` | Base estructural de acceso autenticado |
| `sistema.app.ver` | `sistema` | Protege `GET /app` |
| `seguridad.rbac.ver` | `seguridad` | Reserva la lectura de autorización base |

El seed `rbac_0_seed_base_permissions` crea o reactiva exclusivamente estos
permisos y los asigna al rol estructural `ADMIN`. La clave primaria de
`rol_permisos` y la lógica del seed impiden relaciones duplicadas. Ejecutarlo
varias veces conserva tres permisos y tres asignaciones.

## Resolución de autorización

`PermissionService` delega la consulta en `PermissionRepository`. Un permiso se
concede solo cuando todas estas condiciones se cumplen:

1. el usuario está activo y no eliminado;
2. su asignación de rol está activa y no eliminada;
3. el rol está activo y no eliminado;
4. la relación rol-permiso está activa y no eliminada;
5. el permiso solicitado está activo y no eliminado.

No existe bypass por nombre de rol. `ADMIN` recibe permisos mediante relaciones
explícitas. Los permisos no se guardan en sesión, por lo que una desactivación
se refleja en la siguiente petición.

`PermissionMiddleware` redirige a `/login` cuando no hay usuario autenticado y
responde `403` cuando el usuario no posee el permiso requerido. `GET /app`
mantiene `AuthMiddleware` y exige `sistema.app.ver`.

## Ejecución controlada

```powershell
php database/rbac.php seed --database=<db-test> --confirm-database=<db-test>
php database/rbac.php db:test --database=<db-test> --confirm-database=<db-test>
```

El CLI rechaza producción y exige que el nombre configurado de la base coincida
con las dos confirmaciones. DB-TEST prueba permiso inexistente, rol inactivo,
permiso inactivo y duplicados dentro de una transacción que siempre revierte
los cambios temporales.

## DB-TEST y criterios de aceptación

SELECT de verificación:

```sql
SELECT p.codigo, p.activo, rp.activo AS asignacion_activa
FROM permisos p
INNER JOIN rol_permisos rp ON rp.permiso_id = p.id
INNER JOIN roles r ON r.id = rp.rol_id
WHERE r.codigo = 'ADMIN'
ORDER BY p.codigo;
```

La inserción válida es la primera ejecución controlada del seed. Debe crear
tres permisos estructurales y tres relaciones con `ADMIN`. La segunda ejecución
debe conservar esos mismos conteos.

Los INSERT inválidos de DB-TEST intentan repetir `sistema.app.ver` y su relación
con `ADMIN`. Ambos deben fallar por las restricciones únicas de DB-CORE-0. Un
error distinto de clave duplicada se interpreta como fallo de infraestructura,
no como aceptación del caso negativo.

Criterios:

- tres permisos persistentes, activos y no eliminados;
- tres relaciones activas con `ADMIN`;
- cero códigos o relaciones duplicados;
- permiso inexistente rechazado;
- rol o permiso inactivo rechazado;
- cambios temporales revertidos al finalizar DB-TEST.

## Rollback

Retirar el middleware, servicio, repositorio, vista y wiring devuelve `/app` a
autenticación simple. Los permisos y relaciones persistentes no deben borrarse
manualmente sin revisar dependencias.

Riesgo de rollback: medio. Un rollback parcial podría dejar `/app` inaccesible
o retirar controles de autorización.

## Pendiente

- `UserScopeService`.
- Empresas y almacenes.
- Alcance operativo.
- Matriz de permisos por módulo.
- Administración de roles y permisos.
- Auditoría funcional de cambios RBAC.

## Fuera de alcance

- Dashboard o layout administrativo.
- Menús y temas.
- Productos, inventario, tickets, compras, ventas, CXC o CXP.
- Deploy y configuración de producción.
