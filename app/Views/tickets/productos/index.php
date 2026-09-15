<?php

declare(strict_types=1);

if (!is_array($tickets ?? null)) {
    throw new RuntimeException('Product ticket index data is incomplete.');
}

$permissions = is_array($permissions ?? null) ? $permissions : [];
$canView = ($permissions['canView'] ?? false) === true;
$canCreate = ($permissions['canCreate'] ?? false) === true;
$listing = is_array($listing ?? null) ? $listing : [
    'items' => $tickets,
    'pagination' => [
        'page' => 1,
        'perPage' => 20,
        'total' => count($tickets),
        'totalPages' => 1,
    ],
    'filters' => [
        'folio' => '',
        'estado' => '',
        'empresa_id' => null,
        'almacen_id' => null,
        'fecha_desde' => '',
        'fecha_hasta' => '',
    ],
];
$filters = is_array($listing['filters'] ?? null) ? $listing['filters'] : [];
$pagination = is_array($listing['pagination'] ?? null) ? $listing['pagination'] : [];
$page = (int) ($pagination['page'] ?? 1);
$perPage = (int) ($pagination['perPage'] ?? 20);
$total = (int) ($pagination['total'] ?? count($tickets));
$totalPages = (int) ($pagination['totalPages'] ?? 1);
$states = [
    '' => 'Todos',
    'EN_REVISION' => 'En revisión',
    'RESUELTO_PARCIAL' => 'Resuelto parcial',
    'APROBADO' => 'Aprobado',
    'RECHAZADO' => 'Rechazado',
    'CANCELADO' => 'Cancelado',
];
$pageUrl = static function (int $targetPage) use ($filters, $perPage): string {
    $query = [];

    foreach (['folio', 'estado', 'empresa_id', 'almacen_id', 'fecha_desde', 'fecha_hasta'] as $field) {
        $value = $filters[$field] ?? '';

        if ($value !== null && trim((string) $value) !== '') {
            $query[$field] = (string) $value;
        }
    }

    $query['page'] = max(1, $targetPage);
    $query['per_page'] = $perPage;

    return '/tickets/productos?' . http_build_query($query);
};
?>
<div class="ticket-products ticket-products__page">
        <header class="page-heading ticket-products__hero">
            <div class="page-heading__eyebrow">
                <p class="page-heading__path">Solicitudes de alta de productos</p>
                <?php if ($canCreate): ?>
                    <a class="button" href="/tickets/productos/crear">Nuevo ticket</a>
                <?php else: ?>
                    <span class="ticket-products__permission-note">No tienes permiso para crear tickets.</span>
                <?php endif; ?>
            </div>
            <h1>Tickets de productos</h1>
            <p>
                Flujo documental para revisar partidas solicitadas sin crear productos,
                precios, inventario, compras ni proveedores reales.
            </p>
            <p class="alert alert--warning ticket-products__note">
                Este módulo es documental y no crea productos reales.
            </p>
        </header>

        <section class="home-section ticket-products__section" aria-labelledby="tickets-productos-filtros">
            <div class="home-section__heading">
                <div>
                    <p class="section-kicker">Consulta</p>
                    <h2 id="tickets-productos-filtros">Filtros</h2>
                </div>
            </div>

            <form class="ticket-products__filters" method="get" action="/tickets/productos">
                <label class="field" for="ticket-producto-folio">
                    <span>Folio</span>
                    <input id="ticket-producto-folio" name="folio" value="<?= e($filters['folio'] ?? '') ?>">
                </label>

                <label class="field" for="ticket-producto-estado">
                    <span>Estado</span>
                    <select id="ticket-producto-estado" name="estado">
                        <?php foreach ($states as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= (string) ($filters['estado'] ?? '') === $value ? 'selected' : '' ?>>
                                <?= e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="field" for="ticket-producto-empresa">
                    <span>Empresa ID</span>
                    <input id="ticket-producto-empresa" name="empresa_id" inputmode="numeric" value="<?= e($filters['empresa_id'] ?? '') ?>">
                </label>

                <label class="field" for="ticket-producto-almacen">
                    <span>Almacén ID</span>
                    <input id="ticket-producto-almacen" name="almacen_id" inputmode="numeric" value="<?= e($filters['almacen_id'] ?? '') ?>">
                </label>

                <label class="field" for="ticket-producto-fecha-desde">
                    <span>Desde</span>
                    <input id="ticket-producto-fecha-desde" name="fecha_desde" type="date" value="<?= e($filters['fecha_desde'] ?? '') ?>">
                </label>

                <label class="field" for="ticket-producto-fecha-hasta">
                    <span>Hasta</span>
                    <input id="ticket-producto-fecha-hasta" name="fecha_hasta" type="date" value="<?= e($filters['fecha_hasta'] ?? '') ?>">
                </label>

                <label class="field" for="ticket-producto-per-page">
                    <span>Por página</span>
                    <select id="ticket-producto-per-page" name="per_page">
                        <?php foreach ([10, 20, 50] as $option): ?>
                            <option value="<?= e((string) $option) ?>" <?= $perPage === $option ? 'selected' : '' ?>>
                                <?= e((string) $option) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <div class="ticket-products__filter-actions">
                    <button class="button" type="submit">Filtrar</button>
                    <a class="button button--secondary" href="/tickets/productos">Limpiar filtros</a>
                </div>
            </form>
        </section>

        <section class="home-section ticket-products__section" aria-labelledby="tickets-productos-listado">
            <div class="home-section__heading">
                <div>
                    <p class="section-kicker">Bandeja documental</p>
                    <h2 id="tickets-productos-listado">Listado</h2>
                </div>
            </div>
            <p class="ticket-products__result-summary">
                <?= e((string) $total) ?> resultado<?= $total === 1 ? '' : 's' ?> ·
                página <?= e((string) $page) ?> de <?= e((string) $totalPages) ?>
            </p>

            <?php if ($tickets === []): ?>
                <div class="empty-state ticket-products__empty">
                    <strong>No hay tickets de productos para mostrar.</strong>
                    <p>Ajusta los filtros o registra una solicitud documental para verla en esta bandeja.</p>
                </div>
            <?php else: ?>
                <div class="table-scroll ticket-products__table-wrap">
                    <table class="data-table ticket-products__table">
                        <caption>Tickets documentales de solicitud de alta de productos</caption>
                        <thead>
                            <tr>
                                <th scope="col">Folio</th>
                                <th scope="col">Estado</th>
                                <th scope="col">Empresa</th>
                                <th scope="col">Almacén</th>
                                <th scope="col">Solicitante</th>
                                <th scope="col">Fecha</th>
                                <th scope="col">Total</th>
                                <th scope="col">En revisión</th>
                                <th scope="col">Aprobadas</th>
                                <th scope="col">Rechazadas</th>
                                <th scope="col">Detalle</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tickets as $ticket): ?>
                                <?php if (!is_array($ticket)) {
                                    continue;
                                } ?>
                                <?php $estado = (string) ($ticket['estado'] ?? ''); ?>
                                <tr>
                                    <td><strong><?= e($ticket['folio'] ?? '') ?></strong></td>
                                    <td><span class="badge ticket-products__badge ticket-products__badge--<?= e(strtolower($estado)) ?>"><?= e($estado) ?></span></td>
                                    <td>
                                        <strong><?= e($ticket['empresa_nombre'] ?? '—') ?></strong>
                                        <span class="ticket-products__muted">#<?= e($ticket['empresa_id'] ?? '') ?></span>
                                    </td>
                                    <td>
                                        <strong><?= e($ticket['almacen_codigo'] ?? '—') ?></strong>
                                        <span class="ticket-products__muted"><?= e($ticket['almacen_nombre'] ?? '') ?></span>
                                    </td>
                                    <td>
                                        <strong><?= e($ticket['solicitante_nombre'] ?? '—') ?></strong>
                                        <span class="ticket-products__muted">#<?= e($ticket['solicitante_id'] ?? '') ?></span>
                                    </td>
                                    <td><?= e($ticket['created_at'] ?? '') ?></td>
                                    <td><?= e($ticket['total_partidas'] ?? '0') ?></td>
                                    <td><?= e($ticket['partidas_en_revision'] ?? '0') ?></td>
                                    <td><?= e($ticket['partidas_aprobadas'] ?? '0') ?></td>
                                    <td><?= e($ticket['partidas_rechazadas'] ?? '0') ?></td>
                                    <td>
                                        <?php if ($canView): ?>
                                            <a class="button button--sm button--secondary" href="/tickets/productos/<?= e((string) ($ticket['id'] ?? '')) ?>">
                                                Ver detalle
                                            </a>
                                        <?php else: ?>
                                            <span class="ticket-products__permission-note">Sin permiso de detalle</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <nav class="ticket-products__pagination" aria-label="Paginación de tickets de productos">
                    <?php if ($page > 1): ?>
                        <a class="button button--secondary" href="<?= e($pageUrl($page - 1)) ?>">Anterior</a>
                    <?php else: ?>
                        <span class="button button--secondary ticket-products__disabled-action" aria-disabled="true">Anterior</span>
                    <?php endif; ?>

                    <span class="ticket-products__page-indicator">
                        Página <?= e((string) $page) ?> de <?= e((string) $totalPages) ?>
                    </span>

                    <?php if ($page < $totalPages): ?>
                        <a class="button button--secondary" href="<?= e($pageUrl($page + 1)) ?>">Siguiente</a>
                    <?php else: ?>
                        <span class="button button--secondary ticket-products__disabled-action" aria-disabled="true">Siguiente</span>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        </section>
    </div>
