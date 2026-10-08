# VCARD-VCF-1 — Descarga pública segura de contacto

## Objetivo

Implementar la descarga pública segura de un archivo VCF dinámico para una vCard publicada mediante:

```text
GET /v/{slug}/vcf
```

El archivo se genera en servidor en cada petición. No se persiste un `.vcf` físico.

## Servicio creado

`App\Domain\Vcards\VcardVcfService` recibe la representación pública ya filtrada por `VcardService` y genera texto VCF.

La fase usa:

```text
VERSION:3.0
```

## Campos VCF generados

Según datos visibles por privacidad granular:

- `FN`
- `N`, cuando existe nombre público.
- `TITLE`, si el puesto es visible.
- `ORG`, si la empresa es visible.
- `TEL`, solo teléfonos visibles.
- `EMAIL`, solo correo visible.
- `URL`, solo enlaces visibles.
- `NOTE`, para descripción pública y ubicación visible.

No se incluye foto embebida en esta fase.

## Privacidad

El endpoint resuelve la vCard con `VcardService::resolverPublicaPorSlug()`, por lo que solo recibe la representación pública filtrada.

El VCF no debe incluir:

- campos privados;
- IDs internos;
- hashes, tokens, roles o permisos;
- rutas privadas;
- `storage/uploads`;
- precios, stock o costos.

Slugs inexistentes, despublicados o asociados a usuarios inactivos responden 404 seguro sin revelar el motivo.

## Escape VCF

Los valores se escapan antes de generar el VCF:

- `\` se duplica;
- `;` se escapa como `\;`;
- `,` se escapa como `\,`;
- saltos de línea se convierten a `\n`.

Esto evita que un valor de usuario inyecte nuevas líneas o nuevos campos VCF.

## Headers

La respuesta exitosa envía:

```text
Content-Type: text/vcard; charset=utf-8
Content-Disposition: attachment; filename="contacto-{slug}.vcf"
X-Content-Type-Options: nosniff
Cache-Control: public, max-age=300
```

Los 404 seguros usan cuerpo vacío, `text/plain`, `no-store` y no exponen stack trace.

## Vista pública

La vista pública muestra el enlace “Descargar contacto” únicamente cuando la representación pública contiene datos mínimos para generar VCF.

No se agrega QR.

## Fuera de alcance

- No implementa QR.
- No implementa credencial visual/verificable.
- No implementa productos vCard funcionales.
- No muestra precios.
- No muestra existencias.
- No muestra costos.
- No modifica productos, precios ni inventario.

## Validación esperada

```bash
php -l app/Domain/Vcards/VcardVcfService.php
php -l app/Http/Controllers/PublicVcardController.php
php -l routes/web.php
php -l app/Views/vcards/public.php
php -l bootstrap/app.php
php -l database/vcard-vcf.php
php -l database/tests/vcard_vcf_1_test.php
php -l database/tests/vcard_publico_seguridad_1_test.php
git diff --check
php database/vcard-vcf.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

## Siguiente fase sugerida

`VCARD-QR-1`.
