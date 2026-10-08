# APP-HOME-1 — Inicio operativo privado

## Objetivo

Convertir `GET /app` en el punto de entrada operativo del ERP sin presentar
capacidades que todavía no existen.

La pantalla utiliza exclusivamente información ya resuelta por autenticación,
autorización, alcance y contexto activo. No consulta datos de módulos ni
introduce lógica de negocio.

## Información mostrada

- Usuario y correo de la sesión autenticada.
- Empresa y almacén activos resueltos por el backend.
- Selector de contexto únicamente cuando existe más de un par permitido.
- Estado descriptivo de autenticación, protección de formularios,
  autorización y alcance operativo.
- Accesos reales disponibles: inicio privado y cierre de sesión.
- Capacidades futuras identificadas expresamente como no disponibles.

Todos los valores dinámicos se escapan antes de renderizarse. No se muestran
IDs internos, roles, códigos de permisos, hashes, credenciales ni detalles de
la sesión.

## Límites

APP-HOME-1 no es un dashboard analítico ni operativo. No incluye métricas,
indicadores, gráficas, actividad reciente, accesos a módulos, CRUD, menús
dinámicos ni datos de demostración.

Los textos de capacidades pendientes no son enlaces ni controles. Su propósito
es delimitar el estado real del producto sin aparentar funcionalidades.

## Seguridad

La ruta conserva los controles aprobados:

- `AuthMiddleware` para exigir sesión.
- `PermissionMiddleware` para exigir acceso al inicio privado.
- `UserScopeService` y `ScopeContextService` para resolver el contexto.
- CSRF en cambio de contexto y logout.
- Escape con `e()` para cada valor dinámico.

## Pruebas y rollback

La aceptación requiere regresión de rutas públicas, login, autorización,
contexto, logout y aislamiento de `/health`, además de verificación visual en
escritorio y móvil sin overflow horizontal.

El rollback consiste en revertir la vista, los estilos y esta documentación.
No existen migraciones, seeds, permisos o cambios persistentes asociados.
