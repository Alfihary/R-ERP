<?php

declare(strict_types=1);

if (
    !is_string($fieldPrefix ?? null)
    || !is_array($values ?? null)
    || !is_array($fieldErrors ?? null)
    || !is_array($currencies ?? null)
) {
    throw new RuntimeException('Exchange rate field data is incomplete.');
}

$value = static function (string $key, string $default = '') use ($values): string {
    $current = $values[$key] ?? $default;

    return is_scalar($current) ? (string) $current : $default;
};
$originId = $value('moneda_origen_id');
$destinationId = $value('moneda_destino_id');
?>
<div class="catalog-field">
    <label for="<?= e($fieldPrefix) ?>-origin">Moneda origen</label>
    <select
        id="<?= e($fieldPrefix) ?>-origin"
        name="moneda_origen_id"
        required
    >
        <option value="">Selecciona una moneda</option>
        <?php foreach ($currencies as $currency): ?>
            <?php $currencyId = (string) ($currency['id'] ?? ''); ?>
            <option
                value="<?= e($currencyId) ?>"
                <?= $originId === $currencyId ? 'selected' : '' ?>
            >
                <?= e((string) ($currency['codigo'] ?? '')) ?>
                — <?= e((string) ($currency['nombre'] ?? '')) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?php if (isset($fieldErrors['moneda_origen_id'])): ?>
        <span class="catalog-field-error">
            <?= e($fieldErrors['moneda_origen_id']) ?>
        </span>
    <?php endif; ?>
</div>

<div class="catalog-field">
    <label for="<?= e($fieldPrefix) ?>-destination">Moneda destino</label>
    <select
        id="<?= e($fieldPrefix) ?>-destination"
        name="moneda_destino_id"
        required
        aria-describedby="<?= e($fieldPrefix) ?>-destination-help"
    >
        <option value="">Selecciona una moneda</option>
        <?php foreach ($currencies as $currency): ?>
            <?php $currencyId = (string) ($currency['id'] ?? ''); ?>
            <option
                value="<?= e($currencyId) ?>"
                <?= $destinationId === $currencyId ? 'selected' : '' ?>
            >
                <?= e((string) ($currency['codigo'] ?? '')) ?>
                — <?= e((string) ($currency['nombre'] ?? '')) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <small id="<?= e($fieldPrefix) ?>-destination-help">
        Debe ser diferente de la moneda origen.
    </small>
    <?php if (isset($fieldErrors['moneda_destino_id'])): ?>
        <span class="catalog-field-error">
            <?= e($fieldErrors['moneda_destino_id']) ?>
        </span>
    <?php endif; ?>
</div>

<div class="catalog-field">
    <label for="<?= e($fieldPrefix) ?>-date">Fecha</label>
    <input
        id="<?= e($fieldPrefix) ?>-date"
        name="fecha"
        type="date"
        value="<?= e($value('fecha')) ?>"
        required
    >
    <?php if (isset($fieldErrors['fecha'])): ?>
        <span class="catalog-field-error"><?= e($fieldErrors['fecha']) ?></span>
    <?php endif; ?>
</div>

<div class="catalog-field">
    <label for="<?= e($fieldPrefix) ?>-value">Valor</label>
    <input
        id="<?= e($fieldPrefix) ?>-value"
        name="valor"
        type="number"
        value="<?= e($value('valor')) ?>"
        min="0.00000001"
        max="999999999999.99999999"
        step="0.00000001"
        inputmode="decimal"
        required
        aria-describedby="<?= e($fieldPrefix) ?>-value-help"
    >
    <small id="<?= e($fieldPrefix) ?>-value-help">
        Hasta 8 decimales; debe ser mayor que cero.
    </small>
    <?php if (isset($fieldErrors['valor'])): ?>
        <span class="catalog-field-error"><?= e($fieldErrors['valor']) ?></span>
    <?php endif; ?>
</div>
