# ERP-SIDEBAR-COLLAPSE-UX-1

Estado: `IMPLEMENTADO_SIN_COMMIT`
Rama: `jesus`
HEAD base: `976163650687ffa21ea196fa1442e055040e82d8`

## Contrato

```text
COLLAPSIBLE_GROUPS=true
MULTIPLE_GROUPS_OPEN=true
ACTIVE_GROUP_FORCED_OPEN=true
LOCAL_STORAGE=true
LOCAL_STORAGE_KEY=r_erp_sidebar_groups_v1
SERVER_SIDE_RBAC=true
EMPTY_GROUPS_HIDDEN=true
VERTICAL_SCROLL=true
SENSITIVE_DATA_STORED=false
```

La fase conserva exactamente los 20 enlaces, sus labels, rutas, orden funcional
y permisos existentes. Solo se añadió el comportamiento visual de seis grupos:
`operation`, `inventory`, `catalogs`, `organization`, `administration` y
`account`. `Inicio` permanece como raíz no colapsable.

## Problema y causa

Antes, `.app-sidebar` tenía `min-height: 100vh`, pero no un modelo de altura
confinada ni un contenedor de navegación con `overflow-y`. Cuando todos los
grupos permanecían abiertos, el contenido inferior podía quedar fuera del área
visible sin un scroll propio del sidebar.

La corrección usa `height: 100vh`, `overflow: hidden` en el aside y un
`.app-navigation` flexible con `overflow-y: auto`. En tamaños pequeños se
mantiene el flujo existente y se limita la navegación a la altura disponible.
El scroll y el colapsado son complementarios.

## Comportamiento

- Los botones son `<button type="button">` con `aria-expanded` y
  `aria-controls`.
- El contenido se identifica con IDs estables `sidebar-group-*`.
- No es un acordeón exclusivo: Inventario, Administración y Mi cuenta pueden
  permanecer abiertos simultáneamente.
- El grupo que contiene `activeNavigation` siempre se fuerza a abierto.
- Sin preferencia guardada, solo se abre automáticamente el grupo activo; en
  `/app`, donde Inicio es activo, los grupos pueden iniciar cerrados.
- El teclado Enter/Space funciona de forma nativa al usar botones.
- El chevron cambia visualmente con `aria-expanded`.
- El HTML entrega todos los enlaces sin `hidden`; si JavaScript falla, los
  enlaces siguen siendo accesibles y el usuario puede navegar normalmente.

## Persistencia

Se guarda únicamente un arreglo de IDs de grupos abiertos en:

```text
r_erp_sidebar_groups_v1
```

El script filtra los IDs contra una lista fija de seis grupos conocidos y
conserva preferencias de grupos que no estén renderizados temporalmente por
RBAC en una ruta concreta. No se guardan usuario, email, permisos, roles,
sesiones, tokens, rutas privadas ni datos empresariales.

## RBAC y seguridad

PHP continúa decidiendo qué enlaces y grupos se renderizan. JavaScript solo
controla presentación, estado ARIA y `localStorage`; no usa `fetch`, cookies,
sesión, DB ni llamadas backend. Los grupos sin hijos visibles no se renderizan.

`AuthMiddleware`, `PermissionMiddleware` y `CsrfMiddleware` no fueron
modificados. No se crearon rutas, permisos, migraciones, seeds o módulos.

## Responsive

Desktop conserva el sidebar lateral con scroll vertical. Tablet y móvil usan
las reglas existentes de una columna; los toggles permanecen accesibles y no se
introdujo un drawer móvil. La revisión visual exacta de 1440x900, 768x1024 y
390x844 queda para la comprobación manual de la fase, porque el navegador
integrado no expone un override de viewport en esta ejecución.

## Pruebas

```text
PASS sidebar routes=20 registered_routes=110 groups=6
PASS limited_render permitted=1 forbidden=6 active=1
PASS collapse groups=6 root=1 items=20 accessible=6 scroll=auto storage=ux-only
```

También se ejecutaron `php -l` sobre el layout y tests, `node --check` sobre el
script vanilla y `git diff --check`.

La prueba runtime autenticada confirmó:

- Inventario y Administración abiertos simultáneamente.
- Persistencia al navegar a una ruta que no renderiza todos los grupos por RBAC.
- Inventario forzado abierto en `/inventario/existencias`.
- Administración forzada abierta en `/admin/correo`.
- Mi cuenta forzada abierta en `/perfil`.

## Findings restantes

- La validación visual exacta de los tres viewports debe completarse cuando el
  tooling permita forzarlos.
- No se implementó drawer móvil; queda como mejora posterior si la revisión
  responsive demuestra que hace falta.
- No se stageó ni commitó esta fase.

## Fuera de alcance

Sin DB, migraciones, seeds, permisos nuevos, rutas funcionales nuevas, SMTP,
processor, módulos, cambios en `main`, push o deploy.
