---
name: erp-mail-notifications-architect
description: Usar cuando la tarea involucre correos, SMTP, notificaciones, plantillas, cola de envío, logs de correo, eventos de tickets, reintentos o configuración mail del ERP. Obliga a centralizar correos y evitar SMTP directo desde módulos.
---

# ERP Mail Notifications Architect

## Misión

Diseñar y revisar el subsistema Mail/Notifications del ERP.

## Regla principal

Ningún módulo operativo debe enviar correos directamente.

Incorrecto:

TicketService -> PHPMailer directo

Correcto:

TicketService
  -> registra evento
  -> NotificationService
  -> MailTemplateService
  -> MailQueueService
  -> MailSenderService
  -> MailLogService
  -> AuditService

## Alcance inicial

Diseñar primero:

- Correos transaccionales.
- Notificaciones de tickets.
- Plantillas.
- Variables permitidas.
- Cola de envío.
- Reintentos.
- Logs.
- Auditoría.
- Configuración SMTP desde .env.
- Modo log local.

No construir todavía:

- Campañas masivas.
- Editor visual complejo.
- Tracking de aperturas.
- Tracking de clicks.
- Newsletter externo.
- Redis.
- RabbitMQ.
- Workers permanentes.
- Dependencia de Node.js.

## Servicios esperados

- NotificationService.
- NotificationRuleService.
- MailService.
- MailTemplateService.
- MailQueueService.
- MailSenderService.
- MailLogService.
- MailVariableResolver.
- MailPreviewService.
- AuditService.

## Tablas sugeridas

- mail_templates.
- mail_queue.
- mail_logs.
- notification_rules.
- notification_events si aplica.
- usuario_notificacion_preferencias si conviene.

## Reglas de seguridad

- SMTP vive en .env.
- SMTP no vive en BD.
- Plantillas no permiten PHP.
- Plantillas no permiten JavaScript.
- Variables deben estar en whitelist.
- No guardar secretos en logs.
- No mostrar errores SMTP sensibles al usuario.
- Todo envío manual requiere permiso.
- Toda vista de logs requiere permiso.
- Todo reintento requiere permiso.
- Toda cancelación requiere permiso.
- Todo correo relacionado con empresa/almacén requiere UserScopeService.
- CSRF obligatorio para acciones POST.
- Cambios de plantillas, pruebas, reintentos y cancelaciones se auditan.

## Permisos

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

## Plantillas base

- ticket_creado
- ticket_en_revision
- ticket_aprobado
- ticket_rechazado
- ticket_cancelado
- ticket_comentado
- usuario_bienvenida
- password_reset
- compra_aprobada
- venta_autorizada

## Variables permitidas para tickets

- {{ticket_folio}}
- {{ticket_titulo}}
- {{ticket_estatus}}
- {{ticket_fecha}}
- {{usuario_nombre}}
- {{usuario_email}}
- {{empresa_nombre}}
- {{almacen_nombre}}
- {{ultimo_comentario}}
- {{url_ticket}}

## Cola de envío

Estatus:

- PENDIENTE
- ENVIANDO
- ENVIADO
- ERROR
- CANCELADO

Debe permitir:

- Crear correo pendiente.
- Enviar lote pequeño.
- Reintentar errores.
- Cancelar pendientes.
- Registrar último error.
- Registrar intentos.
- Registrar fecha de envío.
- Auditar reintentos manuales.

## AwardSpace

No asumir workers permanentes.

Estrategias válidas:

1. Procesar cola al finalizar acciones controladas.
2. Procesar desde panel admin.
3. Procesar por ruta protegida con token interno.
4. Usar cron solo si el hosting lo permite.

## Resultado esperado

Cuando se invoque esta skill, entregar:

1. Diagnóstico.
2. Arquitectura Mail/Notifications.
3. Servicios involucrados.
4. Tablas.
5. Permisos.
6. Seguridad.
7. DB-TEST-MAIL si aplica.
8. Checklist local.
9. Checklist AwardSpace.
10. Qué NO construir todavía.