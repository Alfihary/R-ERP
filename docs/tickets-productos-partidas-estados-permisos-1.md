# TP-PARTIDAS-ESTADOS-PERMISOS-1

## Objetivo

Crear permisos formales para el módulo de Tickets de Solicitud de Alta de Productos con partidas resolubles individualmente.

El flujo sigue siendo exclusivamente documental.

## Permisos creados

La fase agrega de forma idempotente estos permisos del módulo `tickets_productos`:

| Código | Descripción |
| --- | --- |
| `tickets_productos.ver` | Ver tickets de solicitud de alta de productos. |
| `tickets_productos.crear` | Crear tickets de solicitud de alta de productos. |
| `tickets_productos.resolver` | Aprobar o rechazar partidas de tickets de productos. |
| `tickets_productos.cancelar` | Cancelar tickets de solicitud de alta de productos. |
| `tickets_productos.adjuntos.ver` | Ver adjuntos privados de tickets de productos. |
| `tickets_productos.comentarios.crear` | Agregar comentarios a tickets de productos. |
| `tickets_productos.correo.reenviar` | Reenviar correos documentales de tickets de productos. |
| `tickets_productos.eventos.ver` | Ver historial/eventos de tickets de productos. |

## Asignación a ADMIN

El seed asigna los 8 permisos al rol `ADMIN`.

La asignación es idempotente:

- si la relación no existe, la crea;
- si existe inactiva o eliminada lógicamente, la reactiva;
- no duplica relaciones;
- no asigna permisos directamente a usuarios.

## Roles no creados

Esta fase no crea roles nuevos.

Si existe o se requiere un rol como `SOLICITANTE_TICKETS`, queda fuera de esta fase y debe tratarse en una fase posterior autorizada.

## Usuarios no tocados

Esta fase no crea, actualiza ni elimina usuarios.

Los permisos se asignan únicamente vía `rol_permisos` al rol `ADMIN`.

## Qué NO hace esta fase

Esta fase no crea:

- rutas;
- controladores;
- vistas;
- migraciones;
- correos runtime;
- plantillas de correo runtime;
- productos reales;
- precios;
- inventario;
- existencias;
- compras;
- proveedores reales;
- claves definitivas.

## Guardrails operativos

El módulo de tickets de solicitud de alta de productos es documental.

Reglas críticas:

- aprobar una partida no crea producto real;
- aprobar una partida no crea precio;
- aprobar una partida no crea inventario;
- aprobar una partida no modifica existencias;
- aprobar una partida no crea compra;
- aprobar una partida no crea proveedor real automáticamente;
- aprobar una partida no genera clave definitiva.

## Evolución de guardrails heredados

Los guardrails de `TP-PARTIDAS-ESTADOS-CONTRATO-1` y
`TP-PARTIDAS-ESTADOS-DB-1` se actualizan para reconocer esta fase:

- antes era correcto bloquear cualquier seed de tickets;
- ahora se permite únicamente
  `database/seeds/tickets_productos_partidas_estados_1_seed_permissions.php`;
- siguen prohibidos seeds funcionales que creen tickets reales, partidas reales,
  productos, precios, inventario, compras o proveedores;
- siguen prohibidos rutas, controladores, vistas y correos runtime.

## Pruebas ejecutadas

Validaciones esperadas:

- `php -l database/seeds/tickets_productos_partidas_estados_1_seed_permissions.php`
- `php -l database/tickets-productos-partidas-estados-permisos.php`
- `php -l database/tests/tickets_productos_partidas_estados_permisos_1_test.php`
- `git diff --check`
- `php database/tickets-productos-partidas-estados-permisos.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`

Regresiones:

- `php database/tickets-productos-partidas-estados-service.php service:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`
- `php database/tickets-productos-partidas-estados-db.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`
- `php database/tickets-productos-partidas-estados-contrato.php audit --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`
- `php database/inventario-service.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`
- `php database/precios.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test`

No se usa `database/productos.php db:test` como bloqueo obligatorio por la excepción documentada:

`102016169 | REFRIGERANTE R-410A 5KG IGAS`

## Siguiente fase recomendada

`TP-PARTIDAS-ESTADOS-CONTROLLER-1`

Crear rutas/controlador/vistas solo después de autorizar explícitamente la exposición privada del flujo y aplicar `PermissionMiddleware` con los permisos de esta fase.
