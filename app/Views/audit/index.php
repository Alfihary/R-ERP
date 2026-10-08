<?php

declare(strict_types=1);

$result = is_array($result ?? null) ? $result : [];
$rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
$filters = is_array($result['filters'] ?? null) ? $result['filters'] : [];
$total = (int) ($result['total'] ?? 0);
$page = (int) ($result['page'] ?? 1);
$perPage = (int) ($result['per_page'] ?? 25);
$pages = max(1, (int) ceil($total / max(1, $perPage)));
$permissionUsed = is_string($permissionUsed ?? null) ? $permissionUsed : '';

$queryString = static function (int $targetPage) use ($filters): string {
    $query = [];

    foreach ($filters as $key => $value) {
        if ($value === '' || $value === null || $key === 'page') {
            continue;
        }

        $query[$key] = $value;
    }

    $query['page'] = $targetPage;

    return http_build_query($query);
};
?>
<section class="audit-page">
    <header class="audit-page__header">
        <div>
            <p class="eyebrow">Seguridad del sistema</p>
            <h1>Auditoria</h1>
            <p>Consulta read-only de eventos registrados. Esta pantalla no edita, borra ni exporta auditoria.</p>
        </div>
        <span class="audit-page__permission">Permiso: <?= e($permissionUsed) ?></span>
    </header>

    <form class="audit-toolbar" method="get" action="/auditoria">
        <label class="field">
            <span>Accion</span>
            <input name="accion" value="<?= e($filters['accion'] ?? '') ?>" placeholder="credencial.qr.ver">
        </label>
        <label class="field">
            <span>Resultado</span>
            <input name="resultado" value="<?= e($filters['resultado'] ?? '') ?>" placeholder="ok, fail">
        </label>
        <label class="field">
            <span>Actor</span>
            <input
                inputmode="numeric"
                name="actor_usuario_id"
                value="<?= e($filters['actor_usuario_id'] ?? '') ?>"
                placeholder="ID usuario"
            >
        </label>
        <label class="field">
            <span>Desde</span>
            <input type="date" name="fecha_desde" value="<?= e($filters['fecha_desde'] ?? '') ?>">
        </label>
        <label class="field">
            <span>Hasta</span>
            <input type="date" name="fecha_hasta" value="<?= e($filters['fecha_hasta'] ?? '') ?>">
        </label>
        <label class="field field--wide">
            <span>Buscar</span>
            <input name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="accion, entidad, resultado o metadata">
        </label>
        <label class="field">
            <span>Por pagina</span>
            <select name="per_page">
                <?php foreach ([25, 50, 100] as $option): ?>
                    <option value="<?= e($option) ?>" <?= $perPage === $option ? 'selected' : '' ?>>
                        <?= e($option) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="audit-toolbar__actions">
            <button class="button button--secondary" type="submit">Filtrar</button>
            <a class="button button--ghost" href="/auditoria">Limpiar</a>
        </div>
    </form>

    <div class="audit-summary" aria-live="polite">
        <span>Total: <?= e($total) ?></span>
        <span>Pagina <?= e($page) ?> de <?= e($pages) ?></span>
        <span>Orden: mas recientes primero</span>
    </div>

    <div class="table-scroll audit-table-scroll" role="region" aria-label="Eventos de auditoria" tabindex="0">
        <table class="data-table audit-table">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Actor</th>
                    <th>Accion</th>
                    <th>Entidad</th>
                    <th>Resultado</th>
                    <th>IP</th>
                    <th>User agent</th>
                    <th>Metadata</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="8">No hay eventos para los filtros seleccionados.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= e($row['creado_en'] ?? '') ?></td>
                        <td>
                            <?php if (!empty($row['actor_usuario_id'])): ?>
                                <strong><?= e($row['actor_username'] ?? 'Usuario') ?></strong>
                                <small>#<?= e($row['actor_usuario_id']) ?></small>
                            <?php else: ?>
                                <span class="badge badge--neutral">Publico</span>
                            <?php endif; ?>
                        </td>
                        <td><code><?= e($row['accion'] ?? '') ?></code></td>
                        <td>
                            <span><?= e($row['entidad'] ?? '') ?></span>
                            <?php if (!empty($row['entidad_id'])): ?>
                                <small><?= e($row['entidad_id']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= ($row['resultado'] ?? '') === 'ok' ? 'badge--success' : 'badge--warning' ?>">
                                <?= e($row['resultado'] ?? '') ?>
                            </span>
                        </td>
                        <td><?= e($row['ip'] ?? '') ?></td>
                        <td class="audit-table__agent"><?= e($row['user_agent'] ?? '') ?></td>
                        <td class="audit-table__metadata">
                            <?php if (is_string($row['metadata_json'] ?? null) && $row['metadata_json'] !== ''): ?>
                                <details>
                                    <summary>Ver metadata</summary>
                                    <pre><?= e($row['metadata_json']) ?></pre>
                                </details>
                            <?php else: ?>
                                <span class="audit-muted">Sin metadata</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <nav class="pagination audit-pagination" aria-label="Paginacion de auditoria">
        <?php if ($page > 1): ?>
            <a class="button button--sm button--secondary" href="/auditoria?<?= e($queryString($page - 1)) ?>">
                Anterior
            </a>
        <?php endif; ?>
        <span>Pagina <?= e($page) ?> de <?= e($pages) ?></span>
        <?php if ($page < $pages): ?>
            <a class="button button--sm button--secondary" href="/auditoria?<?= e($queryString($page + 1)) ?>">
                Siguiente
            </a>
        <?php endif; ?>
    </nav>
</section>
