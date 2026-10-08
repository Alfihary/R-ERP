<?php

declare(strict_types=1);

use App\Support\Security\CsrfTokenService;

if (
    !$csrf instanceof CsrfTokenService
    || !is_array($abilities ?? null)
    || !is_array($catalogs ?? null)
    || !is_array($filters ?? null)
    || !is_array($products ?? null)
) {
    throw new RuntimeException('Product list data is incomplete.');
}

$notice = is_string($notice ?? null) ? $notice : null;
?>
<header class="product-page-heading">
    <div>
        <p>Catálogo estructural global</p>
        <h1>Productos</h1>
        <p>
            Consulta y administra la identidad comercial del producto.
            Inventario, existencias, series y folios consumen esta identidad;
            precios y compras quedan fuera de esta fase.
        </p>
    </div>
    <?php if (($abilities['crear'] ?? false) === true): ?>
        <a class="button product-heading-action" href="/productos/crear">
            Crear producto
        </a>
    <?php endif; ?>
</header>

<?php if ($notice !== null): ?>
    <p class="product-notice" role="status"><?= e($notice) ?></p>
<?php endif; ?>

<section class="product-filter-panel" aria-labelledby="product-filter-title">
    <div class="product-section-heading">
        <div>
            <h2 id="product-filter-title">Buscar y filtrar</h2>
            <p>Los filtros aplican al catálogo global, no al contexto activo.</p>
        </div>
    </div>
    <form class="product-filters" method="get" action="/productos">
        <div class="product-field product-field--search">
            <label for="product-search">ID, descripción o identificador</label>
            <input
                id="product-search"
                name="search"
                type="search"
                maxlength="80"
                value="<?= e((string) ($filters['search'] ?? '')) ?>"
            >
        </div>
        <div class="product-field">
            <label for="product-status">Estado</label>
            <select id="product-status" name="status">
                <option value="">Todos</option>
                <option
                    value="active"
                    <?= ($filters['status'] ?? '') === 'active' ? 'selected' : '' ?>
                >Activos</option>
                <option
                    value="inactive"
                    <?= ($filters['status'] ?? '') === 'inactive' ? 'selected' : '' ?>
                >Inactivos</option>
            </select>
        </div>
        <div class="product-field">
            <label for="product-type">Tipo</label>
            <select id="product-type" name="type_code">
                <option value="">Todos</option>
                <?php foreach (($catalogs['types'] ?? []) as $type): ?>
                    <?php $typeCode = (string) ($type['codigo'] ?? ''); ?>
                    <option
                        value="<?= e($typeCode) ?>"
                        <?= ($filters['type_code'] ?? '') === $typeCode
                            ? 'selected'
                            : '' ?>
                    >
                        <?= e($type['nombre'] ?? '') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php foreach ([
            'line_id' => ['Línea', 'lines'],
            'brand_id' => ['Marca', 'brands'],
            'classification_id' => ['Clasificación', 'classifications'],
        ] as $field => [$label, $catalogKey]): ?>
            <div class="product-field">
                <label for="product-<?= e($field) ?>"><?= e($label) ?></label>
                <select id="product-<?= e($field) ?>" name="<?= e($field) ?>">
                    <option value="">Todas</option>
                    <?php foreach (($catalogs[$catalogKey] ?? []) as $option): ?>
                        <option
                            value="<?= e((string) ($option['id'] ?? '')) ?>"
                            <?= (int) ($filters[$field] ?? 0)
                                === (int) ($option['id'] ?? 0)
                                ? 'selected'
                                : '' ?>
                        >
                            <?= e($option['nombre'] ?? '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endforeach; ?>
        <div class="product-filter-actions">
            <button class="button" type="submit">Aplicar filtros</button>
            <a class="button button--secondary" href="/productos">Limpiar</a>
        </div>
    </form>
</section>

<section class="product-list" aria-labelledby="product-list-title">
    <div class="product-section-heading">
        <div>
            <h2 id="product-list-title">Listado</h2>
            <p><?= e((string) count($products)) ?> producto(s) encontrado(s).</p>
        </div>
    </div>

    <?php if ($products === []): ?>
        <div class="product-empty">
            <strong>No hay productos para estos filtros.</strong>
            <p>Ajusta la búsqueda o crea el primer producto autorizado.</p>
        </div>
    <?php else: ?>
        <div class="product-table-wrap" tabindex="0">
            <table class="product-table">
                <caption class="sr-only">Listado de productos</caption>
                <thead>
                    <tr>
                        <th scope="col">ID producto</th>
                        <th scope="col">Descripción</th>
                        <th scope="col">Identificador</th>
                        <th scope="col">Tipo</th>
                        <th scope="col">Unidad</th>
                        <th scope="col">Línea</th>
                        <th scope="col">Marca</th>
                        <th scope="col">Clasificación</th>
                        <th scope="col">Estado</th>
                        <th scope="col">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product): ?>
                        <?php
                        $productId = (string) ($product['id_producto'] ?? '');
                        $active = (int) ($product['activo'] ?? 0) === 1;
                        ?>
                        <tr>
                            <td><strong><?= e($productId) ?></strong></td>
                            <td><?= e($product['descripcion'] ?? '') ?></td>
                            <td>
                                <?php
                                $identifier = trim(implode(' · ', array_filter([
                                    (string) ($product['sku'] ?? ''),
                                    (string) ($product['upc'] ?? ''),
                                    (string) ($product['ean'] ?? ''),
                                    (string) ($product['gtin'] ?? ''),
                                ])));
                                ?>
                                <?= e($identifier !== '' ? $identifier : '—') ?>
                            </td>
                            <td><?= e($product['tipo_nombre'] ?? '') ?></td>
                            <td><?= e($product['unidad_codigo'] ?? '') ?></td>
                            <td><?= e($product['linea_nombre'] ?? '—') ?></td>
                            <td><?= e($product['marca_nombre'] ?? '—') ?></td>
                            <td>
                                <?= e($product['clasificacion_nombre'] ?? '—') ?>
                            </td>
                            <td>
                                <span class="product-status<?= $active ? ' is-active' : '' ?>">
                                    <?= $active ? 'Activo' : 'Inactivo' ?>
                                </span>
                            </td>
                            <td>
                                <div class="product-row-actions">
                                    <?php if (($abilities['ver'] ?? false) === true): ?>
                                        <a
                                            class="product-text-action"
                                            href="/productos/ver?id_producto=<?= e(rawurlencode($productId)) ?>"
                                        >Ver</a>
                                    <?php endif; ?>
                                    <?php if (($abilities['editar'] ?? false) === true): ?>
                                        <a
                                            class="product-text-action"
                                            href="/productos/editar?id_producto=<?= e(rawurlencode($productId)) ?>"
                                        >Editar</a>
                                    <?php endif; ?>
                                    <?php if (($abilities['estado'] ?? false) === true): ?>
                                        <form
                                            method="post"
                                            action="/productos/<?= $active ? 'desactivar' : 'activar' ?>"
                                        >
                                            <?= csrf_field($csrf) ?>
                                            <input
                                                type="hidden"
                                                name="id_producto"
                                                value="<?= e($productId) ?>"
                                            >
                                            <button
                                                class="button button--secondary button--compact"
                                                type="submit"
                                            >
                                                <?= $active ? 'Desactivar' : 'Activar' ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
