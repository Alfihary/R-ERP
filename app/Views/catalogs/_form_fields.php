<?php

declare(strict_types=1);

if (!is_string($catalog ?? null)
    || !is_string($fieldPrefix ?? null)
    || !is_array($values ?? null)
    || !is_array($fieldErrors ?? null)
) {
    throw new RuntimeException('Catalog form field data is incomplete.');
}

$value = static function (string $key, string $default = '') use ($values): string {
    $candidate = $values[$key] ?? $default;

    return is_scalar($candidate) ? (string) $candidate : $default;
};
?>
<div class="catalog-field">
    <label for="<?= e($fieldPrefix) ?>-codigo">Código</label>
    <input
        id="<?= e($fieldPrefix) ?>-codigo"
        name="codigo"
        value="<?= e($value('codigo')) ?>"
        maxlength="<?= $catalog === 'monedas' ? '3' : '64' ?>"
        autocomplete="off"
        required
        aria-describedby="<?= e($fieldPrefix) ?>-codigo-help"
    >
    <small id="<?= e($fieldPrefix) ?>-codigo-help">
        <?= $catalog === 'monedas'
            ? 'Tres letras; se guardará en mayúsculas.'
            : 'Se guardará en mayúsculas, sin espacios.' ?>
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
        value="<?= e($value('nombre')) ?>"
        maxlength="150"
        required
    >
    <?php if (isset($fieldErrors['nombre'])): ?>
        <span class="catalog-field-error"><?= e($fieldErrors['nombre']) ?></span>
    <?php endif; ?>
</div>

<?php if ($catalog === 'monedas'): ?>
    <div class="catalog-field">
        <label for="<?= e($fieldPrefix) ?>-simbolo">Símbolo</label>
        <input
            id="<?= e($fieldPrefix) ?>-simbolo"
            name="simbolo"
            value="<?= e($value('simbolo')) ?>"
            maxlength="10"
            required
        >
        <?php if (isset($fieldErrors['simbolo'])): ?>
            <span class="catalog-field-error"><?= e($fieldErrors['simbolo']) ?></span>
        <?php endif; ?>
    </div>

    <div class="catalog-field">
        <label for="<?= e($fieldPrefix) ?>-decimales">Decimales</label>
        <input
            id="<?= e($fieldPrefix) ?>-decimales"
            name="decimales"
            type="number"
            min="0"
            max="6"
            step="1"
            value="<?= e($value('decimales', '2')) ?>"
            required
        >
        <?php if (isset($fieldErrors['decimales'])): ?>
            <span class="catalog-field-error"><?= e($fieldErrors['decimales']) ?></span>
        <?php endif; ?>
    </div>

    <label class="catalog-checkbox">
        <input
            name="es_base"
            type="checkbox"
            value="1"
            <?= $value('es_base', '0') === '1' ? 'checked' : '' ?>
        >
        Moneda base
    </label>
    <?php if (isset($fieldErrors['es_base'])): ?>
        <span class="catalog-field-error"><?= e($fieldErrors['es_base']) ?></span>
    <?php endif; ?>
<?php elseif ($catalog === 'unidades'): ?>
    <div class="catalog-field">
        <label for="<?= e($fieldPrefix) ?>-abreviatura">Abreviatura</label>
        <input
            id="<?= e($fieldPrefix) ?>-abreviatura"
            name="abreviatura"
            value="<?= e($value('abreviatura')) ?>"
            maxlength="20"
            required
        >
        <?php if (isset($fieldErrors['abreviatura'])): ?>
            <span class="catalog-field-error"><?= e($fieldErrors['abreviatura']) ?></span>
        <?php endif; ?>
    </div>
<?php elseif ($catalog === 'impuestos'): ?>
    <div class="catalog-field">
        <label for="<?= e($fieldPrefix) ?>-tasa">Tasa (%)</label>
        <input
            id="<?= e($fieldPrefix) ?>-tasa"
            name="tasa"
            type="number"
            min="0"
            max="100"
            step="0.0001"
            value="<?= e($value('tasa', '0')) ?>"
            required
        >
        <?php if (isset($fieldErrors['tasa'])): ?>
            <span class="catalog-field-error"><?= e($fieldErrors['tasa']) ?></span>
        <?php endif; ?>
    </div>

    <div class="catalog-field">
        <label for="<?= e($fieldPrefix) ?>-tipo">Tipo</label>
        <select id="<?= e($fieldPrefix) ?>-tipo" name="tipo" required>
            <?php foreach (['IVA', 'IEPS', 'EXENTO'] as $type): ?>
                <option
                    value="<?= e($type) ?>"
                    <?= $value('tipo', 'IVA') === $type ? 'selected' : '' ?>
                >
                    <?= e($type) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if (isset($fieldErrors['tipo'])): ?>
            <span class="catalog-field-error"><?= e($fieldErrors['tipo']) ?></span>
        <?php endif; ?>
    </div>
<?php endif; ?>
