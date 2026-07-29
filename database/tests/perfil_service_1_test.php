<?php

declare(strict_types=1);

use App\Domain\Profile\ProfileService;
use App\Domain\Profile\ProfileValidationException;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\ProfileRepository;
use App\Infrastructure\Repositories\UserPhotoRepository;

return new class implements DatabaseTest {
    private const ACTIVE_USER = 'qa_profile_service_1';
    private const INACTIVE_USER = 'qa_profile_service_inactive';
    private const OLD_PASSWORD = 'PerfilQA123!';
    private const NEW_PASSWORD = 'PerfilQA456!';

    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $activeDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

        if ($activeDatabase !== $expectedDatabase) {
            throw new RuntimeException('Unexpected active database.');
        }

        foreach ([
            'usuarios',
            'perfiles_usuario',
            'usuarios_fotos',
        ] as $table) {
            if (!$this->tableExists($pdo, $table)) {
                throw new RuntimeException(
                    'PERFIL-SERVICE-1 requires table: ' . $table
                );
            }
        }

        $before = $this->counts($pdo);
        $service = new ProfileService(
            new ProfileRepository($GLOBALS['perfil_service_connection']),
            new UserPhotoRepository($GLOBALS['perfil_service_connection'])
        );
        $results = [];

        $pdo->beginTransaction();

        try {
            $activeUserId = $this->insertUser(
                $pdo,
                self::ACTIVE_USER,
                'qa.profile.service.1@example.test',
                self::OLD_PASSWORD,
                1
            );
            $inactiveUserId = $this->insertUser(
                $pdo,
                self::INACTIVE_USER,
                'qa.profile.service.inactive@example.test',
                self::OLD_PASSWORD,
                0
            );

            $base = $service->asegurarPerfil($activeUserId);
            $baseAgain = $service->asegurarPerfil($activeUserId);
            $existing = $service->obtenerPerfil($activeUserId);

            $results['perfil_base'] = [
                'created' => (int) $base['usuario_id'] === $activeUserId,
                'idempotent' =>
                    (int) $base['id'] === (int) $baseAgain['id'],
                'obtener_existente' =>
                    (int) $existing['id'] === (int) $base['id'],
            ];

            $updated = $service->actualizarPerfil($activeUserId, [
                'primer_nombre' => '  Jesús QA  ',
                'segundo_nombre' => '',
                'apellido_paterno' => 'Perfil',
                'apellido_materno' => '',
                'puesto' => 'Operación QA',
                'telefono_fijo' => '',
                'telefono_movil' => '5555555555',
                'whatsapp' => '',
                'sitio_web' => 'https://example.test/perfil',
                'linkedin_url' => '',
                'facebook_url' => '',
                'instagram_url' => '',
                'google_maps_url' => '',
                'ubicacion_publica' => 'Base QA',
            ]);

            $results['actualizar_perfil'] = [
                'trim_applied' => $updated['primer_nombre'] === 'Jesús QA',
                'empty_to_null' =>
                    $updated['segundo_nombre'] === null
                    && $updated['telefono_fijo'] === null,
                'allowed_fields_updated' =>
                    $updated['puesto'] === 'Operación QA'
                    && $updated['sitio_web'] === 'https://example.test/perfil',
                'no_password_hash_returned' =>
                    !array_key_exists('password_hash', $updated)
                    && !array_key_exists('password', $updated),
            ];

            $results['validaciones_perfil'] = [
                'invalid_url_rejected' => $this->fails(
                    fn () => $service->actualizarPerfil(
                        $activeUserId,
                        ['sitio_web' => 'ftp://example.test']
                    )
                ),
                'max_length_rejected' => $this->fails(
                    fn () => $service->actualizarPerfil(
                        $activeUserId,
                        ['primer_nombre' => str_repeat('A', 81)]
                    )
                ),
                'forbidden_roles_rejected' => $this->fails(
                    fn () => $service->actualizarPerfil(
                        $activeUserId,
                        ['roles' => ['ADMIN']]
                    )
                ),
                'forbidden_security_fields_rejected' => $this->fails(
                    fn () => $service->actualizarPerfil(
                        $activeUserId,
                        [
                            'activo' => 0,
                            'password_hash' => 'x',
                            'username' => 'otro',
                            'email' => 'otro@example.test',
                        ]
                    )
                ),
                'unknown_user_rejected' =>
                    $this->fails(fn () => $service->asegurarPerfil(999999999)),
                'inactive_user_rejected' =>
                    $this->fails(fn () => $service->asegurarPerfil($inactiveUserId)),
            ];

            $passwordWrong = $this->fails(
                fn () => $service->cambiarPassword($activeUserId, [
                    'password_actual' => 'incorrecta',
                    'password_nueva' => self::NEW_PASSWORD,
                    'password_confirmacion' => self::NEW_PASSWORD,
                ])
            );
            $passwordConfirmation = $this->fails(
                fn () => $service->cambiarPassword($activeUserId, [
                    'password_actual' => self::OLD_PASSWORD,
                    'password_nueva' => self::NEW_PASSWORD,
                    'password_confirmacion' => 'OtraQA456!',
                ])
            );
            $passwordShort = $this->fails(
                fn () => $service->cambiarPassword($activeUserId, [
                    'password_actual' => self::OLD_PASSWORD,
                    'password_nueva' => '1234567',
                    'password_confirmacion' => '1234567',
                ])
            );
            $passwordSame = $this->fails(
                fn () => $service->cambiarPassword($activeUserId, [
                    'password_actual' => self::OLD_PASSWORD,
                    'password_nueva' => self::OLD_PASSWORD,
                    'password_confirmacion' => self::OLD_PASSWORD,
                ])
            );
            $passwordResult = $service->cambiarPassword($activeUserId, [
                'password_actual' => self::OLD_PASSWORD,
                'password_nueva' => self::NEW_PASSWORD,
                'password_confirmacion' => self::NEW_PASSWORD,
            ]);
            $newHash = $this->passwordHash($pdo, $activeUserId);

            $results['password'] = [
                'wrong_current_rejected' => $passwordWrong,
                'confirmation_mismatch_rejected' => $passwordConfirmation,
                'short_rejected' => $passwordShort,
                'same_rejected' => $passwordSame,
                'hash_updated' =>
                    password_verify(self::NEW_PASSWORD, $newHash)
                    && !password_verify(self::OLD_PASSWORD, $newHash),
                'no_hash_or_password_returned' => $passwordResult === null,
            ];

            $photo1 = $service->registrarFoto(
                $activeUserId,
                $this->photoMetadata('foto-perfil-1.webp', str_repeat('a', 64)),
                $activeUserId
            );
            $activeAfterFirst = $service->obtenerFotoActiva($activeUserId);
            $photo2 = $service->registrarFoto(
                $activeUserId,
                $this->photoMetadata('foto-perfil-2.png', str_repeat('b', 64), 'image/png', 'png'),
                $activeUserId
            );
            $activeAfterSecond = $service->obtenerFotoActiva($activeUserId);

            $results['foto'] = [
                'creates_active' =>
                    $activeAfterFirst !== null
                    && (int) $activeAfterFirst['id'] === (int) $photo1['id'],
                'previous_deactivated' =>
                    $this->photoActiveValue($pdo, (int) $photo1['id']) === 0,
                'second_active' =>
                    $activeAfterSecond !== null
                    && (int) $activeAfterSecond['id'] === (int) $photo2['id'],
                'safe_return' =>
                    !str_starts_with(
                        (string) $activeAfterSecond['ruta_relativa'],
                        '/'
                    ),
            ];

            $results['validaciones_foto'] = [
                'invalid_mime_rejected' => $this->fails(
                    fn () => $service->registrarFoto(
                        $activeUserId,
                        $this->photoMetadata('bad.gif', str_repeat('c', 64), 'image/gif', 'gif'),
                        $activeUserId
                    )
                ),
                'invalid_extension_rejected' => $this->fails(
                    fn () => $service->registrarFoto(
                        $activeUserId,
                        $this->photoMetadata('bad.exe', str_repeat('d', 64), 'image/png', 'exe'),
                        $activeUserId
                    )
                ),
                'path_traversal_rejected' => $this->fails(
                    fn () => $service->registrarFoto(
                        $activeUserId,
                        [
                            ...$this->photoMetadata('bad.png', str_repeat('e', 64), 'image/png', 'png'),
                            'ruta_relativa' => '../secret/bad.png',
                        ],
                        $activeUserId
                    )
                ),
                'invalid_hash_rejected' => $this->fails(
                    fn () => $service->registrarFoto(
                        $activeUserId,
                        $this->photoMetadata('bad.png', 'not-a-hash', 'image/png', 'png'),
                        $activeUserId
                    )
                ),
                'dangerous_double_extension_rejected' => $this->fails(
                    fn () => $service->registrarFoto(
                        $activeUserId,
                        $this->photoMetadata('avatar.php.jpg', str_repeat('f', 64), 'image/jpeg', 'jpg'),
                        $activeUserId
                    )
                ),
            ];

            $service->eliminarFoto($activeUserId, $activeUserId);
            $service->eliminarFoto($activeUserId, $activeUserId);

            $results['eliminar_foto'] = [
                'active_missing_after_delete' =>
                    $service->obtenerFotoActiva($activeUserId) === null,
                'deleted_photo_inactive' =>
                    $this->photoActiveValue($pdo, (int) $photo2['id']) === 0,
                'idempotent_without_active_photo' => true,
            ];

            $results['guardrails'] = [
                'no_public_routes_file_created' =>
                    !file_exists(BASE_PATH . '/routes/profile.php'),
                'public_vcard_controller_allowed_after_security_phase' =>
                    file_exists(BASE_PATH . '/app/Http/Controllers/PublicVcardController.php')
                    && !file_exists(BASE_PATH . '/app/Http/Controllers/VCardController.php')
                    && !file_exists(BASE_PATH . '/app/Http/Controllers/CredentialController.php'),
                'public_vcard_views_allowed_after_security_phase' =>
                    !is_dir(BASE_PATH . '/app/Views/vcard')
                    && is_dir(BASE_PATH . '/app/Views/vcards')
                    && !is_dir(BASE_PATH . '/app/Views/credential')
                    && !is_dir(BASE_PATH . '/app/Views/credentials'),
                'public_vcard_asset_allowed_after_security_phase' =>
                    file_exists(BASE_PATH . '/public/css/modules/vcard-public.css')
                    && !file_exists(BASE_PATH . '/public/css/modules/vcard.css')
                    && !file_exists(BASE_PATH . '/public/css/modules/credential.css')
                    && !file_exists(BASE_PATH . '/public/js/modules/vcard.js')
                    && !file_exists(BASE_PATH . '/public/js/modules/credential.js'),
                'no_legacy_public_vcard_service_implementation' =>
                    !file_exists(BASE_PATH . '/app/Domain/Profile/VCardService.php')
                    && !file_exists(BASE_PATH . '/app/Domain/Profile/VcardPublicService.php')
                    && !file_exists(BASE_PATH . '/app/Domain/Vcards/VcardPublicService.php'),
            ];

            $during = $this->counts($pdo);
        } finally {
            $pdo->rollBack();
        }

        $after = $this->counts($pdo);

        if (!$this->allTrue($results)) {
            throw new RuntimeException('PERFIL-SERVICE-1 assertions failed.');
        }

        return [
            'database' => $expectedDatabase,
            'cases' => $results,
            'persistent_counts_before' => $before,
            'transient_counts_during' => $during,
            'persistent_counts_after' => $after,
            'cleanup' => 'transaction_rolled_back',
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
            'usuarios_qa' => $this->countWhere(
                $pdo,
                'usuarios',
                "username LIKE 'qa_profile_service%'"
            ),
            'perfiles_qa' => $this->countWhere(
                $pdo,
                'perfiles_usuario p INNER JOIN usuarios u ON u.id = p.usuario_id',
                "u.username LIKE 'qa_profile_service%'"
            ),
            'fotos_qa' => $this->countWhere(
                $pdo,
                'usuarios_fotos f INNER JOIN usuarios u ON u.id = f.usuario_id',
                "u.username LIKE 'qa_profile_service%'"
            ),
        ];
    }

    private function countWhere(PDO $pdo, string $table, string $where): int
    {
        return (int) $pdo->query(
            'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where
        )->fetchColumn();
    }

    private function insertUser(
        PDO $pdo,
        string $username,
        string $email,
        string $password,
        int $active
    ): int {
        $statement = $pdo->prepare(
            <<<'SQL'
            INSERT INTO usuarios (
                username,
                email,
                password_hash,
                activo
            )
            VALUES (
                :username,
                :email,
                :password_hash,
                :activo
            )
            SQL
        );
        $statement->execute([
            'username' => $username,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'activo' => $active,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function passwordHash(PDO $pdo, int $userId): string
    {
        $statement = $pdo->prepare(
            'SELECT password_hash FROM usuarios WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $userId]);

        return (string) $statement->fetchColumn();
    }

    private function photoActiveValue(PDO $pdo, int $photoId): int
    {
        $statement = $pdo->prepare(
            'SELECT activa FROM usuarios_fotos WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $photoId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return array<string, mixed>
     */
    private function photoMetadata(
        string $filename,
        string $sha256,
        string $mime = 'image/webp',
        string $extension = 'webp'
    ): array {
        return [
            'ruta_relativa' => 'profile/users/' . $filename,
            'nombre_archivo' => $filename,
            'nombre_original' => $filename,
            'mime' => $mime,
            'extension' => $extension,
            'tamano_bytes' => 12345,
            'sha256' => $sha256,
            'ancho' => 640,
            'alto' => 640,
        ];
    }

    private function fails(callable $operation): bool
    {
        try {
            $operation();
        } catch (ProfileValidationException) {
            return true;
        }

        return false;
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
