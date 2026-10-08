# VCARD-QR-1 — QR público seguro de vCard

## Objetivo

Implementar generación pública segura de QR para vCard:

```text
GET /v/{slug}/qr
```

El QR apunta únicamente a la página pública de la vCard:

```text
/v/{slug}
```

o a su URL absoluta del mismo host de la petición cuando el header `Host` es seguro.

## Servicio creado

`App\Domain\Vcards\VcardQrService`

Responsabilidad:

- generar PNG QR dinámico;
- no persistir archivo físico;
- recibir únicamente el payload público;
- no consultar base de datos.

## Mecanismo usado

No existía librería QR instalada y no se autorizó agregar dependencias externas.

Se implementó un generador interno mínimo de QR PNG:

- QR Model 2;
- versión 5;
- nivel de corrección L;
- modo byte;
- PNG generado en memoria con compresión zlib.

## Payload del QR

El controlador construye el payload desde la petición:

```text
https://{host}/v/{slug}
```

si `Host` es válido. Si no existe host válido, usa `APP_URL`. Si tampoco es válido, cae a:

```text
/v/{slug}
```

## Qué contiene el QR

Solo contiene la URL pública de la vCard.

## Qué NO contiene el QR

- `usuario_id`
- `vcard_id`
- tokens
- hashes
- roles
- permisos
- correo privado
- teléfono privado
- ubicación privada
- rutas internas
- `storage/uploads`
- VCF embebido
- credencial verificable
- precios
- stock
- costos

## Headers

Respuesta exitosa:

```text
Content-Type: image/png
X-Content-Type-Options: nosniff
Cache-Control: public, max-age=3600
```

404 seguro:

```text
Content-Type: text/plain; charset=UTF-8
Cache-Control: no-store
X-Robots-Tag: noindex, nofollow
```

## Privacidad

El endpoint resuelve la vCard mediante `VcardService::resolverPublicaPorSlug()`.

Por eso:

- slug inexistente responde 404;
- vCard despublicada responde 404;
- usuario inactivo responde 404;
- no revela si el slug existe pero no es público.

## Vista pública

La vista pública muestra un QR cuando la vCard publicada se puede resolver.

No agrega productos vCard, credencial, precios, stock ni costos.

## Fuera de alcance

- No implementa credencial visual/verificable.
- No implementa productos vCard funcionales.
- No muestra precios.
- No muestra existencias.
- No muestra costos.
- No modifica productos, precios ni inventario.
- No persiste archivos QR físicos.

## Validación esperada

```bash
php -l app/Domain/Vcards/VcardQrService.php
php -l app/Http/Controllers/PublicVcardController.php
php -l routes/web.php
php -l app/Views/vcards/public.php
php -l bootstrap/app.php
php -l database/vcard-qr.php
php -l database/tests/vcard_qr_1_test.php
php -l database/tests/vcard_publico_seguridad_1_test.php
git diff --check
php database/vcard-qr.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

## Siguiente fase sugerida

Credencial visual/verificable, solo cuando sea autorizada.
