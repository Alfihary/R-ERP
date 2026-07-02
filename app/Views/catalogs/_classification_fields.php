<?php

declare(strict_types=1);

if (
    !is_string($fieldPrefix ?? null)
    || !is_array($values ?? null)
    || !is_array($fieldErrors ?? null)
    || !is_array($parentOptions ?? null)
) {
    throw new RuntimeException('Classification field data is incomplete.');
}

$value = static function (string $key, string $default = '') use ($values): string {
    $current = $values[$key] ?? $default;

    return is_scalar($current) ? (string) $current : $default;
};
$selectedParent = $value('parent_id');
?>
<div class="catalog-field">
    <label for="<?= e($fieldPrefix) ?>-codigo">Código</label>
    <input
        id="<?= e($fieldPrefix) ?>-codigo"
        name="codigo"
        type="text"
        value="<?= e($value('codigo')) ?>"
        maxlength="64"
        autocomplete="off"
        required
        aria-describedby="<?= e($fieldPrefix) ?>-codigo-help"
    >
    <small id="<?= e($fieldPrefix) ?>-codigo-help">
        Letras, números, guion o guion bajo; se guardará en mayúsculas.
    </small>
    <?php if (isset($fieldErrors['codigo'])): ?>
        <span class="catalog-field-error"><?= e($fieldErrors['codigo']) ?></span>
    <?php endif; ?>
</div>

<div class="catalog-field">
    <label for="<?= e($fieldPrefix) ?>-nombre">Nombre</label>
    <input
        id="<?= e($fieldPrefix) ?>-nombre"
        name="nombre"
        type="text"
        value="<?= e($value('nombre')) ?>"
        maxlength="150"
        required
    >
    <?php if (isset($fieldErrors['nombre'])): ?>
        <span class="catalog-field-error"><?= e($fieldErrors['nombre']) ?></span>
    <?php endif; ?>
</div>

<div class="catalog-field">
    <label for="<?= e($fieldPrefix) ?>-parent">Clasificación padre</label>
    <select
        id="<?= e($fieldPrefix) ?>-parent"
        name="parent_id"
        aria-describedby="<?= e($fieldPrefix) ?>-parent-help"
    >
        <option value="">Sin padre (clasificación raíz)</option>
        <?php foreach ($parentOptions as $option): ?>
            <?php
            $optionId = (string) ($option['id'] ?? '');
            $optionLabel = (string) ($option['path'] ?? '');

            if ((int) ($option['activo'] ?? 0) !== 1) {
                $optionLabel .= ' — Inactiva';
            }
            ?>
            <option
                value="<?= e($optionId) ?>"
                <?= $selectedParent === $optionId ? 'selected' : '' ?>
            >
                <?= e($optionLabel) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <small id="<?= e($fieldPrefix) ?>-parent-help">
        Deja el campo vacío para crear una raíz.
    </small>
    <?php if (isset($fieldErrors['parent_id'])): ?>
        <span class="catalog-field-error"><?= e($fieldErrors['parent_id']) ?></span>
    <?php endif; ?>
</div>
