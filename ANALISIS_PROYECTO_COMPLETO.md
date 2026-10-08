# Auditoría técnica completa — GrupoRefrigerantes

**Fecha:** 6 de octubre de 2026  
**Código analizado:** `C:\xampp\htdocs\GrupoRefrigerantes` (copia local descargada del servidor)  
**Base autorizada:** `gruporefrigerantes_aw_20261002`  
**Alcance:** lectura del código y consultas `SELECT` locales. No se modificaron archivos existentes ni datos; no se accedió a AwardSpace, no se hicieron peticiones HTTP externas y no se llamó a n8n.

## 1. Resumen ejecutivo

El sistema es un ERP PHP con arquitectura MVC propia. Tiene autenticación, permisos, perfil, passkeys, vCard pública, catálogo, empresas/almacenes, auditoría y altas de producto tipo ticket. El sitio ya tiene dos integraciones encaminadas: la vCard reenvía solicitudes a un webhook n8n y un endpoint privado permite que n8n busque productos mediante SELECT.

No es todavía un CRM comercial completo. Las solicitudes de la vCard no se guardan como oportunidades locales: se reenvían y la confirmación depende del webhook. No hay modelo de cotización, clientes/CRM, seguimientos ni cursos/alumnos del Instituto ACR. El dashboard `/app` es una página privada básica, no un tablero operativo por vendedor.

La brecha de mayor riesgo es la divergencia entre varios repositorios y el esquema que realmente existe en la base restaurada. Hay código que consulta nombres de tablas/columnas ausentes o heredados, mientras las tablas reales usan otra nomenclatura. Esto afecta potencialmente inventario, precios, tickets, folios, asignaciones y correo. La base tiene catálogo (208 productos) y datos de vCard, pero compras e inventario están esencialmente vacíos.

La integración de búsqueda identifica el ejemplo de Embraco como producto 7 mediante normalización de código. La autenticación de entrada está implementada con `X-N8N-Token`, `N8N_ERP_API_SECRET` y `hash_equals()`. El historial de staging reportado previamente incluye respuestas 401; esta auditoría no hizo una nueva solicitud y no prueba el estado remoto.

## 2. Arquitectura

- **Lenguaje/runtime:** PHP; el proyecto declara soporte desde PHP 8.2. El runtime CLI local inspeccionado fue PHP 8.2.12.
- **Framework:** no usa un framework completo; implementa MVC/servicios/repositorios propios. Composer administra librerías externas, entre ellas PHPMailer, WebAuthn y BaconQrCode.
- **Entrada:** `public/index.php` inicia la aplicación a través de `bootstrap/app.php`.
- **Bootstrap:** carga `.env`, configura `Config`, conexión PDO, sesión, middleware, servicios, repositorios y controladores; luego registra rutas.
- **Flujo normal:** HTTP request → router → middleware → controller → service → repository/PDO → view o respuesta JSON/imagen.
- **HTTP:** `app/Core/Request.php`, `Response.php`, `Router.php` y middleware propios. El body JSON se lee de forma lazy y con límite en la ruta de integración; `Request::header()` trata los nombres sin distinguir mayúsculas/minúsculas.
- **Autenticación:** servicio propio con `password_verify()`, comprobación de usuario activo/eliminado y regeneración de sesión. Hay passkeys/WebAuthn y desbloqueo de dispositivo.
- **Autorización:** RBAC mediante roles/permisos y verificaciones en middleware/controladores.
- **Configuración:** `Env` carga el `.env` del proyecto desde bootstrap; `Config` ofrece acceso a variables. No se imprimen secretos aquí. El `.env` local presenta `APP_ENV=local`, `APP_DEBUG=true`, conexión a la base autorizada y configuración de webhook/API. La URL de webhook está en modalidad de prueba.
- **Sesiones:** cookies con opciones HttpOnly/SameSite, modo estricto, cookies solamente, regeneración en autenticación y atributo Secure dependiente de configuración/URL.
- **Errores y logs:** manejador propio que registra excepciones y stack trace; los detalles se pueden mostrar cuando `APP_DEBUG` está activo. No se inspeccionaron ni expusieron secretos de logs.
- **Frontend:** PHP views, JavaScript vanilla por módulos y CSS local. Sin framework SPA.

## 3. Mapa del proyecto

