<?php

declare(strict_types=1);

if (!is_array($errors ?? null)
    || !is_array($filters ?? null)
    || !is_array($pagination ?? null)
    || !is_array($rows ?? null)
    || !is_array($summary ?? null)
    || !is_array($warehouses ?? null)
) {
    throw new RuntimeException('Inventory serial kardex index context is incomplete.');
}

$hasActiveContext = ($hasActiveContext ?? false) === true;
$selectedSeries = ($summary['selected_series'] ?? false) === true;
?>
<section class="serial-kardex-heading">
    <div>
        <p><a href="/app">Inicio</a> / Inventario</p>
        <h1>Kardex por serie</h1>
        <p>
            Historial read-only de movimientos aplicados por número de serie.
            Las entradas, salidas y transferencias se leen desde movimientos.
        </p>
    </div>
</section>

<?php if (!$hasActiveContext): ?>
    <div class="serial-kardex-alert" role="alert">
        No hay contexto activo de empresa y almacén. Selecciona un contexto antes
        de consultar kardex por serie.
    </div>
<?php endif; ?>

<?php if ($errors !== []): ?>
    <div class="serial-kardex-alert" role="alert">
        <?php foreach ($errors as $message): ?>
            <p><?= e((string) $message) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<section class="serial-kardex-panel" aria-labelledby="serial-kardex-filters-title">
    <div class="serial-kardex-section-heading">
        <div>
            <h2 id="serial-kardex-filters-title">Filtros</h2>
            <p>La consulta respeta la empresa activa y almacenes autorizados del contexto.</p>
        </div>
    </div>
    <form class="serial-kardex-filters" method="get" action="/inventario/kardex-series">
        <label class="serial-kardex-field">
            <span>Búsqueda</span>
            <input
                type="search"
                name="q"
                value="<?= e((string) ($filters['search'] ?? '')) ?>"
                placeholder="Serie, producto, descripción o referencia"
            >
        </label>
        <label class="serial-kardex-field">
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
        <label class="serial-kardex-field">
            <span>Número de serie</span>
            <input
                type="search"
                name="numero_serie"
                value="<?= e((string) ($filters['serial_number'] ?? '')) ?>"
                placeholder="S001 o parcial"
            >
        </label>
        <label class="serial-kardex-field">
            <span>Estado actual</span>
            <select name="estado_actual">
                <option value="">Todos</option>
                <option value="EN_EXISTENCIA" <?= ($filters['current_status'] ?? '') === 'EN_EXISTENCIA' ? 'selected' : '' ?>>
                    En existencia
                </option>
                <option value="FUERA_EXISTENCIA" <?= ($filters['current_status'] ?? '') === 'FUERA_EXISTENCIA' ? 'selected' : '' ?>>
                    Fuera de existencia
                </option>
            </select>
        </label>
        <label class="serial-kardex-field">
            <span>Almacén del movimiento</span>
            <select name="almacen_id">
                <option value="">Todos de la empresa activa</option>
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
        <label class="serial-kardex-field">
            <span>Desde</span>
            <input type="date" name="fecha_desde" value="<?= e((string) ($filters['date_from'] ?? '')) ?>">
        </label>
        <label class="serial-kardex-field">
            <span>Hasta</span>
            <input type="date" name="fecha_hasta" value="<?= e((string) ($filters['date_to'] ?? '')) ?>">
        </label>
        <div class="serial-kardex-filter-actions">
            <button class="button" type="submit">Filtrar</button>
            <a class="button button--secondary" href="/inventario/kardex-series">Limpiar</a>
        </div>
    </form>
</section>

<section class="serial-kardex-summary" aria-label="Resumen del kardex por serie">
    <article>
        <span><?= $selectedSeries ? 'Producto' : 'Series filtradas' ?></span>
        <strong>
            <?= $selectedSeries
                ? e((string) ($summary['id_producto'] ?? ''))
                : e((string) ($summary['total_series'] ?? 0)) ?>
        </strong>
        <small>
            <?= $selectedSeries
                ? e((string) ($summary['producto_descripcion'] ?? ''))
                : 'Resultados únicos por número de serie' ?>
        </small>
    </article>
    <article>
        <span><?= $selectedSeries ? 'Número de serie' : 'Movimientos' ?></span>
        <strong>
            <?= $selectedSeries
                ? e((string) ($summary['numero_serie'] ?? ''))
                : e((string) ($summary['total_movements'] ?? 0)) ?>
        </strong>
        <small>Total encontrado: <?= e((string) ($summary['total_movements'] ?? 0)) ?></small>
    </article>
    <article>
        <span>Estado actual</span>
        <?php $currentState = (string) ($summary['estado_actual'] ?? ''); ?>
        <strong>
            <?= $selectedSeries && $currentState !== ''
                ? e($currentState === 'EN_EXISTENCIA' ? 'En existencia' : 'Fuera de existencia')
                : '—' ?>
        </strong>
        <small>
            <?= $selectedSeries
                ? e((string) ($summary['almacen_actual_nombre'] ?? 'Sin almacén'))
                : 'Selecciona una serie para ver estado actual' ?>
        </small>
    </article>
    <article>
        <span>Último movimiento</span>
        <strong><?= e((string) ($summary['ultima_fecha_movimiento'] ?? '—')) ?></strong>
        <small>Fecha más reciente encontrada</small>
    </article>
