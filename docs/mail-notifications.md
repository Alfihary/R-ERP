# Arquitectura de Mail y Notifications

## Estado del documento

- Fase: `DOCS-0`.
- Estado: diseño conceptual.
- No existen servicios, tablas, plantillas, SMTP ni integración funcional.
- Tickets y otros módulos no están autorizados.

## Objetivo

Centralizar correos transaccionales y notificaciones para evitar que cada módulo
implemente SMTP, plantillas, reintentos, logs y seguridad de forma distinta.

## Regla principal

Un módulo operativo nunca enviará correo directamente.

Flujo prohibido:

```text
TicketService -> PHPMailer
```

Flujo objetivo:

```text
caso de uso
  -> registra evento aprobado
  -> NotificationService
  -> NotificationRuleService
  -> MailTemplateService
  -> MailQueueService
  -> MailSenderService
  -> MailLogService
  -> AuditService
```

Los nombres finales de contratos y clases se aprobarán en `MAIL-0`.

## Separación de responsabilidades

### `NotificationService`

- Recibe un evento de dominio ya confirmado.
- Resuelve qué reglas aplican.
- Mantiene el contexto de empresa y almacén.
- No renderiza HTML ni abre SMTP.

### `NotificationRuleService`

- Evalúa reglas activas.
- Determina canal, plantilla y destinatarios permitidos.
- Respeta preferencias cuando sean aplicables.
- No concede acceso a datos fuera de alcance.

### `MailService`

- Fachada de casos de uso explícitos de correo.
- No debe ocultar envíos directos desde módulos.
- Coordina servicios especializados sin asumir persistencia concreta.

### `MailTemplateService`

- Obtiene una plantilla activa.
- Valida su tipo y alcance.
- Solicita resolución de variables.
- Produce asunto y cuerpo preparados.
- No evalúa PHP, JavaScript o expresiones arbitrarias.

### `MailVariableResolver`

- Acepta únicamente variables de una whitelist por plantilla.
- Escapa valores según texto o HTML.
- No permite acceso dinámico a objetos o BD.
- Rechaza variables desconocidas.

### `MailQueueService`

- Crea mensajes pendientes de forma idempotente.
- Reserva lotes pequeños.
- Controla reintentos y cancelaciones.
- Evita que dos procesos envíen el mismo mensaje.
- No expone destinatarios o errores sin permiso.

### `MailSenderService`

- Envía mediante un adaptador aprobado.
- Soporta modo `log` local antes de SMTP.
- Traduce errores del proveedor.
- No muestra credenciales o trazas al usuario.

### `MailLogService`

- Registra resultado, tiempos e identificadores técnicos.
- Sanea mensajes de error.
- No guarda contraseñas SMTP, tokens o cuerpos completos por defecto.

### `MailPreviewService`

- Genera previews controlados.
- Usa la misma whitelist de variables.
- Guarda previews temporales fuera de `public/`.
- Requiere permiso y CSRF cuando la acción sea mutable.

### `AuditService`

Audita:

- Cambios de plantillas.
- Envíos de prueba.
- Reintentos manuales.
- Cancelaciones.
- Cambios de reglas.

## Eventos

Un evento deberá incluir:

- Código estable.
- Identificador del recurso.
- Empresa y almacén cuando apliquen.
- Actor.
- Fecha.
- Datos mínimos requeridos.
- Clave de idempotencia cuando pueda repetirse.

No se publicará un evento externo antes de confirmar la transacción de negocio.
Si el evento necesita persistencia transaccional, se evaluará un patrón de
outbox compatible con MySQL y hosting compartido.

## Plantillas

Plantillas candidatas futuras:

- `usuario_bienvenida`
- `password_reset`
- `ticket_creado`
- `ticket_en_revision`
- `ticket_aprobado`
- `ticket_rechazado`
- `ticket_cancelado`
- `ticket_comentado`
- `compra_aprobada`
- `venta_autorizada`

Su existencia en este documento no autoriza crearlas. Las plantillas de módulos
operativos se entregarán con la fase del módulo y de Mail correspondiente.

Reglas:

- Sin PHP.
- Sin JavaScript.
- Sin includes arbitrarios.
- Variables declaradas por plantilla.
- HTML limitado y saneado.
- Enlaces construidos desde una URL base aprobada.
- Asunto sin saltos o headers inyectables.
- Preview y envío usan el mismo motor.
- Versionado o auditoría de cambios.

Variables conceptuales para tickets:

```text
{{ticket_folio}}
{{ticket_titulo}}
{{ticket_estatus}}
{{ticket_fecha}}
{{usuario_nombre}}
{{usuario_email}}
{{empresa_nombre}}
{{almacen_nombre}}
{{ultimo_comentario}}
{{url_ticket}}
```

La whitelist final se aprobará por plantilla. Los valores no se obtienen por
reflexión o consultas libres.

## Cola

Estados propuestos:

- `PENDIENTE`
- `ENVIANDO`
- `ENVIADO`
- `ERROR`
- `CANCELADO`

Transiciones permitidas y concurrencia se definirán formalmente en `MAIL-1`.

La cola deberá considerar:

- Prioridad acotada.
- Fecha disponible para envío.
- Número de intentos.
- Máximo de intentos.
- Último intento.
- Próximo intento.
- Error saneado.
- Fecha de envío.
- Identificador idempotente.
- Bloqueo temporal del procesador.
- Contexto de empresa y almacén.

Un mensaje `ENVIANDO` abandonado deberá poder recuperarse mediante una política
de timeout aprobada.

## Procesamiento en AwardSpace

No se asumirán Redis, RabbitMQ o workers permanentes.

Estrategias compatibles a evaluar:

1. Procesar un lote pequeño después de una acción controlada.
2. Procesar desde un panel administrativo.
3. Usar una ruta interna protegida con token rotado.
4. Usar cron solo si el plan real lo permite.

