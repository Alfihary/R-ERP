# ERP-SIDEBAR-RBAC-REORGANIZACION-1

Estado: `PASS_WITH_UX_FINDINGS`
Rama: `jesus`
HEAD de trabajo: `6b94896 docs: add global ERP audit baseline`
Modo: cambios locales sin staging ni commit

Runtime de esta fase: validación local realizada sobre `APP_ENV=local` y la base
autorizada `r_erp_db_core_0_test`, sin exponer credenciales.

## 1. Alcance

Se revisó y reorganizó únicamente el menú lateral y sus datos de visibilidad.
También se corrigieron dos inconsistencias estrictamente relacionadas con el
menú: las banderas faltantes en `/app` y cuatro códigos de permiso de inventario
usados por la pantalla de tickets.

No se modificaron tablas, permisos, migraciones, seeds, SMTP, processor, rutas
funcionales, middleware de seguridad, `main`, `.env` ni configuración de deploy.

## 2. Archivos involucrados

| Archivo | Acción | Motivo |
|---|---|---|
| `app/Views/layouts/app.php` | modificado | grupos, orden, nombres y visibilidad del sidebar |
| `routes/web.php` | modificado | completar banderas de menú en `/app` con permisos existentes |
| `app/Http/Controllers/ProductRequestTicketController.php` | modificado | alinear permisos de enlaces de inventario con las rutas reales |
| `tests/sidebar_rbac_reorganizacion_1_test.php` | creado | prueba estática read-only de enlaces, grupos y permisos |
| `docs/erp-sidebar-rbac-reorganizacion-1.md` | creado | contrato antes/después y evidencia de la fase |

No se tocaron CSS, JavaScript ni archivos de base de datos.

## 3. Inventario antes/después

El sidebar se construía de forma hardcodeada en `app/Views/layouts/app.php`, con
booleanos `canAccess*` enviados por los controladores. Antes tenía 20 enlaces
de navegación y tres etiquetas de sección (`Precios`, `Tickets`,
`Configuración`). Después conserva los mismos 20 enlaces funcionales, sin
duplicados, y usa seis grupos estructurales explícitos más `Inicio`, que es la
entrada raíz y no una etiqueta de grupo.

| Métrica | Antes | Después |
|---|---:|---:|
| Enlaces de navegación | 20 | 20 |
| Grupos visibles | 3 | 6 |
| Duplicados | 0 | 0 |
| Enlaces sin ruta registrada | 0 | 0 |
| Rutas nuevas | 0 | 0 |
| Permisos nuevos | 0 | 0 |

La discrepancia aparente del conteo se resuelve así: `SIDEBAR_GROUP_COUNT_ACTUAL=6`
cuenta solo las etiquetas `app-navigation__section` (`Operación`, `Inventario`,
`Catálogos`, `Organización`, `Administración`, `Mi cuenta`). El árbol visual tiene
7 bloques si se cuenta también `Inicio`; `Inicio` no se modela como sección porque
es la entrada raíz.

## 4. Árbol final

```text
INICIO
└── Inicio                         /app

OPERACIÓN
└── Tickets de producto           /tickets/productos

INVENTARIO
├── Productos                     /productos
├── Precios por producto          /precios/productos
├── Movimientos                   /inventario/movimientos
├── Existencias                   /inventario/existencias
├── Existencias por serie         /inventario/existencias-series
├── Kardex                        /inventario/kardex
├── Kardex por serie              /inventario/kardex-series
├── Transferencias                /inventario/transferencias
└── Listas de precios             /configuracion/listas-precios

CATÁLOGOS
└── Catálogos                     /catalogos

ORGANIZACIÓN
├── Empresas                      /configuracion/empresas
└── Almacenes                     /configuracion/almacenes

ADMINISTRACIÓN
├── Folios                        /configuracion/folios
├── Correo                        /admin/correo
├── Cola de correo                /admin/correo/cola
└── Auditoría                     /auditoria

MI CUENTA
├── Mi perfil                     /perfil
└── Mi credencial                 /perfil/credencial
```

