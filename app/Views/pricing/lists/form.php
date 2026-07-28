<?php

declare(strict_types=1);

$values = is_array($values ?? null) ? $values : [];
$errors = is_array($errors ?? null) ? $errors : [];
$editing = ($editing ?? false) === true;
$action = $editing
    ? '/configuracion/listas-precios/actualizar'
    : '/configuracion/listas-precios';
$value = static fn (string $key): string => (string) ($values[$key] ?? '');
$error = static fn (string $key): string => (string) ($errors[$key] ?? '');
$active = (string) ($values['activo'] ?? '1') === '1';
$taxes = (string) ($values['incluye_impuestos'] ?? '0') === '1';
$default = (string) ($values['es_predeterminada'] ?? '0') === '1';
?>
<section class="price-list-page">
    <header class="price-list-page__header">
        <div>
            <p class="eyebrow">Configuración de precios</p>
            <h1><?= $editing ? 'Editar lista de precios' : 'Crear lista de precios' ?></h1>
            <p>Las listas son globales y no pertenecen a empresa ni almacén.</p>
        </div>
        <a class="button button--secondary" href="/configuracion/listas-precios">Volver</a>
    </header>

    <?php if ($errors !== []): ?>
        <div class="alert alert--danger"><?= e(implode(' ', array_values($errors))) ?></div>
    <?php endif; ?>

    <form class="price-list-form" method="post" action="<?= e($action) ?>">
        <?= csrf_field($csrf) ?>
        <?php if ($editing): ?>
            <input type="hidden" name="id" value="<?= e($value('id')) ?>">
        <?php endif; ?>

        <fieldset>
            <legend>Datos generales</legend>
            <label class="field">
                <span>Clave *</span>
                <input name="clave" required maxlength="32" value="<?= e($value('clave')) ?>" placeholder="PUBLICO">
                <small><?= e($error('clave') ?: 'Se normaliza a mayúsculas. Usa letras, números y separadores . _ -.') ?></small>
            </label>
            <label class="field">
                <span>Nombre *</span>
                <input name="nombre" required maxlength="100" value="<?= e($value('nombre')) ?>" placeholder="Público">
                <?php if ($error('nombre') !== ''): ?><small><?= e($error('nombre')) ?></small><?php endif; ?>
            </label>
            <label class="field field--wide">
                <span>Observaciones</span>
                <textarea name="observaciones" maxlength="500" rows="4"><?= e($value('observaciones')) ?></textarea>
                <small><?= e($error('observaciones') ?: 'Opcional. No captura precios de productos.') ?></small>
            </label>
        </fieldset>

        <fieldset>
            <legend>Comportamiento</legend>
            <label class="check-field">
                <input type="checkbox" name="incluye_impuestos" value="1" <?= $taxes ? 'checked' : '' ?>>
                <span>Incluye impuestos</span>
            </label>
            <label class="check-field">
                <input type="checkbox" name="activo" value="1" <?= $active ? 'checked' : '' ?>>
                <span>Activa</span>
            </label>
            <label class="check-field">
                <input type="checkbox" name="es_predeterminada" value="1" <?= $default ? 'checked' : '' ?>>
                <span>Predeterminada</span>
            </label>
            <?php if ($error('predeterminada') !== ''): ?>
                <p class="form-note"><?= e($error('predeterminada')) ?></p>
            <?php endif; ?>
        </fieldset>

        <div class="form-actions">
            <button class="button" type="submit"><?= $editing ? 'Guardar cambios' : 'Crear lista' ?></button>
            <a class="button button--secondary" href="/configuracion/listas-precios">Cancelar</a>
        </div>
    </form>
</section>
