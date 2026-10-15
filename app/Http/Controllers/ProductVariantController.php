<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config; use App\Core\Request; use App\Core\Response; use App\Core\View; use App\Domain\Auth\AuthService; use App\Domain\Products\ProductFamilyService; use App\Domain\Products\ProductVariantException; use App\Domain\Products\ProductVariantService; use App\Domain\Security\PermissionService; use App\Domain\Scope\ScopeContextService; use App\Support\Security\CsrfTokenService;

final class ProductVariantController
{
    public function __construct(private readonly Config $config, private readonly AuthService $auth, private readonly PermissionService $permissions, private readonly ScopeContextService $scope, private readonly CsrfTokenService $csrf, private readonly ProductFamilyService $families, private readonly ProductVariantService $variants) { }
    public function index(Request $request): Response { $family=(int)($request->query()['familia_id']??0);$row=$this->families->find($family);if($row===null)return Response::html(View::render('errors/404'),404);return $this->render(['family'=>$row,'attributes'=>$this->families->attributes($family),'members'=>$this->variants->members($family),'errors'=>[]],'Variantes de producto'); }
    public function link(Request $request): Response { $body=$request->body();$family=(int)($body['familia_id']??0);try{$this->variants->link($family,(string)($body['id_producto']??''),$this->rows($body['atributos']??[]),(int)$this->user()['user_id'],(string)($body['es_predeterminada']??'')==='1');return Response::redirect('/productos/variantes?familia_id='.$family.'&result=linked');}catch(ProductVariantException $e){return Response::redirect('/productos/variantes?familia_id='.$family.'&result=error');} }
    public function unlink(Request $request): Response { $product=(string)$request->input('id_producto');try{$this->variants->unlink($product,(int)$this->user()['user_id']);}catch(ProductVariantException){}return Response::redirect('/productos/variantes?familia_id='.(int)$request->input('familia_id').'&result=unlinked'); }
    public function values(Request $request): Response { try{$this->variants->updateValues((string)$request->input('id_producto'),$this->rows($request->input('atributos',[])),(int)$this->user()['user_id']);}catch(ProductVariantException){}return Response::redirect('/productos/variantes?familia_id='.(int)$request->input('familia_id').'&result=updated'); }
    public function default(Request $request): Response { try{$this->variants->setDefault((string)$request->input('id_producto'),(int)$this->user()['user_id']);}catch(ProductVariantException){}return Response::redirect('/productos/variantes?familia_id='.(int)$request->input('familia_id').'&result=default'); }
    private function rows(mixed $value): array { if(!is_array($value))return []; $rows=[];foreach($value as $attribute=>$item){$rows[]=['attribute'=>(int)$attribute,'value'=>(int)(is_array($item)?($item['value']??0):$item)];}return $rows; }
    private function user(): array {$u=$this->auth->user();if($u===null)throw new \RuntimeException('Authenticated variant controller requires a user.');return $u;}
    private function render(array $data,string $title,int $status=200): Response {$u=$this->user();return Response::html(View::render('layouts/app',['activeNavigation'=>'products','appName'=>(string)$this->config->get('app.name','SoporteGR ERP'),'canAccessCatalogs'=>$this->permissions->allows($u['user_id'],'catalogos.acceder'),'canAccessProducts'=>true,'contentData'=>$data,'contentView'=>'products/variants/index','context'=>$this->scope->resolveForUser($u['user_id'])->toArray(),'csrf'=>$this->csrf,'pageTitle'=>$title,'scripts'=>[],'stylesheets'=>['/css/modules/products.css'],'user'=>$u]),$status);}
}
