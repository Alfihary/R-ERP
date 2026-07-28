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

$image = is_array($image ?? null) ? $image : null;
$priceLists = is_array($priceLists ?? null) ? $priceLists : [];
$prices = is_array($prices ?? null) ? $prices : [];
$pricePermissions = is_array($pricePermissions ?? null)
    ? $pricePermissions
    : [];
$selectedTaxes = is_array($values['impuestos'] ?? null)
    ? array_map('strval', $values['impuestos'])
    : [];
$productId = (string) ($values['id_producto'] ?? '');
$originalId = (string) (
    $values['original_id_producto']
    ?? $productId
);
$initialPriceRows = is_array($values['precios_iniciales'] ?? null)
    ? $values['precios_iniciales']
    : [];
$currencyPriceRows = is_array($values['precios_cambio_moneda'] ?? null)
    ? $values['precios_cambio_moneda']
    : [];
$pricedListIds = [];
foreach ($prices as $price) {
    if (is_array($price) && isset($price['lista_precio_id'])) {
        $pricedListIds[(string) $price['lista_precio_id']] = true;
    }
}
$availableInitialPriceLists = array_values(array_filter(
    $priceLists,
    static function (array $list) use ($pricedListIds): bool {
        $listId = (string) ($list['id'] ?? '');

        return $listId !== '' && !isset($pricedListIds[$listId]);
    }
));
$priceRowValue = static function (
    array $rows,
    string $listId,
    string $field
): string {
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ((string) ($row['lista_precio_id'] ?? '') === $listId) {
            return (string) ($row[$field] ?? '');
        }
    }

    return '';
};
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

<?php if (($_GET['image_error'] ?? '') === '1'): ?>
    <div class="product-alert" role="alert">
        <strong>No fue posible actualizar la imagen principal.</strong>
        <p>Usa JPEG, PNG o WebP válidos, con tamaño máximo de 5 MiB.</p>
    </div>
<?php endif; ?>

