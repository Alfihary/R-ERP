<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\Auth\AuthService;
use App\Domain\Mail\MailOutboxActionService;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContextService;
use App\Infrastructure\Repositories\MailOutboxQueryRepository;
use App\Support\Security\CsrfTokenService;

final class MailOutboxController
{
    public const PERMISSION = 'correos.cola.ver';
    public const RETRY_PERMISSION = 'correos.cola.reintentar';
    public const CANCEL_PERMISSION = 'correos.cola.cancelar';

    public function __construct(
        private readonly Config $config,
        private readonly AuthService $auth,
        private readonly PermissionService $permissions,
        private readonly ScopeContextService $scopeContext,
        private readonly CsrfTokenService $csrf,
        private readonly MailOutboxQueryRepository $outbox,
        private readonly ?MailOutboxActionService $actions = null
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
        $canRetry = $this->permissions->allows($user['user_id'], self::RETRY_PERMISSION)
            && (string) ($message['status'] ?? '') === 'ERROR'
            && (int) ($message['intentos'] ?? 0) < (int) ($message['max_intentos'] ?? 0);
        $canCancel = $this->permissions->allows($user['user_id'], self::CANCEL_PERMISSION)
            && in_array((string) ($message['status'] ?? ''), ['PENDIENTE', 'ERROR'], true);

        return $this->render('admin/mail/outbox/show', [
            'message' => $message,
            'canRetry' => $canRetry,
            'canCancel' => $canCancel,
            'actionNotice' => $this->actionNotice($request->query()['result'] ?? null),
        ], 'Detalle de correo', $context->toArray());
    }

    public function retry(Request $request): Response
    {
        $id = $this->positiveInteger($request->input('id'));
        if ($id === null) {
            return $this->notFound();
        }

        $user = $this->user();
        $context = $this->scopeContext->resolveForUser($user['user_id']);
        $result = $this->actionService()->retry(
            $id,
            $user['user_id'],
            array_column($context->effectiveScope()->warehouses(), 'id'),
            $this->requestContext($request)
        );

        if ($result['result'] === 'not_found') {
            return $this->notFound();
        }

        return Response::redirect(
            '/admin/correo/cola/detalle?id=' . $id . '&result=' . $this->resultQuery($result)
        );
    }

    public function cancel(Request $request): Response
    {
        $id = $this->positiveInteger($request->input('id'));
        if ($id === null) {
            return $this->notFound();
        }
        $reason = $request->input('motivo');
        $reason = is_string($reason) ? $reason : '';

        $user = $this->user();
        $context = $this->scopeContext->resolveForUser($user['user_id']);
        $result = $this->actionService()->cancel(
            $id,
            $user['user_id'],
            array_column($context->effectiveScope()->warehouses(), 'id'),
            $reason,
            $this->requestContext($request)
        );

        if ($result['result'] === 'not_found') {
            return $this->notFound();
        }

        return Response::redirect(
            '/admin/correo/cola/detalle?id=' . $id . '&result=' . $this->resultQuery($result)
        );
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

    private function actionService(): MailOutboxActionService
    {
        if (!$this->actions instanceof MailOutboxActionService) {
            throw new \RuntimeException('Mail outbox action service is unavailable.');
        }

        return $this->actions;
    }

    /** @param array{result:string,reason:string,status:string|null} $result */
    private function resultQuery(array $result): string
    {
        if ($result['result'] === 'success') {
            return $result['reason'] === 'cancelled' ? 'cancelled' : 'retry_requested';
        }
        if ($result['reason'] === 'max_attempts') {
            return 'max_attempts';
        }
        if ($result['reason'] === 'invalid_reason') {
            return 'invalid_reason';
        }
        if ($result['result'] === 'already_changed') {
            return 'already_changed';
        }

        return 'invalid_transition';
    }

    /** @return array{type:string,message:string}|null */
    private function actionNotice(mixed $result): ?array
    {
        if (!is_string($result)) {
            return null;
        }

        return match ($result) {
            'retry_requested' => [
                'type' => 'success',
                'message' => 'Reintento solicitado. El mensaje quedó pendiente de procesamiento.',
            ],
            'cancelled' => ['type' => 'success', 'message' => 'Mensaje cancelado.'],
            'max_attempts' => ['type' => 'warning', 'message' => 'Se alcanzó el máximo de intentos.'],
            'invalid_reason' => [
                'type' => 'danger',
                'message' => 'El motivo de cancelación debe tener entre 1 y 300 caracteres.',
            ],
            'already_changed', 'invalid_transition' => [
                'type' => 'warning',
                'message' => 'El estado del mensaje cambió y ya no permite esta acción.',
            ],
            default => null,
        };
    }

    /** @return array{ip:mixed,user_agent:mixed} */
    private function requestContext(Request $request): array
    {
        return [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $request->header('user-agent'),
        ];
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
