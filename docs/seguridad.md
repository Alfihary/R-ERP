# Seguridad base del ERP

## Estado del documento

- Fase base: `DOCS-0`.
- Estado actual: `AUTH-0 + RBAC-0 + UI-SHELL-0 + DB-SCOPE-1` cerradas y
  `SCOPE-SERVICE-1` en revisión.
- Alcance: sesión segura, CSRF, escape HTML, middleware, errores, headers,
  login por email/username, logout y ruta privada mínima.
- Permisos de módulos, contexto activo y módulos funcionales siguen fuera de
  alcance.

## Objetivo

Establecer controles verificables para proteger autenticación, autorización,
alcance multiempresa/multialmacén, formularios, consultas, archivos, errores,
logs e integraciones.

## Modelo de amenazas inicial

Los riesgos prioritarios son:

1. Fuga de datos entre empresas o almacenes.
2. Escalamiento de privilegios por permisos incompletos.
3. Referencias directas inseguras a recursos.
4. CSRF en operaciones mutables.
5. XSS por salida sin escape.
6. Inyección SQL en filtros, ordenamientos o consultas.
7. Fijación o robo de sesión.
8. Carga o exposición de archivos privados.
9. Exposición de secretos, trazas y datos sensibles en logs.
10. Uso indebido de exportaciones, descargas y acciones administrativas.
11. Reintentos o concurrencia que dupliquen operaciones y folios.
12. SMTP o endpoints internos expuestos sin control.

## Capas de control

La seguridad no depende de una sola capa:

```text
ruta
  -> middleware de autenticación
  -> middleware CSRF, cuando aplica
  -> middleware de permiso
  -> resolución de alcance
  -> validación del caso de uso
  -> consulta filtrada
  -> auditoría
  -> salida escapada
```

Ocultar botones mejora la experiencia, pero no concede ni revoca autorización.

## Autenticación

AUTH-0 implementa:

- Usar `password_hash()` y `password_verify()`.
- No almacenar contraseñas reversibles.
- Regenerar el ID de sesión después del login.
- Invalidar la sesión en logout.
- Aplicar respuesta uniforme ante credenciales inválidas.
- Consultar por email o username normalizado mediante PDO preparado.
- Exigir usuario activo y no eliminado.
- Guardar en sesión solo ID, username y email.
- Crear y rotar el administrador inicial desde CLI sin imprimir secretos.

El registro persistente de intentos, bloqueo temporal, recuperación segura e
invalidación global de sesiones requieren fases posteriores expresamente
aprobadas.

La existencia de un usuario no debe poder inferirse por diferencias evitables
en mensajes públicos.

## Sesiones

SECURITY-0 implementa:

- Cookie `HttpOnly`.
- Cookie `Secure` forzada en producción o cuando `APP_URL` usa HTTPS.
- `SameSite=Lax` configurable.
- Tiempo máximo del almacenamiento de sesión configurable.
- Método central para regenerar el identificador.
- Protección contra fijación de sesión.
- Identificador de sesión fuera de URLs.

La expiración por inactividad, duración absoluta e invalidación ligada a
usuarios se definirán en una fase posterior de política de sesión.

No se guardarán permisos completos en sesión sin una estrategia explícita de
invalidación. El backend deberá poder reflejar cambios de permisos y alcance.

## Autorización por acción

RBAC-0 implementa `PermissionService`, `PermissionRepository` y
`PermissionMiddleware`. La consulta exige usuario, asignación de rol, rol,
relación rol-permiso y permiso activos y no eliminados. Los permisos no se
guardan en sesión y `ADMIN` no recibe un bypass implícito.

Los únicos permisos creados en esta fase son:

```text
sistema.acceder
sistema.app.ver
seguridad.rbac.ver
```

`GET /app` exige `sistema.app.ver`. Un usuario sin sesión se redirige a
`/login`; un usuario autenticado sin permiso recibe `403`.

Cada acción tendrá un código estable, por ejemplo:

```text
usuarios.ver
usuarios.crear
usuarios.editar
usuarios.desactivar
usuarios.roles.asignar
usuarios.empresas.asignar
usuarios.almacenes.asignar
empresas.ver
empresas.crear
empresas.editar
empresas.desactivar
almacenes.ver
almacenes.crear
almacenes.editar
almacenes.desactivar
productos.ver
productos.crear
productos.editar
productos.desactivar
productos.importar
productos.exportar
productos.archivos.subir
productos.archivos.descargar
productos.archivos.eliminar
productos.costos.ver
productos.precios.ver
inventario.ver
inventario.ajustar
inventario.traspasar
inventario.cancelar
inventario.movimientos.ejecutar
inventario.exportar
tickets.ver
tickets.crear
tickets.editar
tickets.cancelar
tickets.aprobar
tickets.rechazar
tickets.archivos.subir
tickets.archivos.descargar
tickets.archivos.eliminar
temas.ver
temas.crear
temas.editar
temas.desactivar
temas.establecer_default
temas.asignar_usuario
reportes.ver
reportes.exportar
```

