# TP-PARTIDAS-ESTADOS-CORREO-CONTRATO-1

## Objetivo

Definir el contrato técnico, funcional y de seguridad para el envío futuro de correos del módulo Tickets de Solicitud de Alta de Productos.

Esta fase solo documenta y audita el contrato. No envía correos reales, no configura SMTP, no integra PHPMailer directo al módulo de tickets, no crea una cola real, no crea migraciones, no modifica seeds y no crea productos, precios, inventario, compras ni proveedores.

## Decisión de diseño visual

El diseño de mensaje de email existente en SoporteGR queda aceptado como referencia visual para una fase posterior de runtime: contenedor claro, asunto visible, cuerpo HTML, versión de texto plano, llamada a la acción interna y pie de no responder.

Para R-ERP, el contrato de mensaje futuro debe conservar esa línea visual y técnica:

- destinatarios `to`;
- asunto `subject`;
- cuerpo `html`;
- cuerpo `text`;
- copias controladas `cc`.

Esta referencia no autoriza copiar credenciales, configurar SMTP ni modificar el proyecto `soportegr.com`.

## Arquitectura obligatoria futura

El módulo de tickets no debe enviar correos directamente.

Flujo aprobado para runtime posterior:

1. El módulo Tickets de Productos registra un evento de notificación pendiente.
2. Un servicio central de notificaciones resuelve reglas, destinatarios y variables permitidas.
3. Un servicio central de correo genera la plantilla HTML y texto plano.
4. Una cola/outbox interna procesa el envío.
5. Un log seguro registra intentos, éxito, error seguro, reintentos y cancelación.

Queda prohibido:

- llamar SMTP desde controller;
- llamar SMTP desde views;
- llamar SMTP desde JavaScript;
- llamar PHPMailer directo desde el módulo tickets;
- guardar credenciales hardcodeadas;
- guardar destinatarios hardcodeados en controller, service, view o JS.

## Eventos definidos

| Evento | ¿Envía correo en runtime futuro? | Destinatario principal | CC | Plantilla |
| --- | --- | --- | --- | --- |
| `TICKET_CREADO` | Sí | Solicitante | Responsables configurados, si existen | `ticket_created` |
| `PARTIDA_APROBADA` | Sí | Solicitante | No por defecto | `line_approved` |
| `PARTIDA_RECHAZADA` | Sí | Solicitante | No por defecto | `line_rejected` |
| `TICKET_RESUELTO_TOTAL` | Sí | Solicitante | No por defecto | `ticket_resolved` |
| `TICKET_RESUELTO_PARCIAL` | Sí | Solicitante | No por defecto | `ticket_resolved` |
| `TICKET_CANCELADO` | Sí | Solicitante | No por defecto | `ticket_cancelled` |
| `COMENTARIO_AGREGADO` | No en esta decisión | Solo se registra | No aplica | Pendiente de decisión |
| `ADJUNTO_CARGADO` | No en esta decisión | Solo se registra | No aplica | Pendiente de decisión |

`COMENTARIO_AGREGADO` y `ADJUNTO_CARGADO` quedan como eventos registrables, pero sin correo automático por ahora para evitar ruido y exposición innecesaria de información.

## Destinatarios

### Solicitante

El destinatario principal será el email del usuario creador del ticket. Si el solicitante no tiene email válido:

- no se debe intentar enviar correo;
- se debe registrar el evento como `CANCELADO` o equivalente seguro por falta de destinatario;
- no se debe bloquear la operación principal del ticket;
- no se debe mostrar error técnico al usuario.

### Responsables

Los responsables no se hardcodean. En una fase posterior deberán resolverse desde configuración, permiso, rol, empresa, almacén o tabla futura.

### CC controlado

La regla de CC es restrictiva:

- no permitir CC arbitrario desde request público;
- no permitir correos libres sin validación;
- no aceptar CC desde inputs de usuario sin regla explícita;
- resolver CC desde reglas internas auditables;
- validar formato de cada email antes de encolar.

## Plantillas mínimas

| Plantilla | Uso | Subject |
| --- | --- | --- |
| `ticket_created` | Confirmar recepción del ticket | `Solicitud de alta de producto {folio} recibida` |
| `line_approved` | Informar una partida aprobada | `Partida aprobada en solicitud {folio}` |
| `line_rejected` | Informar una partida rechazada | `Partida rechazada en solicitud {folio}` |
| `ticket_resolved` | Informar cierre total o parcial | `Solicitud de alta de producto {folio} resuelta` |
| `ticket_cancelled` | Informar cancelación | `Solicitud de alta de producto {folio} cancelada` |

Cada plantilla debe tener:

- subject;
- saludo;
- folio;
- empresa;
- almacén;
- solicitante;
- resumen de partidas;
- estado;
- datos autorizados si aplica;
- motivo de rechazo si aplica;
- comentario o respuesta si aplica;
- link interno al detalle del ticket;
- aviso de no responder si aplica;
- versión HTML;
- versión de texto plano.

## Variables permitidas

Variables permitidas para plantillas de tickets:

- `{{ticket_folio}}`;
- `{{ticket_estado}}`;
- `{{ticket_fecha}}`;
- `{{empresa_nombre}}`;
- `{{almacen_nombre}}`;
- `{{solicitante_nombre}}`;
- `{{solicitante_username}}`;
- `{{resumen_partidas}}`;
- `{{partida_numero}}`;
- `{{partida_estado}}`;
- `{{descripcion_solicitada}}`;
- `{{descripcion_autorizada}}`;
- `{{motivo_rechazo}}`;
- `{{comentario_resolucion}}`;
- `{{url_ticket}}`;
- `{{adjuntos_resumen}}`.