```text
app/
  Core/                 Request, Response, Router, sesión, config y errores
  Domain/               servicios y reglas por dominio
  Http/Controllers/     controladores
  Http/Middlewares/     autenticación, permisos, CSRF y seguridad
  Infrastructure/
    Database/           conexión y migraciones
    Repositories/       SQL/PDO
    Storage/            archivos, fotografías y documentos
  Support/              utilidades compartidas
  Views/                plantillas PHP por módulo
bootstrap/app.php       composición de dependencias y registro
routes/                 rutas web, passkeys y desbloqueo
public/                 front controller, JS/CSS, PWA y recursos
config/                 configuración de aplicación/servicios
 database/              migraciones y herramientas de esquema
storage/                archivos subidos, sesiones/logs según entorno
vendor/                 dependencias Composer
```

Áreas de dominio presentes incluyen Auth, Audit, Catalogs, Companies, Configuration, Credentials, Folios, Inventory, Mail, Notifications, Pricing, Products, Profile, Scope, Security, Themes, Tickets, Users, Vcards y Warehouses. Hay carpetas con estructura mínima o `.gitkeep`; la existencia de una carpeta no significa que el módulo esté operativo.

## 4. Módulos

| Módulo | Estado | Implementación y observación |
|---|---|---|
| Login/sesión | Parcial-operativo | Auth service/controller, vistas y rutas. Contraseña con `password_verify`; intentos y sesiones aparecen en BD. |
| Usuarios | Parcial | Datos y RBAC existen; faltan o no coinciden algunas relaciones/repositorios de administración/asignación. No se encontró un módulo completo de administración de usuarios conectado de punta a punta. |
| Roles/permisos | Parcial-operativo | Repositorios y middleware RBAC; la base tiene roles/permisos. Hay código heredado que espera `usuario_roles`, pero la tabla real es `usuario_rol`. |
| Perfil/foto | Implementado con adaptaciones | Controlador, servicio, repositorios, vistas y almacenamiento. Soporta referencias históricas y nuevas de foto con resolución segura. |
| Passkeys/desbloqueo | Parcial-operativo | Rutas/controladores y librería WebAuthn; hay registros de passkeys. |
| Credencial/QR | Implementado, dos generadores | Credencial, reverso y descarga PNG por html2canvas; QR público e imagen para credencial. La credencial consume el QR público `/v/{slug}/qr`. |
| vCard/privacy | Implementado | `usuario_vcards`, privacidad, publicación, foto pública y endpoints QR/contacto. Persistencia de privacidad fue validada localmente por el usuario; no se repitió en esta auditoría. |
| Productos de vCard | Parcial-operativo | Relación numérica a `productos.id`; filtros públicos existen. La BD tiene 8 relaciones; el FK a producto no está declarado. |
| Catálogo | Parcial | Tablas y vistas existen; parte del repositorio consulta columnas/tablas legacy incompatibles con el esquema actual. |
| Marcas/unidades/líneas/clasificaciones | Parcial | Catálogos y datos existen; clasificaciones está vacía y algunos campos auxiliares no están implementados de forma uniforme. |
| Empresas/almacenes | Parcial | Entidades y datos existen; asignación en usuarios usa campos directos, pero parte del código espera tablas de relación ausentes. |
| Auditoría | Implementado | `auditoria_eventos` contiene registros y el módulo tiene código de consulta. |
| Inventario/compras | Estructura/parcial | Hay tablas y código, pero tablas esperadas por repositorios difieren del esquema y los datos de movimientos/existencias/compras consultados están en cero. |
| Tickets de alta de producto | Parcial/incompatible | La base tiene `ticket_alta_*`; el repositorio legado usa `tickets_productos*`, ausentes. |
| Instituto ACR | Solo canal/formulario | Opción de capacitación en vCard, envío al webhook y enlaces; no hay gestión interna de cursos/alumnos/inscripciones. |
| Cotizaciones/clientes/CRM/leads | No existe como módulo local | No se encontraron tablas de cotizaciones, clientes comerciales, oportunidades, leads persistentes o seguimientos. |
| Notificaciones | Parcial | Infraestructura de correo/outbox ligada a tickets, pero tablas referenciadas ausentes; no hay campana/Push/WhatsApp. |
| PWA | Parcial | Manifest, service worker, registro, cache/offline e iconos; no hay Web Push/VAPID. |
| Reportes | Estructura limitada | No hay reporting comercial ni métricas de venta/oportunidad listas. |

