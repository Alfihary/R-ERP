<?php

declare(strict_types=1);

if (!is_array($errors ?? null)
    || !is_array($filters ?? null)
    || !is_array($pagination ?? null)
    || !is_array($rows ?? null)
    || !is_array($warehouses ?? null)
) {
    throw new RuntimeException('Inventory serial stock index context is incomplete.');
}

$hasActiveContext = ($hasActiveContext ?? false) === true;
?>
<section class="serial-stock-heading">
    <div>
        <p><a href="/app">Inicio</a> / Inventario</p>
        <h1>Existencias por serie</h1>
        <p>
            Consulta números de serie por producto, estado y almacén. Esta
            pantalla es solo lectura; los cambios se realizan desde movimientos
            y transferencias autorizadas.
        </p>
    </div>
</section>

<?php if (!$hasActiveContext): ?>
    <div class="serial-stock-alert" role="alert">
        No hay contexto activo de empresa y almacén. Selecciona un contexto antes
        de consultar existencias por serie.
    </div>
<?php endif; ?>

<?php if ($errors !== []): ?>
    <div class="serial-stock-alert" role="alert">
        <?php foreach ($errors as $message): ?>
            <p><?= e((string) $message) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<section class="serial-stock-panel" aria-labelledby="serial-stock-filters-title">
    <div class="serial-stock-section-heading">
        <div>
            <h2 id="serial-stock-filters-title">Filtros</h2>
            <p>La consulta respeta la empresa activa y el almacén activo permitido.</p>
        </div>
    </div>
    <form class="serial-stock-filters" method="get" action="/inventario/existencias-series">
        <label class="serial-stock-field">
            <span>Búsqueda</span>
            <input
                type="search"
                name="q"
                value="<?= e((string) ($filters['search'] ?? '')) ?>"
                placeholder="Serie, producto o descripción"
            >
        </label>
        <label class="serial-stock-field">
            <span>ID producto</span>
            <input
                type="text"
                name="id_producto"
                value="<?= e((string) ($filters['product_id'] ?? '')) ?>"
                placeholder="ABC123"
                maxlength="16"
                pattern="[A-Z0-9]{1,16}"
            >
        </label>
        <label class="serial-stock-field">
            <span>Estado</span>
            <select name="estado">
                <option value="">Todos</option>
                <option value="EN_EXISTENCIA" <?= ($filters['status'] ?? '') === 'EN_EXISTENCIA' ? 'selected' : '' ?>>
                    En existencia
                </option>
                <option value="FUERA_EXISTENCIA" <?= ($filters['status'] ?? '') === 'FUERA_EXISTENCIA' ? 'selected' : '' ?>>
                    Fuera de existencia
                </option>
            </select>
        </label>
        <label class="serial-stock-field">
            <span>Almacén</span>
            <select name="almacen_id">
                <option value="">Todos del contexto</option>
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
        <div class="serial-stock-filter-actions">
            <button class="button" type="submit">Filtrar</button>
            <a class="button button--secondary" href="/inventario/existencias-series">Limpiar</a>
        </div>
    </form>
</section>

<section class="serial-stock-panel" aria-labelledby="serial-stock-list-title">
    <div class="serial-stock-section-heading">
        <div>
            <h2 id="serial-stock-list-title">Series actuales</h2>
            <p>Total: <?= e((string) ($pagination['total'] ?? 0)) ?></p>
        </div>
        <p class="serial-stock-readonly">Solo lectura · sin acciones de escritura</p>
    </div>
    <div class="serial-stock-table-wrap">
        <table class="serial-stock-table">
            <caption class="sr-only">Listado de existencias por número de serie</caption>
            <thead>
                <tr>
                    <th>Producto</th>
                    <th>Serie</th>
                    <th>Estado</th>
                    <th>Almacén actual</th>
                    <th>Activo</th>
                    <th>Actualizado</th>
                    <th>Referencia</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="7">
                            No hay series para estos filtros. Esta vista no crea ni ajusta series.
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <?php $estado = (string) ($row['estado'] ?? ''); ?>
                    <tr>
                        <td>
                            <strong><?= e((string) ($row['id_producto'] ?? '')) ?></strong>
                            <span><?= e((string) ($row['descripcion'] ?? '')) ?></span>
                        </td>
                        <td class="serial-stock-code"><?= e((string) ($row['numero_serie'] ?? '')) ?></td>
                        <td>
                            <span class="serial-stock-badge <?= $estado === 'EN_EXISTENCIA' ? 'is-in-stock' : 'is-out-stock' ?>">
                                <?= e($estado === 'EN_EXISTENCIA' ? 'En existencia' : 'Fuera de existencia') ?>
                            </span>
                        </td>
                        <td>
                            <?= $row['almacen_id'] === null
                                ? 'Sin almacén'
                                : e((string) ($row['almacen_nombre'] ?? '')) ?>
                        </td>
                        <td><?= (int) ($row['activo'] ?? 0) === 1 ? 'Sí' : 'No' ?></td>
                        <td><?= e((string) ($row['existencia_actualizada_en'] ?? $row['actualizado_en'] ?? '')) ?></td>
                        <td>
                            <a href="/productos/ver?id=<?= e(rawurlencode((string) ($row['id_producto'] ?? ''))) ?>">
                                Ver producto
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ((int) ($pagination['total_pages'] ?? 1) > 1): ?>
        <?php
        $page = (int) ($pagination['page'] ?? 1);
        $previousQuery = [
            'q' => $filters['search'] ?? '',
            'id_producto' => $filters['product_id'] ?? '',
            'estado' => $filters['status'] ?? '',
            'almacen_id' => $filters['warehouse_id'] ?? '',
            'page' => max(1, $page - 1),
        ];
        $nextQuery = $previousQuery;
        $nextQuery['page'] = $page + 1;
        ?>
        <nav class="serial-stock-pagination" aria-label="Paginación de existencias por serie">
            <a class="button button--secondary" href="/inventario/existencias-series?<?= e(http_build_query($previousQuery)) ?>">Anterior</a>
            <span>Página <?= e((string) $page) ?> de <?= e((string) ($pagination['total_pages'] ?? 1)) ?></span>
            <a class="button button--secondary" href="/inventario/existencias-series?<?= e(http_build_query($nextQuery)) ?>">Siguiente</a>
        </nav>
    <?php endif; ?>
</section>
