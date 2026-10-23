<?php

declare(strict_types=1);

use App\Domain\Products\History\ProductHistoryPage;

if (
    !is_array($product ?? null)
    || !isset($page)
    || !($page instanceof ProductHistoryPage)
    || !is_array($publicFilters ?? null)
) {
    throw new RuntimeException('Product history view data is incomplete.');
}

$productId = (string) ($product['id_producto'] ?? '');
$description = trim((string) ($product['descripcion'] ?? ''));
$filters = $publicFilters;
$items = $page->items;
$hasFilters = false;

foreach ($filters as $value) {
    if ($value !== null && $value !== '') {
        $hasFilters = true;
        break;
    }
}

$filterValue = static function (array $values, string $key): string {
    $value = $values[$key] ?? '';
    return is_scalar($value) ? trim((string) $value) : '';
};

$pageUrl = static function (int $targetPage) use ($productId, $filters, $page): string {
    $query = ['id_producto' => $productId];

    foreach (['action', 'subresource', 'actor_id', 'date_from', 'date_to'] as $key) {
        $value = $filters[$key] ?? null;
        if (is_scalar($value) && (string) $value !== '') {
            $query[$key] = (string) $value;
        }
    }

    $query['page'] = max(1, $targetPage);
    $query['page_size'] = $page->pageSize;

    return '/productos/historial/ver?' . http_build_query($query);
};

$completenessLabels = [
    'complete' => 'Completo',
    'partial' => 'Parcial',
    'unavailable' => 'No disponible',
];
$historyContract = require BASE_PATH . '/config/products_history.php';
$beforeAfterAllowlist = is_array($historyContract['before_after_allowlist'] ?? null)
    ? $historyContract['before_after_allowlist']
    : [];
$formatBeforeAfterValue = static function (
    ?array $state,
    string $field,
    array $definition
): string {
    if (!is_array($state) || !array_key_exists($field, $state)) {
        return 'No disponible';
    }

    $value = $state[$field];
    if ($value === null) {
        return 'Sin valor';
    }

    if (($definition['type'] ?? null) === 'bool') {
        if (is_bool($value)) {
            return $value ? 'Activo' : 'Inactivo';
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1 ? 'Activo' : 'Inactivo';
        }
        return 'No disponible';
    }

    return is_scalar($value) ? (string) $value : 'No disponible';
};
$safeCompleteness = static function (string $value) use ($completenessLabels): string {
    return $completenessLabels[$value] ?? 'No disponible';
};
$prepareDetail = static function (object $item) use ($beforeAfterAllowlist): array {
    $changedFields = is_array($item->changedFields) ? $item->changedFields : [];
    $comparisonFields = [];
    $changedLabels = [];

    if ($item->completeness !== 'unavailable') {
        foreach ($changedFields as $field) {
            if (is_string($field) && array_key_exists($field, $beforeAfterAllowlist)) {
                $comparisonFields[$field] = $beforeAfterAllowlist[$field];
                $changedLabels[] = (string) ($beforeAfterAllowlist[$field]['label'] ?? $field);
            }
        }
    }

    return [
        'changedLabels' => $changedLabels,
        'comparisonFields' => $comparisonFields,
    ];
};
?>
<header class="product-page-heading">
    <div>
        <p>
            <a href="/productos">Productos</a>
            / <a href="/productos/ver?id_producto=<?= e(rawurlencode($productId)) ?>">Producto</a>
            / Historial
        </p>
        <h1>Historial del producto</h1>
        <p>
            <strong><?= e($productId) ?></strong>
            <?php if ($description !== ''): ?>
                · <?= e($description) ?>
            <?php endif; ?>
        </p>
    </div>
    <div class="product-heading-actions">
        <a
            class="button button--secondary"
            href="/productos/ver?id_producto=<?= e(rawurlencode($productId)) ?>"
        >Volver al producto</a>
    </div>
</header>