Los controladores y servicios específicos se detallan en secciones posteriores y en el inventario de archivos clave.

## 5. Dashboard `/app`

La ruta se registra como closure en `routes/web.php`; no hay `DashboardController`, servicio ni repositorio dedicado. Usa contexto de alcance (`ScopeContextService`, `UserScopeService`/`ScopeRepository`) y la vista privada `app/Views/auth/private.php`, con layout compartido.

Actualmente identifica al usuario autenticado desde la sesión y muestra identidad/contexto de empresa/almacén y controles/enlaces de seguridad. No ejecuta consultas de solicitudes, cotizaciones, seguimientos, cursos ni actividad comercial. La pantalla expresa que las operaciones comerciales aún no están disponibles; no debe describirse como tablero con KPIs.

Agregar tarjetas comerciales es fácil en presentación, pero no se pueden hacer consultas fiables hasta contar con tablas persistentes que incluyan `usuario_id`/propietario y alcance. Conviene convertir la closure en controlador/servicio/repositorio una vez que exista el dominio comercial.

## 6. Usuarios y vendedores

La tabla real `usuarios` contiene identificador `id`, `empresa_id`, `almacen_id`, nombre y apellidos separados, `username`, `email`, teléfonos, `foto`, puesto, `activo`, `deleted_at` y columnas heredadas de eliminación/auditoría. No se muestran valores personales aquí. No hay una columna de rol único que sustituya al RBAC; la asociación real usuario-rol es `usuario_rol`.

La identidad estable recomendada para vendedor es `usuarios.id`. Empresa y almacén contextualizan alcance, pero no sustituyen la identidad. El nombre completo sirve para mostrar/comunicar, no como clave. Para nuevas solicitudes persistentes debe guardarse `vendedor_usuario_id` además del snapshot del nombre si se necesita histórico.

## 7. vCard

La tabla `usuario_vcards` relaciona una vCard con usuario mediante `usuario_id` (único) y mantiene slug, título, descripción, estado y flags `mostrar_*`. `usuario_vcard_productos` vincula vCard y productos usando `producto_id` numérico.

`/v/{slug}` resuelve la tarjeta pública por slug en `PublicVcardController`/`VcardService` y filtra publicación/eliminación. Los repositorios cargan datos del propietario; la representación pública saneada omite identificadores internos como `usuario_id`. La información de vendedor (nombre), foto, correo, teléfonos, puesto, empresa/almacén y enlaces se condiciona a privacidad. El submit puede usar nombre del propietario; actualmente no recibe `vendedor_usuario_id` desde la representación ya saneada.

La foto se sirve por endpoint y verifica publicación/privacidad. Se aceptan referencias históricas `uploads/perfiles/{archivo}` y nuevas `uploads/usuarios/{id}/fotos/{archivo}` dentro de raíces autorizadas, con normalización, `realpath`, archivo regular/legible, tamaño y MIME real JPEG/PNG/WebP. El QR público se sirve como PNG. La configuración/privacidad/productos tienen rutas privadas con permisos `perfil.*`.

La UI pública ofrece Cotizar (valor interno `asesoria`), Refacciones (`refaccion`) e Instituto ACR (`capacitacion`); el soporte backend para `proyecto` permanece aunque su opción está oculta actualmente. Los formularios construyen detalles específicos y resumen antes de enviar.

## 8. Flujo cliente → n8n

Flujo implementado: vCard → formulario público → POST al ERP → validación servidor → llamada HTTPS desde `PublicVcardController::submitLead()` al webhook → respuesta recuperable al navegador. El navegador no llama directamente a n8n.

Configuración: `VCARD_N8N_WEBHOOK_URL` y `VCARD_N8N_WEBHOOK_SECRET` en entorno; el reporte no incluye sus valores. La integración usa header de autenticación `X-VCard-Token`, verificación TLS, timeout y no sigue redirects. Se limita/valida el contenido, se aplica honeypot y limitación temporal, y solo se comunica éxito si el webhook devuelve HTTP 2xx. No se encontró persistencia local/outbox de la solicitud; ante fallo no hay una oportunidad recuperable en el ERP.

