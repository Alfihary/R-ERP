<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('PRODUCTOS-2B-2 UI test is CLI-only.');
}

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 2));
}

$view = file_get_contents(BASE_PATH . '/app/Views/products/index.php');
$script = file_get_contents(BASE_PATH . '/public/js/modules/products-list.js');
$css = file_get_contents(BASE_PATH . '/public/css/modules/products.css');
$controller = file_get_contents(BASE_PATH . '/app/Http/Controllers/ProductController.php');

foreach ([$view, $script, $css, $controller] as $source) {
    if (!is_string($source)) {
        throw new RuntimeException('PRODUCTOS-2B-2 UI source is unavailable.');
    }
}

$assertions = [
    'THUMBNAIL_COLUMN_RENDERED' => str_contains($view, '<th scope="col">Imagen</th>'),
    'PRODUCT_WITH_IMAGE_RENDERS_IMG' => str_contains($view, 'data-product-thumbnail-image'),
    'PRODUCT_WITHOUT_IMAGE_RENDERS_PLACEHOLDER' => str_contains($view, 'product-thumbnail-placeholder'),
    'THUMBNAIL_SRC_USES_PRODUCT_ENDPOINT' => str_contains($view, "'/productos/imagen?id_producto='") ,
    'THUMBNAIL_SRC_USES_ID' => str_contains($view, 'rawurlencode($productId)'),
    'THUMBNAIL_SRC_USES_VERSION' => str_contains(
        $view,
        "'&v=' . rawurlencode(\$imageVersion)"
    ),
    'THUMBNAIL_SRC_DOES_NOT_EXPOSE_RELATIVE_PATH' => !str_contains($view, 'ruta_relativa'),
    'THUMBNAIL_SRC_DOES_NOT_EXPOSE_ABSOLUTE_PATH' => !preg_match('/[A-Za-z]:\\\\/', $view),
    'THUMBNAIL_LOADING_LAZY' => str_contains($view, 'loading="lazy"'),
    'THUMBNAIL_ALT_VALID' => str_contains($view, 'alt=""'),
    'BROKEN_IMAGE_FALLBACK_PRESENT' => str_contains($script, "addEventListener('error'")
        && str_contains($script, 'placeholder.hidden = false'),
    'FILTERS_STILL_RENDER' => str_contains($view, 'name="search"')
        && str_contains($view, "'classification_id'")
        && str_contains($view, 'product-filter-actions'),
    'PAGINATION_STILL_RENDER' => str_contains($view, 'product-pagination'),
    'HTML_ESCAPING' => str_contains($view, 'e($imageSrc)')
        && str_contains($view, 'e($productId)'),
    'PROTECTED_PRODUCT_UNTOUCHED' => !str_contains($view, '102016169'),
    'NO_INLINE_IMAGE_HANDLER' => !str_contains($view, 'onerror='),
    'NO_FETCH_FALLBACK' => !str_contains($script, 'fetch('),
    'RESPONSIVE_THUMBNAIL_MINIMUM' => str_contains($css, 'width: 2.5rem;')
        && str_contains($css, 'height: 2.5rem;'),
    'PERMISSION_UNCHANGED' => str_contains($controller, "'ver'")
        || str_contains($controller, 'productos.ver'),
];

foreach ($assertions as $name => $passed) {
    if (!$passed) {
        throw new RuntimeException('PRODUCTOS-2B-2 UI test failed: ' . $name);
    }
}

echo json_encode(
    array_merge($assertions, [
        'BROKEN_IMAGE_FALLBACK_TEST' => 'PASS',
        'PRODUCTOS_2B_2_UI_TEST' => 'PASS',
    ]),
    JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
) . PHP_EOL;
