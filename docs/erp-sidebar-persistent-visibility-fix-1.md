# ERP-SIDEBAR-PERSISTENT-VISIBILITY-FIX-1

## Diagnóstico

La incidencia se reprodujo con la misma sesión ADMIN: `/app` mostraba el
árbol completo, mientras que inventario, correo y perfil mostraban únicamente
las banderas `canAccess*` que cada controlador había enviado. El layout trataba
las banderas ausentes como `false`, por lo que la navegación dependía de la
ruta en lugar del usuario.

## Corrección

`App\Domain\Navigation\SidebarNavigationService` es ahora la única fuente de
la navegación. Define el árbol aprobado (20 enlaces incluyendo Inicio, seis
grupos y los permisos existentes) y consulta el `PermissionService` actual.
El layout obtiene esa proyección en un punto común, por lo que no depende de
flags parciales del controlador. No se creó un segundo RBAC ni se cambiaron
permisos, rutas, migraciones o datos.

La UX existente se conserva: grupos colapsables, estado activo, scroll y la
clave `r_erp_sidebar_groups_v1`. Los grupos sin elementos autorizados no se
renderizan; un usuario limitado a `inventario.existencias.acceder` conserva
únicamente Inicio y Existencias en todas las rutas.

## Evidencia

- Antes: `/app` = 20 enlaces/6 grupos; otras rutas = subconjuntos variables.
- Después: la proyección central produce el mismo conjunto ADMIN en todas las
  rutas y el mismo subconjunto para usuarios limitados.
- Banderas duplicadas: el layout ya no confía en valores específicos de cada
  controlador; permanecen solo como compatibilidad de contexto de contenido.
- Regresión: `tests/sidebar_persistent_visibility_1_test.php` compara las
  firmas exactas label/href/permission y rechaza grupos vacíos.

## Corrección de páginas largas

- `LONG_PAGE_SIDEBAR_BUG_REPRODUCED=true`
- `ROOT_CAUSE_LONG_PAGE=.app-sidebar tenía height:100vh pero no estaba anclado al viewport`
- `LONG_PAGE_LAYOUT_FIX_IMPLEMENTED=true`
- `SIDEBAR_POSITION_AFTER=sticky`
- `SIDEBAR_VIEWPORT_HEIGHT=true` (`100vh` con fallback moderno `100dvh`)
- `SIDEBAR_INTERNAL_SCROLL=true` (`.app-navigation { overflow-y: auto; }`)
- `STICKY_BLOCKING_ANCESTOR=false`
- `RESPONSIVE_STICKY_OVERRIDE=true` (`position: static`, altura automática en móvil)

En escritorio el sidebar permanece anclado mientras el contenido principal
crece y se desplaza. En el breakpoint móvil deja de ser lateral y vuelve al
flujo normal, sin introducir un drawer.

La validación visual final en `/auditoria` con sesión ADMIN quedó completada.
`MANUAL_OPERATOR_VERIFICATION_PENDING=false`.

## Cierre validado

- `PHASE_STATUS=PASS`
- `VISUAL_MENU_STABILITY=PASS`
- `MANUAL_OPERATOR_VERIFICATION=PASS`
- `LONG_PAGE_LAYOUT_FIX_IMPLEMENTED=true`
- `LONG_PAGE_VISUAL_VERIFICATION=PASS`
- `SIDEBAR_BACKGROUND_GAP=false`
- `SIDEBAR_INTERNAL_SCROLL=PASS`

El operador confirmó visualmente que `/auditoria` conserva el sidebar durante
un scroll largo, sin espacio blanco inferior; el scroll interno funciona y
Inicio/Mi credencial siguen accesibles. También confirmó que Movimientos
mantiene todos los grupos autorizados y solo cambia el estado activo.

## Fuera de alcance

No se modificaron base de datos, migraciones, seeds, SMTP, autenticación,
permisos funcionales, módulos, `package.json`, despliegue ni historial Git.