Campos base: `nombre`, `correo`, `telefono`, `mensaje`, `servicio`; se agregan `detalles`, `vendedor`, `origen=vcard`, `vcard_slug`, `vcard_url` y fecha. El vendedor viene del propietario resuelto server-side, nunca de un campo confiado del navegador. Servicios backend: `proyecto`, `refaccion`, `capacitacion`, `asesoria` (Cotizar). Detalles permitidos: proyecto tipo/aplicación/ubicación/etapa/capacidad/descripción; refacción tipo/marca/modelo/refrigerante/voltaje/cantidad/descripción; capacitación curso/nivel/modalidad/ciudad; asesoría tipo/descripción/cantidad/marca-modelo/urgencia.

Historial previo indica solicitud local con error y un 401 en staging reportado por el usuario. No se hizo solicitud durante esta auditoría; el funcionamiento del webhook no queda certificado por este informe.

## 9. Integración n8n → ERP: búsqueda de productos

- Ruta: `POST /api/integraciones/n8n/productos/buscar`.
- Archivos principales: `routes/web.php`, `N8nProductSearchController.php`, `N8nProductSearchRepository.php`, Request/middleware y ensamblado en `bootstrap/app.php`.
- Autenticación: header `X-N8N-Token`; secreto independiente `N8N_ERP_API_SECRET`; comparación constante `hash_equals()`.
- Entrada: JSON raíz objeto, whitelist exacta `consulta`, `modelo`, `cantidad`; body máximo 16 KiB con lectura limitada, consulta hasta 1000 bytes, modelo 200, cantidad entera 1..100000; consulta o modelo debe tener contenido. Sin token/incorrecto devuelve 401. Sin búsqueda HTTP/JSON inválido se rechaza.
- SQL: consultas preparadas SELECT únicamente; filtra `activo=1` y `deleted_at IS NULL`; máximo 10 resultados. No consulta precios ni existencias y cantidad validada no cambia los resultados.
- Prioridades: código `id_producto` normalizado, modelo, número de parte, SKU fabricante, descripciones exactas y luego parciales. Normalización de código/modelo: trim, mayúsculas, elimina guiones y espacios.

Para el ejemplo, `EAF108H-A1-NAAM` se normaliza como `EAF108HA1NAAM`, coincide con `productos.id_producto` y devuelve el producto de **ID 7**: descripción `COMP EAF108H-A1-NAAM 9HP 3-460V A/C`, descripción larga con refrigerantes R-410A/R-454B/R-452B, marca Embraco, unidad Pieza (`pza`). La tabla no tiene el modelo separado poblado para ese producto; la coincidencia exacta es por código. No se probó el endpoint HTTP en esta auditoría.

## 10. Catálogo de productos

Tablas principales: `productos` (PK `id`, código único `id_producto`), `marcas`, `unidades_medida`, `lineas_producto`, `clasificaciones_producto`, además de `producto_documentos`, `producto_similares`, `productos_kit_componentes`, `precios_productos` y catálogos SAT/tipos.

El producto conserva campos estructurados para descripción corta/larga, modelo, número de parte, SKU de fabricante, código de barras, marca, unidad, línea/clasificación, SAT/tipos e información dimensional. En el producto de prueba modelo/número de parte/SKU no están informados; refrigerante, capacidad y HP aparecen en texto descriptivo, no como atributos técnicos normalizados. Existe tabla de similares, pero no tiene filas en la base consultada; equivalencias no están preparadas como recomendación confiable.

Hay 208 productos, 17 marcas, 7 unidades, 9 líneas y cero clasificaciones. Hay precios en `precios_productos` (371 filas), pero el flujo de búsqueda no los incluye. Esquema de inventario existe, pero las tablas de existencias/movimientos relevantes están vacías según el SELECT realizado.

## 11. Cotizaciones

**No existe un módulo completo de cotizaciones.** No se encontraron tablas de encabezado/partidas de cotización, IVA/snapshot de precio, PDF, estado comercial o vendedor asignado. Hay precios de catálogo, folios genéricos y formularios de solicitud; ninguno equivale a cotización formal. Las tablas de compras son del lado de adquisiciones, no ventas.

## 12. CRM, oportunidades y leads

