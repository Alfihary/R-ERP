<?php

declare(strict_types=1);

use App\Support\Security\CsrfTokenService;

if (
    !$csrf instanceof CsrfTokenService
    || !is_array($catalogs ?? null)
    || !is_array($errors ?? null)
    || !is_array($values ?? null)
    || !is_bool($editing ?? null)
) {
    throw new RuntimeException('Product form data is incomplete.');
}

$selectedTaxes = is_array($values['impuestos'] ?? null)
    ? array_map('strval', $values['impuestos'])
    : [];
$productId = (string) ($values['id_producto'] ?? '');
$originalId = (string) (
    $values['original_id_producto']
    ?? $productId
);
$selectedSatKeyId = (string) ($values['clave_sat_id'] ?? '');
$selectedSatKeyLabel = (string) ($values['clave_sat_label'] ?? '');
?>
<header class="product-page-heading">
    <div>
        <p><a href="/productos">Productos</a> / <?= $editing ? 'Editar' : 'Crear' ?></p>
        <h1><?= $editing ? 'Editar producto' : 'Crear producto' ?></h1>
        <p>
            La identidad es una llave natural. Después de crear el producto,
            su ID permanece inmutable.
        </p>
    </div>
</header>

<?php if ($errors !== []): ?>
    <div class="product-alert" role="alert">
        <strong>No fue posible guardar el producto.</strong>
        <p>Revisa los campos marcados y vuelve a intentarlo.</p>
    </div>
<?php endif; ?>

<form
    class="product-form"
    method="post"
    action="<?= $editing ? '/productos/actualizar' : '/productos' ?>"
