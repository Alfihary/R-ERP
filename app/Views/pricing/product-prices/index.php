<?php

declare(strict_types=1);

$result = is_array($result ?? null) ? $result : [];
$rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
$filters = is_array($result['filters'] ?? null) ? $result['filters'] : [];
$abilities = is_array($abilities ?? null) ? $abilities : [];
$errors = is_array($errors ?? null) ? $errors : [];
$lists = is_array($lists ?? null) ? $lists : [];
$currencies = is_array($currencies ?? null) ? $currencies : [];
$total = (int) ($result['total'] ?? 0);
$page = (int) ($result['page'] ?? 1);
$perPage = (int) ($result['per_page'] ?? 20);
$pages = max(1, (int) ceil($total / max(1, $perPage)));
?>
<section class="product-price-page">
    <header class="product-price-page__header">
        <div>
            <p class="eyebrow">Precios</p>
            <h1>Precios por producto</h1>
            <p>Administra precios globales por producto y lista. No emite ventas ni autorizaciones.</p>
        </div>
        <?php if (($abilities['crear'] ?? false) === true): ?>
            <a class="button" href="/precios/productos/crear">Crear precio</a>
        <?php endif; ?>
    </header>

    <?php if (is_string($notice ?? null) && $notice !== ''): ?>
        <div class="alert alert--success"><?= e($notice) ?></div>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="alert alert--danger"><?= e(implode(' ', array_values($errors))) ?></div>
    <?php endif; ?>

    <form class="product-price-toolbar" method="get" action="/precios/productos">
        <label class="field">
            <span>Buscar</span>
            <input name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="Producto, descripción, SKU, UPC, EAN o GTIN">
        </label>
        <label class="field">
            <span>Lista</span>
            <select name="lista_precio_id">
                <option value="">Todas</option>
                <?php foreach ($lists as $list): ?>
                    <option value="<?= e($list['id'] ?? '') ?>" <?= (int) ($filters['lista_precio_id'] ?? 0) === (int) ($list['id'] ?? 0) ? 'selected' : '' ?>>
                        <?= e($list['clave'] ?? '') ?> · <?= e($list['nombre'] ?? '') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field">
            <span>Moneda</span>
            <select name="moneda_id">
                <option value="">Todas</option>
                <?php foreach ($currencies as $currency): ?>
                    <option value="<?= e($currency['id'] ?? '') ?>" <?= (int) ($filters['moneda_id'] ?? 0) === (int) ($currency['id'] ?? 0) ? 'selected' : '' ?>>
                        <?= e($currency['codigo'] ?? '') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field">
            <span>Estado</span>
            <select name="activo">
                <?php foreach (['active' => 'Activos', 'inactive' => 'Inactivos', 'all' => 'Todos'] as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= ($filters['activo'] ?? '') === $value ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field">
            <span>Revisión</span>
            <select name="requiere_revision">
                <?php foreach (['all' => 'Todos', 'yes' => 'En revisión', 'no' => 'Sin revisión'] as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= ($filters['requiere_revision'] ?? '') === $value ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="button button--secondary" type="submit">Filtrar</button>
    </form>

    <div class="table-scroll product-price-table-scroll" role="region" aria-label="Precios por producto" tabindex="0">
        <table class="data-table product-price-table">
            <thead>
                <tr>
                    <th>Producto</th>
                    <th>Lista</th>
                    <th>Precio lista</th>
                    <th>Precio mínimo</th>
                    <th>Moneda</th>
                    <th>Impuestos</th>
                    <th>Revisión</th>
                    <th>Estado</th>
                    <th>Actualizado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="10">No hay precios para los filtros seleccionados.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <?php $active = (int) ($row['activo'] ?? 0) === 1; ?>
                    <?php $review = (int) ($row['requiere_revision'] ?? 0) === 1; ?>
                    <tr>
                        <td>
                            <strong><?= e($row['id_producto'] ?? '') ?></strong>
                            <small><?= e($row['producto_descripcion'] ?? '') ?></small>
                        </td>
                        <td><code><?= e($row['lista_clave'] ?? '') ?></code><small><?= e($row['lista_nombre'] ?? '') ?></small></td>
                        <td class="numeric"><?= e($row['precio_lista'] ?? '') ?></td>
                        <td class="numeric"><?= e($row['precio_minimo'] ?? '') ?></td>
                        <td><code><?= e($row['moneda_codigo'] ?? '') ?></code></td>
                        <td><?= (int) ($row['incluye_impuestos'] ?? 0) === 1 ? 'Sí' : 'No' ?></td>
                        <td>
                            <span class="badge <?= $review ? 'badge--warning' : 'badge--success' ?>">
                                <?= $review ? 'En revisión' : 'Utilizable' ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge <?= $active ? 'badge--success' : 'badge--warning' ?>">
                                <?= $active ? 'Activo' : 'Inactivo' ?>
                            </span>
                        </td>
                        <td><?= e($row['actualizado_en'] ?? $row['creado_en'] ?? '') ?></td>
                        <td class="actions">
                            <?php if (($abilities['ver'] ?? false) === true): ?>
                                <a class="button button--sm button--secondary" href="/precios/productos/ver?id=<?= e($row['id'] ?? '') ?>">Ver</a>
                            <?php endif; ?>
                            <?php if (($abilities['editar'] ?? false) === true): ?>
                                <a class="button button--sm button--secondary" href="/precios/productos/editar?id=<?= e($row['id'] ?? '') ?>">Editar</a>
                            <?php endif; ?>
                            <?php if (($abilities['historial'] ?? false) === true): ?>
                                <a class="button button--sm button--secondary" href="/precios/productos/historial?id=<?= e($row['id'] ?? '') ?>">Historial</a>
                            <?php endif; ?>
                            <?php if ($active && ($abilities['desactivar'] ?? false) === true): ?>
                                <form method="post" action="/precios/productos/desactivar">
                                    <?= csrf_field($csrf) ?>
                                    <input type="hidden" name="id" value="<?= e($row['id'] ?? '') ?>">
                                    <input type="hidden" name="motivo_cambio" value="Desactivación desde UI global.">
                                    <button class="button button--sm button--ghost" type="submit">Desactivar</button>
                                </form>
                            <?php endif; ?>
                            <?php if (!$active && ($abilities['reactivar'] ?? false) === true): ?>
                                <form method="post" action="/precios/productos/reactivar">
                                    <?= csrf_field($csrf) ?>
                                    <input type="hidden" name="id" value="<?= e($row['id'] ?? '') ?>">
                                    <input type="hidden" name="motivo_cambio" value="Reactivación desde UI global.">
                                    <button class="button button--sm button--ghost" type="submit" <?= $review ? 'disabled' : '' ?>>Reactivar</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <footer class="pagination">
        <span>Total: <?= e($total) ?></span>
        <span>Página <?= e($page) ?> de <?= e($pages) ?></span>
    </footer>
</section>