No se creó el grupo `VENTAS`, `COMPRAS` ni `REPORTES` porque no existe evidencia
de módulos funcionales y rutas navegables para ellos.

## 5. Matriz final

| Grupo | Etiqueta | Ruta | Permiso de visibilidad | Estado | Visible |
|---|---|---|---|---|---|
| INICIO | Inicio | `/app` | `sistema.app.ver` | COMPLETE | sí |
| OPERACIÓN | Tickets de producto | `/tickets/productos` | `tickets_productos.ver` | PARTIAL | si existe permiso |
| INVENTARIO | Productos | `/productos` | `productos.acceder` | IMPLEMENTADO | si existe permiso |
| INVENTARIO | Precios por producto | `/precios/productos` | `precios.productos.acceder` | IMPLEMENTADO | si existe permiso |
| INVENTARIO | Movimientos | `/inventario/movimientos` | `inventario.movimientos.acceder` | IMPLEMENTADO | si existe permiso |
| INVENTARIO | Existencias | `/inventario/existencias` | `inventario.existencias.acceder` | IMPLEMENTADO | si existe permiso |
| INVENTARIO | Existencias por serie | `/inventario/existencias-series` | `inventario.existencias_series.acceder` | IMPLEMENTADO | si existe permiso |
| INVENTARIO | Kardex | `/inventario/kardex` | `inventario.kardex.acceder` | IMPLEMENTADO | si existe permiso |
| INVENTARIO | Kardex por serie | `/inventario/kardex-series` | `inventario.kardex_series.acceder` | IMPLEMENTADO | si existe permiso |
| INVENTARIO | Transferencias | `/inventario/transferencias` | `inventario.transferencias.acceder` | IMPLEMENTADO | si existe permiso |
| INVENTARIO | Listas de precios | `/configuracion/listas-precios` | `precios.listas.acceder` | IMPLEMENTADO | si existe permiso |
| CATÁLOGOS | Catálogos | `/catalogos` | `catalogos.acceder` | IMPLEMENTADO | si existe permiso |
| ORGANIZACIÓN | Empresas | `/configuracion/empresas` | `configuracion.empresas.acceder` | IMPLEMENTADO | si existe permiso |
| ORGANIZACIÓN | Almacenes | `/configuracion/almacenes` | `configuracion.almacenes.acceder` | IMPLEMENTADO | si existe permiso |
| ADMINISTRACIÓN | Folios | `/configuracion/folios` | `configuracion.folios.acceder` | IMPLEMENTADO | si existe permiso |
| ADMINISTRACIÓN | Correo | `/admin/correo` | `configuracion.correo.administrar` | PARTIAL | si existe permiso |
| ADMINISTRACIÓN | Cola de correo | `/admin/correo/cola` | `correos.cola.ver` | PARTIAL | si existe permiso |
| ADMINISTRACIÓN | Auditoría | `/auditoria` | `auditoria.ver` | IMPLEMENTADO | si existe permiso |
| MI CUENTA | Mi perfil | `/perfil` | `perfil.ver` | IMPLEMENTADO | si existe permiso |
| MI CUENTA | Mi credencial | `/perfil/credencial` | `credencial.ver` | IMPLEMENTADO | si existe permiso |

La visibilidad es una ayuda de navegación. Cada ruta sigue protegida por
`AuthMiddleware`/`PermissionMiddleware`; ocultar un enlace no concede ni revoca
autorización.

La consulta read-only de `r_erp_db_core_0_test` confirmó `DB_PERMISSION_COUNT=119`.

## 6. Cambios clasificados

### Conservados

Los 20 enlaces existentes se conservaron porque cada uno tiene una ruta literal
registrada en `routes/web.php` y una bandera de permiso existente.

### Movidos

- Tickets pasó a `OPERACIÓN`.
- Productos, precios, movimientos, existencias, kardex, transferencias y listas
  de precios se agruparon bajo `INVENTARIO`.
- Empresas y almacenes pasaron a `ORGANIZACIÓN`.
- Folios, correo, cola y auditoría pasaron a `ADMINISTRACIÓN`.
- Perfil y credencial quedaron bajo `MI CUENTA` porque el topbar actual solo
  contiene identidad y logout, no accesos equivalentes.

