<?php

declare(strict_types=1);

$result = is_array($result ?? null) ? $result : [];
$rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
$filters = is_array($result['filters'] ?? null) ? $result['filters'] : [];
$abilities = is_array($abilities ?? null) ? $abilities : [];
$companies = is_array($companies ?? null) ? $companies : [];
$warehouses = is_array($warehouses ?? null) ? $warehouses : [];
$documentTypes = is_array($documentTypes ?? null) ? $documentTypes : [];
$total = (int) ($result['total'] ?? 0);
$page = (int) ($result['page'] ?? 1);
$perPage = (int) ($result['per_page'] ?? 20);
$pages = max(1, (int) ceil($total / max(1, $perPage)));
?>
<section class="folio-page">
    <header class="folio-page__header">
        <div>
            <p class="eyebrow">Configuración operativa</p>
            <h1>Folios documentales</h1>
            <p>Administra series por empresa, almacén, tipo de documento y código de serie.</p>
        </div>
        <?php if (($abilities['crear'] ?? false) === true): ?>
            <a class="button" href="/configuracion/folios/crear">Crear serie</a>
        <?php endif; ?>
    </header>

    <div class="folio-rule">
        <strong>Formato por almacén:</strong>
        <code>{PREFIJO}-{ALMACEN}{NUMERO}</code>
        <span>Ejemplo: F-BO000001</span>
    </div>

    <?php if (is_string($notice ?? null) && $notice !== ''): ?>
        <div class="alert alert--success"><?= e($notice) ?></div>
    <?php endif; ?>

    <form class="folio-toolbar" method="get" action="/configuracion/folios">
        <label class="field">
            <span>Buscar</span>
            <input name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="Tipo, serie, prefijo, empresa o almacén">
        </label>
        <label class="field">
            <span>Empresa</span>
            <select name="empresa_id">
                <option value="">Todas</option>
                <?php foreach ($companies as $company): ?>
                    <option value="<?= e($company['id'] ?? '') ?>" <?= (int) ($filters['empresa_id'] ?? 0) === (int) ($company['id'] ?? 0) ? 'selected' : '' ?>>
                        <?= e($company['nombre'] ?? '') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field">
            <span>Almacén</span>
            <select name="almacen_id">
                <option value="">Todos</option>
                <?php foreach ($warehouses as $warehouse): ?>
                    <option value="<?= e($warehouse['id'] ?? '') ?>" <?= (int) ($filters['almacen_id'] ?? 0) === (int) ($warehouse['id'] ?? 0) ? 'selected' : '' ?>>
                        <?= e($warehouse['nombre'] ?? '') ?> · <?= e($warehouse['codigo'] ?? '') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field">
            <span>Tipo</span>
            <select name="tipo_documento">
                <option value="">Todos</option>
                <?php foreach ($documentTypes as $type): ?>
                    <option value="<?= e($type) ?>" <?= ($filters['tipo_documento'] ?? '') === $type ? 'selected' : '' ?>>
                        <?= e($type) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field">
            <span>Estado</span>
            <select name="activo">
                <?php foreach (['active' => 'Activas', 'inactive' => 'Inactivas', 'all' => 'Todas'] as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= ($filters['activo'] ?? '') === $value ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="button button--secondary" type="submit">Filtrar</button>
    </form>

    <div class="table-scroll folio-table-scroll" role="region" aria-label="Series documentales" tabindex="0">
        <table class="data-table folio-table">
            <thead>
                <tr>
                    <th>Empresa</th>
                    <th>Almacén</th>
                    <th>Tipo</th>
                    <th>Serie</th>
                    <th>Prefijo</th>
                    <th>Snapshot</th>
                    <th>Formato</th>
                    <th>Siguiente</th>
                    <th>Longitud</th>
                    <th>Reinicio</th>
                    <th>Año</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="13">No hay series para los filtros seleccionados.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= e($row['empresa_nombre'] ?? '') ?></td>
                        <td><strong><?= e($row['almacen_nombre'] ?? '') ?></strong><small><?= e($row['almacen_codigo'] ?? '') ?></small></td>
                        <td><code><?= e($row['tipo_documento'] ?? '') ?></code></td>
                        <td><code><?= e($row['codigo_serie'] ?? '') ?></code></td>
                        <td><code><?= e($row['prefijo'] ?? '') ?></code></td>
                        <td><code><?= e($row['codigo_almacen_snapshot'] ?? '') ?></code></td>
                        <td><code><?= e($row['formato'] ?? '') ?></code></td>
                        <td><?= e($row['siguiente_numero'] ?? '') ?></td>
                        <td><?= e($row['longitud'] ?? '') ?></td>
                        <td><?= (int) ($row['reinicio_anual'] ?? 0) === 1 ? 'Anual' : 'Continuo' ?></td>
                        <td><?= e($row['anio_actual'] ?? '—') ?></td>
                        <td>
                            <span class="badge <?= (int) ($row['activo'] ?? 0) === 1 ? 'badge--success' : 'badge--warning' ?>">
                                <?= (int) ($row['activo'] ?? 0) === 1 ? 'Activa' : 'Inactiva' ?>
                            </span>
                        </td>
                        <td class="actions">
                            <?php if (($abilities['ver'] ?? false) === true): ?>
                                <a class="button button--sm button--secondary" href="/configuracion/folios/ver?id=<?= e($row['id'] ?? '') ?>">Ver</a>
                            <?php endif; ?>
                            <?php if (($abilities['editar'] ?? false) === true): ?>
                                <a class="button button--sm button--secondary" href="/configuracion/folios/editar?id=<?= e($row['id'] ?? '') ?>">Editar</a>
                            <?php endif; ?>
                            <?php if (($abilities['desactivar'] ?? false) === true): ?>
                                <form method="post" action="/configuracion/folios/<?= (int) ($row['activo'] ?? 0) === 1 ? 'desactivar' : 'activar' ?>">
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
