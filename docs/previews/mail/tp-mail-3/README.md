# TP-MAIL-3 · Diseño visual productivo

Los catorce archivos de correo fueron generados con `ProductTicketEmailTemplatePayloadBuilder`, `ProductTicketEmailTemplatePayload`, `ProductTicketEmailTemplateRenderer` y `RenderedEmail` reales. Se preservan los seis eventos, el asunto, HTML, texto plano, campos SAT, respuestas, motivos, adjuntos y CTA. La variante `ticket-creado-11` verifica el límite visual de diez tarjetas y el aviso explícito de partidas mostradas frente al total.

Los datos son ficticios. La CTA usa `https://preview.example.test/tickets/productos/4301` y no es operativa. No se consultó MySQL, no se leyó `.env`, no se resolvieron secretos, no se creó outbox y no se abrió SMTP.

Abre `index.html` localmente para comparar las seis variantes y el caso de once partidas. Revisa escritorio, tablet y móvil; el renderer usa tablas y estilos inline con fallback de color para clientes de correo conservadores. La compatibilidad exhaustiva con Gmail, Outlook y Apple Mail aún requiere una matriz específica de clientes.
