<?php

declare(strict_types=1);

$profile = is_array($profile ?? null) ? $profile : [];
$photo = is_array($photo ?? null) ? $photo : null;
$errors = is_array($errors ?? null) ? $errors : [];
$abilities = is_array($abilities ?? null) ? $abilities : [];
$notice = is_string($notice ?? null) ? $notice : null;
$vcard = is_array($vcard ?? null) ? $vcard : null;
$vcardErrors = is_array($vcardErrors ?? null) ? $vcardErrors : [];
$value = static fn (string $key): string => (string) ($profile[$key] ?? '');
$error = static fn (string $key): string => (string) ($errors[$key] ?? '');
$vcardValue = static fn (string $key): string => (string) ($vcard[$key] ?? '');
$vcardError = static fn (string $key): string => (string) ($vcardErrors[$key] ?? '');
$canEdit = ($abilities['editar'] ?? false) === true;
$canChangePassword = ($abilities['password'] ?? false) === true;
$canDeletePhoto = ($abilities['foto_eliminar'] ?? false) === true;
$canUpdatePhoto = ($abilities['foto_actualizar'] ?? false) === true;
$canViewVcard = ($abilities['vcard_ver'] ?? false) === true;
$canEditVcard = ($abilities['vcard_editar'] ?? false) === true;
$canPublishVcard = ($abilities['vcard_publicar'] ?? false) === true;
$canEditVcardPrivacy = ($abilities['vcard_privacidad'] ?? false) === true;
$canManageVcardProducts = ($abilities['vcard_productos_administrar'] ?? false) === true;
$vcardPrivacy = is_array($vcard['privacidad'] ?? null) ? $vcard['privacidad'] : [];
$vcardProducts = is_array($vcardProducts ?? null) ? $vcardProducts : [];
$vcardProductSearchResults = is_array($vcardProductSearchResults ?? null)
    ? $vcardProductSearchResults
    : [];
$vcardProductErrors = is_array($vcardProductErrors ?? null)
    ? $vcardProductErrors
    : [];
$vcardProductQuery = is_string($vcardProductQuery ?? null) ? $vcardProductQuery : '';
$vcardProductError = static fn (string $key): string =>
    (string) ($vcardProductErrors[$key] ?? '');
$publicSlug = trim($vcardValue('slug'));
$publicUrl = $publicSlug !== '' ? '/v/' . rawurlencode($publicSlug) : null;
$privacyLabels = [
    'foto' => 'Foto pública',
    'correo' => 'Correo',
    'telefono_fijo' => 'Teléfono fijo',
    'telefono_movil' => 'Teléfono móvil',
    'puesto' => 'Puesto',
    'empresa' => 'Empresa',
    'almacen' => 'Almacén',
    'ubicacion' => 'Ubicación',
    'sitio_web' => 'Sitio web',
    'linkedin' => 'LinkedIn',
    'facebook' => 'Facebook',
    'instagram' => 'Instagram',
    'whatsapp' => 'WhatsApp',
    'google_maps' => 'Google Maps',
    'productos' => 'Productos públicos',
];
?>
<section class="profile-page">
    <header class="profile-page__header">
    <div>
        <p class="eyebrow">Cuenta personal</p>

        <h1>Mi perfil</h1>

        <p>
            Administra tus datos personales, métodos de acceso,
            privacidad y configuración de la aplicación.
        </p>
    </div>

    <span class="profile-status">
        <span class="profile-status__dot" aria-hidden="true"></span>
        Sesión activa
    </span>
</header>

    <?php if ($notice !== null): ?>
        <div class="alert alert--success"><?= e($notice) ?></div>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="alert alert--danger">
            Revisa los campos marcados. No se guardaron cambios.
        </div>
    <?php endif; ?>
<section
    class="profile-security"
    aria-labelledby="profile-security-title"
