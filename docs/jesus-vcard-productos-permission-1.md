# PERMISOS-JESUS-VCARD-PRODUCTOS-1

## Objetivo

Permitir que el usuario real `jesus.g` pueda ver y usar la sección
`Productos en mi vCard` en `/perfil`, sin convertirlo innecesariamente en
`ADMIN`.

## Permiso involucrado

```text
vcard.productos.administrar
```

El permiso fue formalizado previamente por `PERMISOS-VCARD-PRODUCTOS-1`.
Esta fase no crea un permiso funcional nuevo; solo asegura que un rol actual
de `jesus.g` lo conceda cuando aún no lo tenga.

## Runner

```bash
php database/jesus-vcard-productos-permission.php diagnose --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/jesus-vcard-productos-permission.php apply --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/jesus-vcard-productos-permission.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

## Reglas aplicadas

- El usuario `jesus.g` debe existir, estar activo y no estar eliminado
  lógicamente.
- Si el permiso no existe, se reutiliza el seed formal
  `permisos_vcard_productos_1_seed`.
- Si `jesus.g` ya tiene el permiso por alguno de sus roles, no se modifica nada.
- Si no lo tiene, se asigna el permiso a un rol activo ya asignado al usuario.
- Se prefiere un rol activo no `ADMIN`.
- Si el único rol activo es `ADMIN`, no se agrega ADMIN al usuario; solo se usa
  porque el usuario ya lo tenía.
- Si el usuario no tiene roles activos, la fase se bloquea.
- La asignación es idempotente y no duplica `rol_permisos`.

## Seguridad

- No se crean rutas públicas nuevas.
- Las rutas privadas de productos vCard siguen protegidas por
  `vcard.productos.administrar`.
- POST sin CSRF debe seguir respondiendo `419`.
- Usuario sin permiso debe seguir recibiendo `403`.
- No se confía en controles visuales para autorización.

## Fuera de alcance

- No convertir `jesus.g` en `ADMIN`.
- No crear usuarios reales.
- No borrar roles.
- No borrar permisos.
- No modificar migraciones.
- No tocar compras.
- No tocar inventario.
- No tocar precios.
- No modificar productos funcionalmente.
- No cambiar la vCard pública funcionalmente.

## DB-TEST

El test usa fixtures QA transaccionales para validar:

- Usuario equivalente con rol sin el permiso no ve formularios de productos vCard.
- Al aplicar la fase, el rol existente recibe el permiso.
- La segunda ejecución es idempotente.
- No se duplica `rol_permisos`.
- El usuario no se convierte en `ADMIN`.
- `/perfil` muestra `Productos en mi vCard` con permiso.
- POST con permiso ya no falla por `403`.
- POST sin CSRF sigue respondiendo `419`.
- Usuario sin permiso sigue respondiendo `403`.
- No se modifican productos, inventario ni precios fuera de fixtures.
- Los datos transitorios se revierten por rollback.

## Resultado esperado

Después de ejecutar `apply`, `jesus.g` debe conservar sus roles actuales y uno
de esos roles debe conceder `vcard.productos.administrar`.
