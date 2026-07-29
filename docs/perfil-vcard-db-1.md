# PERFIL-VCARD-DB-1

## Objetivo

Crear la base de datos inicial para el módulo futuro de perfil, vCard pública y credencial digital conforme al contrato cerrado en `docs/perfil-vcard-contrato-1.md`.

Esta fase solo agrega migración, runner, seed idempotente de permisos, DB-TEST y documentación QA. No implementa servicios, repositorios, controladores, rutas, vistas, CSS, JavaScript, QR funcional, VCF funcional ni credencial funcional.

## Tablas creadas

### perfiles_usuario

Responsabilidad: almacenar datos extendidos privados/editables del usuario autenticado.

Campos principales:

- `id`
- `usuario_id`
- nombres y apellidos
- `puesto`
- teléfonos
- enlaces públicos candidatos
- `whatsapp`
- `google_maps_url`
- `ubicacion_publica`
- `creado_en`
- `actualizado_en`

Reglas:

- `usuario_id` único.
- FK a `usuarios(id)`.
- No guarda contraseña, hash, tokens, roles, empresas ni almacenes.

### usuarios_fotos

Responsabilidad: almacenar metadatos de foto de usuario administrada como recurso controlado.

Campos principales:

- `usuario_id`
- `disco`
- `ruta_relativa`
- `nombre_original`
- `nombre_archivo`
- `mime`
- `extension`
- `tamano_bytes`
- `sha256`
- dimensiones
- estado activo/reemplazo/eliminación

Reglas:

- No guarda binarios.
- No guarda ruta pública directa.
- `mime` limitado a JPEG, PNG y WebP.
- Se usa columna generada `foto_activa_unica` para permitir una sola foto activa por usuario.

### vcards_usuario

Responsabilidad: configuración general de vCard pública.

Campos principales:

- `usuario_id`
- `slug`
- `titulo_publico`
- `descripcion_publica`
- `publicada`
- `canal_contacto_preferido`
- fechas de creación, actualización, publicación y despublicación

Reglas:

- `usuario_id` único.
- `slug` único, ASCII, minúsculas y validado por patrón.
- No guarda VCF persistente.
- Validación de slugs reservados queda para servicio futuro.

### vcard_privacidad

Responsabilidad: reglas normalizadas de visibilidad por campo.

Campos principales:

- `vcard_id`
- `campo`
- `visible`
- `actualizado_en`

Reglas:

- Único por `vcard_id + campo`.
- Campos permitidos por CHECK.
- Tabla normalizada, alineada con DEC-05.

### vcard_productos

Responsabilidad: relación independiente entre vCard y productos publicados.

Campos principales:

- `vcard_id`
- `id_producto`
- `activo`
- `destacado`
- `orden`
- `texto_publico`
- auditoría mínima

Reglas:

- FK a `vcards_usuario(id)`.
- FK a `productos(id_producto)`.
- Único por `vcard_id + id_producto`.
- No contiene precio, stock ni costo.
- No modifica catálogo maestro.

### credenciales_usuario

Responsabilidad: estado de credencial digital del usuario.

Campos principales:

- `usuario_id`
- `estatus`
- `emitida_en`
- `expira_en`
- `revocada_en`
- `revocada_por`
- `motivo_revocacion`

Estados permitidos:

- `VIGENTE`
- `SUSPENDIDA`
- `VENCIDA`
- `REVOCADA`

Reglas:

- `usuario_id` único.
- Revocación requiere fecha y actor.
- No guarda datos sensibles dentro del QR.

### credencial_tokens

Responsabilidad: tokens opacos de verificación futura.

Campos principales:

- `credencial_id`
- `token_hash`
- `token_prefix`
- `activo`
- `creado_en`
- `expira_en`
- `revocado_en`
- `usado_ultimo_en`

Reglas:

- Guarda hash SHA-256, no token plano.
- `token_hash` único.
- Permite revocación.
- La generación real queda fuera de esta fase.

## Decisiones DEC aplicadas

