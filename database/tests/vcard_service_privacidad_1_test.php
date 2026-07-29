<?php

declare(strict_types=1);

use App\Domain\Vcards\VcardPrivacyService;
use App\Domain\Vcards\VcardService;
use App\Domain\Vcards\VcardValidationException;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\UserVcardRepository;
use App\Infrastructure\Repositories\VcardPrivacyRepository;

return new class implements DatabaseTest {
    private const USERNAME = 'qa_vcard_service_1';
    private const DUPLICATE_USERNAME = 'qa_vcard_service_dup';
    private const INACTIVE_USERNAME = 'qa_vcard_service_inactive';

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
            'vcards_usuario',
            'vcard_privacidad',
            'vcard_productos',
            'credenciales_usuario',
            'credencial_tokens',
        ] as $table) {
            if (!$this->tableExists($pdo, $table)) {
                throw new RuntimeException(
                    'VCARD-SERVICE-PRIVACIDAD-1 requires table: ' . $table
                );
            }
        }

        $before = $this->counts($pdo);
        $service = $this->service();
        $privacy = $this->privacy();
        $results = [];

        $pdo->beginTransaction();

        try {
            $userId = $this->insertUser($pdo, self::USERNAME, 1);
            $duplicateUserId = $this->insertUser($pdo, self::DUPLICATE_USERNAME, 1);
            $inactiveUserId = $this->insertUser($pdo, self::INACTIVE_USERNAME, 1);
            $this->insertProfile($pdo, $userId);
            $this->insertProfile($pdo, $inactiveUserId);
            $this->insertActivePhoto($pdo, $userId);
            $this->assignFirstScope($pdo, $userId);

            $base = $service->asegurarVcard($userId);
            $baseAgain = $service->asegurarVcard($userId);

            $results['asegurar_vcard'] = [
                'created' => (int) $base['usuario_id'] === $userId,
                'idempotent' => (int) $base['id'] === (int) $baseAgain['id'],
                'default_privacy_safe' =>
                    !in_array(true, $base['privacidad'], true)
                    && count($base['privacidad']) === count($privacy->camposPermitidos()),
            ];

            $updated = $service->actualizarConfiguracion($userId, [
                'slug' => ' QA Comercial ',
                'titulo_publico' => 'Contacto comercial QA',
                'descripcion_publica' => 'Representación pública controlada.',
                'canal_contacto_preferido' => 'whatsapp',
                'sitio_web' => 'https://example.test',
            ]);

            $results['configuracion'] = [
                'slug_normalized' => $updated['slug'] === 'qa-comercial',
                'title_updated' =>
                    $updated['titulo_publico'] === 'Contacto comercial QA',
                'description_updated' =>
                    $updated['descripcion_publica'] === 'Representación pública controlada.',
                'channel_updated' =>
                    $updated['canal_contacto_preferido'] === 'whatsapp',
            ];

            $duplicate = $service->asegurarVcard($duplicateUserId);
            $service->actualizarConfiguracion($duplicateUserId, [
                'slug' => 'qa-duplicado',
                'canal_contacto_preferido' => 'ninguno',
            ]);

            $results['slug_validations'] = [
                'duplicate_rejected' => $this->fails(
                    fn () => $service->actualizarConfiguracion($userId, [
                        'slug' => 'qa-duplicado',
                    ])
                ),
                'reserved_rejected' => $this->fails(
                    fn () => $service->actualizarConfiguracion($userId, [
                        'slug' => 'admin',
                    ])
                ),
                'invalid_rejected' => $this->fails(
                    fn () => $service->actualizarConfiguracion($userId, [
                        'slug' => '12',
                    ])
                ),
                'numeric_rejected' => $this->fails(
                    fn () => $service->actualizarConfiguracion($userId, [
                        'slug' => '123456',
                    ])
                ),
            ];

            $results['url_validation'] = [
                'invalid_url_rejected' => $this->fails(
                    fn () => $service->actualizarConfiguracion($userId, [
                        'slug' => 'qa-comercial',
                        'sitio_web' => 'ftp://example.test',
                    ])
                ),
            ];

            $results['privacidad'] = [
                'unknown_field_rejected' => $this->fails(
                    fn () => $privacy->actualizarPrivacidad(
                        (int) $base['id'],
                        ['precio' => true]
                    )
                ),
            ];
            $visibility = $privacy->actualizarPrivacidad((int) $base['id'], [
                'foto' => true,
                'correo' => false,
                'telefono_fijo' => false,
                'telefono_movil' => true,
                'puesto' => true,
                'empresa' => true,
                'almacen' => true,
                'ubicacion' => true,
                'sitio_web' => true,
                'linkedin' => false,
                'facebook' => false,
                'instagram' => false,
                'whatsapp' => true,
                'google_maps' => true,
                'productos' => true,
            ]);
            $results['privacidad']['granular_updated'] =
                $visibility['foto'] === true
                && $visibility['correo'] === false
                && $visibility['whatsapp'] === true
                && $visibility['productos'] === true;

            $published = $service->publicar($userId);
            $public = $service->resolverPublicaPorSlug(' QA Comercial ');
            $unpublished = $service->despublicar($userId);
            $publicAfterUnpublish = $service->resolverPublicaPorSlug('qa-comercial');
            $service->actualizarConfiguracion($userId, [
                'slug' => 'qa-comercial',
                'canal_contacto_preferido' => 'telefono_fijo',
            ]);
            $service->publicar($userId);
            $fallbackPublic = $service->resolverPublicaPorSlug('qa-comercial');
            $privacy->actualizarPrivacidad((int) $base['id'], [
                'foto' => false,
                'correo' => false,
                'telefono_fijo' => false,
                'telefono_movil' => false,
                'puesto' => false,
                'empresa' => false,
                'almacen' => false,
                'ubicacion' => false,
                'sitio_web' => false,
                'linkedin' => false,
                'facebook' => false,
                'instagram' => false,
                'whatsapp' => false,
                'google_maps' => false,
                'productos' => false,
            ]);
            $noContactPublic = $service->resolverPublicaPorSlug('qa-comercial');

            $inactiveVcard = $service->asegurarVcard($inactiveUserId);
            $this->deactivateUser($pdo, $inactiveUserId);
            $this->forcePublish($pdo, (int) $inactiveVcard['id'], 'qa-inactivo');

            $results['publicacion'] = [
                'published_marks_state' =>
                    $published['publicada'] === true
                    && $published['publicado_en'] !== null,
                'unpublished_marks_state' =>
                    $unpublished['publicada'] === false
                    && $unpublished['despublicado_en'] !== null,
                'missing_slug_returns_null' =>
                    $service->resolverPublicaPorSlug('no-existe-qa') === null,
                'unpublished_returns_null' => $publicAfterUnpublish === null,
                'inactive_user_returns_null' =>
                    $service->resolverPublicaPorSlug('qa-inactivo') === null,
            ];

            $results['representacion_publica'] = [
                'published_resolves' => is_array($public),
                'visible_fields_present' =>
                    is_array($public)
                    && ($public['foto_publica_disponible'] ?? null) === true
                    && ($public['telefono_movil'] ?? null) === '5555551111'
                    && ($public['whatsapp'] ?? null) === '5555552222'
                    && ($public['puesto'] ?? null) === 'Ventas QA'
                    && ($public['productos_habilitados'] ?? null) === false,
                'private_fields_omitted' =>
                    is_array($public)
                    && !array_key_exists('correo', $public)
                    && !array_key_exists('linkedin', $public)
                    && !array_key_exists('facebook', $public)
                    && !array_key_exists('instagram', $public),
                'no_internal_ids_or_sensitive_fields' =>
                    is_array($public)
                    && !array_key_exists('usuario_id', $public)
                    && !array_key_exists('vcard_id', $public)
                    && !array_key_exists('id', $public)
                    && !array_key_exists('password_hash', $public)
                    && !array_key_exists('roles', $public)
                    && !array_key_exists('permisos', $public)
                    && !array_key_exists('ruta_relativa', $public)
                    && !array_key_exists('token', $public),
                'no_price_stock_cost' =>
                    is_array($public)
                    && !array_key_exists('precio', $public)
                    && !array_key_exists('stock', $public)
                    && !array_key_exists('costo', $public),
            ];

            $results['contacto_publico'] = [
                'preferred_visible_used' =>
                    is_array($public)
                    && ($public['canal_contacto']['tipo'] ?? null) === 'whatsapp',
                'fallback_uses_visible_field_only' =>
                    is_array($fallbackPublic)
                    && ($fallbackPublic['canal_contacto']['tipo'] ?? null) === 'whatsapp',
                'null_without_authorized_channel' =>
                    is_array($noContactPublic)
                    && !array_key_exists('canal_contacto', $noContactPublic),
            ];

            $results['guardrails'] = [
                'public_vcard_routes_allowed_after_security_phase' =>
                    $this->fileContains('routes/web.php', '/v/{slug}')
                    && $this->fileContains('routes/web.php', '/v/{slug}/foto')
                    && !$this->fileContains('routes/web.php', '/vcf')
                    && !$this->fileContains('routes/web.php', '/qr')
                    && !$this->fileContains('routes/web.php', '/credencial/verificar'),
                'public_vcard_controller_allowed_after_security_phase' =>
                    file_exists(BASE_PATH . '/app/Http/Controllers/PublicVcardController.php')
                    && !file_exists(BASE_PATH . '/app/Http/Controllers/VcardController.php')
                    && !file_exists(BASE_PATH . '/app/Http/Controllers/CredentialController.php'),
                'public_vcard_views_allowed_after_security_phase' =>
                    is_dir(BASE_PATH . '/app/Views/vcards')
                    && !is_dir(BASE_PATH . '/app/Views/vcard')
                    && !is_dir(BASE_PATH . '/app/Views/credentials'),
                'public_vcard_asset_allowed_after_security_phase' =>
                    file_exists(BASE_PATH . '/public/css/modules/vcard-public.css')
                    && !file_exists(BASE_PATH . '/public/css/modules/vcard.css')
                    && !file_exists(BASE_PATH . '/public/js/vcard.js')
                    && !file_exists(BASE_PATH . '/public/js/modules/vcard.js'),
                'no_qr_vcf_credential_classes' =>
                    !file_exists(BASE_PATH . '/app/Domain/Vcards/QrService.php')
                    && !file_exists(BASE_PATH . '/app/Domain/Vcards/VcfService.php')
                    && !is_dir(BASE_PATH . '/app/Domain/Credentials'),
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
                'VCARD-SERVICE-PRIVACIDAD-1 assertions failed: '
                . json_encode(
                    $results,
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                )
            );
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

    private function service(): VcardService
    {
        $privacy = $this->privacy();

        return new VcardService(
            new UserVcardRepository($GLOBALS['vcard_service_connection']),
            new VcardPrivacyRepository($GLOBALS['vcard_service_connection']),
            $privacy
        );
    }

    private function privacy(): VcardPrivacyService
    {
        return new VcardPrivacyService(
            new VcardPrivacyRepository($GLOBALS['vcard_service_connection'])
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
            'usuarios_qa' => $this->countWhere(
                $pdo,
                'usuarios',
                "username LIKE 'qa_vcard_service%'"
            ),
            'perfiles_qa' => $this->countWhere(
                $pdo,
                'perfiles_usuario p INNER JOIN usuarios u ON u.id = p.usuario_id',
                "u.username LIKE 'qa_vcard_service%'"
            ),
            'fotos_qa' => $this->countWhere(
                $pdo,
                'usuarios_fotos f INNER JOIN usuarios u ON u.id = f.usuario_id',
                "u.username LIKE 'qa_vcard_service%'"
            ),
            'vcards_qa' => $this->countWhere(
                $pdo,
                'vcards_usuario v INNER JOIN usuarios u ON u.id = v.usuario_id',
                "u.username LIKE 'qa_vcard_service%'"
            ),
            'privacidad_qa' => $this->countWhere(
                $pdo,
                'vcard_privacidad vp
                 INNER JOIN vcards_usuario v ON v.id = vp.vcard_id
                 INNER JOIN usuarios u ON u.id = v.usuario_id',
                "u.username LIKE 'qa_vcard_service%'"
            ),
            'usuario_empresas_qa' => $this->countWhere(
                $pdo,
                'usuario_empresas ue INNER JOIN usuarios u ON u.id = ue.usuario_id',
                "u.username LIKE 'qa_vcard_service%'"
            ),
            'usuario_almacenes_qa' => $this->countWhere(
                $pdo,
                'usuario_almacenes ua INNER JOIN usuarios u ON u.id = ua.usuario_id',
                "u.username LIKE 'qa_vcard_service%'"
            ),
        ];
    }

    private function countWhere(PDO $pdo, string $table, string $where): int
    {
        return (int) $pdo->query(
            'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where
        )->fetchColumn();
    }

    private function insertUser(PDO $pdo, string $username, int $active): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO usuarios (
                username,
                email,
                password_hash,
                activo
             ) VALUES (
                :username,
                :email,
                :password_hash,
                :activo
             )'
        );
        $statement->execute([
            'username' => $username,
            'email' => $username . '@example.test',
            'password_hash' => password_hash('VcardQa123!', PASSWORD_DEFAULT),
            'activo' => $active,
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
                puesto,
                telefono_fijo,
                telefono_movil,
                sitio_web,
                linkedin_url,
                facebook_url,
                instagram_url,
                whatsapp,
                google_maps_url,
                ubicacion_publica
             ) VALUES (
                :usuario_id,
                \'QA\',
                \'Vcard\',
                \'Ventas QA\',
                \'5555550000\',
                \'5555551111\',
                \'https://example.test\',
                \'https://linkedin.example.test/qa\',
                \'https://facebook.example.test/qa\',
                \'https://instagram.example.test/qa\',
                \'5555552222\',
                \'https://maps.example.test/qa\',
                \'Monterrey QA\'
             )'
        );
        $statement->execute(['usuario_id' => $userId]);
    }

    private function insertActivePhoto(PDO $pdo, int $userId): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO usuarios_fotos (
                usuario_id,
                ruta_relativa,
                nombre_original,
                nombre_archivo,
                mime,
                extension,
                tamano_bytes,
                sha256,
                ancho,
                alto,
                creado_por
             ) VALUES (
                :usuario_id,
                :ruta_relativa,
                \'qa-vcard.webp\',
                \'qa-vcard.webp\',
                \'image/webp\',
                \'webp\',
                1024,
                :sha256,
                640,
                640,
                :creado_por
             )'
        );
        $statement->execute([
            'usuario_id' => $userId,
            'ruta_relativa' => 'profile/users/' . $userId . '/qa-vcard.webp',
            'sha256' => hash('sha256', 'vcard-service-photo-' . $userId),
            'creado_por' => $userId,
        ]);
    }

    private function assignFirstScope(PDO $pdo, int $userId): void
    {
        $scope = $pdo->query(
            'SELECT e.id AS empresa_id, a.id AS almacen_id
             FROM empresas e
             INNER JOIN almacenes a
                ON a.empresa_id = e.id
               AND a.activo = 1
               AND a.eliminado_en IS NULL
             WHERE e.activo = 1
               AND e.eliminado_en IS NULL
             ORDER BY e.id ASC, a.id ASC
             LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);

        if (!is_array($scope)) {
            return;
        }

        $empresa = (int) $scope['empresa_id'];
        $almacen = (int) $scope['almacen_id'];
        $company = $pdo->prepare(
            'INSERT INTO usuario_empresas (usuario_id, empresa_id, activo)
             VALUES (:usuario_id, :empresa_id, 1)'
        );
        $warehouse = $pdo->prepare(
            'INSERT INTO usuario_almacenes (usuario_id, empresa_id, almacen_id, activo)
             VALUES (:usuario_id, :empresa_id, :almacen_id, 1)'
        );
        $company->execute([
            'usuario_id' => $userId,
            'empresa_id' => $empresa,
        ]);
        $warehouse->execute([
            'usuario_id' => $userId,
            'empresa_id' => $empresa,
            'almacen_id' => $almacen,
        ]);
    }

    private function forcePublish(PDO $pdo, int $vcardId, string $slug): void
    {
        $statement = $pdo->prepare(
            'UPDATE vcards_usuario
             SET slug = :slug,
                 publicada = 1,
                 publicado_en = CURRENT_TIMESTAMP,
                 despublicado_en = NULL
             WHERE id = :id'
        );
        $statement->execute([
            'id' => $vcardId,
            'slug' => $slug,
        ]);
    }

    private function deactivateUser(PDO $pdo, int $userId): void
    {
        $statement = $pdo->prepare(
            'UPDATE usuarios SET activo = 0 WHERE id = :id'
        );
        $statement->execute(['id' => $userId]);
    }

    private function fails(callable $operation): bool
    {
        try {
            $operation();
        } catch (VcardValidationException) {
            return true;
        }

        return false;
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
