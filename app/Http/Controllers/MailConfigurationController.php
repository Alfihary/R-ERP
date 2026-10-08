<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\Auth\AuthService;
use App\Domain\Mail\MailConfigurationService;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContextService;
use App\Support\Security\CsrfTokenService;
use InvalidArgumentException;

final class MailConfigurationController
{
    public function __construct(
        private readonly Config $config,
        private readonly AuthService $auth,
        private readonly PermissionService $permissions,
        private readonly ScopeContextService $scopeContext,
        private readonly CsrfTokenService $csrf,
        private readonly MailConfigurationService $mailConfiguration
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->render([
            ...$this->mailConfiguration->overview(),
            'errors' => [],
            'notice' => $this->notice($request),
        ]);
    }

    public function saveAccount(Request $request): Response
    {
        try {
            $this->mailConfiguration->saveAccount($request->body());
        } catch (InvalidArgumentException $exception) {
            return $this->renderWithError($exception->getMessage(), 422);
        }

        return Response::redirect('/admin/correo?result=account_saved');
    }

    public function saveRules(Request $request): Response
    {
        try {
            $this->mailConfiguration->saveRules($request->body());
        } catch (InvalidArgumentException $exception) {
            return $this->renderWithError($exception->getMessage(), 422);
        }

        return Response::redirect('/admin/correo?result=rules_saved');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(array $data, int $status = 200): Response
    {
        $user = $this->user();
        $context = $this->scopeContext->resolveForUser($user['user_id']);

        return Response::html(View::render('layouts/app', [
            'activeNavigation' => 'configuration-mail',
            'appName' => (string) $this->config->get('app.name', 'SoporteGR ERP'),
            'canAccessConfiguration' => true,
            'canAccessConfigCompanies' => $this->permissions->allows(
                $user['user_id'],
                'configuracion.empresas.acceder'
            ),
            'canAccessConfigWarehouses' => $this->permissions->allows(
                $user['user_id'],
                'configuracion.almacenes.acceder'
            ),
            'canAccessConfigFolios' => $this->permissions->allows(
                $user['user_id'],
                'configuracion.folios.acceder'
            ),
            'canAccessMailConfiguration' => true,
            'contentData' => $data,
            'contentView' => 'admin/mail/index',
            'context' => $context->toArray(),
            'csrf' => $this->csrf,
            'pageTitle' => 'Configuración de correo',
            'stylesheets' => ['/css/modules/mail-configuration.css'],
            'user' => $user,
        ]), $status);
    }

    private function renderWithError(string $message, int $status): Response
    {
        return $this->render([
            ...$this->mailConfiguration->overview(),
            'errors' => ['general' => $message],
            'notice' => null,
        ], $status);
    }

    /**
     * @return array{user_id: int, username: string, email: string}
     */
    private function user(): array
    {
        $user = $this->auth->user();

        if ($user === null) {
            throw new \RuntimeException('Authenticated mail configuration controller requires a user.');
        }

        return $user;
    }

    private function notice(Request $request): ?string
    {
        return match ($request->query()['result'] ?? null) {
            'account_saved' => 'Cuenta emisora guardada correctamente.',
            'rules_saved' => 'Reglas de correo guardadas correctamente.',
            default => null,
        };
    }
}