La matriz completa se aprobará por módulo. Los permisos de lectura sensible,
costos, exportación, descarga, aprobación y administración deben permanecer
separados.

ADMIN podrá recibir todos los permisos o un bypass controlado. En ambos casos,
sus acciones críticas deberán auditarse.

## Alcance operativo

DB-SCOPE-1 persiste empresas, almacenes y asignaciones de alcance. La relación
`usuario_almacenes` exige mediante FKs compuestas que el usuario tenga acceso a
la empresa y que el almacén pertenezca a esa misma empresa. Esto protege la
integridad persistente, pero no sustituye la validación de cada caso de uso.

SCOPE-SERVICE-1 implementa `UserScopeService` como fuente central para resolver
empresas y almacenes efectivos. La resolución exige usuario, relaciones,
empresas y almacenes activos y no eliminados. El servicio recibe únicamente el
ID autenticado y filtra defensivamente almacenes fuera de empresas permitidas.

La fase devuelve empresas, almacenes, valores predeterminados y banderas de
disponibilidad. No guarda el alcance completo en sesión y no acepta IDs del
navegador.

Los módulos futuros deberán ampliar la validación para distinguir:

- Alcance global.
- Empresa visible.
- Empresa operable.
- Almacén visible.
- Almacén operable.
- Acceso consolidado solo lectura.
- Restricciones contextuales del recurso.

Reglas:

- El permiso no reemplaza el alcance.
- El alcance no concede el permiso.
- `empresa_id` y `almacen_id` enviados por el cliente son datos no confiables.
- Listados deben filtrar desde la consulta, no después de recuperar datos.
- Lectura por ID debe incluir o verificar alcance.
- Escrituras deben validar tanto el recurso actual como el destino.
- Exportaciones y descargas aplican el mismo alcance que la vista fuente.
- Un almacén debe pertenecer a una empresa permitida.
- El acceso global debe ser explícito y auditable.

## CSRF

Todo `POST`, `PUT`, `PATCH` o `DELETE` deberá:

- Requerir token generado por el servidor.
- Validar token en una capa central.
- Rechazar token ausente, expirado o incorrecto.
- No registrar el token en logs.
- Renovar el token conforme a la estrategia aprobada.
- Responder con error seguro.

Los endpoints AJAX no estarán exentos. Las operaciones idempotentes no deberán
usar `GET` para modificar estado.

SECURITY-0 aplica esta validación mediante middleware central a `POST`, `PUT`,
`PATCH` y `DELETE`. El token puede llegar en `_token` o `X-CSRF-Token`. El
helper `csrf_field()` genera el campo oculto para formularios futuros.

## XSS y salida segura

- Todo texto dinámico se escapará en el contexto correcto.
- Se aprobará un helper `e()` para HTML.
- Atributos, URLs, JavaScript y CSS requieren tratamiento específico.
- Sanitizar entrada no sustituye escapar salida.
- El HTML enriquecido estará prohibido salvo aprobación por módulo y una
  whitelist explícita.
- Las plantillas de correo no aceptarán PHP ni JavaScript.
- Los mensajes de validación no reflejarán contenido peligroso sin escape.

SECURITY-0 incorpora el helper `e()` y actualiza la vista mínima para usarlo.
El escape de URL, JavaScript, CSS o HTML enriquecido requerirá controles
específicos en la fase que introduzca esos contextos.

## SQL Injection

- PDO y prepared statements serán obligatorios.
- Se prohíbe concatenar valores del usuario.
- Nombres de columna, dirección de orden y operadores se resolverán mediante
  whitelist; no pueden parametrizarse como valores.
- Paginación y límites se validarán como enteros dentro de rangos.
- Las consultas residirán en Repositories.
- Las consultas operativas incorporarán alcance en la propia consulta o
  validarán el recurso de forma equivalente y demostrable.
- Las credenciales de BD usarán privilegios mínimos.

## Validación de entrada

- La validación de servidor es obligatoria.
- Normalización y validación son pasos distintos.
- Se definirán Validators por caso de uso.
- Campos desconocidos se descartarán o rechazarán según contrato.
- IDs, fechas, decimales, monedas, estados y enumeraciones tendrán reglas
  explícitas.
- Los mensajes públicos serán útiles sin revelar detalles internos.
- La validación frontend solo mejora UX.

## Archivos privados

Uploads y documentos privados:

