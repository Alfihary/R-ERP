<?php

declare(strict_types=1);

use App\Domain\Audit\AuditService;
use App\Domain\Credentials\CredentialService;
use App\Domain\Credentials\CredentialTokenService;
use App\Domain\Credentials\CredentialVerificationService;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\AuditRepository;
use App\Infrastructure\Repositories\CredentialTokenRepository;
use App\Infrastructure\Repositories\UserCredentialRepository;

return new class implements DatabaseTest {
    private const USERNAME = 'qa_auditoria_service_1';

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
                throw new RuntimeException('AUDITORIA-SERVICE-1 requires table: ' . $table);
            }
        }

        CredentialVerificationService::resetPublicRateLimitForTests();

        $before = $this->counts($pdo);
        $results = [];
        $pdo->beginTransaction();

        try {
            $connection = $GLOBALS['auditoria_service_connection'];
            $audit = new AuditService(new AuditRepository($connection));
            $userId = $this->insertUser($pdo);
            $credentialId = $this->insertCredential($pdo, $userId);
            $plainToken = str_repeat('a', 64);
            $this->insertToken($pdo, $credentialId, $plainToken);

            $audit->record('qa.audit.private', $userId, [
                'entidad' => 'credencial',
                'entidad_id' => (string) $credentialId,
                'resultado' => 'ok',
                'token' => $plainToken,
                'token_hash' => hash('sha256', $plainToken),
                'ruta_relativa' => 'uploads/usuarios/private/foto.png',
                'nested' => [
                    'password_hash' => str_repeat('x', 64),
                    'safe' => 'visible',
                ],
            ]);
            $audit->recordPublic('qa.audit.public', [
                'entidad' => 'credencial_publica',
                'resultado' => 'ok',
                'ip' => '198.51.100.55',
                'user_agent' => "QA\r\nAgent",
                'surface' => 'public_credential_verification',
            ]);

            $verification = new CredentialVerificationService($connection, $audit);
            $okVerification = $verification->verificarTokenPublico(
                $plainToken,
                '198.51.100.56',
                'QA audit service'
            );
            $failVerification = $verification->verificarTokenPublico(
                str_repeat('b', 64),
                '198.51.100.57',
                'QA audit service'
            );
            $verification->auditPublicRateLimited(
                '198.51.100.58',
                'QA audit service'
            );

            $tokenService = new CredentialTokenService(
                new CredentialService(new UserCredentialRepository($connection)),
                new CredentialTokenRepository($connection),
                $audit
            );
            $tokenService->generarToken($userId);
            $tokenService->revocarTokenActivo($userId);

            $audit->record('credencial.qr.ver', $userId, [
                'entidad' => 'credencial',
                'entidad_id' => (string) $credentialId,
                'resultado' => 'ok',
            ]);
            $audit->record('credencial.qr.descargar', $userId, [
                'entidad' => 'credencial',
                'entidad_id' => (string) $credentialId,
                'resultado' => 'ok',
            ]);
            $audit->record('credencial.foto.ver', $userId, [
                'entidad' => 'credencial',
                'entidad_id' => (string) $credentialId,
                'resultado' => 'ok',
                'ruta_relativa' => 'uploads/usuarios/private/foto.png',
            ]);

            $events = $this->auditEvents($pdo);
            $metadata = $this->metadataText($pdo);
            $during = $this->counts($pdo);

            $results['service'] = [
                'repository_exists' => class_exists(AuditRepository::class),
                'service_exists' => class_exists(AuditService::class),
                'writes_private_event' => ($events['qa.audit.private'] ?? 0) === 1,
                'writes_public_event' => ($events['qa.audit.public'] ?? 0) === 1,
                'failure_is_non_blocking' => $this->fileContains(
                    'app/Domain/Audit/AuditService.php',
                    'catch (Throwable)'
                ),
            ];

            $results['public_credential_audit'] = [
                'ok_result_returned' => is_array($okVerification),
                'fail_result_returned_null' => $failVerification === null,
                'ok_event' => ($events['credencial.verificacion.publica.ok'] ?? 0) === 1,
                'fail_event' => ($events['credencial.verificacion.publica.fail'] ?? 0) === 1,
                'rate_limited_event' =>
                    ($events['credencial.verificacion.publica.rate_limited'] ?? 0) === 1,
                'direct_sql_removed_from_verification_service' => !$this->fileContains(
                    'app/Domain/Credentials/CredentialVerificationService.php',
                    'INSERT INTO auditoria_eventos'
                ),
            ];

            $results['private_credential_audit'] = [
                'token_renew_event' => ($events['credencial.token.renovar'] ?? 0) === 1,
                'token_revoke_event' => ($events['credencial.token.revocar'] ?? 0) === 1,
                'qr_view_event' => ($events['credencial.qr.ver'] ?? 0) === 1,
                'qr_download_event' => ($events['credencial.qr.descargar'] ?? 0) === 1,
                'photo_view_event' => ($events['credencial.foto.ver'] ?? 0) === 1,
                'controller_qr_wired' => $this->fileContains(
                    'app/Http/Controllers/CredentialController.php',
                    "credencial.qr.ver"
                ),
                'controller_photo_wired' => $this->fileContains(
                    'app/Http/Controllers/CredentialController.php',
                    "credencial.foto.ver"
                ),
            ];

            $results['metadata_sanitization'] = [
                'token_redacted' => !str_contains($metadata, $plainToken),
                'token_hash_redacted' => !str_contains($metadata, hash('sha256', $plainToken)),
                'path_redacted' => !str_contains($metadata, 'uploads/usuarios/private/foto.png'),
                'password_hash_redacted' => !str_contains($metadata, str_repeat('x', 64)),
                'safe_value_preserved' => str_contains($metadata, 'visible'),
                'has_redacted_marker' => str_contains($metadata, '[REDACTED]'),
            ];

            $results['scope_guards'] = [
                'no_migrations_created' => !$this->hasUncommittedPath('database/migrations'),
                'no_unexpected_seeds_created' => $this->onlyExpectedUncommittedPaths(
                    'database/seeds',
                    ['database/seeds/permisos_auditoria_1_seed.php']
                ),
                'no_unexpected_views_modified' => $this->onlyExpectedUncommittedPaths(
                    'app/Views',
                    [
                        'app/Views/audit/index.php',
                        'app/Views/layouts/app.php',
                    ]
                ),
                'no_unexpected_routes_modified' => $this->onlyExpectedUncommittedPaths(
                    'routes',
                    ['routes/web.php']
                ),
            ];
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            CredentialVerificationService::resetPublicRateLimitForTests();
        }

        $after = $this->counts($pdo);
        $results['rollback'] = [
            'persistent_counts_unchanged' => $before === $after,
        ];

        if (!$this->allTrue($results)) {
            throw new RuntimeException(
                'AUDITORIA-SERVICE-1 assertions failed: '
                . json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        return [
            'database' => $expectedDatabase,
            'cases' => $results,
            'persistent_counts_before' => $before,
            'transient_counts_during' => $during,
            'persistent_counts_after' => $after,
            'events_checked' => [
                'credencial.verificacion.publica.ok',
                'credencial.verificacion.publica.fail',
                'credencial.verificacion.publica.rate_limited',
                'credencial.token.renovar',
                'credencial.token.revocar',
                'credencial.qr.ver',
                'credencial.qr.descargar',
                'credencial.foto.ver',
            ],
            'cleanup' => 'transaction_rolled_back',
        ];
    }

    private function insertUser(PDO $pdo): int
    {
        $statement = $pdo->prepare(
            <<<'SQL'
            INSERT INTO usuarios (username, email, password_hash, activo)
            VALUES (:username, :email, :password_hash, 1)
            SQL
        );
        $statement->execute([
            'username' => self::USERNAME,
            'email' => self::USERNAME . '@example.test',
            'password_hash' => '$2y$10$i2V1QJcC1dAguHOg1mdK1e0wMd6VGit2Z15ooGE7a5ZmzP02OS7Ue',
        ]);

        $userId = (int) $pdo->lastInsertId();

        $statement = $pdo->prepare(
            <<<'SQL'
            INSERT INTO perfiles_usuario (
                usuario_id,
                primer_nombre,
                apellido_paterno,
                puesto,
                ubicacion_publica
            ) VALUES (
                :usuario_id,
                'QA',
                'Auditoria',
                'Tester',
                'Laboratorio'
            )
            SQL
        );
        $statement->execute(['usuario_id' => $userId]);

        return $userId;
    }

    private function insertCredential(PDO $pdo, int $userId): int
    {
        $statement = $pdo->prepare(
            <<<'SQL'
            INSERT INTO credenciales_usuario (
                usuario_id,
                estatus,
                emitida_en
            ) VALUES (
                :usuario_id,
                'VIGENTE',
                CURRENT_TIMESTAMP
            )
            SQL
        );
        $statement->execute(['usuario_id' => $userId]);

        return (int) $pdo->lastInsertId();
    }

    private function insertToken(PDO $pdo, int $credentialId, string $plainToken): void
    {
        $tokenHash = hash('sha256', $plainToken);
        $statement = $pdo->prepare(
            <<<'SQL'
            INSERT INTO credencial_tokens (
                credencial_id,
                token_hash,
                token_prefix,
                activo
            ) VALUES (
                :credencial_id,
                :token_hash,
                :token_prefix,
                1
            )
            SQL
        );
        $statement->execute([
            'credencial_id' => $credentialId,
            'token_hash' => $tokenHash,
            'token_prefix' => strtoupper(substr($tokenHash, 0, 12)),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function auditEvents(PDO $pdo): array
    {
        $statement = $pdo->query(
            'SELECT accion, COUNT(*) AS total
             FROM auditoria_eventos
             GROUP BY accion'
        );
        $events = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $events[(string) $row['accion']] = (int) $row['total'];
        }

        return $events;
    }

    private function metadataText(PDO $pdo): string
    {
        $statement = $pdo->query(
            'SELECT COALESCE(metadata_json, JSON_OBJECT()) AS metadata_json
             FROM auditoria_eventos'
        );

        return implode(
            "\n",
            array_map(
                static fn (array $row): string => (string) $row['metadata_json'],
                $statement->fetchAll(PDO::FETCH_ASSOC)
            )
        );
    }

    /**
     * @return array<string, int>
     */
    private function counts(PDO $pdo): array
    {
        $counts = [];

        foreach ([
            'usuarios',
            'perfiles_usuario',
            'credenciales_usuario',
            'credencial_tokens',
            'auditoria_eventos',
        ] as $table) {
            $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
        }

        return $counts;
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

    private function fileContains(string $path, string $needle): bool
    {
        $contents = file_get_contents(BASE_PATH . '/' . $path);

        return is_string($contents) && str_contains($contents, $needle);
    }

    private function hasUncommittedPath(string $path): bool
    {
        exec('git status --short -- ' . escapeshellarg($path), $output, $exitCode);

        if ($exitCode !== 0) {
            throw new RuntimeException('Unable to inspect git status for ' . $path);
        }

        return $output !== [];
    }

    /**
     * @param list<string> $allowedPaths
     */
    private function onlyExpectedUncommittedPaths(string $path, array $allowedPaths): bool
    {
        exec('git status --short -- ' . escapeshellarg($path), $output, $exitCode);

        if ($exitCode !== 0) {
            throw new RuntimeException('Unable to inspect git status for ' . $path);
        }

        foreach ($output as $line) {
            $changedPath = trim(substr((string) $line, 3));

            if (
                !in_array($changedPath, $allowedPaths, true)
                && !str_starts_with($changedPath, 'app/Views/audit/')
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function allTrue(array $values): bool
    {
        foreach ($values as $value) {
            if (is_array($value)) {
                if (!$this->allTrue($value)) {
                    return false;
                }

                continue;
            }

            if ($value !== true) {
                return false;
            }
        }

        return true;
    }
};
