<?php

declare(strict_types=1);

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Domain\Audit\AuditService;
use App\Domain\Auth\AuthService;
use App\Domain\Mail\MailOutboxActionService;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContextService;
use App\Domain\Scope\UserScopeService;
use App\Http\Controllers\MailOutboxController;
use App\Http\Middlewares\AuthMiddleware;
use App\Http\Middlewares\CsrfMiddleware;
use App\Http\Middlewares\PermissionMiddleware;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\AuditRepository;
use App\Infrastructure\Repositories\MailOutboxActionRepository;
use App\Infrastructure\Repositories\MailOutboxQueryRepository;
use App\Infrastructure\Repositories\PermissionRepository;
use App\Infrastructure\Repositories\ScopeRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Support\Security\CsrfTokenService;

return new class implements DatabaseTest {
    private const PASSWORD = 'CorreoOutboxActionsQA123!';

    public function run(PDO $pdo, string $expectedDatabase): array
    {
        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
            throw new RuntimeException('Unexpected active database.');
        }
        foreach (['usuarios', 'roles', 'permisos', 'rol_permisos', 'usuario_roles', 'usuario_empresas',
            'usuario_almacenes', 'tickets_productos', 'tickets_productos_correos', 'auditoria_eventos'] as $table) {
            if (!$this->tableExists($pdo, $table)) {
                throw new RuntimeException('Outbox action test requires table: ' . $table);
            }
        }

        $permissionState = $this->permissionState($pdo);
        if ($permissionState['active_permissions'] !== 2 || $permissionState['admin_assignments'] !== 2) {
            throw new RuntimeException('Run the outbox action permission seed before DB-TEST.');
        }

        $protectedBefore = $this->protectedState($pdo);
        $hashesBefore = $this->protectedHashes($pdo);
        $cases = [];
        $pdo->beginTransaction();

        try {
            $ticket = $this->ticketContext($pdo);
            $allowedUser = $this->insertUser($pdo, 'qa_outbox_actions_allowed');
            $viewOnlyUser = $this->insertUser($pdo, 'qa_outbox_actions_view');
            $this->assignRole($pdo, $allowedUser, [
                MailOutboxController::PERMISSION,
                MailOutboxController::RETRY_PERMISSION,
                MailOutboxController::CANCEL_PERMISSION,
            ]);
            $this->assignRole($pdo, $viewOnlyUser, [MailOutboxController::PERMISSION]);
            $this->assignScope($pdo, $allowedUser, $ticket['empresa_id'], $ticket['almacen_id']);
            $this->assignScope($pdo, $viewOnlyUser, $ticket['empresa_id'], $ticket['almacen_id']);

            $fixtures = [];
            foreach ([
                'retry_ok' => ['ERROR', 1, 3], 'retry_max' => ['ERROR', 3, 3],
                'retry_pending' => ['PENDIENTE', 0, 3], 'retry_sending' => ['ENVIANDO', 1, 3],
                'retry_sent' => ['ENVIADO', 1, 3], 'retry_cancelled' => ['CANCELADO', 0, 3],
                'cancel_pending' => ['PENDIENTE', 0, 3], 'cancel_error' => ['ERROR', 1, 3],
                'cancel_max' => ['ERROR', 3, 3], 'cancel_sending' => ['ENVIANDO', 1, 3],
                'cancel_sent' => ['ENVIADO', 1, 3], 'cancel_cancelled' => ['CANCELADO', 0, 3],
                'reason_empty' => ['PENDIENTE', 0, 3], 'reason_long' => ['PENDIENTE', 0, 3],
                'scope' => ['ERROR', 1, 3], 'double_retry' => ['ERROR', 1, 3],
                'double_cancel' => ['PENDIENTE', 0, 3], 'race' => ['ERROR', 1, 3],
                'audit_failure' => ['ERROR', 1, 3], 'controller_retry' => ['ERROR', 1, 3],
                'controller_cancel' => ['PENDIENTE', 0, 3], 'sql_reason' => ['PENDIENTE', 0, 3],
                'xss_reason' => ['PENDIENTE', 0, 3],
            ] as $key => [$status, $attempts, $maxAttempts]) {
                $fixtures[$key] = $this->insertOutbox(
                    $pdo,
                    $ticket['id'],
                    $allowedUser,
                    $key,
                    $status,
                    $attempts,
                    $maxAttempts
                );
            }

            $service = $this->service();
            $scope = [$ticket['almacen_id']];
            $context = ['ip' => '127.0.0.1', 'user_agent' => "QA\r\nAgent"];

            $beforeRetry = $this->outbox($pdo, $fixtures['retry_ok']);
            $retry = $service->retry($fixtures['retry_ok'], $allowedUser, $scope, $context);
            $afterRetry = $this->outbox($pdo, $fixtures['retry_ok']);
            $cases['retry_error_eligible'] = $retry['result'] === 'success'
                && $afterRetry['status'] === 'PENDIENTE';
            $cases['retry_preserves_attempts'] = $afterRetry['intentos'] === $beforeRetry['intentos']
                && $afterRetry['ultimo_intento_at'] === $beforeRetry['ultimo_intento_at'];
            $cases['retry_preserves_dedupe_and_error'] = $afterRetry['dedupe_key'] === $beforeRetry['dedupe_key']
                && $afterRetry['error_mensaje_seguro'] === $beforeRetry['error_mensaje_seguro'];
            $cases['retry_error_exhausted'] = $service->retry(
                $fixtures['retry_max'], $allowedUser, $scope
            )['reason'] === 'max_attempts';
            $cases['retry_pending_invalid'] = $service->retry(
                $fixtures['retry_pending'], $allowedUser, $scope
            )['result'] === 'invalid_transition';
            $cases['retry_sending_invalid'] = $service->retry(
                $fixtures['retry_sending'], $allowedUser, $scope
            )['result'] === 'invalid_transition';
            $cases['retry_sent_invalid'] = $service->retry(
                $fixtures['retry_sent'], $allowedUser, $scope
            )['result'] === 'invalid_transition';
            $cases['retry_cancelled_invalid'] = $service->retry(
                $fixtures['retry_cancelled'], $allowedUser, $scope
            )['result'] === 'invalid_transition';

            $cancelPending = $service->cancel(
                $fixtures['cancel_pending'], $allowedUser, $scope, 'Cancelación QA'
            );
            $cancelPendingRow = $this->outbox($pdo, $fixtures['cancel_pending']);
            $cases['cancel_pending'] = $cancelPending['result'] === 'success'
                && $cancelPendingRow['status'] === 'CANCELADO'
                && $cancelPendingRow['cancelado_at'] !== null;
            $cases['cancel_error'] = $service->cancel(
                $fixtures['cancel_error'], $allowedUser, $scope, 'Error no recuperable'
            )['result'] === 'success';
            $cases['cancel_exhausted_error'] = $service->cancel(
                $fixtures['cancel_max'], $allowedUser, $scope, 'Intentos agotados'
            )['result'] === 'success';
            $cases['cancel_sending_invalid'] = $service->cancel(
                $fixtures['cancel_sending'], $allowedUser, $scope, 'No permitido'
            )['result'] === 'invalid_transition';
            $cases['cancel_sent_invalid'] = $service->cancel(
                $fixtures['cancel_sent'], $allowedUser, $scope, 'No permitido'
            )['result'] === 'invalid_transition';
            $cancelledAt = $this->outbox($pdo, $fixtures['cancel_cancelled'])['cancelado_at'];
            $cases['cancel_cancelled_noop'] = $service->cancel(
                $fixtures['cancel_cancelled'], $allowedUser, $scope, 'Repetido'
            )['result'] === 'already_changed'
                && $this->outbox($pdo, $fixtures['cancel_cancelled'])['cancelado_at'] === $cancelledAt;
            $cases['empty_reason_invalid'] = $service->cancel(
                $fixtures['reason_empty'], $allowedUser, $scope, '   '
            )['reason'] === 'invalid_reason';
            $cases['long_reason_invalid'] = $service->cancel(
                $fixtures['reason_long'], $allowedUser, $scope, str_repeat('a', 301)
            )['reason'] === 'invalid_reason';
            $cases['valid_scope'] = $service->retry(
                $fixtures['scope'], $allowedUser, $scope
            )['result'] === 'success';
            $cases['outside_scope_not_found'] = $service->retry(
                $fixtures['retry_pending'], $allowedUser, [$ticket['almacen_id'] + 100000]
            )['result'] === 'not_found';
            $cases['missing_id_not_found'] = $service->retry(
                2147483647, $allowedUser, $scope
            )['result'] === 'not_found';

            $doubleRetryOne = $service->retry($fixtures['double_retry'], $allowedUser, $scope);
            $doubleRetryTwo = $service->retry($fixtures['double_retry'], $allowedUser, $scope);
            $cases['double_retry_single_success'] = $doubleRetryOne['result'] === 'success'
                && $doubleRetryTwo['result'] === 'invalid_transition'
                && $this->auditCount($pdo, 'MAIL_OUTBOX_RETRY_REQUESTED', $fixtures['double_retry']) === 1;
            $doubleCancelOne = $service->cancel(
                $fixtures['double_cancel'], $allowedUser, $scope, 'Doble cancelación'
            );
            $doubleCancelTwo = $service->cancel(
                $fixtures['double_cancel'], $allowedUser, $scope, 'Doble cancelación'
            );
            $cases['double_cancel_single_success'] = $doubleCancelOne['result'] === 'success'
                && $doubleCancelTwo['result'] === 'already_changed'
                && $this->auditCount($pdo, 'MAIL_OUTBOX_CANCELLED', $fixtures['double_cancel']) === 1;

            $pdo->prepare("UPDATE tickets_productos_correos SET status = 'ENVIANDO' WHERE id = :id")
                ->execute(['id' => $fixtures['race']]);
            $cases['changed_state_race_safe'] = $service->retry(
                $fixtures['race'], $allowedUser, $scope
            )['result'] === 'invalid_transition';
            $cases['retry_audit'] = $this->auditCount(
                $pdo, 'MAIL_OUTBOX_RETRY_REQUESTED', $fixtures['retry_ok']
            ) === 1 && $this->auditMetadataContains($pdo, $fixtures['retry_ok'], 'estado_nuevo', 'PENDIENTE');
            $cases['cancel_audit'] = $this->auditCount(
                $pdo, 'MAIL_OUTBOX_CANCELLED', $fixtures['cancel_pending']
            ) === 1 && $this->auditMetadataContains($pdo, $fixtures['cancel_pending'], 'motivo', 'Cancelación QA');

            $auditFailed = false;
            try {
                $service->retry($fixtures['audit_failure'], PHP_INT_MAX, $scope);
            } catch (Throwable) {
                $auditFailed = true;
            }
            $cases['audit_failure_rolls_back'] = $auditFailed
                && $this->outbox($pdo, $fixtures['audit_failure'])['status'] === 'ERROR';

            $sqlReason = "QA'); DROP TABLE tickets_productos_correos; --";
            $cases['sql_injection_safe'] = $service->cancel(
                $fixtures['sql_reason'], $allowedUser, $scope, $sqlReason
            )['result'] === 'success' && $this->tableExists($pdo, 'tickets_productos_correos');
            $xssReason = '<script>alert(1)</script>';
            $cases['xss_reason_safe'] = $service->cancel(
                $fixtures['xss_reason'], $allowedUser, $scope, $xssReason
            )['result'] === 'success'
                && e($xssReason) === '&lt;script&gt;alert(1)&lt;/script&gt;';

            $guest = $this->guestAuth();
            $cases['retry_guest_redirect'] = $this->middlewareStatus(
                new AuthMiddleware($guest), new Request('POST', '/admin/correo/cola/reintentar')
            ) === 302;
            $cases['cancel_guest_redirect'] = $this->middlewareStatus(
                new AuthMiddleware($guest), new Request('POST', '/admin/correo/cola/cancelar')
            ) === 302;
            $viewOnlyAuth = $this->authForUser('qa_outbox_actions_view');
            $cases['retry_without_permission_403'] = $this->middlewareStatus(
                new PermissionMiddleware(
                    $viewOnlyAuth,
                    $this->permissions(),
                    MailOutboxController::RETRY_PERMISSION
                ),
                new Request('POST', '/admin/correo/cola/reintentar')
            ) === 403;
            $cases['cancel_without_permission_403'] = $this->middlewareStatus(
                new PermissionMiddleware(
                    $viewOnlyAuth,
                    $this->permissions(),
                    MailOutboxController::CANCEL_PERMISSION
                ),
                new Request('POST', '/admin/correo/cola/cancelar')
            ) === 403;

            $csrf = new CsrfTokenService($this->session(), 7200);
            $csrf->token();
            $cases['retry_missing_csrf_419'] = $this->middlewareStatus(
                new CsrfMiddleware($csrf), new Request('POST', '/admin/correo/cola/reintentar')
            ) === 419;
            $cases['cancel_invalid_csrf_419'] = $this->middlewareStatus(
                new CsrfMiddleware($csrf),
                new Request('POST', '/admin/correo/cola/cancelar', [], ['_token' => 'invalid'])
            ) === 419;

            $controller = $this->controllerFor('qa_outbox_actions_allowed');
            $retryResponse = $controller->retry(new Request(
                'POST', '/admin/correo/cola/reintentar', [], ['id' => (string) $fixtures['controller_retry']]
            ));
            $cancelResponse = $controller->cancel(new Request(
                'POST', '/admin/correo/cola/cancelar', [], [
                    'id' => (string) $fixtures['controller_cancel'], 'motivo' => 'Cancelación HTTP QA',
                ]
            ));
            $cases['authorized_retry_redirect'] = $retryResponse->status() === 302
                && $this->outbox($pdo, $fixtures['controller_retry'])['status'] === 'PENDIENTE';
            $cases['authorized_cancel_redirect'] = $cancelResponse->status() === 302
                && $this->outbox($pdo, $fixtures['controller_cancel'])['status'] === 'CANCELADO';
            $cases['missing_outbox_404'] = $controller->retry(new Request(
                'POST', '/admin/correo/cola/reintentar', [], ['id' => '2147483647']
            ))->status() === 404;
            $cases['malicious_id_404'] = $controller->retry(new Request(
                'POST', '/admin/correo/cola/reintentar', [], ['id' => '1 OR 1=1']
            ))->status() === 404;

            $errorBody = $controller->show(new Request(
                'GET', '/admin/correo/cola/detalle', ['id' => (string) $fixtures['retry_max']]
            ))->body();
            $sendingBody = $controller->show(new Request(
                'GET', '/admin/correo/cola/detalle', ['id' => (string) $fixtures['retry_sending']]
            ))->body();
            $sentBody = $controller->show(new Request(
                'GET', '/admin/correo/cola/detalle', ['id' => (string) $fixtures['retry_sent']]
            ))->body();
            $cases['ui_exhausted_no_retry'] = str_contains($errorBody, 'Máximo de intentos alcanzado')
                && !str_contains($errorBody, '>Reintentar</button>');
            $cases['ui_sending_no_actions'] = str_contains($sendingBody, 'Procesamiento en curso')
                && !str_contains($sendingBody, '>Reintentar</button>')
                && !str_contains($sendingBody, '>Cancelar mensaje</button>');
            $cases['ui_sent_terminal'] = !str_contains($sentBody, '>Reintentar</button>')
                && !str_contains($sentBody, '>Cancelar mensaje</button>');
            $cases['routes_and_server_authority'] = $this->routeContract();
            $cases['no_mail_runtime_dependencies'] = $this->noMailRuntimeDependencies();
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        $protectedAfter = $this->protectedState($pdo);
        $hashesAfter = $this->protectedHashes($pdo);
        $cases['protected_rows_unchanged'] = $protectedBefore === $protectedAfter;
        $cases['protected_hashes_unchanged'] = $hashesBefore === $hashesAfter;

        if (!$this->allTrue($cases)) {
            throw new RuntimeException(
                'Outbox action assertions failed: '
                . json_encode($cases, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            );
        }

        return [
            'database' => $expectedDatabase,
            'permissions' => $permissionState,
            'cases' => $cases,
            'case_count' => count($cases),
            'protected' => $protectedAfter,
            'integrity_hashes_match' => true,
            'network_connections' => 0,
            'real_emails_sent' => 0,
            'secret_resolutions' => 0,
            'cleanup' => 'transaction_rolled_back',
        ];
    }

    private function service(): MailOutboxActionService
    {
        $connection = $GLOBALS['correo_outbox_actions_connection'];
        $auditRepository = new AuditRepository($connection);

        return new MailOutboxActionService(
            $connection,
            new MailOutboxActionRepository($connection),
            new AuditService($auditRepository),
            $auditRepository
        );
    }

    private function controllerFor(string $username): MailOutboxController
    {
        $connection = $GLOBALS['correo_outbox_actions_connection'];
        $auth = $this->authForUser($username);

        return new MailOutboxController(
            $GLOBALS['correo_outbox_actions_config'],
            $auth,
            $this->permissions(),
            new ScopeContextService(new UserScopeService(new ScopeRepository($connection)), $this->session()),
            new CsrfTokenService($this->session(), 7200),
            new MailOutboxQueryRepository($connection),
            $this->service()
        );
    }

    /** @return array{id:int,empresa_id:int,almacen_id:int} */
    private function ticketContext(PDO $pdo): array
    {
        $row = $pdo->query('SELECT id, empresa_id, almacen_id FROM tickets_productos WHERE id = 34')
            ->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Protected QA ticket 34 is required.');
        }

        return ['id' => (int) $row['id'], 'empresa_id' => (int) $row['empresa_id'], 'almacen_id' => (int) $row['almacen_id']];
    }

    private function insertUser(PDO $pdo, string $username): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO usuarios (username, email, password_hash, activo)
             VALUES (:username, :email, :password_hash, 1)'
        );
        $statement->execute([
            'username' => $username,
            'email' => $username . '@example.test',
            'password_hash' => password_hash(self::PASSWORD, PASSWORD_DEFAULT),
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** @param list<string> $permissions */
    private function assignRole(PDO $pdo, int $userId, array $permissions): void
    {
        $code = 'qa_outbox_actions_' . $userId;
        $pdo->prepare('INSERT INTO roles (codigo, nombre, es_sistema, activo) VALUES (:c, :n, 0, 1)')
            ->execute(['c' => $code, 'n' => $code]);
        $roleId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO usuario_roles (usuario_id, rol_id, activo) VALUES (:u, :r, 1)')
            ->execute(['u' => $userId, 'r' => $roleId]);
        foreach ($permissions as $permission) {
            $pdo->prepare(
                'INSERT INTO rol_permisos (rol_id, permiso_id, activo)
                 SELECT :role, id, 1 FROM permisos WHERE codigo = :permission'
            )->execute(['role' => $roleId, 'permission' => $permission]);
        }
    }

    private function assignScope(PDO $pdo, int $userId, int $companyId, int $warehouseId): void
    {
        $pdo->prepare('INSERT INTO usuario_empresas (usuario_id, empresa_id, activo) VALUES (:u, :e, 1)')
            ->execute(['u' => $userId, 'e' => $companyId]);
        $pdo->prepare(
            'INSERT INTO usuario_almacenes (usuario_id, empresa_id, almacen_id, activo)
             VALUES (:u, :e, :w, 1)'
        )->execute(['u' => $userId, 'e' => $companyId, 'w' => $warehouseId]);
    }

    private function insertOutbox(
        PDO $pdo,
        int $ticketId,
        int $userId,
        string $key,
        string $status,
        int $attempts,
        int $maxAttempts
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO tickets_productos_correos (
                ticket_id, evento, plantilla, destinatario_email, subject, html, text,
                status, intentos, max_intentos, error_mensaje_seguro, ultimo_intento_at,
                enviado_at, cancelado_at, creado_por_usuario_id, dedupe_key
             ) VALUES (
                :ticket, \'TICKET_CREADO\', \'ticket_created\', \'qa@example.test\', :subject,
                \'<p>QA</p>\', \'QA\', :status, :attempts, :max_attempts, :error,
                :last_attempt, :sent_at, :cancelled_at, :user, :dedupe
             )'
        );
        $statement->execute([
            'ticket' => $ticketId,
            'subject' => 'QA outbox action ' . $key,
            'status' => $status,
            'attempts' => $attempts,
            'max_attempts' => $maxAttempts,
            'error' => $status === 'ERROR' ? 'Error QA seguro' : null,
            'last_attempt' => $attempts > 0 ? date('Y-m-d H:i:s') : null,
            'sent_at' => $status === 'ENVIADO' ? date('Y-m-d H:i:s') : null,
            'cancelled_at' => $status === 'CANCELADO' ? date('Y-m-d H:i:s') : null,
            'user' => $userId,
            'dedupe' => 'qa-outbox-actions-' . $ticketId . '-' . $key . '-' . bin2hex(random_bytes(4)),
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** @return array<string,mixed> */
    private function outbox(PDO $pdo, int $id): array
    {
        $statement = $pdo->prepare('SELECT * FROM tickets_productos_correos WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('QA outbox fixture was not found.');
        }

        $row['intentos'] = (int) $row['intentos'];
        $row['max_intentos'] = (int) $row['max_intentos'];

        return $row;
    }

    private function auditCount(PDO $pdo, string $action, int $outboxId): int
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM auditoria_eventos WHERE accion = :action AND entidad_id = :id'
        );
        $statement->execute(['action' => $action, 'id' => (string) $outboxId]);

        return (int) $statement->fetchColumn();
    }

    private function auditMetadataContains(PDO $pdo, int $outboxId, string $key, string $value): bool
    {
        $statement = $pdo->prepare(
            'SELECT metadata_json FROM auditoria_eventos
             WHERE entidad = \'tickets_productos_correos\' AND entidad_id = :id
             ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(['id' => (string) $outboxId]);
        $metadata = json_decode((string) $statement->fetchColumn(), true);

        return is_array($metadata) && (string) ($metadata[$key] ?? '') === $value;
    }

    private function authForUser(string $username): AuthService
    {
        $_SESSION = [];
        $auth = new AuthService(
            new UserRepository($GLOBALS['correo_outbox_actions_connection']),
            $this->session()
        );
        if (!$auth->attempt($username . '@example.test', self::PASSWORD)) {
            throw new RuntimeException('Unable to authenticate outbox action QA user.');
        }

        return $auth;
    }

    private function guestAuth(): AuthService
    {
        $_SESSION = [];

        return new AuthService(
            new UserRepository($GLOBALS['correo_outbox_actions_connection']),
            $this->session()
        );
    }

    private function permissions(): PermissionService
    {
        return new PermissionService(new PermissionRepository($GLOBALS['correo_outbox_actions_connection']));
    }

    private function session(): Session
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_save_path(sys_get_temp_dir());
            session_name('correooutboxactions1');
            session_id('correooutboxactions1' . bin2hex(random_bytes(4)));
            session_start();
        }

        return new Session([
            'name' => session_name(), 'same_site' => 'Lax', 'secure' => false, 'gc_max_lifetime' => 7200,
        ]);
    }

    private function middlewareStatus(object $middleware, Request $request): int
    {
        return $middleware->process(
            $request,
            static fn (Request $request): Response => Response::html('ok')
        )->status();
    }

    private function routeContract(): bool
    {
        $routes = (string) file_get_contents(BASE_PATH . '/routes/web.php');
        $controller = (string) file_get_contents(BASE_PATH . '/app/Http/Controllers/MailOutboxController.php');

        return str_contains($routes, "'/admin/correo/cola/reintentar'")
            && str_contains($routes, "'/admin/correo/cola/cancelar'")
            && str_contains($routes, 'MailOutboxController::RETRY_PERMISSION')
            && str_contains($routes, 'MailOutboxController::CANCEL_PERMISSION')
            && !str_contains($controller, "input('status')")
            && !str_contains($controller, "input('intentos')")
            && !str_contains($controller, "input('ticket_id')")
            && !str_contains($controller, "input('almacen_id')");
    }

    private function noMailRuntimeDependencies(): bool
    {
        $files = [
            BASE_PATH . '/app/Domain/Mail/MailOutboxActionService.php',
            BASE_PATH . '/app/Http/Controllers/MailOutboxController.php',
            BASE_PATH . '/app/Infrastructure/Repositories/MailOutboxActionRepository.php',
        ];
        $content = '';
        foreach ($files as $file) {
            $content .= (string) file_get_contents($file);
        }

        foreach (['PHPMailer', 'smtp_secret_ref', 'MailOutboxProcessor', 'MailTransport', 'fsockopen'] as $forbidden) {
            if (str_contains($content, $forbidden)) {
                return false;
            }
        }

        return true;
    }

    /** @return array{active_permissions:int,admin_assignments:int,non_admin_assignments:int} */
    private function permissionState(PDO $pdo): array
    {
        $codes = "'correos.cola.reintentar','correos.cola.cancelar'";

        return [
            'active_permissions' => (int) $pdo->query(
                "SELECT COUNT(*) FROM permisos WHERE codigo IN ({$codes}) AND activo = 1 AND eliminado_en IS NULL"
            )->fetchColumn(),
            'admin_assignments' => (int) $pdo->query(
                "SELECT COUNT(*) FROM rol_permisos rp
                 INNER JOIN roles r ON r.id = rp.rol_id
                 INNER JOIN permisos p ON p.id = rp.permiso_id
                 WHERE r.codigo = 'ADMIN' AND p.codigo IN ({$codes})
                   AND rp.activo = 1 AND rp.eliminado_en IS NULL"
            )->fetchColumn(),
            'non_admin_assignments' => (int) $pdo->query(
                "SELECT COUNT(*) FROM rol_permisos rp
                 INNER JOIN roles r ON r.id = rp.rol_id
                 INNER JOIN permisos p ON p.id = rp.permiso_id
                 WHERE r.codigo <> 'ADMIN' AND p.codigo IN ({$codes})
                   AND rp.activo = 1 AND rp.eliminado_en IS NULL"
            )->fetchColumn(),
        ];
    }

    /** @return array<string,mixed> */
    private function protectedState(PDO $pdo): array
    {
        $ticket = $pdo->query(
            'SELECT id, folio, estado FROM tickets_productos WHERE id = 34'
        )->fetch(PDO::FETCH_ASSOC);
        $outbox = $pdo->query(
            'SELECT id, status, intentos, enviado_at, cancelado_at
             FROM tickets_productos_correos WHERE id IN (1, 36) ORDER BY id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $eligible = (int) $pdo->query(
            "SELECT COUNT(*) FROM tickets_productos_correos
             WHERE status = 'PENDIENTE' OR (status = 'ERROR' AND intentos < max_intentos)"
        )->fetchColumn();

        return ['ticket' => $ticket, 'outbox' => $outbox, 'eligible_count' => $eligible];
    }

    /** @return array{tickets_productos:string,tickets_productos_correos:string} */
    private function protectedHashes(PDO $pdo): array
    {
        return [
            'tickets_productos' => $this->tableHash($pdo, 'tickets_productos'),
            'tickets_productos_correos' => $this->tableHash($pdo, 'tickets_productos_correos'),
        ];
    }

    private function tableHash(PDO $pdo, string $table): string
    {
        $rows = $pdo->query("SELECT * FROM {$table} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

        return hash('sha256', json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :table'
        );
        $statement->execute(['table' => $table]);

        return (int) $statement->fetchColumn() === 1;
    }

    /** @param array<string,bool> $values */
    private function allTrue(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== true) {
                return false;
            }
        }

        return true;
    }
};
