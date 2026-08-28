# TP-PARTIDAS-ESTADOS-DB-1

## Objetivo

Crear la base de datos documental para Tickets de Solicitud de Alta de
Productos con partidas resolubles individualmente.

El flujo sigue siendo exclusivamente documental. Esta fase no crea rutas,
controladores, vistas, servicios, repositorios, seeds ni correos.

## Tablas creadas

La migración `tp_partidas_estados_db_1_001_create_ticket_product_tables` crea:

- `tickets_productos`
- `tickets_productos_partidas`
- `tickets_productos_adjuntos`
- `tickets_productos_comentarios`
- `tickets_productos_eventos`

Los nombres son específicos para solicitud documental de alta de productos y no
chocan con un módulo futuro genérico de tickets.

## `tickets_productos`

Tabla cabecera del ticket documental.

Campos principales:

- `id`
- `folio`
- `empresa_id`
- `almacen_id`
- `solicitante_usuario_id`
- `estado`
- `observaciones_generales`
- `total_partidas`
- `partidas_en_revision`
- `partidas_aprobadas`
- `partidas_rechazadas`
- `cancelado_por_usuario_id`
- `cancelado_at`
- `motivo_cancelacion`
- `created_at`
- `updated_at`
- `deleted_at`

Índices y constraints:

- `UNIQUE KEY uq_tickets_productos_folio (folio)`
- índices por empresa, almacén, solicitante, estado, fecha de creación y
  borrado lógico;
- `CHECK` de formato de folio;
- `CHECK` de estados permitidos;
- `CHECK` de contadores no negativos;
- FKs a `empresas`, `almacenes` y `usuarios`;
- FK compuesta `(empresa_id, almacen_id)` contra `almacenes`.

Estados del ticket:

- `EN_REVISION`
- `RESUELTO_PARCIAL`
- `APROBADO`
- `RECHAZADO`
- `CANCELADO`

## `tickets_productos_partidas`

Tabla de partidas documentales del ticket.

Campos principales:

- `id`
- `ticket_producto_id`
- `numero_partida`
- `estado`
- `modelo`
- `marca_texto`
- `descripcion`
- `proveedor_id`
- `proveedor_texto`
- `unidad_sat_id`
- `clave_sat_id`
- `moneda_id`
- `costo_sugerido`
- `peso`
- `lleva_serie`
- `observaciones`
- `resuelto_por_usuario_id`
- `resuelto_at`
- `comentario_resolucion`
- `motivo_rechazo`
- `created_at`
- `updated_at`
- `deleted_at`

Índices y constraints:

- `UNIQUE KEY uq_tickets_productos_partidas_numero (ticket_producto_id, numero_partida)`
- índices por ticket, estado, proveedor documental, SAT, moneda, usuario
  resolutor y borrado lógico;
- `CHECK` de número de partida positivo;
- `CHECK` de estados permitidos;
- `CHECK` de descripción obligatoria;
- `CHECK` de costo y peso no negativos cuando existan;
- `CHECK` de `lleva_serie`;
- `CHECK` de resolución con usuario/fecha;
- `CHECK` de motivo obligatorio al rechazar;
- FK a `tickets_productos`;
- FKs opcionales a `unidades_sat`, `claves_sat`, `monedas` y `usuarios`.

Estados de partida:

- `EN_REVISION`
- `APROBADA`
- `RECHAZADA`

`proveedor_id` queda como dato documental sin FK porque la tabla `proveedores`
todavía no existe en el estado actual del ERP. Cuando exista catálogo de
proveedores, una fase posterior podrá agregar la FK correspondiente.

## `tickets_productos_adjuntos`

Tabla de adjuntos documentales privados.

Campos principales:

- `id`
- `ticket_producto_id`
- `partida_id`
- `subido_por_usuario_id`
- `nombre_original`
- `nombre_guardado`
- `ruta_relativa`
- `mime`
- `extension`
- `tamano_bytes`
- `hash_sha256`
- `created_at`
- `deleted_at`

Reglas de integridad:

