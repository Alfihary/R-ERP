<?php

declare(strict_types=1);

$result = is_array($result ?? null) ? $result : [];
$rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
$filters = is_array($result['filters'] ?? null) ? $result['filters'] : [];
$abilities = is_array($abilities ?? null) ? $abilities : [];
$errors = is_array($errors ?? null) ? $errors : [];
$total = (int) ($result['total'] ?? 0);
$page = (int) ($result['page'] ?? 1);
$perPage = (int) ($result['per_page'] ?? 20);
$pages = max(1, (int) ceil($total / max(1, $perPage)));
?>
<section class="price-list-page">
    <header class="price-list-page__header">
        <div>
            <p class="eyebrow">Configuración de precios</p>
            <h1>Listas de precios</h1>
            <p>Administra listas globales. Esta pantalla no edita precios por producto.</p>
        </div>
        <?php if (($abilities['crear'] ?? false) === true): ?>
            <a class="button" href="/configuracion/listas-precios/crear">Crear lista</a>
        <?php endif; ?>
    </header>

    <?php if (is_string($notice ?? null) && $notice !== ''): ?>
        <div class="alert alert--success"><?= e($notice) ?></div>
    <?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="alert alert--danger"><?= e(implode(' ', array_values($errors))) ?></div>
    <?php endif; ?>

    <form class="price-list-toolbar" method="get" action="/configuracion/listas-precios">
        <label class="field">
            <span>Buscar</span>
            <input name="search" value="<?= e($filters['search'] ?? '') ?>" placeholder="Clave o nombre">
        </label>
        <label class="field">
            <span>Estado</span>
            <select name="status">
                <?php foreach (['active' => 'Activas', 'inactive' => 'Inactivas', 'all' => 'Todas'] as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= ($filters['status'] ?? '') === $value ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="button button--secondary" type="submit">Filtrar</button>
    </form>

    <div class="table-scroll price-list-table-scroll" role="region" aria-label="Listas de precios" tabindex="0">
        <table class="data-table price-list-table">
            <thead>
                <tr>
                    <th>Clave</th>
                    <th>Nombre</th>
                    <th>Incluye impuestos</th>
                    <th>Predeterminada</th>
                    <th>Estado</th>
                    <th>Actualizada</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="7">No hay listas para los filtros seleccionados.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <?php $active = (int) ($row['activo'] ?? 0) === 1; ?>
                    <?php $default = (int) ($row['es_predeterminada'] ?? 0) === 1; ?>
                    <tr>
                        <td><code><?= e($row['clave'] ?? '') ?></code></td>
                        <td>
                            <strong><?= e($row['nombre'] ?? '') ?></strong>
                            <?php if (is_string($row['observaciones'] ?? null) && $row['observaciones'] !== ''): ?>
                                <small><?= e($row['observaciones']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) ($row['incluye_impuestos'] ?? 0) === 1 ? 'Sí' : 'No' ?></td>
                        <td>
                            <span class="badge <?= $default ? 'badge--success' : 'badge--neutral' ?>">
                                <?= $default ? 'Predeterminada' : 'No' ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge <?= $active ? 'badge--success' : 'badge--warning' ?>">
                                <?= $active ? 'Activa' : 'Inactiva' ?>
                            </span>
                        </td>
                        <td><?= e($row['actualizado_en'] ?? $row['creado_en'] ?? '') ?></td>
                        <td class="actions">
                            <?php if (($abilities['ver'] ?? false) === true): ?>
                                <a class="button button--sm button--secondary" href="/configuracion/listas-precios/ver?id=<?= e($row['id'] ?? '') ?>">Ver</a>
                            <?php endif; ?>
                            <?php if (($abilities['editar'] ?? false) === true): ?>
                                <a class="button button--sm button--secondary" href="/configuracion/listas-precios/editar?id=<?= e($row['id'] ?? '') ?>">Editar</a>
                            <?php endif; ?>
                            <?php if (($abilities['predeterminada'] ?? false) === true && $active && !$default): ?>
                                <form method="post" action="/configuracion/listas-precios/predeterminada">
                                    <?= csrf_field($csrf) ?>
                                    <input type="hidden" name="id" value="<?= e($row['id'] ?? '') ?>">
                                    <button class="button button--sm button--ghost" type="submit">Predeterminada</button>
                                </form>
                            <?php endif; ?>
                            <?php if (($abilities['activar'] ?? false) === true): ?>
                                <form method="post" action="/configuracion/listas-precios/<?= $active ? 'desactivar' : 'activar' ?>">
                                    <?= csrf_field($csrf) ?>
                                    <input type="hidden" name="id" value="<?= e($row['id'] ?? '') ?>">
                                    <button class="button button--sm button--ghost" type="submit" <?= $default && $active ? 'disabled' : '' ?>>
                                        <?= $active ? 'Desactivar' : 'Activar' ?>
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
