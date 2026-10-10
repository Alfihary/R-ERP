<?php

declare(strict_types=1);

$result = is_array($result ?? null) ? $result : [];
$rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
$filters = is_array($result['filters'] ?? null) ? $result['filters'] : [];
$abilities = is_array($abilities ?? null) ? $abilities : [];
$total = (int) ($result['total'] ?? 0);
$page = max(1, (int) ($result['page'] ?? 1));
$perPage = max(1, (int) ($result['per_page'] ?? 20));
$pages = max(1, (int) ceil($total / $perPage));
$label = static function (array $row): string {
    return trim(implode(' ', array_filter([
        $row['primer_nombre'] ?? '', $row['segundo_nombre'] ?? '',
        $row['apellido_paterno'] ?? '', $row['apellido_materno'] ?? '',
    ]))) ?: 'Sin nombre visible';
};
?>
<section class="admin-users-page">
    <header class="admin-users-page__header">
        <div>
            <p class="eyebrow">Administración de acceso</p>
            <h1>Usuarios</h1>
            <p>Gestiona identidad, estado, alcance y roles con controles por acción.</p>
        </div>
        <?php if (($abilities['crear'] ?? false) === true): ?>
            <a class="button" href="/admin/usuarios/crear">Crear usuario</a>
        <?php endif; ?>
    </header>

    <?php if (is_string($notice ?? null) && $notice !== ''): ?>
        <div class="alert alert--success"><?= e($notice) ?></div>
    <?php endif; ?>

    <form class="admin-users-filters" method="get" action="/admin/usuarios">
        <label class="field"><span>Buscar</span><input name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="Username, email o nombre"></label>
        <label class="field"><span>Estado</span><select name="status">
            <?php foreach (['active' => 'Activos', 'inactive' => 'Inactivos', 'deleted' => 'Eliminados', 'all' => 'Todos'] as $value => $text): ?>
                <option value="<?= e($value) ?>" <?= ($filters['status'] ?? '') === $value ? 'selected' : '' ?>><?= e($text) ?></option>
            <?php endforeach; ?>
        </select></label>
        <label class="field"><span>Empresa</span><select name="company_id"><option value="0">Todas</option><?php foreach ($companies as $company): ?><option value="<?= e($company['id']) ?>" <?= (int) ($filters['company_id'] ?? 0) === (int) $company['id'] ? 'selected' : '' ?>><?= e($company['nombre']) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Almacén</span><select name="warehouse_id"><option value="0">Todos</option><?php foreach ($warehouses as $warehouse): ?><option value="<?= e($warehouse['id']) ?>" <?= (int) ($filters['warehouse_id'] ?? 0) === (int) $warehouse['id'] ? 'selected' : '' ?>><?= e($warehouse['nombre']) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Rol</span><select name="role_id"><option value="0">Todos</option><?php foreach ($roles as $role): ?><option value="<?= e($role['id']) ?>" <?= (int) ($filters['role_id'] ?? 0) === (int) $role['id'] ? 'selected' : '' ?>><?= e($role['nombre']) ?></option><?php endforeach; ?></select></label>
        <button class="button button--secondary" type="submit">Filtrar</button>
    </form>

    <div class="table-scroll" role="region" aria-label="Usuarios administrativos" tabindex="0">
        <table class="data-table admin-users-table">
            <thead><tr><th>Usuario</th><th>Nombre</th><th>Email</th><th>Empresa</th><th>Almacén</th><th>Roles</th><th>Estado</th><th>Último login</th><th>Acciones</th></tr></thead>
            <tbody>
            <?php if ($rows === []): ?><tr><td colspan="9">No hay usuarios para los filtros seleccionados.</td></tr><?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <?php $deleted = ($row['eliminado_en'] ?? null) !== null; $active = (int) ($row['activo'] ?? 0) === 1 && !$deleted; ?>
                <tr>
                    <td><strong><?= e($row['username'] ?? '') ?></strong></td>
                    <td><?= e($label($row)) ?></td>
                    <td><?= e($row['email'] ?? '') ?></td>
                    <td><?= e($row['empresa_nombre'] ?? '—') ?></td>
                    <td><?= e($row['almacen_nombre'] ?? '—') ?></td>
                    <td><span class="admin-users-roles"><?= e($row['roles'] ?? 'Sin rol') ?></span></td>
                    <td><span class="badge <?= $deleted ? 'badge--danger' : ($active ? 'badge--success' : 'badge--warning') ?>"><?= $deleted ? 'Eliminado' : ($active ? 'Activo' : 'Inactivo') ?></span></td>
                    <td><?= e($row['ultimo_acceso_en'] ?? 'Nunca') ?></td>
                    <td class="actions admin-users-actions">
                        <?php if (($abilities['editar'] ?? false) === true && !$deleted): ?><a class="button button--sm button--secondary" href="/admin/usuarios/<?= e($row['id']) ?>/editar">Editar</a><?php endif; ?>
                        <?php if (($abilities['estado'] ?? false) === true && !$deleted): ?><form method="post" action="/admin/usuarios/<?= e($row['id']) ?>/estado"><?= csrf_field($csrf) ?><input type="hidden" name="active" value="<?= $active ? '0' : '1' ?>"><button class="button button--sm button--ghost" type="submit"><?= $active ? 'Desactivar' : 'Activar' ?></button></form><?php endif; ?>
                        <?php if (($abilities['estado'] ?? false) === true && !$deleted): ?><form method="post" action="/admin/usuarios/<?= e($row['id']) ?>/eliminar"><?= csrf_field($csrf) ?><button class="button button--sm button--ghost" type="submit">Eliminar</button></form><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <footer class="pagination"><span>Total: <?= e($total) ?></span><span>Página <?= e($page) ?> de <?= e($pages) ?></span><?php if ($page > 1): ?><a href="/admin/usuarios?q=<?= e(rawurlencode((string) ($filters['q'] ?? ''))) ?>&status=<?= e($filters['status'] ?? 'active') ?>&page=<?= e($page - 1) ?>">Anterior</a><?php endif; ?><?php if ($page < $pages): ?><a href="/admin/usuarios?q=<?= e(rawurlencode((string) ($filters['q'] ?? ''))) ?>&status=<?= e($filters['status'] ?? 'active') ?>&page=<?= e($page + 1) ?>">Siguiente</a><?php endif; ?></footer>
</section>
