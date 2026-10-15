<?php

declare(strict_types=1);

use App\Infrastructure\Database\Seed;

return new class implements Seed {
    private const PERMISSIONS = [
        'productos.kits.ver' => 'Ver componentes de productos KIT',
        'productos.kits.editar' => 'Administrar componentes de productos KIT',
    ];

    public function id(): string { return 'productos_kits_1_seed_permissions'; }

    public function run(PDO $pdo): void
    {
        $role = $pdo->query("SELECT id FROM roles WHERE codigo='ADMIN' AND activo=1 AND eliminado_en IS NULL LIMIT 1")->fetchColumn();
        if ($role === false) { throw new RuntimeException('Active ADMIN role is required.'); }
        foreach (self::PERMISSIONS as $code => $name) {
            $find = $pdo->prepare('SELECT id FROM permisos WHERE codigo=? LIMIT 1');
            $find->execute([$code]);
            $id = $find->fetchColumn();
            if ($id === false) {
                $insert = $pdo->prepare('INSERT INTO permisos (codigo, modulo, nombre, descripcion, es_sistema, activo) VALUES (?, ?, ?, ?, 1, 1)');
                $insert->execute([$code, 'productos', $name, $name]);
                $id = $pdo->lastInsertId();
            }
            $assign = $pdo->prepare('INSERT INTO rol_permisos (rol_id, permiso_id, activo) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE activo=1, eliminado_en=NULL, eliminado_por=NULL');
            $assign->execute([$role, $id]);
        }
    }

    public function rollback(PDO $pdo): void
    {
        foreach (array_keys(self::PERMISSIONS) as $code) {
            $find = $pdo->prepare('SELECT id FROM permisos WHERE codigo=?');
            $find->execute([$code]);
            $id = $find->fetchColumn();
            if ($id !== false) {
                $pdo->prepare('DELETE FROM rol_permisos WHERE permiso_id=?')->execute([$id]);
            }
            $pdo->prepare('DELETE FROM permisos WHERE codigo=? AND es_sistema=1')->execute([$code]);
        }
    }
};
