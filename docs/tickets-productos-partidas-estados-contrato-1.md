# TP-PARTIDAS-ESTADOS-CONTRATO-1

## Objetivo

Auditar y documentar el contrato final del módulo de Tickets de Solicitud de
Alta de Productos antes de implementar cambios funcionales.

Esta fase es solo de auditoría, contrato y planificación técnica. No modifica
funcionalidad, base de datos, controladores, vistas, rutas, servicios, correos,
migraciones ni seeds.

## Regla crítica

Este flujo nunca debe crear productos reales.

Este flujo nunca debe crear precios.

Este flujo nunca debe crear inventario.

Este flujo nunca debe generar claves definitivas.

Este flujo nunca debe modificar existencias.

Este flujo nunca debe crear compras.

Este flujo nunca debe crear proveedores reales automáticamente.

El flujo solo debe servir para registrar solicitudes, revisar documentación,
aprobar o rechazar partidas, conservar evidencia, enviar correos y llevar
control por folio.

## Contrato final del ticket

El usuario podrá crear un ticket de solicitud de alta de productos. El ticket
debe contener:

- empresa;
- almacén;
- observaciones generales;
- adjuntos;
- una o varias partidas.

Cada partida debe poder capturar:

- modelo;
- marca;
- descripción;
- proveedor existente;
- proveedor libre;
- unidad SAT;
- clave SAT;
- moneda;
- costo sugerido;
- peso;
- si lleva serie;
- observaciones de partida.

Los datos capturados en la partida son datos solicitados y documentales. No son
datos maestros definitivos hasta que una fase posterior, explícita y separada,
autorice la conversión controlada a catálogo real.

## Contrato de folio

El ticket debe tener folio por almacén.

Formato obligatorio:

```text
CODIGOALMACEN-000000
```

Ejemplo:

```text
GU-000010
```

Reglas:

- El prefijo sale del código del almacén.
- El consecutivo es numérico.
- El consecutivo debe ser independiente por almacén.
- La emisión debe ser transaccional.
- No debe duplicarse.
- No debe depender del ID global del ticket.
- No debe generar guiones extra.
- No debe usar empresa como prefijo.
- Si el almacén no tiene código válido, la creación debe bloquearse o usar una
  regla documentada y aprobada; no debe inventar silenciosamente un prefijo.

La infraestructura actual de folios (`series_documentales`,
`documentos_folios`, `FolioService`) puede ser reutilizada en una fase funcional
posterior, pero el contrato final de tickets de producto requiere una serie por
almacén que materialice exactamente `CODIGOALMACEN-000000`, por ejemplo
`GU-000010`.

## Estados del ticket

Estados recomendados:

- `EN_REVISION`
- `RESUELTO_PARCIAL`
- `APROBADO`
- `RECHAZADO`
- `CANCELADO`

El estado del ticket debe derivarse de las partidas, excepto `CANCELADO`.

Reglas recomendadas:

- Si todas las partidas están `EN_REVISION`, el ticket queda `EN_REVISION`.
- Si todas las partidas están `APROBADAS`, el ticket queda `APROBADO`.
- Si todas las partidas están `RECHAZADAS`, el ticket queda `RECHAZADO`.
- Si hay mezcla de `APROBADAS` y `RECHAZADAS` sin pendientes, el ticket queda
  `RESUELTO_PARCIAL`.
- Si hay al menos una partida pendiente, el ticket permanece `EN_REVISION` o se
  documenta un estado alternativo `PARCIAL_EN_REVISION` si el sistema ya lo
  incluye y se aprueba formalmente.

## Estados de partida

Cada partida debe resolverse individualmente.

Estados recomendados:

- `EN_REVISION`
- `APROBADA`
- `RECHAZADA`

Cada partida debe conservar:

- usuario que resolvió;
- fecha/hora de resolución;
- comentario de resolución;
- motivo de rechazo si aplica;
- evidencia si aplica;
- datos originales solicitados.

## Aprobación por partida

Aprobar una partida NO crea producto real.

Aprobar una partida significa:

