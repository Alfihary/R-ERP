<?php

declare(strict_types=1);

use App\Core\View;
use App\Support\Security\CsrfTokenService;

if (
    !$csrf instanceof CsrfTokenService
    || !is_array($abilities ?? null)
    || !is_array($createParentOptions ?? null)
    || !is_array($editParentOptions ?? null)
    || !is_array($errors ?? null)
    || !is_array($formData ?? null)
    || !is_array($records ?? null)
    || !is_array($viewableCatalogs ?? null)
) {
    throw new RuntimeException('Classification view data is incomplete.');
}

$createValues = $failedAction === 'create' ? $formData : [];
?>
<header class="catalog-page-heading">
    <p><a href="/catalogos">Catálogos</a> / Clasificaciones</p>
    <h1>Clasificaciones de producto</h1>
    <p>
        Organiza una jerarquía global. Las rutas orientan la estructura y no
        dependen de la empresa o almacén activos.
    </p>
</header>

<nav class="catalog-tabs" aria-label="Catálogos disponibles">
    <?php foreach ($viewableCatalogs as $key => $definition): ?>
        <a
            href="/catalogos/<?= e($definition['slug'] ?? '') ?>"
            <?= $key === 'clasificaciones' ? 'aria-current="page"' : '' ?>
        >
            <?= e($definition['title'] ?? '') ?>
        </a>
    <?php endforeach; ?>
</nav>

<?php if (is_string($notice ?? null) && $notice !== ''): ?>
    <p class="catalog-notice" role="status"><?= e($notice) ?></p>
<?php endif; ?>

<?php if ($errors !== []): ?>
    <div class="catalog-alert" role="alert">
        <strong>No se pudo completar la operación.</strong>
        <ul>
            <?php foreach ($errors as $message): ?>
                <li><?= e($message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (($abilities['crear'] ?? false) === true): ?>
    <section class="catalog-create" aria-labelledby="classification-create-title">
        <div class="catalog-section-heading">
            <div>
                <h2 id="classification-create-title">Agregar clasificación</h2>
                <p>Puede ser una raíz o depender de una clasificación activa.</p>
            </div>
        </div>
        <form
            class="catalog-form"
            method="post"
            action="/catalogos/clasificaciones"
        >
            <?= csrf_field($csrf) ?>
            <?= View::render('catalogs/_classification_fields', [
                'fieldErrors' => $failedAction === 'create' ? $errors : [],
                'fieldPrefix' => 'create-classification',
                'parentOptions' => $createParentOptions,
                'values' => $createValues,
            ]) ?>
            <div class="catalog-form-actions">
                <button class="button" type="submit">Guardar clasificación</button>
            </div>
        </form>
    </section>
<?php endif; ?>

<section class="catalog-list" aria-labelledby="classification-list-title">
    <div class="catalog-section-heading">
        <div>
            <h2 id="classification-list-title">Jerarquía</h2>
            <p>
                Para desactivar un nodo, primero desactiva sus descendientes
                activos.
            </p>
        </div>
    </div>

    <?php if ($records === []): ?>
        <p class="catalog-empty">
            Aún no hay clasificaciones. Crea una raíz para iniciar la jerarquía.
        </p>
    <?php else: ?>
        <div class="catalog-table-wrap">
            <table class="catalog-table catalog-table--classifications">
                <caption class="sr-only">
                    Jerarquía de clasificaciones de producto
                </caption>
                <thead>
                    <tr>
                        <th scope="col">Código</th>
                        <th scope="col">Nombre</th>
                        <th scope="col">Padre</th>
                        <th scope="col">Nivel</th>
                        <th scope="col">Ruta</th>
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
                        $hasActiveDescendants =
                            ($record['has_active_descendants'] ?? false) === true;
                        $parentIsActive =
                            ($record['parent_active'] ?? false) === true;
                        ?>
                        <tr>
                            <td>
                                <strong><?= e($record['codigo'] ?? '') ?></strong>
                            </td>
                            <td><?= e($record['nombre'] ?? '') ?></td>
                            <td>
                                <?= e($record['parent_name'] ?? 'Raíz') ?>
                            </td>
                            <td><?= e((string) ($record['level'] ?? 0)) ?></td>
                            <td>
                                <span class="classification-path">
                                    <?= e($record['path'] ?? '') ?>
                                </span>
                            </td>
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
                                                action="/catalogos/clasificaciones/actualizar"
                                            >
                                                <?= csrf_field($csrf) ?>
                                                <input
                                                    type="hidden"
                                                    name="id"
                                                    value="<?= e((string) $recordId) ?>"
                                                >
                                                <?= View::render(
                                                    'catalogs/_classification_fields',
                                                    [
                                                        'fieldErrors' =>
                                                            $failedAction === 'update'
                                                            && $failedId === $recordId
                                                                ? $errors
                                                                : [],
                                                        'fieldPrefix' =>
                                                            'edit-classification-'
                                                            . $recordId,
                                                        'parentOptions' =>
                                                            $editParentOptions[
                                                                $recordId
                                                            ] ?? [],
                                                        'values' => $recordValues,
                                                    ]
                                                ) ?>
                                                <div class="catalog-form-actions">
                                                    <button class="button" type="submit">
                                                        Actualizar
                                                    </button>
                                                </div>
                                            </form>
                                        </details>
                                    <?php endif; ?>

                                    <?php if (($abilities['estado'] ?? false) === true): ?>
                                        <?php if ($isActive && $hasActiveDescendants): ?>
                                            <span class="catalog-action-note">
                                                Tiene hijos activos
                                            </span>
                                        <?php elseif (!$isActive && !$parentIsActive): ?>
                                            <span class="catalog-action-note">
                                                Activa primero el padre
                                            </span>
                                        <?php else: ?>
                                            <form
                                                method="post"
                                                action="/catalogos/clasificaciones/<?= $isActive ? 'desactivar' : 'activar' ?>"
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
                                                    <?= $isActive
                                                        ? 'Desactivar'
                                                        : 'Activar' ?>
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