- ruta relativa obligatoria;
- no permite ruta absoluta Unix;
- no permite ruta absoluta Windows;
- no permite `..`;
- extensión minúscula controlada;
- tamaño mayor a cero;
- hash SHA-256 opcional con 64 caracteres hexadecimales;
- FKs a ticket, partida y usuario que sube.

La validación de MIME real, máximo 5 archivos y máximo 5 MB queda para el
servicio futuro. Esta fase solo prepara persistencia segura.

## `tickets_productos_comentarios`

Tabla de comentarios documentales.

Campos principales:

- `id`
- `ticket_producto_id`
- `partida_id`
- `usuario_id`
- `comentario`
- `visibilidad`
- `created_at`
- `deleted_at`

Visibilidad permitida:

- `INTERNA`
- `SOLICITANTE`

## `tickets_productos_eventos`

Tabla de eventos/auditoría interna del flujo documental.

Campos principales:

- `id`
- `ticket_producto_id`
- `partida_id`
- `usuario_id`
- `evento`
- `descripcion`
- `metadata_json`
- `created_at`

Eventos documentados:

- `TICKET_CREADO`
- `PARTIDA_AGREGADA`
- `PARTIDA_APROBADA`
- `PARTIDA_RECHAZADA`
- `TICKET_CANCELADO`
- `ADJUNTO_CARGADO`
- `COMENTARIO_AGREGADO`
- `CORREO_ENVIADO`
- `CORREO_FALLIDO`

## Folio documental

La tabla `tickets_productos.folio` almacena el folio completo.

Formato esperado:

```text
CODIGOALMACEN-000000
```

Ejemplo validado por DB-TEST:

```text
GU-000010
```

El folio futuro debe generarse usando la infraestructura ya existente:

- `series_documentales`
- `documentos_folios`
- `FolioService`

Reglas:

- el prefijo debe salir del código de almacén;
- el consecutivo debe ser independiente por almacén;
- la emisión debe ser transaccional;
- `UNIQUE(folio)` queda como defensa adicional.

Esta fase no implementa emisión funcional de folios.

## Guardrail de no creación operativa

Esta fase no crea productos reales.

Esta fase no crea precios.

Esta fase no crea inventario.

Esta fase no crea compras.

Esta fase no crea proveedores reales.

La aprobación o rechazo de partida en el DB-TEST se simula únicamente como
actualización documental de `tickets_productos_partidas.estado`.

El DB-TEST valida que no cambian conteos de:

- `productos`
- `producto_precios`
- `existencias_producto`
- `inventario_existencias`
- `movimientos_inventario`
- `compras`
- `proveedores`

La migración no incluye `INSERT` hacia tablas operativas y no crea triggers ni
procedures.

## Qué NO hace esta fase

- No crea rutas funcionales.
- No crea controladores.
- No crea vistas.
- No crea servicios.
- No crea repositorios.
- No crea seeds.
- No crea permisos.
- No implementa correos.
- No implementa aprobación funcional.
- No convierte partidas aprobadas en productos reales.
- No toca compras.
- No toca inventario.
- No toca precios.
- No toca productos funcionalmente.

## DB-TEST

Runner:

```bash
php database/tickets-productos-partidas-estados-db.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

El DB-TEST valida:

- existencia de tablas;
- columnas mínimas;
- índices y uniques;
- FKs contra tablas existentes;
- checks de estados y reglas mínimas;
- inserción de ticket documental con folio `GU-000010`;
- varias partidas por ticket;
- partida `APROBADA`;
- partida `RECHAZADA` con motivo;
- adjunto documental con ruta relativa;
- comentario;
- evento;
- bloqueo de folio duplicado;
- bloqueo de número de partida duplicado;
- bloqueo de rechazo sin motivo;
- no creación de productos, precios, inventario, compras ni proveedores;
- rollback de la migración al finalizar.

## Siguiente fase recomendada

La siguiente fase lógica es:

```text
TP-PARTIDAS-ESTADOS-SERVICE-1
```

Objetivo sugerido: implementar el servicio transaccional de creación documental
del ticket, emisión real de folio con `FolioService`, contadores derivados de
partidas y guardrails de no creación operativa.