- Se almacenarán bajo `storage/`.
- Se validará tamaño máximo.
- Se validará extensión permitida.
- Se verificará MIME real con `finfo`.
- Se generará un nombre interno no controlado por el usuario.
- Se conservará el nombre original solo como metadato escapado.
- Se rechazará doble extensión peligrosa.
- No se ejecutará contenido subido.
- La descarga pasará por Controller y Service.
- Permiso, alcance y estado del archivo se validarán antes de leerlo.
- Se registrarán descargas sensibles cuando aplique.

No se expondrán rutas físicas en respuestas o vistas.

## Secretos y configuración

- Los secretos vivirán en `.env` o en configuración externa al repositorio.
- `.env.example` solo documentará nombres y valores no secretos.
- SMTP no se almacenará en tablas.
- No se versionarán credenciales, tokens o claves.
- Configuración local y producción permanecerán separadas.
- `APP_DEBUG` deberá ser `false` en producción.
- La rotación de secretos tendrá procedimiento de contingencia.

## Errores y logs

- Producción mostrará páginas genéricas.
- Los detalles se registrarán en `storage/logs`.
- Se definirán niveles de log y retención.
- Se evitarán contraseñas, hashes de recuperación, cookies completas, tokens,
  secretos SMTP, cuerpos de archivos y datos personales innecesarios.
- Los errores de SMTP y BD se traducirán antes de llegar al usuario.
- El sistema deberá poder correlacionar un error público con un registro interno
  sin revelar la traza.

## Headers HTTP

La fase de seguridad evaluará e implementará como mínimo:

- `X-Content-Type-Options: nosniff`.
- Política anti-framing mediante CSP `frame-ancestors` y, como compatibilidad,
  `X-Frame-Options`.
- `Referrer-Policy`.
- `Content-Security-Policy` compatible con los assets reales.
- `Permissions-Policy` mínima.
- HSTS solo después de confirmar HTTPS permanente en todos los subdominios
  afectados.

SECURITY-0 implementa CSP básica compatible con la vista mínima,
`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` y una
`Permissions-Policy` restrictiva. HSTS permanece fuera hasta validar HTTPS en
el entorno de despliegue.

## Auditoría

Se auditarán, cuando aplique:

- Login exitoso y fallido.
- Logout administrativo o invalidación de sesiones.
- Cambios de contraseña.
- Alta, edición y desactivación de usuarios.
- Cambios de roles, permisos y alcance.
- Acciones de ADMIN.
- Exportaciones y descargas sensibles.
- Cambios de configuración y temas.
- Generación o cancelación de folios.
- Aprobaciones, rechazos y cancelaciones.
- Reintentos o cancelaciones manuales de correo.

Una auditoría debe indicar actor, acción, recurso, fecha, resultado y contexto de
alcance; no debe convertirse en copia indiscriminada de datos sensibles.

## Pruebas mínimas futuras

Cada fase funcional deberá incluir casos negativos:

- Ruta privada sin sesión.
- Acción sin permiso.
- Acción con permiso pero fuera de alcance.
- Lectura directa de recurso de otra empresa.
- Escritura con empresa o almacén alterado.
- POST sin CSRF y con CSRF inválido.
- XSS almacenado y reflejado.
- Filtros y ordenamientos manipulados.
- Upload con extensión, MIME o tamaño inválido.
- Descarga sin permiso o alcance.
- Producción sin trazas.
- Auditoría de acción crítica.

## Reglas obligatorias

- Toda ruta privada usa autenticación.
- Toda acción sensible usa permiso por acción.
- Toda operación multiempresa/multialmacén valida alcance.
- Toda operación mutable valida CSRF.
- Toda consulta usa PDO y parámetros preparados.
- Toda salida dinámica se escapa.
- Todo archivo privado queda fuera de `public/`.
- Toda exportación y descarga tiene control explícito.
- Toda acción crítica se audita.
- Producción no muestra información sensible.
- Frontend y JavaScript nunca sustituyen controles backend.

## Pendiente de aprobar

- Política completa de contraseñas; AUTH-0 exige 12 caracteres solo para el
  administrador inicial.
- Duraciones exactas de sesión y bloqueo.
- Estrategia de recuperación de contraseña.
- Contrato de contexto activo de empresa y almacén.
- Matriz completa de permisos por rol.
- Campos exactos de auditoría y retención.
- CSP definitiva.
- Límites y tipos de upload por módulo.
- Política de privacidad y clasificación de datos.
- Pruebas automatizadas y herramientas de análisis.

## Fuera de alcance de esta fase

- Implementar recuperación.
- Crear tablas de seguridad.
- Ejecutar pruebas de penetración.
- Configurar headers directamente en el servidor web.
- Subir o servir archivos.
- Crear usuarios adicionales, roles o permisos de módulos operativos.
- Modificar producción o AwardSpace.
