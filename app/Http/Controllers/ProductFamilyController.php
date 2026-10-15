<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\Auth\AuthService;
use App\Domain\Products\ProductFamilyService;
use App\Domain\Products\ProductVariantException;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContextService;
use App\Support\Security\CsrfTokenService;

final class ProductFamilyController
{
    public function __construct(private readonly Config $config, private readonly AuthService $auth, private readonly PermissionService $permissions, private readonly ScopeContextService $scope, private readonly CsrfTokenService $csrf, private readonly ProductFamilyService $families) { }
    public function index(Request $request): Response { return $this->render('products/variants/families', ['families'=>$this->families->all(),'errors'=>[]],'Familias de productos'); }
    public function store(Request $request): Response { try{$this->families->create($request->body(),(int)$this->user()['user_id']);return Response::redirect('/productos/familias?result=created');}catch(ProductVariantException $e){return $this->render('products/variants/families',['families'=>$this->families->all(),'errors'=>$e->errors()],'Familias de productos',422);} }
    public function update(Request $request): Response { $id=(int)$request->input('familia_id');try{$this->families->update($id,$request->body(),(int)$this->user()['user_id']);return Response::redirect('/productos/familias?result=updated');}catch(ProductVariantException $e){return $this->render('products/variants/families',['families'=>$this->families->all(),'errors'=>$e->errors()],'Familias de productos',422);} }
    private function user(): array { $u=$this->auth->user();if($u===null)throw new \RuntimeException('Authenticated family controller requires a user.');return $u; }
    private function render(string $view,array $data,string $title,int $status=200): Response { $u=$this->user();return Response::html(View::render('layouts/app',['activeNavigation'=>'products','appName'=>(string)$this->config->get('app.name','SoporteGR ERP'),'canAccessCatalogs'=>$this->permissions->allows($u['user_id'],'catalogos.acceder'),'canAccessProducts'=>true,'contentData'=>$data,'contentView'=>$view,'context'=>$this->scope->resolveForUser($u['user_id'])->toArray(),'csrf'=>$this->csrf,'pageTitle'=>$title,'scripts'=>[],'stylesheets'=>['/css/modules/products.css'],'user'=>$u]),$status); }
}
