<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config; use App\Core\Request; use App\Core\Response; use App\Core\View; use App\Domain\Auth\AuthService; use App\Domain\Products\ProductAttributeService; use App\Domain\Products\ProductVariantException; use App\Domain\Security\PermissionService; use App\Domain\Scope\ScopeContextService; use App\Support\Security\CsrfTokenService;

final class ProductAttributeController
{
    public function __construct(private readonly Config $config, private readonly AuthService $auth, private readonly PermissionService $permissions, private readonly ScopeContextService $scope, private readonly CsrfTokenService $csrf, private readonly ProductAttributeService $attributes) { }
    public function index(Request $request): Response { $items=$this->attributes->all();foreach($items as &$item){$item['values']=$this->attributes->values((int)$item['atributo_id']);}return $this->render(['attributes'=>$items,'errors'=>[]],'Atributos de producto'); }
    public function store(Request $request): Response { try{$this->attributes->create($request->body(),(int)$this->user()['user_id']);return Response::redirect('/productos/atributos?result=created');}catch(ProductVariantException $e){return $this->render(['attributes'=>$this->attributes->all(),'errors'=>$e->errors()],'Atributos de producto',422);} }
    public function storeValue(Request $request): Response { try{$this->attributes->createValue((int)$request->input('atributo_id'),$request->body(),(int)$this->user()['user_id']);return Response::redirect('/productos/atributos?result=value_created');}catch(ProductVariantException $e){return $this->render(['attributes'=>$this->attributes->all(),'errors'=>$e->errors()],'Atributos de producto',422);} }
    private function user(): array {$u=$this->auth->user();if($u===null)throw new \RuntimeException('Authenticated attribute controller requires a user.');return $u;}
    private function render(array $data,string $title,int $status=200): Response {$u=$this->user();return Response::html(View::render('layouts/app',['activeNavigation'=>'products','appName'=>(string)$this->config->get('app.name','SoporteGR ERP'),'canAccessCatalogs'=>$this->permissions->allows($u['user_id'],'catalogos.acceder'),'canAccessProducts'=>true,'contentData'=>$data,'contentView'=>'products/variants/attributes','context'=>$this->scope->resolveForUser($u['user_id'])->toArray(),'csrf'=>$this->csrf,'pageTitle'=>$title,'scripts'=>[],'stylesheets'=>['/css/modules/products.css'],'user'=>$u]),$status);}
}