La solicitud HTTP del usuario no debe quedar acoplada a reintentos prolongados.
Los límites de lote y tiempo se medirán antes de producción.

## Modelo conceptual de datos

### `mail_templates`

- Código único.
- Nombre.
- Asunto.
- Cuerpo de texto y HTML conforme a la política aprobada.
- Variables permitidas.
- Estado.
- Versión o auditoría.

### `mail_queue`

- Plantilla o snapshot aprobado.
- Destinatarios normalizados.
- Contexto de recurso y alcance.
- Estado.
- Intentos y fechas.
- Clave de idempotencia.
- Último error saneado.
- Auditoría.

### `mail_logs`

- Referencia a cola.
- Resultado técnico.
- Identificador del proveedor si existe.
- Duración y fecha.
- Error saneado.

### `notification_rules`

- Evento.
- Canal.
- Plantilla.
- Condiciones permitidas.
- Destinatarios por estrategia controlada.
- Estado y alcance.

### `notification_events`

Tabla opcional para persistencia confiable de eventos. Su necesidad se decidirá
antes de `DB-MAIL-8`.

### `usuario_notificacion_preferencias`

Tabla opcional. Las notificaciones obligatorias de seguridad o proceso pueden no
ser desactivables. La política se aprobará antes de crearla.

No se define SQL ni tipos exactos en esta fase.

## Permisos propuestos

```text
notificaciones.ver
notificaciones.configurar
notificaciones.probar
correos.ver
correos.plantillas.ver
correos.plantillas.crear
correos.plantillas.editar
correos.plantillas.desactivar
correos.plantillas.previsualizar
correos.cola.ver
correos.cola.reintentar
correos.cola.cancelar
correos.logs.ver
correos.enviar_prueba
```

Los permisos requieren seeds en una fase autorizada. Ver una cola o log deberá
respetar alcance y minimizar datos personales visibles.

## Seguridad

- SMTP vive en `.env`, nunca en BD.
- El modo local inicial será `log`.
- Acciones mutables requieren CSRF.
- Acciones manuales requieren permiso.
- Contexto de empresa y almacén requiere `UserScopeService`.
- Destinatarios se validan y normalizan.
- Headers no aceptan saltos inyectados.
- Errores SMTP se sanean.
- Logs no guardan secretos.
- Previews y adjuntos permanecen fuera de `public/`.
- Plantillas no ejecutan código.
- URLs usan base aprobada y no parámetros abiertos inseguros.
- Reintentos y cancelaciones se auditan.

## Privacidad y retención

- Guardar solo metadatos necesarios.
- Definir retención de cola, logs y previews.
- Evitar duplicar datos personales en payloads.
- Saneamiento y eliminación deben conservar la auditoría mínima necesaria.
- El acceso a cuerpos, destinatarios y errores se limitará por permiso.

## Estrategia de errores

- Error transitorio: reintento con espera progresiva y límite.
- Error permanente: detener reintentos y solicitar intervención.
- Error de plantilla: no intentar SMTP.
- Destinatario inválido: marcar resultado sin filtrar detalles sensibles.
- Configuración ausente: fallar de forma segura.
- Error mostrado al usuario: mensaje genérico con referencia interna.

La clasificación exacta dependerá del adaptador y quedará cubierta por pruebas.

## DB-TEST-MAIL futuro

Deberá validar:

- Tablas, llaves, índices y estados.
- Plantilla duplicada rechazada.
- Variable no permitida rechazada.
- Mensaje válido entra una sola vez a cola.
- Clave idempotente evita duplicado.
- Transiciones inválidas fallan.
- Reintento incrementa contador.
- Cancelación de enviado falla.
- Alcance inconsistente falla.
- Logs no contienen secretos de prueba.
- Limpieza y rollback.

El SQL y los datos concretos se crearán solo en la fase autorizada.

## QA local futuro

- Render de texto y HTML.
- Escape de variables.
- Preview protegido.
- Modo log sin conexión SMTP.
- Cola y reintentos.
- Concurrencia.
- Permisos, alcance y CSRF.
- Auditoría.
- Saneamiento de errores.

## QA AwardSpace futuro

- Configuración externa.
- Envío de prueba autorizado.
- Límites de tiempo.
- Tamaño de lote.
- Disponibilidad de cron si aplica.
- Protección de endpoint interno.
- Logs privados.
- Ausencia de secretos en respuestas.

## Reglas obligatorias

- Centralizar todo correo y notificación.
- No enviar SMTP desde módulos.
- Publicar eventos después del commit o mediante mecanismo transaccional.
- Usar plantillas sin código y variables en whitelist.
- Mantener secretos fuera de BD y Git.
- Aplicar permisos, alcance, CSRF y auditoría.
- Diseñar cola para hosting compartido.
- Limitar reintentos y garantizar idempotencia.
- Mantener previews, logs y adjuntos fuera de `public/`.

## Pendiente de aprobar

- Eventos iniciales.
- Contratos exactos de servicios.
- Modelo y migraciones `DB-MAIL-8`.
- Proveedor o librería SMTP.
- Estrategia de outbox.
- Formato de plantillas y sanitizador.
- Whitelists definitivas.
- Reglas y destinatarios.
- Política de reintentos y retención.
- Disponibilidad de cron.
- Integración con módulos operativos.

## Fuera de alcance de esta fase

- Instalar PHPMailer.
- Configurar SMTP.
- Crear tablas, migraciones, seeds o plantillas.
- Crear servicios o jobs.
- Procesar una cola.
- Enviar correos de prueba.
- Integrar tickets, compras, ventas o usuarios.
- Crear rutas internas o cron.
- Ejecutar DB-TEST-MAIL.
