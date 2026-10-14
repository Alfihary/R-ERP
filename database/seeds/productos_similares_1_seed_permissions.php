<?php

declare(strict_types=1);

use App\Infrastructure\Database\Seed;

return new class implements Seed {
    private const PERMISSIONS = [
        'productos.similares.ver' => 'Ver productos similares',
        'productos.similares.editar' => 'Administrar productos similares',
    ];

    public function id(): string
    {
        return 'productos_similares_1_seed_permissions';
    }

    public function run(PDO $pdo): void
    {
        $role = $pdo->query("SELECT id FROM roles WHERE codigo='ADMIN' AND activo=1 AND eliminado_en IS NULL LIMIT 1")->fetchColumn();
        if ($role === false) { throw new RuntimeException('Active ADMIN role is required.'); }
        foreach (self::PERMISSIONS as $code => $name) {
            $find = $pdo->prepare('SELECT id FROM permisos WHERE codigo=:codigo LIMIT 1');
            $find->execute(['codigo' => $code]);
            $id = $find->fetchColumn();
            if ($id === false) {
                $insert = $pdo->prepare('INSERT INTO permisos (codigo, modulo, nombre, descripcion, es_sistema, activo) VALUES (:codigo, :modulo, :nombre, :descripcion, 1, 1)');
                $insert->execute(['codigo'=>$code,'modulo'=>'productos','nombre'=>$name,'descripcion'=>$name]);
                $id = $pdo->lastInsertId();
            }
            $assign = $pdo->prepare('INSERT INTO rol_permisos (rol_id, permiso_id, activo) VALUES (:rol, :permiso, 1) ON DUPLICATE KEY UPDATE activo=1, eliminado_en=NULL, eliminado_por=NULL');
            $assign->execute(['rol'=>$role,'permiso'=>$id]);
        }
    }

    public function rollback(PDO $pdo): void
    {
        foreach (array_keys(self::PERMISSIONS) as $code) {
            $pdo->prepare('DELETE rp FROM rol_permisos rp INNER JOIN permisos p ON p.id=rp.permiso_id WHERE p.codigo=:codigo')->execute(['codigo'=>$code]);
            $pdo->prepare('DELETE FROM permisos WHERE codigo=:codigo AND es_sistema=1')->execute(['codigo'=>$code]);
        }
    }
};
