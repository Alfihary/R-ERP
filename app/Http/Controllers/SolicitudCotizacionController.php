<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\Auth\AuthService;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContextService;
use App\Domain\Notifications\NotificationService;
use App\Domain\Solicitudes\SolicitudCotizacionService;
use App\Infrastructure\Repositories\LegacyQuotationRepository;
use App\Support\Security\CsrfTokenService;

final class SolicitudCotizacionController
{
    public function __construct(
        private readonly Config $config,
        private readonly AuthService $auth,
        private readonly PermissionService $permissions,
        private readonly ScopeContextService $scopeContext,
        private readonly CsrfTokenService $csrf,
        private readonly SolicitudCotizacionService $solicitudes,
        private readonly LegacyQuotationRepository $quotations,
        private readonly NotificationService $notifications
    ) {}

    /** @param array<string,string> $params */
    public function show(Request $request, array $params): Response
    {
        $id = $params['id'] ?? '';
        if (!ctype_digit($id) || (int) $id < 1) return Response::html(View::render('errors/404'), 404);
        $user = $this->auth->user();
        $solicitud = $this->solicitudes->findForUser((int) $id, (int) ($user['user_id'] ?? 0));
        if ($solicitud === null) return Response::html(View::render('errors/403'), 403);
        $userId = (int) ($user['user_id'] ?? 0);
        $layoutContext = $this->scopeContext->resolveForUser($userId);
        return Response::html(View::render('layouts/app', [
            'activeNavigation' => 'home', 'appName' => (string) $this->config->get('app.name', 'SoporteGR ERP'),
            ...$this->navigationPermissions($userId),
            'contentData' => ['solicitud' => $solicitud, 'quote' => $this->quote($solicitud, $userId), 'authorizationHistory' => $this->quotations->authorizationHistory((int)$id, $userId), 'canApprovePrices' => $this->permissions->allows($userId, 'precios.autorizaciones.aprobar'), 'taxes' => $this->quotations->taxes(), 'activeCompany' => $layoutContext->activeCompany()], 'contentView' => 'solicitudes-cotizacion/show',
            'context' => $layoutContext->toArray(), 'csrf' => $this->csrf,
            'pageTitle' => 'Solicitud de cotización', 'user' => $user,
        ]));
    }

    public function saveCommercial(Request $request,array $params):Response
    { $user=$this->auth->user();try{$draft=$this->quote($this->solicitudes->findForUser((int)$params['id'],(int)$user['user_id']), (int)$user['user_id']);$this->quotations->saveCommercial((int)$draft['id'],(int)$user['user_id'],$request->body());return Response::redirect('/app/solicitudes-cotizacion/'.(int)$params['id'].'?commercial=1');}catch(\Throwable $e){return Response::redirect('/app/solicitudes-cotizacion/'.(int)$params['id'].'?commercial_error='.rawurlencode($e->getMessage()));}}
    public function emitCommercial(Request $request,array $params):Response
    { $user=$this->auth->user();try{$draft=$this->quote($this->solicitudes->findForUser((int)$params['id'],(int)$user['user_id']), (int)$user['user_id']);$this->quotations->emit((int)$draft['id'],(int)$user['user_id']);return Response::redirect('/app/solicitudes-cotizacion/'.(int)$params['id'].'?emitted=1');}catch(\Throwable $e){return Response::redirect('/app/solicitudes-cotizacion/'.(int)$params['id'].'?commercial_error='.rawurlencode($e->getMessage()));}}

    public function savePrice(Request $request, array $params): Response
    {
        $user = $this->auth->user();
        try {
            $result=$this->quotations->setPrice((int)($params['id'] ?? 0), (int)($user['user_id'] ?? 0), (string)$request->input('precio_venta',''), (string)$request->input('motivo',''), (string)$request->input('cantidad','1'));
            if (($result['estado_precio'] ?? '') === 'REQUIERE_AUTORIZACION') {
                $sol=$this->solicitudes->findForUser((int)$params['id'], (int)($user['user_id'] ?? 0));
                $price=number_format((float)$result['precio_venta'],2,'.','');
                foreach ($this->quotations->authorizerRecipients() as $recipient) {
                    $this->notifications->create(['usuario_id'=>(int)$recipient['id'],'rol_codigo'=>(string)$recipient['rol_codigo'],'tipo'=>'cotizacion_pendiente','titulo'=>'Autorización de precio pendiente','mensaje'=>($sol['folio']??'Solicitud').' · '.($user['nombre_completo']??$user['username']??'Vendedor').' · Precio solicitado: $'.$price.' USD','prioridad'=>'normal','entidad_tipo'=>'solicitud_cotizacion','entidad_id'=>(int)$params['id'],'accion_url'=>'/app/precios/autorizaciones','origen'=>'erp','idempotency_key'=>'precio-auth:'.(int)$params['id'].':'.$price]);
                }
            }
            return Response::redirect('/app/solicitudes-cotizacion/'.(int)$params['id'].'?guardado=1');
        } catch (\Throwable $e) { $code = str_contains($e->getMessage(), 'inferior al mínimo') ? 'policy' : 'invalid'; return Response::redirect('/app/solicitudes-cotizacion/'.(int)$params['id'].'?error='.$code); }
    }

