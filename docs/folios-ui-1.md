# FOLIOS-UI-1

## Alcance

FOLIOS-UI-1 CONFIGURA SERIES DOCUMENTALES.

FOLIOS-UI-1 NO EMITE FOLIOS FUNCIONALES.

FOLIOS-UI-1 NO MODIFICA MOVIMIENTOS NI TRANSFERENCIAS.

La fase agrega una pantalla administrativa para configurar `series_documentales` por empresa, almacén, tipo de documento y código de serie.

## Rutas

- `GET /configuracion/folios`
- `GET /configuracion/folios/crear`
- `POST /configuracion/folios`
- `GET /configuracion/folios/ver?id=...`
- `GET /configuracion/folios/editar?id=...`
- `POST /configuracion/folios/actualizar`
- `POST /configuracion/folios/desactivar`
- `POST /configuracion/folios/activar`

Todas las rutas requieren sesión y RBAC. Los `POST` requieren CSRF.

## Permisos

- `configuracion.folios.acceder`
- `configuracion.folios.ver`
- `configuracion.folios.crear`
- `configuracion.folios.editar`
- `configuracion.folios.desactivar`

No existe permiso de eliminación física, emisión manual ni reinicio manual de consecutivos.

## Listado

El listado muestra:

- empresa;
- almacén;
- `tipo_documento`;
- `codigo_serie`;
- `prefijo`;
- `codigo_almacen_snapshot`;
- `formato`;
- `siguiente_numero`;
- `longitud`;
- `reinicio_anual`;
- `anio_actual`;
- `activo`;
- acciones permitidas por RBAC.

Incluye filtros por búsqueda general, empresa, almacén, tipo documental y estado.

## Formulario

El formulario permite crear y editar series documentales con:

- empresa;
- almacén;
- tipo de documento;
- código de serie;
- prefijo;
- formato;
- separador;
- siguiente número;
- longitud;
- reinicio anual;
- año actual;
- estado activo.

El formato MVP soportado es:

```text
{PREFIJO}-{ALMACEN}{NUMERO}
```

Ejemplos:

```text
F-BO000001
R-BO000001
TR-BO000001
```

## Folios por almacén

LOS FOLIOS OPERATIVOS SERÁN POR ALMACÉN.

La serie documental se define por:

```text
empresa_id + almacen_id + tipo_documento + codigo_serie
```

Cada almacén y tipo documental mantiene su propio consecutivo.

## Snapshot de almacén

Al crear una serie, `codigo_almacen_snapshot` copia `almacenes.codigo`.

Este snapshot protege configuración documental e históricos ante cambios futuros en el código actual del almacén.

Al editar una serie sin folios emitidos, si cambia el almacén, el snapshot se actualiza al código del nuevo almacén.

## Bloqueo con folios emitidos

Si una serie ya tiene registros en `documentos_folios`, la UI bloquea cambios destructivos.

En MVP, con folios emitidos solo se permite cambiar:

- `activo`

No se modifican:

- empresa;
- almacén;
- tipo de documento;
- código de serie;
- prefijo;
- snapshot;
- formato;
- longitud;
- reinicio anual;
- documentos históricos.

## Vista previa

La UI muestra una vista previa del siguiente folio usando prefijo, snapshot, siguiente número y longitud.

La vista previa no llama `FolioService`, no inserta en `documentos_folios` y no consume consecutivos.

## Activación y desactivación

La pantalla permite activar o desactivar series documentales mediante estado lógico.

No hay eliminación física.

## Validaciones

Se valida:

- empresa activa existente;
- almacén activo existente;
- almacén pertenece a empresa;
- tipo de documento permitido;
- código de serie seguro;
- prefijo seguro;
- formato soportado;
- separador soportado;
- `siguiente_numero >= 1`;
- `longitud` entre 1 y 12;
- unicidad por empresa, almacén, tipo y serie.

## Seguridad

- Sesión obligatoria.
- RBAC por acción.
- CSRF en `POST`.
- Prepared statements.
- Sin SQL dinámico inseguro.
- Salida escapada con `e()`.
- Sin SQLSTATE visible en UI.
- Sin stack trace visible.
- Sin hashes ni credenciales.

## Fuera de alcance

FOLIOS-UI-1 no crea:

- emisión manual de folios desde UI;
- registros en `documentos_folios` desde pantalla;
- integración con inventario;
- integración con transferencias;
- `folio_id` en movimientos;
- `folio_id` en transferencias;
- compras;
- ventas;
- CFDI;
- dashboard;
- KPIs;
- deploy;
- remoto Git.

## Fase siguiente

Queda pendiente:

- FOLIOS-INVENTARIO-1
