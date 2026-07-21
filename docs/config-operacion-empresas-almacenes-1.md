# CONFIG-OPERACION-EMPRESAS-ALMACENES-1

## Alcance

Esta fase crea la administración operativa definitiva de empresas y almacenes.
No es un CRUD temporal: queda como base para contexto activo, inventario,
usuarios, permisos, reportes y folios futuros.

Regla confirmada: los folios operativos serán por almacén.

## Empresas

La fase conserva la tabla `empresas` creada en DB-SCOPE-1 y la extiende con
campos operativos mediante una migración nueva. No se editaron migraciones
cerradas.

Campos base respetados:

- `id`
- `codigo`
- `nombre`
- `activo`
- `creado_en`
- `actualizado_en`
- `creado_por`
- `actualizado_por`
- `eliminado_en`
- `eliminado_por`

Campos agregados:

- `razon_social`
- `nombre_comercial`
- `rfc`
- `regimen_fiscal`
- `telefono`
- `email`
- `sitio_web`
- dirección básica
- `logo_path`
- `color_primario`

La desactivación es lógica con `activo=0`. No hay hard delete.

### Regla de código de empresa

CONFIG-OPERACION-CODIGOS-UPPER-1 convierte formalmente los códigos operativos a
mayúsculas. La migración nueva
`config_operacion_codigos_upper_1_001_uppercase_company_warehouse_codes`
reemplaza `chk_empresas_codigo` y convierte datos existentes con
`UPDATE empresas SET codigo = UPPER(codigo)`.

El `CHECK` real de base de datos permite:

- longitud de 2 a 64 caracteres;
- mayúsculas;
- letras `A-Z`;
- números `0-9`;
- separadores `.`, `_` y `-` entre segmentos;
- sin espacios;
- sin acentos ni caracteres especiales.

Antes de guardar, el servicio aplica:

- `trim`;
- conversión a mayúsculas;
- conversión de espacios a guion medio.

Ejemplos válidos:

- `GRUPO-REFRIGERANTES`
- `GR`
- `MT`
- `EMPRESA-QA`
- `SUCURSAL-1`
- `EMPRESA.QA`
- `EMPRESA_QA`

Ejemplos inválidos:

- `G`
- `empresa qa @` en SQL directo;
- `gr` en SQL directo;
- `ñandu`
- `EMPRESA@`
- `EMPRESA--QA`

`telefono` y `email` permanecen en `empresas`. Ambos siguen siendo opcionales;
`email` se valida si se captura.

## Almacenes

La fase conserva la tabla `almacenes` creada en DB-SCOPE-1 y la extiende con
datos necesarios para operación real y folios por almacén.

Campos agregados:

- `tipo_almacen`
- `responsable`
- `telefono`
- `email`
- dirección básica
- `permite_ventas`
- `permite_compras`
- `permite_inventario`
- `permite_transferencias`
- `es_principal`
- `principal_empresa_id` como columna generada para garantizar un almacén
  principal por empresa.

Tipos permitidos:

- `GENERAL`
- `REFACCIONES`
- `SERVICIO`
- `CUARENTENA`
- `DEVOLUCIONES`
- `TRANSITO`
- `VIRTUAL`

El código de almacén queda como dato estable porque será insumo de folios
posteriores. En esta fase no existen folios, pero se documenta que el código no
debe cambiarse libremente cuando existan movimientos o folios asociados.

### Regla de código de almacén

CONFIG-OPERACION-CODIGOS-UPPER-1 convierte formalmente los códigos operativos a
mayúsculas. La migración nueva
`config_operacion_codigos_upper_1_001_uppercase_company_warehouse_codes`
reemplaza `chk_almacenes_codigo` y convierte datos existentes con
`UPDATE almacenes SET codigo = UPPER(codigo)`.

El `CHECK` real de base de datos permite:

- longitud de 2 a 64 caracteres;
- mayúsculas;
- letras `A-Z`;
- números `0-9`;
- separadores `.`, `_` y `-` entre segmentos;
- sin espacios;
- sin acentos ni caracteres especiales.

Antes de guardar, el servicio aplica:

- `trim`;
- conversión a mayúsculas;
- conversión de espacios a guion medio.

Ejemplos válidos:

- `BODEGA`
- `BO`
- `MT`
- `SL`
- `BODEGA-1`
- `BODEGA-PRINCIPAL`
- `ALMACEN_QA`
- `ALMACEN.PRINCIPAL`

Ejemplos inválidos:

- `B`
- `bodega` en SQL directo, aunque el servicio normaliza capturas de usuario a
  mayúsculas;
