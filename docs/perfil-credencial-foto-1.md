# PERFIL-CREDENCIAL-FOTO-1

## Objetivo

Implementar visualización segura de la foto activa del usuario autenticado dentro de la credencial visual privada.

## Ruta privada creada

```text
GET /perfil/credencial/foto
```

La ruta sirve únicamente la foto activa del usuario autenticado. No acepta `usuario_id`, path, nombre de archivo ni parámetros de selección.

## Reglas de acceso

- Requiere sesión activa mediante `AuthMiddleware`.
- Requiere permiso `credencial.ver` mediante `PermissionMiddleware`.
- Solo resuelve la foto activa del usuario autenticado.
- Si no hay foto o no pasa validación de archivo, responde 404 seguro.
- La verificación pública de credencial no muestra foto en esta fase.

## Reglas de validación de archivo

La lectura del archivo se hace desde metadata interna y valida:

- `ruta_relativa` iniciando en `uploads/usuarios/`;
- ausencia de `..`, barras invertidas y rutas absolutas;
- `realpath()` dentro de `storage/uploads/usuarios/`;
- archivo existente, legible y mayor a 0 bytes;
- MIME real con `finfo`;
- MIME real igual al MIME registrado;
- MIME permitido: `image/jpeg`, `image/png`, `image/webp`;
- extensión coherente con MIME;
- rechazo de SVG, GIF, PHP, doble extensión ejecutable y traversal.

## Headers

Para respuesta 200:

```text
Content-Type: image/jpeg | image/png | image/webp
X-Content-Type-Options: nosniff
Cache-Control: private, max-age=300
Referrer-Policy: strict-origin-when-cross-origin
X-Frame-Options: DENY
```

Para respuesta 404:

```text
Content-Type: text/plain; charset=utf-8
Cache-Control: no-store
X-Content-Type-Options: nosniff
Referrer-Policy: strict-origin-when-cross-origin
X-Frame-Options: DENY
```

## Por qué no se expone `storage/uploads`

Las fotos permanecen fuera de `public/`. La vista privada solo usa `/perfil/credencial/foto`; nunca imprime `ruta_relativa`, `storage/uploads`, path físico ni nombre físico como fuente de acceso.

## Verificación pública

La fase mantiene una regla conservadora: `/credencial/verificar/{token}` no muestra foto ni crea endpoint público de foto de credencial. Si se requiere foto pública de credencial, debe diseñarse en una fase posterior con contrato y pruebas propios.

## Datos prohibidos

No deben aparecer en HTML:

- `ruta_relativa`;
- `storage/uploads`;
- path físico;
- `password_hash`;
- `token_hash`;
- roles o permisos internos;
- IDs sensibles.

## Comandos ejecutados

```bash
php database/perfil-credencial-foto.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

## Evidencia esperada

- JPG, PNG y WebP válidos responden 200 con MIME correcto.
- Sin foto, archivo inexistente, archivo vacío, MIME inválido, SVG, GIF, PHP, doble extensión y traversal responden 404 seguro.
- Vista privada usa `<img src="/perfil/credencial/foto">`.
- Vista privada muestra placeholder cuando no hay foto.
- Verificación pública no muestra foto.
- Datos QA se revierten por transacción y archivos QA se eliminan.

## Riesgos residuales

- La disponibilidad depende de que `storage/uploads/usuarios/` exista y sea legible por PHP.
- La respuesta 200 lee el archivo completo en memoria; aceptable para fotos de perfil pequeñas ya limitadas por la fase de upload.

## Siguiente fase sugerida

Si el producto requiere foto en la verificación pública de credencial, crear una fase específica para endpoint público de foto de credencial con privacidad, antifiltración, anti-enumeración y rate limit propios.
