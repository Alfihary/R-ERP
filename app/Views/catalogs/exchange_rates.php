<?php

declare(strict_types=1);

use App\Core\View;
use App\Support\Security\CsrfTokenService;

if (
    !$csrf instanceof CsrfTokenService
    || !is_array($abilities ?? null)
    || !is_array($currencies ?? null)
    || !is_array($errors ?? null)
    || !is_array($formData ?? null)
    || !is_array($records ?? null)
    || !is_array($viewableCatalogs ?? null)
) {
    throw new RuntimeException('Exchange rate view data is incomplete.');
}

$createValues = $failedAction === 'create' ? $formData : [
    'fecha' => date('Y-m-d'),
];
?>
<header class="catalog-page-heading">
    <p><a href="/catalogos">Catálogos</a> / Tipos de cambio</p>
    <h1>Tipos de cambio</h1>
    <p>
        Registra valores diarios entre monedas activas. Esta fase administra
        datos y no realiza conversiones ni cálculos de precios.
    </p>
</header>

<nav class="catalog-tabs" aria-label="Catálogos disponibles">
    <?php foreach ($viewableCatalogs as $key => $definition): ?>
        <a
            href="/catalogos/<?= e($definition['slug'] ?? '') ?>"
            <?= $key === 'tipos_cambio' ? 'aria-current="page"' : '' ?>
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
            <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (($abilities['crear'] ?? false) === true): ?>
    <section class="catalog-create" aria-labelledby="exchange-rate-create-title">
        <div class="catalog-section-heading">
            <div>
                <h2 id="exchange-rate-create-title">Nuevo tipo de cambio</h2>
                <p>Solo se admite un registro por par de monedas y fecha.</p>
            </div>
        </div>
        <form class="catalog-form catalog-form--exchange" method="post" action="/catalogos/tipos-cambio">
            <?= csrf_field($csrf) ?>
            <?= View::render('catalogs/_exchange_rate_fields', [
                'currencies' => $currencies,
                'fieldErrors' => $failedAction === 'create' ? $errors : [],
                'fieldPrefix' => 'create-exchange-rate',
                'values' => $createValues,
            ]) ?>
            <div class="catalog-form-actions">
                <button class="button" type="submit">Guardar tipo de cambio</button>
            </div>
        </form>
    </section>
<?php endif; ?>

<section class="catalog-list" aria-labelledby="exchange-rate-list-title">
    <div class="catalog-section-heading">
        <div>
            <h2 id="exchange-rate-list-title">Registros</h2>
            <p><?= e((string) count($records)) ?> tipos de cambio disponibles.</p>
        </div>
    </div>

    <?php if ($records === []): ?>
        <p class="catalog-empty">No hay tipos de cambio registrados.</p>
    <?php else: ?>
        <div class="catalog-table-wrap">
            <table class="catalog-table catalog-table--exchange-rates">
                <thead>
                    <tr>
                        <th scope="col">Moneda origen</th>
                        <th scope="col">Moneda destino</th>
                        <th scope="col">Fecha</th>
                        <th scope="col">Valor</th>
                        <th scope="col">Estado</th>
                        <th scope="col">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($records as $record): ?>
                        <?php
                        $recordId = (int) ($record['id'] ?? 0);
                        $isActive = (int) ($record['activo'] ?? 0) === 1;
                        $recordValues = $failedAction === 'update'
                            && $failedId === $recordId
                                ? $formData
                                : $record;
                        ?>
                        <tr>
                            <td>
                                <strong><?= e((string) ($record['moneda_origen_codigo'] ?? '')) ?></strong>
                                <span class="catalog-cell-detail">
                                    <?= e((string) ($record['moneda_origen_nombre'] ?? '')) ?>
                                </span>
                            </td>
                            <td>
                                <strong><?= e((string) ($record['moneda_destino_codigo'] ?? '')) ?></strong>
                                <span class="catalog-cell-detail">
                                    <?= e((string) ($record['moneda_destino_nombre'] ?? '')) ?>
                                </span>
                            </td>
                            <td><?= e((string) ($record['fecha'] ?? '')) ?></td>
                            <td class="catalog-decimal">
                                <?= e(rtrim(rtrim((string) ($record['valor'] ?? ''), '0'), '.')) ?>
                            </td>
                            <td>
                                <span class="catalog-status <?= $isActive ? 'is-active' : '' ?>">
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
                                                class="catalog-form catalog-form--edit catalog-form--exchange"
                                                method="post"
                                                action="/catalogos/tipos-cambio/actualizar"
                                            >
                                                <?= csrf_field($csrf) ?>
                                                <input
                                                    type="hidden"
                                                    name="id"
                                                    value="<?= e((string) $recordId) ?>"
                                                >
                                                <?= View::render(
                                                    'catalogs/_exchange_rate_fields',
                                                    [
                                                        'currencies' => $currencies,
                                                        'fieldErrors' =>
                                                            $failedAction === 'update'
                                                            && $failedId === $recordId
                                                                ? $errors
                                                                : [],
                                                        'fieldPrefix' =>
                                                            'edit-exchange-rate-'
                                                            . $recordId,
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
                                        <form
                                            method="post"
                                            action="/catalogos/tipos-cambio/<?= $isActive ? 'desactivar' : 'activar' ?>"
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
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