- `bodega principal` en SQL directo, aunque el servicio normaliza espacios a
  guion;
- `BO@`
- `ñandu`
- `BODEGA--PRINCIPAL`

`telefono` y `email` permanecen en `almacenes`. Ambos siguen siendo opcionales;
`email` se valida si se captura.

Para folios por almacén, el código técnico de `almacenes.codigo` se mantiene en
mayúscula, alineado con snapshots documentales como `BO`, `MT` o `SL`.

El rollback de CONFIG-OPERACION-CODIGOS-UPPER-1 restaura minúsculas y conserva
el mínimo de 2 caracteres aprobado en CONFIG-OPERACION-CODIGOS-MIN-2.

Esta fase no toca folios ni inventario; solo cambia restricciones y datos de
código, además de la validación previa en servicios/formularios.

Los artefactos de prueba y fixtures QA que insertan directamente registros en
`empresas.codigo` o `almacenes.codigo` fueron adaptados al contrato global en
mayúsculas. Esto incluye patrones de limpieza por `codigo` y snapshots de
almacén usados por regresiones de folios. No se modificó lógica funcional de
folios, inventario, productos, series ni transferencias; únicamente datos de
prueba, asserts y documentación relacionados con códigos operativos.

## Permisos

Permisos creados por seed idempotente:

- `configuracion.empresas.acceder`
- `configuracion.empresas.ver`
- `configuracion.empresas.crear`
- `configuracion.empresas.editar`
- `configuracion.empresas.desactivar`
- `configuracion.almacenes.acceder`
- `configuracion.almacenes.ver`
- `configuracion.almacenes.crear`
- `configuracion.almacenes.editar`
- `configuracion.almacenes.desactivar`

Todos se asignan a `ADMIN`. No se creó permiso de borrado físico.

## Rutas

Empresas:

- `GET /configuracion/empresas`
- `GET /configuracion/empresas/crear`
- `POST /configuracion/empresas`
- `GET /configuracion/empresas/ver?id=...`
- `GET /configuracion/empresas/editar?id=...`
- `POST /configuracion/empresas/actualizar`
- `POST /configuracion/empresas/desactivar`
- `POST /configuracion/empresas/activar`

Almacenes:

- `GET /configuracion/almacenes`
- `GET /configuracion/almacenes/crear`
- `POST /configuracion/almacenes`
- `GET /configuracion/almacenes/ver?id=...`
- `GET /configuracion/almacenes/editar?id=...`
- `POST /configuracion/almacenes/actualizar`
- `POST /configuracion/almacenes/desactivar`
- `POST /configuracion/almacenes/activar`

Todas las rutas son privadas. Los `POST` pasan por CSRF global.

## Validaciones

Empresas:

- nombre obligatorio;
- código obligatorio, único global y seguro;
- RFC básico si se captura;
- email válido si se captura;
- código postal básico si se captura;
- no hard delete;
- bloqueo de desactivación de empresa del contexto activo.

Almacenes:

- empresa obligatoria;
- nombre obligatorio;
- código obligatorio, seguro y único por empresa;
- mismo código permitido en empresas distintas;
- tipo obligatorio;
- email válido si se captura;
- un solo principal por empresa;
- no hard delete;
- bloqueo de desactivación del almacén del contexto activo;
- bloqueo si se dejaría la empresa sin almacén activo.

## Relación con ADMIN

Al crear empresa desde la UI autorizada, el usuario ADMIN autenticado se asigna
automáticamente a `usuario_empresas`.

Al crear almacén desde la UI autorizada, el usuario ADMIN autenticado se asigna
automáticamente a `usuario_almacenes`.

Las asignaciones son idempotentes.

## Contexto activo

La fase no cambia automáticamente el contexto activo. La resolución actual por
`UserScopeService` y `ScopeContextService` se conserva.

Si se intenta desactivar la empresa o almacén del contexto activo del usuario
actual, la acción se bloquea con mensaje controlado.

## Por qué esta fase va antes de folios

Como los folios operativos serán por almacén, el código y configuración del
almacén deben estabilizarse antes de crear `FOLIOS-DB-1`. Esta fase deja
empresas y almacenes listos para series por almacén sin crear todavía ninguna
tabla ni servicio de folios.

## Pendientes fuera de esta fase

- UI de asignación usuario-empresa.
- UI de asignación usuario-almacén.
- Folios.
- Servicio de folios.
- Integración de folios con inventario.
- Sucursales fiscales.
- CFDI.
