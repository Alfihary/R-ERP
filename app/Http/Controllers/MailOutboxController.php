<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\Auth\AuthService;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContextService;
use App\Infrastructure\Repositories\MailOutboxQueryRepository;
use App\Support\Security\CsrfTokenService;

final class MailOutboxController
{
    public const PERMISSION = 'correos.cola.ver';

    public function __construct(
        private readonly Config $config,
        private readonly AuthService $auth,
        private readonly PermissionService $permissions,
        private readonly ScopeContextService $scopeContext,
        private readonly CsrfTokenService $csrf,
        private readonly MailOutboxQueryRepository $outbox
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->user();
        $context = $this->scopeContext->resolveForUser($user['user_id']);
        $warehouseIds = array_column($context->effectiveScope()->warehouses(), 'id');

        return $this->render('admin/mail/outbox/index', [
            'counts' => $this->outbox->countByStatus($warehouseIds),
            'result' => $this->outbox->search($request->query(), $warehouseIds),
            'statuses' => MailOutboxQueryRepository::STATUSES,
            'events' => MailOutboxQueryRepository::EVENTS,
        ], 'Cola de correo', $context->toArray());
    }

    public function show(Request $request): Response
    {
        $id = $this->positiveInteger($request->query()['id'] ?? null);
        if ($id === null) {
            return $this->notFound();
        }

        $user = $this->user();
        $context = $this->scopeContext->resolveForUser($user['user_id']);
        $warehouseIds = array_column($context->effectiveScope()->warehouses(), 'id');
        $message = $this->outbox->findDetail($id, $warehouseIds);

        if ($message === null) {
            return $this->notFound();
        }

        $message['ticket_link_allowed'] = ($message['ticket_link_allowed'] ?? false) === true
            && $this->permissions->allows($user['user_id'], 'tickets_productos.ver');

        return $this->render('admin/mail/outbox/show', [
            'message' => $message,
        ], 'Detalle de correo', $context->toArray());
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     */
    private function render(string $view, array $data, string $title, array $context): Response
    {
        $user = $this->user();
        $userId = $user['user_id'];

        return Response::html(View::render('layouts/app', [
            'activeNavigation' => 'mail-outbox',
            'appName' => (string) $this->config->get('app.name', 'SoporteGR ERP'),
            'canAccessConfiguration' => $this->canAccessConfiguration($userId),
            'canAccessConfigCompanies' => $this->permissions->allows($userId, 'configuracion.empresas.acceder'),
            'canAccessConfigWarehouses' => $this->permissions->allows($userId, 'configuracion.almacenes.acceder'),
            'canAccessConfigFolios' => $this->permissions->allows($userId, 'configuracion.folios.acceder'),
            'canAccessPriceLists' => $this->permissions->allows($userId, 'precios.listas.acceder'),
            'canAccessMailConfiguration' => $this->permissions->allows($userId, 'configuracion.correo.administrar'),
            'canAccessMailOutbox' => true,
            'canAccessAudit' => $this->permissions->allows($userId, AuditController::PERMISSION),
            'contentData' => ['permissionUsed' => self::PERMISSION] + $data,
            'contentView' => $view,
            'context' => $context,
            'csrf' => $this->csrf,
            'pageTitle' => $title,
            'stylesheets' => ['/css/modules/mail-outbox.css'],
            'user' => $user,
        ]));
    }

    private function canAccessConfiguration(int $userId): bool
    {
        foreach ([
            'configuracion.empresas.acceder',
            'configuracion.almacenes.acceder',
            'configuracion.folios.acceder',
            'precios.listas.acceder',
            'configuracion.correo.administrar',
            self::PERMISSION,
            AuditController::PERMISSION,
        ] as $permission) {
            if ($this->permissions->allows($userId, $permission)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{user_id: int, username: string, email: string} */
    private function user(): array
    {
        $user = $this->auth->user();
        if (!is_array($user)) {
            throw new \RuntimeException('Authenticated mail outbox controller requires a user.');
        }

        return $user;
    }

    private function positiveInteger(mixed $value): ?int
    {
        if ((!is_string($value) && !is_int($value)) || preg_match('/^[1-9]\d*$/', (string) $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    private function notFound(): Response
    {
        return Response::html(View::render('errors/404'), 404);
    }
}
