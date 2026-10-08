# Convenciones del proyecto

## Estado del documento

- Fase: `DOCS-0`.
- Estado: propuesta base.
- Alcance: nombres, organización, estilo, Git y disciplina de cambios.
- No autoriza implementar código.

## Idioma

- Código, clases y namespaces: inglés.
- Tablas, columnas y permisos: español en minúsculas, conforme al dominio
  definido para este ERP.
- Documentación y mensajes de usuario: español claro.
- Términos técnicos consolidados pueden mantenerse en inglés.
- No mezclar dos nombres distintos para el mismo concepto.

## PHP

Cuando se apruebe la fase de código:

- PHP 8.x con `declare(strict_types=1);`.
- PSR-12 como base de formato.
- Una clase principal por archivo.
- Nombre de archivo igual al nombre de clase.
- Tipos explícitos en parámetros y retornos cuando sean aplicables.
- Dependencias recibidas explícitamente; evitar estado global.
- No usar funciones o variables globales para reglas de negocio.
- No ocultar errores con el operador `@`.
- Comparaciones estrictas cuando el tipo sea conocido.
- Fechas mediante objetos inmutables o una abstracción aprobada.
- Importes monetarios sin aritmética binaria imprecisa.

La estrategia de namespaces y autoload se decidirá en `CONFIG-0`.

## Nombres de clases

| Tipo | Convención | Ejemplo conceptual |
|---|---|---|
| Controller | singular + `Controller` | `UserController` |
| Service | responsabilidad + `Service` | `UserScopeService` |
| Repository | entidad + `Repository` | `UserRepository` |
| Validator | caso de uso + `Validator` | `CreateUserValidator` |
| Middleware | control + `Middleware` | `PermissionMiddleware` |
| Policy | recurso + `Policy` | `TicketPolicy` |
| Exception | causa + `Exception` | `ScopeDeniedException` |
| DTO | caso de uso + `Data` o `DTO` | pendiente de aprobar |

No se usarán nombres genéricos como `Helper`, `Manager`, `Util` o `Common` sin
una responsabilidad única y documentada.

## Controllers

- Un método representa un caso de uso HTTP.
- No contiene SQL.
- No coordina múltiples escrituras directamente.
- No instancia PDO, PHPMailer o adaptadores.
- No decide alcance usando únicamente IDs recibidos.
- No devuelve trazas o mensajes internos.

Los nombres de acciones deben ser consistentes; la convención exacta
`index/show/create/store/edit/update/delete` se aprobará junto con Router.

## Services

- Contienen reglas de negocio y coordinan transacciones.
- Reciben datos validados y contexto del actor.
- Devuelven resultados explícitos.
- No leen directamente superglobales.
- Invocan auditoría, folios, permisos y alcance cuando corresponda.
- No envían SMTP directamente.

Un Service no debe convertirse en una clase con responsabilidades de varios
módulos.

## Repositories

- Son la única capa con SQL de aplicación.
- Usan PDO y prepared statements.
- No reciben fragmentos SQL arbitrarios.
- Ordenamientos y campos dinámicos usan whitelist.
- Las consultas de datos operativos incluyen alcance.
- No realizan autorización visual ni generan HTML.
- Sus métodos expresan intención, no detalles de pantalla.

## Validators

- Normalizan antes de validar cuando la regla lo requiera.
- Distinguen campo ausente, vacío y nulo.
- No convierten silenciosamente datos inválidos en valores válidos.
- Devuelven mensajes seguros y claves de campo estables.
- La validación de negocio que necesita persistencia permanece en Services.

## Policies

- Expresan reglas contextuales específicas de un recurso.
- Complementan permisos y alcance.
- No sustituyen `PermissionService` ni `UserScopeService`.
- No consultan superglobales o generan respuestas HTTP.

## Vistas

- Extensión `.php`.
- Organización por módulo.
- Layouts y parciales reutilizables en sus carpetas.
- Toda salida dinámica se escapa en su contexto.
- Formularios mutables contienen token CSRF.
- No contienen SQL.
- No contienen lógica de negocio.
- No incluyen grandes bloques de CSS o JavaScript.
- Los atributos `data-*` solo transportan datos de interfaz no sensibles.

## Rutas

- URI en minúsculas y con guiones.
- Nombres internos estables por módulo y acción.
- Métodos HTTP coherentes: `GET` consulta; métodos mutables modifican estado.
- No usar `GET` para cancelar, eliminar, aprobar o ejecutar.
- Toda ruta declara visibilidad y middlewares.
- IDs en URL no se consideran secretos y siempre requieren autorización.

Ejemplos conceptuales:

```text
GET    /usuarios
POST   /usuarios
PATCH  /usuarios/{id}
POST   /usuarios/{id}/desactivar
```

Las rutas definitivas se aprobarán en la fase correspondiente.

## Permisos

Formato:

```text
modulo.accion
modulo.recurso.accion
```

Reglas:

- Minúsculas.
- Sin espacios ni acentos.
- Código inmutable después de publicarse, salvo migración controlada.
- Lectura, edición, aprobación, exportación y descarga se separan.
- Cada permiso nuevo requiere seed en su fase.
- La descripción visible no sustituye el código.

