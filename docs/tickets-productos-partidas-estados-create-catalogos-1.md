# TP-PARTIDAS-ESTADOS-CREATE-CATALOGOS-1

## Objetivo

Mejorar la captura de tickets de solicitud de alta de productos para reemplazar inputs libres por catálogos controlados cuando el sistema ya tiene datos maestros disponibles.

La fase mantiene el flujo como solicitud de control y revisión. Aprobar una partida no crea productos reales, precios, inventario, compras ni proveedores.

## Alcance implementado

- Empresa se captura con selector de empresas activas asignadas al usuario.
- Almacén se captura con selector filtrado por empresa y asignación activa del usuario.
- Al cambiar Empresa, Almacén se filtra automáticamente con JavaScript mínimo local en la vista, usando datos seguros ya renderizados por servidor.
- El filtro Empresa → Almacén compara identificadores como string (`empresa_id` seleccionado contra `empresa_id` real del almacén) para evitar fallos por diferencias de tipo entre HTML y JSON.
- Si una empresa seleccionada no tiene almacenes disponibles para el usuario, el selector muestra `Sin almacenes disponibles` y no conserva una selección inválida.
- El JSON de almacenes incluye solo `empresa_id`, `almacen_id`, `codigo` y `nombre`; no incluye sesión cruda, permisos, tokens, rutas físicas ni SQL.
- Ya no se requiere botón manual para actualizar almacenes.
- Marca se captura con selector del catálogo `marcas`; se conserva en `marca_texto` por compatibilidad con el contrato documental de la tabla de partidas.
- Moneda se captura con selector del catálogo `monedas`.
- Unidad SAT se captura con un solo campo buscable `input + datalist` alimentado desde `unidades_sat` activas y mostrando código, nombre y descripción.
- Clave SAT se captura con un solo campo buscable `input + datalist` alimentado desde `claves_sat` activas y mostrando código y descripción.
- No se usan campos separados de búsqueda y resultado para Unidad SAT ni Clave SAT.
- El backend resuelve el texto capturado contra catálogo real antes de persistir el ID; no guarda texto libre SAT.
- El backend valida antes de persistir que la empresa esté disponible para el usuario y que el almacén pertenezca a esa empresa y a su alcance operativo.
- La fase incorpora JavaScript mínimo solo para la dependencia Empresa → Almacén. No crea JS general, framework, dependencias, CDN ni endpoints nuevos.
- Si se requiere autocomplete remoto avanzado para SAT, debe abrirse como fase posterior con endpoint privado y pruebas propias.
- Se redujo el uso visible de la palabra `documental` en etiquetas repetitivas:
  - `Marca documental` ahora es `Marca`.
  - `Proveedor documental` ahora es `Proveedor`.
  - `Costo sugerido documental` ahora es `Costo sugerido`.
  - `Crear ticket documental` ahora es `Crear ticket`.

## Contrato de seguridad

- Las rutas privadas existentes siguen protegidas por `AuthMiddleware`.
- Las acciones sensibles siguen protegidas por `PermissionMiddleware`.
- El formulario conserva CSRF.
- La vista escapa datos con `e()`.
- El filtrado de empresa y almacén se basa en asignaciones reales del usuario, no en controles visuales.
- El contrato real de relación usa `empresas.id`, `almacenes.id`, `almacenes.empresa_id`, `usuario_empresas.empresa_id` y `usuario_almacenes.almacen_id`.
- Una combinación empresa/almacén inválida o fuera de alcance se rechaza en servidor aunque sea enviada manualmente por POST.
- No se exponen rutas físicas, hashes, tokens ni credenciales.

## Fuera de alcance

- No crear productos reales.
- No crear precios.
- No crear inventario.
- No crear compras.
- No crear proveedores reales.
- No implementar correo runtime.
- No implementar adjuntos reales.
- No crear migraciones.
- No crear seeds.
- No hacer deploy.

## Validación esperada

```powershell
php -l app/Http/Controllers/ProductRequestTicketController.php
php -l app/Infrastructure/Repositories/ProductRequestTicketRepository.php
php -l app/Views/tickets/productos/create.php
php -l database/tickets-productos-partidas-estados-create-catalogos.php
php -l database/tests/tickets_productos_partidas_estados_create_catalogos_1_test.php
git diff --check
php database/tickets-productos-partidas-estados-create-catalogos.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

## Regresiones mínimas

```powershell
php database/tickets-productos-partidas-estados-db.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/tickets-productos-partidas-estados-permisos.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/tickets-productos-partidas-estados-service.php service:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/tickets-productos-partidas-estados-routes-controller.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/tickets-productos-partidas-estados-ui.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

## Criterios de aceptación

- Empresa, almacén, marca y moneda se muestran como selects.
- Unidad SAT y Clave SAT usan un solo campo visible buscable por catálogo, con clave y descripción suficiente en cada opción del `datalist`.
- Unidad SAT y Clave SAT rechazan valores inexistentes en el catálogo activo.
- Clave SAT rechaza descripciones ambiguas y solicita una clave más específica.
- Al cambiar Empresa, Almacén se actualiza automáticamente y no conserva almacenes de otra empresa.
- Almacenes se limitan a la empresa seleccionada.
- Empresa y almacén respetan el alcance activo/asignado del usuario.
- El ticket creado conserva los ids SAT y moneda seleccionados.
- La marca seleccionada desde catálogo se convierte a texto de marca para el contrato actual de partidas.
- No se crean datos operativos fuera del ticket transaccional de prueba.
- No se modifica funcionalidad de compras, inventario, precios ni productos reales.