>
    <div class="profile-section-heading">
        <div>
            <p class="eyebrow">Seguridad</p>

            <h2 id="profile-security-title">
                Seguridad de la cuenta
            </h2>

            <p>
                Administra tu contraseña y tus métodos seguros
                de inicio de sesión.
            </p>
        </div>
    </div>

    <div class="profile-security-grid">

        <article class="profile-security-card">
            <div class="profile-security-card__icon" aria-hidden="true">
                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <rect
                        x="4"
                        y="10"
                        width="16"
                        height="11"
                        rx="2"
                    />

                    <path d="M8 10V7a4 4 0 0 1 8 0v3"/>
                    <path d="M12 14v3"/>
                </svg>
            </div>

            <div class="profile-security-card__content">
                <strong>Contraseña</strong>

                <p>
                    Actualiza tu contraseña cuando sea necesario
                    o si sospechas que alguien más la conoce.
                </p>

                <?php if ($canChangePassword): ?>
                    <a
                        class="button button--secondary"
                        href="/perfil/password"
                    >
                        Cambiar contraseña
                    </a>
                <?php else: ?>
                    <span class="badge badge--neutral">
                        Sin permiso para modificar
                    </span>
                <?php endif; ?>
            </div>
        </article>

        <article class="profile-security-card">
            <div class="profile-security-card__icon" aria-hidden="true">
                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <circle cx="8" cy="8" r="4"/>
                    <path d="M11 11l9 9"/>
                    <path d="M15 15l2-2"/>
                    <path d="M18 18l2-2"/>
                </svg>
            </div>

            <div class="profile-security-card__content">
                <strong>Passkeys</strong>

                <p>
                    Accede sin escribir tu contraseña mediante
                    Windows Hello, PIN, rostro, huella o un
                    dispositivo compatible.
                </p>

                <a
                    class="button button--secondary"
                    href="#passkeys-title"
                >
                    Administrar passkeys
                </a>
            </div>
        </article>

        <article class="profile-security-card">
            <div class="profile-security-card__icon" aria-hidden="true">
                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <rect
                        x="3"
                        y="4"
                        width="18"
                        height="13"
                        rx="2"
                    />

                    <path d="M8 21h8"/>
                    <path d="M12 17v4"/>
                </svg>
            </div>

            <div class="profile-security-card__content">
                <strong>Sesiones activas</strong>

                <p>
                    Próximamente podrás revisar tus dispositivos,
                    última actividad y cerrar sesiones remotamente.
                </p>

                <span class="badge badge--neutral">
                    Próxima etapa
                </span>
            </div>
        </article>

    </div>
</section>

<section class="profile-passkeys">
    <?= \App\Core\View::render(
        'auth/passkey-controls',
        [
            'csrf' => $csrf,
        ]
    ) ?>
</section>

<section
    class="profile-application"
    aria-labelledby="profile-application-title"
>
    <div class="profile-section-heading">
        <div>
            <p class="eyebrow">Aplicación</p>

            <h2 id="profile-application-title">
                SoporteGR ERP
            </h2>

            <p>
                Instala el ERP como aplicación para abrirlo
                directamente desde tu equipo.
            </p>
        </div>
    </div>

    <div class="profile-app-card">

        <div class="profile-app-card__icon" aria-hidden="true">
            <svg
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.8"
                stroke-linecap="round"
                stroke-linejoin="round"
            >
                <rect
                    x="6"
                    y="2"
                    width="12"
                    height="20"
                    rx="3"
                />

                <path d="M10 18h4"/>
                <path d="M12 6v7"/>
                <path d="m9 10 3 3 3-3"/>
            </svg>
        </div>

        <div class="profile-app-card__content">
            <strong>Instalar aplicación</strong>

            <p>
                Disponible cuando tu navegador permite instalar
                SoporteGR ERP como aplicación.
            </p>

            <p
                class="profile-app-message"
                data-pwa-message
                role="status"
            ></p>
        </div>

        <button
            type="button"
            class="button button--secondary"
            data-pwa-install
            hidden
        >
            Instalar aplicación
        </button>

    </div>