<form
    class="product-form"
    method="post"
    enctype="multipart/form-data"
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
        <legend>Datos principales</legend>
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

    <?php if (!$editing): ?>
        <fieldset class="product-form-section product-image-section">
            <legend>Imagen principal</legend>
            <p class="product-form-section__help">
                Puedes seleccionar una imagen ahora y se guardará junto con el
                producto.
            </p>
            <div class="product-image-create-note">
                <div class="product-image-placeholder" role="img" aria-label="Producto sin imagen principal">
                    <span aria-hidden="true">▧</span>
                    <strong>Imagen opcional</strong>
                </div>
                <div class="product-field">
                    <label for="imagen">Seleccionar imagen principal</label>
                    <input
                        id="imagen"
                        name="imagen"
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        aria-describedby="imagen_create_help<?= isset($errors['imagen']) ? ' imagen_create_error' : '' ?>"
                    >
                    <small id="imagen_create_help">
                        JPG, PNG o WEBP. Máximo 5 MiB. Se valida MIME real y
                        contenido decodificable. SVG no está permitido.
                    </small>
                    <?php if (isset($errors['imagen'])): ?>
                        <span class="product-field-error" id="imagen_create_error">
                            <?= e($errors['imagen']) ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </fieldset>
    <?php else: ?>
        <fieldset class="product-form-section product-image-section">
            <legend>Imagen principal</legend>
            <p class="product-form-section__help">
                Opcional. Se almacena de forma privada y solo se entrega por
                endpoint autenticado. Formatos: JPEG, PNG o WebP. Tamaño máximo:
                5 MiB.
            </p>
            <div class="product-image-editor">
                <div class="product-image-preview">
                    <?php if ($image !== null): ?>
                        <img
                            src="/productos/imagen?id_producto=<?= e(rawurlencode($productId)) ?>&v=<?= e(rawurlencode((string) ($image['actualizado_en'] ?? $image['creado_en'] ?? ''))) ?>"
                            alt="Imagen principal de <?= e($productId) ?>"
                        >
                    <?php else: ?>
                        <div class="product-image-placeholder" role="img" aria-label="Producto sin imagen principal">
                            <span aria-hidden="true">▧</span>
                            <strong>Sin imagen</strong>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="product-image-actions">
                    <div class="product-field">
                        <label for="imagen">Seleccionar imagen</label>
                        <input
                            id="imagen"
                            name="imagen"
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            aria-describedby="imagen_help<?= isset($errors['imagen']) ? ' imagen_error' : '' ?>"
                        >
                        <small id="imagen_help">
                            El backend valida MIME real, contenido decodificable
                            y tamaño real del archivo temporal.
                        </small>
                        <?php if (isset($errors['imagen'])): ?>
                            <span class="product-field-error" id="imagen_error">
                                <?= e($errors['imagen']) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <button
                        class="button"
                        type="submit"
                        formaction="/productos/imagen/subir"
                        formenctype="multipart/form-data"
                        formmethod="post"
                    >
                        <?= $image === null ? 'Subir imagen' : 'Subir/Reemplazar' ?>
                    </button>
                    <?php if ($image !== null): ?>
                        <button
                            class="button button--secondary"
                            type="submit"
                            formaction="/productos/imagen/eliminar"
                            formmethod="post"
                            formnovalidate
                        >
                            Eliminar imagen
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </fieldset>
    <?php endif; ?>

    <fieldset class="product-form-section">
        <legend>Identificadores</legend>
        <p class="product-form-section__help">
            Campos principales de identificación comercial. El ID producto
            sigue siendo la llave natural del ERP; el SKU no lo reemplaza.
        </p>
        <div class="product-form-grid product-form-grid--identifiers">
            <?php foreach ([
                'sku' => [
                    'SKU',
                    'text',
                    '40',
                    'Ej. RF-BOHN/001',
                    'Interno/comercial principal; se normaliza a mayúsculas.',
                ],
                'sku_alterno' => [
                    'SKU alterno',
                    'text',
                    '40',
                    'Ej. ALT-001',
                    'Opcional para equivalencias internas futuras.',
                ],
                'upc' => [
                    'UPC',
                    'text',
                    '12',
                    '12 dígitos',
                    'Código UPC formal de 12 dígitos.',
                ],
                'ean' => [
                    'EAN',
                    'text',
                    '14',
                    '8 o 13 dígitos',
                    'Código EAN formal de 8 o 13 dígitos.',
                ],
                'gtin' => [
                    'GTIN',
                    'text',
                    '14',
                    '8, 12, 13 o 14 dígitos',
                    'Identificador global de artículo comercial.',
                ],
                'codigo_fabricante' => [
                    'Código fabricante',
                    'text',
                    '60',
                    'Ej. MOD-AB/22',
                    'Código publicado por fabricante; se normaliza a mayúsculas.',
                ],
                'modelo' => [
                    'Modelo',
                    'text',
                    '80',
                    'Ej. Bohn serie comercial',
                    'Modelo, presentación o referencia descriptiva.',
                ],
            ] as $field => [$label, $type, $max, $placeholder, $help]): ?>
                <div class="product-field<?= $field === 'modelo' ? ' product-field--wide' : '' ?>">
                    <label for="<?= e($field) ?>"><?= e($label) ?></label>
                    <input
                        id="<?= e($field) ?>"
                        name="<?= e($field) ?>"
                        type="<?= e($type) ?>"
                        maxlength="<?= e($max) ?>"
                        value="<?= e((string) ($values[$field] ?? '')) ?>"
                        placeholder="<?= e($placeholder) ?>"
                        autocomplete="off"
                    >
                    <small><?= e($help) ?></small>
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
        <legend>Clasificación y catálogos</legend>
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

    <?php if (!$editing && ($pricePermissions['create'] ?? false) === true): ?>
        <fieldset class="product-form-section">
            <legend>Precios iniciales</legend>
            <p class="product-form-section__help">
                Opcional. Si capturas al menos un precio, el producto debe
                tener moneda asignada. Las filas totalmente vacías se ignoran.
            </p>
            <?php if ($priceLists === []): ?>
                <p class="product-related-empty">No hay listas de precios activas.</p>
            <?php else: ?>
                <div class="product-table-wrap">
                    <table class="product-table product-table--prices">
                        <thead>
                            <tr>
                                <th>Lista</th>
                                <th>Precio lista</th>
                                <th>Precio mínimo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_values($priceLists) as $index => $list): ?>
                                <?php $listId = (string) ($list['id'] ?? ''); ?>
                                <tr>
                                    <td>
                                        <strong><?= e($list['clave'] ?? '') ?></strong>
                                        <span><?= e($list['nombre'] ?? '') ?></span>
                                        <input
                                            type="hidden"
                                            name="precios_iniciales[<?= e((string) $index) ?>][lista_precio_id]"
                                            value="<?= e($listId) ?>"
                                        >
                                    </td>
                                    <td>
                                        <label class="sr-only" for="precio_inicial_lista_<?= e((string) $index) ?>">
                                            Precio lista <?= e($list['clave'] ?? '') ?>
                                        </label>
                                        <input
                                            id="precio_inicial_lista_<?= e((string) $index) ?>"
                                            name="precios_iniciales[<?= e((string) $index) ?>][precio_lista]"
                                            type="number"
                                            inputmode="decimal"
                                            min="0"
                                            step="0.0001"
                                            value="<?= e($priceRowValue($initialPriceRows, $listId, 'precio_lista')) ?>"
                                        >
                                    </td>
                                    <td>
                                        <label class="sr-only" for="precio_inicial_minimo_<?= e((string) $index) ?>">
                                            Precio mínimo <?= e($list['clave'] ?? '') ?>
                                        </label>
                                        <input
                                            id="precio_inicial_minimo_<?= e((string) $index) ?>"
                                            name="precios_iniciales[<?= e((string) $index) ?>][precio_minimo]"
                                            type="number"
                                            inputmode="decimal"
                                            min="0"
                                            step="0.0001"
                                            value="<?= e($priceRowValue($initialPriceRows, $listId, 'precio_minimo')) ?>"
                                        >
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <?php if (isset($errors['precios_iniciales'])): ?>
                <span class="product-field-error">
                    <?= e($errors['precios_iniciales']) ?>
                </span>
            <?php endif; ?>
        </fieldset>
    <?php endif; ?>

    <?php if ($editing && ($pricePermissions['view'] ?? false) === true): ?>
        <fieldset class="product-form-section">
            <legend>Precios actuales</legend>
            <p class="product-form-section__help">
                Consulta de precios vigentes del producto. Para editar importes
                ya registrados usa la pantalla global de precios.
            </p>
            <?php if ($prices === []): ?>
                <p class="product-related-empty">El producto no tiene precios registrados.</p>
            <?php else: ?>
                <div class="product-table-wrap">
                    <table class="product-table product-table--prices">
                        <thead>
                            <tr>
                                <th>Lista</th>
                                <th>Precio lista</th>
                                <th>Precio mínimo</th>
                                <th>Moneda</th>
                                <th>Impuestos</th>
                                <th>Revisión</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($prices as $price): ?>
                                <?php
                                $requiresReview = (int) (
                                    $price['requiere_revision'] ?? 0
                                ) === 1;
                                $priceActive = (int) ($price['activo'] ?? 0)
                                    === 1;
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= e($price['lista_clave'] ?? '') ?></strong>
                                        <span><?= e($price['lista_nombre'] ?? '') ?></span>
                                    </td>
                                    <td><?= e($price['precio_lista'] ?? '') ?></td>
                                    <td><?= e($price['precio_minimo'] ?? '') ?></td>
                                    <td><?= e($price['moneda_codigo'] ?? '') ?></td>
                                    <td>
                                        <?= (int) ($price['incluye_impuestos'] ?? 0) === 1
                                            ? 'Incluye'
                                            : 'No incluye' ?>
                                    </td>
                                    <td>
                                        <span class="product-status<?= $requiresReview ? '' : ' is-active' ?>">
                                            <?= $requiresReview
                                                ? 'Requiere revisión'
                                                : 'Vigente' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="product-status<?= $priceActive ? ' is-active' : '' ?>">
                                            <?= $priceActive ? 'Activo' : 'Inactivo' ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </fieldset>
    <?php endif; ?>

    <?php if (
        $editing
        && ($pricePermissions['create'] ?? false) === true
        && $availableInitialPriceLists !== []
    ): ?>
        <fieldset class="product-form-section">
            <legend>Agregar precios faltantes</legend>
            <p class="product-form-section__help">
                Captura precios para listas activas que todavía no están
                registradas en este producto. Las filas totalmente vacías se
                ignoran.
            </p>
            <div class="product-table-wrap">
                <table class="product-table product-table--prices">
                    <thead>
                        <tr>
                            <th>Lista</th>
                            <th>Precio lista</th>
                            <th>Precio mínimo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($availableInitialPriceLists as $index => $list): ?>
                            <?php $listId = (string) ($list['id'] ?? ''); ?>
                            <tr>
                                <td>
                                    <strong><?= e($list['clave'] ?? '') ?></strong>
                                    <span><?= e($list['nombre'] ?? '') ?></span>
                                    <input
                                        type="hidden"
                                        name="precios_iniciales[<?= e((string) $index) ?>][lista_precio_id]"
                                        value="<?= e($listId) ?>"
                                    >
                                </td>
                                <td>
                                    <label class="sr-only" for="precio_faltante_lista_<?= e((string) $index) ?>">
                                        Precio lista <?= e($list['clave'] ?? '') ?>
                                    </label>
                                    <input
                                        id="precio_faltante_lista_<?= e((string) $index) ?>"
                                        name="precios_iniciales[<?= e((string) $index) ?>][precio_lista]"
                                        type="number"
                                        inputmode="decimal"
                                        min="0"
                                        step="0.0001"
                                        value="<?= e($priceRowValue($initialPriceRows, $listId, 'precio_lista')) ?>"
                                    >
                                </td>
                                <td>
                                    <label class="sr-only" for="precio_faltante_minimo_<?= e((string) $index) ?>">
                                        Precio mínimo <?= e($list['clave'] ?? '') ?>
                                    </label>
                                    <input
                                        id="precio_faltante_minimo_<?= e((string) $index) ?>"
                                        name="precios_iniciales[<?= e((string) $index) ?>][precio_minimo]"
                                        type="number"
                                        inputmode="decimal"
                                        min="0"
                                        step="0.0001"
                                        value="<?= e($priceRowValue($initialPriceRows, $listId, 'precio_minimo')) ?>"
                                    >
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (isset($errors['precios_iniciales'])): ?>
                <span class="product-field-error">
                    <?= e($errors['precios_iniciales']) ?>
                </span>
            <?php endif; ?>
        </fieldset>
    <?php endif; ?>

    <?php if (
        $editing
        && $prices !== []
        && ($pricePermissions['edit'] ?? false) === true
    ): ?>
        <fieldset class="product-form-section">
            <legend>Actualizar precios por cambio de moneda</legend>
            <p class="product-form-section__help">
                Si cambias la moneda del producto, captura los importes de las
                listas que ya puedas actualizar. Las listas que dejes vacías
                quedarán en 0.00 y pendientes de revisión.
            </p>
            <div class="product-table-wrap">
                <table class="product-table product-table--prices">
                    <thead>
                        <tr>
                            <th>Lista</th>
                            <th>Precio actual</th>
                            <th>Nuevo precio lista</th>
                            <th>Nuevo precio mínimo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_values($prices) as $index => $price): ?>
                            <?php $listId = (string) ($price['lista_precio_id'] ?? ''); ?>
                            <tr>
                                <td>
                                    <strong><?= e($price['lista_clave'] ?? '') ?></strong>
                                    <span><?= e($price['lista_nombre'] ?? '') ?></span>
                                    <input
                                        type="hidden"
                                        name="precios_cambio_moneda[<?= e((string) $index) ?>][lista_precio_id]"
                                        value="<?= e($listId) ?>"
                                    >
                                </td>
                                <td>
                                    <?= e($price['precio_lista'] ?? '') ?>
                                    /
                                    <?= e($price['precio_minimo'] ?? '') ?>
                                    <?= e($price['moneda_codigo'] ?? '') ?>
                                </td>
                                <td>
                                    <label class="sr-only" for="precio_cambio_lista_<?= e((string) $index) ?>">
                                        Nuevo precio lista <?= e($price['lista_clave'] ?? '') ?>
                                    </label>
                                    <input
                                        id="precio_cambio_lista_<?= e((string) $index) ?>"
                                        name="precios_cambio_moneda[<?= e((string) $index) ?>][precio_lista]"
                                        type="number"
                                        inputmode="decimal"
                                        min="0"
                                        step="0.0001"
                                        value="<?= e($priceRowValue($currencyPriceRows, $listId, 'precio_lista')) ?>"
                                    >
                                </td>
                                <td>
                                    <label class="sr-only" for="precio_cambio_minimo_<?= e((string) $index) ?>">
                                        Nuevo precio mínimo <?= e($price['lista_clave'] ?? '') ?>
                                    </label>
                                    <input
                                        id="precio_cambio_minimo_<?= e((string) $index) ?>"
                                        name="precios_cambio_moneda[<?= e((string) $index) ?>][precio_minimo]"
                                        type="number"
                                        inputmode="decimal"
                                        min="0"
                                        step="0.0001"
                                        value="<?= e($priceRowValue($currencyPriceRows, $listId, 'precio_minimo')) ?>"
                                    >
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (isset($errors['precios_cambio_moneda'])): ?>
                <span class="product-field-error">
                    <?= e($errors['precios_cambio_moneda']) ?>
                </span>
            <?php endif; ?>
        </fieldset>
    <?php endif; ?>

    <fieldset class="product-form-section">
        <legend>Clasificación SAT</legend>
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
        <legend>Medidas</legend>
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
        <legend>Inventario y trazabilidad</legend>
        <p class="product-form-section__help">
            Define políticas de trazabilidad. Series ya participa en
            inventario; lotes y pedimentos siguen pendientes como lógica
            operativa posterior.
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
        <legend>Códigos adicionales</legend>
        <p class="product-form-section__help">
            Captura códigos secundarios, antiguos, de empaque o presentación.
            No sustituyen SKU, UPC, EAN ni GTIN principales.
        </p>
        <div class="product-field product-field--barcode">
            <label for="codigos_barras">Códigos adicionales</label>
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