## Base de datos

- Tablas y columnas en `snake_case`.
- Tablas en plural.
- Llave primaria: `id`.
- Llave foránea: `<entidad_singular>_id`.
- Fechas de auditoría: `creado_en`, `actualizado_en`.
- Actores de auditoría: `creado_por`, `actualizado_por`.
- Estado lógico: `activo`.
- Eliminación lógica, si aplica: `eliminado_en`, `eliminado_por`.
- Nombres de índices y restricciones deberán ser descriptivos y consistentes.

No se guardarán roles, empresas o almacenes como listas dentro de
`usuario_perfiles`.

## Migraciones

Convención propuesta:

```text
<fase>_<secuencia>_<descripcion>.<extension-aprobada>
```

Ejemplo no ejecutable:

```text
bd_core_0_001_crear_usuarios
```

Reglas:

- Una migración pertenece a una fase.
- No reescribir una migración ya aplicada sin plan explícito.
- Toda migración tendrá estrategia de rollback.
- No mezclar tablas operativas de fases futuras.
- No crear SQL fuera de `database/migrations`.
- El formato ejecutable y runner quedan pendientes de aprobación.

## Seeds

- Ubicación exclusiva en `database/seeds`.
- Separar datos estructurales de datos de prueba.
- Deben ser repetibles o detectar duplicados de forma segura.
- Los permisos se crean mediante seed versionado.
- No contienen usuarios reales, contraseñas reales o información productiva.
- Un seed pertenece a una fase aprobada.

## DB-TEST

- Ubicación exclusiva en `database/tests`.
- Nombre relacionado con la fase.
- Debe declarar prerrequisitos, pasos, resultado y limpieza.
- Incluye pruebas válidas e inválidas.
- Nunca apunta por defecto a producción.
- La base seleccionada se verifica antes de cualquier escritura.
- No se mezcla con datos de desarrollo permanentes.

## CSS

- Fuente de Tailwind en `resources/css`.
- CSS desplegable bajo `public/css`.
- `public/css/app.css` será el bundle final cuando se apruebe.
- `core/` contiene reglas transversales.
- `modules/` contiene estilos propios de módulos.
- `themes/` contiene tokens o variantes controladas.
- No usar CDN de Tailwind en producción.
- No usar `!important` como mecanismo habitual.
- Los nombres propios seguirán una convención documentada en la fase UI.

## JavaScript

- ES modules cuando la compatibilidad objetivo lo permita.
- Código reutilizable en `public/js/modules`.
- Inicializadores de pantalla en `public/js/pages`.
- Sin jQuery ni frameworks SPA.
- Sin lógica de permisos real.
- Sin secretos o configuración privada.
- Acciones mutables envían CSRF y manejan errores seguros.
- No insertar HTML no confiable mediante APIs inseguras.

## Archivos

- Archivos privados en `storage/uploads`.
- Nombres internos generados por el servidor.
- Rutas físicas no se guardan o muestran como URLs públicas.
- Los assets genuinamente públicos viven en `public/images`.
- Los archivos temporales deben eliminarse por un proceso controlado futuro.

## Git

Ramas:

```text
main
codex/<fase-o-cambio>
```

Commits:

```text
init:
docs:
config:
security:
db:
auth:
users:
scope:
ui:
js:
mail:
tickets:
fix:
qa:
deploy:
```

Reglas:

- Un commit debe representar una unidad revisable.
- No mezclar varias fases sensibles en un commit.
- No versionar `.env`, dependencias locales, logs, uploads, dumps o respaldos.
- Revisar `git status`, `git diff` y pruebas antes de commit.
- No reescribir trabajo ajeno sin autorización.
- No hacer deploy desde un árbol sucio.

## Documentación

- Cada decisión vinculante debe indicar fase y estado.
- Las propuestas no aprobadas se marcan explícitamente.
- Las rutas se escriben relativas a la raíz del repositorio.
- No incluir credenciales, dumps o datos reales.
- Un cambio de arquitectura actualiza el documento correspondiente.
- Los criterios de aceptación deben ser verificables.

## Reglas obligatorias

- Mantener una responsabilidad por capa y componente.
- Usar convenciones estables antes de crear APIs públicas.
- Versionar migraciones, seeds y DB-TEST por fase.
- Mantener secretos y runtime fuera de Git.
- Documentar cualquier excepción antes de implementarla.
- Preservar separación entre permiso y alcance.
- Mantener commits pequeños y revisables.

## Pendiente de aprobar

- Namespace raíz y autoload.
- Herramienta de formato y análisis estático.
- Convención definitiva de DTOs.
- Nombres de métodos de Controllers.
- Formato ejecutable de migraciones y seeds.
- Runner de pruebas.
- Convención CSS propia y navegadores soportados.
- Política de versionado y releases.
- Rama estable inicial y estrategia de merge.

## Fuera de alcance de esta fase

- Formatear código PHP inexistente.
- Modificar `package.json` o `package-lock.json`.
- Crear scripts npm.
- Crear clases, rutas o vistas funcionales.
- Crear migraciones, seeds o SQL.
- Hacer commits, merges o despliegues.