- DEC-01: se prepara credencial visual y verificable por fases; DB incluye `credenciales_usuario` y `credencial_tokens`, pero no genera tokens reales.
- DEC-02: foto orientada a almacenamiento privado/controlado; DB solo guarda metadatos.
- DEC-03: productos vCard solo admiten información comercial básica; no hay precio, stock ni costo.
- DEC-04: slug público editable con validación fuerte en DB.
- DEC-05: privacidad granular como tabla normalizada por campo.
- DEC-06: VCF dinámico futuro; no se guarda archivo VCF persistente.

## Permisos sembrados

Seed idempotente: `database/seeds/perfil_vcard_1_seed_permissions.php`.

Permisos:

- `perfil.ver`
- `perfil.editar`
- `perfil.password.cambiar`
- `perfil.foto.actualizar`
- `perfil.foto.eliminar`
- `vcard.ver`
- `vcard.editar`
- `vcard.publicar`
- `vcard.privacidad.editar`
- `vcard.productos.administrar`
- `vcard.qr.ver`
- `vcard.vcf.descargar`
- `credencial.ver`
- `credencial.qr.ver`
- `credencial.qr.descargar`

El seed asigna los permisos al rol `ADMIN` existente y no crea usuarios.

## Qué queda fuera de alcance

- No UI.
- No servicios.
- No repositorios.
- No controladores.
- No rutas.
- No CSS.
- No JavaScript.
- No QR funcional.
- No VCF funcional.
- No credencial funcional.
- No administración de usuarios.
- No revocación de sesiones.
- No publicación real de vCard.
- No cambios en productos, precios ni inventario.

## Comandos

Migrar:

```bash
php database/perfil-vcard.php migrate --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

Sembrar permisos:

```bash
php database/perfil-vcard.php seed --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

Consultar estado:

```bash
php database/perfil-vcard.php status --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

Ejecutar DB-TEST:

```bash
php database/perfil-vcard.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

Rollback:

```bash
php database/perfil-vcard.php rollback --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

## Evidencia esperada

El DB-TEST debe confirmar:

- Migración aplicada o ya aplicada.
- Segunda ejecución idempotente con `already_applied`.
- Siete tablas presentes.
- Engine InnoDB.
- Índices únicos principales.
- FKs principales.
- Permisos creados y asignados a `ADMIN`.
- Sin permisos duplicados.
- Sin relaciones `rol_permisos` duplicadas.
- Sin columnas prohibidas:
  - precio en `vcard_productos`
  - stock en `vcard_productos`
  - costo en `vcard_productos`
  - token plano en `credencial_tokens`
  - password/hash en `perfiles_usuario`
- Sin servicios, controladores, rutas, vistas, CSS ni JavaScript de perfil/vCard/credencial.
- Sin triggers, procedures, functions ni events.
- Datos QA revertidos por rollback transaccional.

## Rollback manual para AwardSpace/phpMyAdmin

Si se requiere rollback manual, ejecutar en este orden:

```sql
DROP TABLE IF EXISTS credencial_tokens;
DROP TABLE IF EXISTS credenciales_usuario;
DROP TABLE IF EXISTS vcard_productos;
DROP TABLE IF EXISTS vcard_privacidad;
DROP TABLE IF EXISTS vcards_usuario;
DROP TABLE IF EXISTS usuarios_fotos;
DROP TABLE IF EXISTS perfiles_usuario;
DELETE FROM schema_migrations
WHERE migration = 'perfil_vcard_1_001_create_profile_vcard_tables';
```

Para revertir permisos estructurales, usar preferentemente el runner `rollback`, porque elimina primero relaciones `rol_permisos` del rol `ADMIN` y luego permisos sin relaciones restantes. No borrar permisos manualmente si ya fueron usados por roles adicionales.

## Riesgos conocidos

- La validación de slugs reservados queda para `VCARD-SERVICE-PRIVACIDAD-1`.
- La publicación pública segura depende de servicios futuros; esta fase solo prepara estructura.
- La unicidad de foto activa usa columna generada compatible con el patrón existente de índices únicos condicionales del proyecto.
- `credencial_tokens` almacena hash, pero la generación, rotación y revocación funcional quedan fuera de esta fase.

## Confirmación de alcance

`PERFIL-VCARD-DB-1` no crea UI ni servicios. La fase solo deja lista la base estructural para implementar perfil/vCard/credencial en fases posteriores.
