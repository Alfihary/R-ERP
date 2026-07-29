<?php

declare(strict_types=1);

$profile = is_array($profile ?? null) ? $profile : [];
$photo = is_array($photo ?? null) ? $photo : null;
$errors = is_array($errors ?? null) ? $errors : [];
$abilities = is_array($abilities ?? null) ? $abilities : [];
$notice = is_string($notice ?? null) ? $notice : null;
$value = static fn (string $key): string => (string) ($profile[$key] ?? '');
$error = static fn (string $key): string => (string) ($errors[$key] ?? '');
$canEdit = ($abilities['editar'] ?? false) === true;
$canChangePassword = ($abilities['password'] ?? false) === true;
$canDeletePhoto = ($abilities['foto_eliminar'] ?? false) === true;
?>
<section class="profile-page">
    <header class="profile-page__header">
        <div>
            <p class="eyebrow">Cuenta personal</p>
            <h1>Mi perfil</h1>
            <p>
                Mantén tus datos de contacto operativos. El usuario de acceso,
                correo de login, roles y permisos se administran fuera de esta pantalla.
            </p>
        </div>
        <?php if ($canChangePassword): ?>
            <a class="button button--secondary" href="/perfil/password">
                Cambiar contraseña
            </a>
        <?php endif; ?>
    </header>

    <?php if ($notice !== null): ?>
        <div class="alert alert--success"><?= e($notice) ?></div>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="alert alert--danger">
            Revisa los campos marcados. No se guardaron cambios.
        </div>
    <?php endif; ?>

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
                    <p>El upload físico queda reservado para una fase posterior.</p>
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
                    <div>
                        <dt>Registrada</dt>
                        <dd><?= e($photo['creado_en'] ?? '') ?></dd>
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
        </aside>
    </div>

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