- la solicitud fue revisada documentalmente;
- queda marcada como `APROBADA`;
- puede usarse como referencia futura;
- puede enviarse por correo;
- puede aparecer en historial.

No debe:

- insertar en productos;
- insertar en producto_precios;
- insertar en existencias_producto;
- insertar en inventario_existencias;
- crear movimientos de inventario;
- crear proveedor real;
- crear compra;
- crear clave definitiva.

## Rechazo por partida

Rechazar una partida debe exigir motivo obligatorio.

Debe conservar:

- motivo de rechazo;
- usuario que rechazó;
- fecha/hora;
- comentario.

Debe permitir que el ticket quede parcial si otras partidas fueron aprobadas.

## Correos

El flujo debe mandar correo como ticket mediante la arquitectura de correo
aprobada para el ERP, no con SMTP directo desde controladores.

Eventos mínimos:

1. Al crear ticket:
   - correo a área revisora;
   - opcional copia al solicitante.
2. Al resolver una partida:
   - correo al solicitante indicando partida aprobada o rechazada.
3. Al cerrar el ticket completo:
   - resumen al solicitante;
   - resumen al área revisora.

El correo debe incluir:

- folio;
- empresa;
- almacén;
- solicitante;
- listado de partidas;
- estado por partida;
- motivos de rechazo;
- observaciones;
- link interno al ticket si aplica.

El correo no debe incluir:

- `password_hash`;
- tokens;
- `token_hash`;
- rutas físicas;
- `storage/uploads`;
- datos internos sensibles.

## Adjuntos

El ticket puede tener adjuntos.

Reglas a verificar y formalizar en la fase funcional:

- permitir PDF;
- permitir JPG;
- permitir PNG;
- permitir WEBP;
- permitir DOCX;
- permitir XLSX;
- permitir XML solo si ya está aprobado para este flujo;
- máximo 5 archivos;
- máximo 5 MB por archivo o por acción, según se defina.

Reglas de seguridad:

- validar MIME real;
- rechazar doble extensión peligrosa;
- evitar path traversal;
- guardar en storage privado;
- no exponer rutas físicas;
- servir descargas mediante controlador privado con permiso y alcance.

## Permisos

Permisos recomendados a documentar para una fase posterior:

- `tickets_productos.ver`
- `tickets_productos.crear`
- `tickets_productos.resolver`
- `tickets_productos.cancelar`
- `tickets_productos.adjuntos.ver`
- `tickets_productos.correo.reenviar`

Esta fase no crea permisos. La fase funcional deberá comparar estos permisos
contra los permisos reales existentes y crear seeds solo si se autoriza.

## Auditoría

Eventos auditables:

- creación de ticket;
- agregado de partida;
- aprobación de partida;
- rechazo de partida;
- cancelación de ticket;
- envío de correo;
- carga de adjunto;
- descarga o consulta de adjunto.

La auditoría no debe registrar contraseñas, tokens, rutas físicas ni contenido
completo de archivos.

## Pantallas

Pantallas actuales y faltantes a cubrir en la fase funcional:

- crear ticket;
- listado de tickets;
- detalle de ticket;
- resolver partidas;
- historial;
- adjuntos;
- vista de impresión o resumen si aplica.

## Estado actual detectado

En el estado actual del repositorio:

- No hay rutas funcionales de tickets de producto en `routes/web.php`.
- No hay controlador funcional de tickets de producto.
- `app/Domain/Tickets` solo conserva `.gitkeep`.
- `app/Views/tickets` solo conserva `.gitkeep`.
- No hay repositorio funcional específico para tickets de producto.
- `docs/base-datos.md` y `docs/fases.md` mantienen `DB-TICKETS-7` como fase
  futura con tablas candidatas `tickets`, `ticket_partidas`,
  `ticket_comentarios` y `ticket_archivos`.
- Existe infraestructura de folios (`series_documentales`,
  `documentos_folios`, `FolioService`) para reutilización posterior, pero no
  está integrada a tickets de producto.
