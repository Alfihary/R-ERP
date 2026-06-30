<?php

declare(strict_types=1);

if (!is_array($user ?? null)) {
    throw new RuntimeException('Authenticated content context is incomplete.');
}
?>
<header class="page-heading">
    <p class="page-heading__path">Inicio</p>
    <h1>Área de trabajo</h1>
    <p>
        Este espacio confirma que tu sesión y permiso de acceso están activos.
        Los módulos operativos se incorporarán en fases posteriores.
    </p>
</header>

<section class="identity-section" aria-labelledby="identity-title">
    <div class="identity-section__heading">
        <h2 id="identity-title">Sesión autenticada</h2>
        <span class="status-label">
            <span aria-hidden="true"></span>
            Activa
        </span>
    </div>

    <dl class="identity-list">
        <div>
            <dt>Usuario</dt>
            <dd><?= e($user['username'] ?? '') ?></dd>
        </div>
        <div>
            <dt>Correo</dt>
            <dd><?= e($user['email'] ?? '') ?></dd>
        </div>
    </dl>
</section>

<section class="shell-notice" aria-labelledby="shell-notice-title">
    <h2 id="shell-notice-title">Cascarón administrativo disponible</h2>
    <p>
        La estructura de navegación está lista. Esta pantalla no contiene
        dashboard, métricas ni accesos a módulos aún no aprobados.
    </p>
</section>
