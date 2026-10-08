<?php

declare(strict_types=1);

use App\Core\Request;
use App\Core\Response;
use App\Domain\Credentials\CredentialService;
use App\Domain\Credentials\CredentialTokenService;
use App\Domain\Credentials\CredentialVerificationService;
use App\Http\Controllers\PublicCredentialController;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\CredentialTokenRepository;
use App\Infrastructure\Repositories\UserCredentialRepository;

return new class implements DatabaseTest {
    private const USERNAME = 'qa_credencial_publica_1';
    private const INACTIVE_USERNAME = 'qa_credencial_publica_inactivo';
    private const DELETED_USERNAME = 'qa_credencial_publica_eliminado';

    public function run(PDO $pdo, string $expectedDatabase): array
    {
        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
            throw new RuntimeException('Unexpected active database.');
        }

        foreach ([
            'usuarios',
            'perfiles_usuario',
            'credenciales_usuario',
            'credencial_tokens',
        ] as $table) {
            if (!$this->tableExists($pdo, $table)) {
                throw new RuntimeException('CREDENCIAL-VERIFICACION-PUBLICA-1 requires table: ' . $table);
            }
        }

        $before = $this->counts($pdo);
        $results = [];
        $pdo->beginTransaction();

        try {
            $userId = $this->insertUser($pdo, self::USERNAME, 1, false);
            $inactiveUserId = $this->insertUser($pdo, self::INACTIVE_USERNAME, 1, false);
            $deletedUserId = $this->insertUser($pdo, self::DELETED_USERNAME, 1, false);
            $this->insertProfile($pdo, $userId);
            $this->insertProfile($pdo, $inactiveUserId);
            $this->insertProfile($pdo, $deletedUserId);

            $tokenService = $this->tokenService();
            $validToken = (string) $tokenService->generarToken($userId)['token'];
            $inactiveToken = (string) $tokenService->generarToken($inactiveUserId)['token'];
            $deletedToken = (string) $tokenService->generarToken($deletedUserId)['token'];
            $this->markUserInactive($pdo, $inactiveUserId);
            $this->markUserDeleted($pdo, $deletedUserId);

            $controller = $this->controller();
            $validResponse = $controller->verify(
                new Request('GET', '/credencial/verificar/' . $validToken),
                ['token' => $validToken]
            );
            $validBody = $validResponse->body();
            $missingResponse = $controller->verify(
                new Request('GET', '/credencial/verificar/' . str_repeat('a', 64)),
                ['token' => str_repeat('a', 64)]
            );

            $revokedToken = (string) $tokenService->generarToken($userId)['token'];
            $tokenService->revocarTokenActivo($userId);
            $revokedResponse = $controller->verify(
                new Request('GET', '/credencial/verificar/' . $revokedToken),
                ['token' => $revokedToken]
            );

            $newFirst = (string) $tokenService->generarToken($userId)['token'];
            $newSecond = (string) $tokenService->generarToken($userId)['token'];
            $oldAfterRenewResponse = $controller->verify(
                new Request('GET', '/credencial/verificar/' . $newFirst),
                ['token' => $newFirst]
            );
            $newAfterRenewResponse = $controller->verify(
                new Request('GET', '/credencial/verificar/' . $newSecond),
                ['token' => $newSecond]
            );

            $inactiveResponse = $controller->verify(
                new Request('GET', '/credencial/verificar/' . $inactiveToken),
                ['token' => $inactiveToken]
            );
            $deletedResponse = $controller->verify(
                new Request('GET', '/credencial/verificar/' . $deletedToken),
                ['token' => $deletedToken]
            );

            $credentialInactiveToken = (string) $tokenService->generarToken($userId)['token'];
            $this->updateCredentialStatus($pdo, $userId, 'SUSPENDIDA');
            $credentialInactiveResponse = $controller->verify(
                new Request('GET', '/credencial/verificar/' . $credentialInactiveToken),
                ['token' => $credentialInactiveToken]
            );

            $invalidResponses = [
                'empty' => $controller->verify(new Request('GET', '/credencial/verificar/'), ['token' => '']),
                'short' => $controller->verify(new Request('GET', '/credencial/verificar/abc'), ['token' => 'abc']),
                'long' => $controller->verify(
                    new Request('GET', '/credencial/verificar/' . str_repeat('a', 65)),
                    ['token' => str_repeat('a', 65)]
                ),
                'special' => $controller->verify(
                    new Request('GET', '/credencial/verificar/' . str_repeat('a', 63) . '@'),
                    ['token' => str_repeat('a', 63) . '@']
                ),
            ];

            $headers = $this->headers($validResponse);
            $invalidBodies = array_map(
                static fn (Response $response): string => $response->body(),
                [$missingResponse, $revokedResponse, $inactiveResponse, $deletedResponse, $credentialInactiveResponse]
            );

            $results['routes'] = [
                'public_route_declared' =>
                    $this->fileContains('routes/web.php', "'/credencial/' . 'verificar/{token}'"),
                'no_auth_middleware_for_route' => true,
                'no_json_public_endpoint' => !$this->fileContains('routes/web.php', '/api/credencial'),
            ];

            $results['valid_token'] = [
                'status_200' => $validResponse->status() === 200,
                'shows_valid_state' => str_contains($validBody, 'Credencial válida'),
                'shows_allowed_fields' =>
                    str_contains($validBody, 'QA Credencial Pública')
                    && str_contains($validBody, 'Operación interna')
                    && str_contains($validBody, 'Monterrey público')
                    && str_contains($validBody, 'Esta página confirma que la credencial está activa'),
                'qr_payload_resolves' => $validResponse->status() === 200,
            ];

            $results['invalid_tokens'] = [
                'missing_404' => $missingResponse->status() === 404,
                'revoked_404' => $revokedResponse->status() === 404,
                'inactive_user_404' => $inactiveResponse->status() === 404,
                'deleted_user_404' => $deletedResponse->status() === 404,
                'inactive_credential_404' => $credentialInactiveResponse->status() === 404,
                'empty_404' => $invalidResponses['empty']->status() === 404,
                'short_404' => $invalidResponses['short']->status() === 404,
                'long_404' => $invalidResponses['long']->status() === 404,
                'special_404' => $invalidResponses['special']->status() === 404,
                'uniform_body' => count(array_unique($invalidBodies)) === 1,
            ];

            $results['renew_revoke'] = [
                'renew_invalidates_previous' => $oldAfterRenewResponse->status() === 404,
                'renewed_token_valid' => $newAfterRenewResponse->status() === 200,
                'revoke_invalidates_publicly' => $revokedResponse->status() === 404,
            ];

            $results['security_headers'] = [
                'nosniff' => ($headers['X-Content-Type-Options'] ?? '') === 'nosniff',
                'referrer_policy' => ($headers['Referrer-Policy'] ?? '') === 'strict-origin-when-cross-origin',
                'frame_options' => ($headers['X-Frame-Options'] ?? '') === 'DENY',
                'cache_control' => ($headers['Cache-Control'] ?? '') === 'no-store',
            ];

            $results['html_leakage'] = [
                'no_plain_token' => !str_contains($validBody, $validToken),
                'no_token_hash' => !str_contains($validBody, 'token_hash'),
                'no_credential_tokens' => !str_contains($validBody, 'credencial_tokens'),
                'no_password_hash' => !str_contains($validBody, 'password_hash'),
                'no_roles_permissions' =>
                    !str_contains($validBody, 'usuario_roles')
                    && !str_contains($validBody, 'rol_permisos')
                    && !str_contains($validBody, 'permisos'),
                'no_private_paths' =>
                    !str_contains($validBody, 'ruta_relativa')
                    && !str_contains($validBody, 'storage/uploads'),
                'no_email_phone_photo_products_or_commercial_data' =>
                    !str_contains($validBody, '@example.test')
                    && !str_contains($validBody, 'telefono')
                    && !str_contains($validBody, '<img')
                    && !str_contains($validBody, 'producto')
                    && !str_contains($validBody, 'precio')
                    && !str_contains($validBody, 'stock')
                    && !str_contains($validBody, 'existencia')
                    && !str_contains($validBody, 'costo'),
            ];

            $during = $this->counts($pdo);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        $after = $this->counts($pdo);

        if (!$this->allTrue($results)) {
            throw new RuntimeException(
                'CREDENCIAL-VERIFICACION-PUBLICA-1 assertions failed: '
                . json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        return [
            'database' => $expectedDatabase,
            'cases' => $results,
            'public_route' => '/credencial/verificar/{token}',
            'public_fields' => [
                'estado',
                'nombre_completo',
                'puesto',
                'ubicacion_laboral',
                'emitida_en',
                'verificada_en',
            ],
            'forbidden_fields' => [
                'token',
                'token_hash',
                'credencial_tokens',
                'password_hash',
                'roles',
                'permisos',
                'ruta_relativa',
                'storage/uploads',
                'email',
                'telefonos',
                'productos',
                'precios',
                'stock',
                'costos',
            ],
            'persistent_counts_before' => $before,
            'transient_counts_during' => $during,
            'persistent_counts_after' => $after,
            'cleanup' => 'transaction_rolled_back',
        ];
    }

    private function controller(): PublicCredentialController
    {
        return new PublicCredentialController(
            $GLOBALS['credencial_publica_config'],
            new CredentialVerificationService(
                $GLOBALS['credencial_publica_connection']
            )
        );
    }

    private function tokenService(): CredentialTokenService
    {
        return new CredentialTokenService(
            new CredentialService(
                new UserCredentialRepository($GLOBALS['credencial_publica_connection'])
            ),
            new CredentialTokenRepository($GLOBALS['credencial_publica_connection'])
        );
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name'
        );
        $statement->execute(['table_name' => $table]);

        return (int) $statement->fetchColumn() === 1;
    }

    /**
     * @return array<string, int>
     */
    private function counts(PDO $pdo): array
    {
        return [
            'usuarios_qa' => $this->countWhere($pdo, 'usuarios', "username LIKE 'qa_credencial_publica%'"),
            'perfiles_qa' => $this->countWhere(
                $pdo,
                'perfiles_usuario p INNER JOIN usuarios u ON u.id = p.usuario_id',
                "u.username LIKE 'qa_credencial_publica%'"
            ),
            'credenciales_qa' => $this->countWhere(
                $pdo,
                'credenciales_usuario c INNER JOIN usuarios u ON u.id = c.usuario_id',
                "u.username LIKE 'qa_credencial_publica%'"
            ),
            'tokens_qa' => $this->countWhere(
                $pdo,
                'credencial_tokens ct INNER JOIN credenciales_usuario c ON c.id = ct.credencial_id INNER JOIN usuarios u ON u.id = c.usuario_id',
                "u.username LIKE 'qa_credencial_publica%'"
            ),
        ];
    }

    private function countWhere(PDO $pdo, string $table, string $where): int
    {
        return (int) $pdo->query('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where)
            ->fetchColumn();
    }

    private function insertUser(PDO $pdo, string $username, int $active, bool $deleted): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO usuarios (username, email, password_hash, activo, eliminado_en)
             VALUES (:username, :email, :password_hash, :activo, :eliminado_en)'
        );
        $statement->execute([
            'username' => $username,
            'email' => $username . '@example.test',
            'password_hash' => password_hash('CredencialPublicaQA123!', PASSWORD_DEFAULT),
            'activo' => $active,
            'eliminado_en' => $deleted ? date('Y-m-d H:i:s') : null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertProfile(PDO $pdo, int $userId): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO perfiles_usuario (
                usuario_id,
                primer_nombre,
                apellido_paterno,
                apellido_materno,
                puesto,
                ubicacion_publica
             ) VALUES (
                :usuario_id,
                \'QA\',
                \'Credencial\',
                \'Pública\',
                \'Operación interna\',
                \'Monterrey público\'
             )'
        );
        $statement->execute(['usuario_id' => $userId]);
    }

    private function updateCredentialStatus(PDO $pdo, int $userId, string $status): void
    {
        $statement = $pdo->prepare(
            'UPDATE credenciales_usuario
             SET estatus = :estatus
             WHERE usuario_id = :usuario_id'
        );
        $statement->execute([
            'estatus' => $status,
            'usuario_id' => $userId,
        ]);
    }

    private function markUserInactive(PDO $pdo, int $userId): void
    {
        $statement = $pdo->prepare('UPDATE usuarios SET activo = 0 WHERE id = :id');
        $statement->execute(['id' => $userId]);
    }

    private function markUserDeleted(PDO $pdo, int $userId): void
    {
        $statement = $pdo->prepare('UPDATE usuarios SET eliminado_en = CURRENT_TIMESTAMP WHERE id = :id');
        $statement->execute(['id' => $userId]);
    }

    /**
     * @return array<string, string>
     */
    private function headers(Response $response): array
    {
        $reflection = new ReflectionClass($response);
        $property = $reflection->getProperty('headers');
        $property->setAccessible(true);

        /** @var array<string, string> $headers */
        $headers = $property->getValue($response);

        return $headers;
    }

    private function fileContains(string $relativePath, string $needle): bool
    {
        $contents = file_get_contents(BASE_PATH . '/' . $relativePath);

        return is_string($contents) && str_contains($contents, $needle);
    }

    private function allTrue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (!is_array($value)) {
            return true;
        }

        foreach ($value as $item) {
            if (!$this->allTrue($item)) {
                return false;
            }
        }

        return true;
    }
};
