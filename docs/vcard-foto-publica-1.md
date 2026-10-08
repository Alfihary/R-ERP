# VCARD-FOTO-PUBLICA-1

## Objetivo

Implementar serving público seguro de la foto de vCard en `GET /v/{slug}/foto`, usando los metadatos de foto privada registrados por `ProfileService::registrarFoto()`.

## Endpoint actualizado

`GET /v/{slug}/foto` deja de ser un 404 diferido y sirve el binario de imagen únicamente cuando todas las reglas de publicación, privacidad y archivo pasan.

## Reglas de autorización pública

El endpoint responde `200` solo si:

- la vCard existe;
- la vCard está publicada;
- el usuario está activo y no eliminado;
- la privacidad `foto` está visible;
- existe una foto activa no reemplazada ni eliminada;
- el archivo físico existe;
- el path resuelto permanece dentro de `storage/uploads/usuarios/`;
- el MIME registrado y el MIME real son permitidos.

En cualquier otro caso responde `404` seguro con cuerpo vacío.

## Reglas de privacidad

La representación pública HTML puede mostrar únicamente `/v/{slug}/foto` como URL de imagen. No expone:

- `ruta_relativa`;
- path físico;
- `storage/uploads/usuarios`;
- IDs internos;
- hashes.

## Reglas de path seguro

El controlador no recibe nombres de archivo desde request ni construye rutas con el slug.

La ruta se obtiene desde metadatos autorizados por el servicio, se valida como relativa y debe iniciar con:

```text
uploads/usuarios/
```

Luego se resuelve con `realpath()` y se confirma que permanece dentro del storage privado autorizado.

## MIME permitidos

Solo se sirven:

- `image/jpeg`;
- `image/png`;
- `image/webp`.

No se sirven SVG, GIF, PHP, HTML, JS ni archivos con MIME real diferente al registrado.

## Headers enviados

Para respuestas `200`:

- `Content-Type` de la imagen;
- `Content-Length`;
- `X-Content-Type-Options: nosniff`;
- `Cache-Control: public, max-age=3600`;
- headers públicos de seguridad heredados del controlador.

Para respuestas `404`:

- cuerpo vacío;
- `Cache-Control: no-store`;
- `X-Robots-Tag: noindex, nofollow`;
- `X-Content-Type-Options: nosniff`.

## Casos 404

Se valida 404 para:

- slug inexistente;
- vCard despublicada;
- usuario inactivo;
- privacidad `foto=false`;
- sin foto activa;
- archivo físico inexistente;
- MIME no permitido;
- path traversal;
- archivo vacío;
- error de lectura.

## Fuera de alcance

VCARD-FOTO-PUBLICA-1 no implementa:

- QR;
- VCF;
- credencial visual o verificable;
- productos vCard funcionales;
- cambios en productos;
- cambios en precios;
- cambios en inventario.

## Comandos de validación

```bash
php -l app/Http/Controllers/PublicVcardController.php
php -l app/Domain/Vcards/VcardService.php
php -l app/Infrastructure/Repositories/UserVcardRepository.php
php -l database/vcard-foto-publica.php
php -l database/tests/vcard_foto_publica_1_test.php
git diff --check
php database/vcard-foto-publica.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

## Siguiente fase sugerida

Después de cierre con commit, puede continuarse con una fase explícita de QR o VCF, si se autoriza.
