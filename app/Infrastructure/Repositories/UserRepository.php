<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

use App\Infrastructure\Database\ConnectionProvider;

final class UserRepository
{
    public function __construct(private readonly ConnectionProvider $connection)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForAuthentication(string $login): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            SELECT id, username, email, password_hash, activo, eliminado_en
            FROM usuarios
            WHERE username = :username OR email = :email
            LIMIT 1
            SQL
        );
        $statement->execute([
            'username' => $login,
            'email' => $login,
        ]);
        $user = $statement->fetch();

        return $user === false ? null : $user;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUsername(string $username): ?array
    {
        return $this->findByField('username', $username);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        return $this->findByField('email', $email);
    }

    public function count(): int
    {
        return (int) $this->connection->pdo()
            ->query('SELECT COUNT(*) FROM usuarios')
            ->fetchColumn();
    }

    /**
     * @param array{q?:string,status?:string,company_id?:int,warehouse_id?:int,role_id?:int,page?:int,per_page?:int} $filters
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,per_page:int,filters:array<string,mixed>}
     */
    public function paginateAdmin(array $filters): array
    {
        [$where, $parameters] = $this->adminConditions($filters);
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? 20)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        $statement = $this->connection->pdo()->prepare(
            'SELECT u.id, u.username, u.email, u.activo, u.eliminado_en,
                    u.ultimo_acceso_en,
                    p.primer_nombre, p.segundo_nombre,
                    p.apellido_paterno, p.apellido_materno,
                    e.nombre AS empresa_nombre, a.nombre AS almacen_nombre,
                    GROUP_CONCAT(DISTINCT r.nombre ORDER BY r.nombre SEPARATOR \' | \') AS roles
             FROM usuarios u
             LEFT JOIN perfiles_usuario p ON p.usuario_id = u.id
             LEFT JOIN usuario_empresas ue ON ue.usuario_id = u.id
                AND ue.activo = 1 AND ue.eliminado_en IS NULL
             LEFT JOIN empresas e ON e.id = ue.empresa_id
                AND e.activo = 1 AND e.eliminado_en IS NULL
             LEFT JOIN usuario_almacenes ua ON ua.usuario_id = u.id
                AND ua.activo = 1 AND ua.eliminado_en IS NULL
             LEFT JOIN almacenes a ON a.id = ua.almacen_id
                AND a.empresa_id = ua.empresa_id
                AND a.activo = 1 AND a.eliminado_en IS NULL
             LEFT JOIN usuario_roles ur ON ur.usuario_id = u.id
                AND ur.activo = 1 AND ur.eliminado_en IS NULL
             LEFT JOIN roles r ON r.id = ur.rol_id
                AND r.activo = 1 AND r.eliminado_en IS NULL
             WHERE ' . $where . '
             GROUP BY u.id, u.username, u.email, u.activo, u.eliminado_en,
                      u.ultimo_acceso_en, p.primer_nombre, p.segundo_nombre,
                      p.apellido_paterno, p.apellido_materno, e.nombre, a.nombre
             ORDER BY u.username, u.id
             LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $statement->execute($parameters);

        $count = $this->connection->pdo()->prepare(
            'SELECT COUNT(DISTINCT u.id)
             FROM usuarios u
             LEFT JOIN perfiles_usuario p ON p.usuario_id = u.id
             LEFT JOIN usuario_empresas ue ON ue.usuario_id = u.id
                AND ue.activo = 1 AND ue.eliminado_en IS NULL
             LEFT JOIN usuario_almacenes ua ON ua.usuario_id = u.id
                AND ua.activo = 1 AND ua.eliminado_en IS NULL
             LEFT JOIN usuario_roles ur ON ur.usuario_id = u.id
                AND ur.activo = 1 AND ur.eliminado_en IS NULL
             WHERE ' . $where
        );
        $count->execute($parameters);

        return [
            'rows' => $statement->fetchAll(),
            'total' => (int) $count->fetchColumn(),
            'page' => $page,
            'per_page' => $perPage,
            'filters' => [
                'q' => (string) ($filters['q'] ?? ''),
                'status' => (string) ($filters['status'] ?? 'active'),
                'company_id' => (int) ($filters['company_id'] ?? 0),
                'warehouse_id' => (int) ($filters['warehouse_id'] ?? 0),
                'role_id' => (int) ($filters['role_id'] ?? 0),
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    public function findAdminById(int $userId): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT u.id, u.username, u.email, u.activo, u.eliminado_en,
                    u.ultimo_acceso_en, p.primer_nombre, p.segundo_nombre,
                    p.apellido_paterno, p.apellido_materno,
                    ue.empresa_id, ua.almacen_id
             FROM usuarios u
             LEFT JOIN perfiles_usuario p ON p.usuario_id = u.id
             LEFT JOIN usuario_empresas ue ON ue.usuario_id = u.id
                AND ue.activo = 1 AND ue.eliminado_en IS NULL
             LEFT JOIN usuario_almacenes ua ON ua.usuario_id = u.id
                AND ua.activo = 1 AND ua.eliminado_en IS NULL
             WHERE u.id = :id LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function adminConditions(array $filters): array
    {
        $where = ['1=1'];
        $parameters = [];
        $status = (string) ($filters['status'] ?? 'active');
        if ($status === 'active') {
            $where[] = 'u.activo = 1 AND u.eliminado_en IS NULL';
        } elseif ($status === 'inactive') {
            $where[] = 'u.activo = 0 AND u.eliminado_en IS NULL';
        } elseif ($status === 'deleted') {
            $where[] = 'u.eliminado_en IS NOT NULL';
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(u.username LIKE :q_username OR u.email LIKE :q_email OR '
                . 'CONCAT_WS(\' \', p.primer_nombre, p.segundo_nombre, '
                . 'p.apellido_paterno, p.apellido_materno) LIKE :q_profile)';
            $search = '%' . $q . '%';
            $parameters['q_username'] = $search;
            $parameters['q_email'] = $search;
            $parameters['q_profile'] = $search;
        }
        foreach ([
            'company_id' => 'ue.empresa_id',
            'warehouse_id' => 'ua.almacen_id',
            'role_id' => 'ur.rol_id',
        ] as $key => $column) {
            $value = filter_var($filters[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($value !== false) {
                $where[] = $column . ' = :' . $key;
                $parameters[$key] = (int) $value;
            }
        }
        return [implode(' AND ', $where), $parameters];
    }

    public function create(string $username, string $email, string $passwordHash): int
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            INSERT INTO usuarios (username, email, password_hash, activo)
            VALUES (:username, :email, :password_hash, 1)
            SQL
        );
        $statement->execute([
            'username' => $username,
            'email' => $email,
            'password_hash' => $passwordHash,
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    public function updatePasswordHash(int $userId, string $passwordHash): void
    {
        $statement = $this->connection->pdo()->prepare(
            <<<'SQL'
            UPDATE usuarios
            SET password_hash = :password_hash,
                actualizado_en = CURRENT_TIMESTAMP,
                actualizado_por = :actualizado_por
            WHERE id = :id
            SQL
        );
        $statement->execute([
            'id' => $userId,
            'password_hash' => $passwordHash,
            'actualizado_por' => $userId,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function findByIdForUpdate(int $userId): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT id, username, email, password_hash, activo, ultimo_acceso_en,
                    actualizado_en, eliminado_en
             FROM usuarios WHERE id = :id LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch();

        return $user === false ? null : $user;
    }

    public function existsUsername(string $username, ?int $exceptUserId = null): bool
    {
        return $this->exists('username', $username, $exceptUserId);
    }

    public function existsEmail(string $email, ?int $exceptUserId = null): bool
    {
        return $this->exists('email', $email, $exceptUserId);
    }

    /** @param array{username:string,email:string,password_hash:string,activo:int,creado_por:int,actualizado_por:int} $data */
    public function insertAdmin(array $data): int
    {
        $statement = $this->connection->pdo()->prepare(
            'INSERT INTO usuarios (username, email, password_hash, activo, creado_por, actualizado_por)
             VALUES (:username, :email, :password_hash, :activo, :creado_por, :actualizado_por)'
        );
        $statement->execute($data);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    /** @param array{username:string,email:string,actualizado_por:int} $data */
    public function updateAdmin(int $userId, array $data): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE usuarios SET username = :username, email = :email,
                    actualizado_por = :actualizado_por
             WHERE id = :id AND eliminado_en IS NULL'
        );
        $statement->execute($data + ['id' => $userId]);
    }

    public function setActive(int $userId, bool $active, int $actorId): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE usuarios SET activo = :activo, actualizado_por = :actor_id
             WHERE id = :id AND eliminado_en IS NULL'
        );
        $statement->execute([
            'id' => $userId,
            'activo' => $active ? 1 : 0,
            'actor_id' => $actorId,
        ]);
    }

    public function softDelete(int $userId, int $actorId): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE usuarios SET activo = 0, eliminado_en = CURRENT_TIMESTAMP,
                    eliminado_por = :actor_deleted, actualizado_por = :actor_updated
             WHERE id = :id AND eliminado_en IS NULL'
        );
        $statement->execute([
            'id' => $userId,
            'actor_deleted' => $actorId,
            'actor_updated' => $actorId,
        ]);
    }

    public function updateAdminPassword(int $userId, string $hash, int $actorId): void
    {
        $statement = $this->connection->pdo()->prepare(
            'UPDATE usuarios SET password_hash = :hash, actualizado_por = :actor_id
             WHERE id = :id AND eliminado_en IS NULL'
        );
        $statement->execute(['id' => $userId, 'hash' => $hash, 'actor_id' => $actorId]);
    }

    /** @return list<array{id:int,username:string,email:string,activo:int,eliminado_en:string|null}> */
    public function usableAdminsForUpdate(int $adminRoleId): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT u.id, u.username, u.email, u.activo, u.eliminado_en
             FROM usuarios u
             INNER JOIN usuario_roles ur ON ur.usuario_id = u.id
                AND ur.rol_id = :rol_id AND ur.activo = 1 AND ur.eliminado_en IS NULL
             WHERE u.activo = 1 AND u.eliminado_en IS NULL
             ORDER BY u.id FOR UPDATE'
        );
        $statement->execute(['rol_id' => $adminRoleId]);

        return $statement->fetchAll();
    }

    /** @param callable(): mixed $operation */
    public function transactional(callable $operation): mixed
    {
        $pdo = $this->connection->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        }
        try {
            $result = $operation();
            if ($owns) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $exception) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function exists(string $field, string $value, ?int $exceptUserId): bool
    {
        if (!in_array($field, ['username', 'email'], true)) {
            throw new \InvalidArgumentException('Unsupported user uniqueness field.');
        }
        $sql = 'SELECT COUNT(*) FROM usuarios WHERE ' . $field . ' = :value';
        $parameters = ['value' => $value];
        if ($exceptUserId !== null) {
            $sql .= ' AND id <> :id';
            $parameters['id'] = $exceptUserId;
        }
        $statement = $this->connection->pdo()->prepare($sql);
        $statement->execute($parameters);
        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findByField(string $field, string $value): ?array
    {
        if (!in_array($field, ['username', 'email'], true)) {
            throw new \InvalidArgumentException('Unsupported user lookup field.');
        }

        $statement = $this->connection->pdo()->prepare(
            'SELECT id, username, email, activo, eliminado_en
             FROM usuarios
             WHERE ' . $field . ' = :value
             LIMIT 1'
        );
        $statement->execute(['value' => $value]);
        $user = $statement->fetch();

        return $user === false ? null : $user;
    }
}
