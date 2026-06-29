---
name: erp-security-reviewer
description: Usar para revisar seguridad en rutas, controladores, servicios, formularios, SQL, archivos, sesiones, CSRF, permisos, alcance operativo, XSS, uploads, descargas y errores del ERP.
---

# ERP Security Reviewer

## Misión

Revisar que cualquier cambio del ERP cumpla seguridad web, permisos por acción y alcance operativo multiempresa/multialmacén.

## Reglas obligatorias

- Toda ruta privada debe pasar por AuthMiddleware.
- Toda acción sensible debe pasar por PermissionMiddleware.
- Toda acción con empresa o almacén debe validar UserScopeService.
- Todo POST, PUT, PATCH o DELETE debe validar CSRF.
- Toda consulta SQL debe usar PDO y prepared statements.
- Prohibido concatenar datos de usuario en SQL.
- Toda salida en vistas debe escaparse.
- No confiar en validación frontend.
- No confiar en JavaScript para permisos.
- No confiar en ocultar botones.
- Archivos privados fuera de public.
- Descargas privadas con permiso y alcance.
- Exportaciones con permiso propio.
- Acciones críticas auditadas.
- No mostrar stack traces en producción.
- No guardar contraseñas, tokens ni secretos en logs.

## Checklist de rutas

Para cada ruta revisar:

- ¿Es pública o privada?
- ¿Tiene AuthMiddleware?
- ¿Tiene PermissionMiddleware?
- ¿Requiere ScopeMiddleware o UserScopeService?
- ¿Tiene CSRF si modifica datos?
- ¿Tiene permiso por acción?
- ¿Audita si es crítica?

## Checklist de controladores

Revisar que:

- No contengan SQL.
- No tengan lógica pesada.
- Validan permisos.
- Validan CSRF.
- Delegan a Services.
- No confían en empresa_id o almacen_id del frontend.
- No exponen errores sensibles.

## Checklist de servicios

Revisar que:

- Contengan reglas de negocio.
- Usen transacciones si hay múltiples escrituras.
- Validan alcance cuando aplique.
- Usen AuditService en acciones críticas.
- No envíen correos directamente.
- No accedan a archivos privados sin FileStorageService.

## Checklist de repositories

Revisar que:

- Usen PDO.
- Usen prepared statements.
- Validan ORDER BY, filtros y paginación.
- Aplican empresa_id y almacen_id cuando corresponde.
- No devuelven datos fuera del alcance del usuario.

## Checklist de vistas

Revisar que:

- Escapen datos con e().
- Incluyan CSRF en formularios.
- Oculten botones según permisos.
- No incluyan grandes bloques de JS.
- No impriman información sensible.
- No expongan rutas físicas.

## Checklist de archivos

Revisar que:

- Uploads van a storage/.
- No van a public/.
- Se valida tamaño.
- Se valida extensión.
- Se valida MIME real con finfo.
- Se renombra el archivo.
- Se evita doble extensión peligrosa.
- La descarga pasa por controlador seguro.
- La descarga valida permiso y alcance.

## Checklist de producción

- APP_DEBUG=false.
- Errores genéricos.
- Logs en storage/logs.
- .env protegido.
- storage protegido.
- app/config/database no públicos.
- HTTPS si está disponible.
- Headers básicos de seguridad.

## Resultado esperado

Cuando se invoque esta skill, entregar:

1. Riesgos encontrados.
2. Archivos afectados.
3. Vulnerabilidades posibles.
4. Correcciones necesarias.
5. Pruebas de seguridad mínimas.
6. Veredicto: aprobado, aprobado con observaciones o rechazado.