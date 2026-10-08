# TP-PARTIDAS-ESTADOS-MULTIPARTIDA-RESPUESTAS-UI-1

## Objetivo

Mejorar el flujo documental de Tickets de Solicitud de Alta de Productos antes de implementar correo y antes de crear productos reales.

La fase permite capturar varias partidas al crear un ticket, quitar partidas adicionales antes de enviar y mostrar en el detalle una separación clara entre datos solicitados y respuesta del área de productos.

## Alcance aplicado

- `GET /tickets/productos/crear` muestra la sección **Partidas solicitadas**.
- El formulario conserva adjuntos generales iniciales.
- El mismo JS local autorizado, `public/js/modules/tickets-productos-create.js`, maneja:
  - Empresa → Almacén.
  - Agregar otra partida.
  - Quitar partida.
  - Reindexar nombres e IDs de campos antes de enviar.
- El backend sigue reindexando partidas con `array_values()` y validando cada partida en servidor.
- El detalle separa por partida:
  - Datos solicitados.
  - Respuesta del área de productos.
  - Comentarios.
  - Adjuntos.
  - Acciones permitidas por estado y permiso.
- El detalle muestra un placeholder de **Correo electrónico** sin enviar correo real.

## Adjuntos por partida durante creación

No se implementan en esta fase.

Quedan documentados como pendiente específico porque requieren mapear archivos a índices temporales antes de que existan IDs reales de partida, crear ticket y partidas, insertar metadata por partida y limpiar archivos físicos si cualquier validación falla.

Flujo actual:

- Adjuntos generales iniciales: disponibles al crear ticket.
- Adjuntos por partida: disponibles desde el detalle, después de crear el ticket.

## Seguridad y guardrails

- No se usa CDN.
- No se usa framework JS.
- No se usa JS inline bloqueable por CSP.
- No se usa `innerHTML`.
- No se usa `eval`.
- No se usa `new Function`.
- No se envía correo real.
- No se agrega SMTP directo.
- No se crean rutas de descarga ni preview.
- No se exponen rutas internas de storage.
- No se crea producto real.
- No se crea precio.
- No se crea inventario.
- No se crea compra.
- No se crea proveedor.
- No se crean permisos nuevos.
- No se crean seeds nuevos.
- No se modifican migraciones.

## Prueba específica

Runner:

```bash
php database/tickets-productos-partidas-estados-multipartida-respuestas-ui.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

Valida:

- Formulario multipartida.
- Botones para agregar/quitar partidas.
- Partida 1 protegida cuando es la única.
- JS externo local seguro.
- Backend con múltiples partidas en transacción.
- Rechazo de ticket sin partidas.
- Rechazo de partida completamente vacía.
- Rollback sin ticket a medias.
- Adjuntos generales iniciales.
- Pendiente documentado de adjuntos por partida en creación.
- Detalle con respuesta clara por partida.
- Placeholder de correo sin runtime real.
- Guardrails contra producto/precio/inventario/compra/proveedor.

## Próxima fase recomendada

`TP-PARTIDAS-ESTADOS-CORREO-PLACEHOLDER-A-RUNTIME-1`, enfocada en diseñar arquitectura central de notificaciones/correo antes de SMTP real, cola o envío automático.