**No existe CRM comercial persistente.** La búsqueda de código, tablas y esquema no encontró tablas de `leads`, oportunidades, prospectos, actividades/seguimientos, notas o historial comercial. La vCard reenvía a n8n y no guarda primero en una tabla local. Los tickets de alta de productos son un flujo distinto, aunque algunas etiquetas/rutas heredadas los nombran como `tickets_productos`.

## 13. Instituto ACR

No se encontró subsistema de cursos, alumnos/interesados, inscripciones, grupos, instructores o estados. Instituto ACR aparece como servicio seleccionable en la vCard, que transmite datos de interés/capacitación al webhook, y como enlace externo. No hay modelo que el dashboard pueda consultar. La creación futura debe definir dueño/vendedor, origen, consentimiento y estados, y no reutilizar tickets de producto como sustituto.

## 14. PWA

Existe `public/manifest.webmanifest`, service worker, script de registro/instalación, página offline e iconos. Usa modo standalone, cachea recursos permitidos y ofrece fallback offline para navegación. Hay base de installability/cache, no de notificaciones push.

No se encontró implementación de Notifications API de extremo a extremo, Push API, subscriptions, VAPID o Web Push. El service worker actual no constituye por sí mismo un sistema de notificaciones.

## 15. Notificaciones

Existe código de correo/configuración/outbox asociado a tickets, pero se detectó que repositorios apuntan a tablas ausentes (`mail_accounts`, reglas/destinatarios de correo referenciadas). No hay campana de notificaciones internas/general ni cola/jobs operativa para oportunidades comerciales. No se encontró push ni WhatsApp automatizado. El webhook puede preparar aviso en n8n, pero no equivale a notificación persistente dentro del ERP.

## 16. Permisos

La base contiene 108 códigos de permisos. Entre los relevantes encontrados están `perfil.ver`, `perfil.editar`, `perfil.vcard.ver`, `perfil.vcard.editar`, `perfil.vcard.publicar`, permisos `productos.*`, `auditoria.ver`, `tickets_productos.*` y `seguridad.*`. No se encontraron permisos reales `ventas.*`, `cotizaciones.*` o `instituto.*`; no deben inventarse hasta definir módulos. RBAC vincula `usuario_rol` → `roles` → `rol_permiso` → `permisos`.

## 17. Inventario de la base de datos

Conexión local confirmada por `SELECT DATABASE()` como `gruporefrigerantes_aw_20261002`. La consulta de metadatos identificó 67 tablas activas. No se ejecutó SQL de escritura ni migración. Inventario resumido (PK principal indicado; las FK citadas son las relevantes observadas):

