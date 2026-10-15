<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Domain\Auth\AuthService;
use App\Domain\Products\ProductKitException;
use App\Domain\Products\ProductKitService;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContextService;
use App\Support\Security\CsrfTokenService;

final class ProductKitController
{
    public function __construct(private readonly Config $config, private readonly AuthService $auth, private readonly PermissionService $permissions, private readonly ScopeContextService $scopeContext, private readonly CsrfTokenService $csrf, private readonly ProductKitService $kits) {}
    public function index(Request $request): Response { $id=(string)($request->query()['kit_id_producto']??''); return Response::json(['items'=>$this->kits->listComponents($id)]); }
    public function search(Request $request): Response { $q=$request->query();return Response::json(['items'=>$this->kits->searchEligibleComponents((string)($q['kit_id_producto']??''),(string)($q['q']??''),(int)($q['limit']??10))]); }
    public function add(Request $request): Response { return $this->mutate($request,'add'); }
    public function update(Request $request): Response { return $this->mutate($request,'update'); }
    public function remove(Request $request): Response { $id=(string)$request->input('kit_id_producto');$component=(string)$request->input('componente_id_producto');try{$this->kits->removeComponent($id,$component,(int)$this->user()['user_id']);}catch(ProductKitException){return Response::redirect('/productos/editar?id_producto='.rawurlencode($id).'&result=kit_error');}return Response::redirect('/productos/editar?id_producto='.rawurlencode($id).'&result=kit_component_removed'); }
    private function mutate(Request $request,string $mode): Response { $id=(string)$request->input('kit_id_producto');$component=(string)$request->input('componente_id_producto');$quantity=(string)$request->input('cantidad');try{if($mode==='add')$this->kits->addComponent($id,$component,$quantity,(int)$this->user()['user_id']);else $this->kits->updateQuantity($id,$component,$quantity,(int)$this->user()['user_id']);}catch(ProductKitException){return Response::redirect('/productos/editar?id_producto='.rawurlencode($id).'&result=kit_error');}return Response::redirect('/productos/editar?id_producto='.rawurlencode($id).'&result=kit_component_saved'); }
    private function user(): array { $user=$this->auth->user();if($user===null)throw new \RuntimeException('Authenticated KIT controller requires a user.');return $user; }
}