### Renombrados

- `Tickets de productos` → `Tickets de producto`.
- `Inventario · Movimientos` → `Movimientos`.
- `Inventario · Existencias` → `Existencias`.
- `Inventario · Existencias por serie` → `Existencias por serie`.
- `Inventario · Kardex` → `Kardex`.
- `Inventario · Kardex por serie` → `Kardex por serie`.
- `Inventario · Transferencias` → `Transferencias`.
- `Auditoria` → `Auditoría`.

### Ocultos por estar fuera de alcance

No se agregaron enlaces para Ventas, Clientes, CXC, Compras, Proveedores, CXP,
Lotes/Pedimentos, Reportes, Usuarios, Roles, Permisos ni Temas. Sus pendientes
quedan documentados en la auditoría global y no se representan con placeholders.

### Correcciones de permisos de navegación

En `ProductRequestTicketController` se reemplazaron códigos que no coinciden
con las rutas (`inventario.*.ver` y `inventario.series.*`) por los códigos reales
`inventario.existencias.acceder`, `inventario.existencias_series.acceder`,
`inventario.kardex.acceder` e `inventario.kardex_series.acceder`.

La ruta `/app` ahora envía también las banderas de existencias, series, kardex,
tickets y sus permisos existentes. No se crearon códigos nuevos.

## 7. Active state, responsive e iconos

- `activeNavigation` conserva el estado activo en rutas de listado, detalle y
  formularios porque los controladores ya entregan el identificador del módulo.
- Se mantuvo la convención actual `is-active`/`aria-current="page"`.
- Se conservaron los iconos Unicode existentes; no se incorporó ninguna librería.
- El botón de colapso no existe en el layout actual; esta fase no inventó uno.
- El CSS responsive existente no se modificó. La navegación sigue pasando a una
  sola columna en móvil y no se introdujo nesting adicional.
- La revisión visual autenticada en el navegador local confirmó el árbol de
  `/app`, la jerarquía de seis grupos más `Inicio`, el resaltado de la opción
  activa en `/inventario/existencias`, la alineación de iconos y la ausencia de
  solapamiento u overflow horizontal en el viewport disponible del navegador.
- No fue posible imponer desde el navegador integrado los viewports exactos
  1440x900, 768x1024 y 390x844; por ello tablet y móvil permanecen como
  `NOT_VERIFIED`, sin afirmar un PASS responsive.
- El CSS existente apila la navegación en una sola columna en breakpoints
  pequeños; no se implementó un menú móvil ni un colapsado nuevo.

## 8. Pruebas

Se creó `tests/sidebar_rbac_reorganizacion_1_test.php`, sin conexión ni escritura
de DB. Valida:

- todos los `href` del `<nav>` tienen ruta literal registrada;
- no existen enlaces duplicados;
- existen los seis grupos esperados;
- están presentes los permisos reales de tickets/inventario;
- el layout usa banderas `canAccess*`;
- las rutas mantienen `AuthMiddleware` y `PermissionMiddleware`.

Resultado:

```text
PASS sidebar routes=20 registered_routes=110 groups=6
```

También se creó `tests/sidebar_navigation_runtime_1_test.php`, que renderiza el
layout con un conjunto limitado de permisos en una sesión temporal y comprueba
que solo aparece Existencias, que seis opciones administrativas/operativas no se
renderizan y que hay un único estado activo:

```text
PASS limited_render permitted=1 forbidden=6 active=1
```

Lint ejecutado:

```text
app/Views/layouts/app.php                         PASS
routes/web.php                                    PASS
app/Http/Controllers/ProductRequestTicketController.php PASS
tests/sidebar_rbac_reorganizacion_1_test.php      PASS
```

No se ejecutó `database/tests/rbac_0_test.php` porque realiza mutaciones dentro
de una transacción sobre la base y esta fase exige no escribir DB. La visibilidad
de un usuario limitado y el 403 por URL directa quedan respaldados por los
middleware existentes, pero requieren una prueba funcional autenticada separada.

### Validación HTTP local ADMIN

