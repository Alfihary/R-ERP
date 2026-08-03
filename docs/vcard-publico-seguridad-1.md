# VCARD-PUBLICO-SEGURIDAD-1

## Objetivo

Crear la superficie pública segura inicial de vCard usando `VcardService` como
fuente de verdad de privacidad y el router dinámico con parámetros `{slug}`.

## Rutas creadas

- `GET /v/{slug}`
- `GET /v/{slug}/foto`

No se crearon rutas para QR, VCF, productos vCard ni credencial verificable.

## Controlador creado

`app/Http/Controllers/PublicVcardController.php`

Responsabilidades:

- recibe `Request $request, array $params`;
- lee `slug` desde `$params['slug']`;
- resuelve la representación pública con `VcardService`;
- responde 404 neutro cuando no existe, está despublicada o el usuario está
  inactivo;
- renderiza solo datos ya filtrados por el servicio;
- no contiene SQL directo.

## Vistas creadas

- `app/Views/vcards/public.php`
- `app/Views/vcards/not-found.php`

Las vistas son públicas y autocontenidas. No usan el layout privado del ERP para
evitar exponer navegación, sesión, permisos o contexto interno.

## CSS creado

`public/css/modules/vcard-public.css`

Usa tokens globales de `public/css/core/app.css`, es responsive, mantiene foco
visible y respeta `prefers-reduced-motion`.

## Reglas de privacidad pública

- Solo se muestran campos presentes en la representación pública de
  `VcardService`.
- No se imprimen IDs internos.
- No se imprime `usuario_id`.
- No se imprime `vcard_id`.
- No se imprime `password_hash`.
- No se imprimen roles, permisos ni tokens.
- No se imprime `ruta_relativa`.
- No se muestran precio, stock ni costo.
- Los campos privados no se renderizan como HTML oculto, comentarios,
  atributos `data-*` ni scripts.

## Reglas de foto pública

Estado posterior a `VCARD-FOTO-PUBLICA-1`:

- `GET /v/{slug}/foto` sirve una imagen real solo cuando la vCard está publicada,
  el usuario está activo, la privacidad `foto` está visible y existe foto activa
  con archivo físico seguro.
- Si la privacidad `foto` está desactivada, no hay foto, el archivo no existe o
  el path no es seguro, responde 404 seguro.
- No se expone `ruta_relativa` ni path físico en la vista pública.

## Reglas de contacto público

La acción principal de contacto se muestra solo si la representación pública
incluye `canal_contacto`.

Canales soportados:

- `whatsapp`
- `telefono_movil`
- `telefono_fijo`
- `correo`

Si no hay canal visible autorizado, no se muestra acción de contacto.

## Fuera de alcance

- QR.
- VCF.
- credencial visual.
- credencial verificable.
- productos vCard funcionales.
- precios, existencias o costos.
- formulario de contacto.
- productos, precios e inventario.
- staging y commit.

## Comandos ejecutados

```bash
php -l app/Http/Controllers/PublicVcardController.php
php -l app/Views/vcards/public.php
php -l app/Views/vcards/not-found.php
php -l routes/web.php
php -l bootstrap/app.php
php -l database/vcard-publico.php
php -l database/tests/vcard_publico_seguridad_1_test.php
git diff --check
php database/vcard-publico.php functional:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

## Evidencia esperada

El test funcional valida:

- rutas públicas declaradas;
- slug inexistente = 404 seguro;
- vCard despublicada = 404 seguro;
- usuario inactivo = 404 seguro;
- campos visibles aparecen;
- campos privados no aparecen;
- metadatos no incluyen campos privados;
- no se exponen IDs, hashes, roles, permisos, tokens ni rutas privadas;
- foto con privacidad desactivada y foto inexistente = 404;
- enlaces externos usan `rel="noopener noreferrer"`;
- no existen rutas QR/VCF/productos/credencial;
- rollback de datos QA.

## Siguiente fase sugerida

Revisión/cierre de `VCARD-FOTO-PUBLICA-1` y, posteriormente, una fase explícita
para QR o VCF si se autoriza.
