# SCOPE-SERVICE-1 — Resolución de alcance efectivo

## Objetivo

Resolver en backend las empresas y almacenes efectivos de un usuario a partir
de DB-SCOPE-1. Esta fase no crea selector visual, contexto activo persistente,
dashboard ni módulos funcionales.

## Componentes

### `ScopeRepository`

Encapsula las consultas PDO preparadas. La conexión permanece perezosa porque
el repositorio conserva `ConnectionProvider` y solicita PDO únicamente cuando
se resuelve un usuario.

Las consultas exigen:

- Usuario activo y no eliminado.
- Relación usuario-empresa activa y no eliminada.
- Empresa activa y no eliminada.
- Relación usuario-almacén activa y no eliminada.
- Almacén activo y no eliminado.
- Coincidencia entre la empresa asignada y la empresa del almacén.

### `UserScopeService`

Recibe exclusivamente el ID del usuario autenticado. No recibe
`empresa_id`, `almacen_id`, Request ni datos del navegador.

El servicio devuelve `EffectiveScope`, con:

- `companies`
- `warehouses`
- `default_company`
- `default_warehouse`
- `has_companies`
- `has_warehouses`
- `has_scope`

Un ID inválido o un usuario sin asignaciones produce un alcance vacío
controlado. El servicio filtra defensivamente cualquier almacén cuya empresa no
forme parte de las empresas permitidas, incluso si un adaptador devolviera
datos inconsistentes.

`has_scope` solo es verdadero cuando existe al menos una empresa permitida y un
almacén permitido para la empresa predeterminada.

## Integración con `/app`

La ruta obtiene el ID desde `AuthService`, nunca desde parámetros de la
petición. La vista recibe una representación preparada del alcance y muestra:

- Empresa predeterminada.
- Almacén predeterminado.
- Cantidad de empresas permitidas.
- Cantidad de almacenes permitidos.

Todos los valores dinámicos se escapan con `e()`. La sección es informativa: no
permite seleccionar ni persistir contexto.

El alcance completo no se guarda en sesión. AUTH-0 conserva únicamente ID,
username y email.

## Prueba transaccional

```powershell
php database/scope-service.php db:test `
  --database=<db-test> `
  --confirm-database=<db-test>
```

El runner exige confirmación doble, rechaza producción y comprueba que la base
configurada sea exactamente la confirmada.

La prueba valida:

1. Administrador con una empresa y un almacén.
2. Usuario transitorio sin asignaciones con alcance vacío.
3. Empresa inactiva.
4. Almacén inactivo.
5. Relación usuario-empresa inactiva.
6. Relación usuario-almacén inactiva.
7. Empresa, almacén y relaciones eliminadas lógicamente.
8. Almacén devuelto fuera de una empresa permitida.
9. Parámetros de navegador incapaces de alterar la resolución.
10. Conteos persistentes sin cambios después del rollback.

## Seguridad

- Permiso y alcance siguen siendo controles independientes.
- El repositorio filtra alcance dentro de SQL.
- El servicio no confía en IDs del cliente.
- Las vistas no consultan base de datos.
- Los controladores o rutas no contienen SQL.
- No existe bypass para ADMIN.
- No se guardan listas de alcance en sesión.

## Pendientes

- SCOPE-CONTEXT-1 implementa selector y contexto activo de operación.
- Validación de recursos concretos de módulos futuros.
- Aplicación de filtros de alcance en repositorios operativos.
- Middleware de alcance únicamente cuando una ruta futura lo justifique.

## Fuera de alcance

- CRUD de empresas o almacenes.
- Dashboard, métricas y módulos funcionales.
- Productos, inventario, tickets, compras, ventas, CXC o CXP.
- Catálogos, reportes y menú dinámico.
- Migraciones, seeds, permisos nuevos o cambios de esquema.
- Integración MySQL de `/health`.
- Deploy y configuración de producción.
