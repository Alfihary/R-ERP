# TP-PARTIDAS-ESTADOS-ADJUNTOS-CONTRATO-1

## Objetivo

Definir y validar el contrato seguro de adjuntos documentales para Tickets de Solicitud de Alta de Productos.

Esta fase es contractual y de auditoría. No implementa subida real de archivos, no crea endpoints de descarga, no mueve archivos y no escribe archivos en `storage`.

El flujo sigue siendo exclusivamente documental.

## Qué define esta fase

- Tabla base de adjuntos documentales.
- Alcance ticket/partida.
- Extensiones permitidas.
- MIME reales permitidos.
- Reglas de nombre seguro.
- Reglas de ruta relativa segura.
- Tamaño máximo.
- Permiso de visualización.
- Guardrails de no creación operativa.
- Validaciones mínimas para la futura implementación.

## Qué NO implementa esta fase

- No implementa upload real.
- No implementa download real.
- No implementa preview real.
- No crea rutas nuevas.
- No modifica rutas existentes.
- No modifica controlador, servicio ni repositorio.
- No modifica vistas.
- No crea CSS.
- No crea JavaScript.
- No crea migraciones.
- No modifica seeds.
- No implementa correos.
- No crea adjuntos reales.

## Tabla base

Los adjuntos pertenecen a la tabla documental existente:

```text
tickets_productos_adjuntos
```

Campos relevantes:

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

## Relación ticket/partida

Un adjunto puede pertenecer a:

- un ticket completo;
- una partida específica del ticket.

`partida_id` es opcional. Si se informa, la partida específica debe pertenecer al ticket completo indicado por `ticket_producto_id`.

La futura implementación debe rechazar cualquier `partida_id` que no pertenezca al ticket.

## Tipos y extensiones permitidas

Solo se permitirán:

- PDF
- JPG
- JPEG
- PNG
- WEBP

Extensiones permitidas normalizadas:

```text
pdf
jpg
jpeg
png
webp
```

## MIME reales permitidos

La futura implementación debe validar MIME real con `fileinfo`/`finfo`, no solo por extensión.

MIME permitidos:

```text
application/pdf
image/jpeg
image/png
image/webp
```

Debe rechazarse:

- archivo con extensión permitida pero MIME real incorrecto;
- MIME permitido con extensión peligrosa;
- archivos de texto renombrados;
- ejecutables renombrados;
- doble extensión peligrosa.

## Extensiones prohibidas

Rechazar cualquier extensión no permitida, especialmente:

- `php`
- `phtml`
- `phar`
- `js`
- `html`
- `htm`
- `svg`
- `exe`
- `bat`
- `cmd`
- `sh`
- `ps1`
- `zip`
- `rar`
- `7z`
- `sql`
- `env`

Archivos ejecutables, HTML, JavaScript, SVG y ZIP/RAR/7Z no forman parte del contrato permitido.

## Doble extensión peligrosa

La futura implementación debe rechazar nombres como:

- `archivo.pdf.php`
- `imagen.jpg.php`
- `comprobante.png.exe`
- `documento.webp.html`
- `factura.pdf.js`

La validación debe evaluar el nombre completo, no únicamente el último segmento visual.

## Tamaño máximo

Tamaño máximo definido:

```text
5 MB por archivo
```

La futura implementación debe validar tamaño antes de persistir metadatos.

## Nombre original

`nombre_original` se guarda solo como dato documental escapado.

No debe usarse como nombre físico final.

No debe imprimirse sin escape.

## Nombre almacenado

`nombre_guardado` debe generarse de forma segura en la futura implementación:

- aleatorio;
- sin datos sensibles;
- sin confiar en el nombre del usuario;
- con extensión normalizada permitida;
- sin rutas relativas del usuario.

## Ruta relativa segura

La tabla debe guardar solo `ruta_relativa` segura.

Está prohibido guardar:

- rutas absolutas de Windows;
- rutas absolutas de Linux;
- `..`;
- `\`;
- `//`;
- esquemas como `file://`;
- URLs externas;
- rutas que salgan de storage privado.

La ruta relativa segura no debe imprimirse al usuario.

## Ubicación privada sugerida

Ubicación futura sugerida:

```text
storage/private/tickets_productos/{ticket_id}/...
```

Los archivos deben quedar fuera de public.

No guardar en:

- `public/`
- `public/uploads/`
- `storage/uploads/productos/`
- `storage/uploads/usuarios/`

## Exposición

Nunca mostrar al usuario:

- ruta física;
- `ruta_relativa` directa;
- ruta absoluta;
- ruta de storage;
- `stored_name` si revela estructura interna;
- metadata interna sensible.

La descarga futura debe pasar por controlador privado con permiso y validación de alcance.

## Permiso de visualización

Para ver, listar o descargar adjuntos debe requerirse:

```text
tickets_productos.adjuntos.ver
```

## Permisos nuevos

No se crea permiso nuevo en esta fase.

La futura fase deberá decidir si la carga de adjuntos usa un permiso existente o un permiso específico nuevo. Preferencia actual: no crear permisos hasta definir la implementación real.

## Eventos futuros

La futura implementación puede registrar evento documental:

```text
ADJUNTO_AGREGADO
```

Esta fase no modifica CHECK constraints ni enums de eventos. No crea migración.

## Antivirus/escaneo

Como recomendación futura opcional, si el hosting lo permite, se puede agregar escaneo antivirus o integración equivalente antes de aceptar el archivo.

No se implementa escaneo en esta fase.

## Guardrails de no creación operativa

Adjuntar o consultar adjuntos documentales nunca debe crear productos reales.

Adjuntar o consultar adjuntos documentales nunca debe crear precios.

Adjuntar o consultar adjuntos documentales nunca debe crear inventario.

Adjuntar o consultar adjuntos documentales nunca debe crear compras.

Adjuntar o consultar adjuntos documentales nunca debe crear proveedores.

También nunca debe crear o modificar:

- precios;
- inventario;
- existencias;
- movimientos de inventario;
- compras;
- proveedores reales;
- claves definitivas.

Agregar o consultar adjuntos documentales no aprueba, no rechaza, no cancela y no crea datos operativos.

## Pruebas ejecutadas

Runner específico:

```bash
php database/tickets-productos-partidas-estados-adjuntos-contrato.php audit --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

Validaciones esperadas:

- `php -l` del runner y test.
- `git diff --check`.
- Audit específico del contrato.
- Regresiones de comentarios, detalle pulido, listado, UI, permisos visuales, rutas/controlador, permisos, servicio, DB, contrato, inventario y precios.

## Criterios de aceptación

- Contrato de adjuntos documentado.
- Tabla `tickets_productos_adjuntos` validada.
- Relación con ticket validada.
- Relación opcional con partida validada.
- Campos de metadatos validados.
- Se confirma que no hay upload real.
- Se confirma que no hay download real.
- Se confirma que no hay rutas nuevas de adjuntos.
- Se confirma que no hay controlador específico de adjuntos.
- Se confirma que no se escriben archivos reales.
- Se confirma que no se exponen rutas físicas.
- Se confirma que no se crean permisos nuevos.
- Se confirma que no se modifican migraciones ni seeds.
- Se confirma que no se crea información operativa.

## Siguiente fase recomendada

`TP-PARTIDAS-ESTADOS-ADJUNTOS-IMPLEMENTACION-1`
