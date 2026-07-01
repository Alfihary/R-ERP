# Arquitectura base del ERP

## Estado del documento

- Fase: `ARCH-0`.
- Estado: propuesta materializada para revisión.
- Alcance: decisiones arquitectónicas y límites entre capas.
- No representa autorización para implementar código funcional.

## Objetivo

Definir una arquitectura MVC modular propia para un ERP PHP 8.x/MySQL que pueda
crecer sin mezclar HTTP, negocio, persistencia, presentación e infraestructura.
El sistema operará múltiples empresas y almacenes en una sola base de datos.

## Principios arquitectónicos

1. Monolito modular desplegable como una unidad.
2. Una sola base de datos con separación lógica estricta.
3. Renderizado principal en PHP.
4. Mejora progresiva con JavaScript vanilla.
5. Dependencias dirigidas hacia el dominio, no hacia la interfaz.
6. Seguridad y alcance aplicados en backend.
7. Transacciones coordinadas en Services.
8. SQL encapsulado en Repositories.
9. Integraciones externas detrás de contratos propios.
10. Cambios entregados en fases pequeñas y autorizadas.

No se adoptarán Laravel, microservicios, SPA, React, Vue, Angular, jQuery ni
Bootstrap como framework visual.

## Árbol base

```text
app/
├── Core/
├── Http/
│   ├── Controllers/
│   └── Middlewares/
├── Domain/
│   ├── Auth/
│   ├── Users/
│   ├── Companies/
│   ├── Warehouses/
│   ├── Security/
│   ├── Folios/
│   ├── Themes/
│   ├── Products/
│   ├── Inventory/
│   ├── Tickets/
│   ├── Mail/
│   └── Notifications/
├── Infrastructure/
│   ├── Database/
│   ├── Repositories/
│   ├── Mail/
│   └── Notifications/
├── Support/
│   ├── Validation/
│   ├── Security/
│   ├── Files/
│   └── Mail/
└── Views/
    ├── layouts/
    ├── partials/
    ├── auth/
    ├── usuarios/
    ├── empresas/
    ├── almacenes/
    ├── productos/
    ├── inventario/
    ├── tickets/
    ├── emails/
    └── notificaciones/

config/
routes/
database/
├── migrations/
├── seeds/
└── tests/
docs/
tests/
public/
├── css/
│   ├── core/
│   ├── modules/
│   └── themes/
├── js/
│   ├── modules/
│   └── pages/
└── images/
resources/
└── css/
storage/
├── uploads/
├── logs/
├── cache/
├── temp/
└── mail/
    ├── previews/
    └── logs/
```

Los directorios de módulos representan límites futuros, no módulos
implementados. Los archivos `.gitkeep` existen únicamente para conservar el
árbol vacío.

## Responsabilidad de cada área

### `app/Core`

Contendrá las primitivas mínimas del framework propio: aplicación, router,
request, response, sesión, vista, contenedor o resolución explícita de
dependencias y manejo central de errores.

`Core` no debe contener reglas de productos, inventario, tickets u otros
módulos. Tampoco debe depender de un módulo de dominio concreto.

### `app/Http/Controllers`

Los Controllers:

- Reciben una Request ya normalizada.
- Orquestan el caso de uso HTTP.
- Invocan Validators y Services.
- Seleccionan Response o View.
- No ejecutan SQL.
- No abren transacciones.
- No contienen reglas de negocio pesadas.
- No confían en IDs de empresa o almacén enviados por el cliente.

La protección de ruta se declarará centralmente y se volverá a validar donde la
regla de negocio lo requiera.

### `app/Http/Middlewares`

Los Middlewares resolverán preocupaciones HTTP transversales:

- Autenticación.
- Invitados.
- CSRF.
- Permisos por acción.
- Alcance de empresa o almacén cuando sea determinable desde la ruta.
- Headers de seguridad.
- Límite de solicitudes cuando se apruebe.

El middleware de alcance no sustituye la validación de `UserScopeService` dentro
del caso de uso.

### `app/Domain`

Contendrá reglas, servicios, entidades conceptuales y contratos propios del
negocio. Cada módulo solo debe exponer una superficie explícita.

Los módulos pueden colaborar mediante Services o eventos de dominio
documentados. No deben acceder directamente a tablas privadas de otro módulo
desde Controllers o Views.

### `app/Infrastructure`

Contendrá adaptadores concretos:

- Conexión PDO.
- Implementaciones de Repositories.
- Adaptadores SMTP o modo log.
- Persistencia de notificaciones.
- Integraciones externas futuras.

La infraestructura implementa contratos definidos hacia el dominio o la
aplicación. No decide permisos ni alcance por sí sola.

### `app/Support`

Contendrá utilidades compartidas que no expresan negocio:

- Validación y normalización.
- Escape y seguridad auxiliar.
- Gestión segura de archivos.
- Renderizado y validación auxiliar de plantillas de correo.

No debe convertirse en un contenedor general de código sin propietario.

### `app/Views`

Las Views:

- Renderizan HTML.
- Escapan salida por defecto con el helper aprobado.
- Incluyen token CSRF en formularios mutables.
- Pueden ocultar acciones según permisos para mejorar UX.
- No ejecutan SQL ni lógica de autorización real.
- No contienen bloques extensos de JavaScript.
- No imprimen rutas físicas, secretos o trazas.

### `config` y `routes`

`config` contendrá configuración normalizada obtenida del entorno. `routes`
declarará método, URI, handler y cadena de middlewares.

Ninguno de estos directorios debe ser público o contener secretos versionados.

### `database`

- `migrations`: cambios estructurales versionados por fase.
- `seeds`: datos iniciales controlados.
- `tests`: DB-TEST de la fase correspondiente.

No se permite una migración global que construya todo el ERP.

### `public`

