<?php

declare(strict_types=1);

use App\Core\Request;
use App\Core\Response;
use App\Domain\Credentials\CredentialService;
use App\Domain\Credentials\CredentialTokenService;
use App\Domain\Credentials\CredentialVerificationService;
use App\Http\Controllers\CredentialController;
use App\Http\Controllers\PublicCredentialController;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\CredentialTokenRepository;
use App\Infrastructure\Repositories\UserCredentialRepository;

return new class implements DatabaseTest {
    private const USERNAME = 'qa_credencial_hardening_1';
    private const INACTIVE_USERNAME = 'qa_credencial_hardening_inactivo';
    private const DELETED_USERNAME = 'qa_credencial_hardening_eliminado';

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
            'auditoria_eventos',
        ] as $table) {
            if (!$this->tableExists($pdo, $table)) {
                throw new RuntimeException('CREDENCIAL-HARDENING-1 requires table: ' . $table);
            }
        }

        CredentialVerificationService::resetPublicRateLimitForTests();

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
            $controller = $this->controller();
            $validToken = (string) $tokenService->generarToken($userId)['token'];
            $inactiveToken = (string) $tokenService->generarToken($inactiveUserId)['token'];
            $deletedToken = (string) $tokenService->generarToken($deletedUserId)['token'];
            $this->markUserInactive($pdo, $inactiveUserId);
            $this->markUserDeleted($pdo, $deletedUserId);

            $validResponse = $controller->verify(
                $this->request($validToken, '198.51.100.10'),
                ['token' => $validToken]
            );
            $validBody = $validResponse->body();
            $missingResponse = $controller->verify(
                $this->request(str_repeat('a', 64), '198.51.100.11'),
                ['token' => str_repeat('a', 64)]
            );

            $revokedToken = (string) $tokenService->generarToken($userId)['token'];
            $tokenService->revocarTokenActivo($userId);
            $revokedResponse = $controller->verify(
                $this->request($revokedToken, '198.51.100.12'),
                ['token' => $revokedToken]
            );

            $oldToken = (string) $tokenService->generarToken($userId)['token'];
            $renewedToken = (string) $tokenService->generarToken($userId)['token'];
            $oldAfterRenewResponse = $controller->verify(
                $this->request($oldToken, '198.51.100.13'),
                ['token' => $oldToken]
            );
            $renewedResponse = $controller->verify(
                $this->request($renewedToken, '198.51.100.14'),
                ['token' => $renewedToken]
            );

            $inactiveResponse = $controller->verify(
                $this->request($inactiveToken, '198.51.100.15'),
                ['token' => $inactiveToken]
            );
            $deletedResponse = $controller->verify(
                $this->request($deletedToken, '198.51.100.16'),
                ['token' => $deletedToken]
            );

            $inactiveCredentialToken = (string) $tokenService->generarToken($userId)['token'];
            $this->updateCredentialStatus($pdo, $userId, 'SUSPENDIDA');
            $inactiveCredentialResponse = $controller->verify(
                $this->request($inactiveCredentialToken, '198.51.100.17'),
                ['token' => $inactiveCredentialToken]
            );

            $invalidCases = [
                'slash' => str_repeat('a', 63) . '/',
                'backslash' => str_repeat('a', 63) . '\\',
                'dot' => str_repeat('a', 63) . '.',
                'percent' => str_repeat('a', 63) . '%',
                'question' => str_repeat('a', 63) . '?',
                'hash' => str_repeat('a', 63) . '#',
                'ampersand' => str_repeat('a', 63) . '&',
                'equals' => str_repeat('a', 63) . '=',
                'unicode' => str_repeat('a', 63) . 'á',
                'short' => 'abc',
                'long' => str_repeat('a', 65),
                'leading_space' => ' ' . str_repeat('a', 64),
                'trailing_space' => str_repeat('a', 64) . ' ',
            ];
            $invalidResponses = [];

            foreach ($invalidCases as $case => $token) {
                $invalidResponses[$case] = $controller->verify(
                    $this->request($token, '198.51.100.30'),
                    ['token' => $token]
                );
            }

            CredentialVerificationService::resetPublicRateLimitForTests();
            $rateResponses = [];

            for ($i = 1; $i <= 31; $i++) {
                $rateResponses[$i] = $controller->verify(
                    $this->request(str_repeat('b', 64), '203.0.113.77'),
                    ['token' => str_repeat('b', 64)]
                );
            }

            $invalidBodies = array_map(
                static fn (Response $response): string => $response->body(),
                [
                    $missingResponse,
                    $revokedResponse,
                    $oldAfterRenewResponse,
                    $inactiveResponse,
                    $deletedResponse,
                    $inactiveCredentialResponse,
                    ...array_values($invalidResponses),
                ]
            );
            $invalidBody = $missingResponse->body();
            $rateBody = $rateResponses[31]->body();
            $audits = $this->auditEvidence($pdo);
            $during = $this->counts($pdo);

            $results['public_route'] = [
                'declared' => $this->fileContains('routes/web.php', "'/credencial/' . 'verificar/{token}'"),
                'valid_200' => $validResponse->status() === 200,
                'renewed_200' => $renewedResponse->status() === 200,
            ];

            $results['anti_enumeration'] = [
                'missing_404' => $missingResponse->status() === 404,
                'revoked_404' => $revokedResponse->status() === 404,
                'old_after_renew_404' => $oldAfterRenewResponse->status() === 404,
                'inactive_user_404' => $inactiveResponse->status() === 404,
                'deleted_user_404' => $deletedResponse->status() === 404,
                'inactive_credential_404' => $inactiveCredentialResponse->status() === 404,
                'uniform_404_body' => count(array_unique($invalidBodies)) === 1,
                'body_does_not_reveal_reason' => $this->bodyIsSafe($invalidBody),
            ];

            $results['token_validation'] = [
                'all_invalid_formats_404' => !in_array(
                    false,
                    array_map(
                        static fn (Response $response): bool => $response->status() === 404,
                        $invalidResponses
                    ),
                    true
                ),
                'spaces_rejected' =>
                    $invalidResponses['leading_space']->status() === 404
                    && $invalidResponses['trailing_space']->status() === 404,
            ];

            $results['rate_limit'] = [
                'first_30_normal_404' => !in_array(
                    false,
                    array_map(
                        static fn (int $index): bool => $rateResponses[$index]->status() === 404,
                        range(1, 30)
                    ),
                    true
                ),
                'attempt_31_429' => $rateResponses[31]->status() === 429,
                'rate_body_safe' => $this->bodyIsSafe($rateBody),
            ];

            $results['headers'] = [
                'valid_200' => $this->headersAreSecure($validResponse),
                'invalid_404' => $this->headersAreSecure($missingResponse),
                'rate_limited_429' => $this->headersAreSecure($rateResponses[31]),
            ];

            $results['leakage'] = [
                'valid_body_safe' =>
                    !str_contains($validBody, $validToken)
                    && !str_contains($validBody, 'token_hash')
                    && !str_contains($validBody, 'credencial_tokens')
                    && !str_contains($validBody, 'password_hash')
                    && !str_contains($validBody, 'usuario_roles')
                    && !str_contains($validBody, 'rol_permisos')
                    && !str_contains($validBody, 'permisos')
                    && !str_contains($validBody, 'ruta_relativa')
                    && !str_contains($validBody, 'storage/uploads')
                    && !str_contains($validBody, '@example.test')
                    && !str_contains($validBody, 'telefono')
                    && !str_contains($validBody, '<img')
                    && !str_contains($validBody, 'producto')
                    && !str_contains($validBody, 'precio')
                    && !str_contains($validBody, 'stock')
                    && !str_contains($validBody, 'existencia')
                    && !str_contains($validBody, 'costo'),
                'audit_has_events' => $audits['total'] > 0,
                'audit_has_ok_fail_and_rate_limited' =>
                    $audits['ok'] > 0 && $audits['fail'] > 0 && $audits['rate_limited'] > 0,
                'audit_no_token_or_hash' => $audits['token_leaks'] === 0,
            ];

            $results['private_compatibility'] = [
                'credential_visual_route_declared' => $this->fileContains('routes/web.php', "'/perfil/credencial'"),
                'renew_route_declared' => $this->fileContains('routes/web.php', "'/perfil/credencial/token/renovar'"),
                'revoke_route_declared' => $this->fileContains('routes/web.php', "'/perfil/credencial/token/revocar'"),
                'qr_route_declared' => $this->fileContains('routes/web.php', "'/perfil/credencial/' . 'qr'"),
                'qr_download_route_declared' =>
                    $this->fileContains('routes/web.php', "'/perfil/credencial/' . 'qr/descargar'"),
                'vcard_public_still_declared' => $this->fileContains('routes/web.php', "'/v/{slug}'"),
                'vcard_qr_still_declared' => $this->fileContains('routes/web.php', "'/v/{slug}/' . 'qr'"),
                'vcard_vcf_still_declared' => $this->fileContains('routes/web.php', "'/v/{slug}/' . 'vcf'"),
                'vcard_products_service_exists' =>
                    file_exists(BASE_PATH . '/app/Domain/Vcards/VcardProductService.php'),
            ];
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            CredentialVerificationService::resetPublicRateLimitForTests();
        }

        $after = $this->counts($pdo);

        if (!$this->allTrue($results) || $before !== $after) {
            throw new RuntimeException(
                'CREDENCIAL-HARDENING-1 assertions failed: '
                . json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        return [
            'database' => $expectedDatabase,
            'cases' => $results,
            'rate_limit' => 'in_memory_30_attempts_per_ip_per_5_minutes',
            'audit' => 'auditoria_eventos_public_events_without_token_or_hash',
            'persistent_counts_before' => $before,
            'transient_counts_during' => $during,
            'persistent_counts_after' => $after,
            'cleanup' => 'transaction_rolled_back',
        ];
    }

    private function controller(): PublicCredentialController
    {
        return new PublicCredentialController(
            $GLOBALS['credencial_hardening_config'],
            new CredentialVerificationService(
                $GLOBALS['credencial_hardening_connection']
            )
        );
    }

    private function tokenService(): CredentialTokenService
    {
        return new CredentialTokenService(
            new CredentialService(
                new UserCredentialRepository($GLOBALS['credencial_hardening_connection'])
            ),
            new CredentialTokenRepository($GLOBALS['credencial_hardening_connection'])
        );
    }

    private function request(string $token, string $ip): Request
    {
        return new Request(
            'GET',
            '/credencial/verificar/' . $token,
            [],
            [],
            [
                'x-forwarded-for' => $ip,
                'user-agent' => 'R-ERP QA hardening',
            ]
        );
    }

    private function bodyIsSafe(string $body): bool
    {
        $forbidden = [
            'revocado',
            'expirado',
            'inactivo',
            'eliminado',
            'inexistente',
            'token inválido',
            'token invalido',
            'credencial encontrada',
            'token_hash',
            'credencial_tokens',
        ];
        $body = strtolower($body);

        foreach ($forbidden as $needle) {
            if (str_contains($body, $needle)) {
                return false;
            }
        }

        return true;
    }

    private function headersAreSecure(Response $response): bool
    {
        $headers = $this->headers($response);

        return ($headers['Cache-Control'] ?? '') === 'no-store'
            && ($headers['X-Content-Type-Options'] ?? '') === 'nosniff'
            && ($headers['Referrer-Policy'] ?? '') === 'strict-origin-when-cross-origin'
            && ($headers['X-Frame-Options'] ?? '') === 'DENY';
    }

    /**
     * @return array<string, int>
     */
    private function auditEvidence(PDO $pdo): array
    {
        $total = (int) $pdo->query(
            "SELECT COUNT(*)
             FROM auditoria_eventos
             WHERE accion LIKE 'credencial.verificacion.publica.%'"
        )->fetchColumn();
        $ok = (int) $pdo->query(
            "SELECT COUNT(*) FROM auditoria_eventos WHERE accion = 'credencial.verificacion.publica.ok'"
        )->fetchColumn();
        $fail = (int) $pdo->query(
            "SELECT COUNT(*) FROM auditoria_eventos WHERE accion = 'credencial.verificacion.publica.fail'"
        )->fetchColumn();
        $rateLimited = (int) $pdo->query(
            "SELECT COUNT(*) FROM auditoria_eventos WHERE accion = 'credencial.verificacion.publica.rate_limited'"
        )->fetchColumn();
        $leaks = (int) $pdo->query(
            "SELECT COUNT(*)
             FROM auditoria_eventos
             WHERE accion LIKE 'credencial.verificacion.publica.%'
               AND (
                   metadata_json LIKE '%token%'
                   OR metadata_json LIKE '%hash%'
                   OR entidad_id LIKE '%credencial/verificar%'
                   OR user_agent LIKE '%credencial/verificar%'
               )"
        )->fetchColumn();

        return [
            'total' => $total,
            'ok' => $ok,
            'fail' => $fail,
            'rate_limited' => $rateLimited,
            'token_leaks' => $leaks,
        ];
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
            'usuarios_qa' => $this->countWhere($pdo, 'usuarios', "username LIKE 'qa_credencial_hardening%'"),
            'perfiles_qa' => $this->countWhere(
                $pdo,
                'perfiles_usuario p INNER JOIN usuarios u ON u.id = p.usuario_id',
                "u.username LIKE 'qa_credencial_hardening%'"
            ),
            'credenciales_qa' => $this->countWhere(
                $pdo,
                'credenciales_usuario c INNER JOIN usuarios u ON u.id = c.usuario_id',
                "u.username LIKE 'qa_credencial_hardening%'"
            ),
            'tokens_qa' => $this->countWhere(
                $pdo,
                'credencial_tokens ct INNER JOIN credenciales_usuario c ON c.id = ct.credencial_id INNER JOIN usuarios u ON u.id = c.usuario_id',
                "u.username LIKE 'qa_credencial_hardening%'"
            ),
            'audit_public_events' => $this->countWhere(
                $pdo,
                'auditoria_eventos',
                "accion LIKE 'credencial.verificacion.publica.%'"
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
            'password_hash' => password_hash('CredencialHardeningQA123!', PASSWORD_DEFAULT),
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
                \'Hardening\',
                \'Operación interna\',
                \'Monterrey público\'
             )'
        );
        $statement->execute(['usuario_id' => $userId]);
    }

    private function updateCredentialStatus(PDO $pdo, int $userId, string $status): void
    {
        $statement = $pdo->prepare(
            'UPDATE credenciales_usuario SET estatus = :estatus WHERE usuario_id = :usuario_id'
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
