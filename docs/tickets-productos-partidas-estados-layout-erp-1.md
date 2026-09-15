# TP-PARTIDAS-ESTADOS-LAYOUT-ERP-1

## Objetivo

Corregir la visualización privada del módulo de Tickets de Solicitud de Alta de Productos para que sus pantallas principales se rendericen dentro del layout autenticado del ERP.

## Rutas cubiertas

- `GET /tickets/productos`
- `GET /tickets/productos/crear`
- `GET /tickets/productos/{id}`

Las rutas `POST` existentes se conservan sin cambios de URL ni de permisos.

## Diagnóstico

`/app` usa el layout reutilizable `app/Views/layouts/app.php`. Ese layout centraliza:

- sidebar lateral;
- topbar;
- identidad del usuario autenticado;
- formulario de cierre de sesión;
- contexto empresa/almacén;
- hojas CSS globales y modulares;
- scripts externos locales permitidos.

Las vistas de tickets (`index.php`, `create.php`, `show.php`) tenían documento HTML propio (`doctype`, `html`, `head`, `body`) y cargaban CSS directamente. Por eso se veían como páginas sueltas, sin sidebar ni topbar.

## Corrección

`ProductRequestTicketController` ahora renderiza las vistas de tickets mediante `layouts/app`, pasando:

- `contentView` con la vista de tickets;
- `contentData` con datos del módulo;
- usuario autenticado;
- contexto de alcance;
- permisos visuales;
- CSS modular `/css/modules/tickets-productos.css`;
- JS externo local `/js/modules/tickets-productos-create.js` solo en creación.

Las vistas de tickets quedaron como contenido del área principal y no duplican el shell privado.

## Seguridad conservada

- `AuthMiddleware` se conserva.
- `PermissionMiddleware` se conserva.
- CSRF se conserva en formularios.
- Las vistas siguen usando `e()`.
- No se exponen rutas internas.
- No se exponen `storage/private` ni `storage/uploads`.
- No se agregan CDN, frameworks ni JavaScript inline ejecutable.

## Fuera de alcance

Esta fase no crea:

- productos;
- precios;
- inventario;
- compras;
- proveedores;
- correos reales;
- SMTP;
- worker;
- cron;
- descargas o previews de adjuntos;
- permisos nuevos;
- seeds nuevos;
- migraciones nuevas.

## Prueba

Runner:

```powershell
php database\tickets-productos-partidas-estados-layout-erp.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

El test valida render autenticado de `index`, `create` y `show`, redirección anónima a `/login`, presencia de sidebar/topbar/usuario/logout, conservación de CSRF, JS externo local y ausencia de cambios operativos.
