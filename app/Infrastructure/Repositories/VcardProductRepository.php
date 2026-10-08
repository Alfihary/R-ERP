<?php
declare(strict_types=1);
namespace App\Infrastructure\Repositories;
use App\Infrastructure\Database\ConnectionProvider;
use PDO;
final class VcardProductRepository
{
    public function __construct(private readonly ConnectionProvider $connection){}
    public function pdo():PDO{return $this->connection->pdo();}
    public function activeUser(int $userId):?array{$q=$this->pdo()->prepare('SELECT id,username,email FROM usuarios WHERE id=:id AND activo=1 AND deleted_at IS NULL LIMIT 1');$q->execute(['id'=>$userId]);$r=$q->fetch(PDO::FETCH_ASSOC);return is_array($r)?$r:null;}
    public function vcardByUser(int $userId):?array{$q=$this->pdo()->prepare('SELECT id,usuario_id,slug_publico AS slug,activa AS publicada FROM usuario_vcards WHERE usuario_id=:id AND deleted_at IS NULL LIMIT 1');$q->execute(['id'=>$userId]);$r=$q->fetch(PDO::FETCH_ASSOC);return is_array($r)?$r:null;}
    private function productId(string $code):?int{$q=$this->pdo()->prepare('SELECT id FROM productos WHERE id_producto=:code AND deleted_at IS NULL LIMIT 1');$q->execute(['code'=>$code]);$id=$q->fetchColumn();return $id===false?null:(int)$id;}
    public function productExists(string $productId):bool{return $this->productId($productId)!==null;}
    public function activeProductExists(string $productId):bool{$q=$this->pdo()->prepare('SELECT COUNT(*) FROM productos WHERE id_producto=:code AND activo=1 AND deleted_at IS NULL');$q->execute(['code'=>$productId]);return (int)$q->fetchColumn()===1;}
    public function searchActiveProducts(string $term,int $limit=10):array{$term=trim($term);if($term==='')return[];$limit=max(1,min(25,$limit));$sql='SELECT p.id_producto,p.descripcion,u.codigo AS unidad_codigo,u.nombre AS unidad_nombre,m.nombre AS marca_nombre,l.nombre AS linea_nombre,c.nombre AS clasificacion_nombre FROM productos p JOIN unidades_medida u ON u.id=p.unidad_medida_id AND u.deleted_at IS NULL LEFT JOIN marcas m ON m.id=p.marca_id AND m.deleted_at IS NULL LEFT JOIN lineas_producto l ON l.id=p.linea_producto_id AND l.deleted_at IS NULL LEFT JOIN clasificaciones_producto c ON c.id=p.clasificacion_producto_id AND c.deleted_at IS NULL WHERE p.activo=1 AND p.deleted_at IS NULL AND (p.id_producto LIKE :code OR p.descripcion LIKE :description) ORDER BY p.descripcion,p.id_producto LIMIT '.$limit;$q=$this->pdo()->prepare($sql);$q->execute(['code'=>'%'.$term.'%','description'=>'%'.$term.'%']);return $q->fetchAll(PDO::FETCH_ASSOC);}
    public function privateLinkedProducts(int $vcardId):array{$sql=<<<'SQL'
SELECT p.id_producto,vp.activo,vp.orden,p.descripcion,p.activo AS producto_activo,
       u.codigo AS unidad_codigo,u.nombre AS unidad_nombre,m.nombre AS marca_nombre,l.nombre AS linea_nombre,c.nombre AS clasificacion_nombre
FROM usuario_vcard_productos vp JOIN productos p ON p.id=vp.producto_id AND p.deleted_at IS NULL
JOIN unidades_medida u ON u.id=p.unidad_medida_id AND u.deleted_at IS NULL LEFT JOIN marcas m ON m.id=p.marca_id AND m.deleted_at IS NULL
LEFT JOIN lineas_producto l ON l.id=p.linea_producto_id AND l.deleted_at IS NULL LEFT JOIN clasificaciones_producto c ON c.id=p.clasificacion_producto_id AND c.deleted_at IS NULL
WHERE vp.usuario_vcard_id=:id AND vp.deleted_at IS NULL ORDER BY vp.orden,p.descripcion,p.id_producto
SQL;$q=$this->pdo()->prepare($sql);$q->execute(['id'=>$vcardId]);return $q->fetchAll(PDO::FETCH_ASSOC);}
    public function syncProducts(int $vcardId,int $userId,array $products):void{$this->pdo()->prepare('UPDATE usuario_vcard_productos SET activo=0,deleted_at=CURRENT_TIMESTAMP,deleted_by=:deleted_by,updated_by=:updated_by WHERE usuario_vcard_id=:vcard')->execute(['deleted_by'=>$userId,'updated_by'=>$userId,'vcard'=>$vcardId]);foreach($products as $p)$this->upsertProduct($vcardId,$userId,(string)$p['id_producto'],(int)$p['activo'],0,null);}
    public function upsertProduct(int $vcardId,int $userId,string $productId,int $active,int $featured,?string $publicText):void{$numeric=$this->productId($productId);if($numeric===null)return;$q=$this->pdo()->prepare('SELECT id,deleted_at FROM usuario_vcard_productos WHERE usuario_vcard_id=:vcard AND producto_id=:product ORDER BY (deleted_at IS NULL) DESC,id DESC LIMIT 1');$q->execute(['vcard'=>$vcardId,'product'=>$numeric]);$link=$q->fetch(PDO::FETCH_ASSOC);if(is_array($link)){if($link['deleted_at']!==null){$restore=$this->pdo()->prepare('UPDATE usuario_vcard_productos SET activo=:active,deleted_at=NULL,deleted_by=NULL,updated_by=:updated_by,updated_at=CURRENT_TIMESTAMP WHERE id=:id AND deleted_at IS NOT NULL');$restore->execute(['active'=>$active,'updated_by'=>$userId,'id'=>(int)$link['id']]);return;}$this->updateLinkById((int)$link['id'],$active,$userId);return;}$order=$this->nextOrder($vcardId);$insert=$this->pdo()->prepare('INSERT INTO usuario_vcard_productos(usuario_vcard_id,producto_id,activo,orden,created_by,updated_by) VALUES(:vcard,:product,:active,:order,:created_by,:updated_by)');$insert->execute(['vcard'=>$vcardId,'product'=>$numeric,'active'=>$active,'order'=>$order,'created_by'=>$userId,'updated_by'=>$userId]);}
    public function updateProductLink(int $vcardId,string $productId,int $active,int $featured,?string $publicText):bool{$numeric=$this->productId($productId);if($numeric===null)return false;$q=$this->pdo()->prepare('UPDATE usuario_vcard_productos SET activo=:active,updated_at=CURRENT_TIMESTAMP WHERE usuario_vcard_id=:vcard AND producto_id=:product AND deleted_at IS NULL');$q->execute(['active'=>$active,'vcard'=>$vcardId,'product'=>$numeric]);return $q->rowCount()>0;}
    public function removeProductLink(int $vcardId,string $productId):bool{$numeric=$this->productId($productId);if($numeric===null)return false;$q=$this->pdo()->prepare('UPDATE usuario_vcard_productos SET activo=0,deleted_at=CURRENT_TIMESTAMP WHERE usuario_vcard_id=:vcard AND producto_id=:product AND deleted_at IS NULL');$q->execute(['vcard'=>$vcardId,'product'=>$numeric]);return $q->rowCount()>0;}
    private function updateLinkById(int $id,int $active,int $userId):void{$q=$this->pdo()->prepare('UPDATE usuario_vcard_productos SET activo=:active,updated_by=:actor,updated_at=CURRENT_TIMESTAMP WHERE id=:id');$q->execute(['active'=>$active,'actor'=>$userId,'id'=>$id]);}
    private function nextOrder(int $vcardId):int{$q=$this->pdo()->prepare('SELECT COALESCE(MAX(orden),0)+10 FROM usuario_vcard_productos WHERE usuario_vcard_id=:id AND deleted_at IS NULL');$q->execute(['id'=>$vcardId]);return max(10,(int)$q->fetchColumn());}
    public function publicProductsBySlug(string $slug):array{$sql=<<<'SQL'
SELECT p.id_producto,p.descripcion,vp.orden,u.codigo AS unidad_codigo,u.nombre AS unidad_nombre,
       m.nombre AS marca_nombre,l.nombre AS linea_nombre,c.nombre AS clasificacion_nombre,pd.id AS imagen_id
FROM usuario_vcards v JOIN usuarios usr ON usr.id=v.usuario_id AND usr.activo=1 AND usr.deleted_at IS NULL
JOIN usuario_vcard_productos vp ON vp.usuario_vcard_id=v.id AND vp.activo=1 AND vp.deleted_at IS NULL
JOIN productos p ON p.id=vp.producto_id AND p.activo=1 AND p.deleted_at IS NULL JOIN unidades_medida u ON u.id=p.unidad_medida_id AND u.deleted_at IS NULL
LEFT JOIN marcas m ON m.id=p.marca_id AND m.deleted_at IS NULL LEFT JOIN lineas_producto l ON l.id=p.linea_producto_id AND l.deleted_at IS NULL
LEFT JOIN clasificaciones_producto c ON c.id=p.clasificacion_producto_id AND c.deleted_at IS NULL
LEFT JOIN producto_documentos pd ON pd.producto_id=p.id AND pd.tipo_documento=:tipo AND pd.es_principal=1 AND pd.activo=1 AND pd.deleted_at IS NULL
WHERE v.slug_publico=:slug AND v.activa=1 AND v.deleted_at IS NULL AND v.mostrar_productos_publicos=1
ORDER BY vp.orden,p.descripcion,p.id_producto
SQL;$q=$this->pdo()->prepare($sql);$q->execute(['slug'=>$slug,'tipo'=>ProductDocumentRepository::TYPE_MAIN_PHOTO]);return $q->fetchAll(PDO::FETCH_ASSOC);}
    public function publicProductMainPhotoBySlug(string $slug,string $productId):?array{$sql=<<<'SQL'
SELECT pd.id,pd.producto_id AS id_producto,pd.archivo_path AS ruta_relativa,pd.mime_type,pd.size_bytes AS tamano_bytes,pd.created_at AS creado_en,NULL AS actualizado_en
FROM usuario_vcards v JOIN usuarios usr ON usr.id=v.usuario_id AND usr.activo=1 AND usr.deleted_at IS NULL
JOIN usuario_vcard_productos vp ON vp.usuario_vcard_id=v.id AND vp.activo=1 AND vp.deleted_at IS NULL
JOIN productos p ON p.id=vp.producto_id AND p.id_producto=:code AND p.activo=1 AND p.deleted_at IS NULL
JOIN producto_documentos pd ON pd.producto_id=p.id AND pd.tipo_documento=:tipo AND pd.es_principal=1 AND pd.activo=1 AND pd.deleted_at IS NULL
WHERE v.slug_publico=:slug AND v.activa=1 AND v.deleted_at IS NULL AND v.mostrar_productos_publicos=1 ORDER BY pd.id DESC LIMIT 1
SQL;$q=$this->pdo()->prepare($sql);$q->execute(['slug'=>$slug,'code'=>$productId,'tipo'=>ProductDocumentRepository::TYPE_MAIN_PHOTO]);$r=$q->fetch(PDO::FETCH_ASSOC);return is_array($r)?$r:null;}
}