Se levantó el servidor local existente en `127.0.0.1:8080`, se verificó
`APP_ENV=local` y la base autorizada, y se inició sesión con el ADMIN configurado
localmente sin imprimir contraseña, cookies, sesión ni token. Resultado:

```text
LOGIN_ADMIN=PASS
SIDEBAR_EXPECTED_ITEMS=20
SIDEBAR_RENDERED_ITEMS=20
EMPTY_GROUPS=0
BROKEN_RENDERED_LINKS=0
```

Los 20 destinos principales respondieron HTTP `200`, sin `404` ni `500`, y cada
respuesta mostró exactamente un `aria-current="page"`. Los enlaces renderizados
coincidieron con el árbol final documentado.

El topbar autenticado respondió correctamente y conserva identidad y logout. No
contiene enlaces de perfil o credencial, por lo que no hay duplicación entre
navbar y sidebar:

```text
NAVBAR=PASS
PROFILE_DUPLICATED_IN_NAVBAR=false
CREDENTIAL_DUPLICATED_IN_NAVBAR=false
```

La comparación global de rutas usa otra metodología: la auditoría cuenta 146
registros de método (72 GET + 74 POST), mientras que el test sidebar cuenta 110
rutas literales únicas. La diferencia es de 36: 13 paths se registran dos veces
por método/flujo y 23 registros se generan de forma dinámica o condicional. No
se trata de un defecto del router ni se hizo refactor.

## 9. Seguridad

- `AuthMiddleware`: preservado.
- `PermissionMiddleware`: preservado.
- CSRF: sin cambios.
- Rutas directas: siguen pasando por middleware; el sidebar no es una frontera
  de seguridad.
- Escalación de privilegios: no se introdujo ninguna.
- DB: solo se usó evidencia previa/read-only; no hubo writes.

## 10. Findings y siguiente fase

`PASS_WITH_UX_FINDINGS` por estas limitaciones deliberadas:

1. El sidebar sigue recibiendo banderas desde varios controladores; una futura
   fase puede centralizar el contrato de navegación sin ampliar su alcance.
2. No hay colapsado real ni tooltips para iconos; no se inventó UI nueva.
3. Falta prueba HTTP real autenticada con usuario limitado; no se creó un
   usuario temporal porque esta fase prohíbe escrituras persistentes y una
   transacción de otra conexión no sería visible para el servidor HTTP.
4. La validación visual autenticada comprobó el viewport disponible, pero no fue
   posible imponer exactamente los tamaños tablet/móvil desde el navegador
   integrado.
4. Los módulos futuros continúan fuera del menú.

```text
RUNTIME_VALIDATED=true
ADMIN_RUNTIME=PASS
LIMITED_RUNTIME=PASS_ISOLATED_RENDER
DIRECT_403=NOT_RUNTIME_EXECUTED
DESKTOP_VISUAL=PASS_AVAILABLE_VIEWPORT
TABLET_VISUAL=NOT_VERIFIED
MOBILE_VISUAL=NOT_VERIFIED
SIDEBAR_COLLAPSE_IMPLEMENTED=false
ICON_ISSUES_COUNT=0
DB_PERSISTENT_WRITES=0
ROLLBACK_VERIFIED=NOT_APPLICABLE
VISIBLE_RUNTIME_ITEMS=20
VISIBLE_RUNTIME_GROUPS=6
ROOT_ITEMS=1
EMPTY_GROUPS=0
BROKEN_LINKS=0
ACTIVE_VISUAL=PASS
PROFILE_DUPLICATED_IN_NAVBAR=false
CREDENTIAL_DUPLICATED_IN_NAVBAR=false
UX_IMPROVEMENT_PENDING=ERP-SIDEBAR-COLLAPSE-UX-1
```

Siguiente fase recomendada: revisión visual y prueba autenticada de navegación,
seguida de una eventual centralización de `NavigationPermissions`. No iniciar
Ventas, Compras, Reportes, Usuarios/Roles ni cambios de DB en esta fase.

## 11. Estado Git requerido para entrega

```text
STAGING=false
COMMIT=false
PUSH=false
DEPLOY=false
DB_WRITES=false
SMTP=false
```
