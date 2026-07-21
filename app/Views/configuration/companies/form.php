<?php

declare(strict_types=1);

$values = is_array($values ?? null) ? $values : [];
$errors = is_array($errors ?? null) ? $errors : [];
$editing = ($editing ?? false) === true;
$action = $editing
    ? '/configuracion/empresas/actualizar'
    : '/configuracion/empresas';

$value = static fn (string $key): string => (string) ($values[$key] ?? '');
$error = static fn (string $key): string => (string) ($errors[$key] ?? '');
?>
<section class="config-page">
    <header class="config-page__header">
        <div>
            <p class="eyebrow">Configuración operativa</p>
            <h1><?= $editing ? 'Editar empresa' : 'Crear empresa' ?></h1>
            <p>Datos estables para operación real, contexto y preparación de folios por almacén.</p>
        </div>
        <a class="button button--secondary" href="/configuracion/empresas">Volver</a>
    </header>

    <?php if ($errors !== []): ?>
        <div class="alert alert--danger">Corrige los campos marcados.</div>
    <?php endif; ?>

    <form class="config-form" method="post" action="<?= e($action) ?>">
        <?= csrf_field($csrf) ?>
        <?php if ($editing): ?>
            <input type="hidden" name="id" value="<?= e($value('id')) ?>">
        <?php endif; ?>

        <fieldset>
            <legend>Identidad</legend>
            <label class="field">
                <span>Nombre *</span>
                <input name="nombre" required maxlength="150" value="<?= e($value('nombre')) ?>">
                <?php if ($error('nombre') !== ''): ?><small><?= e($error('nombre')) ?></small><?php endif; ?>
            </label>
            <label class="field">
                <span>Código *</span>
                <input name="codigo" required maxlength="64" value="<?= e($value('codigo')) ?>">
                <small><?= e($error('codigo') ?: 'Usa de 2 a 64 caracteres: minúsculas, números y separadores . _ -. Los espacios se normalizan a guion. Ejemplo: gr o grupo-refrigerantes.') ?></small>
            </label>
            <label class="field">
                <span>Razón social</span>
                <input name="razon_social" maxlength="180" value="<?= e($value('razon_social')) ?>">
            </label>
            <label class="field">
                <span>Nombre comercial</span>
                <input name="nombre_comercial" maxlength="180" value="<?= e($value('nombre_comercial')) ?>">
            </label>
            <label class="field">
                <span>RFC</span>
                <input name="rfc" maxlength="13" value="<?= e($value('rfc')) ?>">
                <?php if ($error('rfc') !== ''): ?><small><?= e($error('rfc')) ?></small><?php endif; ?>
            </label>
            <label class="field">
                <span>Régimen fiscal</span>
                <input name="regimen_fiscal" maxlength="10" value="<?= e($value('regimen_fiscal')) ?>">
            </label>
        </fieldset>

        <fieldset>
            <legend>Contacto</legend>
            <label class="field">
                <span>Teléfono</span>
                <input name="telefono" maxlength="30" value="<?= e($value('telefono')) ?>">
            </label>
            <label class="field">
                <span>Email</span>
                <input type="email" name="email" maxlength="120" value="<?= e($value('email')) ?>">
                <?php if ($error('email') !== ''): ?><small><?= e($error('email')) ?></small><?php endif; ?>
            </label>
            <label class="field">
                <span>Sitio web</span>
                <input name="sitio_web" maxlength="160" value="<?= e($value('sitio_web')) ?>">
            </label>
        </fieldset>

        <fieldset>
            <legend>Dirección básica</legend>
            <?php foreach ([
                'pais' => 'País',
                'estado' => 'Estado',
                'municipio' => 'Municipio',
                'colonia' => 'Colonia',
                'calle' => 'Calle',
                'numero_exterior' => 'Número exterior',
                'numero_interior' => 'Número interior',
                'codigo_postal' => 'Código postal',
            ] as $key => $label): ?>
                <label class="field">
                    <span><?= e($label) ?></span>
                    <input name="<?= e($key) ?>" maxlength="160" value="<?= e($value($key)) ?>">
                    <?php if ($error($key) !== ''): ?><small><?= e($error($key)) ?></small><?php endif; ?>
                </label>
            <?php endforeach; ?>
        </fieldset>

        <fieldset>
            <legend>Presentación futura</legend>
            <label class="field">
                <span>Logo path</span>
                <input name="logo_path" maxlength="255" value="<?= e($value('logo_path')) ?>">
            </label>
            <label class="field">
                <span>Color primario</span>
                <input name="color_primario" maxlength="20" value="<?= e($value('color_primario')) ?>" placeholder="#1A2B3C">
                <?php if ($error('color_primario') !== ''): ?><small><?= e($error('color_primario')) ?></small><?php endif; ?>
            </label>
        </fieldset>

        <div class="form-actions">
            <button class="button" type="submit"><?= $editing ? 'Guardar cambios' : 'Crear empresa' ?></button>
            <a class="button button--secondary" href="/configuracion/empresas">Cancelar</a>
        </div>
    </form>
</section>
