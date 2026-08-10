# CATALOGOS-BASE-CIERRE-QA-1

## Resultado general

Fase de cierre QA tecnico/documental para inventariar la base transversal antes de avanzar a modulos operativos mayores.

Esta fase no agrega funcionalidad, no crea UI, no modifica migraciones, no modifica seeds y no toca datos reales.

## Runner

```bash
php database/catalogos-base-cierre-qa.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

El runner es read-only: solo consulta estructura, rutas, archivos y consistencia minima. No inserta, actualiza ni borra datos.

## Tablas confirmadas por el DB-TEST

El DB-TEST confirma como obligatorias:

- `usuarios`
- `roles`
- `permisos`
- `usuario_roles`
- `rol_permisos`
- `auditoria_eventos`

Tambien inventaria, cuando existen:

- `empresas`
- `almacenes`
- `monedas`
- `tipos_cambio`
- `unidades_medida`
- `marcas`
- `lineas_producto`
- `clasificaciones_producto`
- `tipos_producto`
- `conceptos_movimiento_inventario`
- `impuestos`
- `ui_temas`
- `theme_assignments`

Las tablas opcionales ausentes se reportan como `PENDIENTE_CONTROLADO`, no como fallo fatal, salvo que un guardrail de integridad indique riesgo real.

## Permisos clave confirmados

El test valida:

- rol `ADMIN` activo;
- permiso `auditoria.ver` activo;
- `ADMIN` con `auditoria.ver`;
- permisos clave de perfil/vCard/credencial cuando existen;
- cero codigos de permisos duplicados;
- cero relaciones duplicadas en `rol_permisos`.

## Rutas criticas confirmadas

Privadas:

- `/app`
- `/perfil`
- `/perfil/credencial`
- `/auditoria`

Publicas:

- `/health`
- `/v/{slug}`
- `/credencial/verificar/{token}`

Rutas peligrosas conocidas que deben permanecer ausentes:

- `/storage/uploads`
- `/api/credencial`
- `/api/vcard`

## Seeds existentes relevantes

El inventario del proyecto incluye seeds relevantes para:

- RBAC base;
- permisos de perfil/vCard/credencial;
- permisos de auditoria;
- catalogos base;
- scope empresa/almacen;
- inventario;
- folios;
- precios.

Esta fase no modifica ningun seed.

## Runners existentes relevantes

El test inventaria runners transversales y marca como `PENDIENTE_CONTROLADO` los que no existan. La ausencia de un runner opcional no falla el cierre QA si no hay dependencia funcional actual.

## Guardrails vigentes

El test falla si detecta:

- tablas obligatorias ausentes;
- `ADMIN` ausente;
- `auditoria.ver` ausente;
- `ADMIN` sin `auditoria.ver`;
- permisos duplicados;
- `rol_permisos` duplicados;
- usernames o emails duplicados;
- almacenes huerfanos;
- tipos de cambio huerfanos;
- productos apuntando a catalogos inexistentes;
- `id_producto` fuera de `^[A-Z0-9]{1,16}$`;
- metadata de auditoria con `password_hash`, `token_hash`, `storage/uploads` o token plano obvio;
- rutas publicas peligrosas;
- cambios abiertos fuera de los archivos permitidos de esta fase.

## Excepcion conocida

`database/productos.php db:test` no debe usarse como bloqueo obligatorio porque la base de prueba contiene un producto persistente real/no-QA:

```text
102016169 | REFRIGERANTE R-410A 5KG IGAS
```

Ese producto no debe borrarse ni modificarse para forzar una precondicion historica de base vacia.

## Riesgos residuales

- `CATALOGOS-BASE-CIERRE-QA-1` no reemplaza pruebas funcionales especificas de cada modulo.
- Las tablas opcionales pendientes deben cerrarse en fases propias.
- El cierre QA no implementa nuevas reglas de negocio ni UI.
- La auditoria sigue limitada por el esquema actual de `auditoria_eventos`.

## Siguiente fase sugerida

Continuar solo con la siguiente fase autorizada por el usuario. Esta fase no inicia ni recomienda automaticamente una fase funcional nueva.
