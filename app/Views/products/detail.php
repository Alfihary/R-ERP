<?php

declare(strict_types=1);

use App\Support\Security\CsrfTokenService;

if (
    !$csrf instanceof CsrfTokenService
    || !is_array($abilities ?? null)
    || !is_array($product ?? null)
) {
    throw new RuntimeException('Product detail data is incomplete.');
}

$image = is_array($image ?? null) ? $image : null;
$notice = is_string($notice ?? null) ? $notice : null;
$productId = (string) ($product['id_producto'] ?? '');
$active = (int) ($product['activo'] ?? 0) === 1;
$taxes = is_array($product['taxes'] ?? null) ? $product['taxes'] : [];
$barcodes = is_array($product['barcodes'] ?? null)
    ? $product['barcodes']
    : [];
$measurement = static function (mixed $value, string $unit): string {
    if ($value === null || $value === '') {
        return '—';
    }

    return (string) $value . ' ' . $unit;
};
$satKey = trim(
    (string) ($product['clave_sat_codigo'] ?? '')
    . ' · '
    . (string) ($product['clave_sat_descripcion'] ?? ''),
    ' ·'
) ?: 'Sin asignar';
$satUnit = trim(
    (string) ($product['unidad_sat_codigo'] ?? '')
    . ' · '
    . (string) ($product['unidad_sat_nombre'] ?? ''),
    ' ·'
) ?: 'Sin asignar';
?>
<header class="product-page-heading">
    <div>
        <p><a href="/productos">Productos</a> / Detalle</p>
        <h1><?= e($productId) ?></h1>
        <p><?= e($product['descripcion'] ?? '') ?></p>
    </div>
    <div class="product-heading-actions">
        <?php if (($abilities['editar'] ?? false) === true): ?>
            <a
                class="button"
                href="/productos/editar?id_producto=<?= e(rawurlencode($productId)) ?>"
            >Editar producto</a>
        <?php endif; ?>
        <a class="button button--secondary" href="/productos">Volver</a>
    </div>
</header>

<?php if ($notice !== null): ?>
    <p class="product-notice" role="status"><?= e($notice) ?></p>
<?php endif; ?>

<section class="product-detail product-image-detail" aria-labelledby="product-image-title">
    <div class="product-section-heading">
        <div>
            <h2 id="product-image-title">Imagen principal</h2>
            <p>Vista privada del producto; sin rutas físicas ni metadatos internos.</p>
        </div>
    </div>
    <div class="product-image-display">
        <?php if ($image !== null): ?>
            <img
                src="/productos/imagen?id_producto=<?= e(rawurlencode($productId)) ?>&v=<?= e(rawurlencode((string) ($image['actualizado_en'] ?? $image['creado_en'] ?? ''))) ?>"
                alt="Imagen principal de <?= e($productId) ?>"
            >
        <?php else: ?>
            <div class="product-image-placeholder" role="img" aria-label="Producto sin imagen principal">
                <span aria-hidden="true">▧</span>
                <strong>Sin imagen principal</strong>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="product-detail" aria-labelledby="product-detail-title">
    <div class="product-section-heading">
        <div>
            <h2 id="product-detail-title">Información del producto</h2>
            <p>Identidad comercial y relaciones estructurales.</p>
        </div>
        <span class="product-status<?= $active ? ' is-active' : '' ?>">
            <?= $active ? 'Activo' : 'Inactivo' ?>
        </span>
    </div>
    <dl class="product-definition-list">
        <?php foreach ([
            'ID producto' => $productId,
            'Descripción corta' => $product['descripcion'] ?? '',
            'Descripción larga' => $product['descripcion_larga'] ?? '—',
            'SKU' => $product['sku'] ?? '—',
            'SKU alterno' => $product['sku_alterno'] ?? '—',
            'UPC' => $product['upc'] ?? '—',
            'EAN' => $product['ean'] ?? '—',
            'GTIN' => $product['gtin'] ?? '—',
            'Código fabricante' => $product['codigo_fabricante'] ?? '—',
            'Modelo' => $product['modelo'] ?? '—',
            'Tipo' => $product['tipo_nombre'] ?? '—',
            'Unidad' => trim(
                (string) ($product['unidad_codigo'] ?? '')
                . ' · '
                . (string) ($product['unidad_nombre'] ?? ''),
                ' ·'
            ),
            'Moneda' => trim(
                (string) ($product['moneda_codigo'] ?? '')
                . ' · '
                . (string) ($product['moneda_nombre'] ?? ''),
                ' ·'
            ) ?: '—',
            'Línea' => $product['linea_nombre'] ?? '—',
            'Marca' => $product['marca_nombre'] ?? '—',
            'Clasificación' => $product['clasificacion_nombre'] ?? '—',
            'Clave SAT' => $satKey,
            'Unidad SAT' => $satUnit,
            'Peso' => $measurement($product['peso_kg'] ?? null, 'kg'),
            'Largo' => $measurement($product['largo_cm'] ?? null, 'cm'),
            'Ancho' => $measurement($product['ancho_cm'] ?? null, 'cm'),
            'Alto' => $measurement($product['alto_cm'] ?? null, 'cm'),
            'Controla series' =>
                (int) ($product['controla_series'] ?? 0) === 1 ? 'Sí' : 'No',
            'Controla lotes' =>
                (int) ($product['controla_lotes'] ?? 0) === 1 ? 'Sí' : 'No',
            'Controla pedimentos' =>
                (int) ($product['controla_pedimentos'] ?? 0) === 1
                    ? 'Sí'
                    : 'No',
        ] as $term => $description): ?>
            <div>
                <dt><?= e($term) ?></dt>
                <dd><?= e((string) $description) ?></dd>
            </div>
        <?php endforeach; ?>
    </dl>
</section>

<div class="product-related">
    <section class="product-related-section" aria-labelledby="product-tax-title">
        <div class="product-section-heading">
            <div>
                <h2 id="product-tax-title">Impuestos</h2>
                <p>Asociaciones activas; no se calculan importes.</p>
            </div>
        </div>
        <?php if ($taxes === []): ?>
            <p class="product-related-empty">Sin impuestos asociados.</p>
        <?php else: ?>
            <ul class="product-plain-list">
                <?php foreach ($taxes as $tax): ?>
                    <li>
                        <strong><?= e($tax['codigo'] ?? '') ?></strong>
                        <span><?= e($tax['nombre'] ?? '') ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="product-related-section" aria-labelledby="product-barcode-title">
        <div class="product-section-heading">
            <div>
                <h2 id="product-barcode-title">Códigos adicionales</h2>
                <p>
                    Códigos secundarios o de empaque; no sustituyen SKU, UPC,
                    EAN ni GTIN principales.
                </p>
            </div>
        </div>
        <?php if ($barcodes === []): ?>
            <p class="product-related-empty">Sin códigos adicionales.</p>
        <?php else: ?>
            <ul class="product-plain-list">
                <?php foreach ($barcodes as $barcode): ?>
                    <li>
                        <strong><?= e($barcode['codigo_barras'] ?? '') ?></strong>
                        <?php if ((int) ($barcode['es_principal'] ?? 0) === 1): ?>
                            <span>Principal</span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