Las plantillas no pueden ejecutar PHP ni JavaScript. Toda variable debe salir de una whitelist y debe escaparse según el contexto HTML o texto plano.

## Link interno al detalle

El correo futuro podrá incluir únicamente un link interno al detalle del ticket en el ERP, por ejemplo una URL construida desde `APP_URL` hacia `/tickets/productos/{id}`.

Reglas:

- no incluir rutas físicas;
- no incluir rutas `storage/private`;
- no incluir nombres internos de archivo;
- no incluir links directos de descarga o preview de adjuntos mientras esa fase no exista;
- no incluir tokens de sesión;
- no incluir credenciales ni parámetros sensibles.

## Adjuntos

En esta etapa contractual y en el primer runtime futuro:

- no enviar adjuntos por correo;
- no adjuntar automáticamente archivos del ticket;
- solo mencionar que el ticket tiene adjuntos, si aplica;
- el usuario deberá entrar al ERP para revisarlos;
- no incluir links directos a archivos porque descarga/preview no está implementado.

## Seguridad y privacidad

El contrato prohíbe exponer por correo o logs:

- rutas internas;
- `storage/private`;
- nombre interno de archivo;
- DSN;
- credenciales SMTP;
- contraseñas;
- password hash;
- tokens;
- información de sesión;
- errores técnicos crudos;
- stack traces;
- rutas absolutas del servidor.

Si el correo falla:

- la operación principal del ticket no debe revertirse solo por fallo de correo;
- debe registrarse error seguro sin credenciales, sin DSN y sin respuesta cruda del proveedor SMTP;
- el usuario debe ver como máximo un aviso genérico si aplica;
- el reintento debe quedar controlado por permiso.

## Historial/outbox futuro

Se documenta una tabla futura, sin migración en esta fase contractual:

`tickets_productos_correos`

Campos sugeridos:

- `id`;
- `ticket_id`;
- `partida_id` nullable;
- `evento`;
- `plantilla`;
- `destinatario_email`;
- `cc_json` nullable;
- `subject`;
- `status`;
- `error_mensaje_seguro` nullable;
- `intentos`;
- `ultimo_intento_at`;
- `enviado_at`;
- `creado_por_usuario_id` nullable;
- `created_at`.

Estados de correo:

- `PENDIENTE`;
- `ENVIANDO`;
- `ENVIADO`;
- `ERROR`;
- `CANCELADO`.

## Idempotencia y duplicados

El runtime futuro debe evitar duplicados con una llave lógica por:

- ticket;
- partida nullable;
- evento;
- plantilla;
- destinatario;
- versión de plantilla o payload hash.

Si ya existe un correo `PENDIENTE`, `ENVIANDO` o `ENVIADO` con esa llave lógica, no se debe crear otro automáticamente.

## Reintentos y reenvío manual

Los reintentos automáticos deben ser limitados y registrar intentos.

El reenvío manual futuro debe requerir el permiso existente:

`tickets_productos.correo.reenviar`

No se crea permiso nuevo en esta fase. No se modifican seeds ni roles.

Reglas:

- solo usuarios autorizados pueden reenviar;
- todo reenvío manual debe auditarse;
- no debe permitir cambiar destinatario libremente;
- no debe revelar errores técnicos crudos;
- debe respetar alcance por empresa y almacén.

## UI actual

La vista de detalle conserva un placeholder visual de correo electrónico. Ese placeholder indica que el envío automático queda pendiente de fase posterior, lista los eventos futuros y muestra el reenvío como acción deshabilitada cuando el usuario tiene permiso.

Esta fase no convierte el placeholder en envío real.

## Fuera de alcance

Nota de evolución: la fase posterior `TP-PARTIDAS-ESTADOS-CORREO-OUTBOX-DB-1` puede materializar esta tabla como outbox/historial, siempre que mantenga prohibido el envío real, SMTP directo, rutas de correo, seeds nuevos y dependencias.

No se crea en esta fase contractual:

- envío real;
- SMTP;
- PHPMailer directo en tickets;
- cola real;
- migración;
- seed;
- ruta nueva;
- config SMTP;
- dependencia Composer;
- dependencia npm;
- producto;
- precio;
- inventario;
- compra;
- proveedor;
- descarga de adjuntos;
- preview de adjuntos;
- permiso nuevo.

No se modifica:

- controller de tickets;
- service de tickets;
- repository de tickets;
- `routes/web.php`;
- `bootstrap/app.php`;
- `database/migrations/`;
- `database/seeds/`;
- `config/mail.php`;
- `.env`;
- `package.json`;
- `package-lock.json`.

## Siguiente fase recomendada

La siguiente fase recomendada, cuando se autorice, es una fase de runtime centralizado de correo/notificaciones, por ejemplo:

`TP-PARTIDAS-ESTADOS-CORREO-RUNTIME-1`

Esa fase deberá crear o integrar servicios centrales de notificaciones/correo, configuración local segura, outbox/logs con migración propia, DB-TEST-MAIL, permisos de reintento si aún no existen y pruebas de no exposición de datos sensibles.
