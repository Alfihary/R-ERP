# CREDENCIAL-CIERRE-QA-1

## Nota AUDITORIA-SERVICE-1

El cierre QA de credencial mantiene la superficie publica/privada existente y agrega auditoria centralizada mediante `AuditService` para eventos publicos de verificacion y eventos privados de token, QR y foto. No se agregan rutas publicas nuevas ni se exponen datos sensibles.

## Objetivo

Realizar el cierre integral de QA del bloque perfil, vCard y credencial sin agregar funcionalidad nueva.

Esta fase consolida:

- inventario final de rutas;
- matriz final de permisos;
- checklist de seguridad;
- endpoints públicos y privados;
- datos permitidos y prohibidos por contexto;
- runner QA de agregación;
- regresiones completas del bloque.

## Rutas finales

### Perfil y credencial privada

| Método | Ruta | Acceso |
| --- | --- | --- |
| GET | `/perfil` | Sesión + `perfil.ver` |
| POST | `/perfil/actualizar` | Sesión + `perfil.editar` + CSRF |
| POST | `/perfil/password` | Sesión + `perfil.password.cambiar` + CSRF |
| POST | `/perfil/foto` | Sesión + `perfil.foto.actualizar` + CSRF |
| POST | `/perfil/foto/eliminar` | Sesión + `perfil.foto.eliminar` + CSRF |
| GET | `/perfil/credencial` | Sesión + `credencial.ver` |
| GET | `/perfil/credencial/foto` | Sesión + `credencial.ver` |
| GET | `/perfil/credencial/qr` | Sesión + `credencial.ver` + `credencial.qr.ver` |
| GET | `/perfil/credencial/qr/descargar` | Sesión + `credencial.ver` + `credencial.qr.ver` + `credencial.qr.descargar` |
| POST | `/perfil/credencial/token/renovar` | Sesión + `credencial.ver` + `credencial.qr.ver` + CSRF |
| POST | `/perfil/credencial/token/revocar` | Sesión + `credencial.ver` + `credencial.qr.ver` + CSRF |

### Credencial pública

| Método | Ruta | Acceso |
| --- | --- | --- |
| GET | `/credencial/verificar/{token}` | Público, anti-enumeración, rate limit |

### vCard pública

| Método | Ruta | Acceso |
| --- | --- | --- |
| GET | `/v/{slug}` | Público, condicionado por publicación y privacidad |
| GET | `/v/{slug}/foto` | Público, condicionado por publicación, privacidad y validación de archivo |
| GET | `/v/{slug}/vcf` | Público, condicionado por publicación y privacidad |
| GET | `/v/{slug}/qr` | Público, PNG dinámico no persistido |

## Rutas explícitamente no existentes

```text
GET /credencial/verificar/{token}/foto
GET /v/{slug}/productos
GET /api/credencial/*
GET /api/vcard/*
```

La foto pública de credencial no está implementada. Los productos de vCard se integran dentro de `/v/{slug}` cuando la privacidad lo permite; no existe ruta independiente `/v/{slug}/productos`.

## Matriz final de permisos

| Permiso | Uso |
| --- | --- |
| `perfil.ver` | Ver perfil privado |
| `perfil.editar` | Actualizar datos editables del perfil |
| `perfil.password.cambiar` | Cambiar contraseña propia |
| `perfil.foto.actualizar` | Subir/reemplazar foto privada |
| `perfil.foto.eliminar` | Eliminar lógicamente foto privada |
| `vcard.ver` | Ver configuración privada de vCard |
| `vcard.editar` | Editar datos de vCard |
| `vcard.publicar` | Publicar/despublicar vCard |
| `vcard.privacidad.editar` | Editar privacidad de campos |
| `vcard.productos.administrar` | Administrar exposición pública de productos en vCard |
| `vcard.qr.ver` | Ver QR de vCard |
| `vcard.vcf.descargar` | Descargar VCF privado cuando aplique |
| `credencial.ver` | Ver credencial privada y foto privada de credencial |
| `credencial.qr.ver` | Ver QR privado y renovar/revocar token |
| `credencial.qr.descargar` | Descargar PNG dinámico de QR privado |

Reglas generales:

