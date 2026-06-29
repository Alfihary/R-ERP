<?php

declare(strict_types=1);

use App\Infrastructure\Database\Seed;

return new class implements Seed {
    public function id(): string
    {
        return 'db_core_0_seed_admin_role';
    }

    public function run(PDO $pdo): void
    {
        $find = $pdo->prepare(
            'SELECT id, es_sistema FROM roles WHERE codigo = :codigo'
        );
        $find->execute(['codigo' => 'ADMIN']);
        $existing = $find->fetch();

        if ($existing === false) {
            $insert = $pdo->prepare(
                <<<'SQL'
                INSERT INTO roles (codigo, nombre, descripcion, es_sistema, activo)
                VALUES (:codigo, :nombre, :descripcion, 1, 1)
                SQL
            );
            $insert->execute([
                'codigo' => 'ADMIN',
                'nombre' => 'Administrador',
                'descripcion' => 'Rol estructural reservado para administración futura.',
            ]);
            return;
        }

        if ((int) $existing['es_sistema'] !== 1) {
            throw new RuntimeException('The ADMIN role exists but is not marked as a system role.');
        }

        $update = $pdo->prepare(
            <<<'SQL'
            UPDATE roles
            SET nombre = :nombre,
                descripcion = :descripcion,
                activo = 1,
                eliminado_en = NULL,
                eliminado_por = NULL
            WHERE id = :id
            SQL
        );
        $update->execute([
            'id' => $existing['id'],
            'nombre' => 'Administrador',
            'descripcion' => 'Rol estructural reservado para administración futura.',
        ]);
    }

    public function rollback(PDO $pdo): void
    {
        $statement = $pdo->prepare(
            <<<'SQL'
            DELETE FROM roles
            WHERE codigo = :codigo
              AND es_sistema = 1
              AND NOT EXISTS (
                  SELECT 1
                  FROM usuario_roles
                  WHERE usuario_roles.rol_id = roles.id
              )
              AND NOT EXISTS (
                  SELECT 1
                  FROM rol_permisos
                  WHERE rol_permisos.rol_id = roles.id
              )
            SQL
        );
        $statement->execute(['codigo' => 'ADMIN']);
    }
};