Será el único punto público. Alojará el entry point futuro y assets públicos.
Nunca contendrá `.env`, configuración, uploads privados, logs, respaldos o SQL.

### `resources`

Contendrá fuentes locales que necesitan compilación, especialmente el CSS fuente
de Tailwind. No debe publicarse como raíz web.

### `storage`

Contendrá datos privados o transitorios. Todo contenido real está ignorado en
Git; solo se versiona el árbol vacío.

## Flujo de una solicitud

```text
public/index.php (futuro)
  -> bootstrap
  -> Router
  -> SecurityHeadersMiddleware
  -> AuthMiddleware
  -> CsrfMiddleware, si modifica estado
  -> PermissionMiddleware
  -> ScopeMiddleware, si aplica
  -> Controller
  -> Validator
  -> Service
  -> PermissionService y UserScopeService
  -> Repository
  -> PDO/MySQL
  -> AuditService, si la acción es crítica
  -> View o Response
```

La presencia de un control en middleware no autoriza omitirlo en el Service
cuando el recurso se determina durante el caso de uso.

## Dependencias permitidas

- Views reciben datos preparados; no llaman Repositories.
- Controllers pueden depender de Validators y Services.
- Services pueden depender de contratos de Repositories y servicios
  transversales.
- Repositories pueden depender de PDO y mapeadores, no de Controllers o Views.
- `Core` no depende de módulos operativos.
- Infraestructura no decide reglas de negocio.
- Un módulo no instancia directamente adaptadores SMTP, almacenamiento o PDO.

## Servicios transversales previstos

### `PermissionService`

Resolverá permisos por acción. Un permiso habilita una capacidad, pero no amplía
el alcance del usuario.

### `UserScopeService`

SCOPE-SERVICE-1 resuelve:

- Empresas y almacenes activos asignados al usuario autenticado.
- Valores predeterminados derivados del alcance disponible.
- Estado vacío controlado.
- Rechazo defensivo de almacenes fuera de empresas permitidas.

El contexto activo, los filtros de módulos, la validación de recursos y el
alcance de exportaciones o descargas se implementarán en sus fases autorizadas.

### `ScopeContextService`

SCOPE-CONTEXT-1 mantiene un único par empresa/almacén activo. Depende de
`UserScopeService`, valida el par antes de guardarlo y conserva únicamente dos
IDs en sesión.

El contexto activo es una preferencia operativa validada, no una autorización
de recurso. Los Repositories futuros seguirán obligados a filtrar por alcance y
los Services deberán validar permiso, alcance, recurso y regla contextual.

La autorización efectiva será:

```text
autenticación + permiso + alcance + estado del recurso + regla contextual
```

### `AuditService`

Registrará acciones críticas sin almacenar contraseñas, tokens, secretos o
contenido privado innecesario.

### `FolioService`

Generará consecutivos dentro de la transacción de negocio, con bloqueo de la
serie correspondiente y unicidad por las dimensiones aprobadas.

### Mail y Notifications

La publicación de un evento no enviará SMTP directamente. El subsistema central
resolverá reglas, plantillas, cola, envío, logs y auditoría.

## Transacciones y consistencia

- El Service que coordina varias escrituras es dueño de la transacción.
- Un Repository no debe confirmar parcialmente una operación compuesta.
- Un folio definitivo se reservará dentro de la misma transacción.
- Los efectos externos se ejecutarán después del commit o mediante cola.
- Una auditoría crítica debe quedar coordinada con el resultado del caso de uso.
- Los reintentos deben ser idempotentes cuando el proceso pueda repetirse.

## Estrategia de vistas, CSS y JavaScript

- PHP renderizará layout, navegación, formularios y tablas.
- `resources/css/input.css` será la fuente futura de Tailwind.
- `public/css/app.css` será el artefacto compilado y versionable para deploy.
- `public/css/core`, `modules` y `themes` alojarán CSS público aprobado.
- `public/js/main.js` será el punto de inicialización futuro.
- `public/js/modules` contendrá comportamiento reutilizable.
- `public/js/pages` contendrá inicializadores específicos por página.
- JavaScript nunca será la única validación de permisos, alcance o datos.

## Errores y observabilidad

- Los errores técnicos se transformarán en respuestas seguras.
- Producción no mostrará stack traces.
- Los logs vivirán fuera de `public/`.
- Cada registro tendrá contexto mínimo y un identificador de correlación cuando
  se apruebe su diseño.
- No se registrarán credenciales, cookies completas, tokens CSRF, tokens de
  recuperación ni cuerpos privados indiscriminadamente.

## Reglas obligatorias

- Respetar el flujo de dependencias definido.
- Aplicar autorización y alcance en backend.
- Mantener SQL en Repositories y negocio en Services.
- Escapar salida en Views.
- Mantener archivos privados fuera de `public/`.
- Coordinar transacciones desde Services.
- Centralizar auditoría, folios, correo y notificaciones.
- No crear dependencias circulares entre módulos.
- No avanzar a un módulo operativo sin sus prerrequisitos aprobados.

## Pendiente de aprobar

- Clases concretas del bootstrap y del router.
- Estrategia de autoload y Composer.
- Contratos e interfaces exactos.
- Convención definitiva de namespaces.
- Modelo de excepciones y respuestas.
- Diseño de `AuditService` y `FolioService`.
- Entry point y configuración funcional.
- Arquitectura interna de cada módulo operativo.

## Fuera de alcance de esta fase

- Crear `public/index.php`.
- Implementar clases, rutas o controladores.
- Configurar PDO o conectar MySQL.
- Crear migraciones, seeds o DB-TEST ejecutable.
- Implementar autenticación, autorización o sesiones.
- Construir módulos de negocio.
- Compilar CSS, ejecutar JavaScript o desplegar.