</section>

<section class="serial-kardex-panel" aria-labelledby="serial-kardex-list-title">
    <div class="serial-kardex-section-heading">
        <div>
            <h2 id="serial-kardex-list-title">Movimientos por serie</h2>
            <p>Total: <?= e((string) ($pagination['total'] ?? 0)) ?></p>
        </div>
        <p class="serial-kardex-readonly">Solo lectura · sin acciones de escritura</p>
    </div>
    <div class="serial-kardex-table-wrap">
        <table class="serial-kardex-table">
            <caption class="sr-only">Historial de movimientos por número de serie</caption>
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Concepto</th>
                    <th>Entrada / salida</th>
                    <th>Movimiento</th>
                    <th>Referencia</th>
                    <th>Producto</th>
                    <th>Serie</th>
                    <th>Almacén movimiento</th>
                    <th>Estado actual</th>
                    <th>Almacén actual</th>
                    <th>Observaciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="11">
                            No hay historial de series para estos filtros. Esta vista no crea ni modifica series.
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $estado = (string) ($row['estado_actual'] ?? '');
                    $naturaleza = (string) ($row['naturaleza'] ?? '');
                    ?>
                    <tr>
                        <td><?= e((string) ($row['fecha_movimiento'] ?? '')) ?></td>
                        <td>
                            <strong><?= e((string) ($row['concepto_codigo'] ?? '')) ?></strong>
                            <span><?= e((string) ($row['concepto_nombre'] ?? '')) ?></span>
                        </td>
                        <td>
                            <span class="serial-kardex-badge <?= $naturaleza === 'ENTRADA' ? 'is-entry' : 'is-exit' ?>">
                                <?= e($naturaleza === 'ENTRADA' ? 'Entrada' : 'Salida') ?>
                            </span>
                        </td>
                        <td>#<?= e((string) ($row['movimiento_id'] ?? '')) ?></td>
                        <td><?= e((string) ($row['referencia'] ?? '')) ?></td>
                        <td>
                            <strong><?= e((string) ($row['id_producto'] ?? '')) ?></strong>
                            <span><?= e((string) ($row['producto_descripcion'] ?? '')) ?></span>
                        </td>
                        <td class="serial-kardex-code"><?= e((string) ($row['numero_serie'] ?? '')) ?></td>
                        <td><?= e((string) ($row['almacen_movimiento_nombre'] ?? '')) ?></td>
                        <td>
                            <span class="serial-kardex-badge <?= $estado === 'EN_EXISTENCIA' ? 'is-in-stock' : 'is-out-stock' ?>">
                                <?= e($estado === 'EN_EXISTENCIA' ? 'En existencia' : 'Fuera de existencia') ?>
                            </span>
                        </td>
                        <td>
                            <?= ($row['almacen_actual_id'] ?? null) === null
                                ? 'Sin almacén'
                                : e((string) ($row['almacen_actual_nombre'] ?? '')) ?>
                        </td>
                        <td><?= e((string) ($row['observaciones'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ((int) ($pagination['total_pages'] ?? 1) > 1): ?>
        <?php
        $page = (int) ($pagination['page'] ?? 1);
        $query = [
            'q' => $filters['search'] ?? '',
            'id_producto' => $filters['product_id'] ?? '',
            'numero_serie' => $filters['serial_number'] ?? '',
            'estado_actual' => $filters['current_status'] ?? '',
            'almacen_id' => $filters['warehouse_id'] ?? '',
            'fecha_desde' => $filters['date_from'] ?? '',
            'fecha_hasta' => $filters['date_to'] ?? '',
        ];
        $previousQuery = array_merge($query, ['page' => max(1, $page - 1)]);
        $nextQuery = array_merge($query, ['page' => $page + 1]);
        ?>
        <nav class="serial-kardex-pagination" aria-label="Paginación de kardex por serie">
            <a class="button button--secondary" href="/inventario/kardex-series?<?= e(http_build_query($previousQuery)) ?>">Anterior</a>
            <span>Página <?= e((string) $page) ?> de <?= e((string) ($pagination['total_pages'] ?? 1)) ?></span>
            <a class="button button--secondary" href="/inventario/kardex-series?<?= e(http_build_query($nextQuery)) ?>">Siguiente</a>
        </nav>
    <?php endif; ?>
</section>
