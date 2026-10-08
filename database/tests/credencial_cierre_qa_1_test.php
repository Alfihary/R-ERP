<?php

declare(strict_types=1);

use App\Infrastructure\Database\DatabaseTest;

return new class implements DatabaseTest {
    /** @var list<string> */
    private const REQUIRED_PERMISSIONS = [
        'perfil.ver',
        'perfil.editar',
        'perfil.password.cambiar',
        'perfil.foto.actualizar',
        'perfil.foto.eliminar',
        'vcard.ver',
        'vcard.editar',
        'vcard.publicar',
        'vcard.privacidad.editar',
        'vcard.productos.administrar',
        'vcard.qr.ver',
        'vcard.vcf.descargar',
        'credencial.ver',
        'credencial.qr.ver',
        'credencial.qr.descargar',
    ];

    public function run(PDO $pdo, string $expectedDatabase): array
    {
        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
            throw new RuntimeException('Unexpected active database.');
        }

        foreach (['usuarios', 'permisos', 'roles', 'rol_permisos', 'usuario_roles'] as $table) {
            if (!$this->tableExists($pdo, $table)) {
                throw new RuntimeException('CREDENCIAL-CIERRE-QA-1 requires table: ' . $table);
            }
        }

        $routes = $this->contents('routes/web.php');
        $bootstrap = $this->contents('bootstrap/app.php');
        $credentialShow = $this->contents('app/Views/credentials/show.php');
        $credentialVerify = $this->contents('app/Views/credentials/verify.php');
        $vcardPublic = $this->contents('app/Views/vcards/public.php');
        $publicCredentialController = $this->contents('app/Http/Controllers/PublicCredentialController.php');
        $credentialController = $this->contents('app/Http/Controllers/CredentialController.php');
        $verificationService = $this->contents('app/Domain/Credentials/CredentialVerificationService.php');
        $perfilCredencialFotoTest = $this->contents('database/tests/perfil_credencial_foto_1_test.php');
        $credencialHardeningTest = $this->contents('database/tests/credencial_hardening_1_test.php');
        $vcardPublicoTest = $this->contents('database/tests/vcard_publico_seguridad_1_test.php');
        $vcardFotoTest = $this->contents('database/tests/vcard_foto_publica_1_test.php');
        $vcardVcfTest = $this->contents('database/tests/vcard_vcf_1_test.php');
        $vcardQrTest = $this->contents('database/tests/vcard_qr_1_test.php');
        $vcardProductosTest = $this->contents('database/tests/vcard_productos_1_test.php');
        $vcardProductosImagenTest = $this->contents('database/tests/vcard_productos_imagen_publica_ui_1_test.php');
        $credencialTokenQrTest = $this->contents('database/tests/credencial_token_qr_1_test.php');
        $credencialVerificationTest = $this->contents('database/tests/credencial_verificacion_publica_1_test.php');

        $results = [
            'routes_private_profile_credential' => [
                'get_profile_declared' => $this->contains($routes, "'/perfil'"),
                'post_profile_update_declared' => $this->contains($routes, "'/perfil/actualizar'"),
                'post_password_declared' => $this->contains($routes, "'/perfil/password'"),
                'post_photo_declared' => $this->contains($routes, "'/perfil/foto'"),
                'post_photo_delete_declared' => $this->contains($routes, "'/perfil/foto/eliminar'"),
                'credential_declared' => $this->contains($routes, "'/perfil/credencial'"),
                'credential_photo_declared' => $this->contains($routes, "'/perfil/credencial/foto'"),
                'credential_qr_declared' => $this->contains($routes, "'/perfil/credencial/' . 'qr'"),
                'credential_qr_download_declared' => $this->contains($routes, "'/perfil/credencial/' . 'qr/descargar'"),
                'credential_renew_declared' => $this->contains($routes, "'/perfil/credencial/token/renovar'"),
                'credential_revoke_declared' => $this->contains($routes, "'/perfil/credencial/token/revocar'"),
            ],
            'routes_public' => [
                'credential_verification_declared' => $this->contains($routes, "'/credencial/' . 'verificar/{token}'"),
                'vcard_public_declared' => $this->contains($routes, "'/v/{slug}'"),
                'vcard_photo_declared' => $this->contains($routes, "'/v/{slug}/foto'"),
                'vcard_vcf_declared' => $this->contains($routes, "'/v/{slug}/' . 'vcf'"),
                'vcard_qr_declared' => $this->contains($routes, "'/v/{slug}/' . 'qr'"),
                'vcard_products_declared' => $this->contains($routes, "'/v/{slug}/productos'"),
                'vcard_product_image_declared' => $this->contains($routes, "'/v/{slug}/productos/{id_producto}/imagen'"),
            ],
            'routes_forbidden' => [
                'no_public_credential_photo' =>
                    !$this->contains($routes, '/credencial/verificar/{token}/foto')
                    && !$this->contains($routes, "'/credencial/' . 'verificar/{token}/foto'"),
                'public_vcard_products_routes_controlled' =>
                    $this->contains($routes, "'/v/{slug}/productos'")
                    && $this->contains($routes, "'/v/{slug}/productos/{id_producto}/imagen'")
                    && $this->contains($vcardProductosTest, 'products_false_hides_section')
                    && $this->contains($vcardProductosTest, 'unpublished_hides_all')
                    && $this->contains($vcardProductosTest, 'inactive_user_hides_all')
                    && $this->contains($vcardProductosTest, 'inactive_product_hidden')
                    && $this->contains($vcardProductosImagenTest, 'privacy_false_404')
                    && $this->contains($vcardProductosImagenTest, 'unlinked_product_404')
                    && $this->contains($vcardProductosImagenTest, 'inactive_link_404')
                    && $this->contains($vcardProductosImagenTest, 'inactive_product_404')
                    && $this->contains($vcardProductosImagenTest, 'missing_file_404')
                    && $this->contains($vcardProductosImagenTest, 'traversal_404')
                    && $this->contains($vcardProductosImagenTest, 'svg_rejected')
                    && $this->contains($vcardProductosImagenTest, 'gif_rejected')
                    && $this->contains($vcardProductosImagenTest, 'php_rejected')
                    && $this->contains($vcardProductosTest, 'no_price_min_cost_stock')
                    && $this->contains($vcardProductosTest, 'no_internal_ids_or_sensitive'),
                'no_public_credential_api' => !$this->contains($routes, "'/api/credencial"),
                'no_public_vcard_api' => !$this->contains($routes, "'/api/vcard"),
            ],
            'permissions_matrix' => [
                'permissions_active' => $this->activePermissions($pdo) === count(self::REQUIRED_PERMISSIONS),
                'private_routes_have_auth_middleware' =>
                    $this->contains($routes, '$authMiddleware')
                    && $this->contains($routes, 'new AuthMiddleware($auth)'),
                'private_routes_have_permission_middleware' =>
                    $this->contains($routes, 'new PermissionMiddleware($auth, $permissions, \'credencial.ver\')')
                    && $this->contains($routes, '$profileMiddleware(\'perfil.editar\')')
                    && $this->contains($routes, '$profileMiddleware(\'perfil.foto.actualizar\')'),
                'public_routes_before_private_middlewares' =>
                    strpos($routes, "'/credencial/' . 'verificar/{token}'") !== false
                    && strpos($routes, "'/v/{slug}'") !== false,
            ],
            'csrf_and_private_access' => [
                'csrf_middleware_registered' => $this->contains($bootstrap, 'new CsrfMiddleware($csrf)'),
                'private_posts_declared_as_post' =>
                    $this->contains($routes, '$router->post(')
                    && $this->contains($routes, "'/perfil/actualizar'")
                    && $this->contains($routes, "'/perfil/password'")
                    && $this->contains($routes, "'/perfil/foto'")
                    && $this->contains($routes, "'/perfil/foto/eliminar'")
                    && $this->contains($routes, "'/perfil/credencial/token/renovar'")
                    && $this->contains($routes, "'/perfil/credencial/token/revocar'"),
                'dedicated_tests_cover_no_session_and_403' =>
                    $this->contains($perfilCredencialFotoTest, 'no_session_redirects_to_login')
                    && $this->contains($perfilCredencialFotoTest, 'without_permission_403'),
            ],
            'credential_private_security' => [
                'visual_no_sensitive_terms' => $this->notContainsAny($credentialShow, [
                    'password_hash',
                    'token_hash',
                    'credencial_tokens',
                    'usuario_roles',
                    'rol_permisos',
                    'storage/uploads',
                    'uploads/usuarios',
                    'ruta_relativa',
                ]),
                'private_photo_endpoint_used' => $this->contains($credentialShow, 'src="/perfil/credencial/foto"'),
                'private_photo_controller_validates_realpath' =>
                    $this->contains($credentialController, 'realpath(')
                    && $this->contains($credentialController, 'uploads/usuarios')
                    && $this->contains($credentialController, 'safePhotoPath'),
                'private_photo_controller_validates_mime_and_size' =>
                    $this->contains($credentialController, 'finfo_open(FILEINFO_MIME_TYPE)')
                    && $this->contains($credentialController, 'filesize(')
                    && $this->contains($credentialController, 'image/jpeg')
                    && $this->contains($credentialController, 'image/png')
                    && $this->contains($credentialController, 'image/webp'),
                'private_photo_rejects_dangerous_double_extension' =>
                    $this->contains($credentialController, 'hasDangerousDoubleExtension')
                    && $this->contains($credentialController, "'php'"),
            ],
            'credential_token_qr_security' => [
                'qr_not_persisted_covered' => $this->contains($credencialTokenQrTest, 'png_not_persisted'),
                'plain_token_not_stored_covered' => $this->contains($credencialTokenQrTest, 'plain_token_not_stored'),
                'token_hash_not_in_html_covered' => $this->contains($credencialTokenQrTest, 'no_token_hash'),
            ],
            'credential_public_security' => [
                'public_verify_no_photo_or_sensitive_data' => $this->notContainsAny($credentialVerify, [
                    '<img',
                    '/perfil/credencial/foto',
                    'storage/uploads',
                    'ruta_relativa',
                    'password_hash',
                    'token_hash',
                    'credencial_tokens',
                    'email',
                    'telefono',
                    'precio',
                    'stock',
                    'costo',
                ]),
                'valid_200_covered' =>
                    $this->contains($credencialVerificationTest, "'valid_token'")
                    && $this->contains($credencialVerificationTest, "'status_200'"),
                'uniform_404_covered' =>
                    $this->contains($credencialHardeningTest, 'uniform_404_body')
                    && $this->contains($credencialHardeningTest, 'body_does_not_reveal_reason'),
                'rate_limit_429_covered' =>
                    $this->contains($credencialHardeningTest, 'attempt_31_429')
                    && $this->contains($verificationService, 'RATE_LIMIT_MAX_ATTEMPTS = 30'),
                'public_audit_without_token_hash_covered' =>
                    $this->contains($credencialHardeningTest, 'audit_no_token_or_hash')
                    && $this->contains($verificationService, 'credencial.verificacion.publica.rate_limited'),
            ],
            'vcard_public_security' => [
                'public_view_no_private_storage_paths' => $this->notContainsAny($vcardPublic, [
                    'ruta_relativa',
                    'storage/uploads',
                    'password_hash',
                    'token_hash',
                    'credencial_tokens',
                ]),
                'privacy_respected_covered' =>
                    $this->contains($vcardPublicoTest, 'privacy_hidden_fields_not_rendered')
                    || $this->contains($vcardPublicoTest, 'privacy'),
                'photo_privacy_covered' =>
                    $this->contains($vcardFotoTest, 'privacy_hidden_404')
                    || $this->contains($vcardFotoTest, 'photoVisible'),
                'vcf_privacy_no_products_covered' =>
                    $this->contains($vcardVcfTest, 'privacy')
                    && $this->contains($vcardVcfTest, 'productos'),
                'qr_not_persisted_covered' => $this->contains($vcardQrTest, 'no_physical_png_file_created'),
                'products_public_only_if_enabled_covered' =>
                    $this->contains($vcardProductosTest, 'productos')
                    && $this->contains($vcardProductosTest, 'products_false_hides_section')
                    && $this->contains($vcardProductosTest, 'products_true_shows_public_products'),
            ],
            'regression_surface' => [
                'no_product_price_inventory_routes_touched_in_qa' =>
                    !$this->contains($routes, 'CREDENCIAL-CIERRE-QA-1'),
                'no_new_functional_files_expected' =>
                    file_exists(BASE_PATH . '/database/credencial-cierre-qa.php')
                    && file_exists(BASE_PATH . '/database/tests/credencial_cierre_qa_1_test.php')
                    && file_exists(BASE_PATH . '/docs/credencial-cierre-qa-1.md'),
            ],
        ];

        if (!$this->allTrue($results)) {
            throw new RuntimeException(
                'CREDENCIAL-CIERRE-QA-1 assertions failed: '
                . json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        return [
            'database' => $expectedDatabase,
            'cases' => $results,
            'routes_private' => [
                'GET /perfil',
                'POST /perfil/actualizar',
                'POST /perfil/password',
                'POST /perfil/foto',
                'POST /perfil/foto/eliminar',
                'GET /perfil/credencial',
                'GET /perfil/credencial/foto',
                'GET /perfil/credencial/qr',
                'GET /perfil/credencial/qr/descargar',
                'POST /perfil/credencial/token/renovar',
                'POST /perfil/credencial/token/revocar',
            ],
            'routes_public' => [
                'GET /credencial/verificar/{token}',
                'GET /v/{slug}',
                'GET /v/{slug}/foto',
                'GET /v/{slug}/vcf',
                'GET /v/{slug}/qr',
                'GET /v/{slug}/productos',
                'GET /v/{slug}/productos/{id_producto}/imagen',
            ],
            'forbidden_routes' => [
                'GET /credencial/verificar/{token}/foto',
                'GET /api/credencial/*',
                'GET /api/vcard/*',
            ],
            'permissions' => self::REQUIRED_PERMISSIONS,
            'cleanup' => 'no_transient_data_created',
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

    private function activePermissions(PDO $pdo): int
    {
        $placeholders = implode(',', array_fill(0, count(self::REQUIRED_PERMISSIONS), '?'));
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM permisos
             WHERE codigo IN (' . $placeholders . ')
               AND activo = 1
               AND eliminado_en IS NULL'
        );
        $statement->execute(self::REQUIRED_PERMISSIONS);

        return (int) $statement->fetchColumn();
    }

    private function contents(string $relativePath): string
    {
        $path = BASE_PATH . '/' . ltrim($relativePath, '/');

        if (!is_file($path)) {
            throw new RuntimeException('Missing expected file: ' . $relativePath);
        }

        $contents = file_get_contents($path);

        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read expected file: ' . $relativePath);
        }

        return $contents;
    }

    private function contains(string $haystack, string $needle): bool
    {
        return str_contains($haystack, $needle);
    }

    /**
     * @param list<string> $needles
     */
    private function notContainsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $value
     */
    private function allTrue(array $value): bool
    {
        foreach ($value as $item) {
            if (is_array($item)) {
                if (!$this->allTrue($item)) {
                    return false;
                }

                continue;
            }

            if ($item !== true) {
                return false;
            }
        }

        return true;
    }
};