| Tabla(s) | Propósito / PK | Relaciones y estado |
|---|---|---|
| `usuarios` | Usuarios, PK `id` | Empresa/almacén directos; RBAC por `usuario_rol`; 20 filas. |
| `roles`, `permisos`, `rol_permiso`, `usuario_rol` | RBAC; PK id o compuesta según tabla | 8 roles, 108 permisos, 194 asignaciones rol-permiso, 20 asignaciones usuario-rol. |
| `usuario_passkeys`, `sesiones_usuario`, `login_intentos` | Seguridad/autenticación; PK `id` | Passkeys 2, sesiones 288, intentos 89. |
| `auditoria_eventos` | Auditoría, PK `id` | 1,329 eventos. |
| `empresas`, `almacenes` | Organización, PK `id` | 7 empresas, 14 almacenes. |
| `usuario_vcards` | Publicación/perfil vCard, PK `id` | `usuario_id` → `usuarios.id`; 15 tarjetas. |
| `usuario_vcard_productos` | Productos públicos, PK `id` | vCard relacionada; `producto_id` indexado numérico, no FK declarado a `productos.id`; 8 filas. |
| `productos` | Catálogo, PK `id`, código `id_producto` | Catálogos de marca/unidad/línea/tipo/SAT; 208 filas. |
| `marcas`, `unidades_medida`, `lineas_producto`, `clasificaciones_producto` | Catálogos, PK `id` | 17, 7, 9 y 0 filas respectivamente. |
| `claves_sat`, `unidades_sat`, `impuestos`, `monedas`, `tipos_producto`, `tipos_inventario` | Catálogos fiscales/tipos, PK `id` | 569, 11, 3, 3, 3 y 4 filas. |
| `listas_precios`, `precios_productos`, `tipos_cambio` | Precios, PK `id` | 2 listas, 371 precios, 0 tipos de cambio. |
| `producto_documentos`, `producto_similares`, `productos_kit_componentes` | Adjuntos/equivalencias/kits, PK `id` | 23 documentos; similares y componentes sin registros. |
| `proveedores`, `proveedor_contactos` | Proveedores, PK `id` | Tablas estructuradas; no son clientes de ventas. |
| `compras_requisiciones`, `compras_requisicion_partidas`, `compras_ordenes`, `compras_orden_partidas`, `compras_recepciones`, `compras_recepcion_partidas`, `compras_facturas`, `compras_factura_partidas`, `compras_devoluciones`, `compras_devolucion_partidas` | Ciclo de compras; PK `id` | Tablas presentes, sin filas de negocio en el dump consultado. |
| `inventario_tipos_movimiento`, `inventario_folios_almacen`, `inventario_existencias`, `inventario_lotes`, `inventario_series`, `inventario_movimientos`, `inventario_movimiento_lotes`, `inventario_movimiento_series`, `inventario_ajustes`, `inventario_ajuste_partidas`, `inventario_transferencias`, `inventario_transferencia_partidas`, `inventario_fisico_capturas`, `inventario_fisico_conteos`, `inventario_fisico_sistema_rows`, `inventario_fisico_ajustes` | Inventario físico/lógico; PK `id` | Grupo real usa prefijo `inventario_`; datos consultados vacíos o sin operación registrada. |
| `ticket_alta_productos`, `ticket_alta_producto_partidas`, `ticket_alta_producto_movimientos`, `ticket_alta_producto_adjuntos`, `ticket_producto_destinatarios` | Solicitud de alta de producto; PK `id` | 77 solicitudes, 101 partidas, 207 movimientos, 79 adjuntos y 0 destinatarios. |
| `themes`, `theme_assignments`, `theme_audit`, `ui_temas` | Temas/UI, PK `id` | 3 themes, asignaciones/auditoría vacías y 3 temas UI. |

Las tablas listadas son 67; no se encontró `schema_migrations`. Bootstrap no ejecuta automáticamente `MigrationRunner`.

### Divergencias código ↔ esquema

Repositorios/migraciones referencian nombres que no están en la base actual: `usuario_empresas`, `usuario_almacenes`, `usuario_roles`, `credenciales_usuario`, `credencial_tokens`, `series_documentales`, `documentos_folios`, `movimientos_inventario`, `conceptos_movimiento_inventario`, `movimientos_inventario_detalle`, `existencias_producto`, `producto_series`, `existencias_serie`, `movimiento_detalle_series`, `mail_accounts`, `tickets_productos_correo_reglas`, `tickets_productos_correos`, `autorizaciones_precio`, `producto_precios_historial`, `producto_precios`, `producto_codigos_barras`, `producto_impuestos`, `tickets_productos`, `tickets_productos_partidas`, `tickets_productos_comentarios`, `tickets_productos_adjuntos`, `tickets_productos_eventos`. Los nombres reales sustitutos incluyen `usuario_rol`, `precios_productos`, `ticket_alta_productos` y tablas `inventario_*`. Es necesario cotejar cada consulta con el esquema antes de habilitar dichos flujos; este informe no propone cambiar datos ni esquema.

## 18. Flujo actual vs. objetivo comercial

| Paso objetivo | Estado | Soporte actual / brecha |
|---|---|---|
| Cliente llega a vCard/web | EXISTE | `/v/{slug}`, vista pública y privacidad. |
| Envía solicitud | PARCIAL | Formulario y proxy servidor→n8n; no persiste localmente. |
| Identificación automática del vendedor | PARCIAL | Se resuelve propietario y nombre para payload; falta guardar `usuario_id` en una entidad de solicitud. |
| Búsqueda producto | PARCIAL | API interna con coincidencia exacta/código y parciales; no precio/inventario. |
| Coincidencia exacta | EXISTE para código | Código normalizado contra `id_producto`. |
| Equivalencias | NO EXISTE funcionalmente | Tabla `producto_similares` existe sin datos; sin reglas aprobadas. |
| Oportunidad persistente | NO EXISTE | Sin tabla/modelo CRM. |
| Aviso al vendedor | PARCIAL | n8n puede ramificar/avisar; no hay bandeja local persistente. |
| Crear cotización | NO EXISTE | Sin cabecera/partidas comerciales. |
| Envío de cotización | NO EXISTE | No hay documento/estado de oferta. |
| Seguimiento/ganada/perdida | NO EXISTE | Sin actividades/estados CRM. |
| Reportes comerciales | NO EXISTE | Sin base persistida ni agregaciones. |