    public function authorizationIndex(Request $request): Response
    {
        $user=$this->auth->user(); $uid=(int)($user['user_id']??0);
        return Response::html(View::render('layouts/app', ['activeNavigation'=>'home','appName'=>(string)$this->config->get('app.name','SoporteGR ERP'),...$this->navigationPermissions($uid),'contentData'=>['authorizations'=>$this->quotations->pendingAuthorizations()],'contentView'=>'pricing/authorizations/index','context'=>$this->scopeContext->resolveForUser($uid)->toArray(),'csrf'=>$this->csrf,'pageTitle'=>'Autorizaciones de precio','user'=>$user]));
    }

    public function decideAuthorization(Request $request,array $params,bool $approve): Response
    { $user=$this->auth->user(); $aid=(int)($params['id']??0); try{$ctx=$this->quotations->authorizationContext($aid);$this->quotations->decideAuthorization($aid,(int)($user['user_id']??0),$approve);if($ctx){$this->notifications->create(['usuario_id'=>(int)$ctx['solicitado_por'],'rol_codigo'=>$this->quotations->roleCodeForUser((int)$ctx['solicitado_por'])??'ADMIN','tipo'=>$approve?'cotizacion_enviada':'seguimiento_pendiente','titulo'=>$approve?'Precio autorizado':'Precio rechazado','mensaje'=>(string)$ctx['folio'],'prioridad'=>'normal','entidad_tipo'=>'solicitud_cotizacion','entidad_id'=>(int)$ctx['solicitud_cotizacion_id'],'accion_url'=>'/app/solicitudes-cotizacion/'.(int)$ctx['solicitud_cotizacion_id'],'origen'=>'erp','idempotency_key'=>'precio-decision:'.$aid.':'.($approve?'approved':'rejected')]);}}catch(\Throwable $e){} return Response::redirect('/app/precios/autorizaciones'); }


    private function quote(array $solicitud, int $userId): ?array
    {
        $code = (string) ($solicitud['producto_codigo'] ?? $solicitud['producto_id_externo'] ?? '');
        if ($code === '') return null;
        try {
            $price = $this->quotations->resolvePrice($code);
            return $this->quotations->findDraft((int) $solicitud['id'], $userId)
                ?: $this->quotations->createDraft((int) $solicitud['id'], $userId, $price);
        } catch (\Throwable) { return null; }
    }
    /** @return array<string, bool> */
    private function navigationPermissions(int $userId): array
    {
        return [
            'canAccessProfile' => $this->permissions->allows($userId, 'perfil.ver'), 'canAccessCredential' => $this->permissions->allows($userId, 'credencial.ver'),
            'canAccessCatalogs' => $this->permissions->allows($userId, 'catalogos.acceder'), 'canAccessProducts' => $this->permissions->allows($userId, 'productos.acceder'),
            'canAccessProductPrices' => $this->permissions->allows($userId, 'precios.productos.acceder'), 'canAccessInventory' => $this->permissions->allows($userId, 'inventario.movimientos.acceder'),
            'canAccessInventoryStock' => $this->permissions->allows($userId, 'inventario.existencias.acceder'), 'canAccessInventorySerialStock' => $this->permissions->allows($userId, 'inventario.existencias_series.acceder'),
            'canAccessInventoryKardex' => $this->permissions->allows($userId, 'inventario.kardex.acceder'), 'canAccessInventorySerialKardex' => $this->permissions->allows($userId, 'inventario.kardex_series.acceder'),
            'canAccessInventoryTransfers' => $this->permissions->allows($userId, 'inventario.transferencias.acceder'),
            'canAccessConfiguration' => $this->permissions->allows($userId, 'configuracion.empresas.acceder') || $this->permissions->allows($userId, 'configuracion.almacenes.acceder') || $this->permissions->allows($userId, 'configuracion.folios.acceder') || $this->permissions->allows($userId, 'precios.listas.acceder'),
            'canAccessConfigCompanies' => $this->permissions->allows($userId, 'configuracion.empresas.acceder'), 'canAccessConfigWarehouses' => $this->permissions->allows($userId, 'configuracion.almacenes.acceder'),
            'canAccessConfigFolios' => $this->permissions->allows($userId, 'configuracion.folios.acceder'), 'canAccessPriceLists' => $this->permissions->allows($userId, 'precios.listas.acceder'), 'canAccessAudit' => $this->permissions->allows($userId, 'auditoria.ver'),
        ];
    }
}






