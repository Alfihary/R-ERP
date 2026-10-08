<?php

declare(strict_types=1);

if (!is_array($filters ?? null)
    || !is_array($pagination ?? null)
    || !is_array($transfers ?? null)
    || !is_array($warehouses ?? null)
) {
    throw new RuntimeException('Inventory transfer index context is incomplete.');
}

$hasActiveContext = ($hasActiveContext ?? false) === true;
$canCreateTransfer = ($canCreateTransfer ?? false) === true;
$notice = is_string($notice ?? null) ? $notice : null;
$errors = is_array($errors ?? null) ? $errors : [];
?>
<section class="transfer-heading">
    <div>
        <p><a href="/app">Inicio</a> / Inventario</p>
        <h1>Transferencias de inventario</h1>
        <p>
            Consulta transferencias aplicadas entre almacenes de la empresa activa.
            La aplicación de inventario ocurre exclusivamente mediante el servicio
            transaccional de transferencias.
        </p>
    </div>
    <?php if ($hasActiveContext && $canCreateTransfer): ?>
        <a class="button transfer-heading-action" href="/inventario/transferencias/crear">
            Nueva transferencia
        </a>
    <?php endif; ?>
</section>

<?php if ($notice !== null): ?>
    <div class="transfer-notice" role="status"><?= e($notice) ?></div>
<?php endif; ?>

<?php if (!$hasActiveContext): ?>
    <div class="transfer-alert" role="alert">
        No hay contexto activo de empresa y almacén. Selecciona un contexto antes
        de consultar o crear transferencias.
    </div>
<?php endif; ?>

<?php if ($errors !== []): ?>
    <div class="transfer-alert" role="alert">
        <?php foreach ($errors as $message): ?>
            <p><?= e((string) $message) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<section class="transfer-panel" aria-labelledby="transfer-filters-title">
    <div class="transfer-section-heading">
        <div>
            <h2 id="transfer-filters-title">Filtros</h2>
            <p>La consulta se limita a la empresa activa y a referencias TRF.</p>
        </div>
    </div>
    <form class="transfer-filters" method="get" action="/inventario/transferencias">
        <label class="transfer-field">
            <span>Folio o referencia</span>
            <input
                type="search"
                name="search"
                value="<?= e((string) ($filters['search'] ?? '')) ?>"
                placeholder="TRF-20260714"
            >
        </label>
        <label class="transfer-field">
            <span>Almacén origen/destino</span>
            <select name="warehouse_id">
                <option value="">Todos</option>
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
        <label class="transfer-field">
            <span>Desde</span>
            <input type="date" name="date_from" value="<?= e((string) ($filters['date_from'] ?? '')) ?>">
        </label>
        <label class="transfer-field">
            <span>Hasta</span>
            <input type="date" name="date_to" value="<?= e((string) ($filters['date_to'] ?? '')) ?>">
        </label>
        <div class="transfer-filter-actions">
            <button class="button" type="submit">Filtrar</button>
            <a class="button button--secondary" href="/inventario/transferencias">Limpiar</a>
        </div>
    </form>
</section>

<section class="transfer-panel" aria-labelledby="transfer-list-title">
    <div class="transfer-section-heading">
        <div>
            <h2 id="transfer-list-title">Historial aplicado</h2>
            <p>Total: <?= e((string) ($pagination['total'] ?? 0)) ?></p>
        </div>
    </div>
    <div class="transfer-table-wrap">
        <table class="transfer-table">
            <caption class="sr-only">Listado de transferencias de inventario</caption>
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Folio</th>
                    <th>Referencia</th>
                    <th>Origen</th>
                    <th>Destino</th>
                    <th>Partidas</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($transfers === []): ?>
                    <tr>
                        <td colspan="8">No hay transferencias para estos filtros.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($transfers as $transfer): ?>
                    <?php $reference = (string) ($transfer['referencia'] ?? ''); ?>
                    <tr>
                        <td><?= e((string) ($transfer['fecha_movimiento'] ?? '')) ?></td>
                        <td><?= e((string) ($transfer['folio'] ?? 'Sin folio')) ?></td>
                        <td><?= e($reference) ?></td>
                        <td><?= e((string) ($transfer['almacen_origen_nombre'] ?? '')) ?></td>
                        <td><?= e((string) ($transfer['almacen_destino_nombre'] ?? '')) ?></td>
                        <td><?= e((string) ($transfer['partidas'] ?? '0')) ?></td>
                        <td><span class="transfer-badge"><?= e((string) ($transfer['estado'] ?? 'APLICADA')) ?></span></td>
                        <td>
                            <a class="button button--secondary" href="/inventario/transferencias/ver?ref=<?= e(rawurlencode($reference)) ?>">
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
        <nav class="transfer-pagination" aria-label="Paginación de transferencias">
            <a class="button button--secondary" href="/inventario/transferencias?<?= e(http_build_query($previousQuery)) ?>">Anterior</a>
            <span>Página <?= e((string) $page) ?> de <?= e((string) ($pagination['total_pages'] ?? 1)) ?></span>
            <a class="button button--secondary" href="/inventario/transferencias?<?= e(http_build_query($nextQuery)) ?>">Siguiente</a>
        </nav>
    <?php endif; ?>
</section>
