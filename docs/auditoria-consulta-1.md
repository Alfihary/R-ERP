# AUDITORIA-CONSULTA-1

## Objetivo

Crear una pantalla privada read-only para consultar eventos de `auditoria_eventos`.

## Ruta

- `GET /auditoria`

No se crean rutas `POST`, `PUT`, `PATCH` ni `DELETE` para auditoria.

## Permiso usado

Se usa temporalmente el permiso existente:

- `seguridad.rbac.ver`

Decision: `auditoria.ver` no existe y la fase no autoriza modificar seeds. `seguridad.rbac.ver` es el permiso administrativo existente mas cercano para lectura de seguridad/RBAC.

## Componentes creados

- `App\Http\Controllers\AuditController`
- `App\Infrastructure\Repositories\AuditQueryRepository`
- `app/Views/audit/index.php`
- `public/css/modules/audit.css`
- `database/auditoria-consulta.php`
- `database/tests/auditoria_consulta_1_test.php`

## Filtros implementados

- `accion`
- `resultado`
- `actor_usuario_id`
- `fecha_desde`
- `fecha_hasta`
- `q`
- `page`
- `per_page`

`per_page` tiene maximo 100 y default 25. Fechas invalidas se ignoran de forma controlada.

## Seguridad

- La ruta requiere sesion.
- La ruta requiere permiso.
- La vista es read-only.
- No hay botones de editar, borrar o exportar.
- No hay formularios POST.
- SQL usa PDO y prepared statements.
- Metadata y demas datos se escapan con `e()`.
- `AuditQueryRepository` vuelve a redactar metadata sensible antes de mostrarla.
- La consulta no inserta ni modifica eventos.

## Excluido

AUDITORIA-CONSULTA-1 no crea:

- migraciones;
- seeds;
- permisos nuevos;
- exportaciones;
- edicion o borrado de eventos;
- UI avanzada de reportes;
- dashboard;
- cambios funcionales en productos, precios, inventario, vCard o credenciales.

## Prueba

```bash
php database/auditoria-consulta.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

La prueba valida ruta, permisos, filtros, paginacion, orden descendente, metadata escapada/redactada, ausencia de rutas de escritura y rollback transaccional.
