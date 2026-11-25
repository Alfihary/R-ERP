<?php
declare(strict_types=1);
use App\Support\Security\CsrfTokenService;
if (!$csrf instanceof CsrfTokenService || !is_array($ticket ?? null) || !is_array($errors ?? null)) {
    throw new RuntimeException('Product ticket detail data is incomplete.');
}
$ticketId = (string) ($ticket['id'] ?? '');
$partidas = is_array($ticket['partidas'] ?? null) ? $ticket['partidas'] : [];
$eventos = is_array($ticket['eventos'] ?? null) ? $ticket['eventos'] : [];
$comentarios = is_array($ticket['comentarios'] ?? null) ? $ticket['comentarios'] : [];
$adjuntos = is_array($ticket['adjuntos'] ?? null) ? $ticket['adjuntos'] : [];
$estadoTicket = (string) ($ticket['estado'] ?? '');
$permissions = is_array($permissions ?? null) ? $permissions : [];
$approvalCatalogs = is_array($approvalCatalogs ?? null) ? $approvalCatalogs : [];
$approvalSatUnits = is_array($approvalCatalogs['sat_units'] ?? null) ? $approvalCatalogs['sat_units'] : [];
$approvalSatKeys = is_array($approvalCatalogs['sat_keys'] ?? null) ? $approvalCatalogs['sat_keys'] : [];
$canResolve = ($permissions['canResolve'] ?? false) === true;
$canCancel = ($permissions['canCancel'] ?? false) === true;
$canViewAttachments = ($permissions['canViewAttachments'] ?? false) === true;
$canCreateAttachments = ($permissions['canCreateAttachments'] ?? false) === true;
$canCreateComments = ($permissions['canCreateComments'] ?? false) === true;
$canResendEmail = ($permissions['canResendEmail'] ?? false) === true;
$canViewEvents = ($permissions['canViewEvents'] ?? false) === true;
$isCancelled = $estadoTicket === 'CANCELADO';
$formatValue = static fn (mixed $value): string => trim((string) ($value ?? '')) !== '' ? (string) $value : '—';
$entityLabel = static function (
    array $item,
    string $codeField,
    string $nameField,
    string $fallbackField
) use ($formatValue): string {
    $code = trim((string) ($item[$codeField] ?? ''));
    $name = trim((string) ($item[$nameField] ?? ''));
    if ($code !== '' && $name !== '') {
        return $code . ' · ' . $name;
    }
    if ($name !== '') {
        return $name;
    }
    if ($code !== '') {
        return $code;
    }
    return $formatValue($item[$fallbackField] ?? null);
};
$personLabel = static function (
    array $item,
    string $usernameField,
    string $nameField,
    string $fallbackField
) use ($formatValue): string {
    $username = trim((string) ($item[$usernameField] ?? ''));
    $name = trim((string) ($item[$nameField] ?? ''));
    if ($username !== '' && $name !== '') {
        return $username . ' / ' . $name;
    }
    if ($username !== '') {
        return $username;
    }
    if ($name !== '') {
        return $name;
    }
    return $formatValue($item[$fallbackField] ?? null);
};
$countByState = static function (array $items, string $state): int {
    $count = 0;
    foreach ($items as $item) {
        if (is_array($item) && (string) ($item['estado'] ?? '') === $state) {
            $count++;
        }
    }
    return $count;
};
$totalPartidas = (int) ($ticket['total_partidas'] ?? count($partidas));
$partidasEnRevision = (int) ($ticket['partidas_en_revision'] ?? $countByState($partidas, 'EN_REVISION'));
$partidasAprobadas = (int) ($ticket['partidas_aprobadas'] ?? $countByState($partidas, 'APROBADA'));
$partidasRechazadas = (int) ($ticket['partidas_rechazadas'] ?? $countByState($partidas, 'RECHAZADA'));
$comentariosPorPartida = [];
$comentariosGenerales = [];
$adjuntosPorPartida = [];
$adjuntosGenerales = [];
$formatBytes = static function (mixed $bytes): string {
    $size = is_numeric($bytes) ? (int) $bytes : 0;
    if ($size >= 1048576) {
        return number_format($size / 1048576, 2) . ' MB';
    }
    if ($size >= 1024) {
        return number_format($size / 1024, 1) . ' KB';
    }
    return $size . ' bytes';
};
$catalogLabel = static function (array $item, string $descriptionField = 'descripcion'): string {
    $code = trim((string) ($item['codigo'] ?? ''));
    $description = trim((string) ($item[$descriptionField] ?? $item['nombre'] ?? ''));
    if ($code === '') {
        return $description;
    }
    if ($description === '') {
        return $code;
    }
    return $code . ' - ' . $description;
};
$partCatalogLabel = static function (
    array $item,
    string $codeField,
    string $nameField,
    string $descriptionField,
    string $fallbackField
) use ($formatValue): string {
    $code = trim((string) ($item[$codeField] ?? ''));
    $description = trim((string) ($item[$descriptionField] ?? ''));
    $name = trim((string) ($item[$nameField] ?? ''));
    $text = $description !== '' ? $description : $name;
    if ($code !== '' && $text !== '') {
        return $code . ' · ' . $text;
    }
    if ($code !== '') {
        return $code;
    }
    if ($text !== '') {
        return $text;
    }
    return $formatValue($item[$fallbackField] ?? null);
};
foreach ($comentarios as $comentario) {
    if (!is_array($comentario)) {
        continue;
    }
    $comentarioPartidaId = (string) ($comentario['partida_id'] ?? '');
    if ($comentarioPartidaId !== '' && $comentarioPartidaId !== '0') {
        $comentariosPorPartida[$comentarioPartidaId][] = $comentario;
        continue;
    }
    $comentariosGenerales[] = $comentario;
}
foreach ($adjuntos as $adjunto) {
    if (!is_array($adjunto)) {
        continue;
    }
    $adjuntoPartidaId = (string) ($adjunto['partida_id'] ?? '');
    if ($adjuntoPartidaId !== '' && $adjuntoPartidaId !== '0') {
        $adjuntosPorPartida[$adjuntoPartidaId][] = $adjunto;
        continue;
    }
    $adjuntosGenerales[] = $adjunto;
}
?>
<div class="ticket-products ticket-products__page ticket-products__detail">
    <header class="ticket-products__detail-header">
        <p class="ticket-products__detail-eyebrow">Solicitud documental de alta de productos</p>
        <div class="ticket-products__detail-title-row">
            <div class="ticket-products__detail-title">
                <span class="ticket-products__overline">Folio</span>
                <h1>Ticket <?= e($ticket['folio'] ?? '') ?></h1>
                <span class="badge ticket-products__badge ticket-products__hero-state ticket-products__badge--<?= e(strtolower($estadoTicket)) ?>"><?= e($estadoTicket) ?></span>
            </div>
            <a class="button button--secondary ticket-products__hero-back" href="/tickets/productos">← Volver a tickets</a>
        </div>
        <div class="ticket-products__document-banner" role="note">
            <span class="ticket-products__document-mark" aria-hidden="true">i</span>
            <span>Este ticket es documental y no crea productos reales.</span>
            <span class="ticket-products__document-divider" aria-hidden="true"></span>
            <span>Autorizar una partida no crea el producto en el catálogo.</span>
        </div>
    </header>
    <?php if ($errors !== []): ?>
        <section class="alert alert--danger ticket-products__errors" role="alert" aria-labelledby="ticket-producto-errores">
            <h2 id="ticket-producto-errores">Revisa la operación</h2>
            <ul>
                <?php foreach ($errors as $field => $message): ?>
                    <li><?= e($field) ?>: <?= e($message) ?></li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>
    <div class="ticket-products__detail-layout">
        <main class="ticket-products__detail-main">
            <?php if ($canResolve && !$isCancelled && $partidasEnRevision > 0): ?>
                <section class="ticket-products__detail-card ticket-products__detail-approval" aria-labelledby="ticket-producto-aprobar-partidas">
                    <div class="ticket-products__detail-card-heading">
                        <p class="ticket-products__section-kicker">Resolución</p>
                        <h2 id="ticket-producto-aprobar-partidas">Aprobar partidas</h2>
                        <p class="ticket-products__hint">Completa la respuesta de aprobación por cada partida antes de aprobar el ticket. Aprobar no crea un producto real.</p>
                    </div>
                    <datalist id="ticket-producto-unidades-sat-autorizadas">
                        <?php foreach ($approvalSatUnits as $unit): ?>
                            <?php if (!is_array($unit)) { continue; } ?>
                            <option value="<?= e($catalogLabel($unit, 'nombre')) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <datalist id="ticket-producto-claves-sat-autorizadas">
                        <?php foreach ($approvalSatKeys as $satKey): ?>
                            <?php if (!is_array($satKey)) { continue; } ?>
                            <option value="<?= e($catalogLabel($satKey, 'descripcion')) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <div class="ticket-products__approval-list">
                        <?php foreach ($partidas as $partida): ?>
                            <?php if (!is_array($partida) || (string) ($partida['estado'] ?? '') !== 'EN_REVISION') { continue; } ?>
                            <?php $partidaId = (string) ($partida['id'] ?? ''); ?>
                            <?php $descriptionValue = trim((string) ($partida['descripcion'] ?? '')); ?>
                            <article class="ticket-products__approval-card">
                                <div class="ticket-products__approval-head">
                                    <div>
                                        <span class="ticket-products__overline">Partida <?= e($partida['numero_partida'] ?? '') ?></span>
                                        <h3><?= e($formatValue($descriptionValue)) ?></h3>
                                    </div>
                                    <span class="badge ticket-products__badge ticket-products__badge--en_revision">EN_REVISION</span>
                                </div>
                                <dl class="ticket-products__approval-request">
                                    <div><dt>Unidad SAT solicitada</dt><dd><?= e($partCatalogLabel($partida, 'unidad_sat_clave', 'unidad_sat_nombre', 'unidad_sat_descripcion', 'unidad_sat_id')) ?></dd></div>
                                    <div><dt>Clave SAT solicitada</dt><dd><?= e($partCatalogLabel($partida, 'clave_sat_clave', 'clave_sat_descripcion', 'clave_sat_descripcion', 'clave_sat_id')) ?></dd></div>
                                </dl>
                                <form class="ticket-products__approval-form" method="post" action="/tickets/productos/<?= e($ticketId) ?>/partidas/<?= e($partidaId) ?>/aprobar">
                                    <?= csrf_field($csrf) ?>
                                    <label class="field"><span>Clave autorizada</span><input type="text" name="clave_autorizada" maxlength="16" pattern="[A-Za-z0-9._-]{1,16}" autocomplete="off"></label>
                                    <label class="field ticket-products__approval-description"><span>Descripción autorizada *</span><input type="text" name="descripcion_autorizada" maxlength="255" value="<?= e($descriptionValue) ?>" required></label>
                                    <label class="field"><span>Unidad SAT autorizada</span><input type="text" name="unidad_sat_autorizada" list="ticket-producto-unidades-sat-autorizadas" autocomplete="off"></label>
                                    <label class="field"><span>Clave SAT autorizada</span><input type="text" name="clave_sat_autorizada" list="ticket-producto-claves-sat-autorizadas" autocomplete="off"></label>
                                    <label class="field ticket-products__field-full"><span>Respuesta / comentario para el solicitante</span><textarea name="comentario_resolucion">Producto autorizado para captura manual en catálogo.</textarea></label>
                                    <button class="button" type="submit">Aprobar partida</button>
                                </form>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php elseif (!$canResolve): ?>
                <p class="ticket-products__permission-note">No tienes permiso para aprobar partidas.</p>
            <?php endif; ?>
            <section class="ticket-products__detail-partidas" aria-labelledby="ticket-producto-partidas">
                <div class="ticket-products__detail-section-heading">
                    <p class="ticket-products__section-kicker">Revisión documental</p>
                    <h2 id="ticket-producto-partidas">Partidas</h2>
                </div>
                <?php if ($partidas === []): ?>
                    <div class="empty-state ticket-products__empty"><strong>Este ticket no tiene partidas visibles.</strong></div>
                <?php else: ?>
                    <div class="ticket-products__lines">
                        <?php foreach ($partidas as $partida): ?>
                            <?php if (!is_array($partida)) { continue; } ?>
                            <?php $partidaId = (string) ($partida['id'] ?? ''); ?>
                            <?php $estadoPartida = (string) ($partida['estado'] ?? ''); ?>
                            <?php $comentariosDePartida = $comentariosPorPartida[$partidaId] ?? []; ?>
                            <?php $adjuntosDePartida = $adjuntosPorPartida[$partidaId] ?? []; ?>
                            <article class="ticket-products__line-card">
                                <header class="ticket-products__line-heading">
                                    <div class="ticket-products__line-identity"><span class="ticket-products__line-number"><?= e($partida['numero_partida'] ?? '') ?></span><div><span class="ticket-products__overline">Partida documental</span><h3>Partida <?= e($partida['numero_partida'] ?? '') ?></h3></div></div>
                                    <div class="ticket-products__chips"><span class="badge ticket-products__badge ticket-products__badge--<?= e(strtolower($estadoPartida)) ?>"><?= e($estadoPartida) ?></span><?php if ($estadoPartida === 'EN_REVISION'): ?><span class="ticket-products__chip">Pendiente de revisión</span><?php endif; ?></div>
                                </header>
                                <section class="ticket-products__line-section ticket-products__requested-data" aria-label="Datos solicitados">
                                    <h4>Datos solicitados</h4>
                                    <dl class="ticket-products__line-data">
                                        <div class="ticket-products__line-full"><dt>Descripción solicitada</dt><dd><?= e($formatValue($partida['descripcion'] ?? null)) ?></dd></div>
                                        <div><dt>Modelo</dt><dd><?= e($formatValue($partida['modelo'] ?? null)) ?></dd></div><div><dt>Marca documental</dt><dd><?= e($formatValue($partida['marca_texto'] ?? null)) ?></dd></div><div><dt>Proveedor documental</dt><dd><?= e($formatValue($partida['proveedor_texto'] ?? null)) ?></dd></div>
                                        <div><dt>Unidad SAT solicitada</dt><dd><?= e($partCatalogLabel($partida, 'unidad_sat_clave', 'unidad_sat_nombre', 'unidad_sat_descripcion', 'unidad_sat_id')) ?></dd></div><div><dt>Clave SAT solicitada</dt><dd><?= e($partCatalogLabel($partida, 'clave_sat_clave', 'clave_sat_descripcion', 'clave_sat_descripcion', 'clave_sat_id')) ?></dd></div><div><dt>Moneda</dt><dd><?= e($entityLabel($partida, 'moneda_codigo', 'moneda_nombre', 'moneda_id')) ?></dd></div>
                                        <div><dt>Costo sugerido <span class="ticket-products__muted-inline">solicitado</span></dt><dd><?= e($formatValue($partida['costo_sugerido'] ?? null)) ?></dd></div><div><dt>Peso</dt><dd><?= e($formatValue($partida['peso'] ?? null)) ?></dd></div><div><dt>Lleva serie</dt><dd><?= ((int) ($partida['lleva_serie'] ?? 0)) === 1 ? 'Sí' : 'No' ?></dd></div>
                                        <div class="ticket-products__line-full"><dt>Observaciones</dt><dd><?= e($formatValue($partida['observaciones'] ?? null)) ?></dd></div>
                                    </dl>
                                </section>
                                <section class="ticket-products__line-section ticket-products__line-response ticket-products__line-response--<?= e(strtolower($estadoPartida)) ?>" aria-label="Respuesta del área de productos"><h4>Respuesta del área de productos</h4><?php if ($estadoPartida === 'EN_REVISION'): ?><p class="ticket-products__placeholder">Pendiente de revisión.</p><?php else: ?><dl class="ticket-products__line-data"><div><dt>Resuelto por</dt><dd><?= e($personLabel($partida, 'resuelto_por_username', 'resuelto_por_nombre_completo', 'resuelto_por_usuario_id')) ?></dd></div><div><dt>Fecha de resolución</dt><dd><?= e($formatValue($partida['resuelto_at'] ?? null)) ?></dd></div><?php if ($estadoPartida === 'APROBADA'): ?><div><dt>Clave autorizada</dt><dd><?= e($formatValue($partida['clave_autorizada'] ?? null)) ?></dd></div><div class="ticket-products__line-full"><dt>Descripción autorizada</dt><dd><?= e($formatValue($partida['descripcion_autorizada'] ?? null)) ?></dd></div><div><dt>Unidad SAT autorizada</dt><dd><?= e($partCatalogLabel($partida, 'unidad_sat_autorizada_clave', 'unidad_sat_autorizada_nombre', 'unidad_sat_autorizada_descripcion', 'unidad_sat_id_autorizada')) ?></dd></div><div><dt>Clave SAT autorizada</dt><dd><?= e($partCatalogLabel($partida, 'clave_sat_autorizada_clave', 'clave_sat_autorizada_descripcion', 'clave_sat_autorizada_descripcion', 'clave_sat_id_autorizada')) ?></dd></div><?php elseif ($estadoPartida === 'RECHAZADA'): ?><div class="ticket-products__line-full"><dt>Motivo de rechazo</dt><dd><?= e($formatValue($partida['motivo_rechazo'] ?? null)) ?></dd></div><?php endif; ?><div class="ticket-products__line-full"><dt>Comentario / respuesta</dt><dd><?= e($formatValue($partida['comentario_resolucion'] ?? null)) ?></dd></div></dl><?php endif; ?></section>
                                <div class="ticket-products__line-comments"><h4>Comentarios de partida</h4><?php if ($comentariosDePartida === []): ?><p class="ticket-products__hint">Sin comentarios registrados para esta partida.</p><?php else: ?><ul class="ticket-products__comment-list"><?php foreach ($comentariosDePartida as $comentario): ?><li><span class="ticket-products__comment-scope">Partida <?= e($partida['numero_partida'] ?? '') ?></span><p><?= e($formatValue($comentario['comentario'] ?? null)) ?></p><span class="ticket-products__event-meta">Usuario <?= e($formatValue($comentario['usuario_id'] ?? null)) ?> · <?= e($formatValue($comentario['created_at'] ?? null)) ?></span></li><?php endforeach; ?></ul><?php endif; ?><?php if ($canCreateComments): ?><form class="ticket-products__action-form ticket-products__comment-form" method="post" action="/tickets/productos/<?= e($ticketId) ?>/comentarios"><?= csrf_field($csrf) ?><input type="hidden" name="partida_id" value="<?= e($partidaId) ?>"><label class="field"><span>Agregar comentario a esta partida</span><textarea name="comentario" required></textarea></label><button class="button button--secondary" type="submit">Comentar partida</button></form><?php endif; ?></div>
                                <?php if ($canViewAttachments || $canCreateAttachments): ?><div class="ticket-products__line-attachments"><h4>Adjuntos de partida</h4><?php if ($canCreateAttachments && !$isCancelled): ?><form class="ticket-products__action-form ticket-products__attachment-form" method="post" action="/tickets/productos/<?= e($ticketId) ?>/adjuntos" enctype="multipart/form-data"><?= csrf_field($csrf) ?><input type="hidden" name="partida_id" value="<?= e($partidaId) ?>"><label class="field"><span>Adjuntar soporte a esta partida</span><input type="file" name="adjunto" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp" required></label><button class="button button--secondary" type="submit">Subir adjunto de partida</button></form><?php endif; ?><?php if (!$canViewAttachments): ?><p class="ticket-products__hint">No tienes permiso para consultar adjuntos existentes.</p><?php elseif ($adjuntosDePartida === []): ?><p class="ticket-products__hint">Sin adjuntos registrados para esta partida.</p><?php else: ?><ul class="ticket-products__attachment-list"><?php foreach ($adjuntosDePartida as $adjunto): ?><li><strong><?= e($formatValue($adjunto['nombre_original'] ?? null)) ?></strong><?php if ($canViewAttachments): ?><a class="button button--sm button--secondary" href="/tickets/productos/<?= e($ticketId) ?>/adjuntos/<?= e((string) ($adjunto['id'] ?? '')) ?>/descargar">Descargar</a><?php endif; ?><span><?= e(strtoupper($formatValue($adjunto['extension'] ?? null))) ?> · <?= e($formatValue($adjunto['mime'] ?? null)) ?> · <?= e($formatBytes($adjunto['tamano_bytes'] ?? null)) ?></span><span class="ticket-products__event-meta">Usuario <?= e($formatValue($adjunto['subido_por_usuario_id'] ?? null)) ?> · <?= e($formatValue($adjunto['created_at'] ?? null)) ?></span></li><?php endforeach; ?></ul><?php endif; ?></div><?php endif; ?>
                                <?php if ($canResolve && !$isCancelled && $estadoPartida === 'EN_REVISION'): ?><div class="ticket-products__line-actions"><h4>Rechazar partida</h4><form class="ticket-products__action-form" method="post" action="/tickets/productos/<?= e($ticketId) ?>/partidas/<?= e($partidaId) ?>/rechazar"><?= csrf_field($csrf) ?><label class="field"><span>Motivo de rechazo</span><textarea name="motivo_rechazo" required></textarea></label><label class="field"><span>Comentario de resolución</span><textarea name="comentario_resolucion"></textarea></label><button class="button button--secondary ticket-products__danger-action" type="submit">Rechazar partida</button></form></div><?php elseif ($canResolve && !$isCancelled): ?><p class="ticket-products__permission-note">Esta partida ya fue resuelta; las acciones de aprobación y rechazo están ocultas.</p><?php elseif ($isCancelled): ?><p class="ticket-products__permission-note">Ticket cancelado; las acciones de la partida están ocultas.</p><?php else: ?><p class="ticket-products__permission-note">No tienes permiso para aprobar o rechazar partidas.</p><?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </main>
        <aside class="ticket-products__detail-aside">
            <section class="ticket-products__detail-card ticket-products__detail-context" aria-labelledby="ticket-producto-resumen">
                <div class="ticket-products__detail-card-heading">
                    <p class="ticket-products__section-kicker">Contexto</p>
                    <h2 id="ticket-producto-resumen">Resumen</h2>
                </div>
                <dl class="ticket-products__summary">
                    <div>
                        <dt>Empresa</dt>
                        <dd><?= e($entityLabel($ticket, 'empresa_codigo', 'empresa_nombre', 'empresa_id')) ?></dd>
                    </div>
                    <div>
                        <dt>Almacén</dt>
                        <dd><?= e($entityLabel($ticket, 'almacen_codigo', 'almacen_nombre', 'almacen_id')) ?></dd>
                    </div>
                    <div>
                        <dt>Solicitante</dt>
                        <dd><?= e($personLabel($ticket, 'solicitante_username', 'solicitante_nombre_completo', 'solicitante_usuario_id')) ?></dd>
                    </div>
                    <div class="ticket-products__summary-dates">
                        <div>
                            <dt>Creación</dt>
                            <dd><?= e($formatValue($ticket['created_at'] ?? null)) ?></dd>
                        </div>
                        <div>
                            <dt>Actualización</dt>
                            <dd><?= e($formatValue($ticket['updated_at'] ?? null)) ?></dd>
                        </div>
                    </div>
                    <?php if (($ticket['motivo_cancelacion'] ?? null) !== null): ?>
                        <div>
                            <dt>Motivo de cancelación</dt>
                            <dd><?= e($formatValue($ticket['motivo_cancelacion'])) ?></dd>
                        </div>
                    <?php endif; ?>
                    <div class="ticket-products__summary-full">
                        <dt>Observaciones generales</dt>
                        <dd><?= e($formatValue($ticket['observaciones_generales'] ?? null)) ?></dd>
                    </div>
                </dl>
            </section>
            <?php if ($canViewAttachments || $canCreateAttachments): ?><section class="ticket-products__detail-card ticket-products__detail-attachments" aria-labelledby="ticket-producto-adjuntos"><div class="ticket-products__detail-card-heading"><p class="ticket-products__section-kicker">Soporte documental</p><h2 id="ticket-producto-adjuntos">Adjuntos</h2></div><p class="ticket-products__hint">Los adjuntos sirven como soporte para revisar la solicitud.</p><?php if ($canCreateAttachments && !$isCancelled): ?><form class="ticket-products__action-form ticket-products__attachment-form" method="post" action="/tickets/productos/<?= e($ticketId) ?>/adjuntos" enctype="multipart/form-data"><?= csrf_field($csrf) ?><label class="field"><span>Adjunto general</span><input type="file" name="adjunto" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp" required></label><p class="ticket-products__hint">Formatos: PDF, JPG, JPEG, PNG o WEBP. Máximo 5 MB.</p><button class="button" type="submit">Subir adjunto</button></form><?php endif; ?><?php if (!$canViewAttachments): ?><p class="ticket-products__placeholder">No tienes permiso para consultar adjuntos existentes.</p><?php elseif ($adjuntosGenerales === []): ?><p class="ticket-products__placeholder">Sin adjuntos generales registrados.</p><?php else: ?><ul class="ticket-products__attachment-list"><?php foreach ($adjuntosGenerales as $adjunto): ?><li><strong><?= e($formatValue($adjunto['nombre_original'] ?? null)) ?></strong><?php if ($canViewAttachments): ?><a class="button button--sm button--secondary" href="/tickets/productos/<?= e($ticketId) ?>/adjuntos/<?= e((string) ($adjunto['id'] ?? '')) ?>/descargar">Descargar</a><?php endif; ?><span><?= e(strtoupper($formatValue($adjunto['extension'] ?? null))) ?> · <?= e($formatValue($adjunto['mime'] ?? null)) ?> · <?= e($formatBytes($adjunto['tamano_bytes'] ?? null)) ?></span><span class="ticket-products__event-meta">Usuario <?= e($formatValue($adjunto['subido_por_usuario_id'] ?? null)) ?> · <?= e($formatValue($adjunto['created_at'] ?? null)) ?></span></li><?php endforeach; ?></ul><?php endif; ?></section><?php endif; ?>
            <section class="ticket-products__detail-card ticket-products__detail-comments" aria-labelledby="ticket-producto-comentarios"><div class="ticket-products__detail-card-heading"><p class="ticket-products__section-kicker">Bitácora documental</p><h2 id="ticket-producto-comentarios">Comentarios</h2></div><p class="ticket-products__hint">Los comentarios son documentales y no modifican el estado del ticket.</p><?php if ($comentariosGenerales === []): ?><p class="ticket-products__placeholder">Sin comentarios generales registrados.</p><?php else: ?><ul class="ticket-products__comment-list"><?php foreach ($comentariosGenerales as $comentario): ?><li><span class="ticket-products__comment-scope">Comentario general</span><p><?= e($formatValue($comentario['comentario'] ?? null)) ?></p><span class="ticket-products__event-meta">Usuario <?= e($formatValue($comentario['usuario_id'] ?? null)) ?> · <?= e($formatValue($comentario['created_at'] ?? null)) ?></span></li><?php endforeach; ?></ul><?php endif; ?><?php if ($canCreateComments): ?><form class="ticket-products__action-form ticket-products__comment-form" method="post" action="/tickets/productos/<?= e($ticketId) ?>/comentarios"><?= csrf_field($csrf) ?><label class="field"><span>Agregar comentario general</span><textarea name="comentario" required></textarea></label><button class="button" type="submit">Agregar comentario</button></form><?php endif; ?></section>
            <?php if ($canViewEvents): ?><section class="ticket-products__detail-card ticket-products__detail-events" aria-labelledby="ticket-producto-eventos"><div class="ticket-products__detail-card-heading"><h2 id="ticket-producto-eventos">Eventos</h2></div><?php if ($eventos === []): ?><p class="ticket-products__hint">Sin eventos visibles.</p><?php else: ?><ul class="ticket-products__events"><?php foreach ($eventos as $evento): ?><?php if (!is_array($evento)) { continue; } ?><li><span class="ticket-products__event-type"><?= e($formatValue($evento['evento'] ?? null)) ?></span><span class="ticket-products__event-copy"><?= e($formatValue($evento['descripcion'] ?? null)) ?></span><span class="ticket-products__event-meta">Usuario <?= e($formatValue($evento['usuario_id'] ?? null)) ?> · <?= e($formatValue($evento['created_at'] ?? null)) ?></span></li><?php endforeach; ?></ul><?php endif; ?></section><?php endif; ?>
            <section class="ticket-products__detail-card ticket-products__detail-mail" aria-labelledby="ticket-producto-correo"><div class="ticket-products__detail-card-heading"><h2 id="ticket-producto-correo">Correo electrónico</h2></div><p class="ticket-products__hint">Las notificaciones de correo se gestionan mediante la cola administrativa de correo.</p><p class="ticket-products__hint">Eventos: ticket creado, partida aprobada, partida rechazada, ticket resuelto y ticket cancelado.</p><?php if ($canResendEmail): ?><button class="button button--secondary ticket-products__disabled-action" type="button" disabled>Reenvío manual no disponible.</button><?php endif; ?></section>
            <?php if ($canCancel && !$isCancelled): ?><section class="ticket-products__detail-card ticket-products__detail-cancel" aria-labelledby="ticket-producto-cancelar"><div class="ticket-products__detail-card-heading"><h2 id="ticket-producto-cancelar">Cancelar ticket</h2></div><form class="ticket-products__action-form ticket-products__cancel-form" method="post" action="/tickets/productos/<?= e($ticketId) ?>/cancelar"><?= csrf_field($csrf) ?><label class="field"><span>Motivo de cancelación</span><textarea name="motivo" required></textarea></label><button class="button button--secondary ticket-products__danger-action" type="submit">Cancelar ticket</button></form></section><?php endif; ?>
        </aside>
    </div>
</div>