>
    <?= csrf_field($csrf) ?>
    <?php if ($editing): ?>
        <input
            type="hidden"
            name="original_id_producto"
            value="<?= e($originalId) ?>"
        >
    <?php endif; ?>

    <fieldset class="product-form-section">
        <legend>Identidad y descripción</legend>
        <p class="product-form-section__help">
            El servicio normaliza a mayúsculas durante la creación.
        </p>
        <div class="product-form-grid">
            <div class="product-field">
                <label for="id_producto">ID producto</label>
                <input
                    id="id_producto"
                    name="id_producto"
                    type="text"
                    maxlength="16"
                    pattern="[A-Za-z0-9]{1,16}"
                    value="<?= e($productId) ?>"
                    <?= $editing ? 'readonly aria-readonly="true"' : 'required' ?>
                    autocomplete="off"
                >
                <small>1–16 letras o números; sin espacios, guiones ni acentos.</small>
                <?php if (isset($errors['id_producto'])): ?>
                    <span class="product-field-error">
                        <?= e($errors['id_producto']) ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="product-field product-field--wide">
                <label for="descripcion">Descripción</label>
                <input
                    id="descripcion"
                    name="descripcion"
                    type="text"
                    maxlength="40"
                    value="<?= e((string) ($values['descripcion'] ?? '')) ?>"
                    required
                >
                <?php if (isset($errors['descripcion'])): ?>
                    <span class="product-field-error">
                        <?= e($errors['descripcion']) ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="product-field product-field--full">
                <label for="descripcion_larga">Descripción larga</label>
                <textarea
                    id="descripcion_larga"
                    name="descripcion_larga"
                    maxlength="255"
                    rows="3"
                ><?= e((string) ($values['descripcion_larga'] ?? '')) ?></textarea>
                <small>Opcional, máximo 255 caracteres.</small>
                <?php if (isset($errors['descripcion_larga'])): ?>
                    <span class="product-field-error">
                        <?= e($errors['descripcion_larga']) ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </fieldset>

    <fieldset class="product-form-section">
        <legend>Tipo de producto</legend>
        <p class="product-form-section__help">
            El tipo define si admite características físicas y controles de
            inventario. Un servicio no admite ninguno de esos datos.
        </p>
        <div class="product-form-grid">
            <div class="product-field">
                <label for="tipo_producto">Tipo</label>
                <select id="tipo_producto" name="tipo_producto" required>
                    <option value="">Selecciona un tipo</option>
                    <?php foreach (($catalogs['types'] ?? []) as $type): ?>
                        <?php
                        $typeCode = (string) ($type['codigo'] ?? '');
                        $selectedType = (string) (
                            $values['tipo_producto'] ?? ''
                        ) === $typeCode;
                        ?>
                        <option
                            value="<?= e($typeCode) ?>"
                            <?= $selectedType ? 'selected' : '' ?>
                        >
                            <?= e($type['nombre'] ?? '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small>
                    KIT registra una política futura; no administra componentes.
                </small>
                <?php if (isset($errors['tipo_producto'])): ?>
                    <span class="product-field-error">
                        <?= e($errors['tipo_producto']) ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </fieldset>

    <fieldset class="product-form-section">
        <legend>Clasificación comercial</legend>
        <p class="product-form-section__help">
            Solo se ofrecen registros activos de los catálogos aprobados.
        </p>
        <div class="product-form-grid">
            <?php foreach ([
                'unidad_medida_id' => ['Unidad de medida', 'units', true],
                'moneda_id' => ['Moneda', 'currencies', false],
                'linea_producto_id' => ['Línea', 'lines', false],
                'marca_id' => ['Marca', 'brands', false],
                'clasificacion_producto_id' => [
                    'Clasificación',
                    'classifications',
                    false,
                ],
            ] as $field => [$label, $catalogKey, $required]): ?>
                <div class="product-field">
                    <label for="<?= e($field) ?>"><?= e($label) ?></label>
                    <select
                        id="<?= e($field) ?>"
                        name="<?= e($field) ?>"
                        <?= $required ? 'required' : '' ?>
                    >
                        <option value="">
                            <?= $required ? 'Selecciona una opción' : 'Sin asignar' ?>
                        </option>
                        <?php foreach (($catalogs[$catalogKey] ?? []) as $option): ?>
                            <?php
                            $optionId = (string) ($option['id'] ?? '');
                            $selected = (string) ($values[$field] ?? '')
                                === $optionId;
                            ?>
                            <option
                                value="<?= e($optionId) ?>"
                                <?= $selected ? 'selected' : '' ?>
                            >
                                <?= e($option['codigo'] ?? '') ?>
                                ·
                                <?= e($option['nombre'] ?? '') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors[$field])): ?>
                        <span class="product-field-error">
                            <?= e($errors[$field]) ?>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </fieldset>

    <fieldset class="product-form-section">
        <legend>Datos SAT</legend>
        <p class="product-form-section__help">
            Datos fiscales opcionales. El producto puede operar sin clave o
            unidad SAT; CFDI y facturación quedan fuera de esta fase.
        </p>
        <div class="product-form-grid">
            <div
                class="product-field product-field--wide product-sat-search"
                data-sat-search
                data-endpoint="/catalogos/claves-sat/buscar"
            >
                <label for="clave_sat_search">Clave SAT</label>
                <input
                    id="clave_sat_id"
                    name="clave_sat_id"
                    type="hidden"
                    value="<?= e($selectedSatKeyId) ?>"
                    data-sat-key-id
                >
                <input
                    id="clave_sat_search"
                    name="clave_sat_label"
                    type="search"
                    value="<?= e($selectedSatKeyLabel) ?>"
                    placeholder="Busca por código o descripción"
                    autocomplete="off"
                    data-sat-key-search
                    aria-describedby="clave_sat_help clave_sat_status"
                >
                <small id="clave_sat_help">
                    Escribe al menos 2 caracteres. Se muestran hasta 20
                    resultados activos.
                </small>
                <div
                    class="product-sat-search__status"
                    id="clave_sat_status"
                    role="status"
                    aria-live="polite"
                    data-sat-status
                ></div>
                <div
                    class="product-sat-search__results"
                    role="listbox"
                    aria-label="Resultados de clave SAT"
                    data-sat-results
                    hidden
                ></div>
                <button
                    class="button button--secondary product-sat-search__clear"
                    type="button"
                    data-sat-clear
                >
                    Limpiar clave SAT
                </button>
                <?php if (isset($errors['clave_sat_id'])): ?>
                    <span class="product-field-error">
                        <?= e($errors['clave_sat_id']) ?>
                    </span>
                <?php endif; ?>
            </div>

            <div class="product-field">
                <label for="unidad_sat_id">Unidad SAT</label>
                <select id="unidad_sat_id" name="unidad_sat_id">
                    <option value="">Sin asignar</option>
                    <?php foreach (($catalogs['sat_units'] ?? []) as $option): ?>
                        <?php
                        $optionId = (string) ($option['id'] ?? '');
                        $selected = (string) ($values['unidad_sat_id'] ?? '')
                            === $optionId;
                        ?>
                        <option
                            value="<?= e($optionId) ?>"
                            <?= $selected ? 'selected' : '' ?>
                        >
                            <?= e($option['codigo'] ?? '') ?>
                            ·
                            <?= e($option['nombre'] ?? '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small>Opcional; solo unidades SAT activas.</small>
                <?php if (isset($errors['unidad_sat_id'])): ?>
                    <span class="product-field-error">
                        <?= e($errors['unidad_sat_id']) ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </fieldset>

    <fieldset class="product-form-section">
        <legend>Características físicas</legend>
        <p class="product-form-section__help">
            Opcionales para productos y kits. Usa kilogramos para peso y
            centímetros para dimensiones. Solo se aceptan valores positivos.
        </p>
        <div class="product-form-grid product-form-grid--physical">
            <?php foreach ([
                'peso_kg' => ['Peso', 'kg', '0.0001', '99999999.9999'],
                'largo_cm' => ['Largo', 'cm', '0.001', '999999999.999'],
                'ancho_cm' => ['Ancho', 'cm', '0.001', '999999999.999'],
                'alto_cm' => ['Alto', 'cm', '0.001', '999999999.999'],
            ] as $field => [$label, $unit, $step, $max]): ?>
                <div class="product-field">
                    <label for="<?= e($field) ?>"><?= e($label) ?></label>
                    <div class="product-input-unit">
                        <input
                            id="<?= e($field) ?>"
                            name="<?= e($field) ?>"
                            type="number"
                            inputmode="decimal"
                            min="<?= e($step) ?>"
                            max="<?= e($max) ?>"
                            step="<?= e($step) ?>"
                            value="<?= e((string) ($values[$field] ?? '')) ?>"
                        >
                        <span aria-hidden="true"><?= e($unit) ?></span>
                    </div>
                    <?php if (isset($errors[$field])): ?>
                        <span class="product-field-error">
                            <?= e($errors[$field]) ?>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </fieldset>

    <fieldset class="product-form-section">
        <legend>Control de inventario</legend>
        <p class="product-form-section__help">
            Define políticas futuras. Esta fase no crea series, lotes,
            pedimentos, existencias ni movimientos.
        </p>
        <div class="product-check-grid">
            <?php foreach ([
                'controla_series' => [
                    'Maneja series',
                    'Identificación individual futura.',
                ],
                'controla_lotes' => [
                    'Maneja lotes',
                    'Agrupación por lote futura.',
                ],
                'controla_pedimentos' => [
                    'Maneja pedimentos',
                    'Referencia aduanal futura.',
                ],
            ] as $field => [$label, $help]): ?>
                <div>
                    <label class="product-check">
                        <input
                            type="checkbox"
                            name="<?= e($field) ?>"
                            value="1"
                            <?= (string) ($values[$field] ?? '0') === '1'
                                ? 'checked'
                                : '' ?>
                        >
                        <span>
                            <strong><?= e($label) ?></strong>
                            <?= e($help) ?>
                        </span>
                    </label>
                    <?php if (isset($errors[$field])): ?>
                        <span class="product-field-error">
                            <?= e($errors[$field]) ?>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </fieldset>

    <fieldset class="product-form-section">
        <legend>Impuestos</legend>
        <p class="product-form-section__help">
            La asociación no calcula importes ni precios en esta fase.
        </p>
        <div class="product-check-grid">
            <?php foreach (($catalogs['taxes'] ?? []) as $tax): ?>
                <?php $taxId = (string) ($tax['id'] ?? ''); ?>
                <label class="product-check">
                    <input
                        type="checkbox"
                        name="impuestos[]"
                        value="<?= e($taxId) ?>"
                        <?= in_array($taxId, $selectedTaxes, true)
                            ? 'checked'
                            : '' ?>
                    >
                    <span>
                        <strong><?= e($tax['codigo'] ?? '') ?></strong>
                        <?= e($tax['nombre'] ?? '') ?>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>
        <?php if (isset($errors['impuestos'])): ?>
            <span class="product-field-error">
                <?= e($errors['impuestos']) ?>
            </span>
        <?php endif; ?>
    </fieldset>

    <fieldset class="product-form-section">
        <legend>Códigos de barras</legend>
        <p class="product-form-section__help">
            Captura cero o varios códigos, uno por línea. El primero se
            considera principal.
        </p>
        <div class="product-field product-field--barcode">
            <label for="codigos_barras">Códigos</label>
            <textarea
                id="codigos_barras"
                name="codigos_barras"
                rows="5"
                spellcheck="false"
                placeholder="7501234567890"
            ><?= e((string) ($values['codigos_barras'] ?? '')) ?></textarea>
            <?php if (isset($errors['codigos_barras'])): ?>
                <span class="product-field-error">
                    <?= e($errors['codigos_barras']) ?>
                </span>
            <?php endif; ?>
        </div>
    </fieldset>

    <div class="product-form-actions">
        <button class="button" type="submit">
            <?= $editing ? 'Guardar cambios' : 'Crear producto' ?>
        </button>
        <a class="button button--secondary" href="/productos">Cancelar</a>
    </div>
</form>
