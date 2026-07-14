<?php

declare(strict_types=1);

if (!is_array($errors ?? null)
    || !is_array($filters ?? null)
    || !is_array($pagination ?? null)
    || !is_array($rows ?? null)
    || !is_array($warehouses ?? null)
) {
    throw new RuntimeException('Inventory kardex index context is incomplete.');
}

$hasActiveContext = ($hasActiveContext ?? false) === true;
$canViewMovement = ($canViewMovement ?? false) === true;
$product = is_array($product ?? null) ? $product : null;
$currentStock = is_string($currentStock ?? null) ? $currentStock : null;
?>
<section class="kardex-heading">
    <div>
        <p><a href="/app">Inicio</a> / Inventario</p>
        <h1>Kardex de inventario</h1>
        <p>
            Historial de movimientos aplicados por producto y almacén. Los saldos
            se leen desde movimientos; no se recalculan ni guardan existencias.
        </p>
    </div>
    <a class="button button--secondary" href="/inventario/movimientos/crear">
        Crear ajuste
    </a>
</section>

<?php if (!$hasActiveContext): ?>
    <div class="kardex-alert" role="alert">
        No hay contexto activo de empresa y almacén. Selecciona un contexto antes
        de consultar el kardex.
    </div>
<?php endif; ?>

<?php if ($errors !== []): ?>
    <div class="kardex-alert" role="alert">
        <?php foreach ($errors as $message): ?>
            <p><?= e((string) $message) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<section class="kardex-panel" aria-labelledby="kardex-filters-title">
    <div class="kardex-section-heading">
        <div>
            <h2 id="kardex-filters-title">Filtros</h2>
            <p>Selecciona un producto de inventario para consultar su historial.</p>
        </div>
    </div>
    <form
        class="kardex-filters"
        method="get"
        action="/inventario/kardex"
        data-kardex-form
        data-product-search-endpoint="/inventario/kardex/productos/buscar"
    >
        <label class="kardex-field kardex-field--product">
            <span>Producto</span>
            <input
                type="search"
                name="product_search"
                value="<?= e((string) ($filters['product_search'] ?? '')) ?>"
                placeholder="ID o descripción"
                autocomplete="off"
                data-kardex-product-search
            >
            <input
                type="hidden"
                name="product_id"
                value="<?= e((string) ($filters['product_id'] ?? '')) ?>"
                data-kardex-product-id
            >
            <small data-kardex-product-status>
                Escribe al menos 2 caracteres. Solo PRODUCTO y KIT.
            </small>
            <div
                class="kardex-search-results"
                role="listbox"
                aria-label="Resultados de productos"
                data-kardex-product-results
                hidden
            ></div>
        </label>
        <label class="kardex-field">
            <span>Almacén</span>
            <select name="warehouse_id">
                <?php foreach ($warehouses as $warehouse): ?>
                    <?php $warehouseId = (int) ($warehouse['id'] ?? 0); ?>
                    <option
                        value="<?= e((string) $warehouseId) ?>"
                        <?= (int) ($filters['warehouse_id'] ?? 0) === $warehouseId ? 'selected' : '' ?>
                    >
                        <?= e((string) ($warehouse['nombre'] ?? '')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="kardex-field">
            <span>Desde</span>
            <input type="date" name="date_from" value="<?= e((string) ($filters['date_from'] ?? '')) ?>">
        </label>
        <label class="kardex-field">
            <span>Hasta</span>
            <input type="date" name="date_to" value="<?= e((string) ($filters['date_to'] ?? '')) ?>">
        </label>
        <label class="kardex-field">
            <span>Concepto</span>
            <select name="concept">
                <option value="">Todos</option>
                <option value="ENTRADA_AJUSTE" <?= ($filters['concept'] ?? '') === 'ENTRADA_AJUSTE' ? 'selected' : '' ?>>Entrada ajuste</option>
                <option value="SALIDA_AJUSTE" <?= ($filters['concept'] ?? '') === 'SALIDA_AJUSTE' ? 'selected' : '' ?>>Salida ajuste</option>
            </select>
        </label>
        <label class="kardex-field">
            <span>Naturaleza</span>
            <select name="nature">
                <option value="">Todas</option>
                <option value="ENTRADA" <?= ($filters['nature'] ?? '') === 'ENTRADA' ? 'selected' : '' ?>>Entrada</option>
                <option value="SALIDA" <?= ($filters['nature'] ?? '') === 'SALIDA' ? 'selected' : '' ?>>Salida</option>
            </select>
        </label>
        <div class="kardex-filter-actions">
            <button class="button" type="submit">Consultar</button>
            <a class="button button--secondary" href="/inventario/kardex">Limpiar</a>
        </div>
    </form>
</section>

<?php if (($filters['product_id'] ?? '') === ''): ?>
    <section class="kardex-empty" aria-live="polite">
        <h2>Selecciona un producto para consultar su kardex.</h2>
        <p>No se carga el historial de todos los productos por defecto.</p>
    </section>
<?php else: ?>
    <section class="kardex-summary" aria-label="Resumen del producto">
        <article>
            <span>Producto</span>
            <strong><?= e((string) ($product['id_producto'] ?? $filters['product_id'])) ?></strong>
            <small><?= e((string) ($product['descripcion'] ?? '')) ?></small>
        </article>
        <article>
            <span>Tipo</span>
            <strong><?= e((string) ($product['tipo_codigo'] ?? '')) ?></strong>
        </article>
        <article>
            <span>Saldo actual materializado</span>
            <strong class="kardex-number"><?= e($currentStock ?? '0.000000') ?></strong>
            <small>Solo lectura desde existencias</small>
        </article>
    </section>

    <section class="kardex-panel" aria-labelledby="kardex-list-title">
        <div class="kardex-section-heading">
            <div>
                <h2 id="kardex-list-title">Movimientos aplicados</h2>
                <p>Total: <?= e((string) ($pagination['total'] ?? 0)) ?></p>
            </div>
            <p class="kardex-readonly">Solo lectura · movimientos son la verdad histórica</p>
        </div>
        <div class="kardex-table-wrap">
            <table class="kardex-table">
                <caption class="sr-only">Kardex histórico por producto y almacén</caption>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Movimiento</th>
                        <th>Concepto</th>
                        <th>Referencia</th>
                        <th>Entrada</th>
                        <th>Salida</th>
                        <th>Saldo resultante</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows === []): ?>
                        <tr>
                            <td colspan="9">
                                No hay movimientos aplicados para este producto en el almacén seleccionado.
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <?php $movementId = (int) ($row['movimiento_id'] ?? 0); ?>
                        <tr>
                            <td><?= e((string) ($row['fecha_movimiento'] ?? '')) ?></td>
                            <td>#<?= e((string) $movementId) ?></td>
                            <td>
                                <strong><?= e((string) ($row['concepto_codigo'] ?? '')) ?></strong>
                                <span><?= e((string) ($row['concepto_nombre'] ?? '')) ?></span>
                            </td>
                            <td><?= e((string) ($row['referencia'] ?? '')) ?></td>
                            <td class="kardex-number"><?= e((string) ($row['entrada'] ?? '—')) ?></td>
                            <td class="kardex-number"><?= e((string) ($row['salida'] ?? '—')) ?></td>
                            <td class="kardex-number"><?= e((string) ($row['saldo_resultante'] ?? '0.000000')) ?></td>
                            <td><span class="kardex-badge"><?= e((string) ($row['estado'] ?? '')) ?></span></td>
                            <td>
                                <?php if ($canViewMovement && $movementId > 0): ?>
                                    <a class="button button--secondary" href="/inventario/movimientos/ver?id=<?= e((string) $movementId) ?>">
                                        Ver movimiento
                                    </a>
                                <?php else: ?>
                                    <span class="kardex-muted">No disponible</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ((int) ($pagination['total_pages'] ?? 1) > 1): ?>
            <?php
            $page = (int) ($pagination['page'] ?? 1);
            $previousQuery = array_merge($filters, ['page' => max(1, $page - 1)]);
            $nextQuery = array_merge($filters, ['page' => $page + 1]);
            ?>
            <nav class="kardex-pagination" aria-label="Paginación de kardex">
                <a class="button button--secondary" href="/inventario/kardex?<?= e(http_build_query($previousQuery)) ?>">Anterior</a>
                <span>Página <?= e((string) $page) ?> de <?= e((string) ($pagination['total_pages'] ?? 1)) ?></span>
                <a class="button button--secondary" href="/inventario/kardex?<?= e(http_build_query($nextQuery)) ?>">Siguiente</a>
            </nav>
        <?php endif; ?>
    </section>
<?php endif; ?>
