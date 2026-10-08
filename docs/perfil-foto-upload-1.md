# PERFIL-FOTO-UPLOAD-1

## Alcance

Implementa la carga física segura de foto de perfil desde `/perfil`, usando `POST /perfil/foto`.

La ruta queda protegida por:

- sesión autenticada;
- permiso `perfil.foto.actualizar`;
- token CSRF;
- validación estricta de archivo real.

## Almacenamiento

Los archivos se guardan fuera de `public/`, bajo `storage/uploads/usuarios/{usuario_id}/fotos/`.

La vista privada de perfil no expone `ruta_relativa`, `sha256`, IDs internos ni rutas físicas. Solo muestra metadatos seguros de la foto activa.

## Validaciones de archivo

Se aceptan únicamente:

- JPEG;
- PNG;
- WebP, cuando el entorno PHP puede detectar y leer el formato.

Se rechazan:

- SVG;
- GIF;
- PHP u otros ejecutables;
- archivos con MIME real no permitido;
- imágenes corruptas;
- archivos vacíos;
- archivos mayores a 5 MiB;
- nombres con traversal;
- dobles extensiones peligrosas.

En producción el archivo debe provenir de una carga HTTP válida mediante `is_uploaded_file()` y se mueve con `move_uploaded_file()`.

## Registro de metadatos

La foto se registra mediante `ProfileService::registrarFoto()`, que desactiva la foto activa anterior y conserva una sola foto activa por usuario.

Si falla el registro después de almacenar el archivo físico, el controlador elimina el archivo nuevo para evitar huérfanos.

## Fuera de alcance

PERFIL-FOTO-UPLOAD-1 no implementa:

- serving público de foto;
- `/v/{slug}/foto` funcional;
- QR;
- VCF;
- credencial visual o verificable;
- productos en vCard;
- cambios en productos, precios o inventario.

`/v/{slug}/foto` debe permanecer como ruta segura 404 hasta una fase posterior.

## Validación

Runner específico:

```bash
php database/perfil-foto-upload.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

Regresiones esperadas:

- `perfil-ui`;
- `perfil-service`;
- `vcard-publico`;
- `vcard-service`;
- `router-dynamic-params`;
- `perfil-vcard`;
- `rbac`;
- `productos-imagen`;
- `productos-2`.
