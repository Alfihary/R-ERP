<?php

declare(strict_types=1);

use App\Core\Request;
use App\Domain\Vcards\VcardPrivacyService;
use App\Domain\Vcards\VcardService;
use App\Http\Controllers\PublicVcardController;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\UserVcardRepository;
use App\Infrastructure\Repositories\VcardPrivacyRepository;

return new class implements DatabaseTest {
    private const USERNAME = 'qa_vcard_publico_1';
    private const NO_PHOTO_USERNAME = 'qa_vcard_publico_no_photo';
    private const INACTIVE_USERNAME = 'qa_vcard_publico_inactive';
    private const PASSWORD = 'VcardPublicQa123!';
    private const SLUG = 'qa-publico-seguro';
    private const NO_PHOTO_SLUG = 'qa-publico-sin-foto';
    private const INACTIVE_SLUG = 'qa-publico-inactivo';

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
        ] as $table) {
            if (!$this->tableExists($pdo, $table)) {
                throw new RuntimeException(
                    'VCARD-PUBLICO-SEGURIDAD-1 requires table: ' . $table
                );
            }
        }

        $before = $this->counts($pdo);
        $service = $this->service();
        $privacy = $this->privacy();
        $controller = $this->controller();
        $results = [];
        $pdo->beginTransaction();

        try {
            $userId = $this->insertUser($pdo, self::USERNAME, 1);
            $noPhotoUserId = $this->insertUser($pdo, self::NO_PHOTO_USERNAME, 1);
            $inactiveUserId = $this->insertUser($pdo, self::INACTIVE_USERNAME, 1);
            $this->insertProfile($pdo, $userId);
            $this->insertProfile($pdo, $noPhotoUserId);
            $this->insertProfile($pdo, $inactiveUserId);
            $this->insertActivePhoto($pdo, $userId);

            $vcard = $this->publishVcard($service, $privacy, $userId, self::SLUG, true);
            $this->publishVcard($service, $privacy, $noPhotoUserId, self::NO_PHOTO_SLUG, true);
            $inactiveVcard = $this->publishVcard($service, $privacy, $inactiveUserId, self::INACTIVE_SLUG, true);
            $this->deactivateUser($pdo, $inactiveUserId);
            $this->forcePublish($pdo, (int) $inactiveVcard['id'], self::INACTIVE_SLUG);

            $published = $controller->show(
                new Request('GET', '/v/' . self::SLUG),
                ['slug' => self::SLUG]
            );
            $publishedBody = $published->body();
            $missing = $controller->show(
                new Request('GET', '/v/no-existe-qa'),
                ['slug' => 'no-existe-qa']
            );
            $service->despublicar($userId);
            $unpublished = $controller->show(
                new Request('GET', '/v/' . self::SLUG),
                ['slug' => self::SLUG]
            );
            $service->publicar($userId);
            $inactive = $controller->show(
                new Request('GET', '/v/' . self::INACTIVE_SLUG),
                ['slug' => self::INACTIVE_SLUG]
            );

            $results['routes'] = [
                'public_vcard_declared' => $this->fileContains('routes/web.php', "'/v/{slug}'"),
                'public_photo_declared' => $this->fileContains('routes/web.php', "'/v/{slug}/foto'"),
                'no_qr_route' => !$this->fileContains('routes/web.php', '/v/{slug}/qr'),
                'no_vcf_route' => !$this->fileContains('routes/web.php', '/v/{slug}/vcf'),
                'no_products_route' => !$this->fileContains('routes/web.php', '/v/{slug}/productos'),
                'no_credential_verify_route' => !$this->fileContains('routes/web.php', '/credencial/verificar'),
            ];

            $results['safe_404'] = [
                'missing_slug_404' => $missing->status() === 404,
                'unpublished_404' => $unpublished->status() === 404,
                'inactive_user_404' => $inactive->status() === 404,
                'neutral_body' =>
                    str_contains($missing->body(), 'Información no disponible')
                    && !str_contains($missing->body(), self::SLUG)
                    && !str_contains($unpublished->body(), 'QA Público'),
            ];

            $results['public_render'] = [
                'published_status_200' => $published->status() === 200,
                'visible_fields_present' =>
                    str_contains($publishedBody, 'Contacto público QA')
                    && str_contains($publishedBody, 'QA Público')
                    && str_contains($publishedBody, 'Ventas públicas')
                    && str_contains($publishedBody, '5555552222')
                    && str_contains($publishedBody, 'https://example.test/publico')
                    && str_contains($publishedBody, 'Monterrey público'),
                'private_fields_absent' =>
                    !str_contains($publishedBody, self::USERNAME . '@example.test')
                    && !str_contains($publishedBody, '5555550000')
                    && !str_contains($publishedBody, 'https://linkedin.example.test/privado')
                    && !str_contains($publishedBody, 'https://facebook.example.test/privado')
                    && !str_contains($publishedBody, 'https://instagram.example.test/privado'),
                'metadata_uses_only_public_data' =>
                    str_contains($publishedBody, '<meta name="description"')
                    && str_contains($publishedBody, 'Descripción pública segura')
                    && !str_contains($publishedBody, self::USERNAME . '@example.test')
                    && !str_contains($publishedBody, '5555550000'),
                'no_internal_or_sensitive_data' =>
                    !str_contains($publishedBody, 'usuario_id')
                    && !str_contains($publishedBody, 'vcard_id')
                    && !str_contains($publishedBody, 'password_hash')
                    && !str_contains($publishedBody, self::PASSWORD)
                    && !str_contains($publishedBody, 'roles')
                    && !str_contains($publishedBody, 'permisos')
                    && !str_contains($publishedBody, 'token')
                    && !str_contains($publishedBody, 'ruta_relativa'),
                'no_price_stock_cost' =>
                    !str_contains($publishedBody, 'precio')
                    && !str_contains($publishedBody, 'stock')
                    && !str_contains($publishedBody, 'costo'),
            ];

            $results['contact'] = [
                'authorized_contact_action' =>
                    str_contains($publishedBody, 'Contactar por WhatsApp')
                    && str_contains($publishedBody, 'https://wa.me/5555552222'),
                'external_links_use_rel' =>
                    str_contains($publishedBody, 'rel="noopener noreferrer"'),
            ];

            $privacy->actualizarPrivacidad((int) $vcard['id'], [
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
            $noContact = $controller->show(
                new Request('GET', '/v/' . self::SLUG),
                ['slug' => self::SLUG]
            );

            $results['contact']['no_visible_channel_no_action'] =
                $noContact->status() === 200
                && !str_contains($noContact->body(), 'vcard-public__action');

            $privatePhoto = $controller->photo(
                new Request('GET', '/v/' . self::SLUG . '/foto'),
                ['slug' => self::SLUG]
            );
            $noPhoto = $controller->photo(
                new Request('GET', '/v/' . self::NO_PHOTO_SLUG . '/foto'),
                ['slug' => self::NO_PHOTO_SLUG]
            );

            $results['photo_endpoint'] = [
                'private_photo_404' => $privatePhoto->status() === 404,
                'no_photo_404' => $noPhoto->status() === 404,
                'no_private_path_leak' =>
                    !str_contains($privatePhoto->body(), 'profile/users/')
                    && !str_contains($noPhoto->body(), 'profile/users/')
                    && !str_contains($privatePhoto->body(), 'ruta_relativa'),
                'mode' => true,
            ];

            $results['guardrails'] = [
                'no_qr_vcf_credential_controllers' =>
                    !file_exists(BASE_PATH . '/app/Http/Controllers/QrController.php')
                    && !file_exists(BASE_PATH . '/app/Http/Controllers/VcfController.php')
                    && !file_exists(BASE_PATH . '/app/Http/Controllers/CredentialController.php'),
                'no_products_vcard_functional' =>
                    !$this->fileContains('routes/web.php', '/v/{slug}/productos')
                    && !file_exists(BASE_PATH . '/app/Http/Controllers/VcardProductController.php'),
                'no_qr_vcf_credential_services' =>
                    !file_exists(BASE_PATH . '/app/Domain/Vcards/QrService.php')
                    && !file_exists(BASE_PATH . '/app/Domain/Vcards/VcfService.php')
                    && !is_dir(BASE_PATH . '/app/Domain/Credentials'),
                'no_public_json_api' => !$this->fileContains('routes/web.php', '/api/vcard'),
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
                'VCARD-PUBLICO-SEGURIDAD-1 assertions failed: '
                . json_encode(
                    $results,
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                )
            );
        }

        return [
            'database' => $expectedDatabase,
            'cases' => $results,
            'photo_endpoint_mode' => 'safe_404_deferred_to_VCARD-FOTO-PUBLICA-1',
            'persistent_counts_before' => $before,
            'transient_counts_during' => $during,
            'persistent_counts_after' => $after,
            'cleanup' => 'transaction_rolled_back',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publishVcard(
        VcardService $service,
        VcardPrivacyService $privacy,
        int $userId,
        string $slug,
        bool $photoVisible
    ): array {
        $vcard = $service->asegurarVcard($userId);
        $service->actualizarConfiguracion($userId, [
            'slug' => $slug,
            'titulo_publico' => 'Contacto público QA',
            'descripcion_publica' => 'Descripción pública segura',
            'canal_contacto_preferido' => 'whatsapp',
        ]);
        $privacy->actualizarPrivacidad((int) $vcard['id'], [
            'foto' => $photoVisible,
            'correo' => false,
            'telefono_fijo' => false,
            'telefono_movil' => true,
            'puesto' => true,
            'empresa' => false,
            'almacen' => false,
            'ubicacion' => true,
            'sitio_web' => true,
            'linkedin' => false,
            'facebook' => false,
            'instagram' => false,
            'whatsapp' => true,
            'google_maps' => false,
            'productos' => false,
        ]);

        return $service->publicar($userId);
    }

    private function controller(): PublicVcardController
    {
        return new PublicVcardController(
            $GLOBALS['vcard_publico_config'],
            $this->service()
        );
    }

    private function service(): VcardService
    {
        $privacy = $this->privacy();

        return new VcardService(
            new UserVcardRepository($GLOBALS['vcard_publico_connection']),
            new VcardPrivacyRepository($GLOBALS['vcard_publico_connection']),
            $privacy
        );
    }

    private function privacy(): VcardPrivacyService
    {
        return new VcardPrivacyService(
            new VcardPrivacyRepository($GLOBALS['vcard_publico_connection'])
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
                "username LIKE 'qa_vcard_publico%'"
            ),
            'perfiles_qa' => $this->countWhere(
                $pdo,
                'perfiles_usuario p INNER JOIN usuarios u ON u.id = p.usuario_id',
                "u.username LIKE 'qa_vcard_publico%'"
            ),
            'fotos_qa' => $this->countWhere(
                $pdo,
                'usuarios_fotos f INNER JOIN usuarios u ON u.id = f.usuario_id',
                "u.username LIKE 'qa_vcard_publico%'"
            ),
            'vcards_qa' => $this->countWhere(
                $pdo,
                'vcards_usuario v INNER JOIN usuarios u ON u.id = v.usuario_id',
                "u.username LIKE 'qa_vcard_publico%'"
            ),
            'privacidad_qa' => $this->countWhere(
                $pdo,
                'vcard_privacidad vp
                 INNER JOIN vcards_usuario v ON v.id = vp.vcard_id
                 INNER JOIN usuarios u ON u.id = v.usuario_id',
                "u.username LIKE 'qa_vcard_publico%'"
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
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
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
                \'Público\',
                \'Ventas públicas\',
                \'5555550000\',
                \'5555551111\',
                \'https://example.test/publico\',
                \'https://linkedin.example.test/privado\',
                \'https://facebook.example.test/privado\',
                \'https://instagram.example.test/privado\',
                \'5555552222\',
                \'https://maps.example.test/privado\',
                \'Monterrey público\'
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
                \'qa-vcard-publico.webp\',
                \'qa-vcard-publico.webp\',
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
            'ruta_relativa' => 'profile/users/' . $userId . '/qa-vcard-publico.webp',
            'sha256' => hash('sha256', 'vcard-publico-photo-' . $userId),
            'creado_por' => $userId,
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
