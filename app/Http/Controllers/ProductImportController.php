<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\Auth\AuthService;
use App\Domain\Products\Import\ProductImportPreviewException;
use App\Domain\Products\Import\ProductImportPreviewService;
use App\Domain\Security\PermissionService;
use App\Domain\Scope\ScopeContextService;
use App\Support\Security\CsrfTokenService;

final class ProductImportController
{
    public function __construct(
        private readonly Config $config,
        private readonly AuthService $auth,
        private readonly PermissionService $permissions,
        private readonly ScopeContextService $scopeContext,
        private readonly CsrfTokenService $csrf,
        private readonly ProductImportPreviewService $previews,
    ) {
    }

    public function index(Request $request): Response
    {
        $preview = null;
        $error = null;
        $status = 200;
        $previewId = $request->query()['preview_id'] ?? null;

        if (is_string($previewId) && $previewId !== '') {
            try {
                $preview = $this->previews->get(
                    $previewId,
                    $this->user()['user_id'],
                    $this->sessionBinding(),
                );
            } catch (ProductImportPreviewException $exception) {
                $error = $this->safeError($exception);
                $status = $exception->httpStatus;
            }
        }

        return $this->render($preview, $error, $this->notice($request), $status);
    }

    public function validateFile(Request $request): Response
    {
        try {
            $preview = $this->previews->create(
                $request->file('archivo'),
                $this->user()['user_id'],
                $this->sessionBinding(),
            );
            return Response::redirect(
                '/productos/importar?preview_id='
                . rawurlencode((string) $preview['preview_id']),
            );
        } catch (ProductImportPreviewException $exception) {
            return $this->render(null, $this->safeError($exception), null, $exception->httpStatus);
        }
    }

    public function discard(Request $request): Response
    {
        $previewId = $request->input('preview_id');
        if (!is_string($previewId)) {
            return $this->notFound();
        }

        try {
            $this->previews->discard(
                $previewId,
                $this->user()['user_id'],
                $this->sessionBinding(),
            );
        } catch (ProductImportPreviewException $exception) {
            if ($exception->httpStatus === 403 || $exception->httpStatus === 404) {
                return $this->notFound();
            }
            return $this->render(null, $this->safeError($exception), null, $exception->httpStatus);
        }

        return Response::redirect('/productos/importar?result=discarded');
    }

    /** @param array<string, mixed>|null $preview @param array{code: string, message: string}|null $error */
    private function render(?array $preview, ?array $error, ?string $notice, int $status): Response
    {
        $user = $this->user();
        $context = $this->scopeContext->resolveForUser($user['user_id']);

        return Response::html(View::render('layouts/app', [
            'activeNavigation' => 'products',
            'appName' => (string) $this->config->get('app.name', 'SoporteGR ERP'),
            'canAccessCatalogs' => $this->permissions->allows($user['user_id'], 'catalogos.acceder'),
            'canAccessProducts' => true,
            'contentData' => [
                'error' => $error,
                'notice' => $notice,
                'preview' => $preview,
            ],
            'contentView' => 'products/import',
            'context' => $context->toArray(),
            'csrf' => $this->csrf,
            'pageTitle' => 'Importar productos',
            'stylesheets' => [
                '/css/modules/products.css',
                '/css/modules/product-import.css',
            ],
            'user' => $user,
        ]), $status);
    }

    /** @return array{user_id: int, username: string, email: string} */
    private function user(): array
    {
        $user = $this->auth->user();
        if ($user === null) {
            throw new \RuntimeException('Authenticated product import controller requires a user.');
        }
        return $user;
    }

    private function sessionBinding(): string
    {
        $sessionId = session_id();
        if ($sessionId === '') {
            throw new \RuntimeException('An active session is required for product import previews.');
        }
        return hash('sha256', $sessionId);
    }

    /** @return array{code: string, message: string} */
    private function safeError(ProductImportPreviewException $exception): array
    {
        return ['code' => $exception->errorCode, 'message' => $exception->getMessage()];
    }

    private function notice(Request $request): ?string
    {
        return ($request->query()['result'] ?? null) === 'discarded'
            ? 'Preview descartado.'
            : null;
    }

    private function notFound(): Response
    {
        return Response::html(View::render('errors/404'), 404);
    }
}
