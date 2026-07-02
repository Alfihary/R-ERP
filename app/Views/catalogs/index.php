<?php

declare(strict_types=1);

if (!is_array($availableCatalogs ?? null)) {
    throw new RuntimeException('Catalog directory data is incomplete.');
}

$descriptions = [
    'monedas' => 'Códigos, símbolos, decimales y moneda base.',
    'unidades' => 'Unidades y abreviaturas para captura futura.',
    'impuestos' => 'Tasas y tipos estructurales aprobados.',
    'lineas' => 'Agrupación general para productos futuros.',
    'marcas' => 'Marcas globales para productos futuros.',
];
?>
<header class="catalog-page-heading">
    <p><a href="/app">Inicio</a> / Catálogos</p>
    <h1>Catálogos base</h1>
    <p>
        Administra datos estructurales globales. Estos registros no dependen de
        la empresa o almacén activos.
    </p>
</header>

<section class="catalog-directory" aria-labelledby="catalog-directory-title">
    <div class="catalog-section-heading">
        <div>
            <h2 id="catalog-directory-title">Catálogos disponibles</h2>
            <p>Selecciona un catálogo para consultar o administrar sus registros.</p>
        </div>
    </div>

    <?php if ($availableCatalogs === []): ?>
        <p class="catalog-empty">
            Tu cuenta no tiene permisos de consulta para catálogos.
        </p>
    <?php else: ?>
        <ul class="catalog-directory-list">
            <?php foreach ($availableCatalogs as $key => $definition): ?>
                <li>
                    <div>
                        <strong><?= e($definition['title'] ?? '') ?></strong>
                        <span><?= e($descriptions[$key] ?? '') ?></span>
                    </div>
                    <a href="/catalogos/<?= e($definition['slug'] ?? '') ?>">
                        Administrar
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<p class="catalog-scope-note">
    Tipos de cambio y clasificaciones de producto permanecen fuera de esta
    fase por sus reglas adicionales de vigencia y jerarquía.
</p>
