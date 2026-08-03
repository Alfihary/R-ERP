<?php

declare(strict_types=1);

use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Database\Migration;
use App\Infrastructure\Database\MigrationRunner;
use App\Infrastructure\Database\Seed;

return new class implements DatabaseTest {
    private const MIGRATION = 'perfil_vcard_1_001_create_profile_vcard_tables';
    private const QA_USERNAME = 'qa_perfil_vcard_db_1';
    private const QA_EMAIL = 'qa.perfil.vcard.db.1@example.test';
    private const TABLES = [
        'perfiles_usuario',
        'usuarios_fotos',
        'vcards_usuario',
        'vcard_privacidad',
        'vcard_productos',
        'credenciales_usuario',
        'credencial_tokens',
    ];
    private const PERMISSIONS = [
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
    private const PRIVACY_FIELDS = [
        'foto',
        'correo',
        'telefono_fijo',
        'telefono_movil',
        'puesto',
        'empresa',
        'almacen',
        'ubicacion',
        'sitio_web',
        'linkedin',
        'facebook',
        'instagram',
        'whatsapp',
        'google_maps',
        'productos',
    ];

    /**
     * @return array<string, mixed>
     */
    public function run(PDO $pdo, string $expectedDatabase): array
    {
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

        if ($database !== $expectedDatabase) {
            throw new RuntimeException(
                'The active database does not match PERFIL-VCARD-DB-1.'
            );
        }

        $migration = require BASE_PATH
            . '/database/migrations/perfil_vcard_1_001_create_profile_vcard_tables.php';
        $permissionSeed = require BASE_PATH
            . '/database/seeds/perfil_vcard_1_seed_permissions.php';

        if (!$migration instanceof Migration) {
            throw new RuntimeException(
                'PERFIL-VCARD-DB-1 migration has an invalid contract.'
            );
        }

        if (!$permissionSeed instanceof Seed) {
            throw new RuntimeException(
                'PERFIL-VCARD-DB-1 seed has an invalid contract.'
            );
        }

        $runner = new MigrationRunner($pdo);
        $migrationFirst = $runner->migrate($migration);
        $migrationSecond = $runner->migrate($migration);

        $permissionSeed->run($pdo);
        $permissionSeed->run($pdo);

        $schema = $this->schemaEvidence($pdo);
        $seed = $this->seedEvidence($pdo);
        $guardrails = $this->guardrails($pdo);
        $forbiddenColumns = $this->forbiddenColumns($pdo);
        $filesystem = $this->filesystemEvidence();
        $events = $this->programmableObjects($pdo);
        $before = $this->persistentCounts($pdo);
        $cases = $this->functionalCases($pdo);
        $after = $this->persistentCounts($pdo);

        if (
            !in_array($migrationFirst, ['applied', 'already_applied'], true)
            || $migrationSecond !== 'already_applied'
            || $this->migrationRows($pdo, self::MIGRATION) !== 1
            || $schema['tables_exist'] !== true
            || $schema['engines_are_innodb'] !== true
            || $schema['required_indexes_present'] !== true
            || $schema['required_foreign_keys_present'] !== true
            || $seed['permission_rows'] !== count(self::PERMISSIONS)
            || $seed['admin_active_permission_rows'] !== count(self::PERMISSIONS)
            || $seed['duplicate_permission_codes'] !== 0
            || $seed['duplicate_role_permissions'] !== 0
            || $guardrails['all_passed'] !== true
            || $forbiddenColumns['all_absent'] !== true
            || $filesystem['forbidden_absent'] !== true
            || $events['total'] !== 0
            || $cases['all_passed'] !== true
            || $before !== $after
        ) {
            throw new RuntimeException(
                'PERFIL-VCARD-DB-1 assertions are incomplete.'
            );
        }

        return [
            'database' => $database,
            'migration' => [
                'id' => $migration->id(),
                'first_run' => $migrationFirst,
                'second_run' => $migrationSecond,
                'migration_rows' => $this->migrationRows($pdo, self::MIGRATION),
            ],
            'schema' => $schema,
            'seed' => $seed,
            'guardrails' => $guardrails,
            'forbidden_columns' => $forbiddenColumns,
            'filesystem_guardrails' => $filesystem,
            'programmable_objects' => $events,
            'functional' => $cases,
            'persistent_counts_before' => $before,
            'persistent_counts_after' => $after,
            'cleanup' => $before === $after
                ? 'transaction_rolled_back'
                : 'dirty',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaEvidence(PDO $pdo): array
    {
        $tables = [];

        foreach (self::TABLES as $table) {
            $tables[$table] = $this->tableExists($pdo, $table);
        }

        $indexes = [
            'uq_perfiles_usuario_usuario' =>
                $this->indexExists($pdo, 'perfiles_usuario', 'uq_perfiles_usuario_usuario'),
            'uq_usuarios_fotos_activa' =>
                $this->indexExists($pdo, 'usuarios_fotos', 'uq_usuarios_fotos_activa'),
            'uq_vcards_usuario_usuario' =>
                $this->indexExists($pdo, 'vcards_usuario', 'uq_vcards_usuario_usuario'),
            'uq_vcards_usuario_slug' =>
                $this->indexExists($pdo, 'vcards_usuario', 'uq_vcards_usuario_slug'),
            'uq_vcard_privacidad_campo' =>
                $this->indexExists($pdo, 'vcard_privacidad', 'uq_vcard_privacidad_campo'),
            'uq_vcard_productos_producto' =>
                $this->indexExists($pdo, 'vcard_productos', 'uq_vcard_productos_producto'),
            'uq_credenciales_usuario_usuario' =>
                $this->indexExists($pdo, 'credenciales_usuario', 'uq_credenciales_usuario_usuario'),
            'uq_credencial_tokens_hash' =>
                $this->indexExists($pdo, 'credencial_tokens', 'uq_credencial_tokens_hash'),
        ];

        $foreignKeys = [
            'fk_perfiles_usuario_usuario' =>
                $this->foreignKeyExists($pdo, 'perfiles_usuario', 'usuario_id', 'usuarios', 'id'),
            'fk_usuarios_fotos_usuario' =>
                $this->foreignKeyExists($pdo, 'usuarios_fotos', 'usuario_id', 'usuarios', 'id'),
            'fk_vcards_usuario_usuario' =>
                $this->foreignKeyExists($pdo, 'vcards_usuario', 'usuario_id', 'usuarios', 'id'),
            'fk_vcard_privacidad_vcard' =>
                $this->foreignKeyExists($pdo, 'vcard_privacidad', 'vcard_id', 'vcards_usuario', 'id'),
            'fk_vcard_productos_vcard' =>
                $this->foreignKeyExists($pdo, 'vcard_productos', 'vcard_id', 'vcards_usuario', 'id'),
            'fk_vcard_productos_producto' =>
                $this->foreignKeyExists($pdo, 'vcard_productos', 'id_producto', 'productos', 'id_producto'),
            'fk_credenciales_usuario_usuario' =>
                $this->foreignKeyExists($pdo, 'credenciales_usuario', 'usuario_id', 'usuarios', 'id'),
            'fk_credencial_tokens_credencial' =>
                $this->foreignKeyExists($pdo, 'credencial_tokens', 'credencial_id', 'credenciales_usuario', 'id'),
        ];

        return [
            'tables' => $tables,
            'tables_exist' => !in_array(false, $tables, true),
            'engines' => $this->engines($pdo),
            'engines_are_innodb' => !in_array(false, $this->engines($pdo), true),
            'indexes' => $indexes,
            'required_indexes_present' => !in_array(false, $indexes, true),
            'foreign_keys' => $foreignKeys,
            'required_foreign_keys_present' => !in_array(false, $foreignKeys, true),
            'product_fk_type' => $this->columnSignature($pdo, 'vcard_productos', 'id_producto'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function seedEvidence(PDO $pdo): array
    {
        $placeholders = implode(', ', array_fill(0, count(self::PERMISSIONS), '?'));

        $permissions = $pdo->prepare(
            'SELECT COUNT(*) FROM permisos WHERE codigo IN (' . $placeholders . ')'
        );
        $permissions->execute(self::PERMISSIONS);

        $adminPermissions = $pdo->prepare(
            'SELECT COUNT(*)
             FROM permisos p
             INNER JOIN rol_permisos rp
                ON rp.permiso_id = p.id
               AND rp.activo = 1
               AND rp.eliminado_en IS NULL
             INNER JOIN roles r
                ON r.id = rp.rol_id
               AND r.codigo = ?
               AND r.activo = 1
               AND r.eliminado_en IS NULL
             WHERE p.codigo IN (' . $placeholders . ')
               AND p.activo = 1
               AND p.eliminado_en IS NULL'
        );
        $adminPermissions->execute(['ADMIN', ...self::PERMISSIONS]);

        $duplicateCodes = $pdo->prepare(
            'SELECT COUNT(*)
             FROM (
                 SELECT codigo
                 FROM permisos
                 WHERE codigo IN (' . $placeholders . ')
                 GROUP BY codigo
                 HAVING COUNT(*) > 1
             ) duplicated'
        );
        $duplicateCodes->execute(self::PERMISSIONS);

        return [
            'permissions' => self::PERMISSIONS,
            'permission_rows' => (int) $permissions->fetchColumn(),
            'admin_active_permission_rows' => (int) $adminPermissions->fetchColumn(),
            'duplicate_permission_codes' => (int) $duplicateCodes->fetchColumn(),
            'duplicate_role_permissions' => $this->duplicateRolePermissions($pdo),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function guardrails(PDO $pdo): array
    {
        $checks = [
            'usuarios_table_real' => $this->tableExists($pdo, 'usuarios'),
            'products_table_real' => $this->tableExists($pdo, 'productos'),
            'producto_documentos_table_real' => $this->tableExists($pdo, 'producto_documentos'),
            'empresas_table_real' => $this->tableExists($pdo, 'empresas'),
            'almacenes_table_real' => $this->tableExists($pdo, 'almacenes'),
            'auditoria_eventos_table_real' => $this->tableExists($pdo, 'auditoria_eventos'),
            'sessions_table_absent' => !$this->tableExists($pdo, 'sesiones')
                && !$this->tableExists($pdo, 'sessions'),
            'no_vcard_slugs_reservados_table' => !$this->tableExists($pdo, 'vcard_slugs_reservados'),
        ];

        return $checks + ['all_passed' => !in_array(false, $checks, true)];
    }

    /**
     * @return array<string, mixed>
     */
    private function forbiddenColumns(PDO $pdo): array
    {
        $checks = [
            'vcard_productos_precio_absent' =>
                !$this->columnExists($pdo, 'vcard_productos', 'precio'),
            'vcard_productos_stock_absent' =>
                !$this->columnExists($pdo, 'vcard_productos', 'stock'),
            'vcard_productos_costo_absent' =>
                !$this->columnExists($pdo, 'vcard_productos', 'costo'),
            'credencial_tokens_token_absent' =>
                !$this->columnExists($pdo, 'credencial_tokens', 'token'),
            'credencial_tokens_token_plano_absent' =>
                !$this->columnExists($pdo, 'credencial_tokens', 'token_plano'),
            'perfiles_usuario_password_absent' =>
                !$this->columnExists($pdo, 'perfiles_usuario', 'password'),
            'perfiles_usuario_password_hash_absent' =>
                !$this->columnExists($pdo, 'perfiles_usuario', 'password_hash'),
            'perfiles_usuario_hash_absent' =>
                !$this->columnExists($pdo, 'perfiles_usuario', 'hash'),
        ];

        return $checks + ['all_absent' => !in_array(false, $checks, true)];
    }

    /**
     * @return array<string, mixed>
     */
    private function filesystemEvidence(): array
    {
        $paths = [
            'credential_services' => BASE_PATH . '/app/Domain/Credentials',
            'credential_controller' => BASE_PATH . '/app/Http/Controllers/CredentialController.php',
            'vcard_views' => BASE_PATH . '/app/Views/vcards',
            'credential_views' => BASE_PATH . '/app/Views/credentials',
            'vcard_css' => BASE_PATH . '/public/css/modules/vcard.css',
            'credential_css' => BASE_PATH . '/public/css/modules/credential.css',
            'vcard_js' => BASE_PATH . '/public/js/vcard.js',
            'credential_js' => BASE_PATH . '/public/js/credential.js',
        ];
        $exists = [];

        foreach ($paths as $key => $path) {
            $exists[$key] = file_exists($path);
        }

        return [
            'checked' => array_keys($paths),
            'phase_compatibility' =>
                'PERFIL-VCARD-DB-1 validates DB contract only; private profile UI, private vCard service, public vCard security surface, private visual credential and private credential QR may exist after later approved phases.',
            'exists' => $exists,
            'forbidden_absent' =>
                !file_exists(BASE_PATH . '/app/Domain/Credentials/CredentialVerificationService.php')
                && !file_exists(BASE_PATH . '/app/Http/Controllers/CredentialVerificationController.php')
                && ($exists['vcard_css'] ?? false) === false
                && ($exists['vcard_js'] ?? false) === false
                && ($exists['credential_js'] ?? false) === false,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function programmableObjects(PDO $pdo): array
    {
        $types = ['TRIGGER', 'PROCEDURE', 'FUNCTION', 'EVENT'];
        $counts = [];

        foreach ($types as $type) {
            $statement = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM information_schema.routines
                 WHERE routine_schema = DATABASE()
                   AND routine_type = :type'
            );

            if ($type === 'TRIGGER') {
                $statement = $pdo->prepare(
                    'SELECT COUNT(*)
                     FROM information_schema.triggers
                     WHERE trigger_schema = DATABASE()'
                );
                $statement->execute();
            } elseif ($type === 'EVENT') {
                $statement = $pdo->prepare(
                    'SELECT COUNT(*)
                     FROM information_schema.events
                     WHERE event_schema = DATABASE()'
                );
                $statement->execute();
            } else {
                $statement->execute(['type' => $type]);
            }

            $counts[strtolower($type) . 's'] = (int) $statement->fetchColumn();
        }

        $counts['total'] = array_sum($counts);

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private function persistentCounts(PDO $pdo): array
    {
        return [
            'usuarios' => $this->countRows($pdo, 'usuarios'),
            'productos' => $this->countRows($pdo, 'productos'),
            'perfiles_usuario' => $this->countRows($pdo, 'perfiles_usuario'),
            'usuarios_fotos' => $this->countRows($pdo, 'usuarios_fotos'),
            'vcards_usuario' => $this->countRows($pdo, 'vcards_usuario'),
            'vcard_privacidad' => $this->countRows($pdo, 'vcard_privacidad'),
            'vcard_productos' => $this->countRows($pdo, 'vcard_productos'),
            'credenciales_usuario' => $this->countRows($pdo, 'credenciales_usuario'),
            'credencial_tokens' => $this->countRows($pdo, 'credencial_tokens'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function functionalCases(PDO $pdo): array
    {
        $this->adminId($pdo);
        $productId = $this->existingProductId($pdo);
        $results = [];

        $pdo->beginTransaction();

        try {
            $userId = $this->insertUser($pdo);
            $profileId = $this->insertProfile($pdo, $userId);
            $photoId = $this->insertPhoto($pdo, $userId);
            $vcardId = $this->insertVcard($pdo, $userId, 'qa-perfil-vcard');
            foreach (self::PRIVACY_FIELDS as $field) {
                $this->insertPrivacy($pdo, $vcardId, $field, $field === 'productos');
            }
            $vcardProductId = $this->insertVcardProduct($pdo, $vcardId, $productId, $userId);
            $credentialId = $this->insertCredential($pdo, $userId);
            $tokenId = $this->insertCredentialToken($pdo, $credentialId);

            $results['valid_profile_accepted'] = $profileId > 0;
            $results['valid_photo_accepted'] = $photoId > 0;
            $results['valid_vcard_accepted'] = $vcardId > 0;
            $results['valid_privacy_fields_accepted'] =
                $this->privacyFieldCount($pdo, $vcardId) === count(self::PRIVACY_FIELDS);
            $results['valid_vcard_product_accepted'] = $vcardProductId > 0;
            $results['valid_credential_accepted'] = $credentialId > 0;
            $results['valid_token_hash_accepted'] = $tokenId > 0;
            $results['duplicate_profile_rejected'] = $this->fails(
                fn () => $this->insertProfile($pdo, $userId)
            );
            $results['duplicate_vcard_user_rejected'] = $this->fails(
                fn () => $this->insertVcard($pdo, $userId, 'qa-otra-vcard')
            );
            $results['duplicate_slug_rejected'] = $this->fails(
                fn () => $this->insertVcard($pdo, $userId + 999999, 'qa-perfil-vcard')
            );
            $results['invalid_slug_uppercase_rejected'] = $this->fails(
                fn () => $this->insertVcardRawUser($pdo, $userId + 999998, 'QA-PERFIL')
            );
            $results['invalid_privacy_field_rejected'] = $this->fails(
                fn () => $this->insertPrivacy($pdo, $vcardId, 'precio', true)
            );
            $results['duplicate_privacy_field_rejected'] = $this->fails(
                fn () => $this->insertPrivacy($pdo, $vcardId, 'foto', false)
            );
            $results['duplicate_vcard_product_rejected'] = $this->fails(
                fn () => $this->insertVcardProduct($pdo, $vcardId, $productId, $userId)
            );
            $results['vcard_product_unknown_product_rejected'] = $this->fails(
                fn () => $this->insertVcardProduct($pdo, $vcardId, 'QAMISSING000001', $userId)
            );
            $results['duplicate_credential_user_rejected'] = $this->fails(
                fn () => $this->insertCredential($pdo, $userId)
            );
            $results['duplicate_token_hash_rejected'] = $this->fails(
                fn () => $this->insertCredentialToken($pdo, $credentialId)
            );
            $results['plain_token_column_absent'] =
                !$this->columnExists($pdo, 'credencial_tokens', 'token');
            $results['no_price_stock_cost_public_product_columns'] =
                !$this->columnExists($pdo, 'vcard_productos', 'precio')
                && !$this->columnExists($pdo, 'vcard_productos', 'stock')
                && !$this->columnExists($pdo, 'vcard_productos', 'costo');
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        foreach ($results as $result) {
            if ($result !== true) {
                return $results + ['all_passed' => false];
            }
        }

        return $results + ['all_passed' => true];
    }

    private function insertProfile(PDO $pdo, int $userId): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO perfiles_usuario (
                usuario_id,
                primer_nombre,
                apellido_paterno,
                puesto,
                telefono_movil
             ) VALUES (
                :usuario_id,
                :primer_nombre,
                :apellido_paterno,
                :puesto,
                :telefono_movil
             )'
        );
        $statement->execute([
            'usuario_id' => $userId,
            'primer_nombre' => 'QA',
            'apellido_paterno' => 'Perfil',
            'puesto' => 'Operación',
            'telefono_movil' => '5555555555',
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertUser(PDO $pdo): int
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
                1
             )'
        );
        $statement->execute([
            'username' => self::QA_USERNAME,
            'email' => self::QA_EMAIL,
            'password_hash' => password_hash('PerfilVcardQa123!', PASSWORD_DEFAULT),
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertPhoto(PDO $pdo, int $userId): int
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
                :nombre_original,
                :nombre_archivo,
                :mime,
                :extension,
                :tamano_bytes,
                :sha256,
                :ancho,
                :alto,
                :creado_por
             )'
        );
        $statement->execute([
            'usuario_id' => $userId,
            'ruta_relativa' => 'usuarios/' . $userId . '/qa.webp',
            'nombre_original' => 'qa.webp',
            'nombre_archivo' => 'qa.webp',
            'mime' => 'image/webp',
            'extension' => 'webp',
            'tamano_bytes' => 1234,
            'sha256' => hash('sha256', 'perfil-vcard-db-1-photo-' . $userId),
            'ancho' => 100,
            'alto' => 100,
            'creado_por' => $userId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertVcard(PDO $pdo, int $userId, string $slug): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO vcards_usuario (
                usuario_id,
                slug,
                titulo_publico,
                descripcion_publica,
                publicada,
                canal_contacto_preferido
             ) VALUES (
                :usuario_id,
                :slug,
                :titulo_publico,
                :descripcion_publica,
                0,
                :canal_contacto_preferido
             )'
        );
        $statement->execute([
            'usuario_id' => $userId,
            'slug' => $slug,
            'titulo_publico' => 'Perfil QA',
            'descripcion_publica' => 'vCard QA',
            'canal_contacto_preferido' => 'whatsapp',
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertVcardRawUser(PDO $pdo, int $userId, string $slug): int
    {
        return $this->insertVcard($pdo, $userId, $slug);
    }

    private function insertPrivacy(
        PDO $pdo,
        int $vcardId,
        string $field,
        bool $visible
    ): void {
        $statement = $pdo->prepare(
            'INSERT INTO vcard_privacidad (vcard_id, campo, visible)
             VALUES (:vcard_id, :campo, :visible)'
        );
        $statement->execute([
            'vcard_id' => $vcardId,
            'campo' => $field,
            'visible' => $visible ? 1 : 0,
        ]);
    }

    private function insertVcardProduct(
        PDO $pdo,
        int $vcardId,
        string $productId,
        int $actorId
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO vcard_productos (
                vcard_id,
                id_producto,
                activo,
                destacado,
                orden,
                texto_publico,
                creado_por
             ) VALUES (
                :vcard_id,
                :id_producto,
                1,
                0,
                10,
                :texto_publico,
                :creado_por
             )'
        );
        $statement->execute([
            'vcard_id' => $vcardId,
            'id_producto' => $productId,
            'texto_publico' => 'Producto publicado QA sin precio ni stock.',
            'creado_por' => $actorId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function insertCredential(PDO $pdo, int $userId): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO credenciales_usuario (
                usuario_id,
                estatus,
                emitida_en
             ) VALUES (
                :usuario_id,
                \'VIGENTE\',
                CURRENT_TIMESTAMP
             )'
        );
        $statement->execute(['usuario_id' => $userId]);

        return (int) $pdo->lastInsertId();
    }

    private function insertCredentialToken(PDO $pdo, int $credentialId): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO credencial_tokens (
                credencial_id,
                token_hash,
                token_prefix
             ) VALUES (
                :credencial_id,
                :token_hash,
                :token_prefix
             )'
        );
        $statement->execute([
            'credencial_id' => $credentialId,
            'token_hash' => hash('sha256', 'perfil-vcard-db-1-token-' . $credentialId),
            'token_prefix' => 'QATOKEN00001',
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function privacyFieldCount(PDO $pdo, int $vcardId): int
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM vcard_privacidad
             WHERE vcard_id = :vcard_id'
        );
        $statement->execute(['vcard_id' => $vcardId]);

        return (int) $statement->fetchColumn();
    }

    private function adminId(PDO $pdo): int
    {
        $statement = $pdo->prepare(
            'SELECT u.id
             FROM usuarios u
             INNER JOIN usuario_roles ur
                ON ur.usuario_id = u.id
               AND ur.activo = 1
               AND ur.eliminado_en IS NULL
             INNER JOIN roles r
                ON r.id = ur.rol_id
               AND r.codigo = :codigo
               AND r.activo = 1
               AND r.eliminado_en IS NULL
             WHERE u.activo = 1
               AND u.eliminado_en IS NULL
             LIMIT 1'
        );
        $statement->execute(['codigo' => 'ADMIN']);
        $userId = $statement->fetchColumn();

        if ($userId === false) {
            throw new RuntimeException(
                'PERFIL-VCARD-DB-1 requires active ADMIN user.'
            );
        }

        return (int) $userId;
    }

    private function existingProductId(PDO $pdo): string
    {
        $productId = $pdo->query(
            'SELECT id_producto
             FROM productos
             WHERE activo = 1
               AND eliminado_en IS NULL
             ORDER BY id_producto
             LIMIT 1'
        )->fetchColumn();

        if (!is_string($productId) || $productId === '') {
            throw new RuntimeException(
                'PERFIL-VCARD-DB-1 requires at least one active product.'
            );
        }

        return $productId;
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = :table_name'
        );
        $statement->execute(['table_name' => $table]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name'
        );
        $statement->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);

        return (int) $statement->fetchColumn() === 1;
    }

    /**
     * @return array<string, bool>
     */
    private function engines(PDO $pdo): array
    {
        $statement = $pdo->prepare(
            'SELECT table_name AS table_name, engine AS engine
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name IN (' . $this->placeholders(self::TABLES) . ')'
        );
        $statement->execute(self::TABLES);
        $engines = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $engines[(string) $row['table_name']] =
                strtoupper((string) $row['engine']) === 'INNODB';
        }

        return $engines;
    }

    private function indexExists(PDO $pdo, string $table, string $index): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND index_name = :index_name'
        );
        $statement->execute([
            'table_name' => $table,
            'index_name' => $index,
        ]);

        return (int) $statement->fetchColumn() > 0;
    }

    private function foreignKeyExists(
        PDO $pdo,
        string $table,
        string $column,
        string $referencedTable,
        string $referencedColumn
    ): bool {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.key_column_usage
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name
               AND referenced_table_name = :referenced_table_name
               AND referenced_column_name = :referenced_column_name'
        );
        $statement->execute([
            'table_name' => $table,
            'column_name' => $column,
            'referenced_table_name' => $referencedTable,
            'referenced_column_name' => $referencedColumn,
        ]);

        return (int) $statement->fetchColumn() === 1;
    }

    /**
     * @return array<string, string|null>
     */
    private function columnSignature(PDO $pdo, string $table, string $column): array
    {
        $statement = $pdo->prepare(
            'SELECT column_type, character_set_name, collation_name, is_nullable
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table_name
               AND column_name = :column_name'
        );
        $statement->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return [];
        }

        return array_map(
            static fn ($value): ?string => $value === null ? null : (string) $value,
            $row
        );
    }

    private function duplicateRolePermissions(PDO $pdo): int
    {
        return (int) $pdo->query(
            'SELECT COUNT(*)
             FROM (
                 SELECT rol_id, permiso_id
                 FROM rol_permisos
                 GROUP BY rol_id, permiso_id
                 HAVING COUNT(*) > 1
             ) duplicates'
        )->fetchColumn();
    }

    private function migrationRows(PDO $pdo, string $migration): int
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*)
             FROM schema_migrations
             WHERE migration = :migration'
        );
        $statement->execute(['migration' => $migration]);

        return (int) $statement->fetchColumn();
    }

    private function countRows(PDO $pdo, string $table): int
    {
        return (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    private function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    private function fails(callable $operation): bool
    {
        try {
            $operation();
        } catch (PDOException) {
            return true;
        }

        return false;
    }
};
