# SCOPE-CONTEXT-1 — Contexto activo empresa/almacén

## Objetivo

Mantener un par empresa/almacén activo para la sesión autenticada, validado
siempre contra el alcance efectivo entregado por `UserScopeService`.

Esta fase no crea dashboard, CRUD de empresas o almacenes ni módulos
operativos.

## Alcance efectivo y contexto activo

El alcance efectivo es el conjunto completo de empresas y almacenes que el
usuario puede utilizar según asignaciones, estados y borrado lógico.

El contexto activo es un único par empresa/almacén seleccionado dentro de ese
alcance. Facilita la operación futura, pero no amplía permisos ni sustituye la
validación de un recurso concreto.

```text
autenticación
  + permiso
  + alcance efectivo
  + contexto activo
  + validación del recurso
```

## `ScopeContextService`

El servicio:

- Resuelve el alcance vigente mediante `UserScopeService`.
- Valida que la empresa solicitada esté permitida.
- Valida que el almacén solicitado esté permitido.
- Valida que el almacén pertenezca a la empresa.
- Selecciona automáticamente cuando existe un solo par posible.
- Limpia un contexto almacenado que dejó de ser válido.
- Devuelve estado controlado cuando no existe alcance.

El servicio no recibe nombres como fuente de verdad y no confía en IDs del
navegador.

## Persistencia en sesión

Las únicas claves de contexto son:

```text
active_company_id
active_warehouse_id
```

No se almacenan en sesión:

- Listas de empresas.
- Listas de almacenes.
- Nombres.
- Códigos.
- Objetos de alcance.
- Permisos.

Los nombres mostrados se obtienen del backend después de volver a resolver el
alcance.

## Ruta de actualización

```text
POST /app/contexto
```

La ruta exige:

- Sesión autenticada.
- Permiso `sistema.app.ver`.
- Token CSRF válido.
- Empresa y almacén dentro del alcance efectivo.
- Coincidencia entre empresa y almacén.

La entrada inválida no cambia el contexto válido existente. La respuesta
siempre redirige de forma controlada a `/app` y no expone detalles internos.

No existe una ruta `GET` que modifique contexto.

## Selector

Si existe un solo par posible, el contexto se establece automáticamente y el
shell muestra únicamente los nombres activos.

Si existen varios pares, `/app` muestra un formulario mínimo con empresa y
almacén. El formulario usa `POST`, incluye CSRF y no aplica seguridad desde
JavaScript.

## DB-TEST

```powershell
php database/scope-context.php db:test `
  --database=<db-test> `
  --confirm-database=<db-test>
```

La prueba transaccional valida:

1. Autoselección con un solo par.
2. Solo dos IDs de contexto en sesión.
3. Selector requerido con múltiples pares.
4. Cambio válido.
5. Empresa fuera de alcance rechazada.
6. Almacén fuera de alcance rechazado.
7. Empresa y almacén incompatibles rechazados.
8. Contexto inválido en sesión eliminado.
9. Usuario sin alcance controlado.
10. Conteos persistentes intactos después del rollback.

## Seguridad para módulos futuros

El contexto activo no autoriza por sí solo una consulta o una escritura. Cada
módulo futuro deberá:

1. Exigir autenticación.
2. Exigir permiso por acción.
3. Resolver alcance efectivo.
4. Validar que el recurso pertenezca al alcance.
5. Aplicar empresa y almacén en la consulta.
6. Auditar cuando la acción sea crítica.

## Fuera de alcance

- CRUD de empresas o almacenes.
- Dashboard y métricas.
- Productos, inventario, tickets, compras, ventas, CXC o CXP.
- Catálogos, reportes y menú dinámico.
- Migraciones, seeds o permisos nuevos.
- Integración MySQL de `/health`.
- Deploy o configuración de producción.