<section class="product-filter-panel" aria-labelledby="product-history-filter-title">
    <div class="product-section-heading">
        <div>
            <h2 id="product-history-filter-title">Filtrar historial</h2>
            <p>Las reglas de compatibilidad y fechas las valida el servicio.</p>
        </div>
    </div>
    <form class="product-filters" method="get" action="/productos/historial/ver">
        <input type="hidden" name="id_producto" value="<?= e($productId) ?>">
        <input type="hidden" name="page_size" value="<?= e((string) $page->pageSize) ?>">

        <div class="product-field">
            <label for="history-action">Acción</label>
            <input
                id="history-action"
                name="action"
                type="text"
                value="<?= e($filterValue($filters, 'action')) ?>"
            >
        </div>
        <div class="product-field">
            <label for="history-subresource">Subrecurso</label>
            <input
                id="history-subresource"
                name="subresource"
                type="text"
                value="<?= e($filterValue($filters, 'subresource')) ?>"
            >
        </div>
        <div class="product-field">
            <label for="history-actor">Actor</label>
            <input
                id="history-actor"
                name="actor_id"
                type="number"
                min="1"
                value="<?= e($filterValue($filters, 'actor_id')) ?>"
            >
        </div>
        <div class="product-field">
            <label for="history-date-from">Desde</label>
            <input
                id="history-date-from"
                name="date_from"
                type="date"
                value="<?= e($filterValue($filters, 'date_from')) ?>"
            >
        </div>
        <div class="product-field">
            <label for="history-date-to">Hasta</label>
            <input
                id="history-date-to"
                name="date_to"
                type="date"
                value="<?= e($filterValue($filters, 'date_to')) ?>"
            >
        </div>
        <div class="product-filter-actions">
            <button class="button" type="submit">Aplicar filtros</button>
            <a
                class="button button--secondary"
                href="/productos/historial/ver?id_producto=<?= e(rawurlencode($productId)) ?>&page_size=<?= e((string) $page->pageSize) ?>"
            >Limpiar</a>
        </div>
    </form>
</section>