</section>
    <div class="profile-grid">
        <aside class="profile-panel profile-panel--summary">
            <div class="profile-panel__heading">
                <div>
                    <h2>Cuenta</h2>
                    <p>Datos de sesión, solo lectura.</p>
                </div>
                <span class="badge badge--success">Sesión activa</span>
            </div>

            <dl class="profile-readonly-list">
                <div>
                    <dt>Usuario</dt>
                    <dd><?= e($user['username'] ?? '') ?></dd>
                </div>
                <div>
                    <dt>Email de login</dt>
                    <dd><?= e($user['email'] ?? '') ?></dd>
                </div>
                <div>
                    <dt>Estado</dt>
                    <dd>Activo para esta sesión</dd>
                </div>
            </dl>
        </aside>

        <aside class="profile-panel">
            <div class="profile-panel__heading">
                <div>
                    <h2>Foto activa</h2>
                    <p>Solo metadatos; la ruta privada no se expone.</p>
                </div>
            </div>

            <?php if ($photo === null): ?>
                <div class="profile-empty">
                    <strong>Sin foto activa</strong>
                    <p>Sube una imagen JPEG, PNG o WebP. El archivo se guarda en almacenamiento privado.</p>
                </div>
            <?php else: ?>
                <dl class="profile-readonly-list">
                    <div>
                        <dt>Archivo</dt>
                        <dd><?= e($photo['nombre_archivo'] ?? '') ?></dd>
                    </div>
                    <div>
                        <dt>MIME</dt>
                        <dd><?= e($photo['mime'] ?? '') ?></dd>
                    </div>
                    <div>
                        <dt>Tamaño</dt>
                        <dd><?= e(number_format((int) ($photo['tamano_bytes'] ?? 0))) ?> bytes</dd>
                    </div>
                </dl>

                <?php if ($canDeletePhoto): ?>
                    <form class="profile-danger-action" method="post" action="/perfil/foto/eliminar">
                        <?= csrf_field($csrf) ?>
                        <button class="button button--secondary" type="submit">
                            Eliminar foto activa
                        </button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($canUpdatePhoto): ?>
                <form class="profile-photo-form" method="post" action="/perfil/foto" enctype="multipart/form-data">
                    <?= csrf_field($csrf) ?>
                    <label class="field">
                        <span>Actualizar foto</span>
                        <input
                            type="file"
                            name="foto"
                            accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp"
                            required
                        >
                        <small>Máximo 5 MiB. No se expone la ruta privada del archivo.</small>
                        <?php if ($error('foto') !== ''): ?><small><?= e($error('foto')) ?></small><?php endif; ?>
                    </label>
                    <button class="button" type="submit">Guardar foto</button>
                </form>
            <?php endif; ?>
        </aside>
    </div>

    <?php if ($canViewVcard && $vcard !== null): ?>
        <div class="profile-grid">
            <aside class="profile-panel">
                <div class="profile-panel__heading">
                    <div>
                        <h2>vCard pública</h2>
                        <p>Controla el slug, presentación y estado público.</p>
                    </div>
                    <span class="badge <?= (int) ($vcard['publicada'] ?? 0) === 1 ? 'badge--success' : 'badge--neutral' ?>">
                        <?= (int) ($vcard['publicada'] ?? 0) === 1 ? 'Publicada' : 'No publicada' ?>
                    </span>
                </div>

                <dl class="profile-readonly-list">
                    <div>
                        <dt>Ruta pública</dt>
                        <dd>
                            <?php if ((int) ($vcard['publicada'] ?? 0) === 1 && $publicUrl !== null): ?>
                                <a href="<?= e($publicUrl) ?>" target="_blank" rel="noopener noreferrer">
                                    <?= e($publicUrl) ?>
                                </a>
                            <?php else: ?>
                                Disponible después de publicar.
                            <?php endif; ?>
                        </dd>
                    </div>
                </dl>

                <?php if ($canPublishVcard): ?>
                    <form class="profile-danger-action" method="post" action="<?= (int) ($vcard['publicada'] ?? 0) === 1 ? '/perfil/vcard/despublicar' : '/perfil/vcard/publicar' ?>">
                        <?= csrf_field($csrf) ?>
                        <button class="button" type="submit">
                            <?= (int) ($vcard['publicada'] ?? 0) === 1 ? 'Despublicar vCard' : 'Publicar vCard' ?>
                        </button>
                    </form>
                <?php endif; ?>
            </aside>

            <form class="profile-form" method="post" action="/perfil/vcard/configuracion">
                <?= csrf_field($csrf) ?>
                <fieldset>
                    <legend>Configuración pública</legend>
                    <label class="field">
                        <span>Slug público</span>
                        <input name="slug" maxlength="80" value="<?= e($vcardValue('slug')) ?>" <?= $canEditVcard ? '' : 'readonly' ?>>
                        <?php if ($vcardError('slug') !== ''): ?><small><?= e($vcardError('slug')) ?></small><?php endif; ?>
                    </label>
                    <label class="field">
                        <span>Canal preferido</span>
                        <select name="canal_contacto_preferido" <?= $canEditVcard ? '' : 'disabled' ?>>
                            <?php foreach ([
                                'ninguno' => 'Sin preferencia',
                                'whatsapp' => 'WhatsApp',
                                'telefono_movil' => 'Teléfono móvil',
                                'telefono_fijo' => 'Teléfono fijo',
                                'correo' => 'Correo',
                            ] as $channel => $label): ?>
                                <option value="<?= e($channel) ?>" <?= $vcardValue('canal_contacto_preferido') === $channel ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($vcardError('canal_contacto_preferido') !== ''): ?><small><?= e($vcardError('canal_contacto_preferido')) ?></small><?php endif; ?>
                    </label>
                    <label class="field field--wide">
                        <span>Título público</span>
                        <input name="titulo_publico" maxlength="160" value="<?= e($vcardValue('titulo_publico')) ?>" <?= $canEditVcard ? '' : 'readonly' ?>>
                        <?php if ($vcardError('titulo_publico') !== ''): ?><small><?= e($vcardError('titulo_publico')) ?></small><?php endif; ?>
                    </label>
                    <label class="field field--wide">
                        <span>Descripción pública</span>
                        <textarea name="descripcion_publica" maxlength="2000" rows="4" <?= $canEditVcard ? '' : 'readonly' ?>><?= e($vcardValue('descripcion_publica')) ?></textarea>
                        <?php if ($vcardError('descripcion_publica') !== ''): ?><small><?= e($vcardError('descripcion_publica')) ?></small><?php endif; ?>
                    </label>
                </fieldset>
                <div class="form-actions">
                    <?php if ($canEditVcard): ?>
                        <button class="button" type="submit">Guardar vCard</button>
                    <?php else: ?>
                        <span class="badge badge--neutral">Sin permiso de edición vCard</span>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <form class="profile-form" method="post" action="/perfil/vcard/privacidad">
            <?= csrf_field($csrf) ?>
            <fieldset>
                <legend>Privacidad pública</legend>
                <?php foreach ($privacyLabels as $field => $label): ?>
                    <label class="field">
                        <span><?= e($label) ?></span>
                        <input type="hidden" name="<?= e($field) ?>" value="0">
                        <label>
                            <input
                                type="checkbox"
                                name="<?= e($field) ?>"
                                value="1"
                                <?= ($vcardPrivacy[$field] ?? false) === true ? 'checked' : '' ?>
                                <?= $canEditVcardPrivacy ? '' : 'disabled' ?>
                            >
                            Visible en vCard pública
                        </label>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <div class="form-actions">
                <?php if ($canEditVcardPrivacy): ?>
                    <button class="button" type="submit">Guardar privacidad</button>
                <?php else: ?>
                    <span class="badge badge--neutral">Sin permiso de privacidad vCard</span>
                <?php endif; ?>
            </div>
        </form>

        <?php if ($canManageVcardProducts): ?>
            <section class="profile-panel profile-panel--wide" aria-labelledby="profile-vcard-products-title">
                <div class="profile-panel__heading">
                    <div>
                        <h2 id="profile-vcard-products-title">Productos en mi vCard</h2>
                        <p>
                            Selecciona productos activos para mostrarlos en tu vCard pública.
                            No se publican precios, stock, costos, proveedor ni almacén.
                        </p>
                    </div>
                    <span class="badge badge--neutral"><?= e((string) count($vcardProducts)) ?> vinculados</span>
                </div>

                <?php if ($vcardProductErrors !== []): ?>
                    <div class="alert alert--danger">
                        Revisa el producto seleccionado. No se guardaron cambios.
                    </div>
                <?php endif; ?>

                <form class="profile-search-form" method="get" action="/perfil">
                    <label class="field field--wide">
                        <span>Buscar producto activo</span>
                        <input
                            name="producto"
                            maxlength="80"
                            value="<?= e($vcardProductQuery) ?>"
                            placeholder="ID o descripción"
                        >
                        <?php if ($vcardProductError('id_producto') !== ''): ?>
                            <small><?= e($vcardProductError('id_producto')) ?></small>
                        <?php endif; ?>
                    </label>
                    <button class="button button--secondary" type="submit">Buscar</button>
                </form>

                <?php if ($vcardProductQuery !== '' && $vcardProductSearchResults === []): ?>
                    <div class="profile-empty">
                        <strong>Sin resultados</strong>
                        <p>No se encontraron productos activos para agregar.</p>
                    </div>
                <?php endif; ?>

                <?php if ($vcardProductSearchResults !== []): ?>
                    <div class="profile-product-results" aria-label="Resultados de productos activos">
                        <?php foreach ($vcardProductSearchResults as $product): ?>
                            <form class="profile-product-result" method="post" action="/perfil/vcard/productos/agregar">
                                <?= csrf_field($csrf) ?>
                                <input type="hidden" name="id_producto" value="<?= e((string) $product['id_producto']) ?>">
                                <div>
                                    <strong><?= e((string) $product['descripcion']) ?></strong>
                                    <small><?= e((string) $product['id_producto']) ?></small>
                                </div>
                                <button class="button" type="submit">Agregar</button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($vcardProducts === []): ?>
                    <div class="profile-empty">
                        <strong>Aún no has agregado productos a tu vCard.</strong>
                        <p>Usa el buscador para vincular productos activos.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data-table profile-product-table">
                            <thead>
                                <tr>
                                    <th>Producto</th>
                                    <th>Visible</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($vcardProducts as $product): ?>
                                    <?php $productFormId = 'vcard-product-' . preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $product['id_producto']); ?>
                                    <tr>
                                        <td>
                                            <strong><?= e((string) $product['descripcion']) ?></strong>
                                            <small><?= e((string) $product['id_producto']) ?></small>
                                        </td>
                                        <td>
                                            <input form="<?= e($productFormId) ?>" type="hidden" name="activo" value="0">
                                            <label>
                                                <input
                                                    form="<?= e($productFormId) ?>"
                                                    type="checkbox"
                                                    name="activo"
                                                    value="1"
                                                    <?= (int) ($product['activo'] ?? 0) === 1 ? 'checked' : '' ?>
                                                >
                                                Visible
                                            </label>
                                        </td>
                                        <td class="table-actions">
                                            <form id="<?= e($productFormId) ?>" method="post" action="/perfil/vcard/productos/actualizar">
                                                <?= csrf_field($csrf) ?>
                                                <input type="hidden" name="id_producto" value="<?= e((string) $product['id_producto']) ?>">
                                                <button class="button button--secondary" type="submit">Actualizar</button>
                                            </form>
                                            <form method="post" action="/perfil/vcard/productos/quitar">
                                                <?= csrf_field($csrf) ?>
                                                <input type="hidden" name="id_producto" value="<?= e((string) $product['id_producto']) ?>">
                                                <button class="button button--secondary" type="submit">Quitar</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    <?php endif; ?>

    <form class="profile-form" method="post" action="/perfil/actualizar">
        <?= csrf_field($csrf) ?>

        <fieldset>
            <legend>Datos personales</legend>
            <label class="field">
                <span>Primer nombre</span>
                <input name="primer_nombre" maxlength="80" value="<?= e($value('primer_nombre')) ?>" <?= $canEdit ? '' : 'readonly' ?>>
                <?php if ($error('primer_nombre') !== ''): ?><small><?= e($error('primer_nombre')) ?></small><?php endif; ?>
            </label>
            <label class="field">
                <span>Segundo nombre</span>
                <input name="segundo_nombre" maxlength="80" value="<?= e($value('segundo_nombre')) ?>" <?= $canEdit ? '' : 'readonly' ?>>
                <?php if ($error('segundo_nombre') !== ''): ?><small><?= e($error('segundo_nombre')) ?></small><?php endif; ?>
            </label>
            <label class="field">
                <span>Apellido paterno</span>
                <input name="apellido_paterno" maxlength="80" value="<?= e($value('apellido_paterno')) ?>" <?= $canEdit ? '' : 'readonly' ?>>
                <?php if ($error('apellido_paterno') !== ''): ?><small><?= e($error('apellido_paterno')) ?></small><?php endif; ?>
            </label>
            <label class="field">
                <span>Apellido materno</span>
                <input name="apellido_materno" maxlength="80" value="<?= e($value('apellido_materno')) ?>" <?= $canEdit ? '' : 'readonly' ?>>
                <?php if ($error('apellido_materno') !== ''): ?><small><?= e($error('apellido_materno')) ?></small><?php endif; ?>
            </label>
            <label class="field field--wide">
                <span>Puesto</span>
                <input name="puesto" maxlength="120" value="<?= e($value('puesto')) ?>" <?= $canEdit ? '' : 'readonly' ?>>
                <?php if ($error('puesto') !== ''): ?><small><?= e($error('puesto')) ?></small><?php endif; ?>
            </label>
        </fieldset>

        <fieldset>
            <legend>Contacto</legend>
            <?php foreach ([
                'telefono_fijo' => 'Teléfono fijo',
                'telefono_movil' => 'Teléfono móvil',
                'whatsapp' => 'WhatsApp',
                'sitio_web' => 'Sitio web',
                'linkedin_url' => 'LinkedIn',
                'facebook_url' => 'Facebook',
                'instagram_url' => 'Instagram',
                'google_maps_url' => 'Google Maps',
            ] as $field => $label): ?>
                <label class="field<?= $field === 'google_maps_url' ? ' field--wide' : '' ?>">
                    <span><?= e($label) ?></span>
                    <input
                        name="<?= e($field) ?>"
                        maxlength="<?= in_array($field, ['telefono_fijo', 'telefono_movil', 'whatsapp'], true) ? '40' : ($field === 'google_maps_url' ? '500' : '255') ?>"
                        value="<?= e($value($field)) ?>"
                        <?= $canEdit ? '' : 'readonly' ?>
                    >
                    <?php if ($error($field) !== ''): ?><small><?= e($error($field)) ?></small><?php endif; ?>
                </label>
            <?php endforeach; ?>
            <label class="field field--wide">
                <span>Ubicación pública</span>
                <input name="ubicacion_publica" maxlength="255" value="<?= e($value('ubicacion_publica')) ?>" <?= $canEdit ? '' : 'readonly' ?>>
                <?php if ($error('ubicacion_publica') !== ''): ?><small><?= e($error('ubicacion_publica')) ?></small><?php endif; ?>
            </label>
        </fieldset>

        <div class="form-actions">
            <?php if ($canEdit): ?>
                <button class="button" type="submit">Guardar cambios</button>
                <a class="button button--secondary" href="/perfil">Cancelar</a>
            <?php else: ?>
                <span class="badge badge--neutral">Sin permiso de edición</span>
            <?php endif; ?>
        </div>
    </form>
</section>
