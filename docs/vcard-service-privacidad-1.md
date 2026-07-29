# VCARD-SERVICE-PRIVACIDAD-1

## Objetivo

Crear la capa backend reutilizable para configurar la vCard del usuario autenticado y construir una representación pública segura filtrada por privacidad granular.

Esta fase no crea rutas públicas, UI pública, QR, VCF, credencial visual ni productos vCard funcionales.

## Archivos creados

- `app/Domain/Vcards/VcardValidationException.php`
- `app/Domain/Vcards/VcardService.php`
- `app/Domain/Vcards/VcardPrivacyService.php`
- `app/Infrastructure/Repositories/UserVcardRepository.php`
- `app/Infrastructure/Repositories/VcardPrivacyRepository.php`
- `database/vcard-service.php`
- `database/tests/vcard_service_privacidad_1_test.php`
- `docs/vcard-service-privacidad-1.md`

## Métodos principales

`VcardService`:

- `asegurarVcard(int $usuarioId): array`
- `obtenerConfiguracionPrivada(int $usuarioId): array`
- `actualizarConfiguracion(int $usuarioId, array $input): array`
- `publicar(int $usuarioId): array`
- `despublicar(int $usuarioId): array`
- `resolverPublicaPorSlug(string $slug): ?array`
- `construirRepresentacionPublica(array $vcard): array`
- `validarSlugDisponible(string $slug, ?int $vcardId = null): void`
- `resolverCanalContactoPublico(array $representacion): ?array`

`VcardPrivacyService`:

- `camposPermitidos(): array`
- `privacidadDefault(): array`
- `normalizarPrivacidad(array $input): array`
- `actualizarPrivacidad(int $vcardId, array $input): array`
- `aplicarPrivacidad(array $datos, array $privacidad): array`
- `esVisible(array $privacidad, string $campo): bool`

## Reglas de slug

El slug se normaliza con:

- `trim`;
- minúsculas;
- espacios a guion medio;
- eliminación de caracteres fuera de `a-z`, `0-9` y `-`;
- compactación de guiones repetidos.

Reglas finales:

- longitud entre 3 y 80;
- patrón `^[a-z0-9]+(?:-[a-z0-9]+)*$`;
- único en `vcards_usuario`;
- no puede ser numérico puro;
- no puede estar reservado.

## Slugs reservados

`admin`, `login`, `logout`, `perfil`, `usuarios`, `productos`, `precios`, `inventario`, `api`, `assets`, `public`, `storage`, `qr`, `vcard`, `credencial`, `verificar`, `soporte`, `soportegr`, `dashboard`, `configuracion`, `catalogos`.

## Reglas de privacidad

Campos permitidos:

- `foto`
- `correo`
- `telefono_fijo`
- `telefono_movil`
- `puesto`
- `empresa`
- `almacen`
- `ubicacion`
- `sitio_web`
- `linkedin`
- `facebook`
- `instagram`
- `whatsapp`
- `google_maps`
- `productos`

Default seguro: todos los campos privados (`false`).

Los campos desconocidos se rechazan.

## Representación pública

La representación pública incluye siempre:

- `slug`
- `nombre`
- `titulo_publico`
- `descripcion_publica`, si existe

Solo incluye campos visibles por privacidad.

No incluye:

- `usuario_id`;
- `vcard_id`;
- `password_hash`;
- roles;
- permisos;
- `ruta_relativa` de foto;
- tokens;
- precios;
- stock;
- costos.

La foto se representa únicamente como `foto_publica_disponible: bool`.

Productos queda limitado a `productos_habilitados: bool`, sin listado funcional.

## Contacto público

La selección de canal público usa esta prioridad:

1. Canal preferido si está visible y tiene valor.
2. WhatsApp visible.
3. Teléfono móvil visible.
4. Teléfono fijo visible.
5. Correo visible.
6. `null` si no hay canal autorizado.

Nunca usa campos ocultos como fallback.

## DB-TEST

Comando:

```bash
php database/vcard-service.php db:test --database=r_erp_db_core_0_test --confirm-database=r_erp_db_core_0_test
```

El test valida:

- creación e idempotencia de vCard base;
- privacidad default segura;
- configuración de slug/título/descripción/canal;
- slug normalizado;
- slug duplicado, reservado, inválido y numérico rechazados;
- URL inválida rechazada;
- privacidad granular;
- publicación/despublicación;
- resolución pública por slug;
- usuario inactivo no resuelve públicamente;
- filtrado de campos privados;
- ausencia de IDs/hash/rutas/tokens/precio/stock/costo;
- canal de contacto solo con campos visibles;
- ausencia de rutas/controladores/vistas/assets públicos;
- ausencia de QR/VCF/credencial;
- rollback de datos QA.

## Fuera de alcance

- UI pública.
- Rutas públicas.
- QR.
- VCF.
- Credencial visual.
- Productos vCard funcionales.
- Upload de foto.
- Cambios en productos, precios o inventario.
- Migraciones.
- Seeds.

## Riesgos conocidos

- La representación pública depende de que futuras rutas/controladores no agreguen campos al payload sin pasar por `VcardService`.
- La carga real de foto debe diseñarse en una fase posterior de storage privado.

## Siguiente fase sugerida

`VCARD-PUBLICO-SEGURIDAD-1`, solo cuando se autorice, debería crear la capa pública HTTP con rutas controladas y pruebas específicas de no exposición.
