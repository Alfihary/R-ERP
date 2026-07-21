<?php

declare(strict_types=1);

$result = is_array($result ?? null) ? $result : [];
$rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
$filters = is_array($result['filters'] ?? null) ? $result['filters'] : [];
$abilities = is_array($abilities ?? null) ? $abilities : [];
$companies = is_array($companies ?? null) ? $companies : [];
$errors = is_array($errors ?? null) ? $errors : [];
$total = (int) ($result['total'] ?? 0);
$page = (int) ($result['page'] ?? 1);
$perPage = (int) ($result['per_page'] ?? 20);
$pages = max(1, (int) ceil($total / max(1, $perPage)));
?>
<section class="config-page">
    <header class="config-page__header">
        <div>
            <p class="eyebrow">Configuración operativa</p>
            <h1>Almacenes</h1>
            <p>Base estable para inventario real y folios operativos por almacén.</p>
        </div>
        <?php if (($abilities['crear'] ?? false) === true): ?>
            <a class="button" href="/configuracion/almacenes/crear">Crear almacén</a>
        <?php endif; ?>
    </header>

    <?php if (is_string($notice ?? null) && $notice !== ''): ?>
        <div class="alert alert--success"><?= e($notice) ?></div>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="alert alert--danger">
            <?= e(implode(' ', array_values($errors))) ?>
        </div>
    <?php endif; ?>

    <form class="config-toolbar" method="get" action="/configuracion/almacenes">
        <label class="field">
            <span>Buscar</span>
            <input
                name="search"
                value="<?= e($filters['search'] ?? '') ?>"
                placeholder="Código, nombre o responsable"
            >
        </label>
        <label class="field">
            <span>Empresa</span>
            <select name="empresa_id">
                <option value="">Todas</option>
                <?php foreach ($companies as $company): ?>
                    <option value="<?= e($company['id'] ?? '') ?>" <?= (int) ($filters['company_id'] ?? 0) === (int) ($company['id'] ?? 0) ? 'selected' : '' ?>>
                        <?= e($company['nombre'] ?? '') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field">
            <span>Estado</span>
            <select name="status">
                <?php foreach (['active' => 'Activos', 'inactive' => 'Inactivos', 'all' => 'Todos'] as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= ($filters['status'] ?? '') === $value ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="button button--secondary" type="submit">Filtrar</button>
    </form>

    <div class="table-scroll" role="region" aria-label="Almacenes configurados" tabindex="0">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Código</th>
                    <th>Almacén</th>
                    <th>Empresa</th>
                    <th>Tipo</th>
                    <th>Operación</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="7">No hay almacenes para los filtros seleccionados.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><code><?= e($row['codigo'] ?? '') ?></code></td>
                        <td>
                            <strong><?= e($row['nombre'] ?? '') ?></strong>
                            <?php if ((int) ($row['es_principal'] ?? 0) === 1): ?>
                                <small>Principal</small>
                            <?php endif; ?>
                        </td>
                        <td><?= e($row['empresa_nombre'] ?? '') ?></td>
                        <td><?= e($row['tipo_almacen'] ?? '') ?></td>
                        <td>
                            <span class="flags">
                                <?= (int) ($row['permite_inventario'] ?? 0) === 1 ? 'Inventario' : '' ?>
                                <?= (int) ($row['permite_transferencias'] ?? 0) === 1 ? 'Transferencias' : '' ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge <?= (int) ($row['activo'] ?? 0) === 1 ? 'badge--success' : 'badge--warning' ?>">
                                <?= (int) ($row['activo'] ?? 0) === 1 ? 'Activo' : 'Inactivo' ?>
                            </span>
                        </td>
                        <td class="actions">
                            <?php if (($abilities['ver'] ?? false) === true): ?>
                                <a class="button button--sm button--secondary" href="/configuracion/almacenes/ver?id=<?= e($row['id'] ?? '') ?>">Ver</a>
                            <?php endif; ?>
                            <?php if (($abilities['editar'] ?? false) === true): ?>
                                <a class="button button--sm button--secondary" href="/configuracion/almacenes/editar?id=<?= e($row['id'] ?? '') ?>">Editar</a>
                            <?php endif; ?>
                            <?php if (($abilities['desactivar'] ?? false) === true): ?>
                                <form method="post" action="/configuracion/almacenes/<?= (int) ($row['activo'] ?? 0) === 1 ? 'desactivar' : 'activar' ?>">
                                    <?= csrf_field($csrf) ?>
                                    <input type="hidden" name="id" value="<?= e($row['id'] ?? '') ?>">
                                    <button class="button button--sm button--ghost" type="submit">
                                        <?= (int) ($row['activo'] ?? 0) === 1 ? 'Desactivar' : 'Activar' ?>
                                    </button>
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
