<?php

declare(strict_types=1);

$values = is_array($values ?? null) ? $values : [];
$errors = is_array($errors ?? null) ? $errors : [];
$lists = is_array($lists ?? null) ? $lists : [];
$editing = ($editing ?? false) === true;
$action = $editing ? '/precios/productos/actualizar' : '/precios/productos';
$value = static fn (string $key): string => (string) ($values[$key] ?? '');
$error = static fn (string $key): string => (string) ($errors[$key] ?? '');
?>
<section class="product-price-page">
    <header class="product-price-page__header">
        <div>
            <p class="eyebrow">Precios</p>
            <h1><?= $editing ? 'Editar precio de producto' : 'Crear precio de producto' ?></h1>
            <p>La moneda se toma del producto y los impuestos se toman de la lista.</p>
        </div>
        <a class="button button--secondary" href="/precios/productos">Volver</a>
    </header>

    <?php if ($errors !== []): ?>
        <div class="alert alert--danger"><?= e(implode(' ', array_values($errors))) ?></div>
    <?php endif; ?>

    <form class="product-price-form" method="post" action="<?= e($action) ?>">
        <?= csrf_field($csrf) ?>
        <?php if ($editing): ?>
            <input type="hidden" name="id" value="<?= e($value('id')) ?>">
        <?php endif; ?>

        <fieldset>
            <legend>Referencia</legend>
            <?php if ($editing): ?>
                <div class="readonly-field">
                    <span>Producto</span>
                    <strong><?= e($value('id_producto')) ?></strong>
                    <small><?= e($value('producto_descripcion')) ?></small>
                </div>
                <div class="readonly-field">
                    <span>Lista</span>
                    <strong><?= e($value('lista_clave')) ?></strong>
                    <small><?= e($value('lista_nombre')) ?></small>
                </div>
                <div class="readonly-field">
                    <span>Moneda</span>
                    <strong><?= e($value('moneda_codigo')) ?></strong>
                </div>
                <div class="readonly-field">
                    <span>Estado</span>
                    <strong><?= (int) ($values['activo'] ?? 0) === 1 ? 'Activo' : 'Inactivo' ?></strong>
                    <small><?= (int) ($values['requiere_revision'] ?? 0) === 1 ? 'En revisión' : 'Sin revisión' ?></small>
                </div>
            <?php else: ?>
                <label class="field">
                    <span>ID de producto *</span>
                    <input name="id_producto" required maxlength="16" value="<?= e($value('id_producto')) ?>" placeholder="ABC123">
                    <small><?= e($error('id_producto') ?: 'Usa el ID del producto. Debe tener moneda asignada.') ?></small>
                </label>
                <label class="field">
                    <span>Lista de precios *</span>
                    <select name="lista_precio_id" required>
                        <option value="">Selecciona lista</option>
                        <?php foreach ($lists as $list): ?>
                            <option value="<?= e($list['id'] ?? '') ?>" <?= (int) ($value('lista_precio_id') ?: 0) === (int) ($list['id'] ?? 0) ? 'selected' : '' ?>>
                                <?= e($list['clave'] ?? '') ?> · <?= e($list['nombre'] ?? '') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($error('lista_precio_id') !== ''): ?><small><?= e($error('lista_precio_id')) ?></small><?php endif; ?>
                </label>
            <?php endif; ?>
        </fieldset>

        <fieldset>
            <legend>Importes</legend>
            <label class="field">
                <span>Precio lista *</span>
                <input name="precio_lista" inputmode="decimal" required value="<?= e($value('precio_lista')) ?>" placeholder="0.0000">
                <?php if ($error('precio_lista') !== ''): ?><small><?= e($error('precio_lista')) ?></small><?php endif; ?>
            </label>
            <label class="field">
                <span>Precio mínimo *</span>
                <input name="precio_minimo" inputmode="decimal" required value="<?= e($value('precio_minimo')) ?>" placeholder="0.0000">
                <?php if ($error('precio_minimo') !== ''): ?><small><?= e($error('precio_minimo')) ?></small><?php endif; ?>
            </label>
            <label class="field field--wide">
                <span>Motivo de cambio *</span>
                <textarea name="motivo_cambio" required maxlength="500" rows="4"><?= e($value('motivo_cambio')) ?></textarea>
                <small><?= e($error('motivo_cambio') ?: 'Queda registrado en historial.') ?></small>
            </label>
        </fieldset>

        <div class="form-actions">
            <button class="button" type="submit"><?= $editing ? 'Guardar precio' : 'Crear precio' ?></button>
            <a class="button button--secondary" href="/precios/productos">Cancelar</a>
        </div>
    </form>
</section>
