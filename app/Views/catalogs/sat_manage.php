<?php

declare(strict_types=1);

use App\Support\Security\CsrfTokenService;

if (!$csrf instanceof CsrfTokenService
    || !is_array($abilities ?? null)
    || !is_array($definition ?? null)
    || !is_array($errors ?? null)
    || !is_array($filters ?? null)
    || !is_array($formData ?? null)
    || !is_array($pagination ?? null)
    || !is_array($records ?? null)
    || !is_array($viewableCatalogs ?? null)
    || !is_string($type ?? null)
) {
    throw new RuntimeException('SAT catalog data is incomplete.');
}

$mode = is_string($mode ?? null) ? $mode : null;
$record = is_array($record ?? null) ? $record : null;
$notice = is_string($notice ?? null) ? $notice : null;
$slug = (string) ($definition['slug'] ?? '');
$title = (string) ($definition['title'] ?? '');
$singular = (string) ($definition['singular'] ?? 'registro');
$isKeys = $type === 'claves_sat';
$values = $mode === 'update' && $record !== null ? $record : $formData;
$search = (string) ($filters['search'] ?? '');
$status = (string) ($filters['status'] ?? 'all');
$queryBase = http_build_query(array_filter([
    'search' => $search !== '' ? $search : null,
    'status' => $status !== 'all' ? $status : null,
]));
?>
<header class="catalog-page-heading">
    <p><a href="/catalogos">Catálogos</a> / <?= e($title) ?></p>
    <h1><?= e($title) ?></h1>
    <p>
        Catálogo SAT estructural. No se relaciona con productos, CFDI,
        facturación ni inventario en esta fase.
    </p>
</header>

<nav class="catalog-tabs" aria-label="Catálogos disponibles">
    <?php foreach ($viewableCatalogs as $key => $catalog): ?>
        <a
            href="/catalogos/<?= e($catalog['slug'] ?? '') ?>"
            <?= $key === $type ? 'aria-current="page"' : '' ?>
        >
            <?= e($catalog['title'] ?? '') ?>
        </a>
    <?php endforeach; ?>
</nav>

<?php if ($notice !== null): ?>
    <p class="catalog-notice" role="status"><?= e($notice) ?></p>
<?php endif; ?>

