<?php

declare(strict_types=1);

use App\Infrastructure\Database\Seed;

return new class implements Seed {
    private const PERMISSIONS = [
        'productos.familias.ver' => 'Ver familias de productos',
        'productos.familias.editar' => 'Administrar familias de productos',
        'productos.atributos.ver' => 'Ver atributos de productos',
        'productos.atributos.editar' => 'Administrar atributos de productos',
        'productos.variantes.ver' => 'Ver variantes de productos',
        'productos.variantes.editar' => 'Administrar variantes de productos',
    ];
    public function id(): string { return 'productos_variantes_1_seed_permissions'; }
    public function run(PDO $pdo): void {
        $role=$pdo->query("SELECT id FROM roles WHERE codigo='ADMIN' AND activo=1 AND eliminado_en IS NULL LIMIT 1")->fetchColumn();
        if($role===false)throw new RuntimeException('Active ADMIN role is required.');
        foreach(self::PERMISSIONS as $code=>$name){
            $find=$pdo->prepare('SELECT id FROM permisos WHERE codigo=:code LIMIT 1');$find->execute(['code'=>$code]);$id=$find->fetchColumn();
            if($id===false){$ins=$pdo->prepare("INSERT INTO permisos (codigo,modulo,nombre,descripcion,es_sistema,activo) VALUES (:code,'productos',:name,:description,1,1)");$ins->execute(['code'=>$code,'name'=>$name,'description'=>$name]);$id=$pdo->lastInsertId();}
            $assign=$pdo->prepare('INSERT INTO rol_permisos (rol_id,permiso_id,activo) VALUES (:role,:permission,1) ON DUPLICATE KEY UPDATE activo=1,eliminado_en=NULL,eliminado_por=NULL');$assign->execute(['role'=>$role,'permission'=>$id]);
        }
    }
    public function rollback(PDO $pdo): void { foreach(array_keys(self::PERMISSIONS) as $code){$pdo->prepare('DELETE rp FROM rol_permisos rp INNER JOIN permisos p ON p.id=rp.permiso_id WHERE p.codigo=:code')->execute(['code'=>$code]);$pdo->prepare('DELETE FROM permisos WHERE codigo=:code AND es_sistema=1')->execute(['code'=>$code]);} }
};
