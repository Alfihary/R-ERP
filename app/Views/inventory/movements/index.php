<?php

declare(strict_types=1);

if (!is_array($filters ?? null)
    || !is_array($movements ?? null)
    || !is_array($pagination ?? null)
) {
    throw new RuntimeException('Inventory movement index context is incomplete.');
}

$hasActiveContext = ($hasActiveContext ?? false) === true;
$notice = is_string($notice ?? null) ? $notice : null;
?>
<section class="inventory-heading">
    <div>
        <p><a href="/app">Inicio</a> / Inventario</p>
        <h1>Movimientos de inventario</h1>
        <p>
            Consulta movimientos aplicados y registra ajustes manuales mediante
            el servicio transaccional de inventario.
        </p>
    </div>
    <?php if ($hasActiveContext): ?>
        <a class="button inventory-heading-action" href="/inventario/movimientos/crear">
            Nuevo ajuste
        </a>
    <?php endif; ?>
</section>

<?php if ($notice !== null): ?>
    <div class="inventory-notice" role="status"><?= e($notice) ?></div>
<?php endif; ?>

<?php if (!$hasActiveContext): ?>
    <div class="inventory-alert" role="alert">
        No hay contexto activo de empresa y almacén. Selecciona un contexto antes
        de consultar o crear movimientos.
    </div>
<?php endif; ?>

<section class="inventory-panel" aria-labelledby="inventory-filters-title">
    <div class="inventory-section-heading">
        <div>
            <h2 id="inventory-filters-title">Filtros</h2>
            <p>La consulta se limita al almacén activo.</p>
        </div>
    </div>
    <form class="inventory-filters" method="get" action="/inventario/movimientos">
        <label class="inventory-field">
            <span>Referencia o MOV</span>
            <input
                type="search"
                name="search"
                value="<?= e((string) ($filters['search'] ?? '')) ?>"
                placeholder="MOV-125 o referencia"
            >
        </label>
        <label class="inventory-field">
            <span>Concepto</span>
            <select name="concept">
                <option value="">Todos</option>
                <?php foreach ([
                    'ENTRADA_AJUSTE' => 'Entrada por ajuste',
                    'SALIDA_AJUSTE' => 'Salida por ajuste',
                ] as $code => $label): ?>
                    <option
                        value="<?= e($code) ?>"
                        <?= ($filters['concept'] ?? '') === $code ? 'selected' : '' ?>
                    >
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="inventory-field">
            <span>Estado</span>
            <select name="status">
                <option value="">Todos</option>
                <?php foreach (['APLICADO', 'BORRADOR', 'ANULADO'] as $state): ?>
                    <option
                        value="<?= e($state) ?>"
                        <?= ($filters['status'] ?? '') === $state ? 'selected' : '' ?>
                    >
                        <?= e($state) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="inventory-field">
            <span>Desde</span>
            <input type="date" name="date_from" value="<?= e((string) ($filters['date_from'] ?? '')) ?>">
        </label>
        <label class="inventory-field">
            <span>Hasta</span>
            <input type="date" name="date_to" value="<?= e((string) ($filters['date_to'] ?? '')) ?>">
        </label>
        <div class="inventory-filter-actions">
            <button class="button" type="submit">Filtrar</button>
            <a class="button button--secondary" href="/inventario/movimientos">Limpiar</a>
        </div>
    </form>
</section>

<section class="inventory-panel" aria-labelledby="inventory-list-title">
    <div class="inventory-section-heading">
        <div>
            <h2 id="inventory-list-title">Historial</h2>
            <p>Total: <?= e((string) ($pagination['total'] ?? 0)) ?></p>
        </div>
    </div>
    <div class="inventory-table-wrap">
        <table class="inventory-table">
            <caption class="sr-only">Listado de movimientos de inventario</caption>
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Movimiento</th>
                    <th>Concepto</th>
                    <th>Naturaleza</th>
                    <th>Almacén</th>
                    <th>Partidas</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($movements === []): ?>
                    <tr>
                        <td colspan="8">No hay movimientos para estos filtros.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($movements as $movement): ?>
                    <?php $id = (int) ($movement['id'] ?? 0); ?>
                    <tr>
                        <td><?= e((string) ($movement['fecha_movimiento'] ?? '')) ?></td>
                        <td>MOV-<?= e((string) $id) ?></td>
                        <td><?= e((string) ($movement['concepto_nombre'] ?? '')) ?></td>
                        <td><?= e((string) ($movement['naturaleza'] ?? '')) ?></td>
                        <td><?= e((string) ($movement['almacen_nombre'] ?? '')) ?></td>
                        <td><?= e((string) ($movement['partidas'] ?? '0')) ?></td>
                        <td><span class="inventory-badge"><?= e((string) ($movement['estado'] ?? '')) ?></span></td>
                        <td>
                            <a class="button button--secondary" href="/inventario/movimientos/ver?id=<?= e((string) $id) ?>">
                                Ver
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
        $previousQuery = array_merge($filters, ['page' => max(1, $page - 1)]);
        $nextQuery = array_merge($filters, ['page' => $page + 1]);
        ?>
        <nav class="inventory-pagination" aria-label="Paginación de movimientos">
            <a class="button button--secondary" href="/inventario/movimientos?<?= e(http_build_query($previousQuery)) ?>">Anterior</a>
            <span>Página <?= e((string) $page) ?> de <?= e((string) ($pagination['total_pages'] ?? 1)) ?></span>
            <a class="button button--secondary" href="/inventario/movimientos?<?= e(http_build_query($nextQuery)) ?>">Siguiente</a>
        </nav>
    <?php endif; ?>
</section>