<?php if ($errors !== []): ?>
    <div class="catalog-alert" role="alert">
        <strong>No fue posible guardar el cambio.</strong>
        <ul>
            <?php foreach ($errors as $message): ?>
                <li><?= e($message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<section class="catalog-list" aria-labelledby="sat-filter-title">
    <div class="catalog-section-heading">
        <div>
            <h2 id="sat-filter-title">Filtros</h2>
            <p>La búsqueda se resuelve del lado del servidor.</p>
        </div>
        <?php if (($abilities['crear'] ?? false) === true): ?>
            <a class="button" href="/catalogos/<?= e($slug) ?>/crear">
                Agregar <?= e($singular) ?>
            </a>
        <?php endif; ?>
    </div>

    <form class="catalog-form catalog-form--filters" method="get">
        <div class="catalog-field">
            <label for="sat-search">Buscar</label>
            <input
                id="sat-search"
                name="search"
                type="search"
                value="<?= e($search) ?>"
                placeholder="<?= $isKeys ? 'Código o descripción' : 'Código o nombre' ?>"
            >
        </div>
        <div class="catalog-field">
            <label for="sat-status">Estado</label>
            <select id="sat-status" name="status">
                <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>
                    Todos
                </option>
                <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>
                    Activos
                </option>
                <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>
                    Inactivos
                </option>
            </select>
        </div>
        <div class="catalog-form-actions">
            <button class="button button--secondary" type="submit">Filtrar</button>
            <a class="button button--ghost" href="/catalogos/<?= e($slug) ?>">
                Limpiar
            </a>
        </div>
    </form>
</section>

<?php if ($mode === 'create' || $mode === 'update'): ?>
    <section class="catalog-create" aria-labelledby="sat-form-title">
        <div class="catalog-section-heading">
            <div>
                <h2 id="sat-form-title">
                    <?= $mode === 'create' ? 'Agregar' : 'Editar' ?>
                    <?= e($singular) ?>
                </h2>
                <p>Usa solo datos estructurales autorizados para el catálogo.</p>
            </div>
        </div>
        <form
            class="catalog-form"
            method="post"
            action="/catalogos/<?= e($slug) ?><?= $mode === 'update' ? '/actualizar' : '' ?>"
        >
            <?= csrf_field($csrf) ?>
            <?php if ($mode === 'update' && $record !== null): ?>
                <input
                    type="hidden"
                    name="id"
                    value="<?= e((string) ($record['id'] ?? '')) ?>"
                >
            <?php endif; ?>
            <div class="catalog-field">
                <label for="sat-code">Código</label>
                <input
                    id="sat-code"
                    name="codigo"
                    required
                    maxlength="16"
                    value="<?= e((string) ($values['codigo'] ?? '')) ?>"
                >
            </div>
            <?php if ($isKeys): ?>
                <div class="catalog-field catalog-field--wide">
                    <label for="sat-description">Descripción</label>
                    <input
                        id="sat-description"
                        name="descripcion"
                        required
                        maxlength="255"
                        value="<?= e((string) ($values['descripcion'] ?? '')) ?>"
                    >
                </div>
            <?php else: ?>
                <div class="catalog-field">
                    <label for="sat-name">Nombre</label>
                    <input
                        id="sat-name"
                        name="nombre"
                        required
                        maxlength="120"
                        value="<?= e((string) ($values['nombre'] ?? '')) ?>"
                    >
                </div>
                <div class="catalog-field catalog-field--wide">
                    <label for="sat-description">Descripción</label>
                    <input
                        id="sat-description"
                        name="descripcion"
                        maxlength="255"
                        value="<?= e((string) ($values['descripcion'] ?? '')) ?>"
                    >
                </div>
            <?php endif; ?>
            <div class="catalog-form-actions">
                <button class="button" type="submit">
                    <?= $mode === 'create' ? 'Guardar' : 'Actualizar' ?>
                </button>
                <a class="button button--ghost" href="/catalogos/<?= e($slug) ?>">
                    Cancelar
                </a>
            </div>
        </form>
    </section>
<?php endif; ?>

<section class="catalog-list" aria-labelledby="sat-list-title">
    <div class="catalog-section-heading">
        <div>
            <h2 id="sat-list-title">Registros</h2>
            <p>Los cambios de estado conservan el registro y su auditoría.</p>
        </div>
        <?php if ($isKeys): ?>
            <span class="catalog-action-note">
                Total: <?= e((string) ($pagination['total'] ?? 0)) ?>
            </span>
        <?php endif; ?>
    </div>

    <div class="catalog-table-wrap">
        <table class="catalog-table">
            <caption class="sr-only">Listado de <?= e($title) ?></caption>
            <thead>
                <tr>
                    <th scope="col">Código</th>
                    <?php if (!$isKeys): ?>
                        <th scope="col">Nombre</th>
                    <?php endif; ?>
                    <th scope="col">Descripción</th>
                    <th scope="col">Estado</th>
                    <th scope="col">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($records === []): ?>
                    <tr>
                        <td colspan="<?= $isKeys ? '4' : '5' ?>">
                            <span class="catalog-empty-inline">
                                No hay registros para los filtros actuales.
                            </span>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($records as $row): ?>
                    <?php
                    $rowId = (int) ($row['id'] ?? 0);
                    $isActive = (int) ($row['activo'] ?? 0) === 1;
                    ?>
                    <tr>
                        <td><strong><?= e((string) ($row['codigo'] ?? '')) ?></strong></td>
                        <?php if (!$isKeys): ?>
                            <td><?= e((string) ($row['nombre'] ?? '')) ?></td>
                        <?php endif; ?>
                        <td><?= e((string) ($row['descripcion'] ?? '')) ?></td>
                        <td>
                            <span class="catalog-status<?= $isActive ? ' is-active' : '' ?>">
                                <?= $isActive ? 'Activo' : 'Inactivo' ?>
                            </span>
                        </td>
                        <td>
                            <div class="catalog-row-actions">
                                <?php if (($abilities['editar'] ?? false) === true): ?>
                                    <a
                                        class="button button--secondary button--compact"
                                        href="/catalogos/<?= e($slug) ?>/editar?id=<?= e((string) $rowId) ?>"
                                    >
                                        Editar
                                    </a>
                                <?php endif; ?>
                                <?php if (($abilities['estado'] ?? false) === true): ?>
                                    <form
                                        method="post"
                                        action="/catalogos/<?= e($slug) ?>/<?= $isActive ? 'desactivar' : 'activar' ?>"
                                    >
                                        <?= csrf_field($csrf) ?>
                                        <input
                                            type="hidden"
                                            name="id"
                                            value="<?= e((string) $rowId) ?>"
                                        >
                                        <button
                                            class="button button--secondary button--compact"
                                            type="submit"
                                        >
                                            <?= $isActive ? 'Desactivar' : 'Activar' ?>
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

    <?php if ($isKeys && (int) ($pagination['total_pages'] ?? 1) > 1): ?>
        <nav class="catalog-pagination" aria-label="Paginación de claves SAT">
            <?php
            $page = (int) ($pagination['page'] ?? 1);
            $totalPages = (int) ($pagination['total_pages'] ?? 1);
            $previous = http_build_query(array_filter([
                'search' => $search !== '' ? $search : null,
                'status' => $status !== 'all' ? $status : null,
                'page' => max(1, $page - 1),
            ]));
            $next = http_build_query(array_filter([
                'search' => $search !== '' ? $search : null,
                'status' => $status !== 'all' ? $status : null,
                'page' => min($totalPages, $page + 1),
            ]));
            ?>
            <a
                class="button button--secondary button--compact"
                href="/catalogos/<?= e($slug) ?>?<?= e($previous) ?>"
                aria-disabled="<?= $page <= 1 ? 'true' : 'false' ?>"
            >
                Anterior
            </a>
            <span>Página <?= e((string) $page) ?> de <?= e((string) $totalPages) ?></span>
            <a
                class="button button--secondary button--compact"
                href="/catalogos/<?= e($slug) ?>?<?= e($next) ?>"
                aria-disabled="<?= $page >= $totalPages ? 'true' : 'false' ?>"
            >
                Siguiente
            </a>
        </nav>
    <?php endif; ?>
</section>