<section class="product-list" aria-labelledby="product-history-list-title">
    <div class="product-section-heading">
        <div>
            <h2 id="product-history-list-title">Eventos registrados</h2>
            <p><?= e((string) $page->total) ?> evento(s) encontrado(s).</p>
        </div>
        <?php if ($page->pages > 0): ?>
            <span class="product-status is-active">
                Página <?= e((string) $page->page) ?> de <?= e((string) $page->pages) ?>
            </span>
        <?php endif; ?>
    </div>

    <?php if ($items === []): ?>
        <div class="product-empty">
            <strong>
                <?= $hasFilters
                    ? 'No hay eventos que coincidan con estos filtros.'
                    : 'Este producto no tiene eventos de historial disponibles.' ?>
            </strong>
            <?php if ($hasFilters): ?>
                <p>Prueba con otros criterios o limpia los filtros.</p>
            <?php else: ?>
                <p>Los cambios auditables aparecerán aquí cuando existan.</p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="product-table-wrap history-table-view" tabindex="0">
            <table class="product-table">
                <caption class="sr-only">Historial de eventos del producto <?= e($productId) ?></caption>
                <thead>
                    <tr>
                        <th scope="col">Fecha y hora</th>
                        <th scope="col">Evento</th>
                        <th scope="col">Categoría</th>
                        <th scope="col">Resultado</th>
                        <th scope="col">Actor</th>
                        <th scope="col">Completitud</th>
                        <th scope="col">Detalle</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <?php
                        $detailId = 'history-detail-' . (string) $item->auditId;
                        $subresource = $item->subresource ?? 'producto';
                        $detail = $prepareDetail($item);
                        $changedFields = is_array($item->changedFields) ? $item->changedFields : [];
                        $comparisonFields = $detail['comparisonFields'];
                        ?>
                        <tr>
                            <td><?= e($item->occurredAt) ?></td>
                            <td>
                                <strong><?= e($item->summary) ?></strong>
                                <span><?= e($item->action) ?></span>
                            </td>
                            <td>
                                <span class="product-status"><?= e($item->category) ?></span>
                                <span><?= e($subresource) ?></span>
                            </td>
                            <td><?= e($item->result) ?></td>
                            <td><?= e($item->actorDisplay) ?></td>
                            <td>
                                <span class="product-status<?= $item->completeness === 'complete' ? ' is-active' : '' ?>">
                                    <?= e($safeCompleteness($item->completeness)) ?>
                                </span>
                            </td>
                            <td>
                                <details>
                                    <summary aria-controls="<?= e($detailId) ?>">Ver detalle</summary>
                                    <div id="<?= e($detailId) ?>">
                                        <?php if ($changedFields !== []): ?>
                                            <p><strong>Campos modificados</strong></p>
                                            <ul>
                                                <?php foreach ($detail['changedLabels'] as $label): ?>
                                                    <li><?= e($label) ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                        <?php if ($item->completeness === 'unavailable'): ?>
                                            <p>Los valores Antes / Después no están disponibles.</p>
                                        <?php elseif ($comparisonFields === []): ?>
                                            <p>No hay campos autorizados para mostrar.</p>
                                        <?php else: ?>
                                            <table>
                                                <caption>Estado del cambio</caption>
                                                <thead>
                                                    <tr>
                                                        <th scope="col">Campo</th>
                                                        <th scope="col">Antes</th>
                                                        <th scope="col">Después</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($comparisonFields as $field => $definition): ?>
                                                        <tr>
                                                            <th scope="row"><?= e((string) ($definition['label'] ?? $field)) ?></th>
                                                            <td><?= e($formatBeforeAfterValue($item->before, $field, $definition)) ?></td>
                                                            <td><?= e($formatBeforeAfterValue($item->after, $field, $definition)) ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        <?php endif; ?>
                                        <p>
                                            Los valores internos de auditoría no se muestran en esta vista.
                                        </p>
                                    </div>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="history-card-list" aria-label="Eventos del historial en formato compacto">
            <?php foreach ($items as $item): ?>
                <?php
                $detailId = 'history-card-detail-' . (string) $item->auditId;
                $subresource = $item->subresource ?? 'producto';
                $detail = $prepareDetail($item);
                $comparisonFields = $detail['comparisonFields'];
                ?>
                <article class="history-card">
                    <header class="history-card__header">
                        <div>
                            <p class="history-card__eyebrow"><?= e($item->occurredAt) ?></p>
                            <h3><?= e($item->summary) ?></h3>
                        </div>
                        <span class="product-status<?= $item->completeness === 'complete' ? ' is-active' : '' ?>">
                            <?= e($safeCompleteness($item->completeness)) ?>
                        </span>
                    </header>
                    <dl class="history-card__meta">
                        <div><dt>Actor</dt><dd><?= e($item->actorDisplay) ?></dd></div>
                        <div><dt>Resultado</dt><dd><?= e($item->result) ?></dd></div>
                        <div><dt>Subrecurso</dt><dd><?= e($subresource) ?></dd></div>
                        <div><dt>Acción</dt><dd><?= e($item->action) ?></dd></div>
                    </dl>
                    <details>
                        <summary aria-controls="<?= e($detailId) ?>">Ver cambios</summary>
                        <div id="<?= e($detailId) ?>" class="history-card__details">
                            <?php if ($item->completeness === 'unavailable'): ?>
                                <p>Los valores Antes / Después no están disponibles.</p>
                            <?php elseif ($comparisonFields === []): ?>
                                <p>No hay campos autorizados para mostrar.</p>
                            <?php else: ?>
                                <div class="history-card__comparison">
                                    <?php foreach ($comparisonFields as $field => $definition): ?>
                                        <div class="history-card__comparison-row">
                                            <strong><?= e((string) ($definition['label'] ?? $field)) ?></strong>
                                            <dl>
                                                <div><dt>Antes</dt><dd><?= e($formatBeforeAfterValue($item->before, $field, $definition)) ?></dd></div>
                                                <div><dt>Después</dt><dd><?= e($formatBeforeAfterValue($item->after, $field, $definition)) ?></dd></div>
                                            </dl>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($detail['changedLabels'] !== []): ?>
                                <p><strong>Campos modificados:</strong> <?= e(implode(', ', $detail['changedLabels'])) ?></p>
                            <?php endif; ?>
                            <p>Los valores internos de auditoría no se muestran en esta vista.</p>
                        </div>
                    </details>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($page->pages > 1): ?>
        <nav class="product-pagination" aria-label="Paginación del historial">
            <?php if ($page->page > 1): ?>
                <a class="button button--secondary" href="<?= e($pageUrl($page->page - 1)) ?>">Anterior</a>
            <?php endif; ?>
            <span>Página <?= e((string) $page->page) ?> de <?= e((string) $page->pages) ?></span>
            <?php if ($page->page < $page->pages): ?>
                <a class="button button--secondary" href="<?= e($pageUrl($page->page + 1)) ?>">Siguiente</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
