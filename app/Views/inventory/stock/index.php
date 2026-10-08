<?php

declare(strict_types=1);

if (!is_array($errors ?? null)
    || !is_array($filters ?? null)
    || !is_array($pagination ?? null)
    || !is_array($rows ?? null)
    || !is_array($summary ?? null)
    || !is_array($types ?? null)
    || !is_array($warehouses ?? null)
) {
    throw new RuntimeException('Inventory stock index context is incomplete.');
}

$hasActiveContext = ($hasActiveContext ?? false) === true;
?>
<section class="stock-heading">
    <div>
        <p><a href="/app">Inicio</a> / Inventario</p>
        <h1>Existencias de inventario</h1>
        <p>
            Consulta saldos materializados por almacén. Los ajustes se realizan
            únicamente desde movimientos de inventario.
        </p>
    </div>
    <a class="button button--secondary" href="/inventario/movimientos/crear">
        Crear ajuste
    </a>
</section>

<?php if (!$hasActiveContext): ?>
    <div class="stock-alert" role="alert">
        No hay contexto activo de empresa y almacén. Selecciona un contexto antes
        de consultar existencias.
    </div>
<?php endif; ?>

<?php if ($errors !== []): ?>
    <div class="stock-alert" role="alert">
        <?php foreach ($errors as $message): ?>
            <p><?= e((string) $message) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<section class="stock-summary" aria-label="Resumen de existencias">
    <article>
        <span>Con saldo positivo</span>
        <strong><?= e((string) ($summary['positive'] ?? 0)) ?></strong>
    </article>
    <article>
        <span>En cero</span>
        <strong><?= e((string) ($summary['zero'] ?? 0)) ?></strong>
    </article>
    <article>
        <span>Con saldo negativo</span>
        <strong><?= e((string) ($summary['negative'] ?? 0)) ?></strong>
    </article>
    <article>
        <span>Filas de existencia</span>
        <strong><?= e((string) ($summary['total'] ?? 0)) ?></strong>
    </article>
</section>

<section class="stock-panel" aria-labelledby="stock-filters-title">
    <div class="stock-section-heading">
        <div>
            <h2 id="stock-filters-title">Filtros</h2>
            <p>Por defecto se consulta únicamente el almacén activo.</p>
        </div>
    </div>
    <form class="stock-filters" method="get" action="/inventario/existencias">
        <label class="stock-field">
            <span>Producto</span>
            <input
                type="search"
                name="search"
                value="<?= e((string) ($filters['search'] ?? '')) ?>"
                placeholder="ID o descripción"
            >
        </label>
        <label class="stock-field">
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
        <label class="stock-field">
            <span>Tipo</span>
            <select name="type">
                <option value="">Todos</option>
                <?php foreach ($types as $type): ?>
                    <?php $code = (string) ($type['codigo'] ?? ''); ?>
                    <option
                        value="<?= e($code) ?>"
                        <?= ($filters['type'] ?? '') === $code ? 'selected' : '' ?>
                    >
                        <?= e((string) ($type['nombre'] ?? $code)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="stock-field">
            <span>Estado de saldo</span>
            <select name="balance_state">
                <option value="">Todos</option>
                <option value="positive" <?= ($filters['balance_state'] ?? '') === 'positive' ? 'selected' : '' ?>>Positivo</option>
                <option value="zero" <?= ($filters['balance_state'] ?? '') === 'zero' ? 'selected' : '' ?>>En cero</option>
                <option value="negative" <?= ($filters['balance_state'] ?? '') === 'negative' ? 'selected' : '' ?>>Negativo</option>
            </select>
        </label>
        <div class="stock-filter-actions">
            <button class="button" type="submit">Filtrar</button>
            <a class="button button--secondary" href="/inventario/existencias">Limpiar</a>
        </div>
    </form>
</section>

<section class="stock-panel" aria-labelledby="stock-list-title">
    <div class="stock-section-heading">
        <div>
            <h2 id="stock-list-title">Saldos materializados</h2>
            <p>Total: <?= e((string) ($pagination['total'] ?? 0)) ?></p>
        </div>
        <p class="stock-readonly">Solo lectura · movimientos son la verdad histórica</p>
    </div>
    <div class="stock-table-wrap">
        <table class="stock-table">
            <caption class="sr-only">Listado de existencias de inventario</caption>
            <thead>
                <tr>
                    <th>Almacén</th>
                    <th>Producto</th>
                    <th>Tipo</th>
                    <th>Cantidad actual</th>
                    <th>Actualizado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="6">
                            No hay existencias para estos filtros. No se crean saldos en cero desde esta pantalla.
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= e((string) ($row['almacen_nombre'] ?? '')) ?></td>
                        <td>
                            <strong><?= e((string) ($row['id_producto'] ?? '')) ?></strong>
                            <span><?= e((string) ($row['descripcion'] ?? '')) ?></span>
                        </td>
                        <td>
                            <span class="stock-badge"><?= e((string) ($row['tipo_codigo'] ?? '')) ?></span>
                        </td>
                        <td class="stock-quantity"><?= e((string) ($row['cantidad_actual'] ?? '0.000000')) ?></td>
                        <td><?= e((string) ($row['actualizado_en'] ?? '')) ?></td>
                        <td><span class="stock-muted">Solo lectura</span></td>
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
        <nav class="stock-pagination" aria-label="Paginación de existencias">
            <a class="button button--secondary" href="/inventario/existencias?<?= e(http_build_query($previousQuery)) ?>">Anterior</a>
            <span>Página <?= e((string) $page) ?> de <?= e((string) ($pagination['total_pages'] ?? 1)) ?></span>
            <a class="button button--secondary" href="/inventario/existencias?<?= e(http_build_query($nextQuery)) ?>">Siguiente</a>
        </nav>
    <?php endif; ?>
</section>