## 19. Dashboard por vendedor: requisitos faltantes

Para solicitudes, cotizaciones y seguimientos se requiere `usuario_id` propietario en cada entidad, índices por propietario/estado/fecha y políticas de alcance. Hoy las entidades de vCard solo conectan tarjeta con usuario; el payload externo lleva nombre, pero el ERP no conserva solicitud. Los tickets no son CRM y su esquema de código está desalineado. Empresa/almacén en `usuarios` permiten alcance organizacional inicial, pero la autorización “solo lo mío” debe basarse explícitamente en ID de vendedor y comprobarse en cada consulta.

El dashboard requeriría consultas separadas por estados para solicitudes nuevas, cotizaciones abiertas, seguimientos vencidos, interesados del Instituto ACR y actividad reciente; filtros por `usuario_id`, empresa/almacén y permisos de supervisor donde corresponda. No se deben mostrar tarjetas vacías con datos ficticios antes de crear fuentes reales.

## 20. Propuesta técnica por fases (sin implementar)

1. **Solicitudes/oportunidades:** persistir entrada de vCard antes de webhook, asignar `usuario_id`, slug/origen/detalles/estado/fechas, idempotencia y outbox/reintentos. Crear tabla y controller/service/repository. Riesgos: PII, duplicados, consentimiento, webhook caído. Dependencia: definir retención y estados.
2. **Dashboard vendedor:** convertir la closure en controller/service/repository; consultas agregadas por ID y alcance, actividad reciente. Riesgo de filtración entre vendedores. Depende de fase 1 y permisos/índices.
3. **Cotizaciones:** encabezado, partidas, cliente/contacto, snapshots de precio/impuesto/unidad, folio, moneda, vigencia, estado y PDF. Crear flujo y permisos específicos. Riesgos: precisión fiscal, historial de precio y concurrencia de folios.
4. **Notificaciones PWA:** preferencias, endpoint de suscripción, claves VAPID, almacenamiento seguro, worker y cola/outbox; además fallback correo. Riesgos de secretos, expiración y compatibilidad navegador. Depende de entidades/estados de fases anteriores.
5. **Equivalencias inteligentes:** definir relación aprobada entre productos, atributos normalizados, evidencia/motivo y control editorial; no inferir solo por texto. Riesgos de recomendar pieza incorrecta. Depende de datos técnicos y revisión comercial.
6. **CRM/seguimiento:** actividades, notas, tareas, asignación, transiciones ganada/perdida y auditoría. Riesgo de duplicar estados entre lead/cotización. Depende de un modelo de oportunidad unificado.
7. **Reportes:** métricas sobre estados normalizados, vendedor/empresa/almacén, conversión y tiempos; permisos y agregados. Depende de calidad histórica de las fases 1–6.

En todas las fases, primero reconciliar migraciones/repositorios con el esquema real; no dar por hecho que una migración describe producción.

## 21. Riesgos técnicos

- **Alto — divergencia de esquema:** múltiples repositorios apuntan a tablas antiguas ausentes; operaciones pueden fallar en runtime aunque las pantallas existan.
- **Alto — solicitud no durable:** fallo de n8n pierde el lead desde la perspectiva del ERP; no hay outbox.
- **Alto — vendedor solo como texto externo:** no queda propiedad estable para dashboard/seguimiento.
- **Alto — historial de autenticación de endpoint:** hubo 401 en staging según evidencia compartida; esta auditoría no verificó/corrigió el entorno remoto.
- **Medio — migraciones sin registro canónico:** no hay tabla de historial y bootstrap no ejecuta migrador automáticamente; difícil conocer el estado aplicado solo desde código.
- **Medio — dos generadores QR:** `VcardQrService` y `CredentialQrService` no producen necesariamente el mismo estilo/corrección; la credencial actualmente referencia QR público.
- **Medio — PHP 8.5:** `PublicVcardController` tiene llamada incondicional a `finfo_close()` según el código revisado, que PHP 8.5 depreca; `UserPhotoStorage` ya contempla compatibilidad.
- **Medio — respuestas de integración:** errores deben seguir siendo recuperables y sin falsos éxitos; no registrar secretos ni PII.
- **Medio — alcance RBAC:** algunas relaciones legacy de usuario/rol/almacén no coinciden con el esquema; validar todo acceso cross-user.
- **Medio — secretos:** `.env` no debe versionarse ni copiarse a paquetes; separar secreto entrante de API y secreto saliente del webhook.
- **Medio — correo/tickets:** configuración disponible, pero repositorios esperan tablas ausentes y pueden ocultar fallos de envío.
- **Bajo/medio — complejidad:** múltiples carpetas/nombres legacy y módulos parcialmente implementados dificultan distinguir código activo.
- **Configuración local:** `APP_DEBUG=true` facilita desarrollo pero no debe trasladarse a producción.

