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
<section class="config-page">
    <header class="config-page__header">
        <div>
            <p class="eyebrow">Configuración operativa</p>
            <h1>Empresas</h1>
            <p>
                Base administrativa para multiempresa, contexto activo y operación futura.
            </p>
        </div>
        <?php if (($abilities['crear'] ?? false) === true): ?>
            <a class="button" href="/configuracion/empresas/crear">Crear empresa</a>
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

    <form class="config-toolbar" method="get" action="/configuracion/empresas">
        <label class="field">
            <span>Buscar</span>
            <input
                name="search"
                value="<?= e($filters['search'] ?? '') ?>"
                placeholder="Código, nombre o RFC"
            >
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

    <div class="table-scroll" role="region" aria-label="Empresas configuradas" tabindex="0">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Código</th>
                    <th>Nombre</th>
                    <th>RFC</th>
                    <th>Contacto</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="6">No hay empresas para los filtros seleccionados.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><code><?= e($row['codigo'] ?? '') ?></code></td>
                        <td>
                            <strong><?= e($row['nombre'] ?? '') ?></strong>
                            <?php if (($row['razon_social'] ?? null) !== null): ?>
                                <small><?= e($row['razon_social']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= e($row['rfc'] ?? '—') ?></td>
                        <td><?= e($row['email'] ?? $row['telefono'] ?? '—') ?></td>
                        <td>
                            <span class="badge <?= (int) ($row['activo'] ?? 0) === 1 ? 'badge--success' : 'badge--warning' ?>">
                                <?= (int) ($row['activo'] ?? 0) === 1 ? 'Activa' : 'Inactiva' ?>
                            </span>
                        </td>
                        <td class="actions">
                            <?php if (($abilities['ver'] ?? false) === true): ?>
                                <a class="button button--sm button--secondary" href="/configuracion/empresas/ver?id=<?= e($row['id'] ?? '') ?>">Ver</a>
                            <?php endif; ?>
                            <?php if (($abilities['editar'] ?? false) === true): ?>
                                <a class="button button--sm button--secondary" href="/configuracion/empresas/editar?id=<?= e($row['id'] ?? '') ?>">Editar</a>
                            <?php endif; ?>
                            <?php if (($abilities['desactivar'] ?? false) === true): ?>
                                <form method="post" action="/configuracion/empresas/<?= (int) ($row['activo'] ?? 0) === 1 ? 'desactivar' : 'activar' ?>">
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
        <?php if ($page > 1): ?>
            <a href="/configuracion/empresas?search=<?= e(rawurlencode((string) ($filters['search'] ?? ''))) ?>&status=<?= e($filters['status'] ?? 'active') ?>&page=<?= e($page - 1) ?>">Anterior</a>
        <?php endif; ?>
        <?php if ($page < $pages): ?>
            <a href="/configuracion/empresas?search=<?= e(rawurlencode((string) ($filters['search'] ?? ''))) ?>&status=<?= e($filters['status'] ?? 'active') ?>&page=<?= e($page + 1) ?>">Siguiente</a>
        <?php endif; ?>
    </footer>
</section>