- `docs/mail-notifications.md` contiene plantillas conceptuales de tickets, pero
  no hay runtime funcional de Mail/Notifications para este flujo.
- No existe aprobación o rechazo por partida en código funcional.
- No existe guardrail funcional específico que pruebe que una aprobación de
  partida no crea productos, precios, inventario, compras o proveedores.

## Brechas detectadas

Brechas contra el contrato final:

- Falta esquema de tickets de producto.
- Falta tabla de partidas con estado individual.
- Falta tabla de adjuntos privados del ticket.
- Falta emisión de folio por almacén con formato `CODIGOALMACEN-000000`.
- Falta servicio transaccional de creación documental.
- Falta derivación de estado del ticket desde estados de partida.
- Falta resolución individual de partidas.
- Falta motivo obligatorio de rechazo.
- Falta integración con permisos `tickets_productos.*`.
- Falta integración con alcance por empresa y almacén.
- Falta integración con auditoría.
- Falta integración con correo centralizado.
- Falta test funcional de guardrail `no crea producto real`.

## Riesgos

- Si se aprueba por ticket completo, se pierde granularidad cuando una solicitud
  contiene partidas aprobadas y rechazadas.
- Si el folio se emite fuera de la transacción, puede haber duplicados o saltos
  de consecutivo.
- Si la aprobación documental crea productos reales, se mezclan solicitudes con
  catálogo, precios, inventario y compras.
- Si los adjuntos se publican directamente, se exponen rutas físicas o archivos
  privados.
- Si los correos se envían directo desde controladores, se duplican reglas y se
  puede filtrar información sensible.

## No alcance

Queda explícitamente fuera:

- creación real de producto;
- compras;
- inventario;
- precios;
- proveedores reales;
- CFDI;
- recepción de mercancía;
- movimientos de almacén;
- órdenes de compra;
- migraciones;
- seeds;
- controladores;
- rutas;
- vistas funcionales;
- servicios funcionales durante la fase documental inicial.

## Evolución de guardrails

`TP-PARTIDAS-ESTADOS-CONTRATO-1` fue la fase documental inicial. En ese momento
era correcto auditar que todavía no existieran servicio ni repositorio.

Después se cerró `TP-PARTIDAS-ESTADOS-DB-1` para crear tablas documentales, y la
fase abierta `TP-PARTIDAS-ESTADOS-SERVICE-1` autorizó únicamente:

- `app/Domain/Tickets/ProductRequestTicketService.php`
- `app/Domain/Tickets/ProductRequestTicketValidationException.php`
- `app/Infrastructure/Repositories/ProductRequestTicketRepository.php`

El guardrail vigente ya no es “no servicio/repositorio”, sino:

- no rutas;
- no controladores;
- no vistas;
- no CSS/JS;
- no correos runtime;
- no seeds;
- no creación funcional de producto;
- no creación de precio;
- no creación de inventario;
- no creación de compra;
- no creación de proveedor real.

## Validación de esta fase

Runner:

```bash
php database/tickets-productos-partidas-estados-contrato.php audit --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

Validaciones:

```bash
php -l database/tickets-productos-partidas-estados-contrato.php
php -l database/tests/tickets_productos_partidas_estados_contrato_1_test.php
git diff --check
```

El runner debe pasar si:

- confirma que esta fase solo agrega auditoría/documentación;
- documenta el contrato final;
- identifica el estado actual;
- identifica brechas;
- conserva la regla crítica de no crear productos reales;
- conserva el folio por almacén tipo `GU-000010`;
- confirma la necesidad de resolver por partida;
- confirma la necesidad de correos;
- no escribe datos en base de datos.

## Siguiente fase recomendada

La siguiente fase lógica debe ser una fase funcional separada de diseño de
persistencia, por ejemplo:

```text
TP-PARTIDAS-ESTADOS-DB-1
```

Objetivo sugerido: crear migración y DB-TEST para tablas documentales de tickets
de producto, partidas, adjuntos y estados, sin crear todavía productos reales,
precios, inventario, compras ni proveedores.