## 22. Archivos clave

- `public/index.php`, `bootstrap/app.php`: entrada y composición de dependencias.
- `app/Core/Request.php`, `Response.php`, `Router.php`: ciclo HTTP.
- `app/Http/Middlewares/*`: sesión, CSRF, permisos.
- `app/Http/Controllers/ProfileController.php`, `PublicVcardController.php`, `CredentialController.php`: perfil, vCard y credencial.
- `app/Domain/Vcards/VcardService.php`, `VcardPrivacyService.php`; `app/Infrastructure/Repositories/UserVcardRepository.php`, `VcardPrivacyRepository.php`, `VcardProductRepository.php`: publicación/privacidad/productos.
- `app/Infrastructure/Storage/UserPhotoStorage.php`: almacenamiento seguro.
- `app/Http/Controllers/N8nProductSearchController.php`, `app/Infrastructure/Repositories/N8nProductSearchRepository.php`: endpoint API de búsqueda.
- `app/Domain/Credentials/CredentialQrService.php`, `app/Domain/Vcards/VcardQrService.php`: QR; `app/Views/credentials/show.php`, `public/js/modules/credential.js`: credencial y PNG.
- `routes/web.php`, `routes/passkeys.php`, `routes/device-unlock.php`: rutas.
- `public/manifest.webmanifest`, `public/sw.js`, `public/js/pwa.js`: PWA.
- `app/Views/auth/private.php`: pantalla actual de `/app`.
- `app/Infrastructure/Repositories/ProductRepository.php`, `PricingRepository.php`, `InventoryRepository.php` y repositorios de Tickets/Folios: priorizar auditoría de esquema antes de activar.

## 23. Tablas clave y preguntas pendientes

**Tablas clave:** `usuarios`, `usuario_rol`, `roles`, `rol_permiso`, `permisos`, `usuario_vcards`, `usuario_vcard_productos`, `productos`, `marcas`, `unidades_medida`, `lineas_producto`, `precios_productos`, `producto_similares`, `ticket_alta_productos`, `auditoria_eventos`, `empresas`, `almacenes` y grupo `inventario_*`.

**Preguntas para arquitectura/producto:**

1. ¿La fuente de verdad del lead será ERP o n8n? Recomendación: persistir primero en ERP y usar outbox para integración.
2. ¿Qué equipo/rol puede reasignar vendedor y consultar datos de otros vendedores?
3. ¿Qué consentimiento, retención y eliminación aplican a teléfonos/correos de prospectos?
4. ¿Cómo se definen estados y SLA de solicitud, cotización e Instituto ACR?
5. ¿Precios de cotización dependen de lista, empresa, moneda, impuestos, almacén o aprobación?
6. ¿Qué datos técnicos de producto son obligatorios y quién valida equivalencias?
7. ¿Instituto ACR tendrá operación dentro del ERP o solo seguirá como canal externo?
8. ¿Cuáles migraciones y snapshots son la fuente canónica del esquema desplegado?
9. ¿Qué nivel de soporte real de PHP 8.5 se exige y en qué hosting/runtime se ejecutará?

---

**Método:** inspección estática de código/configuración y consultas `SELECT` a la BD local autorizada. No se ejecutaron migraciones, escrituras, pruebas HTTP, POST, pruebas al webhook ni llamadas a servidores externos. Los datos de negocio sensibles se omitieron; las conclusiones de staging se limitan a evidencias previas proporcionadas por el usuario.