- rutas privadas requieren sesión;
- rutas privadas requieren permiso correspondiente;
- rutas públicas no requieren sesión;
- rutas públicas no exponen datos sensibles.

## Endpoints públicos y privados

| Contexto | Tipo | Datos permitidos |
| --- | --- | --- |
| Perfil privado | Privado | Datos propios editables, metadata segura de foto |
| Credencial privada | Privado | Nombre, username, email interno, puesto, teléfonos, ubicación, estado, emisión, foto vía endpoint privado, QR privado |
| Verificación pública | Público | Estado de verificación, nombre, puesto, ubicación laboral, emisión, fecha de verificación |
| vCard pública | Público | Campos habilitados por privacidad |
| VCF | Público | Campos habilitados por privacidad, sin productos |
| QR | Público/privado según endpoint | PNG dinámico con URL pública correspondiente |

## Datos prohibidos

No deben exponerse:

- `password_hash`;
- `token_hash`;
- token plano en HTML;
- tabla o nombre interno `credencial_tokens`;
- roles o permisos internos;
- `ruta_relativa`;
- `storage/uploads`;
- paths físicos;
- precios;
- stock/existencias;
- costos;
- proveedores internos;
- rutas privadas de archivos;
- datos ocultos por privacidad.

## Checklist de seguridad validado

- CSRF global activo para POST privados.
- Rutas privadas protegidas por `AuthMiddleware`.
- Rutas privadas sensibles protegidas por `PermissionMiddleware`.
- Falta de sesión redirige/bloquea.
- Falta de permiso responde 403.
- Verificación pública usa respuestas inválidas uniformes.
- Verificación pública aplica rate limit en memoria.
- Auditoría pública mínima no guarda token ni hash.
- QR privado y QR de vCard se generan dinámicamente y no persisten PNG.
- Foto privada de credencial se sirve por endpoint autenticado.
- Foto privada valida `realpath()` dentro de `storage/uploads/usuarios/`.
- Foto privada valida tamaño, legibilidad, MIME real y extensión coherente.
- Foto privada rechaza SVG, GIF, PHP, doble extensión ejecutable y traversal.
- Foto pública de vCard respeta publicación, privacidad y validación de archivo.
- VCF respeta privacidad y no incluye productos.
- vCard pública solo muestra productos si la privacidad lo permite.
- Headers defensivos aplicados:
  - `X-Content-Type-Options: nosniff`;
  - `Referrer-Policy: strict-origin-when-cross-origin`;
  - `X-Frame-Options: DENY`;
  - `Cache-Control` según endpoint.

## Comandos ejecutados

```bash
php -l database/credencial-cierre-qa.php
php -l database/tests/credencial_cierre_qa_1_test.php
git diff --check
php database/credencial-cierre-qa.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

Regresiones completas del bloque:

```bash
php database/perfil-vcard.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/perfil-service.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/perfil-ui.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/perfil-foto-upload.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/vcard-service.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/vcard-publico.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/vcard-foto-publica.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/vcard-vcf.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/vcard-qr.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/vcard-productos.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/credencial-visual.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/credencial-token-qr.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/credencial-verificacion-publica.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/credencial-hardening.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/perfil-credencial-foto.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/rbac.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/router-dynamic-params.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/productos-imagen.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
php database/productos-2.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

## Resultado de pruebas

Resultado esperado:

- runner QA integral: PASS;
- regresiones completas: PASS;
- sin staging;
- sin commit;
- sin cambios en `app/`, `routes/web.php`, `bootstrap/app.php`, `public/css/`, migraciones, seeds, productos, precios ni inventario.

## Riesgos residuales

- El rate limit público de credencial es en memoria por proceso PHP. En despliegues multiproceso o con reinicios, no es persistente.
- Auditoría privada granular queda pendiente hasta crear un `AuditService` central reutilizable.
- Foto pública de credencial no está implementada. Si el producto la requiere, debe diseñarse con contrato propio.
- La vCard pública expone solo lo permitido por privacidad, pero requiere mantener disciplina de escape y revisión en futuras ampliaciones.

## Recomendación de siguiente módulo/fase

El bloque perfil/vCard/credencial queda apto para cierre de QA y permite continuar con módulos posteriores una vez autorizado el commit de esta fase documental/QA.
