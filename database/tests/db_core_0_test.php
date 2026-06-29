<?php

declare(strict_types=1);

use App\Infrastructure\Database\DatabaseTest;

return new class implements DatabaseTest {
    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $currentDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

        if ($currentDatabase !== $expectedDatabase) {
            throw new RuntimeException('The active database does not match the confirmed test database.');
        }

        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $expectedTables = [
            'schema_migrations',
            'usuarios',
            'roles',
            'permisos',
            'usuario_roles',
            'rol_permisos',
            'auditoria_eventos',
        ];

        $tables = $this->tableMetadata($pdo, $expectedDatabase, $expectedTables);

        if (count($tables) !== count($expectedTables)) {
            throw new RuntimeException('DB-CORE-0 does not contain every expected table.');
        }

        foreach ($tables as $table) {
            if (strtoupper((string) $table['engine']) !== 'INNODB') {
                throw new RuntimeException('Every DB-CORE-0 table must use InnoDB.');
            }

            if (strtolower((string) $table['table_collation']) !== 'utf8mb4_unicode_ci') {
                throw new RuntimeException('Every DB-CORE-0 table must use utf8mb4_unicode_ci.');
            }
        }

        $requiredIndexes = [
            'PRIMARY',
            'uq_usuarios_email',
            'uq_usuarios_username',
            'uq_roles_codigo',
            'uq_permisos_codigo',
            'idx_auditoria_actor_fecha',
            'idx_auditoria_accion_fecha',
            'idx_auditoria_entidad_recurso',
        ];
        $indexes = $this->indexNames($pdo, $expectedDatabase);

        foreach ($requiredIndexes as $requiredIndex) {
            if (!in_array($requiredIndex, $indexes, true)) {
                throw new RuntimeException('Required index is missing: ' . $requiredIndex);
            }
        }

        if (!$this->hasUniqueIndex(
            $pdo,
            $expectedDatabase,
            'usuarios',
            'uq_usuarios_username'
        )) {
            throw new RuntimeException('usuarios.username must have an independent unique index.');
        }

        $requiredForeignKeys = [
            'fk_usuario_roles_usuario',
            'fk_usuario_roles_rol',
            'fk_rol_permisos_rol',
            'fk_rol_permisos_permiso',
            'fk_auditoria_actor',
        ];
        $foreignKeys = $this->foreignKeyNames($pdo, $expectedDatabase);

        foreach ($requiredForeignKeys as $requiredForeignKey) {
            if (!in_array($requiredForeignKey, $foreignKeys, true)) {
                throw new RuntimeException('Required foreign key is missing: ' . $requiredForeignKey);
            }
        }

        $adminRole = $this->fetchAdminRole($pdo);

        if ($adminRole === false
            || (int) $adminRole['es_sistema'] !== 1
            || (int) $adminRole['activo'] !== 1
        ) {
            throw new RuntimeException('The structural ADMIN role seed is missing or invalid.');
        }

        if ((int) $pdo->query('SELECT COUNT(*) FROM permisos')->fetchColumn() !== 0) {
            throw new RuntimeException('DB-CORE-0 must not seed functional permissions.');
        }

        if ((int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() !== 0) {
            throw new RuntimeException('DB-CORE-0 must not seed an administrator user.');
        }

        $expectedFailures = [];
        $pdo->beginTransaction();

        try {
            $userId = $this->insertUser($pdo);
            $roleId = $this->insertTestRole($pdo);
            $permissionId = $this->insertTestPermission($pdo);

            $this->expectConstraintFailure(
                fn () => $this->insertUser(
                    $pdo,
                    'db-core-user@example.invalid',
                    'qa.user002'
                ),
                'duplicate_user_email',
                $expectedFailures
            );
            $this->expectConstraintFailure(
                fn () => $this->insertUser(
                    $pdo,
                    'db-core-username@example.invalid',
                    'qa.user001'
                ),
                'duplicate_username',
                $expectedFailures
            );
            $this->expectConstraintFailure(
                fn () => $this->insertUserWithUsername(
                    $pdo,
                    'db-core-null-username@example.invalid',
                    null
                ),
                'null_username',
                $expectedFailures
            );
            $this->expectConstraintFailure(
                fn () => $this->insertUserWithUsername(
                    $pdo,
                    'db-core-space@example.invalid',
                    'qa user'
                ),
                'username_with_spaces',
                $expectedFailures
            );
            $this->expectConstraintFailure(
                fn () => $this->insertUserWithUsername(
                    $pdo,
                    'db-core-accent@example.invalid',
                    'jésús.g'
                ),
                'username_with_accents',
                $expectedFailures
            );
            $this->expectConstraintFailure(
                fn () => $this->insertUserWithUsername(
                    $pdo,
                    'db-core-special@example.invalid',
                    'qa/user'
                ),
                'username_with_disallowed_character',
                $expectedFailures
            );
            $this->expectConstraintFailure(
                fn () => $this->insertUserWithUsername(
                    $pdo,
                    'db-core-uppercase@example.invalid',
                    'Qa.user'
                ),
                'username_not_normalized',
                $expectedFailures
            );
            $this->expectConstraintFailure(
                fn () => $this->insertUserWithUsername(
                    $pdo,
                    'db-core-short@example.invalid',
                    'ab'
                ),
                'username_too_short',
                $expectedFailures
            );
            $this->expectConstraintFailure(
                fn () => $this->insertUserWithUsername(
                    $pdo,
                    'db-core-long@example.invalid',
                    str_repeat('a', 51)
                ),
                'username_too_long',
                $expectedFailures
            );
            $this->expectConstraintFailure(
                fn () => $this->insertNullPasswordUser($pdo),
                'null_password_hash',
                $expectedFailures
            );
            $this->expectConstraintFailure(
                fn () => $this->insertInvalidActiveUser($pdo),
                'invalid_user_active_state',
                $expectedFailures
            );
            $this->expectConstraintFailure(
                fn () => $this->insertTestRole($pdo, 'ADMIN'),
                'duplicate_role_code',
                $expectedFailures
            );
            $this->expectConstraintFailure(
                fn () => $this->insertTestPermission($pdo),
                'duplicate_permission_code',
                $expectedFailures
            );

            $assignRole = $pdo->prepare(
                'INSERT INTO usuario_roles (usuario_id, rol_id, creado_por)
                 VALUES (:usuario_id, :rol_id, :creado_por)'
            );
            $assignRole->execute([
                'usuario_id' => $userId,
                'rol_id' => $roleId,
                'creado_por' => $userId,
            ]);

            $this->expectConstraintFailure(
                fn () => $assignRole->execute([
                    'usuario_id' => $userId,
                    'rol_id' => $roleId,
                    'creado_por' => $userId,
                ]),
                'duplicate_user_role',
                $expectedFailures
            );

            $invalidUserRole = $pdo->prepare(
                'INSERT INTO usuario_roles (usuario_id, rol_id)
                 VALUES (:usuario_id, :rol_id)'
            );
            $this->expectConstraintFailure(
                fn () => $invalidUserRole->execute([
                    'usuario_id' => 999999999,
                    'rol_id' => $roleId,
                ]),
                'invalid_user_role_foreign_key',
                $expectedFailures
            );

            $assignPermission = $pdo->prepare(
                'INSERT INTO rol_permisos (rol_id, permiso_id, creado_por)
                 VALUES (:rol_id, :permiso_id, :creado_por)'
            );
            $assignPermission->execute([
                'rol_id' => $roleId,
                'permiso_id' => $permissionId,
                'creado_por' => $userId,
            ]);

            $this->expectConstraintFailure(
                fn () => $assignPermission->execute([
                    'rol_id' => $roleId,
                    'permiso_id' => $permissionId,
                    'creado_por' => $userId,
                ]),
                'duplicate_role_permission',
                $expectedFailures
            );

            $invalidRolePermission = $pdo->prepare(
                'INSERT INTO rol_permisos (rol_id, permiso_id)
                 VALUES (:rol_id, :permiso_id)'
            );
            $this->expectConstraintFailure(
                fn () => $invalidRolePermission->execute([
                    'rol_id' => $roleId,
                    'permiso_id' => 999999999,
                ]),
                'invalid_role_permission_foreign_key',
                $expectedFailures
            );

            $audit = $pdo->prepare(
                <<<'SQL'
                INSERT INTO auditoria_eventos (
                    actor_usuario_id,
                    accion,
                    entidad,
                    entidad_id,
                    resultado,
                    ip,
                    user_agent,
                    metadata_json
                ) VALUES (
                    :actor_usuario_id,
                    :accion,
                    :entidad,
                    :entidad_id,
                    :resultado,
                    :ip,
                    :user_agent,
                    :metadata_json
                )
                SQL
            );
            $metadata = json_encode(
                ['test' => 'DB-TEST-CORE', 'contains_secret' => false],
                JSON_THROW_ON_ERROR
            );
            $audit->execute([
                'actor_usuario_id' => $userId,
                'accion' => 'db_core.test',
                'entidad' => 'usuarios',
                'entidad_id' => (string) $userId,
                'resultado' => 'exito',
                'ip' => '127.0.0.1',
                'user_agent' => 'DB-TEST-CORE',
                'metadata_json' => $metadata,
            ]);

            $auditCheck = $pdo->prepare(
                'SELECT accion, entidad, entidad_id, resultado, metadata_json
                 FROM auditoria_eventos
                 WHERE actor_usuario_id = :actor_usuario_id'
            );
            $auditCheck->execute(['actor_usuario_id' => $userId]);
            $auditEvidence = $auditCheck->fetch();

            if ($auditEvidence === false
                || json_decode((string) $auditEvidence['metadata_json'], true) === null
            ) {
                throw new RuntimeException('The audit event structure did not preserve valid metadata.');
            }

            $relationshipCounts = [
                'usuario_roles' => (int) $pdo->query(
                    'SELECT COUNT(*) FROM usuario_roles'
                )->fetchColumn(),
                'rol_permisos' => (int) $pdo->query(
                    'SELECT COUNT(*) FROM rol_permisos'
                )->fetchColumn(),
                'auditoria_eventos' => (int) $pdo->query(
                    'SELECT COUNT(*) FROM auditoria_eventos'
                )->fetchColumn(),
            ];

            $showCreate = $this->showCreateTables($pdo, $expectedTables);

            return [
                'database' => $currentDatabase,
                'server_version' => $version,
                'tables' => $tables,
                'indexes_verified' => $requiredIndexes,
                'username_unique_index' => [
                    'table' => 'usuarios',
                    'index' => 'uq_usuarios_username',
                    'unique' => true,
                ],
                'foreign_keys_verified' => $requiredForeignKeys,
                'seed' => [
                    'admin_role' => [
                        'codigo' => $adminRole['codigo'],
                        'es_sistema' => (int) $adminRole['es_sistema'],
                        'activo' => (int) $adminRole['activo'],
                    ],
                    'users' => 0,
                    'permissions' => 0,
                ],
                'valid_insert' => [
                    'usuario_id' => $userId,
                    'username' => 'qa.user001',
                    'rol_id' => $roleId,
                    'permiso_id' => $permissionId,
                ],
                'expected_failures' => $expectedFailures,
                'relationship_counts_before_cleanup' => $relationshipCounts,
                'audit_evidence' => $auditEvidence,
                'show_create_table' => $showCreate,
                'cleanup' => 'transaction_rolled_back',
            ];
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    /**
     * @param list<string> $tables
     * @return list<array{table_name: string, engine: string, table_collation: string}>
     */
    private function tableMetadata(PDO $pdo, string $database, array $tables): array
    {
        $placeholders = [];
        $parameters = ['database' => $database];

        foreach ($tables as $index => $table) {
            $key = 'table_' . $index;
            $placeholders[] = ':' . $key;
            $parameters[$key] = $table;
        }

        $statement = $pdo->prepare(
            'SELECT
                 table_name AS table_name,
                 engine AS engine,
                 table_collation AS table_collation
             FROM information_schema.tables
             WHERE table_schema = :database
               AND table_name IN (' . implode(', ', $placeholders) . ')
             ORDER BY table_name'
        );
        $statement->execute($parameters);

        return $statement->fetchAll();
    }

    /**
     * @return list<string>
     */
    private function indexNames(PDO $pdo, string $database): array
    {
        $statement = $pdo->prepare(
            'SELECT DISTINCT index_name AS index_name
             FROM information_schema.statistics
             WHERE table_schema = :database
             ORDER BY index_name'
        );
        $statement->execute(['database' => $database]);

        return array_map(
            static fn (array $row): string => (string) $row['index_name'],
            $statement->fetchAll()
        );
    }

    /**
     * @return list<string>
     */
    private function foreignKeyNames(PDO $pdo, string $database): array
    {
        $statement = $pdo->prepare(
            'SELECT DISTINCT constraint_name AS constraint_name
             FROM information_schema.referential_constraints
             WHERE constraint_schema = :database
             ORDER BY constraint_name'
        );
        $statement->execute(['database' => $database]);

        return array_map(
            static fn (array $row): string => (string) $row['constraint_name'],
            $statement->fetchAll()
        );
    }

    private function hasUniqueIndex(
        PDO $pdo,
        string $database,
        string $table,
        string $index
    ): bool {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.statistics
             WHERE table_schema = :database
               AND table_name = :table
               AND index_name = :index
               AND non_unique = 0'
        );
        $statement->execute([
            'database' => $database,
            'table' => $table,
            'index' => $index,
        ]);

        return (int) $statement->fetchColumn() >= 1;
    }

    /**
     * @return array<string, mixed>|false
     */
    private function fetchAdminRole(PDO $pdo): array|false
    {
        $statement = $pdo->prepare(
            'SELECT codigo, es_sistema, activo FROM roles WHERE codigo = :codigo'
        );
        $statement->execute(['codigo' => 'ADMIN']);

        return $statement->fetch();
    }

    private function insertUser(
        PDO $pdo,
        string $email = 'db-core-user@example.invalid',
        string $username = 'qa.user001'
    ): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO usuarios (username, email, password_hash, activo)
             VALUES (:username, :email, :password_hash, 1)'
        );
        $statement->execute([
            'username' => $username,
            'email' => $email,
            'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertTestRole(PDO $pdo, string $code = 'DB_TEST_ROLE'): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO roles (codigo, nombre, descripcion, es_sistema, activo)
             VALUES (:codigo, :nombre, :descripcion, 0, 1)'
        );
        $statement->execute([
            'codigo' => $code,
            'nombre' => 'DB Test Role',
            'descripcion' => 'Transient DB-TEST-CORE role.',
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertNullPasswordUser(PDO $pdo): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO usuarios (username, email, password_hash, activo)
             VALUES (:username, :email, :password_hash, 1)'
        );
        $statement->execute([
            'username' => 'qa.nullpassword',
            'email' => 'db-core-null@example.invalid',
            'password_hash' => null,
        ]);
    }

    private function insertInvalidActiveUser(PDO $pdo): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO usuarios (username, email, password_hash, activo)
             VALUES (:username, :email, :password_hash, :activo)'
        );
        $statement->execute([
            'username' => 'qa.invalidactive',
            'email' => 'db-core-active@example.invalid',
            'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
            'activo' => 2,
        ]);
    }

    private function insertUserWithUsername(
        PDO $pdo,
        string $email,
        ?string $username
    ): void {
        $statement = $pdo->prepare(
            'INSERT INTO usuarios (username, email, password_hash, activo)
             VALUES (:username, :email, :password_hash, 1)'
        );
        $statement->execute([
            'username' => $username,
            'email' => $email,
            'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
        ]);
    }

    private function insertTestPermission(PDO $pdo): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO permisos (codigo, modulo, nombre, descripcion, es_sistema, activo)
             VALUES (:codigo, :modulo, :nombre, :descripcion, 1, 1)'
        );
        $statement->execute([
            'codigo' => 'db_core.test',
            'modulo' => 'db_core',
            'nombre' => 'DB Test Permission',
            'descripcion' => 'Transient DB-TEST-CORE permission.',
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param list<string> $failures
     */
    private function expectConstraintFailure(
        callable $operation,
        string $label,
        array &$failures
    ): void {
        try {
            $operation();
        } catch (PDOException $exception) {
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);

            if (!in_array(
                $driverCode,
                [1048, 1062, 1366, 1406, 1452, 3819, 3988, 4025],
                true
            )) {
                throw new RuntimeException(
                    'Unexpected database error while testing: ' . $label,
                    0,
                    $exception
                );
            }

            $failures[] = $label;
            return;
        }

        throw new RuntimeException('Expected constraint failure was accepted: ' . $label);
    }

    /**
     * @param list<string> $tables
     * @return array<string, string>
     */
    private function showCreateTables(PDO $pdo, array $tables): array
    {
        $evidence = [];

        foreach ($tables as $table) {
            if (preg_match('/^[a-z0-9_]+$/', $table) !== 1) {
                throw new RuntimeException('Unsafe table name in DB-TEST-CORE.');
            }

            $row = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch();

            if ($row === false) {
                throw new RuntimeException('Unable to inspect table: ' . $table);
            }

            $values = array_values($row);
            $evidence[$table] = (string) ($values[1] ?? '');
        }

        return $evidence;
    }
};
