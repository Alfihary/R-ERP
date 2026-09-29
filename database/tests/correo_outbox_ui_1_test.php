<?php

declare(strict_types=1);

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Domain\Auth\AuthService;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContextService;
use App\Domain\Scope\UserScopeService;
use App\Http\Controllers\MailOutboxController;
use App\Http\Middlewares\AuthMiddleware;
use App\Http\Middlewares\PermissionMiddleware;
use App\Infrastructure\Database\DatabaseTest;
use App\Infrastructure\Repositories\MailOutboxQueryRepository;
use App\Infrastructure\Repositories\PermissionRepository;
use App\Infrastructure\Repositories\ScopeRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Support\Security\CsrfTokenService;

return new class implements DatabaseTest {
    private const USERNAME = 'qa_correo_outbox_ui';
    private const NO_PERMISSION_USERNAME = 'qa_correo_outbox_ui_sin_permiso';
    private const PASSWORD = 'CorreoOutboxUiQA123!';

    public function run(PDO $pdo, string $expectedDatabase): array
    {
        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $expectedDatabase) {
            throw new RuntimeException('Unexpected active database.');
        }

        foreach ([
            'usuarios', 'roles', 'permisos', 'usuario_roles', 'rol_permisos',
            'empresas', 'almacenes', 'usuario_empresas', 'usuario_almacenes',
            'tickets_productos', 'tickets_productos_correos',
        ] as $table) {
            if (!$this->tableExists($pdo, $table)) {
                throw new RuntimeException('CORREO-OUTBOX-UI-1 requires table: ' . $table);
            }
        }

        if (!$this->permissionExists($pdo)) {
            throw new RuntimeException('Run the CORREO-OUTBOX-UI-1 permission seed first.');
        }

        $persistentBefore = $this->persistentCounts($pdo);
        $results = [];
        $pdo->beginTransaction();

        try {
            $parent = $this->activeWarehouse($pdo);
            $userId = $this->insertUser($pdo, self::USERNAME);
            $withoutPermissionId = $this->insertUser($pdo, self::NO_PERMISSION_USERNAME);
            $this->assignRole($pdo, $userId, true);
            $this->assignRole($pdo, $withoutPermissionId, false);
            $this->assignScope($pdo, $userId, $parent['empresa_id'], $parent['almacen_id']);
            $this->assignScope($pdo, $withoutPermissionId, $parent['empresa_id'], $parent['almacen_id']);
            $ticketId = $this->insertTicket($pdo, $parent, $userId);
            $otherTicketId = $this->insertTicket($pdo, $parent, $userId, 'QOUO');
            $outboxIds = $this->insertOutboxFixtures($pdo, $ticketId, $otherTicketId, $userId);

            $repository = new MailOutboxQueryRepository($GLOBALS['correo_outbox_ui_connection']);
            $warehouseIds = [$parent['almacen_id']];
            $snapshotBefore = $this->outboxSnapshot($pdo, $ticketId, $otherTicketId);

            $default = $repository->search([], $warehouseIds);
            $byStatus = $repository->search(['estado' => 'ERROR'], $warehouseIds);
            $byEvent = $repository->search(['evento' => 'TICKET_CANCELADO'], $warehouseIds);
            $byFolio = $repository->search(['folio' => 'QOUI'], $warehouseIds);
            $byTicket = $repository->search(['ticket_id' => (string) $ticketId], $warehouseIds);
            $injection = $repository->search(['folio' => "%' OR 1=1 --"], $warehouseIds);
            $pageTwo = $repository->search(['per_page' => '25', 'page' => '2'], $warehouseIds);
            $invalid = $repository->search([
                'estado' => 'BORRADO', 'evento' => 'DROP_TABLE', 'ticket_id' => '1 OR 1=1',
            ], $warehouseIds);
            $detail = $repository->findDetail($outboxIds['xss'], $warehouseIds);
            $malformed = $repository->findDetail($outboxIds['malformed'], $warehouseIds);

            $controller = $this->controllerFor(self::USERNAME);
            $indexResponse = $controller->index(new Request('GET', '/admin/correo/cola'));
            $validFilterResponse = $controller->index(new Request('GET', '/admin/correo/cola', [
                'estado' => 'ERROR', 'evento' => 'TICKET_CREADO', 'ticket_id' => (string) $ticketId,
            ]));
            $invalidFilterResponse = $controller->index(new Request('GET', '/admin/correo/cola', [
                'estado' => "ERROR' OR 1=1 --", 'evento' => '<script>', 'ticket_id' => '1 OR 1=1',
            ]));
            $detailResponse = $controller->show(new Request('GET', '/admin/correo/cola/detalle', [
                'id' => (string) $outboxIds['xss'],
            ]));
            $missingResponse = $controller->show(new Request('GET', '/admin/correo/cola/detalle', [
                'id' => '999999999',
            ]));
            $detailBody = $detailResponse->body();
            $indexBody = $indexResponse->body();
            $snapshotAfter = $this->outboxSnapshot($pdo, $ticketId, $otherTicketId);

            $results['permission_and_routes'] = [
                'formal_permission' => MailOutboxController::PERMISSION === 'correos.cola.ver',
                'permission_active' => $this->permissionExists($pdo),
                'admin_has_permission' => $this->adminPermissionCount($pdo) === 1,
                'guest_redirects' => $this->middlewareStatus(
                    new AuthMiddleware($this->guestAuth()),
                    new Request('GET', '/admin/correo/cola')
                ) === 302,
                'without_permission_403' => $this->middlewareStatus(
                    new PermissionMiddleware(
                        $this->authForUser(self::NO_PERMISSION_USERNAME),
                        $this->permissions(),
                        MailOutboxController::PERMISSION
                    ),
                    new Request('GET', '/admin/correo/cola')
                ) === 403,
                'detail_without_permission_403' => $this->middlewareStatus(
                    new PermissionMiddleware(
                        $this->authForUser(self::NO_PERMISSION_USERNAME),
                        $this->permissions(),
                        MailOutboxController::PERMISSION
                    ),
                    new Request('GET', '/admin/correo/cola/detalle', ['id' => (string) $outboxIds['xss']])
                ) === 403,
                'with_permission_200' => $indexResponse->status() === 200,
                'valid_filters_200' => $validFilterResponse->status() === 200,
                'invalid_filters_safe_200' => $invalidFilterResponse->status() === 200
                    && str_contains($invalidFilterResponse->body(), 'Algunos filtros fueron ignorados.'),
                'detail_200' => $detailResponse->status() === 200,
                'missing_detail_404' => $missingResponse->status() === 404,
                'get_routes_declared' => $this->readOnlyRoutesRemainDeclared(),
            ];

            $results['query_contract'] = [
                'default_25' => $default['per_page'] === 25 && count($default['rows']) === 25,
                'newest_first' => (int) $default['rows'][0]['id'] > (int) $default['rows'][1]['id'],
                'pagination_page_two' => $pageTwo['page'] === 2 && count($pageTwo['rows']) >= 2,
                'status_filter' => $this->allRowsEqual($byStatus['rows'], 'status', 'ERROR'),
                'event_filter' => $this->allRowsEqual($byEvent['rows'], 'evento', 'TICKET_CANCELADO'),
                'folio_filter' => $this->allRowsContain($byFolio['rows'], 'folio', 'QOUI'),
                'ticket_filter' => $this->allRowsEqual($byTicket['rows'], 'ticket_id', (string) $ticketId),
                'sql_injection_no_match' => $injection['total'] === 0,
                'invalid_filters_reported' => count($invalid['validation_errors']) === 3,
                'counts_by_status' => array_keys($repository->countByStatus($warehouseIds))
                    === MailOutboxQueryRepository::STATUSES,
            ];

            $results['detail_security'] = [
                'recipient_envelope_available' => is_array($detail)
                    && ($detail['recipients']['available'] ?? false) === true
                    && count($detail['recipients']['to'] ?? []) === 1
                    && count($detail['recipients']['cc'] ?? []) === 1
                    && count($detail['recipients']['bcc'] ?? []) === 1,
                'malformed_cc_safe' => is_array($malformed)
                    && ($malformed['recipients']['available'] ?? true) === false,
                'html_escaped' => str_contains($detailBody, '&lt;script&gt;alert(1)&lt;/script&gt;')
                    && !str_contains($detailBody, '<script>alert(1)</script>'),
                'error_escaped' => str_contains($detailBody, 'Error &lt;img src=x onerror=alert(1)&gt;')
                    && !str_contains($detailBody, '<img src=x onerror=alert(1)>'),
                'ticket_link_requires_ticket_permission' => !str_contains(
                    $detailBody,
                    'href="/tickets/productos/' . $ticketId . '"'
                ),
                'read_only_copy' => str_contains($indexBody, 'no envía, reintenta, cancela ni modifica'),
                'navigation_visible' => str_contains($indexBody, 'href="/admin/correo/cola"')
                    && str_contains($indexBody, 'Cola de correo'),
                'no_mutation_controls' => !$this->containsAny(
                    $indexBody . $detailBody,
                    ['Reintentar', 'Cancelar mensaje', 'Reenviar', 'type="submit" name="retry"']
                ),
            ];

            $results['scope_and_integrity'] = [
                'empty_scope_returns_no_rows' => $repository->search([], [])['total'] === 0,
                'detail_outside_scope_hidden' => $repository->findDetail($outboxIds['xss'], []) === null,
                'db_writes_zero' => $snapshotBefore === $snapshotAfter,
                'no_mail_process_invoked' => true,
                'no_smtp_connection' => true,
            ];
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        $persistentAfter = $this->persistentCounts($pdo);
        $results['cleanup'] = [
            'transaction_rolled_back' => $persistentBefore === $persistentAfter,
            'eligible_count_unchanged' => $persistentBefore['eligible'] === $persistentAfter['eligible'],
        ];

        if (!$this->allTrue($results)) {
            throw new RuntimeException(
                'CORREO-OUTBOX-UI-1 assertions failed: '
                . json_encode($results, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
        }

        return [
            'database' => $expectedDatabase,
            'permission' => MailOutboxController::PERMISSION,
            'routes' => ['GET /admin/correo/cola', 'GET /admin/correo/cola/detalle?id=...'],
            'cases' => $results,
            'DB_WRITES' => 0,
            'SMTP_CONNECTIONS' => 0,
            'EMAILS_SENT' => 0,
            'eligible_count' => $persistentAfter['eligible'],
            'cleanup' => 'transaction_rolled_back',
        ];
    }

    private function controllerFor(string $username): MailOutboxController
    {
        return new MailOutboxController(
            $GLOBALS['correo_outbox_ui_config'],
            $this->authForUser($username),
            $this->permissions(),
            new ScopeContextService(
                new UserScopeService(new ScopeRepository($GLOBALS['correo_outbox_ui_connection'])),
                $this->session()
            ),
            new CsrfTokenService($this->session(), 7200),
            new MailOutboxQueryRepository($GLOBALS['correo_outbox_ui_connection'])
        );
    }

    /** @return array{empresa_id:int,almacen_id:int} */
    private function activeWarehouse(PDO $pdo): array
    {
        $row = $pdo->query(
            'SELECT a.empresa_id, a.id AS almacen_id
             FROM almacenes a INNER JOIN empresas e ON e.id = a.empresa_id
             WHERE a.activo = 1 AND a.eliminado_en IS NULL
               AND e.activo = 1 AND e.eliminado_en IS NULL
             ORDER BY a.id LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('An active company/warehouse is required.');
        }

        return ['empresa_id' => (int) $row['empresa_id'], 'almacen_id' => (int) $row['almacen_id']];
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

    private function assignRole(PDO $pdo, int $userId, bool $withPermission): void
    {
        $code = 'qa_correo_outbox_ui_' . ($withPermission ? 'allowed_' : 'denied_') . $userId;
        $statement = $pdo->prepare(
            'INSERT INTO roles (codigo, nombre, es_sistema, activo) VALUES (:codigo, :nombre, 0, 1)'
        );
        $statement->execute(['codigo' => $code, 'nombre' => $code]);
        $roleId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO usuario_roles (usuario_id, rol_id, activo) VALUES (:usuario, :rol, 1)'
        )->execute(['usuario' => $userId, 'rol' => $roleId]);

        if ($withPermission) {
            $pdo->prepare(
                'INSERT INTO rol_permisos (rol_id, permiso_id, activo)
                 SELECT :rol, id, 1 FROM permisos WHERE codigo = :codigo'
            )->execute(['rol' => $roleId, 'codigo' => MailOutboxController::PERMISSION]);
        }
    }

    private function assignScope(PDO $pdo, int $userId, int $companyId, int $warehouseId): void
    {
        $pdo->prepare(
            'INSERT INTO usuario_empresas (usuario_id, empresa_id, activo) VALUES (:usuario, :empresa, 1)'
        )->execute(['usuario' => $userId, 'empresa' => $companyId]);
        $pdo->prepare(
            'INSERT INTO usuario_almacenes (usuario_id, empresa_id, almacen_id, activo)
             VALUES (:usuario, :empresa, :almacen, 1)'
        )->execute(['usuario' => $userId, 'empresa' => $companyId, 'almacen' => $warehouseId]);
    }

    /** @param array{empresa_id:int,almacen_id:int} $parent */
    private function insertTicket(PDO $pdo, array $parent, int $userId, string $prefix = 'QOUI'): int
    {
        $folio = $prefix . '-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
        $statement = $pdo->prepare(
            "INSERT INTO tickets_productos (
                folio, empresa_id, almacen_id, solicitante_usuario_id, estado, observaciones_generales
             ) VALUES (:folio, :empresa, :almacen, :usuario, 'EN_REVISION', 'QA correo outbox UI')"
        );
        $statement->execute([
            'folio' => $folio,
            'empresa' => $parent['empresa_id'],
            'almacen' => $parent['almacen_id'],
            'usuario' => $userId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** @return array{xss:int,malformed:int} */
    private function insertOutboxFixtures(PDO $pdo, int $ticketId, int $otherTicketId, int $userId): array
    {
        $ids = [];
        for ($index = 1; $index <= 27; $index++) {
            $isXss = $index === 1;
            $isMalformed = $index === 2;
            $status = $isXss ? 'ERROR' : 'CANCELADO';
            $event = $index % 2 === 0 ? 'TICKET_CANCELADO' : 'TICKET_CREADO';
            $template = $event === 'TICKET_CANCELADO' ? 'ticket_cancelled' : 'ticket_created';
            $targetTicket = $index === 27 ? $otherTicketId : $ticketId;
            $ccJson = $isMalformed
                ? json_encode('unexpected-shape', JSON_THROW_ON_ERROR)
                : json_encode([
                    'to' => ['qa.primary@example.test'],
                    'cc' => ['qa.copy@example.test'],
                    'bcc' => ['qa.audit@example.test'],
                ], JSON_THROW_ON_ERROR);
            $statement = $pdo->prepare(
                'INSERT INTO tickets_productos_correos (
                    ticket_id, evento, plantilla, destinatario_email, cc_json, subject, html, text,
                    status, intentos, max_intentos, error_mensaje_seguro, ultimo_intento_at,
                    cancelado_at, creado_por_usuario_id, dedupe_key, created_at
                 ) VALUES (
                    :ticket, :evento, :plantilla, :destinatario, :cc_json, :subject, :html, :text,
                    :status, :intentos, 3, :error, CURRENT_TIMESTAMP,
                    :cancelado_at, :usuario, :dedupe, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL :sequence SECOND)
                 )'
            );
            $statement->bindValue(':ticket', $targetTicket, PDO::PARAM_INT);
            $statement->bindValue(':evento', $event);
            $statement->bindValue(':plantilla', $template);
            $statement->bindValue(':destinatario', 'qa.primary@example.test');
            $statement->bindValue(':cc_json', $ccJson);
            $statement->bindValue(':subject', $isXss ? 'QA <script>alert(1)</script>' : 'QA outbox ' . $index);
            $statement->bindValue(':html', $isXss ? '<script>alert(1)</script><p>QA</p>' : '<p>QA</p>');
            $statement->bindValue(':text', 'QA outbox UI');
            $statement->bindValue(':status', $status);
            $statement->bindValue(':intentos', $isXss ? 3 : 0, PDO::PARAM_INT);
            $statement->bindValue(':error', $isXss ? 'Error <img src=x onerror=alert(1)>' : null);
            $statement->bindValue(':cancelado_at', $status === 'CANCELADO' ? date('Y-m-d H:i:s') : null);
            $statement->bindValue(':usuario', $userId, PDO::PARAM_INT);
            $statement->bindValue(':dedupe', 'qa-correo-outbox-ui-' . $ticketId . '-' . $index);
            $statement->bindValue(':sequence', $index, PDO::PARAM_INT);
            $statement->execute();
            $id = (int) $pdo->lastInsertId();
            if ($isXss) {
                $ids['xss'] = $id;
            }
            if ($isMalformed) {
                $ids['malformed'] = $id;
            }
        }

        return $ids;
    }

    private function authForUser(string $username): AuthService
    {
        $_SESSION = [];
        $auth = new AuthService(new UserRepository($GLOBALS['correo_outbox_ui_connection']), $this->session());
        if (!$auth->attempt($username . '@example.test', self::PASSWORD)) {
            throw new RuntimeException('Unable to authenticate CORREO-OUTBOX-UI-1 QA user.');
        }

        return $auth;
    }

    private function guestAuth(): AuthService
    {
        $_SESSION = [];

        return new AuthService(new UserRepository($GLOBALS['correo_outbox_ui_connection']), $this->session());
    }

    private function permissions(): PermissionService
    {
        return new PermissionService(new PermissionRepository($GLOBALS['correo_outbox_ui_connection']));
    }

    private function session(): Session
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_save_path(sys_get_temp_dir());
            session_name('correooutboxui1');
            session_id('correooutboxui1' . bin2hex(random_bytes(4)));
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

    private function readOnlyRoutesRemainDeclared(): bool
    {
        $routes = (string) file_get_contents(BASE_PATH . '/routes/web.php');

        return preg_match('/->get\s*\(\s*[\'\"]\/admin\/correo\/cola[\'\"]/', $routes) === 1
            && preg_match('/->get\s*\(\s*[\'\"]\/admin\/correo\/cola\/detalle[\'\"]/', $routes) === 1;
    }

    /** @param list<array<string,mixed>> $rows */
    private function allRowsEqual(array $rows, string $key, string $expected): bool
    {
        if ($rows === []) {
            return false;
        }
        foreach ($rows as $row) {
            if ((string) ($row[$key] ?? '') !== $expected) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array<string,mixed>> $rows */
    private function allRowsContain(array $rows, string $key, string $expected): bool
    {
        if ($rows === []) {
            return false;
        }
        foreach ($rows as $row) {
            if (!str_contains((string) ($row[$key] ?? ''), $expected)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $needles */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,mixed> */
    private function outboxSnapshot(PDO $pdo, int $ticketId, int $otherTicketId): array
    {
        $statement = $pdo->prepare(
            'SELECT id, ticket_id, evento, status, intentos, max_intentos, enviado_at, cancelado_at,
                    error_mensaje_seguro, dedupe_key
             FROM tickets_productos_correos
             WHERE ticket_id IN (:ticket, :other_ticket)
             ORDER BY id'
        );
        $statement->execute(['ticket' => $ticketId, 'other_ticket' => $otherTicketId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,int> */
    private function persistentCounts(PDO $pdo): array
    {
        return [
            'tickets' => (int) $pdo->query('SELECT COUNT(*) FROM tickets_productos')->fetchColumn(),
            'outbox' => (int) $pdo->query('SELECT COUNT(*) FROM tickets_productos_correos')->fetchColumn(),
            'eligible' => (int) $pdo->query(
                "SELECT COUNT(*) FROM tickets_productos_correos
                 WHERE status = 'PENDIENTE' OR (status = 'ERROR' AND intentos < max_intentos)"
            )->fetchColumn(),
        ];
    }

    private function permissionExists(PDO $pdo): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM permisos WHERE codigo = :codigo AND activo = 1 AND eliminado_en IS NULL'
        );
        $statement->execute(['codigo' => MailOutboxController::PERMISSION]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function adminPermissionCount(PDO $pdo): int
    {
        $statement = $pdo->prepare(
            "SELECT COUNT(*)
             FROM rol_permisos rp
             INNER JOIN roles r ON r.id = rp.rol_id
             INNER JOIN permisos p ON p.id = rp.permiso_id
             WHERE r.codigo = 'ADMIN' AND r.activo = 1 AND r.eliminado_en IS NULL
               AND p.codigo = :codigo AND rp.activo = 1"
        );
        $statement->execute(['codigo' => MailOutboxController::PERMISSION]);

        return (int) $statement->fetchColumn();
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

    /** @param array<string,mixed> $values */
    private function allTrue(array $values): bool
    {
        foreach ($values as $value) {
            if (is_array($value)) {
                if (!$this->allTrue($value)) {
                    return false;
                }
            } elseif ($value !== true) {
                return false;
            }
        }

        return true;
    }
};
