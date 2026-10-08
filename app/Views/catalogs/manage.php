<?php

declare(strict_types=1);

use App\Core\View;
use App\Support\Security\CsrfTokenService;

if (!$csrf instanceof CsrfTokenService
    || !is_string($catalog ?? null)
    || !is_array($catalogDefinition ?? null)
    || !is_array($abilities ?? null)
    || !is_array($records ?? null)
    || !is_array($viewableCatalogs ?? null)
    || !is_array($errors ?? null)
    || !is_array($formData ?? null)
) {
    throw new RuntimeException('Catalog management data is incomplete.');
}

$failedAction = is_string($failedAction ?? null) ? $failedAction : null;
$failedId = is_int($failedId ?? null) ? $failedId : null;
$notice = is_string($notice ?? null) ? $notice : null;
$slug = (string) ($catalogDefinition['slug'] ?? '');
$title = (string) ($catalogDefinition['title'] ?? '');
$singular = (string) ($catalogDefinition['singular'] ?? 'registro');
$createValues = $failedAction === 'create' ? $formData : [];
?>
<header class="catalog-page-heading">
    <p><a href="/catalogos">Catálogos</a> / <?= e($title) ?></p>
    <h1><?= e($title) ?></h1>
    <p>
        Catálogo global. La empresa y el almacén activos se muestran como
        contexto de sesión, pero no filtran estos registros.
    </p>
</header>

<nav class="catalog-tabs" aria-label="Catálogos disponibles">
    <?php foreach ($viewableCatalogs as $key => $definition): ?>
        <a
            href="/catalogos/<?= e($definition['slug'] ?? '') ?>"
            <?= $key === $catalog ? 'aria-current="page"' : '' ?>
        >
            <?= e($definition['title'] ?? '') ?>
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

<?php if (($abilities['crear'] ?? false) === true): ?>
    <section class="catalog-create" aria-labelledby="catalog-create-title">
        <div class="catalog-section-heading">
            <div>
                <h2 id="catalog-create-title">Agregar <?= e($singular) ?></h2>
                <p>Completa los campos obligatorios y guarda el registro.</p>
            </div>
        </div>

        <form
            class="catalog-form"
            method="post"
            action="/catalogos/<?= e($slug) ?>"
        >
            <?= csrf_field($csrf) ?>
            <?= View::render('catalogs/_form_fields', [
                'catalog' => $catalog,
                'fieldErrors' => $failedAction === 'create' ? $errors : [],
                'fieldPrefix' => 'create-' . $slug,
                'values' => $createValues,
            ]) ?>
            <div class="catalog-form-actions">
                <button class="button" type="submit">Guardar</button>
            </div>
        </form>
    </section>
<?php endif; ?>

<section class="catalog-list" aria-labelledby="catalog-list-title">
    <div class="catalog-section-heading">
        <div>
            <h2 id="catalog-list-title">Registros</h2>
            <p>Los cambios de estado conservan el registro y su auditoría.</p>
        </div>
    </div>

    <?php if ($records === []): ?>
        <p class="catalog-empty">
            Aún no hay registros. Usa el formulario superior para crear el
            primero.
        </p>
    <?php else: ?>
        <div class="catalog-table-wrap">
            <table class="catalog-table">
                <caption class="sr-only">Listado de <?= e($title) ?></caption>
                <thead>
                    <tr>
                        <th scope="col">Código</th>
                        <th scope="col">Nombre</th>
                        <?php if ($catalog === 'monedas'): ?>
                            <th scope="col">Símbolo</th>
                            <th scope="col">Decimales</th>
                            <th scope="col">Base</th>
                        <?php elseif ($catalog === 'unidades'): ?>
                            <th scope="col">Abreviatura</th>
                        <?php elseif ($catalog === 'impuestos'): ?>
                            <th scope="col">Tasa</th>
                            <th scope="col">Tipo</th>
                        <?php endif; ?>
                        <th scope="col">Estado</th>
                        <th scope="col">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $record): ?>
                        <?php
                        $recordId = (int) ($record['id'] ?? 0);
                        $recordValues = $failedAction === 'update'
                            && $failedId === $recordId
                                ? $formData
                                : $record;
                        $isActive = (int) ($record['activo'] ?? 0) === 1;
                        $isBase = $catalog === 'monedas'
                            && (int) ($record['es_base'] ?? 0) === 1;
                        ?>
                        <tr>
                            <td><strong><?= e($record['codigo'] ?? '') ?></strong></td>
                            <td><?= e($record['nombre'] ?? '') ?></td>
                            <?php if ($catalog === 'monedas'): ?>
                                <td><?= e($record['simbolo'] ?? '') ?></td>
                                <td><?= e((string) ($record['decimales'] ?? '')) ?></td>
                                <td><?= $isBase ? 'Sí' : 'No' ?></td>
                            <?php elseif ($catalog === 'unidades'): ?>
                                <td><?= e($record['abreviatura'] ?? '') ?></td>
                            <?php elseif ($catalog === 'impuestos'): ?>
                                <td><?= e((string) ($record['tasa'] ?? '')) ?>%</td>
                                <td><?= e($record['tipo'] ?? '') ?></td>
                            <?php endif; ?>
                            <td>
                                <span class="catalog-status<?= $isActive ? ' is-active' : '' ?>">
                                    <?= $isActive ? 'Activo' : 'Inactivo' ?>
                                </span>
                            </td>
                            <td>
                                <div class="catalog-row-actions">
                                    <?php if (($abilities['editar'] ?? false) === true): ?>
                                        <details
                                            class="catalog-edit"
                                            <?= $failedAction === 'update'
                                                && $failedId === $recordId
                                                    ? 'open'
                                                    : '' ?>
                                        >
                                            <summary>Editar</summary>
                                            <form
                                                class="catalog-form catalog-form--edit"
                                                method="post"
                                                action="/catalogos/<?= e($slug) ?>/actualizar"
                                            >
                                                <?= csrf_field($csrf) ?>
                                                <input
                                                    type="hidden"
                                                    name="id"
                                                    value="<?= e((string) $recordId) ?>"
                                                >
                                                <?= View::render('catalogs/_form_fields', [
                                                    'catalog' => $catalog,
                                                    'fieldErrors' =>
                                                        $failedAction === 'update'
                                                        && $failedId === $recordId
                                                            ? $errors
                                                            : [],
                                                    'fieldPrefix' => 'edit-'
                                                        . $slug . '-' . $recordId,
                                                    'values' => $recordValues,
                                                ]) ?>
                                                <div class="catalog-form-actions">
                                                    <button class="button" type="submit">
                                                        Actualizar
                                                    </button>
                                                </div>
                                            </form>
                                        </details>
                                    <?php endif; ?>

                                    <?php if (($abilities['estado'] ?? false) === true): ?>
                                        <?php if ($isBase && $isActive): ?>
                                            <span class="catalog-action-note">
                                                Base activa
                                            </span>
                                        <?php else: ?>
                                            <form
                                                method="post"
                                                action="/catalogos/<?= e($slug) ?>/<?= $isActive ? 'desactivar' : 'activar' ?>"
                                            >
                                                <?= csrf_field($csrf) ?>
                                                <input
                                                    type="hidden"
                                                    name="id"
                                                    value="<?= e((string) $recordId) ?>"
                                                >
                                                <button
                                                    class="button button--secondary button--compact"
                                                    type="submit"
                                                >
                                                    <?= $isActive ? 'Desactivar' : 'Activar' ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
